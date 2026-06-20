<?php
/**
 * Location market-map smoke test.
 *
 * Self-contained (no PHPUnit / WP test framework). Verifies that
 * extrachill_events_get_market_slug_for_venue() rolls metro-suburb venue
 * cities up to the correct discovery market. Guards the Houston/Dallas
 * metro mappings added for extrachill-events#... (data-machine-events#379):
 * a 50mi city-pipeline ingest radius was stamping every metro event with the
 * pipeline-center city term (e.g. Galveston) because suburbs like Sugar Land,
 * Pasadena TX, Pearland, Humble, Texas City, Lake Jackson had no market
 * mapping and no location term, so the venue-city resolver fell back to the
 * flow-config term.
 *
 * Run directly:
 *   php tests/location-market-map-smoke.php
 *
 * @package ExtraChillEvents\Tests
 */

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

// Minimal WP shims used while loading the normalizer file. Only the market-map
// resolver path is exercised here, which performs no WP lookups.
if ( ! function_exists( 'add_action' ) ) {
	function add_action() {}
}
if ( ! function_exists( 'get_term_by' ) ) {
	function get_term_by() {
		return false;
	}
}
if ( ! function_exists( 'get_term' ) ) {
	function get_term() {
		return null;
	}
}
if ( ! function_exists( 'get_terms' ) ) {
	function get_terms() {
		return array();
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $thing ) {
		return false;
	}
}

require_once dirname( __DIR__ ) . '/inc/core/location-normalizer.php';

$cases = array(
	// [ city, state, zip, expected market slug ]
	array( 'Sugar Land', 'TX', '77478', 'houston' ),
	array( 'Pasadena', 'TX', '77506', 'houston' ),
	array( 'Pasadena', 'CA', '91101', 'los-angeles' ),
	array( 'Pearland', 'TX', '77581', 'houston' ),
	array( 'Humble', 'TX', '77338', 'houston' ),
	array( 'Texas City', 'TX', '77590', 'houston' ),
	array( 'Lake Jackson', 'TX', '77566', 'houston' ),
	array( 'Conroe', 'TX', '77301', 'houston' ),
	array( 'Katy', 'TX', '77449', 'houston' ),
	array( 'Spring', 'TX', '77373', 'houston' ),
	array( 'The Woodlands', 'TX', '77380', 'houston' ),
	array( 'Carrollton', 'TX', '75006', 'dallas' ),
	array( 'Fort Worth', 'TX', '76102', 'dallas' ),
	array( 'Ft Worth', 'TX', '76102', 'dallas' ),
	// Galveston itself has its own location term — the market map must NOT
	// roll it up; null lets the exact-city-name match resolve it.
	array( 'Galveston', 'TX', '77550', null ),
	// Existing mappings must keep working.
	array( 'Cambridge', 'MA', '02139', 'boston' ),
	array( 'North Charleston', 'SC', '29405', 'charleston' ),
);

$pass = 0;
$fail = 0;

foreach ( $cases as $case ) {
	list( $city, $state, $zip, $expected ) = $case;
	$got = extrachill_events_get_market_slug_for_venue( $city, $state, $zip );

	if ( $got === $expected ) {
		++$pass;
	} else {
		++$fail;
		printf(
			"FAIL: %s, %s (%s) -> %s, expected %s\n",
			$city,
			$state,
			$zip,
			var_export( $got, true ),
			var_export( $expected, true )
		);
	}
}

printf( "\n%d passed, %d failed\n", $pass, $fail );

exit( $fail > 0 ? 1 : 0 );
