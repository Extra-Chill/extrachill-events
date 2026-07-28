<?php
/**
 * Plugin Name: Extra Chill Events Network Blocks
 * Description: Exposes Events-owned public blocks across the Extra Chill network without loading the Events runtime on other sites.
 * Author: Chris Huber
 * Author URI: https://chubes.net
 * Requires Plugins: extrachill-network, extrachill-api
 * License: GPL v2 or later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: extrachill-events
 * Requires at least: 6.9
 * Tested up to: 6.9
 * Requires PHP: 7.4
 * Network: true
 *
 * @package ExtraChillEvents
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/inc/Core/VenueBookingConfig.php';

/** Register Events-owned blocks that are safe to render on every network site. */
function extrachill_events_register_network_blocks(): void {
	$booking_inquiry_dir = __DIR__ . '/build/venue-booking-inquiry';
	if ( file_exists( $booking_inquiry_dir . '/block.json' ) ) {
		register_block_type( $booking_inquiry_dir );
	}
}
add_action( 'init', 'extrachill_events_register_network_blocks' );
