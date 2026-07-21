<?php
/**
 * Canonical event and venue adapter contract tests.
 *
 * @package ExtraChillEvents\Tests
 */

// phpcs:disable -- This isolated fixture intentionally declares WordPress test doubles.

use PHPUnit\Framework\TestCase;

if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {}
}

if ( ! class_exists( 'WP_Term' ) ) {
	class WP_Term {
		public int $term_id;
		public string $name;
		public string $slug;

		public function __construct( int $term_id, string $name, string $slug ) {
			$this->term_id = $term_id;
			$this->name    = $name;
			$this->slug    = $slug;
		}
	}
}

if ( ! function_exists( 'add_action' ) ) {
	function add_action() {}
}
if ( ! function_exists( '__' ) ) {
	function __( $text ) {
		return $text;
	}
}
if ( ! function_exists( 'absint' ) ) {
	function absint( $value ) {
		return abs( (int) $value );
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $value ) {
		return $value instanceof WP_Error;
	}
}
if ( ! function_exists( 'wp_get_ability' ) ) {
	function wp_get_ability( $name ) {
		return $GLOBALS['ec_adapter_abilities'][ $name ] ?? null;
	}
}
if ( ! function_exists( 'update_termmeta_cache' ) ) {
	function update_termmeta_cache( $term_ids ) {
		$GLOBALS['ec_adapter_primed_ids'] = $term_ids;
	}
}
if ( ! function_exists( 'data_machine_events_get_venue_data' ) ) {
	function data_machine_events_get_venue_data( int $term_id ): ?array {
		$GLOBALS['ec_adapter_venue_reads'][] = array(
			'term_id' => $term_id,
			'primed'  => in_array( $term_id, $GLOBALS['ec_adapter_primed_ids'] ?? array(), true ),
		);

		return $GLOBALS['ec_adapter_venues'][ $term_id ] ?? null;
	}
}
if ( ! function_exists( 'wp_get_post_terms' ) ) {
	function wp_get_post_terms( $post_id, $taxonomy ) {
		return $GLOBALS['ec_adapter_relationships'][ $post_id ][ $taxonomy ] ?? array();
	}
}
if ( ! function_exists( 'get_term_link' ) ) {
	function get_term_link( $term, $taxonomy = '' ) {
		return 'https://events.example/' . $taxonomy . '/' . $term->slug . '/';
	}
}
if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta() {
		return '';
	}
}
if ( ! function_exists( 'get_permalink' ) ) {
	function get_permalink( $post_id ) {
		return 'https://events.example/events/' . $post_id . '/';
	}
}

require_once dirname( __DIR__, 3 ) . '/inc/abilities/events-get-venue.php';
require_once dirname( __DIR__, 3 ) . '/inc/abilities/events-list-venues.php';
require_once dirname( __DIR__, 3 ) . '/inc/abilities/events-check-venue-duplicate.php';
require_once dirname( __DIR__, 3 ) . '/inc/abilities/events-calendar.php';

final class CanonicalAdapterContractsTest extends TestCase {
	protected function setUp(): void {
		parent::setUp();

		$GLOBALS['ec_adapter_abilities']     = array();
		$GLOBALS['ec_adapter_primed_ids']    = array();
		$GLOBALS['ec_adapter_venue_reads']   = array();
		$GLOBALS['ec_adapter_relationships'] = array();
		$GLOBALS['ec_adapter_venues']        = array(
			44 => array(
				'term_id'     => 44,
				'name'        => 'The Royal American',
				'slug'        => 'the-royal-american',
				'description' => 'Neighborhood music venue.',
				'address'     => '970 Morrison Dr',
				'city'        => 'Charleston',
				'state'       => 'SC',
				'zip'         => '29403',
				'country'     => 'US',
				'coordinates' => '32.8007,-79.9362',
				'timezone'    => 'America/New_York',
				'website'     => 'https://theroyalamerican.com',
				'phone'       => '843-817-6925',
				'capacity'    => '250',
			),
		);
	}

