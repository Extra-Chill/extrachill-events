<?php
/**
 * Canonical venue-city location resolution smoke test.
 *
 * Run directly: php tests/location-resolution-smoke.php
 *
 * @package ExtraChillEvents\Tests
 */

// phpcs:disable -- Isolated test doubles intentionally mirror WordPress globals.

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

if ( function_exists( 'wp_insert_post' ) ) {
	$test_suffix = wp_generate_uuid4();
	$city_name   = 'Location Smoke ' . $test_suffix;
	$venue_name  = 'Venue Smoke ' . $test_suffix;

	$city = wp_insert_term( $city_name, 'location' );
	$stale = wp_insert_term( 'Stale Location ' . $test_suffix, 'location' );
	$venue = wp_insert_term( $venue_name, 'venue' );
	$post_id = wp_insert_post(
		array(
			'post_title'  => 'Location Smoke Event ' . $test_suffix,
			'post_type'   => 'data_machine_events',
			'post_status' => 'publish',
		)
	);

	$failures = 0;
	if ( is_wp_error( $city ) || is_wp_error( $stale ) || is_wp_error( $venue ) || is_wp_error( $post_id ) || ! $post_id ) {
		$failures = 1;
		printf( "FAIL: unable to create WordPress integration fixtures\n" );
	} else {
		$city_id  = (int) $city['term_id'];
		$stale_id = (int) $stale['term_id'];
		$venue_id = (int) $venue['term_id'];

		update_term_meta( $venue_id, '_venue_city', $city_name );
		wp_set_object_terms( $post_id, array( $venue_id ), 'venue', false );
		wp_set_object_terms( $post_id, array( $city_id, $stale_id ), 'location', false );

		extrachill_events_normalize_location( $post_id );
		$locations = wp_get_object_terms( $post_id, 'location', array( 'fields' => 'ids' ) );
		if ( array( $city_id ) !== array_map( 'intval', $locations ) ) {
			$failures = 1;
			printf( "FAIL: WordPress integration must replace conflicting markets with the canonical market\n" );
		}
	}

	if ( $post_id && ! is_wp_error( $post_id ) ) {
		wp_delete_post( $post_id, true );
	}
	foreach ( array( $venue, $city, $stale ) as $term ) {
		if ( ! is_wp_error( $term ) ) {
			$taxonomy = $term === $venue ? 'venue' : 'location';
			wp_delete_term( (int) $term['term_id'], $taxonomy );
		}
	}

	printf( "%d passed, %d failed\n", 1 - $failures, $failures );
	exit( $failures > 0 ? 1 : 0 );
}

if ( ! class_exists( 'WP_Term' ) ) {
	class WP_Term {
		public int $term_id;
		public string $name;
		public string $slug;
		public int $parent;

		public function __construct( int $term_id, string $name, string $slug, int $parent = 0 ) {
			$this->term_id = $term_id;
			$this->name    = $name;
			$this->slug    = $slug;
			$this->parent  = $parent;
		}
	}
}

$GLOBALS['ec_resolution_terms'] = array(
	1  => new WP_Term( 1, 'USA', 'usa' ),
	2  => new WP_Term( 2, 'North Carolina', 'north-carolina', 1 ),
	3  => new WP_Term( 3, 'Delaware', 'delaware', 1 ),
	4  => new WP_Term( 4, 'Canada', 'canada' ),
	5  => new WP_Term( 5, 'Ontario', 'ontario', 4 ),
	6  => new WP_Term( 6, 'United Kingdom', 'united-kingdom' ),
	7  => new WP_Term( 7, 'England', 'england', 6 ),
	10 => new WP_Term( 10, 'Wilmington', 'wilmington-nc', 2 ),
	11 => new WP_Term( 11, 'Wilmington', 'wilmington-de', 3 ),
	12 => new WP_Term( 12, 'London', 'london-on', 5 ),
	13 => new WP_Term( 13, 'London', 'london-uk', 7 ),
	14 => new WP_Term( 14, 'Oxford', 'oxford-uk', 7 ),
	15 => new WP_Term( 15, 'Phoenix', 'phoenix' ),
);
$GLOBALS['ec_resolution_venue']       = new WP_Term( 20, 'Resolution Venue', 'resolution-venue' );
$GLOBALS['ec_resolution_locations']   = array( $GLOBALS['ec_resolution_terms'][10] );
$GLOBALS['ec_resolution_assignments'] = array();
$GLOBALS['ec_resolution_meta']        = array(
	'_venue_city'    => 'Wilmington',
	'_venue_state'   => 'NC',
	'_venue_zip'     => '28401',
	'_venue_country' => 'US',
);

