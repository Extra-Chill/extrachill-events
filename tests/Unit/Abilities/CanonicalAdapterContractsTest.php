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
		$GLOBALS['ec_adapter_relationships'][123] = array(
			'venue'    => array( new WP_Term( 44, 'The Royal American', 'the-royal-american' ) ),
			'artist'   => array( new WP_Term( 51, 'Kid Lake', 'kid-lake' ) ),
			'location' => array( new WP_Term( 52, 'Charleston', 'charleston-sc' ) ),
			'promoter' => array( new WP_Term( 53, 'Extra Chill', 'extra-chill' ) ),
		);
		$canonical_event = array(
			'post_id'         => 123,
			'title'           => 'Kid Lake at The Royal American',
			'event_data'      => array(
				'startDate'      => '2026-08-08',
				'startTime'      => '20:00:00',
				'endDate'        => '2026-08-09',
				'endTime'        => '00:30:00',
				'performer'      => 'Kid Lake',
				'performerType'  => 'MusicGroup',
				'organizer'      => 'Extra Chill',
				'organizerUrl'   => 'https://extrachill.com',
				'organizerType'  => 'promoter',
				'address'        => '970 Morrison Dr, Charleston, SC 29403',
				'venueTimezone'  => 'America/New_York',
				'eventStatus'    => 'EventRescheduled',
				'ticketUrl'      => 'https://tickets.example/show',
			),
			'display_context' => array(
				'is_multi_day'    => true,
				'is_continuation' => false,
				'day_number'      => 1,
				'total_days'      => 2,
			),
		);

		$adapted = extrachill_events_transform_calendar_response(
			array(
				'paged_date_groups' => array(
					array(
						'date'   => '2026-08-08',
						'events' => array( $canonical_event ),
					),
				),
				'total_event_count' => 1,
				'current_page'      => 1,
				'max_pages'         => 1,
			)
		);
		$event   = $adapted['dates'][0]['events'][0];

		$this->assertSame( $canonical_event['event_data']['performer'], $event['performer']['name'] );
		$this->assertSame( $canonical_event['event_data']['performerType'], $event['performer']['type'] );
		$this->assertSame( $canonical_event['event_data']['eventStatus'], $event['status'] );
		$this->assertSame( $canonical_event['display_context'], $event['occurrence_context'] );
		$this->assertSame( 'kid-lake', $event['taxonomies']['artist'][0]['slug'] );
		$this->assertSame( 'charleston-sc', $event['taxonomies']['location'][0]['slug'] );
		$this->assertSame( 'extra-chill', $event['taxonomies']['promoter'][0]['slug'] );
		$this->assertSame( $GLOBALS['ec_adapter_venues'][44]['coordinates'], $event['venue']['coordinates'] );
		$this->assertSame( $GLOBALS['ec_adapter_venues'][44]['timezone'], $event['venue']['timezone'] );

		// Existing API callers retain their original transport fields.
		foreach ( array( 'id', 'title', 'datetime', 'end_datetime', 'venue', 'ticket_url', 'permalink' ) as $field ) {
			$this->assertArrayHasKey( $field, $event );
		}
	}
}