	public function test_venue_list_primes_metadata_and_matches_detail_identity_fields(): void {
		$list = extrachill_events_transform_venue_list(
			array(
				array(
					'term_id'     => 44,
					'name'        => 'The Royal American',
					'slug'        => 'the-royal-american',
					'lat'         => 32.8007,
					'lon'         => -79.9362,
					'address'     => '970 Morrison Dr, Charleston, SC, 29403',
					'url'         => 'https://events.example/venue/the-royal-american/',
					'event_count' => 7,
				),
			)
		);

		$detail = extrachill_events_ability_get_venue( array( 'id' => 44 ) );

		$this->assertTrue( $GLOBALS['ec_adapter_venue_reads'][0]['primed'] );
		foreach ( array( 'id', 'name', 'slug', 'address', 'city', 'state', 'zip', 'country', 'latitude', 'longitude', 'coordinates', 'timezone', 'website' ) as $field ) {
			$this->assertSame( $detail[ $field ], $list[0][ $field ], $field );
		}
		$this->assertSame( '970 Morrison Dr, Charleston, SC, 29403', $list[0]['formatted_address'] );
		$this->assertSame( 7, $list[0]['event_count'] );
	}

	public function test_duplicate_check_delegates_all_identity_evidence_and_returns_canonical_venue(): void {
		$resolver = function ( string $name, array $venue_data ): array {
			$GLOBALS['ec_adapter_duplicate_input'] = array_merge( array( 'name' => $name ), $venue_data );

			return array(
				'term_id'      => 44,
				'match_status' => 'matched',
			);
		};

		$result = extrachill_events_find_duplicate_venues(
			array(
				'name'    => 'Royal American',
				'address' => '970 Morrison Dr',
				'city'    => 'Charleston',
				'state'   => 'SC',
				'country' => 'US',
			),
			$resolver
		);

		$this->assertSame(
			array(
				'name'    => 'Royal American',
				'address' => '970 Morrison Dr',
				'city'    => 'Charleston',
				'state'   => 'SC',
				'country' => 'US',
			),
			$GLOBALS['ec_adapter_duplicate_input']
		);
		$this->assertSame( 44, $result[0]['id'] );
		$this->assertSame( 'America/New_York', $result[0]['timezone'] );
		$this->assertSame(
			array(),
			extrachill_events_find_duplicate_venues(
				array( 'name' => 'The Foundry', 'city' => 'Atlanta' ),
				static fn() => array(
					'term_id'      => null,
					'match_status' => 'ambiguous',
				)
			)
		);
	}

	public function test_calendar_adapter_preserves_canonical_event_and_occurrence_contracts(): void {
		$fixture = json_decode(
			(string) file_get_contents( dirname( __DIR__, 2 ) . '/Fixtures/dme-calendar-page-v0.49.4.json' ),
			true,
			512,
			JSON_THROW_ON_ERROR
		);
		$this->assertSame( 'data-machine-events/get-calendar-page', $fixture['provenance']['producer'] );

		$canonical_event = $fixture['payload']['paged_date_groups'][0]['events'][0];
		$post_id         = $canonical_event['post_id'];
		foreach ( $fixture['canonical_context']['taxonomies'] as $taxonomy => $terms ) {
			$GLOBALS['ec_adapter_relationships'][ $post_id ][ $taxonomy ] = array_map(
				static fn( $term ) => new WP_Term( $term['term_id'], $term['name'], $term['slug'] ),
				$terms
			);
		}
		$canonical_venue                                       = $fixture['canonical_context']['venue'];
		$GLOBALS['ec_adapter_venues'][ $canonical_venue['term_id'] ] = $canonical_venue;

		$adapted = extrachill_events_transform_calendar_response( $fixture['payload'] );
		$event   = $adapted['dates'][0]['events'][0];

		$this->assertSame( $canonical_event['event_data']['performer'], $event['performer']['name'] );
		$this->assertSame( $canonical_event['event_data']['performerType'], $event['performer']['type'] );
		$this->assertSame( $canonical_event['event_data']['organizer'], $event['organizer']['name'] );
		$this->assertSame( $canonical_event['event_data']['organizerType'], $event['organizer']['type'] );
		$this->assertSame( $canonical_event['event_data']['eventStatus'], $event['status'] );
		$this->assertSame( $canonical_event['display_context'], $event['occurrence_context'] );
		foreach ( array( 'artist', 'location', 'promoter' ) as $taxonomy ) {
			$this->assertSame(
				array_column( $fixture['canonical_context']['taxonomies'][ $taxonomy ], 'slug' ),
				array_column( $event['taxonomies'][ $taxonomy ], 'slug' )
			);
		}
		foreach ( array( 'address', 'city', 'state', 'zip', 'country', 'coordinates', 'timezone' ) as $field ) {
			$this->assertSame( $canonical_venue[ $field ], $event['venue'][ $field ], $field );
		}

		// Existing API callers retain their original transport fields.
		foreach ( array( 'id', 'title', 'datetime', 'end_datetime', 'venue', 'ticket_url', 'permalink' ) as $field ) {
			$this->assertArrayHasKey( $field, $event );
		}
	}
}
