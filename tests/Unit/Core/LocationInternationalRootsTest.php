<?php
/**
 * International venue location resolution and root pollution (#854).
 *
 * @package ExtraChillEvents\Tests\Unit\Core
 */

namespace ExtraChillEvents\Tests\Unit\Core;

use WP_UnitTestCase;

/**
 * @covers ::extrachill_events_resolve_location_term_for_venue_city
 * @covers ::extrachill_events_create_location_term_from_venue
 * @covers ::extrachill_events_validate_city_in_country
 * @covers ::extrachill_events_filter_locations_by_hierarchy
 * @covers ::extrachill_events_is_structural_location_root_name
 */
final class LocationInternationalRootsTest extends WP_UnitTestCase {

	public function setUp(): void {
		parent::setUp();
		if ( ! taxonomy_exists( 'location' ) ) {
			register_taxonomy( 'location', 'data_machine_events', array( 'hierarchical' => true ) );
		}
		extrachill_events_get_location_terms_by_name( true );
		add_filter( 'extrachill_events_geonames_username', static fn(): string => 'test-user' );
		delete_transient( $this->city_check_key( 'Hamburg', 'DE' ) );
		delete_transient( $this->city_check_key( 'Barbanegra', 'ES' ) );
		delete_transient( $this->city_check_key( 'Vadstena', 'SE' ) );
	}

	public function tearDown(): void {
		remove_filter( 'extrachill_events_geonames_username', static fn(): string => 'test-user' );
		remove_all_filters( 'pre_http_request' );
		parent::tearDown();
	}

	/** Build the transient key used by the GeoNames validation cache. */
	private function city_check_key( string $city, string $iso2 ): string {
		return 'ec_events_city_check_' . md5( extrachill_events_location_identity_key( $city ) . '|' . $iso2 );
	}

	/** Mock a GeoNames searchJSON response listing the given place names. */
	private function mock_geonames( array $names, int $status = 200 ): void {
		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) use ( $names, $status ) {
				unset( $preempt, $args );
				if ( ! is_string( $url ) || false === strpos( $url, 'searchJSON' ) ) {
					return false;
				}

				parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $query );
				$requested = (string) ( $query['name_startsWith'] ?? '' );
				// GeoNames search is diacritic-insensitive; mirror that by
				// comparing accent-folded identities.
				$requested_key = extrachill_events_location_identity_key( $requested );
				$hit           = '';
				foreach ( $names as $name ) {
					if ( extrachill_events_location_identity_key( $name ) === $requested_key ) {
						$hit = $name;
						break;
					}
				}
				$hits = '' === $hit ? array() : array( array( 'name' => $hit ) );

