<?php
/**
 * Focused tests for qualified event-source intake.
 *
 * @package ExtraChillEvents\Tests
 */

// phpcs:disable -- Isolated pure-unit fixtures intentionally declare WordPress doubles.

require_once dirname( __DIR__, 3 ) . '/inc/Core/ArtistUrlSubmissionsTable.php';
require_once dirname( __DIR__, 3 ) . '/inc/Core/VenueExpansionRunner.php';
require_once dirname( __DIR__, 3 ) . '/inc/Abilities/ArtistUrlImportAbilities.php';

use ExtraChillEvents\Abilities\ArtistUrlImportAbilities;
use ExtraChillEvents\Abilities\VenueQualificationAbilities;
use ExtraChillEvents\Core\ArtistUrlSubmissionsTable;
use ExtraChillEvents\Core\VenueExpansionRunner;

if ( ! class_exists( 'WP_Term' ) ) {
	class WP_Term {
		public int $term_id;
		public string $name;

		public function __construct( int $term_id, string $name ) {
			$this->term_id = $term_id;
			$this->name    = $name;
		}
	}
}

if ( ! function_exists( 'taxonomy_exists' ) ) {
	function taxonomy_exists( $taxonomy ) {
		return in_array( $taxonomy, array( 'artist', 'venue' ), true );
	}
}

if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( $title ) {
		return strtolower( trim( preg_replace( '/[^a-z0-9]+/i', '-', $title ), '-' ) );
	}
}

if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $key ) {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $key ) );
	}
}

if ( ! function_exists( 'get_term_by' ) ) {
	function get_term_by( $field, $value, $taxonomy ) {
		$key = $taxonomy . ':' . strtolower( (string) $value );
		return $GLOBALS['event_source_terms'][ $key ] ?? false;
	}
}

if ( ! function_exists( 'get_terms' ) ) {
	function get_terms() {
		return array( 72 => 'The Band' );
	}
}

if ( ! function_exists( 'wp_strip_all_tags' ) ) {
	function wp_strip_all_tags( $text ) {
		return strip_tags( $text );
	}
}

