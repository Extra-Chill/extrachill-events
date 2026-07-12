<?php
/**
 * Account market integration tests.
 *
 * @package ExtraChillEvents\Tests
 */

use PHPUnit\Framework\TestCase;

if ( ! function_exists( 'add_filter' ) ) {
	function add_filter() {
		return true;
	}
}

if ( ! function_exists( 'is_user_logged_in' ) ) {
	function is_user_logged_in() {
		return (bool) ( $GLOBALS['test_is_user_logged_in'] ?? false );
	}
}

if ( ! function_exists( 'wp_get_ability' ) ) {
	function wp_get_ability( $name ) {
		return 'extrachill/get-user-settings' === $name ? ( $GLOBALS['test_account_market_ability'] ?? null ) : null;
	}
}

if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error() {
		return false;
	}
}

if ( ! function_exists( 'sanitize_title' ) ) {
	function sanitize_title( $value ) {
		return strtolower( preg_replace( '/[^a-z0-9]+/i', '-', trim( (string) $value ) ) );
	}
}

if ( ! function_exists( 'absint' ) ) {
	function absint( $value ) {
		return abs( (int) $value );
	}
}

if ( ! function_exists( 'sanitize_text_field' ) ) {
	function sanitize_text_field( $value ) {
		return trim( (string) $value );
	}
}

