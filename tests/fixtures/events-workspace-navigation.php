<?php
/**
 * Standalone repository-backed Events workspace navigation fixture.
 *
 * @package ExtraChillEvents\Tests
 */

// phpcs:disable -- Isolated fixture intentionally defines minimal WordPress and database doubles.

namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'ARRAY_A', 'ARRAY_A' );
	$GLOBALS['events_workspace_scenario'] = $argv[1] ?? 'anonymous';

	final class WP_Error {
		public function __construct( $code = '', $message = '', $data = null ) {
			unset( $code, $message, $data );
		}
	}

	final class EventsWorkspaceNavigationDatabase {
		public $prefix = 'wp_7_';
		public $term_taxonomy = 'wp_7_term_taxonomy';
		public $last_error = '';
		public $queries = array();
		private $prepared = array();

		public function prepare( $query, ...$args ) {
			$this->prepared = $args;
			return $query;
		}

		public function get_var( $query ) {
			$this->queries[] = array(
				'sql'  => $query,
				'args' => $this->prepared,
			);
			if ( $GLOBALS['events_workspace_navigation']['database_error'] ) {
				$this->last_error = 'Simulated authority read failure.';
				return null;
			}
			$this->last_error = '';
			$user_id = (int) ( false !== strpos( $query, 'ec_promoter_members' ) ? $this->prepared[2] : $this->prepared[1] );
			if ( false !== strpos( $query, 'ec_venue_members' ) ) {
				foreach ( $GLOBALS['events_workspace_navigation']['venue_memberships'] as $membership ) {
					if ( $user_id === $membership['user_id'] && 'active' === $membership['status'] && 'venue' === ( $GLOBALS['events_workspace_navigation']['terms'][ $membership['venue_term_id'] ] ?? '' ) ) {
						return '1';
					}
				}
				return null;
			}
			foreach ( $GLOBALS['events_workspace_navigation']['promoter_memberships'] as $membership ) {
				$promoter_id = $membership['promoter_term_id'];
				if ( $user_id === $membership['user_id'] && 'active' === $membership['status'] && 'active' === ( $GLOBALS['events_workspace_navigation']['organizations'][ $promoter_id ] ?? '' ) && 'promoter' === ( $GLOBALS['events_workspace_navigation']['terms'][ $promoter_id ] ?? '' ) ) {
					return '1';
				}
			}
			return null;
		}
	}
}

namespace ExtraChillEvents\Core {
	final class BookingSchema {
		public static function is_ready(): bool {
			return true;
		}
		public static function memberships_table(): string {
			return 'wp_7_ec_venue_members';
		}
	}

	if ( 'provider-missing' !== $GLOBALS['events_workspace_scenario'] ) {
		final class PromoterAuthoritySchema {
			public static function is_ready(): bool {
				return true;
			}
			public static function memberships_table(): string {
				return 'wp_7_ec_promoter_members';
			}
			public static function organizations_table(): string {
				return 'wp_7_ec_promoter_organizations';
			}
		}
	}
}

namespace {
	$GLOBALS['events_workspace_navigation'] = array(
		'blog_stack'            => array( 12 ),
		'current_blog_id'        => 12,
		'logged_in'              => true,
		'admin'                  => false,
		'capability'             => true,
		'feature'                => true,
		'database_error'         => false,
		'venue_memberships'      => array(),
		'promoter_memberships'   => array(),
		'organizations'          => array(),
		'terms'                  => array(),
		'switches'               => array(),
	);
	$GLOBALS['wpdb'] = new EventsWorkspaceNavigationDatabase();

