<?php
/**
 * Events-owned Local Support organizer authority providers.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

defined( 'ABSPATH' ) || exit;

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound -- Cohesive provider contract, registry, and built-in implementations.

/** Action-specific organizer authority contract. */
interface LocalSupportOrganizerProvider {
	public function type(): string;
	public function authorize( string $action, array $request, array $identity, int $user_id, LocalSupportAuthorization $authorization, bool $locked = false, ?object $scope = null );
	public function choices( int $event_id, int $user_id, LocalSupportAuthorization $authorization );
	public function recipient_ids( array $request, LocalSupportAuthorization $authorization );
}

/** Strict provider registry. Exactly one provider may claim a selected identity. */
final class LocalSupportOrganizerProviderRegistry {
	/** @var array<string,LocalSupportOrganizerProvider> */
	private $providers = array();

	public function register( LocalSupportOrganizerProvider $provider ) {
		$type = $this->type_key( $provider->type() );
		if ( '' === $type || isset( $this->providers[ $type ] ) ) {
			return new \WP_Error( 'local_support_organizer_provider_duplicate', __( 'A Local Support organizer provider claimed a duplicate or invalid identity type.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
		$this->providers[ $type ] = $provider;
		return true;
	}

	public function authorize( string $action, array $request, array $identity, int $user_id, LocalSupportAuthorization $authorization, bool $locked = false, ?object $scope = null ) {
		$type = $this->type_key( (string) ( $identity['type'] ?? '' ) );
		$id   = absint( $identity['id'] ?? 0 );
		if ( $id < 1 || ! isset( $this->providers[ $type ] ) ) {
			return new \WP_Error( 'local_support_organizer_identity_unknown', __( 'The selected Local Support organizer identity is unavailable.', 'extrachill-events' ), array( 'status' => 403 ) );
		}
		$identity = array(
			'type' => $type,
			'id'   => $id,
		);
		return $this->invoke( $this->providers[ $type ], 'authorize', array( $action, $request, $identity, $user_id, $authorization, $locked, $scope ) );
	}

	/** Prepare provider advisory locks before the service transaction starts. */
	public function prepare( string $action, array $request, array $identity, int $user_id, LocalSupportAuthorization $authorization ) {
		$type = $this->type_key( (string) ( $identity['type'] ?? '' ) );
		$id   = absint( $identity['id'] ?? 0 );
		if ( $id < 1 || ! isset( $this->providers[ $type ] ) ) {
			return new \WP_Error( 'local_support_organizer_identity_unknown', __( 'The selected Local Support organizer identity is unavailable.', 'extrachill-events' ), array( 'status' => 403 ) );
		}
		$provider = $this->providers[ $type ];
		return method_exists( $provider, 'prepare_transaction' )
			? $this->invoke(
				$provider,
				'prepare_transaction',
				array(
					$action,
					$request,
					array(
						'type' => $type,
						'id'   => $id,
					),
					$user_id,
					$authorization,
				)
			)
			: null;
	}

	/** Resolve choices from only the explicitly selected provider. */
	public function choice( int $event_id, int $user_id, array $identity, LocalSupportAuthorization $authorization ) {
		$type = $this->type_key( (string) ( $identity['type'] ?? '' ) );
		$id   = absint( $identity['id'] ?? 0 );
		if ( $id < 1 || ! isset( $this->providers[ $type ] ) ) {
			return new \WP_Error( 'local_support_organizer_identity_unknown', __( 'The selected Local Support organizer identity is unavailable.', 'extrachill-events' ), array( 'status' => 403 ) );
		}
		$provider = $this->providers[ $type ];
		$rows     = method_exists( $provider, 'choice' )
			? $this->invoke(
				$provider,
				'choice',
				array(
					$event_id,
					$user_id,
					array(
						'type' => $type,
						'id'   => $id,
					),
					$authorization,
				)
			)
			: $this->invoke( $provider, 'choices', array( $event_id, $user_id, $authorization ) );
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}
		return array_values(
			array_filter(
				(array) $rows,
				static function ( array $row ) use ( $type, $id ): bool {
					return ( $row['type'] ?? '' ) === $type && (int) ( $row['id'] ?? 0 ) === $id;
				}
			)
		);
	}

	public function choices( int $event_id, int $user_id, LocalSupportAuthorization $authorization ) {
		$choices = array();
		foreach ( $this->providers as $provider ) {
			$rows = $this->invoke( $provider, 'choices', array( $event_id, $user_id, $authorization ) );
			if ( is_wp_error( $rows ) ) {
				return $rows;
			}
			foreach ( (array) $rows as $row ) {
				$type = sanitize_key( (string) ( $row['type'] ?? '' ) );
				$id   = absint( $row['id'] ?? 0 );
				if ( $type !== $provider->type() || $id < 1 ) {
					return new \WP_Error( 'local_support_organizer_provider_contract_invalid', __( 'A Local Support organizer provider returned an invalid identity.', 'extrachill-events' ), array( 'status' => 503 ) );
				}
				$key = $type . ':' . $id;
				if ( isset( $choices[ $key ] ) ) {
					return new \WP_Error( 'local_support_organizer_identity_duplicate', __( 'More than one provider claimed a Local Support organizer identity.', 'extrachill-events' ), array( 'status' => 409 ) );
				}
				$choices[ $key ] = $row;
			}
		}
		return array_values( $choices );
	}

	public function recipient_ids( array $request, LocalSupportAuthorization $authorization ) {
		$ids = array();
		foreach ( $this->providers as $provider ) {
			$provider_ids = $this->invoke( $provider, 'recipient_ids', array( $request, $authorization ) );
			if ( is_wp_error( $provider_ids ) ) {
				return $provider_ids;
			}
			$ids = array_merge( $ids, array_map( 'absint', (array) $provider_ids ) );
		}
		return array_values( array_unique( array_filter( $ids ) ) );
	}

	private function invoke( LocalSupportOrganizerProvider $provider, string $method, array $args ) {
		$blog_id = get_current_blog_id();
		$depth   = count( (array) ( $GLOBALS['_wp_switched_stack'] ?? array() ) );
		try {
			$result = call_user_func_array( array( $provider, $method ), $args ); // @phpstan-ignore-line
		} catch ( \Throwable $throwable ) {
			$result = new \WP_Error(
				'local_support_organizer_provider_failed',
				__( 'A Local Support organizer provider failed safely.', 'extrachill-events' ),
				array(
					'provider'  => $provider->type(),
					'exception' => get_class( $throwable ),
					'status'    => 503,
				)
			);
		}
		$context_leaked = get_current_blog_id() !== $blog_id || count( (array) ( $GLOBALS['_wp_switched_stack'] ?? array() ) ) !== $depth;
		$attempts       = 0;
		$current_depth  = count( (array) ( $GLOBALS['_wp_switched_stack'] ?? array() ) );
		while ( ( $current_depth > $depth || get_current_blog_id() !== $blog_id ) && $attempts < 100 ) {
			restore_current_blog();
			++$attempts;
			$current_depth = count( (array) ( $GLOBALS['_wp_switched_stack'] ?? array() ) );
		}
		if ( $context_leaked || get_current_blog_id() !== $blog_id || count( (array) ( $GLOBALS['_wp_switched_stack'] ?? array() ) ) !== $depth ) {
			return new \WP_Error(
				'local_support_organizer_provider_context_corrupt',
				__( 'A Local Support organizer provider corrupted multisite context.', 'extrachill-events' ),
				array(
					'provider' => $provider->type(),
					'status'   => 503,
				)
			);
		}
		return $result;
	}

	private function type_key( string $type ): string {
		$type = strtolower( $type );
		return preg_match( '/^[a-z][a-z0-9_-]{0,31}$/', $type ) ? $type : '';
	}
}

/** Exact canonical venue authority. */
final class LocalSupportVenueOrganizerProvider implements LocalSupportOrganizerProvider {
	public function type(): string {
		return 'venue';
	}

	public function authorize( string $action, array $request, array $identity, int $user_id, LocalSupportAuthorization $authorization, bool $locked = false, ?object $scope = null ) {
		unset( $action );
		if ( (int) $identity['id'] !== (int) $request['venue_term_id'] ) {
			return $authorization->denied();
		}
		if ( $locked ) {
			$context = $authorization->event_context_locked_for_provider( (int) $request['event_id'], $scope );
			if ( is_wp_error( $context ) || (int) $context['venue_term_id'] !== (int) $request['venue_term_id'] ) {
				return is_wp_error( $context ) ? $context : $authorization->denied();
			}
		}
		if ( $locked ) {
			return $authorization->authorize_venue_locked( $user_id, (int) $identity['id'], $scope );
		}
		$policy = $authorization->venue_policy();
		return is_wp_error( $policy ) ? $policy : $policy->authorize( $user_id, (int) $identity['id'], VenueAuthorization::ACTION_ACCESS_VENUE );
	}

	public function choices( int $event_id, int $user_id, LocalSupportAuthorization $authorization ) {
		$context = $authorization->event_context( $event_id );
		if ( is_wp_error( $context ) ) {
			return $context;
		}
		$allowed = $this->authorize(
			LocalSupportAuthorization::ACTION_OPEN,
			array_merge(
				$context,
				array(
					'organizer_type' => '',
					'organizer_id'   => 0,
				)
			),
			array(
				'type' => $this->type(),
				'id'   => $context['venue_term_id'],
			),
			$user_id,
			$authorization
		);
		if ( true !== $allowed ) {
			return $authorization->is_denial( $allowed ) ? array() : $allowed;
		}
		$term = $authorization->organizer_term( $context['venue_term_id'], $this->type() );
		return $term instanceof \WP_Term ? array(
			array(
				'type'  => $this->type(),
				'id'    => $context['venue_term_id'],
				'label' => $term->name,
			),
		) : array();
	}

	public function recipient_ids( array $request, LocalSupportAuthorization $authorization ) {
		$members = ( new VenueMembershipRepository() )->list_for_venue(
			(int) $request['venue_term_id'],
			array(
				'status' => VenueAuthorization::STATUS_ACTIVE,
				'limit'  => 100,
			)
		);
		if ( is_wp_error( $members ) ) {
			return $members;
		}
		$ids = array();
		foreach ( $members as $member ) {
			$identity = array(
				'type' => $this->type(),
				'id'   => (int) $request['venue_term_id'],
			);
			$allowed  = $this->authorize( LocalSupportAuthorization::ACTION_NOTIFY, $request, $identity, (int) $member['user_id'], $authorization );
			if ( true === $allowed ) {
				$ids[] = (int) $member['user_id'];
			} elseif ( ! $authorization->is_denial( $allowed ) ) {
				return $allowed;
			}
		}
		return $ids;
	}
}

/** Booking-backed Artist organizer authority. Taxonomy attachment alone is insufficient. */
final class LocalSupportArtistOrganizerProvider implements LocalSupportOrganizerProvider {
	public function type(): string {
		return 'artist';
	}

	public function authorize( string $action, array $request, array $identity, int $user_id, LocalSupportAuthorization $authorization, bool $locked = false, ?object $scope = null ) {
		$artist_id = (int) $identity['id'];
		if ( LocalSupportAuthorization::ACTION_OPEN !== $action && ( $request['organizer_type'] ?? '' ) !== $this->type() ) {
			return $authorization->denied();
		}
		if ( LocalSupportAuthorization::ACTION_OPEN !== $action && (int) ( $request['organizer_id'] ?? 0 ) !== $artist_id ) {
			return $authorization->denied();
		}
		$booking = $this->booking( $request, $locked );
		if ( ! is_array( $booking ) || ! in_array( $booking['status'], array( 'confirmed', 'completed' ), true ) || (int) $booking['event_id'] !== (int) $request['event_id'] || (int) $booking['venue_term_id'] !== (int) $request['venue_term_id'] || (int) $booking['artist_term_id'] !== $artist_id ) {
			return is_wp_error( $booking ) ? $booking : $authorization->denied();
		}
		if ( $locked ) {
			$context = $authorization->event_context_locked_for_provider( (int) $request['event_id'], $scope );
			if ( is_wp_error( $context ) || (int) $context['venue_term_id'] !== (int) $request['venue_term_id'] ) {
				return is_wp_error( $context ) ? $context : $authorization->denied();
			}
		}
		$attached = $locked ? $authorization->artist_attached_to_event_locked( (int) $request['event_id'], $artist_id, $scope ) : $authorization->artist_attached_to_event( (int) $request['event_id'], $artist_id );
		if ( true !== $attached ) {
			return is_wp_error( $attached ) ? $attached : $authorization->denied();
		}
		return $locked ? $authorization->authorize_artist_locked( $artist_id, $user_id, $scope ) : $authorization->authorize_artist( $artist_id, $user_id );
	}

	public function prepare_transaction( string $action, array $request, array $identity, int $user_id, LocalSupportAuthorization $authorization ) {
		unset( $action, $request );
		return $authorization->prepare_artist_transaction( (int) $identity['id'], $user_id );
	}

	public function choices( int $event_id, int $user_id, LocalSupportAuthorization $authorization ) {
		$context = $authorization->event_context( $event_id );
		if ( is_wp_error( $context ) ) {
			return $context;
		}
		$booking = ( new BookingRepository() )->get_by_event( $event_id );
		if ( ! is_array( $booking ) || empty( $booking['artist_term_id'] ) ) {
			return is_wp_error( $booking ) ? $booking : array();
		}
		$identity = array(
			'type' => $this->type(),
			'id'   => (int) $booking['artist_term_id'],
		);
		$request  = array_merge(
			$context,
			array(
				'booking_id'     => (int) $booking['id'],
				'organizer_type' => $this->type(),
				'organizer_id'   => $identity['id'],
			)
		);
		$allowed  = $this->authorize( LocalSupportAuthorization::ACTION_OPEN, $request, $identity, $user_id, $authorization );
		if ( true !== $allowed ) {
			return $authorization->is_denial( $allowed ) ? array() : $allowed;
		}
		$mapped = extrachill_events_resolve_artist_term( $identity['id'] );
		$term   = is_wp_error( $mapped ) ? null : $authorization->organizer_term( (int) $mapped['term_id'], $this->type() );
		return $term instanceof \WP_Term ? array(
			array(
				'type'       => $this->type(),
				'id'         => $identity['id'],
				'label'      => $term->name,
				'booking_id' => (int) $booking['id'],
			),
		) : array();
	}

	public function recipient_ids( array $request, LocalSupportAuthorization $authorization ) {
		if ( ( $request['organizer_type'] ?? '' ) !== $this->type() || ! function_exists( 'extrachill_artist_platform_get_local_support_manager_ids' ) ) {
			return array();
		}
		$profile_id = $authorization->artist_profile_id_for_provider( (int) $request['organizer_id'] );
		if ( is_wp_error( $profile_id ) ) {
			return $profile_id;
		}
		$artist_blog_id = (int) ec_get_blog_id( 'artist' );
		switch_to_blog( $artist_blog_id );
		try {
			$ids = extrachill_artist_platform_get_local_support_manager_ids( $profile_id );
		} finally {
			restore_current_blog();
		}
		$recipients = array();
		$identity   = array(
			'type' => $this->type(),
			'id'   => (int) $request['organizer_id'],
		);
		foreach ( array_unique( array_map( 'absint', (array) $ids ) ) as $user_id ) {
			$allowed = $this->authorize( LocalSupportAuthorization::ACTION_NOTIFY, $request, $identity, $user_id, $authorization );
			if ( true === $allowed ) {
				$recipients[] = $user_id;
			} elseif ( ! $authorization->is_denial( $allowed ) ) {
				return $allowed;
			}
		}
		return $recipients;
	}

	private function booking( array $request, bool $locked ) {
		$repository = new BookingRepository();
		if ( ! empty( $request['booking_id'] ) ) {
			return $locked ? $repository->get_for_update( (int) $request['booking_id'] ) : $repository->get( (int) $request['booking_id'] );
		}
		return $locked ? $repository->get_by_event_for_update( (int) $request['event_id'] ) : $repository->get_by_event( (int) $request['event_id'] );
	}
}

/** Exact current promoter organization, membership, and delegated venue grant. */
final class LocalSupportPromoterOrganizerProvider implements LocalSupportOrganizerProvider {
	/** @var callable|null Deterministic authorization seam. */
	private $authorization_resolver;

	public function __construct( ?callable $authorization_resolver = null ) {
		$this->authorization_resolver = $authorization_resolver;
	}

	public function type(): string {
		return 'promoter';
	}

	public function authorize( string $action, array $request, array $identity, int $user_id, LocalSupportAuthorization $authorization, bool $locked = false, ?object $scope = null ) {
		unset( $action );
		if ( ! class_exists( PromoterVenueAuthorization::class ) ) {
			return $authorization->denied();
		}
		if ( $locked ) {
			$context = $authorization->event_context_locked_for_provider( (int) $request['event_id'], $scope );
			if ( is_wp_error( $context ) || (int) $context['venue_term_id'] !== (int) $request['venue_term_id'] ) {
				return is_wp_error( $context ) ? $context : $authorization->denied();
			}
		}
		if ( $locked ) {
			return $this->authorize_locked( $user_id, (int) $identity['id'], (int) $request['venue_term_id'] );
		}
		if ( $this->authorization_resolver ) {
			return call_user_func( $this->authorization_resolver, $user_id, (int) $identity['id'], (int) $request['venue_term_id'] );
		}
		return ( new PromoterVenueAuthorization() )->authorize( $user_id, (int) $identity['id'], (int) $request['venue_term_id'], PromoterVenueGrantRepository::ACTION_ORGANIZE_LOCAL_SUPPORT );
	}

	public function choices( int $event_id, int $user_id, LocalSupportAuthorization $authorization ) {
		if ( ! class_exists( PromoterAuthorityRepository::class ) || ! class_exists( PromoterVenueAuthorization::class ) ) {
			return array();
		}
		$context = $authorization->event_context( $event_id );
		if ( is_wp_error( $context ) ) {
			return $context;
		}
		$memberships = ( new PromoterAuthorityRepository() )->list_active_memberships_for_user( $user_id );
		if ( is_wp_error( $memberships ) ) {
			return $memberships;
		}
		$rows = array();
		foreach ( $memberships as $membership ) {
			$identity = array(
				'type' => $this->type(),
				'id'   => (int) $membership['promoter_term_id'],
			);
			$request  = array_merge(
				$context,
				array(
					'organizer_type' => $this->type(),
					'organizer_id'   => $identity['id'],
				)
			);
			$allowed  = $this->authorize( LocalSupportAuthorization::ACTION_OPEN, $request, $identity, $user_id, $authorization );
			if ( true !== $allowed && $authorization->is_denial( $allowed ) ) {
				continue;
			} elseif ( true !== $allowed ) {
				return $allowed;
			}
			$term = $authorization->organizer_term( $identity['id'], $this->type() );
			if ( $term instanceof \WP_Term ) {
				$rows[] = array(
					'type'  => $this->type(),
					'id'    => $identity['id'],
					'label' => $term->name,
				);
			}
		}
		return $rows;
	}

	/** Resolve one explicitly selected promoter without listing other identities. */
	public function choice( int $event_id, int $user_id, array $identity, LocalSupportAuthorization $authorization ) {
		$context = $authorization->event_context( $event_id );
		if ( is_wp_error( $context ) ) {
			return $context;
		}
		$request = array_merge(
			$context,
			array(
				'organizer_type' => $this->type(),
				'organizer_id'   => (int) $identity['id'],
			)
		);
		$allowed = $this->authorize( LocalSupportAuthorization::ACTION_OPEN, $request, $identity, $user_id, $authorization );
		if ( true !== $allowed ) {
			return $authorization->is_denial( $allowed ) ? array() : $allowed;
		}
		$term = $authorization->organizer_term( (int) $identity['id'], $this->type() );
		return $term instanceof \WP_Term ? array(
			array(
				'type'  => $this->type(),
				'id'    => (int) $identity['id'],
				'label' => $term->name,
			),
		) : array();
	}

	public function recipient_ids( array $request, LocalSupportAuthorization $authorization ) {
		if ( ! class_exists( PromoterAuthoritySchema::class ) ) {
			return array();
		}
		global $wpdb;
		$organizations = PromoterAuthoritySchema::organizations_table();
		$memberships   = PromoterAuthoritySchema::memberships_table();
		$grants        = PromoterAuthoritySchema::venue_grants_table();
		$rows          = $wpdb->get_results( $wpdb->prepare( "SELECT DISTINCT membership.user_id, membership.promoter_term_id FROM {$grants} grant_row INNER JOIN {$organizations} organization ON organization.promoter_term_id = grant_row.promoter_term_id AND organization.status = %s INNER JOIN {$memberships} membership ON membership.promoter_term_id = grant_row.promoter_term_id AND membership.status = %s WHERE grant_row.venue_term_id = %d AND grant_row.action = %s AND grant_row.status = %s LIMIT 101", PromoterAuthorityRepository::STATUS_ACTIVE, PromoterAuthorityRepository::STATUS_ACTIVE, (int) $request['venue_term_id'], PromoterVenueGrantRepository::ACTION_ORGANIZE_LOCAL_SUPPORT, PromoterAuthorityRepository::STATUS_ACTIVE ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact current notification participants.
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'local_support_promoter_recipient_read_failed', __( 'Promoter notification participants could not be read.', 'extrachill-events' ), array( 'status' => 503 ) );
		}
		if ( count( (array) $rows ) > 100 ) {
			return new \WP_Error( 'local_support_promoter_recipient_limit_exceeded', __( 'Promoter notification participants exceed the supported bound.', 'extrachill-events' ) );
		}
		$recipients = array();
		foreach ( (array) $rows as $row ) {
			$identity = array(
				'type' => $this->type(),
				'id'   => (int) $row['promoter_term_id'],
			);
			$allowed  = $this->authorize( LocalSupportAuthorization::ACTION_NOTIFY, $request, $identity, (int) $row['user_id'], $authorization );
			if ( true === $allowed ) {
				$recipients[] = (int) $row['user_id'];
			} elseif ( ! $authorization->is_denial( $allowed ) ) {
				return $allowed;
			}
		}
		return array_values( array_unique( $recipients ) );
	}

	private function authorize_locked( int $user_id, int $promoter_id, int $venue_id ) {
		global $wpdb;
		$organizations = PromoterAuthoritySchema::organizations_table();
		$memberships   = PromoterAuthoritySchema::memberships_table();
		$grants        = PromoterAuthoritySchema::venue_grants_table();
		$organization  = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$organizations} WHERE promoter_term_id = %d FOR UPDATE", $promoter_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted current-site schema table.
		$membership    = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$memberships} WHERE promoter_term_id = %d AND user_id = %d FOR UPDATE", $promoter_id, $user_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted current-site schema table.
		$grant         = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$grants} WHERE promoter_term_id = %d AND venue_term_id = %d AND action = %s FOR UPDATE", $promoter_id, $venue_id, PromoterVenueGrantRepository::ACTION_ORGANIZE_LOCAL_SUPPORT ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Trusted current-site schema table.
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'local_support_promoter_authority_read_failed', __( 'Promoter Local Support authority could not be locked.', 'extrachill-events' ), array( 'status' => 503 ) );
		}
		$ceiling = PromoterAuthorization::user_can( $user_id, VenueAuthorization::ACCESS_CAPABILITY ) && function_exists( 'ec_feature_available' ) && ec_feature_available( VenueAuthorization::FEATURE, $user_id );
		if ( ! $ceiling || PromoterAuthorityRepository::STATUS_ACTIVE !== ( $organization['status'] ?? '' ) || PromoterAuthorityRepository::STATUS_ACTIVE !== ( $membership['status'] ?? '' ) || PromoterAuthorityRepository::STATUS_ACTIVE !== ( $grant['status'] ?? '' ) ) {
			return new \WP_Error( 'local_support_forbidden', __( 'You are not authorized for this Local Support request.', 'extrachill-events' ), array( 'status' => 403 ) );
		}
		return true;
	}
}
