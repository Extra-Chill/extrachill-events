<?php
/**
 * Deterministic contract checks for the private Local Support UI.
 *
 * @package ExtraChillEvents\Tests
 */

use PHPUnit\Framework\TestCase;

/** Covers privacy and state presentation without a public API surface. */
final class LocalSupportUiContractTest extends TestCase {

	/** Prove required UI states and responsive behavior are represented. */
	public function test_ui_covers_private_states_and_consent_preview(): void {
		$root   = dirname( __DIR__ );
		// phpcs:disable WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local test fixtures, not remote requests.
		$php    = file_get_contents( $root . '/inc/core/local-support-workspace.php' );
		$script = file_get_contents( $root . '/assets/js/local-support.js' );
		$style  = file_get_contents( $root . '/assets/css/local-support.css' );
		// phpcs:enable WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

		$this->assertStringContainsString( 'No responses yet', $php );
		$this->assertStringContainsString( 'Workspace unavailable', $php );
		$this->assertStringContainsString( 'latest version is shown', $php );
		$this->assertStringContainsString( 'Contact not shared', $php );
		$this->assertStringContainsString( 'Revoke contact access', $php );
		$this->assertStringContainsString( 'data-consent-preview', $php );
		$this->assertStringContainsString( "aria-busy', 'true", $script );
		$this->assertStringContainsString( '@media (max-width: 600px)', $style );
	}

	/** Prove the browser UI does not expose the private domain abilities. */
	public function test_private_domain_abilities_remain_outside_rest(): void {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local test fixture, not a remote request.
		$abilities = file_get_contents( dirname( __DIR__ ) . '/inc/Abilities/LocalSupportAbilities.php' );
		$this->assertStringContainsString( "'show_in_rest' => false", $abilities );
	}
}
