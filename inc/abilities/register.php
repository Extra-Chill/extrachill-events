<?php
/**
 * Events-Domain Abilities Registration
 *
 * Registers the extrachill-events ability category and loads all
 * events-domain ability files.  Each file self-registers on the
 * wp_abilities_api_init hook.
 *
 * @package ExtraChillEvents
 * @since   0.19.0
 */

declare( strict_types=1 );

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/canonical-locations.php';

// Load ability files — each self-registers on wp_abilities_api_init.
// Note: events-submit was removed as a pure pass-through wrapper around
// extrachill/submit-event. extrachill-api now calls the underlying ability
// directly. See Extra-Chill/extrachill-events#104.
require_once __DIR__ . '/events-calendar.php';
require_once __DIR__ . '/events-filters.php';
require_once __DIR__ . '/events-geocode.php';
require_once __DIR__ . '/events-upcoming-counts.php';
require_once __DIR__ . '/events-list-venues.php';
require_once __DIR__ . '/events-get-venue.php';
require_once __DIR__ . '/events-check-venue-duplicate.php';