function add_action() {}
function is_wp_error() {
	return false;
}
function wp_is_post_revision() {
	return false;
}
function wp_is_post_autosave() {
	return false;
}
function get_the_terms( $post_id, $taxonomy ) {
	return 'venue' === $taxonomy
		? array( $GLOBALS['ec_resolution_venue'] )
		: $GLOBALS['ec_resolution_locations'];
}
function get_term_meta( $term_id, $key ) {
	return $GLOBALS['ec_resolution_meta'][ $key ] ?? '';
}
function wp_set_object_terms( $post_id, $terms, $taxonomy, $append = false ) {
	$GLOBALS['ec_resolution_assignments'][] = compact( 'post_id', 'terms', 'taxonomy', 'append' );
	return $terms;
}
function get_term_by( $field, $value ) {
	foreach ( $GLOBALS['ec_resolution_terms'] as $term ) {
		if ( isset( $term->{$field} ) && $term->{$field} === $value ) {
			return $term;
		}
	}
	return false;
}
function get_term( $term_id ) {
	return $GLOBALS['ec_resolution_terms'][ $term_id ] ?? null;
}
function get_terms() {
	return array_values( $GLOBALS['ec_resolution_terms'] );
}

require_once dirname( __DIR__ ) . '/inc/core/location-normalizer.php';
require_once dirname( __DIR__ ) . '/inc/Abilities/EventLocationAlignmentAbilities.php';

$cases = array(
	array( 'Wilmington', 'NC', '', 'US', 10, 'state abbreviation selects North Carolina' ),
	array( 'Wilmington', 'North Carolina', '', 'United States', 10, 'full state name selects North Carolina' ),
	array( 'Wilmington', 'DE', '', 'USA', 11, 'Delaware remains independently resolvable' ),
	array( 'Wilmington', '', '', 'US', null, 'country alone leaves duplicate US cities unresolved' ),
	array( 'Wilmington', 'PA', '', 'US', null, 'conflicting state fails safely' ),
	array( 'London', '', '', 'Canada', 12, 'country context selects Canada hierarchy' ),
	array( 'London', '', '', 'GB', 13, 'country code selects United Kingdom hierarchy' ),
	array( 'Oxford', 'Mississippi', '', 'US', null, 'sole city match rejects conflicting hierarchy' ),
);

$failures = 0;
foreach ( $cases as $case ) {
	list( $city, $state, $zip, $country, $expected_id, $label ) = $case;
	$resolved = extrachill_events_resolve_location_term_for_venue_city( $city, $state, $zip, $country );
	$actual   = $resolved instanceof WP_Term ? $resolved->term_id : null;
	if ( $expected_id !== $actual ) {
		++$failures;
		printf( "FAIL: %s: got %s, expected %s\n", $label, var_export( $actual, true ), var_export( $expected_id, true ) );
	}
}

if ( 'philadelphia' !== extrachill_events_get_market_slug_for_venue( 'Wilmington', 'DE', '', 'US' ) ) {
	++$failures;
	printf( "FAIL: Wilmington, DE must retain the Philadelphia market rollup\n" );
}
if ( null !== extrachill_events_get_market_slug_for_venue( 'Wilmington', 'NC', '', 'US' ) ) {
	++$failures;
	printf( "FAIL: Wilmington, NC must not roll up to Philadelphia\n" );
}

extrachill_events_normalize_location( 150479 );
if ( array() !== $GLOBALS['ec_resolution_assignments'] ) {
	++$failures;
	printf( "FAIL: an already-correct canonical assignment must not be rewritten\n" );
}

// Simulate a canonical event encountered by another city pipeline: the new
// expected market is present, but the stale market from the prior flow remains.
$GLOBALS['ec_resolution_locations'] = array( $GLOBALS['ec_resolution_terms'][10], $GLOBALS['ec_resolution_terms'][15] );
extrachill_events_normalize_location( 150479 );
$replacement = end( $GLOBALS['ec_resolution_assignments'] );
if ( array( 10 ) !== ( $replacement['terms'] ?? null ) || true === ( $replacement['append'] ?? true ) ) {
	++$failures;
	printf( "FAIL: cross-pipeline normalization must replace all conflicting markets with the canonical market\n" );
}

$ability = new ExtraChillEvents\Abilities\EventLocationAlignmentAbilities();
$method  = new ReflectionMethod( $ability, 'resolveExpectedLocationTerm' );
$result  = $method->invoke( $ability, 'Wilmington', '', '', 'US', $GLOBALS['ec_resolution_terms'][11] );
if ( null !== $result['term'] || 'ambiguous_location_hierarchy' !== $result['reason'] ) {
	++$failures;
	printf( "FAIL: ambiguous hierarchy must not fall back to a flow term in audit/fix mode\n" );
}

printf( "%d passed, %d failed\n", count( $cases ) + 5 - $failures, $failures );
exit( $failures > 0 ? 1 : 0 );
