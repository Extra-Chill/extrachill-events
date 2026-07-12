<?php
/**
 * Network bootstrap isolation tests.
 *
 * @package ExtraChillEvents\Tests
 */

use PHPUnit\Framework\TestCase;

/**
 * Verifies that network loading cannot leak the Events runtime to other sites.
 */
final class NetworkBootstrapTest extends TestCase {
	/** Verify the site gate follows the network Ability bootstrap. */
	public function test_non_events_bootstrap_only_loads_canonical_ability(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local test fixture.
		$source = file_get_contents( dirname( __DIR__, 3 ) . '/extrachill-events.php' );

		$ability_position   = strpos( $source, "require_once __DIR__ . '/inc/abilities/canonical-locations.php';" );
		$site_gate_position = strpos( $source, 'if ( get_current_blog_id() !== $extrachill_events_blog_id )' );
		$runtime_position   = strpos( $source, '// WP-CLI commands.' );

		$this->assertNotFalse( $ability_position );
		$this->assertNotFalse( $site_gate_position );
		$this->assertNotFalse( $runtime_position );
		$this->assertLessThan( $site_gate_position, $ability_position );
		$this->assertLessThan( $runtime_position, $site_gate_position );
	}

	/** Verify the narrow bootstrap contains no UI integration hooks. */
	public function test_network_bootstrap_has_no_frontend_or_admin_hooks(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local test fixture.
		$source = file_get_contents( dirname( __DIR__, 3 ) . '/inc/abilities/canonical-locations.php' );

		$this->assertStringNotContainsString( 'wp_enqueue', $source );
		$this->assertStringNotContainsString( 'template_', $source );
		$this->assertStringNotContainsString( 'admin_', $source );
		$this->assertStringContainsString( "require_once __DIR__ . '/events-locations.php';", $source );
	}

	/** Verify network activation does not require a site-active dependency. */
	public function test_plugin_is_network_only_without_site_active_dependency(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local test fixture.
		$source = file_get_contents( dirname( __DIR__, 3 ) . '/extrachill-events.php' );

		$this->assertStringContainsString( 'Network: true', $source );
		$this->assertStringContainsString( 'Requires Plugins: data-machine', $source );
		$this->assertStringNotContainsString( 'Requires Plugins: data-machine, data-machine-events', $source );
	}

	/** Verify activation restores the originating blog context. */
	public function test_activation_lifecycle_switches_and_restores_events_blog(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local test fixture.
		$source = file_get_contents( dirname( __DIR__, 3 ) . '/inc/activation.php' );

		$this->assertStringContainsString( 'switch_to_blog( $events_blog_id )', $source );
		$this->assertStringContainsString( 'restore_current_blog()', $source );
		$this->assertStringContainsString( "register_activation_hook( EXTRACHILL_EVENTS_PLUGIN_FILE, 'extrachill_events_activate' )", $source );
	}
}
