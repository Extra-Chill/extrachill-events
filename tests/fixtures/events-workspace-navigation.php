<?php
/**
 * Standalone Events workspace navigation fixture.
 *
 * @package ExtraChillEvents\Tests
 */

// phpcs:disable -- Isolated fixture intentionally defines minimal WordPress and domain doubles.

namespace ExtraChillEvents\Core {
	final class BookingSchema {}
	final class VenueAuthorization {
		public const STATUS_ACTIVE      = 'active';
		public const ACTION_ACCESS_VENUE = 'access_venue';
	}
	final class PromoterWorkspace {
		public function __construct( $promoters = null, $grants = null, $venues = null, bool $use_execution_principal = true ) {
			unset( $promoters, $grants, $venues );
			$GLOBALS['events_workspace_navigation']['uses_principal'] = $use_execution_principal;
		}

		public function identities_for_user( int $user_id ): array {
			$GLOBALS['events_workspace_navigation']['queried_users'][] = $user_id;
			return array( 'identities' => $GLOBALS['events_workspace_navigation']['identities'] );
		}
	}
}

namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	$GLOBALS['events_workspace_navigation'] = array(
		'current_blog_id' => 1,
		'events_blog_id'  => 7,
		'identities'      => array(),
		'logged_in'       => true,
		'admin'           => false,
		'queried_users'   => array(),
		'switches'        => array(),
		'uses_principal'  => true,
	);

	function add_filter() {}
	function add_action() {}
	function __( $text ) {
		return $text;
	}
	function ec_get_blog_id( $site ): int {
		return 'events' === $site ? $GLOBALS['events_workspace_navigation']['events_blog_id'] : 1;
	}
	function get_current_blog_id(): int {
		return $GLOBALS['events_workspace_navigation']['current_blog_id'];
	}
	function switch_to_blog( $blog_id ): void {
		$GLOBALS['events_workspace_navigation']['switches'][]        = array( 'to', (int) $blog_id );
		$GLOBALS['events_workspace_navigation']['current_blog_id'] = (int) $blog_id;
	}
	function restore_current_blog(): void {
		$GLOBALS['events_workspace_navigation']['switches'][]        = array( 'restore', 1 );
		$GLOBALS['events_workspace_navigation']['current_blog_id'] = 1;
	}
	function get_home_url( $blog_id, $path = '' ): string {
		return 7 === (int) $blog_id ? 'https://events.example' . $path : 'https://example.com' . $path;
	}
	function home_url( $path = '' ): string {
		return 'https://example.com' . $path;
	}
	function add_query_arg( $args, $url ): string {
		return $url . '?' . http_build_query( $args );
	}
	function ec_is_events_site(): bool {
		return true;
	}
	function is_user_logged_in(): bool {
		return $GLOBALS['events_workspace_navigation']['logged_in'];
	}
	function get_current_user_id(): int {
		return $GLOBALS['events_workspace_navigation']['logged_in'] ? 9 : 0;
	}
	function current_user_can( $capability ): bool {
		return 'manage_options' === $capability && $GLOBALS['events_workspace_navigation']['admin'];
	}

	$scenario = $argv[1] ?? 'anonymous';
	if ( 'promoter' === $scenario ) {
		$GLOBALS['events_workspace_navigation']['identities'] = array( array( 'reference' => 'promoter:30', 'type' => 'promoter' ) );
	} elseif ( 'venue' === $scenario ) {
		$GLOBALS['events_workspace_navigation']['identities'] = array( array( 'reference' => 'venue:40', 'type' => 'venue' ) );
	} elseif ( 'mixed' === $scenario ) {
		$GLOBALS['events_workspace_navigation']['identities'] = array(
			array( 'reference' => 'venue:40', 'type' => 'venue' ),
			array( 'reference' => 'promoter:30', 'type' => 'promoter' ),
		);
	} elseif ( 'administrator' === $scenario ) {
		$GLOBALS['events_workspace_navigation']['admin'] = true;
	} elseif ( 'anonymous' === $scenario ) {
		$GLOBALS['events_workspace_navigation']['logged_in'] = false;
	}

	require dirname( __DIR__, 2 ) . '/inc/core/booking-console.php';

	$avatar = ec_events_add_manage_events_avatar_item( array(), get_current_user_id() );
	if ( 'mixed' === $scenario ) {
		$avatar = ec_events_add_manage_events_avatar_item( $avatar, get_current_user_id() );
	}
	$header = ec_events_add_events_workspace_header_item( array() );
	if ( 'mixed' === $scenario ) {
		$header = ec_events_add_events_workspace_header_item( $header );
	}

	echo json_encode(
		array(
			'avatar'          => $avatar,
			'header'          => $header,
			'queried_users'   => $GLOBALS['events_workspace_navigation']['queried_users'],
			'switches'        => $GLOBALS['events_workspace_navigation']['switches'],
			'uses_principal'  => $GLOBALS['events_workspace_navigation']['uses_principal'],
			'current_blog_id' => $GLOBALS['events_workspace_navigation']['current_blog_id'],
		)
	);
}