if ( ! function_exists( 'esc_url_raw' ) ) {
	function esc_url_raw( $value ) {
		return filter_var( $value, FILTER_SANITIZE_URL );
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	function wp_unslash( $value ) {
		return $value;
	}
}

if ( ! function_exists( 'is_tax' ) ) {
	function is_tax() {
		return (bool) ( $GLOBALS['test_is_tax'] ?? false );
	}
}

if ( ! function_exists( 'is_front_page' ) ) {
	function is_front_page() {
		return (bool) ( $GLOBALS['test_is_front_page'] ?? false );
	}
}

if ( ! function_exists( 'ec_is_events_site' ) ) {
	function ec_is_events_site() {
		return true;
	}
}

if ( ! function_exists( 'extrachill_events_is_near_me_page' ) ) {
	function extrachill_events_is_near_me_page() {
		return (bool) ( $GLOBALS['test_is_near_me_page'] ?? false );
	}
}

if ( ! function_exists( 'extrachill_events_is_all_events_page' ) ) {
	function extrachill_events_is_all_events_page() {
		return (bool) ( $GLOBALS['test_is_all_events_page'] ?? false );
	}
}

if ( ! function_exists( 'esc_html' ) ) {
	function esc_html( $value ) {
		return htmlspecialchars( (string) $value, ENT_QUOTES, 'UTF-8' );
	}
}

if ( ! function_exists( 'esc_html__' ) ) {
	function esc_html__( $value ) {
		return esc_html( $value );
	}
}

if ( ! function_exists( 'esc_html_e' ) ) {
	function esc_html_e( $value ) {
		echo esc_html( $value );
	}
}

if ( ! function_exists( 'esc_attr_e' ) ) {
	function esc_attr_e( $value ) {
		echo esc_html( $value );
	}
}

if ( ! function_exists( 'esc_url' ) ) {
	function esc_url( $value ) {
		return esc_html( $value );
	}
}

if ( ! function_exists( 'trailingslashit' ) ) {
	function trailingslashit( $value ) {
		return rtrim( $value, '/' ) . '/';
	}
}

if ( ! function_exists( 'ec_get_site_url' ) ) {
	function ec_get_site_url() {
		return 'https://community.example';
	}
}

if ( ! function_exists( 'remove_query_arg' ) ) {
	function remove_query_arg() {
		return 'https://events.example/all/';
	}
}

if ( ! function_exists( 'add_query_arg' ) ) {
	function add_query_arg( $key, $value, $url ) {
		return $url . '?' . rawurlencode( $key ) . '=' . rawurlencode( $value );
	}
}

if ( ! function_exists( 'wp_login_url' ) ) {
	function wp_login_url( $redirect ) {
		return 'https://events.example/login/?redirect_to=' . rawurlencode( $redirect );
	}
}

require_once dirname( __DIR__, 3 ) . '/inc/core/account-market.php';

/**
 * Verifies the account preference integration and its precedence gates.
 */
final class AccountMarketTest extends TestCase {
	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_resolves_coordinates_from_user_ability(): void {
		$GLOBALS['test_is_user_logged_in']      = true;
		$GLOBALS['test_account_market_ability'] = new class() {
			public function execute(): array {
				return array(
					'default_event_location' => array(
						'slug'        => 'Charleston SC',
						'term_id'     => 1618,
						'coordinates' => array(
							'lat' => 32.7765,
							'lon' => -79.9311,
						),
						'hierarchy'   => array( 'label' => 'Charleston, South Carolina' ),
						'archive_url' => 'https://events.example/location/charleston-sc/',
					),
				);
			}
		};

		$this->assertSame(
			array(
				'lat'     => 32.7765,
				'lon'     => -79.9311,
				'slug'    => 'charleston-sc',
				'term_id' => 1618,
				'label'   => 'Charleston, South Carolina',
				'url'     => 'https://events.example/location/charleston-sc/',
			),
			extrachill_events_get_account_market()
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_fails_open_when_ability_is_unavailable(): void {
		$GLOBALS['test_is_user_logged_in'] = true;

		$this->assertNull( extrachill_events_get_account_market() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_adds_taxonomy_default_without_mutating_request_globals(): void {
		$GLOBALS['test_is_user_logged_in']      = true;
		$GLOBALS['test_is_front_page']          = true;
		$GLOBALS['test_account_market_ability'] = new class() {
			public function execute(): array {
				return array(
					'default_event_location' => array(
						'slug'        => 'charleston',
						'term_id'     => 1618,
						'coordinates' => array(
							'lat' => 32.7765,
							'lon' => -79.9311,
						),
					),
				);
			}
		};
		$_GET = array();
		$result = extrachill_events_calendar_account_market_defaults( array(), array( 'archive_term' => null ) );

		$this->assertSame( array( 'location' => array( 1618 ) ), $result['tax_filter'] );
		$this->assertSame( array(), $_GET );
	}

	public function test_explicit_geo_and_taxonomy_filters_win(): void {
		$_GET = array(
			'lat' => '40.7',
			'lng' => '-74.0',
		);
		$this->assertTrue( extrachill_events_has_explicit_market() );

		$_GET = array( 'tax_filter' => array( 'location' => array( 42 ) ) );
		$this->assertTrue( extrachill_events_has_explicit_market() );

		$explicit_geo = array(
			'lat' => '40.7',
			'lng' => '-74.0',
		);
		$this->assertSame(
			$explicit_geo,
			extrachill_events_calendar_account_market_defaults( $explicit_geo, array( 'archive_term' => null ) )
		);

		$explicit_taxonomy = array( 'tax_filter' => array( 'location' => array( 42 ) ) );
		$this->assertSame(
			$explicit_taxonomy,
			extrachill_events_calendar_account_market_defaults( $explicit_taxonomy, array( 'archive_term' => null ) )
		);
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_explore_all_suppresses_fallback_without_mutating_preference(): void {
		$GLOBALS['test_is_user_logged_in']      = true;
		$GLOBALS['test_is_front_page']          = true;
		$GLOBALS['test_account_market_ability'] = new class() {
			public function execute(): array {
				return array(
					'default_event_location' => array(
						'slug'        => 'charleston',
						'term_id'     => 1618,
						'coordinates' => array(
							'lat' => 32.7765,
							'lon' => -79.9311,
						),
					),
				);
			}
		};
		$_GET = array( 'explore_all' => '1' );

		$this->assertTrue( extrachill_events_is_exploring_all_markets() );
		$this->assertSame( array(), extrachill_events_calendar_account_market_defaults( array(), array( 'archive_term' => null ) ) );
		$this->assertSame( 1618, extrachill_events_get_account_market()['term_id'] );
		$this->assertSame( array( 'explore_all' => '1' ), $_GET );

		ob_start();
		extrachill_events_render_account_market_context();
		$output = (string) ob_get_clean();
		$this->assertStringContainsString( 'Exploring all locations', $output );
		$this->assertStringContainsString( 'Use my default market', $output );
	}

	public function test_explore_all_requires_exact_sanitized_flag(): void {
		$_GET = array( 'explore_all' => 'true' );
		$this->assertFalse( extrachill_events_is_exploring_all_markets() );

		$_GET = array( 'explore_all' => array( '1' ) );
		$this->assertFalse( extrachill_events_is_exploring_all_markets() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_near_me_adds_geo_defaults_without_taxonomy_filter(): void {
		$GLOBALS['test_is_user_logged_in']      = true;
		$GLOBALS['test_is_near_me_page']        = true;
		$GLOBALS['test_account_market_ability'] = new class() {
			public function execute(): array {
				return array(
					'default_event_location' => array(
						'slug'        => 'charleston',
						'term_id'     => 1618,
						'coordinates' => array(
							'lat' => 32.7765,
							'lon' => -79.9311,
						),
					),
				);
			}
		};

		$result = extrachill_events_calendar_account_market_defaults( array(), array( 'archive_term' => null ) );

		$this->assertSame( 32.7765, $result['lat'] );
		$this->assertSame( -79.9311, $result['lng'] );
		$this->assertArrayNotHasKey( 'tax_filter', $result );
	}

	public function test_anonymous_request_does_not_resolve_account_market(): void {
		$GLOBALS['test_is_user_logged_in'] = false;

		$this->assertNull( extrachill_events_get_account_market() );
	}

	public function test_supported_surfaces_are_limited_to_primary_discovery_pages(): void {
		$GLOBALS['test_is_front_page'] = true;
		$this->assertTrue( extrachill_events_supports_account_market() );

		$GLOBALS['test_is_front_page']     = false;
		$GLOBALS['test_is_all_events_page'] = true;
		$this->assertTrue( extrachill_events_supports_account_market() );

		$GLOBALS['test_is_all_events_page'] = false;
		$GLOBALS['test_is_near_me_page']    = true;
		$this->assertTrue( extrachill_events_supports_account_market() );

		$GLOBALS['test_is_near_me_page'] = false;
		$this->assertFalse( extrachill_events_supports_account_market() );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_active_market_context_escapes_label_and_links_to_account_details(): void {
		$GLOBALS['test_is_user_logged_in']      = true;
		$GLOBALS['test_is_front_page']          = true;
		$GLOBALS['test_account_market_ability'] = new class() {
			public function execute(): array {
				return array(
					'default_event_location' => array(
						'slug'      => 'charleston',
						'term_id'   => 1618,
						'hierarchy' => array( 'label' => '<script>Charleston</script>' ),
					),
				);
			}
		};

		ob_start();
		extrachill_events_render_account_market_context();
		$output = (string) ob_get_clean();

		$this->assertStringContainsString( 'Showing events for Charleston', $output );
		$this->assertStringNotContainsString( '<script>', $output );
		$this->assertStringContainsString( '/settings/#tab-account-details', $output );
		$this->assertStringContainsString( 'explore_all=1', $output );
	}

	/**
	 * @runInSeparateProcess
	 * @preserveGlobalState disabled
	 */
	public function test_logged_in_without_market_and_anonymous_prompts(): void {
		$GLOBALS['test_is_front_page']     = true;
		$GLOBALS['test_is_user_logged_in'] = true;

		ob_start();
		extrachill_events_render_account_market_context();
		$logged_in = (string) ob_get_clean();
		$this->assertStringContainsString( 'Set default market', $logged_in );

		$GLOBALS['test_is_user_logged_in'] = false;
		ob_start();
		extrachill_events_render_account_market_context();
		$anonymous = (string) ob_get_clean();
		$this->assertStringContainsString( 'Sign in to save a default market', $anonymous );
		$this->assertStringContainsString( 'events-market-context--quiet', $anonymous );
	}
}