				return array(
					'response' => array( 'code' => $status ),
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

	/** A German venue with country metadata nests under its country, never at root. */
	public function test_german_city_nests_under_country_not_root(): void {
		$this->mock_geonames( array( 'Hamburg' ) );

		wp_insert_term( 'Europe', 'location' );
		$root = wp_insert_term( 'Hamburg', 'location' );
		$this->assertNotWPError( $root );

		// "DE" matches the production venue meta for Hamburg venues.
		$city = extrachill_events_resolve_location_term_for_venue_city( 'Hamburg', '', '20359', 'DE', true );

		$this->assertInstanceOf( \WP_Term::class, $city );
		$this->assertNotSame( (int) $root['term_id'], (int) $city->term_id, 'The root Hamburg term must not capture German events.' );

		$country = get_term( $city->parent, 'location' );
		$this->assertInstanceOf( \WP_Term::class, $country );
		$this->assertSame( 'Germany', $country->name );

		$continent = get_term( $country->parent, 'location' );
		$this->assertInstanceOf( \WP_Term::class, $continent );
		$this->assertSame( 'Europe', $continent->name );

		// Once the canonical term exists, resolution matches it directly
		// through the hierarchy filter instead of the create path.
		$again = extrachill_events_resolve_location_term_for_venue_city( 'Hamburg', '', '20359', 'Germany', true );
		$this->assertSame( (int) $city->term_id, (int) $again->term_id );
	}

	/** The hierarchy filter accepts Continent > Country > City trees. */
	public function test_hierarchy_filter_accepts_country_parent(): void {
		$europe  = wp_insert_term( 'Europe', 'location' );
		$germany = wp_insert_term( 'Germany', 'location', array( 'parent' => (int) $europe['term_id'] ) );
		$hamburg = wp_insert_term( 'Hamburg', 'location', array( 'parent' => (int) $germany['term_id'] ) );

		$matches  = array( get_term( (int) $hamburg['term_id'], 'location' ) );
		$filtered = extrachill_events_filter_locations_by_hierarchy( $matches, '', 'DE' );

		$this->assertCount( 1, $filtered );
		$this->assertSame( (int) $hamburg['term_id'], (int) $filtered[0]->term_id );
	}

	/** The hierarchy filter still rejects root-level terms with country context. */
	public function test_hierarchy_filter_rejects_root_term(): void {
		$root    = wp_insert_term( 'Hamburg', 'location' );
		$matches = array( get_term( (int) $root['term_id'], 'location' ) );

		$this->assertSame( array(), extrachill_events_filter_locations_by_hierarchy( $matches, '', 'DE' ) );
		$this->assertSame( array(), extrachill_events_filter_locations_by_hierarchy( $matches, 'NY', 'US' ) );
	}

	/** A venue-named candidate absent from GeoNames is rejected. */
	public function test_venue_name_without_geonames_place_is_rejected(): void {
		$this->mock_geonames( array() );

		$before = wp_count_terms( array(
			'taxonomy'   => 'location',
			'hide_empty' => false,
		) );

		$this->assertNull( extrachill_events_resolve_location_term_for_venue_city( 'Barbanegra', '', '', 'Spain', true ) );
		$this->assertNull( extrachill_events_resolve_location_term_for_venue_city( 'Chalet du Lac', '', '', 'France', true ) );

		$this->assertSame( $before, wp_count_terms( array(
			'taxonomy'   => 'location',
			'hide_empty' => false,
		) ) );
		$this->assertSame( '0', get_transient( $this->city_check_key( 'Barbanegra', 'ES' ) ), 'Negative validation must be cached.' );
	}

	/** A city that cannot be validated fails closed instead of creating a root term. */
	public function test_unvalidatable_city_fails_closed(): void {
		$this->mock_geonames( array( 'Vadstena' ), 500 );

		$before = wp_count_terms( array(
			'taxonomy'   => 'location',
			'hide_empty' => false,
		) );

		$this->assertNull( extrachill_events_resolve_location_term_for_venue_city( 'Vadstena', '', '', 'Sweden', true ) );
		$this->assertSame( $before, wp_count_terms( array(
			'taxonomy'   => 'location',
			'hide_empty' => false,
		) ) );
	}

	/** No GeoNames username means no validation, and no term creation. */
	public function test_missing_username_fails_closed(): void {
		remove_filter( 'extrachill_events_geonames_username', static fn(): string => 'test-user' );
		add_filter( 'extrachill_events_geonames_username', '__return_empty_string' );

		$http_calls = 0;
		add_filter(
			'pre_http_request',
			static function ( $preempt, $args, $url ) use ( &$http_calls ) {
				unset( $preempt, $args );
				if ( is_string( $url ) && false !== strpos( $url, 'searchJSON' ) ) {
					++$http_calls;
				}
				return false;
			},
			10,
			3
		);

		$this->assertNull( extrachill_events_validate_city_in_country( 'Hamburg', 'Germany' ) );
		$this->assertSame( 0, $http_calls, 'No username, no HTTP request.' );

		$before = wp_count_terms( array(
			'taxonomy'   => 'location',
			'hide_empty' => false,
		) );
		$this->assertNull( extrachill_events_resolve_location_term_for_venue_city( 'Hamburg', '', '20359', 'Germany', true ) );
		$this->assertSame( $before, wp_count_terms( array(
			'taxonomy'   => 'location',
			'hide_empty' => false,
		) ) );
	}

	/** Validation results are cached so repeat passes skip the API. */
	public function test_validation_outcome_is_cached(): void {
		$this->mock_geonames( array( 'Hamburg' ) );

		$this->assertTrue( extrachill_events_validate_city_in_country( 'Hamburg', 'Germany' ) );
		$this->assertSame( '1', get_transient( $this->city_check_key( 'Hamburg', 'DE' ) ) );

		// A later pass that would answer "absent" must still hit the cache.
		$this->mock_geonames( array() );
		$this->assertTrue( extrachill_events_validate_city_in_country( 'Hamburg', 'Germany' ) );
	}

	/** Accent-folded comparison validates accented place names. */
	public function test_validation_folds_accents(): void {
		$this->mock_geonames( array( 'München' ) );

		$this->assertTrue( extrachill_events_validate_city_in_country( 'Munchen', 'Germany' ) );
		$this->assertTrue( extrachill_events_validate_city_in_country( 'München', 'Germany' ) );
	}

	/** Endonym and typo country values normalize to canonical identities. */
	public function test_country_endonyms_and_typos_normalize(): void {
		$this->assertSame( 'germany', extrachill_events_normalize_country_name( 'Deutschland' ) );
		$this->assertSame( 'united states', extrachill_events_normalize_country_name( 'Unites States' ) );
		$this->assertSame( 'netherlands', extrachill_events_normalize_country_name( 'Nederland' ) );
		$this->assertSame( 'spain', extrachill_events_normalize_country_name( 'España' ) );
		$this->assertSame( 'canada', extrachill_events_normalize_country_name( 'Canadá' ) );
	}

	/** Structural root detection separates country trees from polluted roots. */
	public function test_structural_root_names(): void {
		$this->assertTrue( extrachill_events_is_structural_location_root_name( 'Europe' ) );
		$this->assertTrue( extrachill_events_is_structural_location_root_name( 'South America' ) );
		$this->assertTrue( extrachill_events_is_structural_location_root_name( 'Germany' ) );
		$this->assertTrue( extrachill_events_is_structural_location_root_name( 'United States' ) );
		$this->assertTrue( extrachill_events_is_structural_location_root_name( 'Brasil' ) );

		$this->assertFalse( extrachill_events_is_structural_location_root_name( 'Hamburg' ) );
		$this->assertFalse( extrachill_events_is_structural_location_root_name( 'The Abbey' ) );
		$this->assertFalse( extrachill_events_is_structural_location_root_name( 'YES' ) );
	}
}
