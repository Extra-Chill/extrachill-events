<?php
/**
 * Polluted-root reconciliation command tests for #854.
 *
 * Drives ReconcileRootLocationsCommand::polluted_root_row() against real
 * WordPress terms, venues, and events: dry runs never mutate, and apply
 * runs never leave an event orphaned by a deleted root.
 *
 * @package ExtraChillEvents\Tests\Unit\Core
 */

namespace ExtraChillEvents\Tests\Unit\Core;

use ExtraChillEvents\Cli\ReconcileRootLocationsCommand;
use ExtraChillEvents\Core\RootLocationRepair;
use WP_UnitTestCase;

require_once dirname( __DIR__, 3 ) . '/inc/Core/QualifiedRootLocation.php';
require_once dirname( __DIR__, 3 ) . '/inc/Core/RootLocationRepair.php';
require_once dirname( __DIR__, 3 ) . '/inc/Cli/ReconcileRootLocationsCommand.php';

/**
 * @covers \ExtraChillEvents\Cli\ReconcileRootLocationsCommand
 */
final class ReconcileInternationalRootsCommandTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		if ( ! taxonomy_exists( 'location' ) ) {
			register_taxonomy( 'location', 'data_machine_events', array( 'hierarchical' => true ) );
		}
		if ( ! taxonomy_exists( 'venue' ) ) {
			register_taxonomy( 'venue', 'data_machine_events' );
		}
		if ( ! post_type_exists( 'data_machine_events' ) ) {
			register_post_type(
				'data_machine_events',
				array(
					'label'    => 'Events',
					'public'   => true,
					'supports' => array( 'title' ),
				)
			);
		}
		extrachill_events_get_location_terms_by_name( true );
		add_filter( 'extrachill_events_geonames_username', static fn(): string => 'test-user' );
		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) {
				unset( $preempt, $args );
				if ( ! is_string( $url ) || false === strpos( $url, 'searchJSON' ) ) {
					return false;
				}
				parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
				$city = (string) ( $query['name_startsWith'] ?? '' );
				$hits = '' === $city ? array() : array( array( 'name' => $city ) );
				return array(
					'response' => array( 'code' => 200 ),
					'body'     => wp_json_encode(
						array(
							'totalResultsCount' => count( $hits ),
							'geonames'          => $hits,
						)
					),
				);
			},
			10,
			3
		);
	}

	public function tearDown(): void {
		remove_filter( 'extrachill_events_geonames_username', static fn(): string => 'test-user' );
		remove_all_filters( 'pre_http_request' );
		parent::tearDown();
	}

	/** Create a venue term with normalized metadata and return its ID. */
	private function create_venue( string $name, string $city, string $state, string $country ): int {
		$venue = wp_insert_term( $name, 'venue' );
		$this->assertNotWPError( $venue );
		$venue_id = (int) $venue['term_id'];
		update_term_meta( $venue_id, '_venue_city', $city );
		update_term_meta( $venue_id, '_venue_state', $state );
		update_term_meta( $venue_id, '_venue_country', $country );
		return $venue_id;
	}

	/** Create an event post attached to a venue and a location term. */
	private function create_event( string $title, int $venue_id, int $location_id ): int {
		$post_id = wp_insert_post(
			array(
				'post_type'   => 'data_machine_events',
				'post_status' => 'publish',
				'post_title'  => $title,
			)
		);
		$this->assertNotWPError( $post_id );
		wp_set_object_terms( (int) $post_id, array( $venue_id ), 'venue', false );
		wp_set_object_terms( (int) $post_id, array( $location_id ), 'location', false );
		return (int) $post_id;
	}

	/** Event location term IDs. */
	private function event_locations( int $event_id ): array {
		return array_map( 'intval', (array) wp_get_object_terms( $event_id, 'location', array( 'fields' => 'ids' ) ) );
	}

	/** RootLocationRepair with WP-backed data adapters and a stubbed redirect seam. */
	private function wp_repair_service(): RootLocationRepair {
		return new RootLocationRepair(
			array(
				'prepare_redirect'    => static fn( object $duplicate, object $canonical ): array => array(
					'from'     => '/location/' . $duplicate->slug . '/',
					'to'       => '/location/' . $canonical->slug . '/',
					'existing' => true,
				),
				'get_objects'         => static fn( int $term_id ): array => array_map( 'intval', (array) get_objects_in_term( $term_id, 'location' ) ),
				'get_object_terms'    => static fn( int $object_id ): array => array_map( 'intval', (array) wp_get_object_terms( $object_id, 'location', array( 'fields' => 'ids' ) ) ),
				'add_relationship'    => static fn( int $object_id, int $term_id ) => wp_set_object_terms( $object_id, array( $term_id ), 'location', true ),
				'remove_relationship' => static fn( int $object_id, int $term_id ) => wp_remove_object_terms( $object_id, array( $term_id ), 'location' ),
				'create_redirect'     => static function ( array $context ): int {
					unset( $context );
					return 1;
				},
				'verify_redirect'     => static function ( array $context ): bool {
					unset( $context );
					return true;
				},
				'delete_redirect'     => static function ( int $redirect_id ): bool {
					unset( $redirect_id );
					return true;
				},
				'delete_term'         => static fn( int $term_id ) => wp_delete_term( $term_id, 'location' ),
				'term_exists'         => static fn( int $term_id ): bool => null !== get_term( $term_id, 'location' ) && ! is_wp_error( get_term( $term_id, 'location' ) ),
			)
		);
	}

	/** Dry run classifies and resolves but never mutates terms or events. */
	public function test_dry_run_does_not_mutate(): void {
		wp_insert_term( 'Europe', 'location' );
		$root  = wp_insert_term( 'Hamburg', 'location' );
		$venue = $this->create_venue( 'Bahnhof Pauli', 'Hamburg', '', 'Germany' );
		$event = $this->create_event( 'Hamburg show', $venue, (int) $root['term_id'] );

		$terms  = get_terms( array(
			'taxonomy'   => 'location',
			'hide_empty' => false,
			'number'     => 0,
		) );
		$before = wp_count_terms( array(
			'taxonomy'   => 'location',
			'hide_empty' => false,
		) );

		$row = ( new ReconcileRootLocationsCommand() )->polluted_root_row( get_term( (int) $root['term_id'], 'location' ), $terms, false, null );

		$this->assertSame( 'unqualified_city_root_canonical_created_on_apply', $row['reason'] );
		$this->assertSame( 'would_create_and_reconcile', $row['status'] );
		$this->assertSame( $before, wp_count_terms( array(
			'taxonomy'   => 'location',
			'hide_empty' => false,
		) ), 'Dry run must not create canonical terms.' );
		$this->assertSame( array( (int) $root['term_id'] ), $this->event_locations( $event ), 'Dry run must not reassign events.' );
	}

	/** Apply reconciles a real city root under its country without orphaning events. */
	public function test_apply_reconciles_city_root_without_orphans(): void {
		wp_insert_term( 'Europe', 'location' );
		$root  = wp_insert_term( 'Hamburg', 'location' );
		$venue = $this->create_venue( 'Bahnhof Pauli', 'Hamburg', '', 'Germany' );
		$event = $this->create_event( 'Hamburg show', $venue, (int) $root['term_id'] );

		$command = new ReconcileRootLocationsCommand();
		$terms   = get_terms( array(
			'taxonomy'   => 'location',
			'hide_empty' => false,
			'number'     => 0,
		) );

		$row = $command->polluted_root_row( get_term( (int) $root['term_id'], 'location' ), $terms, true, $this->wp_repair_service() );

		$this->assertSame( 'reconciled', $row['status'] );
		$this->assertNotSame( (int) $root['term_id'], (int) $row['canonical_id'] );

		$locations = $this->event_locations( $event );
		$this->assertSame( array( (int) $row['canonical_id'] ), $locations, 'Event must sit at the canonical term only.' );

		$canonical = get_term( (int) $row['canonical_id'], 'location' );
		$this->assertSame( 'Hamburg', $canonical->name );
		$country = get_term( (int) $canonical->parent, 'location' );
		$this->assertSame( 'Germany', $country->name );

		$this->assertNull( get_term( (int) $root['term_id'], 'location' ), 'The polluted root must be deleted only after events moved.' );
	}

	/** Multiple canonicals reassign every event and never delete the root. */
	public function test_apply_multiple_canonicals_reassign_without_delete(): void {
		wp_insert_term( 'Europe', 'location' );
		$australia = wp_insert_term( 'Australia', 'location' );
		$melbourne = wp_insert_term( 'Melbourne', 'location', array( 'parent' => (int) $australia['term_id'] ) );

		$root = wp_insert_term( 'Skydiver Records, 358 Smith Street, Melbourne', 'location' );

		$venue_one = $this->create_venue( 'Skydiver Records', 'Melbourne', 'Victoria', 'Australia' );
		$venue_two = $this->create_venue( 'The White Hotel', 'Salford', 'England', 'United Kingdom' );
		$event_one = $this->create_event( 'Melbourne show', $venue_one, (int) $root['term_id'] );
		$event_two = $this->create_event( 'Salford show', $venue_two, (int) $root['term_id'] );

		$command = new ReconcileRootLocationsCommand();
		$terms   = get_terms( array(
			'taxonomy'   => 'location',
			'hide_empty' => false,
			'number'     => 0,
		) );

		$row = $command->polluted_root_row( get_term( (int) $root['term_id'], 'location' ), $terms, true, $this->wp_repair_service() );

		$this->assertSame( 'reassigned_no_delete', $row['status'] );
		$this->assertSame( array( (int) $melbourne['term_id'] ), $this->event_locations( $event_one ) );
		$this->assertContains( 'Salford', array_map(
			static fn( int $term_id ): string => (string) get_term( $term_id, 'location' )->name,
			$this->event_locations( $event_two )
		) );
		$this->assertNotNull( get_term( (int) $root['term_id'], 'location' ), 'Multi-canonical roots are emptied, not deleted.' );
		$this->assertSame( 0, (int) get_term( (int) $root['term_id'], 'location' )->count, 'No event may remain on the polluted root.' );
	}

	/** A root whose events cannot resolve is reported, never mutated. */
	public function test_unresolved_events_block_deletion(): void {
		$root  = wp_insert_term( 'The Baby G', 'location' );
		$venue = $this->create_venue( 'The Baby G', 'The Baby G', '', 'Canada' );
		$event = $this->create_event( 'Toronto show', $venue, (int) $root['term_id'] );

		$command = new ReconcileRootLocationsCommand();
		$terms   = get_terms( array(
			'taxonomy'   => 'location',
			'hide_empty' => false,
			'number'     => 0,
		) );

		$row = $command->polluted_root_row( get_term( (int) $root['term_id'], 'location' ), $terms, true, $this->wp_repair_service() );

		$this->assertSame( 'skipped', $row['status'] );
		$this->assertStringContainsString( 'unresolved_events_block_deletion', $row['reason'] );
		$this->assertSame( array( (int) $root['term_id'] ), $this->event_locations( $event ) );
		$this->assertNotNull( get_term( (int) $root['term_id'], 'location' ) );
	}

	/** Structural roots and parent nodes are never classified as polluted. */
	public function test_structural_roots_are_not_candidates(): void {
		wp_insert_term( 'Europe', 'location' );
		$canada = wp_insert_term( 'Canada', 'location' );
		$quebec = wp_insert_term( 'Québec', 'location', array( 'parent' => (int) $canada['term_id'] ) );

		$command = new ReconcileRootLocationsCommand();
		$terms   = get_terms( array(
			'taxonomy'   => 'location',
			'hide_empty' => false,
			'number'     => 0,
		) );

		$this->assertNull( $command->polluted_root_row( get_term( (int) $canada['term_id'], 'location' ), $terms, false, null ) );
		$this->assertNull( $command->polluted_root_row( get_term( (int) $quebec['term_id'], 'location' ), $terms, false, null ) );
	}
}