final class EventSourceIntakeTest extends BookingTestCase {
	private ArtistUrlImportAbilities $intake;

	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['event_source_terms'] = array(
			'venue:the room' => new WP_Term( 41, 'The Room' ),
			'artist:the band' => new WP_Term( 72, 'The Band' ),
		);
		$this->intake = ( new ReflectionClass( ArtistUrlImportAbilities::class ) )->newInstanceWithoutConstructor();
	}

	private function classify( array $events, bool $compatibility_artist = false ): array {
		$method = new ReflectionMethod( ArtistUrlImportAbilities::class, 'classifySource' );
		$method->setAccessible( true );
		return $method->invoke(
			$this->intake,
			'https://example.test/events',
			array(
				'events_found'    => count( $events ),
				'raw_events'      => $events,
				'raw_first_event' => $events[0] ?? array(),
				'page_html'       => '',
			),
			$compatibility_artist
		);
	}

	private function approval_kind( string $stored, ?string $explicit = null ) {
		$method = new ReflectionMethod( ArtistUrlImportAbilities::class, 'resolveApprovalKind' );
		$method->setAccessible( true );
		return $method->invoke( $this->intake, $stored, $explicit );
	}

	public function test_repeated_venue_with_varied_performers_classifies_as_venue(): void {
		$result = $this->classify(
			array(
				array( 'venue' => 'The Room', 'performer' => 'Artist A' ),
				array( 'venue' => 'The Room', 'performer' => 'Artist B' ),
			)
		);

		$this->assertSame( 'venue', $result['source_kind'] );
		$this->assertSame( 'high', $result['confidence'] );
		$this->assertSame( 41, $result['binding']['term_id'] );
	}

	public function test_repeated_performer_across_venues_classifies_as_artist(): void {
		$result = $this->classify(
			array(
				array( 'venue' => 'Room A', 'performer' => 'The Band' ),
				array( 'venue' => 'Room B', 'performer' => 'The Band' ),
			)
		);

		$this->assertSame( 'artist', $result['source_kind'] );
		$this->assertSame( 72, $result['binding']['term_id'] );
	}

	public function test_one_off_and_ambiguous_sources_remain_unknown(): void {
		$result = $this->classify( array( array( 'venue' => 'The Room', 'performer' => 'The Band' ) ) );

		$this->assertSame( 'unknown', $result['source_kind'] );
		$this->assertSame( 'low', $result['confidence'] );
		$this->assertNotEmpty( $result['warnings'] );
	}

	public function test_artist_compatibility_alias_preserves_artist_classification(): void {
		$result = $this->classify( array( array( 'venue' => 'The Room' ) ), true );

		$this->assertSame( 'artist', $result['source_kind'] );
	}

	public function test_approval_dispatch_preserves_artist_and_venue(): void {
		$this->assertSame( 'artist', $this->approval_kind( 'artist' ) );
		$this->assertSame( 'venue', $this->approval_kind( 'venue' ) );
	}

	public function test_unknown_approval_requires_explicit_supported_kind(): void {
		$blocked = $this->approval_kind( 'unknown' );

		$this->assertInstanceOf( WP_Error::class, $blocked );
		$this->assertSame( 'explicit_source_kind_required', $blocked->get_error_code() );
		$this->assertSame( 'venue', $this->approval_kind( 'unknown', 'venue' ) );
	}

	public function test_legacy_rows_read_as_artist_sources(): void {
		$row = ArtistUrlSubmissionsTable::with_compatibility_defaults(
			array(
				'url'                      => 'https://artist.test/tour',
				'suggested_artist_name'    => 'The Band',
				'suggested_artist_term_id' => 72,
			)
		);

		$this->assertSame( 'artist', $row['source_kind'] );
		$this->assertSame( 'artist', $row['entity_taxonomy'] );
		$this->assertSame( 72, $row['entity_term_id'] );
	}

	public function test_venue_qualifier_discovers_canonical_navigation_candidate(): void {
		$qualifier = ( new ReflectionClass( VenueQualificationAbilities::class ) )->newInstanceWithoutConstructor();
		$method    = new ReflectionMethod( VenueQualificationAbilities::class, 'buildCandidateUrls' );
		$method->setAccessible( true );
		$candidates = $method->invoke(
			$qualifier,
			'https://venue.test',
			'https://venue.test',
			'<nav><a href="/calendar">Upcoming shows</a></nav>'
		);

		$this->assertContains( 'https://venue.test/calendar', array_column( $candidates, 'url' ) );
	}

	public function test_existing_coverage_uses_venue_expansion_flow_lookup(): void {
		$runner = new VenueExpansionRunner( null, null, static fn() => array( 'flow_id' => 99, 'flow_name' => 'Existing' ) );

		$this->assertSame( 99, $runner->lookupExistingFlow( 'https://venue.test/calendar' )['flow_id'] );
	}

	public function test_generic_and_compatibility_contracts_are_both_checked_in(): void {
		$abilities = file_get_contents( dirname( __DIR__, 3 ) . '/inc/Abilities/ArtistUrlImportAbilities.php' );
		$routes    = file_get_contents( dirname( __DIR__, 3 ) . '/inc/Api/ArtistUrlImportRoutes.php' );

		$this->assertStringContainsString( 'extrachill/qualify-event-source', $abilities );
		$this->assertStringContainsString( 'extrachill-events/preview-event-source', $abilities );
		$this->assertStringContainsString( 'extrachill-events/preview-artist-url', $abilities );
		$this->assertStringContainsString( '/event-source/preview', $routes );
		$this->assertStringContainsString( '/artist-url/preview', $routes );
	}

	public function test_venue_owner_preselects_venue_and_location_taxonomies(): void {
		$source = file_get_contents( dirname( __DIR__, 3 ) . '/inc/Abilities/VenueAddAbilities.php' );

		$this->assertStringContainsString( "'taxonomy_venue_selection'", $source );
		$this->assertStringContainsString( "'taxonomy_location_selection'", $source );
	}
}
