<?php
/**
 * Network bootstrap for the canonical event locations Ability.
 *
 * @package ExtraChillEvents
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

add_action( 'wp_abilities_api_categories_init', 'extrachill_events_register_abilities_category' );

/**
 * Register the events-domain ability category.
 */
function extrachill_events_register_abilities_category(): void {
	if ( ! function_exists( 'wp_register_ability_category' ) ) {
		return;
	}

	if ( function_exists( 'wp_has_ability_category' ) && wp_has_ability_category( 'extrachill-events' ) ) {
		return;
	}

	wp_register_ability_category(
		'extrachill-events',
		array(
			'label'       => __( 'Extra Chill Events', 'extrachill-events' ),
			'description' => __( 'Events calendar, filters, geocoding, venue lookup, submissions, and upcoming-count queries.', 'extrachill-events' ),
		)
	);
}

require_once __DIR__ . '/events-locations.php';