	$GLOBALS['events_workspace_actions'] = array();
	function add_filter( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		$GLOBALS['events_workspace_actions'][ $hook ][ $priority ][] = array( $callback, $accepted_args );
	}
	function add_action( $hook, $callback, $priority = 10, $accepted_args = 1 ) {
		add_filter( $hook, $callback, $priority, $accepted_args );
	}
	function do_action( $hook, ...$args ): void {
		foreach ( $GLOBALS['events_workspace_actions'][ $hook ] ?? array() as $callbacks ) {
			foreach ( $callbacks as $callback ) {
				call_user_func_array( $callback[0], array_slice( $args, 0, $callback[1] ) );
			}
		}
	}
	function __( $text ) {
		return $text;
	}
	function ec_get_blog_id( $site ): int {
		return 'events' === $site ? 7 : 1;
	}
	function get_current_blog_id(): int {
		return $GLOBALS['events_workspace_navigation']['current_blog_id'];
	}
	function switch_to_blog( $blog_id ): void {
		$GLOBALS['events_workspace_navigation']['blog_stack'][]     = $GLOBALS['events_workspace_navigation']['current_blog_id'];
		$GLOBALS['events_workspace_navigation']['current_blog_id'] = (int) $blog_id;
		$GLOBALS['events_workspace_navigation']['switches'][]       = array( 'to', (int) $blog_id );
	}
	function restore_current_blog(): void {
		$GLOBALS['events_workspace_navigation']['current_blog_id'] = (int) array_pop( $GLOBALS['events_workspace_navigation']['blog_stack'] );
		$GLOBALS['events_workspace_navigation']['switches'][]       = array( 'restore', $GLOBALS['events_workspace_navigation']['current_blog_id'] );
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
	function user_can( $user_id, $capability ): bool {
		return 9 === (int) $user_id && 'access_events_admin' === $capability && $GLOBALS['events_workspace_navigation']['capability'];
	}
	function ec_feature_available( $feature, $user_id ): bool {
		return 'venue_booking' === $feature && 9 === (int) $user_id && $GLOBALS['events_workspace_navigation']['feature'];
	}

	$scenario = $GLOBALS['events_workspace_scenario'];
	if ( 'promoter' === $scenario || 'mixed' === $scenario ) {
		$GLOBALS['events_workspace_navigation']['promoter_memberships'][] = array( 'promoter_term_id' => 30, 'user_id' => 9, 'status' => 'active' );
		$GLOBALS['events_workspace_navigation']['organizations'][30]      = 'active';
		$GLOBALS['events_workspace_navigation']['terms'][30]              = 'promoter';
	}
	if ( 'venue' === $scenario || 'mixed' === $scenario || 'no-capability' === $scenario || 'no-feature' === $scenario || 'invalidate-capability' === $scenario || 'invalidate-feature' === $scenario ) {
		$GLOBALS['events_workspace_navigation']['venue_memberships'][] = array( 'venue_term_id' => 40, 'user_id' => 9, 'status' => 'active' );
		$GLOBALS['events_workspace_navigation']['terms'][40]           = 'venue';
	}
	if ( 'revoked' === $scenario ) {
		$GLOBALS['events_workspace_navigation']['venue_memberships'][]    = array( 'venue_term_id' => 40, 'user_id' => 9, 'status' => 'revoked' );
		$GLOBALS['events_workspace_navigation']['promoter_memberships'][] = array( 'promoter_term_id' => 30, 'user_id' => 9, 'status' => 'revoked' );
		$GLOBALS['events_workspace_navigation']['organizations'][30]      = 'active';
		$GLOBALS['events_workspace_navigation']['terms']                   = array( 30 => 'promoter', 40 => 'venue' );
	} elseif ( 'stale' === $scenario ) {
		$GLOBALS['events_workspace_navigation']['venue_memberships'][]    = array( 'venue_term_id' => 40, 'user_id' => 9, 'status' => 'active' );
		$GLOBALS['events_workspace_navigation']['promoter_memberships'][] = array( 'promoter_term_id' => 30, 'user_id' => 9, 'status' => 'active' );
		$GLOBALS['events_workspace_navigation']['organizations'][30]      = 'active';
	} elseif ( 'revoked-organization' === $scenario ) {
		$GLOBALS['events_workspace_navigation']['promoter_memberships'][] = array( 'promoter_term_id' => 30, 'user_id' => 9, 'status' => 'active' );
		$GLOBALS['events_workspace_navigation']['organizations'][30]      = 'revoked';
		$GLOBALS['events_workspace_navigation']['terms'][30]              = 'promoter';
	} elseif ( 'administrator' === $scenario ) {
		$GLOBALS['events_workspace_navigation']['admin'] = true;
	} elseif ( 'database-error' === $scenario ) {
		$GLOBALS['events_workspace_navigation']['database_error'] = true;
	} elseif ( 'no-capability' === $scenario ) {
		$GLOBALS['events_workspace_navigation']['capability'] = false;
	} elseif ( 'no-feature' === $scenario ) {
		$GLOBALS['events_workspace_navigation']['feature'] = false;
	} elseif ( 'anonymous' === $scenario ) {
		$GLOBALS['events_workspace_navigation']['logged_in'] = false;
	}

	if ( 'provider-missing' !== $scenario ) {
		require dirname( __DIR__, 2 ) . '/inc/Core/VenueMembershipRepository.php';
		require dirname( __DIR__, 2 ) . '/inc/Core/VenueAuthorization.php';
		require dirname( __DIR__, 2 ) . '/inc/Core/PromoterAuthorityRepository.php';
	}
	require dirname( __DIR__, 2 ) . '/inc/core/booking-console.php';

	$avatar = ec_events_add_manage_events_avatar_item( array(), get_current_user_id() );
	$header = ec_events_add_events_workspace_header_item( array() );
	$avatar = ec_events_add_manage_events_avatar_item( $avatar, get_current_user_id() );
	$header = ec_events_add_events_workspace_header_item( $header );

	$invalidation = null;
	if ( in_array( $scenario, array( 'invalidate-venue', 'invalidate-promoter', 'invalidate-capability', 'invalidate-feature' ), true ) ) {
		$before = ec_events_user_has_managed_identity( 9 );
		if ( 'invalidate-venue' === $scenario ) {
			$GLOBALS['events_workspace_navigation']['venue_memberships'][] = array( 'venue_term_id' => 40, 'user_id' => 9, 'status' => 'active' );
			$GLOBALS['events_workspace_navigation']['terms'][40]           = 'venue';
			do_action( 'extrachill_events_venue_membership_changed', 40, 9, 'membership_created' );
		} elseif ( 'invalidate-promoter' === $scenario ) {
			$GLOBALS['events_workspace_navigation']['promoter_memberships'][] = array( 'promoter_term_id' => 30, 'user_id' => 9, 'status' => 'active' );
			$GLOBALS['events_workspace_navigation']['organizations'][30]      = 'active';
			$GLOBALS['events_workspace_navigation']['terms'][30]              = 'promoter';
			do_action( 'extrachill_events_promoter_authority_changed', 30, 'membership_created' );
		} elseif ( 'invalidate-capability' === $scenario ) {
			$GLOBALS['events_workspace_navigation']['capability'] = false;
			do_action( 'set_user_role', 9, 'subscriber', array( 'extra_chill_team' ) );
		} else {
			$GLOBALS['events_workspace_navigation']['feature'] = false;
			do_action( 'update_site_option_ec_feature_tier_venue_booking', 'team', 'admin', 'ec_feature_tier_venue_booking' );
		}
		$invalidation = array( 'before' => $before, 'after' => ec_events_user_has_managed_identity( 9 ) );
	}

	echo json_encode(
		array(
			'avatar'          => $avatar,
			'header'          => $header,
			'queries'         => $GLOBALS['wpdb']->queries,
			'switches'        => $GLOBALS['events_workspace_navigation']['switches'],
			'current_blog_id' => $GLOBALS['events_workspace_navigation']['current_blog_id'],
			'invalidation'    => $invalidation,
		)
	);
}
