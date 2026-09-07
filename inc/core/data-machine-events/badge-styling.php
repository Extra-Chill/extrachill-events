<?php
/**
 * Badge Styling
 *
 * Map data-machine-events badges to theme badge classes for festival/location/venue styling.
 *
 * Uses data-machine-events' public integration API (DATA_MACHINE_EVENTS_POST_TYPE
 * constant + documented filters) so this code survives internal class refactors
 * in DM-events.
 *
 * @package ExtraChillEvents
 * @since 0.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Initialize badge styling hooks
 */
function extrachill_events_init_badge_styling() {
	if ( ! defined( 'DATA_MACHINE_EVENTS_POST_TYPE' ) ) {
		return;
	}

	add_filter( 'data_machine_events_badge_wrapper_classes', 'extrachill_events_add_wrapper_classes', 10, 2 );
	add_filter( 'data_machine_events_badge_classes', 'extrachill_events_add_badge_classes', 10, 4 );
	add_filter( 'data_machine_events_excluded_taxonomies', 'extrachill_events_exclude_taxonomies', 10, 2 );
	add_filter( 'extrachill_taxonomy_badges_skip_term', 'extrachill_events_skip_hidden_taxonomy_badges', 10, 3 );
}

/**
 * Taxonomies that must not render as reader-facing badges.
 *
 * `event_type` carries the closed Schema.org vocabulary seeded by
 * data-machine-events (`MusicEvent`, `ComedyEvent`, ...). Those names are
 * `@type` identifiers, not labels. Keep the taxonomy out of every badge
 * surface until the Extra Chill editorial vocabulary lands (#802).
 *
 * @return string[] Taxonomy slugs.
 */
function extrachill_events_hidden_badge_taxonomies() {
	return array( 'event_type' );
}

/**
 * Add theme-compatible wrapper class to badge container
 *
 * @param array $wrapper_classes Default wrapper classes from data-machine-events.
 * @param int   $post_id         Event post ID.
 * @return array Enhanced wrapper classes with theme compatibility.
 */
function extrachill_events_add_wrapper_classes( $wrapper_classes, $post_id ) {
	unset( $post_id );
	$wrapper_classes[] = 'taxonomy-badges';
	return $wrapper_classes;
}

/**
 * Map festival/location taxonomies to theme badge classes
 *
 * Enables custom colors from theme's badge-colors.css via taxonomy-specific
 * classes (e.g., festival-bonnaroo, location-charleston).
 *
 * @param array   $badge_classes  Default badge classes from data-machine-events.
 * @param string  $taxonomy_slug  Taxonomy name (festival, venue, location, etc.).
 * @param WP_Term $term           The taxonomy term object.
 * @param int     $post_id        Event post ID.
 * @return array Enhanced badge classes with taxonomy-specific styling.
 */
function extrachill_events_add_badge_classes( $badge_classes, $taxonomy_slug, $term, $post_id ) {
	unset( $post_id );
	$badge_classes[] = 'taxonomy-badge';

	switch ( $taxonomy_slug ) {
		case 'festival':
			$badge_classes[] = 'festival-badge';
			$badge_classes[] = 'festival-' . esc_attr( $term->slug );
			break;

		case 'location':
			$badge_classes[] = 'location-badge';
			$badge_classes[] = 'location-' . esc_attr( $term->slug );
			break;

		case 'venue':
			$badge_classes[] = 'venue-badge';
			$badge_classes[] = 'venue-' . esc_attr( $term->slug );
			break;
	}

	return $badge_classes;
}

/**
 * Exclude taxonomies from badge and modal display
 *
 * Artist taxonomy excluded to prevent redundant display with artist-specific
 * metadata. Hidden taxonomies (see extrachill_events_hidden_badge_taxonomies())
 * are excluded from calendar cards, related-event cards, and REST badge HTML.
 *
 * @param array  $excluded Array of taxonomy slugs to exclude.
 * @param string $context  Context identifier: 'badge', 'modal'.
 * @return array Enhanced exclusion array.
 */
function extrachill_events_exclude_taxonomies( $excluded, $context = '' ) {
	$excluded[] = 'artist';
	$excluded   = array_merge( $excluded, extrachill_events_hidden_badge_taxonomies() );

	if ( 'modal' !== $context ) {
		return array_values( array_unique( $excluded ) );
	}

	if ( ! defined( 'DATA_MACHINE_EVENTS_POST_TYPE' ) ) {
		return array_values( array_unique( $excluded ) );
	}

	$taxonomies = get_object_taxonomies( DATA_MACHINE_EVENTS_POST_TYPE, 'names' );
	if ( empty( $taxonomies ) || is_wp_error( $taxonomies ) ) {
		return array_values( array_unique( $excluded ) );
	}

	foreach ( $taxonomies as $taxonomy_slug ) {
		if ( 'location' === $taxonomy_slug ) {
			continue;
		}

		$excluded[] = $taxonomy_slug;
	}

	return array_values( array_unique( $excluded ) );
}

/**
 * Skip hidden taxonomies in the theme's above-title badge strip.
 *
 * The theme's extrachill_display_taxonomy_badges() iterates every taxonomy
 * registered on the post type and only offers a per-term skip filter, so this
 * is the hook that keeps hidden taxonomies off single event pages.
 *
 * @param bool    $skip     Whether to skip this term.
 * @param WP_Term $term     The term being rendered.
 * @param string  $taxonomy The taxonomy slug.
 * @return bool True for hidden taxonomies, unchanged otherwise.
 */
function extrachill_events_skip_hidden_taxonomy_badges( $skip, $term, $taxonomy ) {
	unset( $term );

	if ( in_array( $taxonomy, extrachill_events_hidden_badge_taxonomies(), true ) ) {
		return true;
	}

	return $skip;
}
