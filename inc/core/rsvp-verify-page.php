<?php
/**
 * RSVP Verify Page
 *
 * The QR scan target: a virtual page (no physical WP page) at /rsvp-verify/
 * that resolves a pass code and, for an authorized host only
 * (extrachill_events_user_can_manage_event()), shows the attendee's name
 * and a one-tap Redeem. Owns its own rewrite plumbing, mirroring the
 * established pattern in inc/core/router-pages.php and
 * inc/core/discovery-pages.php — each virtual page owns its own tag/rule
 * rather than sharing one central registry.
 *
 * Non-enumerating by design: an invalid code, a valid code viewed by an
 * unauthorized user, and a logged-out visitor all render the identical
 * generic message (see templates/rsvp-verify.php) — a scan by anyone other
 * than the event's own host learns nothing.
 *
 * @package ExtraChillEvents
 * @since 0.70.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const EXTRACHILL_EVENTS_RSVP_VERIFY_REWRITE_VERSION = '1';

/**
 * Register the /rsvp-verify/ rewrite rule.
 *
 * @hook init
 */
function extrachill_events_rsvp_verify_rewrite_rules(): void {
	if ( ! function_exists( 'ec_is_events_site' ) || ! ec_is_events_site() ) {
		return;
	}

	add_rewrite_tag( '%ec_rsvp_verify%', '(1)' );
	add_rewrite_rule( '^rsvp-verify/?$', 'index.php?ec_rsvp_verify=1', 'top' );
}
add_action( 'init', 'extrachill_events_rsvp_verify_rewrite_rules' );

/**
 * Whether the current request is the RSVP verify page.
 *
 * @return bool
 */
function extrachill_events_is_rsvp_verify_page(): bool {
	return function_exists( 'ec_is_events_site' ) && ec_is_events_site() && '1' === (string) get_query_var( 'ec_rsvp_verify', '' );
}

/**
 * Flush rewrites once when this route is introduced.
 *
 * @hook init
 */
function extrachill_events_maybe_flush_rsvp_verify_rewrites(): void {
	if ( get_option( 'extrachill_events_rsvp_verify_rewrite_version' ) === EXTRACHILL_EVENTS_RSVP_VERIFY_REWRITE_VERSION ) {
		return;
	}

	extrachill_events_rsvp_verify_rewrite_rules();
	flush_rewrite_rules( false );
	update_option( 'extrachill_events_rsvp_verify_rewrite_version', EXTRACHILL_EVENTS_RSVP_VERIFY_REWRITE_VERSION, false );
}
add_action( 'init', 'extrachill_events_maybe_flush_rsvp_verify_rewrites', 20 );

/**
 * Prevent core from 404ing the virtual verify page before the template
 * filter runs (there is no matched post/term for this synthetic route).
 *
 * @hook parse_query
 * @param \WP_Query $query Main query.
 */
function extrachill_events_rsvp_verify_query_flags( $query ): void {
	if ( ! $query->is_main_query() || is_admin() ) {
		return;
	}
	if ( '1' !== (string) $query->get( 'ec_rsvp_verify', '' ) ) {
		return;
	}

	$query->is_404  = false;
	$query->is_page = true;
	$query->is_home = false;
}
add_action( 'parse_query', 'extrachill_events_rsvp_verify_query_flags' );

/**
 * @hook pre_handle_404
 * @param bool|null $preempt Existing 404 preemption result.
 * @param \WP_Query $query   Main query.
 * @return bool|null
 */
function extrachill_events_rsvp_verify_pre_handle_404( $preempt, $query ) {
	if ( '1' !== (string) $query->get( 'ec_rsvp_verify', '' ) ) {
		return $preempt;
	}

	status_header( 200 );
	return true;
}
add_filter( 'pre_handle_404', 'extrachill_events_rsvp_verify_pre_handle_404', 10, 2 );

/**
 * Route the request to the verify-page template.
 *
 * @hook extrachill_template_archive
 * @param string $template Current template path.
 * @return string
 */
function extrachill_events_rsvp_verify_template( string $template ): string {
	if ( extrachill_events_is_rsvp_verify_page() ) {
		return EXTRACHILL_EVENTS_PLUGIN_DIR . 'inc/templates/rsvp-verify.php';
	}

	return $template;
}
add_filter( 'extrachill_template_archive', 'extrachill_events_rsvp_verify_template', 25 );

/**
 * @hook document_title_parts
 * @param array $parts Title parts.
 * @return array
 */
function extrachill_events_rsvp_verify_title( array $parts ): array {
	if ( extrachill_events_is_rsvp_verify_page() ) {
		$parts['title'] = __( 'Verify RSVP Pass', 'extrachill-events' );
	}

	return $parts;
}
add_filter( 'document_title_parts', 'extrachill_events_rsvp_verify_title', 1000 );
