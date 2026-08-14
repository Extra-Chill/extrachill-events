<?php
/**
 * Events breadcrumb integration tests.
 *
 * @package ExtraChillEvents\Tests
 */

use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'ec_is_events_site' ) ) {
	/** Return the Events-site context for this fixture. */
	function ec_is_events_site() {
		return true;
	}
}

if ( ! function_exists( 'get_query_var' ) ) {
	/**
	 * Return a query variable from the test request.
	 *
	 * @param string $name          Query variable name.
	 * @param mixed  $default_value Default value.
	 * @return mixed
	 */
	function get_query_var( $name, $default_value = '' ) {
		return $GLOBALS['test_query_vars'][ $name ] ?? $default_value;
	}
}

if ( ! function_exists( 'is_front_page' ) ) {
	/** Router breadcrumb tests never represent the front page. */
	function is_front_page() {
		return false;
	}
}

if ( ! function_exists( 'is_tax' ) ) {
	/** Return the fixture's taxonomy condition. */
	function is_tax() {
		return (bool) ( $GLOBALS['test_is_tax'] ?? false );
	}
}

if ( ! function_exists( 'get_queried_object' ) ) {
	/** Return the fixture's queried taxonomy term. */
	function get_queried_object() {
		return $GLOBALS['test_queried_term'] ?? null;
	}
}

if ( ! function_exists( 'is_post_type_archive' ) ) {
	/** Router breadcrumb tests never represent the post type archive. */
	function is_post_type_archive() {
		return false;
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	/**
	 * Escape fixture output as HTML.
	 *
	 * @param mixed $value Value to escape.
	 * @return string
	 */
	function esc_html( $value ) {
		return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	/**
	 * Return escaped, untranslated fixture text.
	 *
	 * @param mixed $value Value to escape.
	 * @return string
	 */
	function esc_html__( $value ) {
		return esc_html( $value );
	}
}

require_once dirname( __DIR__, 3 ) . '/inc/core/router-pages.php';
require_once dirname( __DIR__, 3 ) . '/inc/single-event/breadcrumbs.php';

// phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed -- Focused WordPress function doubles live with their tests.
/** Verifies breadcrumb labels for Events archives and virtual routes. */
final class BreadcrumbsTest extends TestCase {
	/** Reset request state before each test. */
	protected function setUp(): void {
		parent::setUp();
		$GLOBALS['test_query_vars']   = array();
		$GLOBALS['test_is_tax']       = false;
		$GLOBALS['test_queried_term'] = null;
	}

	/** Remove request state after each test. */
	protected function tearDown(): void {
		unset( $GLOBALS['test_query_vars'], $GLOBALS['test_is_tax'], $GLOBALS['test_queried_term'] );
		parent::tearDown();
	}

	/** The location directory identifies its destination. */
	public function test_location_directory_has_destination_specific_breadcrumb(): void {
		$GLOBALS['test_query_vars']['ec_events_location_index'] = '1';

		$this->assertSame(
			'<span>Live Music by Location</span>',
			ec_events_breadcrumb_trail_archives( '' )
		);
	}

	/** The all-events page identifies its destination. */
	public function test_all_events_page_has_destination_specific_breadcrumb(): void {
		$GLOBALS['test_query_vars']['ec_events_router'] = 'all';

		$this->assertSame(
			'<span>All Live Music Events</span>',
			ec_events_breadcrumb_trail_archives( '' )
		);
	}

	/** Ordinary taxonomy archives retain the queried term label. */
	public function test_taxonomy_archive_keeps_term_breadcrumb(): void {
		$GLOBALS['test_is_tax']       = true;
		$GLOBALS['test_queried_term'] = (object) array( 'name' => 'Charleston' );

		$this->assertSame(
			'<span>Charleston</span>',
			ec_events_breadcrumb_trail_archives( '' )
		);
	}
}
