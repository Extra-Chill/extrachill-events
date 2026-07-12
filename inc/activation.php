<?php
/**
 * Network-safe activation lifecycle.
 *
 * @package ExtraChillEvents
 */

defined( 'ABSPATH' ) || exit;

/**
 * Run an activation callback in the Events blog context.
 *
 * @param callable $callback Activation or deactivation work.
 * @return void
 */
function extrachill_events_run_on_events_site( callable $callback ): void {
	$events_blog_id = function_exists( 'ec_get_blog_id' ) ? (int) ec_get_blog_id( 'events' ) : 7;
	$switched       = get_current_blog_id() !== $events_blog_id;

	if ( $switched && ! switch_to_blog( $events_blog_id ) ) {
		return;
	}

	try {
		$callback();
	} finally {
		if ( $switched ) {
			restore_current_blog();
		}
	}
}

/**
 * Install Events-owned schema and rewrite rules once on network activation.
 */
function extrachill_events_activate(): void {
	extrachill_events_run_on_events_site(
		static function (): void {
			require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/QualifyVerdictsTable.php';
			require_once EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/Core/ArtistUrlSubmissionsTable.php';
			\ExtraChillEvents\Core\QualifyVerdictsTable::create_table();
			\ExtraChillEvents\Core\ArtistUrlSubmissionsTable::create_table();
			flush_rewrite_rules();
		}
	);
}

/**
 * Flush only the Events site's rewrite rules on deactivation.
 */
function extrachill_events_deactivate(): void {
	extrachill_events_run_on_events_site( 'flush_rewrite_rules' );
}

register_activation_hook( EXTRACHILL_EVENTS_PLUGIN_FILE, 'extrachill_events_activate' );
register_deactivation_hook( EXTRACHILL_EVENTS_PLUGIN_FILE, 'extrachill_events_deactivate' );
