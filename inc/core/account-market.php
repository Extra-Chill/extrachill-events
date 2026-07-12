<?php
/**
 * Account-backed event market integration.
 *
 * Extra Chill Users owns the private preference. This integration consumes its
 * resolved coordinates and feeds them into Data Machine Events' existing geo
 * inputs without teaching the generic event layer about accounts.
 *
 * @package ExtraChillEvents
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Get the current user's resolved default event location.
 *
 * The Ability is introduced by extrachill-users#176. Until that dependency is
 * available, or when it returns an invalid/empty preference, this fails open.
 *
 * @return array{lat: float, lng: float, slug: string}|null
 */
function extrachill_events_get_account_market(): ?array {
	static $resolved = false;
	static $market   = null;

	if ( $resolved ) {
		return $market;
	}
	$resolved = true;

	if ( ! is_user_logged_in() || ! function_exists( 'wp_get_ability' ) ) {
		return null;
	}

	$ability = wp_get_ability( 'extrachill/get-user-settings' );
	if ( ! $ability ) {
		return null;
	}

	$result = $ability->execute( array() );
	if ( is_wp_error( $result ) || ! is_array( $result ) || ! is_array( $result['default_event_location'] ?? null ) ) {
		return null;
	}

	$location    = $result['default_event_location'];
	$coordinates = is_array( $location['coordinates'] ?? null ) ? $location['coordinates'] : array();
	$lat         = filter_var( $coordinates['lat'] ?? null, FILTER_VALIDATE_FLOAT );
	$lng         = filter_var( $coordinates['lng'] ?? null, FILTER_VALIDATE_FLOAT );

	if ( false === $lat || false === $lng || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 ) {
		return null;
	}

	$market = array(
		'lat'  => (float) $lat,
		'lng'  => (float) $lng,
		'slug' => sanitize_title( $location['slug'] ?? '' ),
	);

	return $market;
}

/**
 * Whether the request already carries an explicit location selection.
 */
function extrachill_events_has_explicit_market(): bool {
	if ( is_tax() ) {
		return true;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public read-only calendar filters.
	if ( ! empty( $_GET['tax_filter'] ) ) {
		return true;
	}

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public read-only geo parameters.
	$lat = isset( $_GET['lat'] ) ? sanitize_text_field( wp_unslash( $_GET['lat'] ) ) : '';
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Public read-only geo parameters.
	$lng = isset( $_GET['lng'] ) ? sanitize_text_field( wp_unslash( $_GET['lng'] ) ) : '';
	return '' !== $lat && '' !== $lng;
}

/**
 * Whether this page owns the unscoped public calendar/map experience.
 *
 * Embedded calendars such as My Shows and related events have their own query
 * contracts and must not inherit a market fallback.
 */
function extrachill_events_supports_account_market(): bool {
	if ( function_exists( 'ec_is_events_site' ) && ! ec_is_events_site() ) {
		return false;
	}

	return is_front_page()
		|| ( function_exists( 'extrachill_events_is_all_events_page' ) && extrachill_events_is_all_events_page() )
		|| ( function_exists( 'extrachill_events_is_near_me_page' ) && extrachill_events_is_near_me_page() );
}

/**
 * Seed account coordinates into an otherwise unscoped calendar render.
 *
 * DME already gives server-rendered geo attributes precedence over its browser
 * localStorage fallback. URL/archive inputs remain first because this hook does
 * nothing when either is present.
 *
 * @param array $parsed_block Parsed block data.
 * @return array
 */
function extrachill_events_seed_account_market( array $parsed_block ): array {
	if ( 'data-machine-events/calendar' !== ( $parsed_block['blockName'] ?? '' ) || ! extrachill_events_supports_account_market() || extrachill_events_has_explicit_market() ) {
		return $parsed_block;
	}

	$market = extrachill_events_get_account_market();
	if ( null === $market ) {
		return $parsed_block;
	}

	// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Temporarily preserving and seeding public read-only calendar parameters.
	$GLOBALS['extrachill_events_account_market_request'][] = array(
		'lat_exists' => isset( $_GET['lat'] ),
		'lng_exists' => isset( $_GET['lng'] ),
		'lat'        => isset( $_GET['lat'] ) ? sanitize_text_field( wp_unslash( $_GET['lat'] ) ) : null,
		'lng'        => isset( $_GET['lng'] ) ? sanitize_text_field( wp_unslash( $_GET['lng'] ) ) : null,
	);

	$_GET['lat'] = (string) $market['lat'];
	$_GET['lng'] = (string) $market['lng'];
	// phpcs:enable WordPress.Security.NonceVerification.Recommended

	return $parsed_block;
}
add_filter( 'render_block_data', 'extrachill_events_seed_account_market' );

/**
 * Restore request globals after an account-scoped calendar has rendered.
 *
 * @param string $block_content Rendered block HTML.
 * @param array  $block         Parsed block data.
 * @return string
 */
function extrachill_events_restore_account_market_request( string $block_content, array $block ): string {
	if ( 'data-machine-events/calendar' !== ( $block['blockName'] ?? '' ) || empty( $GLOBALS['extrachill_events_account_market_request'] ) ) {
		return $block_content;
	}

	$previous = array_pop( $GLOBALS['extrachill_events_account_market_request'] );
	foreach ( array( 'lat', 'lng' ) as $key ) {
		if ( $previous[ $key . '_exists' ] ) {
			$_GET[ $key ] = $previous[ $key ];
		} else {
			unset( $_GET[ $key ] );
		}
	}

	return $block_content;
}
add_filter( 'render_block', 'extrachill_events_restore_account_market_request', 10, 2 );

/**
 * Use the account market as a map center only when no stronger context exists.
 *
 * @param mixed $center  Existing map center.
 * @param array $context Map render context.
 * @return mixed
 */
function extrachill_events_account_market_map_center( $center, array $context ) {
	if ( null !== $center || ! extrachill_events_supports_account_market() || ! empty( $context['is_taxonomy'] ) || extrachill_events_has_explicit_market() ) {
		return $center;
	}

	$market = extrachill_events_get_account_market();
	if ( null === $market ) {
		return $center;
	}

	return array(
		'lat' => $market['lat'],
		'lon' => $market['lng'],
	);
}
add_filter( 'data_machine_events_map_center', 'extrachill_events_account_market_map_center', 5, 2 );
