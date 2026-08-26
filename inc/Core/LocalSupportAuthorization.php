<?php
/**
 * Local support identity and authorization policy.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validates canonical event bindings and exact organizer/artist authority.
 *
 * The service locks Local Support request/interest rows first. Each provider
 * then owns its canonical domain order: venue locks event then membership;
 * Artist locks booking, event/Artist attachment, mapping, and reciprocal roster;
 * promoter locks event, organization, membership, and exact delegated grant.
 * Promoter consumption uses organize_local_support, never ACTION_ACCESS_VENUE.
 */
class LocalSupportAuthorization {
	public const ACTION_ORGANIZE_LOCAL_SUPPORT = 'organize_local_support';
	public const ACTION_OPEN                   = 'open';
	public const ACTION_VIEW                   = 'view';
	public const ACTION_TRANSITION_REQUEST     = 'transition_request';
	public const ACTION_REVIEW_INTERESTS       = 'review_interests';
	public const ACTION_SELECT_INTEREST        = 'select_interest';
	public const ACTION_NOTIFY                 = 'notification_participation';
	private const MAX_ARTIST_AUTHORITY_IDS     = 100;

	/**
	 * Venue authorization policy.
	 *
	 * @var VenueAuthorization
	 */
	private $venues;

	/** @var LocalSupportOrganizerProviderRegistry */
	private $organizers;

	/** @var string[] Artist relationship advisory locks held through commit/rollback. */
	private $artist_locks = array();

	/** @var string[] Events mapping advisory locks held through commit/rollback. */
	private $mapping_locks = array();

	/** @var array<int,array{binding:?string,membership:?string,scope:object}> Pre-transaction Artist lock scopes. */
	private $artist_pre_scopes = array();

	/** @var \SplObjectStorage Active service-owned transaction scopes. */
	private $transaction_scopes;

	/** @var object|null Opaque owner token held only by LocalSupportService. */
	private $transaction_owner;

	/** @var bool Connection was quarantined after uncertain advisory-lock cleanup. */
	private $authorization_quarantined = false;

	public function __construct( ?VenueAuthorization $venues = null, ?LocalSupportOrganizerProviderRegistry $organizers = null ) {
		$this->venues             = $venues ? $venues : new VenueAuthorization();
		$this->organizers         = $organizers ? $organizers : $this->default_organizers();
		$this->transaction_scopes = new \SplObjectStorage();
	}

	/** Claim this authorization instance for one service-owned transaction token. */
	public function claim_transaction_owner( object $owner ) {
		if ( null === $this->transaction_owner ) {
			$this->transaction_owner = $owner;
			return true;
		}
		return $this->transaction_owner === $owner
			? true
			: new \WP_Error( 'local_support_transaction_owner_conflict', __( 'Local support authorization already belongs to another service.', 'extrachill-events' ), array( 'status' => 409 ) );
	}

	/** Register a lock-current scope only for the owning service's opaque token. */
	public function open_transaction_scope( object $owner ) {
		if ( null === $this->transaction_owner || $this->transaction_owner !== $owner ) {
			return new \WP_Error( 'local_support_transaction_scope_required', __( 'Lock-current local support authorization requires its service transaction.', 'extrachill-events' ), array( 'status' => 503 ) );
		}
		$scope = new \stdClass();
		$this->transaction_scopes->attach( $scope );
		return $scope;
	}

	/** Resolve a valid canonical event and its one exact venue. */
	public function event_context( int $event_id ) {
		$post = get_post( $event_id );
		if ( ! $post || 'data_machine_events' !== $post->post_type || 'trash' === $post->post_status ) {
			return new \WP_Error( 'invalid_local_support_event', __( 'A valid canonical event is required.', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		$venues = $this->read_object_term_ids( $event_id, 'venue', 'local_support_event_venues_unavailable' );
		if ( is_wp_error( $venues ) ) {
			return new \WP_Error( 'local_support_event_venues_unavailable', __( 'The event venue binding could not be validated.', 'extrachill-events' ), array( 'status' => 503 ) );
		}
		if ( 1 !== count( (array) $venues ) ) {
			return new \WP_Error( 'invalid_local_support_event_venue', __( 'The event must have one canonical venue.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
		$venue_id = (int) reset( $venues );
		$venue    = $this->read_term( $venue_id, 'venue', 'local_support_event_venues_unavailable' );
		if ( is_wp_error( $venue ) ) {
			return new \WP_Error( 'local_support_event_venues_unavailable', __( 'The event venue binding could not be validated.', 'extrachill-events' ), array( 'status' => 503 ) );
		}
		if ( ! $venue || 'venue' !== $venue->taxonomy ) {
			return new \WP_Error( 'invalid_local_support_event_venue', __( 'The event venue binding is invalid.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
		return array(
			'event_id'      => $event_id,
			'venue_term_id' => $venue_id,
		);
	}

	/** Authorize and validate the organizer identity against the exact event. */
	public function authorize_organizer( array $request, int $user_id ) {
		return $this->authorize_organizer_action( self::ACTION_VIEW, $request, $user_id, $this->provenance_identity( $request ) );
	}

	/** Authorize an exact selected managed identity for one organizer action. */
	public function authorize_organizer_action( string $action, array $request, int $user_id, array $identity ) {
		$context = $this->event_context( (int) $request['event_id'] );
		if ( is_wp_error( $context ) ) {
			return $context;
		}
		if ( (int) $request['venue_term_id'] !== (int) $context['venue_term_id'] ) {
			return $this->denied();
		}
		return $this->organizers->authorize( $action, $request, $identity, $user_id, $this );
	}

	/** Authorize an organizer from authority locked in the service-owned transaction. */
	public function authorize_organizer_locked( array $request, int $user_id, object $scope ) {
		return $this->authorize_organizer_action_locked( self::ACTION_TRANSITION_REQUEST, $request, $user_id, $this->provenance_identity( $request ), $scope );
	}

	/** Lock-current authorization for one exact selected managed identity and action. */
	public function authorize_organizer_action_locked( string $action, array $request, int $user_id, array $identity, object $scope ) {
		$valid_scope = $this->validate_scope( $scope );
		if ( true !== $valid_scope ) {
			return $valid_scope;
		}
		return $this->organizers->authorize( $action, $request, $identity, $user_id, $this, true, $scope );
	}

	public function prepare_organizer_transaction( string $action, array $request, int $user_id, array $identity ) {
		return $this->organizers->prepare( $action, $request, $identity, $user_id, $this );
	}

	public function organizer_choice( int $event_id, int $user_id, array $identity ) {
		return $this->organizers->choice( $event_id, $user_id, $identity, $this );
	}

	/** Acquire Artist writer advisory locks in canonical order before START TRANSACTION. */
	public function prepare_artist_transaction( int $artist_term_id, int $user_id ) {
		global $wpdb;
		if ( $this->authorization_quarantined ) {
			return new \WP_Error( 'local_support_artist_authorization_quarantined', __( 'Artist authorization is unavailable after uncertain lock cleanup.', 'extrachill-events' ), array( 'status' => 503 ) );
		}
		if ( preg_match( '/sqlite|pgsql|postgres/', strtolower( (string) get_class( $wpdb ) ) ) ) {
			return new \WP_Error( 'local_support_artist_advisory_locks_unsupported', __( 'Artist mutation authorization requires MySQL advisory locks.', 'extrachill-events' ), array( 'status' => 503 ) );
		}
		$binding = 'ec_artist_binding_v1';
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $binding, 5 ) ) ) {
			return new \WP_Error( 'local_support_artist_binding_lock_failed', __( 'Artist binding authority could not be locked.', 'extrachill-events' ), array( 'status' => 503 ) );
		}
		$scope = new \stdClass();
		$this->artist_pre_scopes[ spl_object_id( $scope ) ] = array(
			'binding'    => $binding,
			'membership' => null,
			'scope'      => $scope,
		);
		$profile_id = $this->artist_profile_id( $artist_term_id );
		if ( is_wp_error( $profile_id ) ) {
			return $this->finish_failed_prepare( $scope, $profile_id );
		}
		$membership = sprintf( 'ec_artist_membership_%d_%d', $user_id, $profile_id );
		if ( '1' !== (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $membership, 5 ) ) ) {
			return $this->finish_failed_prepare( $scope, new \WP_Error( 'local_support_artist_authority_lock_failed', __( 'Artist authority could not be locked.', 'extrachill-events' ), array( 'status' => 503 ) ) );
		}
		$this->artist_pre_scopes[ spl_object_id( $scope ) ]['membership'] = $membership;
		return $scope;
	}

	/** Resolve early prepare failure without returning while session locks remain uncertain. */
	private function finish_failed_prepare( object $scope, \WP_Error $cause ): \WP_Error {
		global $wpdb;
		for ( $attempt = 1; $attempt <= 3; ++$attempt ) {
			$closed = $this->close_pretransaction_scope( $scope );
			if ( true === $closed ) {
				return $cause;
			}
		}
		$disconnected = false;
		try {
			$disconnected = true === $wpdb->close();
		} catch ( \Throwable $throwable ) {
			unset( $throwable );
		}
		$wpdb->ready                     = false;
		$this->artist_pre_scopes         = array();
		$this->authorization_quarantined = true;
		return new \WP_Error(
			'local_support_artist_pretransaction_cleanup_failed',
			__( 'Artist authorization lock cleanup was uncertain and its connection was retired.', 'extrachill-events' ),
			array(
				'status'                 => 503,
				'cause'                  => $cause->get_error_code(),
				'connection_quarantined' => true,
				'disconnect_confirmed'   => $disconnected,
			)
		);
	}

	/** Release Artist writer locks only after transaction completion. */
	public function close_pretransaction_scope( $scope ) {
		global $wpdb;
		$scope_id = is_object( $scope ) ? spl_object_id( $scope ) : 0;
		if ( $scope_id < 1 || ! isset( $this->artist_pre_scopes[ $scope_id ] ) ) {
			return true;
		}
		$failure = null;
		foreach ( array( 'membership', 'binding' ) as $key ) {
			$name = $this->artist_pre_scopes[ $scope_id ][ $key ] ?? null;
			if ( ! is_string( $name ) || '' === $name ) {
				continue;
			}
			if ( '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) ) ) {
				$this->artist_pre_scopes[ $scope_id ][ $key ] = null;
			} else {
				$failure = new \WP_Error(
					'local_support_artist_authority_release_failed',
					__( 'Artist authority lock cleanup failed.', 'extrachill-events' ),
					array(
						'status' => 503,
						'lock'   => $key,
					)
				);
			}
		}
		if ( null === ( $this->artist_pre_scopes[ $scope_id ]['membership'] ?? null ) && null === ( $this->artist_pre_scopes[ $scope_id ]['binding'] ?? null ) ) {
			unset( $this->artist_pre_scopes[ $scope_id ] );
		}
		return $failure ? $failure : true;
	}

	/** Lock canonical event venue context within one provider-owned sequence. */
	public function event_context_locked_for_provider( int $event_id, object $scope ) {
		$valid = $this->validate_scope( $scope );
		return true === $valid ? $this->locked_event_context( $event_id ) : $valid;
	}

	/** Resolve every currently authorized organizer identity for an event. */
	public function organizer_choices( int $event_id, int $user_id ) {
		return $this->organizers->choices( $event_id, $user_id, $this );
	}

	/** Resolve exact current notification participants across all providers. */
	public function organizer_recipient_ids( array $request ) {
		return $this->organizers->recipient_ids( $request, $this );
	}

	/** Expose the injected venue policy only to the Events-local venue provider. */
	public function venue_policy(): VenueAuthorization {
		return $this->venues;
	}

	/** Lock exact venue authority for the Events-local venue provider. */
	public function authorize_venue_locked( int $user_id, int $venue_term_id, ?object $scope ) {
		$valid = $scope ? $this->validate_scope( $scope ) : $this->denied();
		return true === $valid ? $this->authorize_locked_venue( $user_id, $venue_term_id ) : $valid;
	}

	/** Provider-safe access to the canonical reciprocal Artist profile binding. */
	public function artist_profile_id_for_provider( int $artist_term_id ) {
		return $this->artist_profile_id( $artist_term_id );
	}

	/** Shared non-enumerating denial for provider implementations. */
	public function denied(): \WP_Error {
		return new \WP_Error( 'local_support_forbidden', __( 'You are not authorized for this local support request.', 'extrachill-events' ), array( 'status' => 403 ) );
	}

	/** Whether an error is a normal authority denial rather than an operational fault. */
	public function is_denial( $result ): bool {
		return is_wp_error( $result ) && in_array( $result->get_error_code(), array( 'local_support_forbidden', 'venue_action_forbidden', 'promoter_venue_action_forbidden' ), true );
	}

	/** Lock and read one exact event artist attachment in a service-owned scope. */
	public function artist_attached_to_event_locked( int $event_id, int $artist_term_id, object $scope ) {
		$valid_scope = $this->validate_scope( $scope );
		return true === $valid_scope ? $this->locked_artist_attachment( $event_id, $artist_term_id ) : $valid_scope;
	}

	/** Authorize one canonical artist manager through its bound profile. */
	public function authorize_artist( int $artist_term_id, int $user_id ) {
		$profile_id = $this->artist_profile_id( $artist_term_id );
		if ( is_wp_error( $profile_id ) ) {
			return $profile_id;
		}
		if ( ! function_exists( 'ec_get_artists_for_user' ) || ! in_array( $profile_id, array_map( 'intval', ec_get_artists_for_user( $user_id ) ), true ) ) {
			return $this->denied();
		}
		return true;
	}

	/** Lock and authorize the reciprocal persisted artist relationship. */
	public function authorize_artist_locked( int $artist_term_id, int $user_id, object $scope ) {
		global $wpdb;
		$valid_scope = $this->validate_scope( $scope );
		if ( true !== $valid_scope ) {
			return $valid_scope;
		}
		$profile_id = $this->artist_profile_id( $artist_term_id );
		if ( is_wp_error( $profile_id ) ) {
			return $profile_id;
		}
		if ( $user_id < 1 || ! get_userdata( $user_id ) ) {
			return $this->denied();
		}
		$lock_name = sprintf( 'ec_artist_membership_%d_%d', $user_id, $profile_id );
		$held      = false;
		foreach ( $this->artist_pre_scopes as $locks ) {
			$held = $held || $lock_name === $locks['membership'];
		}
		if ( ! $held ) {
			return new \WP_Error( 'local_support_artist_pretransaction_scope_required', __( 'Artist authority requires its canonical pre-transaction locks.', 'extrachill-events' ), array( 'status' => 503 ) );
		}

		$artist_blog_id = (int) ec_get_blog_id( 'artist' );
		$main_blog_id   = (int) ec_get_blog_id( 'main' );
		$user_table     = $wpdb->usermeta;
		// Keep the writer's profile-then-term order. Its old-term inversion remains
		// an Artist Platform protocol issue: Extra-Chill/extrachill-artist-platform#196.
		switch_to_blog( $artist_blog_id );
		try {
			$artist_table          = $wpdb->postmeta;
			$profile_binding_rows  = $wpdb->get_results( $wpdb->prepare( "SELECT meta_id, meta_value FROM {$artist_table} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id ASC LIMIT 2 FOR UPDATE", $profile_id, '_artist_term_id' ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Canonical writer order: exact profile binding first.
			$profile_binding_error = (string) $wpdb->last_error;
		} finally {
			restore_current_blog();
		}
		if ( '' !== $profile_binding_error ) {
			return new \WP_Error( 'local_support_artist_authority_read_failed', __( 'Artist authority could not be read.', 'extrachill-events' ), array( 'status' => 503 ) );
		}
		switch_to_blog( $main_blog_id );
		try {
			$term_table         = $wpdb->termmeta;
			$term_binding_rows  = $wpdb->get_results( $wpdb->prepare( "SELECT meta_id, meta_value FROM {$term_table} WHERE term_id = %d AND meta_key = %s ORDER BY meta_id ASC LIMIT 2 FOR UPDATE", $artist_term_id, '_artist_profile_id' ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Canonical writer order: reciprocal term binding second.
			$term_binding_error = (string) $wpdb->last_error;
		} finally {
			restore_current_blog();
		}
		if ( '' !== $term_binding_error ) {
			return new \WP_Error( 'local_support_artist_authority_read_failed', __( 'Artist authority could not be read.', 'extrachill-events' ), array( 'status' => 503 ) );
		}
		if ( 1 !== count( (array) $profile_binding_rows ) || 1 !== count( (array) $term_binding_rows ) ) {
			return new \WP_Error( 'local_support_artist_binding_corrupt', __( 'The canonical artist binding is missing or duplicated.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
		$locked_term_id    = $this->authority_scalar_id( $profile_binding_rows[0]['meta_value'] );
		$locked_profile_id = $this->authority_scalar_id( $term_binding_rows[0]['meta_value'] );
		if ( null === $locked_term_id || null === $locked_profile_id ) {
			return new \WP_Error( 'local_support_artist_binding_corrupt', __( 'The canonical artist binding contains an invalid identifier.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
		if ( $artist_term_id !== $locked_term_id || $profile_id !== $locked_profile_id ) {
			return new \WP_Error( 'invalid_local_support_artist', __( 'The artist profile binding changed.', 'extrachill-events' ), array( 'status' => 409 ) );
		}

		switch_to_blog( $artist_blog_id );
		try {
			$artist_rows  = $wpdb->get_results( $wpdb->prepare( "SELECT meta_id, meta_value FROM {$artist_table} WHERE post_id = %d AND meta_key = %s ORDER BY meta_id ASC LIMIT 2 FOR UPDATE", $profile_id, '_artist_member_ids' ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact artist-side reciprocal authority with one-row overflow probe.
			$artist_error = (string) $wpdb->last_error;
		} finally {
			restore_current_blog();
		}
		if ( '' !== $artist_error ) {
			return new \WP_Error( 'local_support_artist_authority_read_failed', __( 'Artist authority could not be read.', 'extrachill-events' ), array( 'status' => 503 ) );
		}
		$user_rows = $wpdb->get_results( $wpdb->prepare( "SELECT umeta_id, meta_value FROM {$user_table} WHERE user_id = %d AND meta_key = %s ORDER BY umeta_id ASC LIMIT 2 FOR UPDATE", $user_id, '_artist_profile_ids' ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact user-side reciprocal authority with one-row overflow probe.
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'local_support_artist_authority_read_failed', __( 'Artist authority could not be read.', 'extrachill-events' ), array( 'status' => 503 ) );
		}
		if ( 1 !== count( (array) $artist_rows ) || 1 !== count( (array) $user_rows ) ) {
			return count( (array) $artist_rows ) > 1 || count( (array) $user_rows ) > 1
				? new \WP_Error( 'local_support_artist_authority_rows_corrupt', __( 'Artist authority contains duplicate relationship records.', 'extrachill-events' ), array( 'status' => 409 ) )
				: $this->denied();
		}
		$member_ids  = $this->authority_ids( $artist_rows[0]['meta_value'] );
		$profile_ids = $this->authority_ids( $user_rows[0]['meta_value'] );
		if ( is_wp_error( $member_ids ) || is_wp_error( $profile_ids ) ) {
			return is_wp_error( $member_ids ) ? $member_ids : $profile_ids;
		}
		return in_array( $user_id, $member_ids, true ) && in_array( $profile_id, $profile_ids, true ) ? true : $this->denied();
	}

	/** Release relationship advisory locks after the owning transaction ends. */
	public function close_transaction_scope( object $scope ) {
		global $wpdb;
		$has_transaction_scope = $this->transaction_scopes->contains( $scope );
		for ( $index = count( $this->artist_locks ) - 1; $index >= 0; --$index ) {
			$lock_name = $this->artist_locks[ $index ];
			$released  = $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Releases the canonical relationship lock after transaction completion.
			if ( '1' !== (string) $released ) {
				return new \WP_Error( 'local_support_artist_authority_release_failed', __( 'Artist authority lock cleanup failed.', 'extrachill-events' ), array( 'status' => 503 ) );
			}
			array_splice( $this->artist_locks, $index, 1 );
		}
		for ( $index = count( $this->mapping_locks ) - 1; $index >= 0; --$index ) {
			$released = ArtistMappingLock::release( $this->mapping_locks[ $index ] );
			if ( is_wp_error( $released ) ) {
				return $released;
			}
			array_splice( $this->mapping_locks, $index, 1 );
		}
		if ( $has_transaction_scope ) {
			$this->transaction_scopes->detach( $scope );
		}
		foreach ( $this->artist_pre_scopes as $locks ) {
			$released = $this->close_pretransaction_scope( $locks['scope'] );
			if ( is_wp_error( $released ) ) {
				return $released;
			}
		}
		return true;
	}

	/** Best-effort cleanup if a caller is interrupted before primary scope closure. */
	public function __destruct() {
		$scope_count = count( $this->transaction_scopes );
		while ( $scope_count > 0 ) {
			$this->transaction_scopes->rewind();
			$result = $this->close_transaction_scope( $this->transaction_scopes->current() );
			if ( is_wp_error( $result ) ) {
				break;
			}
			--$scope_count;
		}
		foreach ( $this->artist_pre_scopes as $locks ) {
			$this->finish_failed_prepare( $locks['scope'], new \WP_Error( 'local_support_artist_pretransaction_orphaned' ) );
		}
	}

	/** Determine whether a canonical artist is explicitly attached to the event. */
	public function artist_attached_to_event( int $event_id, int $artist_term_id ) {
		if ( ! function_exists( 'extrachill_events_resolve_artist_term' ) ) {
			return new \WP_Error( 'local_support_artist_mapping_unavailable', __( 'Canonical artist mapping is unavailable.', 'extrachill-events' ), array( 'status' => 503 ) );
		}
		$mapped = extrachill_events_resolve_artist_term( $artist_term_id );
		if ( is_wp_error( $mapped ) ) {
			return $mapped;
		}
		$artists = $this->event_artist_term_ids( $event_id );
		if ( is_wp_error( $artists ) ) {
			return new \WP_Error( 'local_support_event_artists_unavailable', __( 'Event artist bindings could not be validated.', 'extrachill-events' ), array( 'status' => 503 ) );
		}
		return in_array( (int) $mapped['term_id'], array_map( 'intval', (array) $artists ), true );
	}

	/** Read attached Events artist IDs without hiding database failures. */
	public function event_artist_term_ids( int $event_id ) {
		return $this->read_object_term_ids( $event_id, 'artist', 'local_support_event_artists_unavailable' );
	}

	/** Read one organizer term without using a potentially stale object cache. */
	public function organizer_term( int $term_id, string $taxonomy ) {
		$error_code = 'venue' === $taxonomy ? 'local_support_event_venues_unavailable' : 'local_support_event_artists_unavailable';
		return $this->read_term( $term_id, $taxonomy, $error_code );
	}

	/** Resolve and verify a canonical term's bidirectional Artist Platform profile. */
	protected function artist_profile_id( int $artist_term_id ) {
		$main_blog_id   = function_exists( 'ec_get_blog_id' ) ? (int) ec_get_blog_id( 'main' ) : 0;
		$artist_blog_id = function_exists( 'ec_get_blog_id' ) ? (int) ec_get_blog_id( 'artist' ) : 0;
		if ( $main_blog_id < 1 || $artist_blog_id < 1 || $artist_term_id < 1 ) {
			return new \WP_Error( 'invalid_local_support_artist', __( 'A valid canonical artist is required.', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		switch_to_blog( $main_blog_id );
		try {
			$term = $this->read_term( $artist_term_id, 'artist', 'local_support_artist_terms_unavailable' );
			if ( is_wp_error( $term ) ) {
				return $term;
			}
			$profile_id = $term ? absint( get_term_meta( $artist_term_id, '_artist_profile_id', true ) ) : 0;
		} finally {
			restore_current_blog();
		}
		if ( $profile_id < 1 ) {
			return new \WP_Error( 'invalid_local_support_artist', __( 'The canonical artist has no bound profile.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
		switch_to_blog( $artist_blog_id );
		try {
			$profile = get_post( $profile_id );
			$bound   = absint( get_post_meta( $profile_id, '_artist_term_id', true ) );
		} finally {
			restore_current_blog();
		}
		if ( ! $profile || 'artist_profile' !== $profile->post_type || 'publish' !== $profile->post_status || $bound !== $artist_term_id ) {
			return new \WP_Error( 'invalid_local_support_artist', __( 'The artist profile binding is invalid.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
		return $profile_id;
	}

	/** Execute one uncached object-term query and preserve wpdb failures. */
	private function read_object_term_ids( int $event_id, string $taxonomy, string $error_code ) {
		global $wpdb;

		$wpdb->flush();
		$terms          = wp_get_object_terms(
			$event_id,
			$taxonomy,
			array(
				'fields'        => 'ids',
				'cache_results' => false,
			)
		);
		$database_error = (string) $wpdb->last_error;
		if ( '' !== $database_error || is_wp_error( $terms ) ) {
			return new \WP_Error( $error_code, __( 'Event taxonomy bindings could not be validated.', 'extrachill-events' ), array( 'status' => 503 ) );
		}
		return array_map( 'intval', (array) $terms );
	}

	/** Execute one uncached term query and preserve wpdb failures. */
	private function read_term( int $term_id, string $taxonomy, string $error_code ) {
		global $wpdb;

		$wpdb->flush();
		$query = new \WP_Term_Query();
		$terms = $query->query(
			array(
				'taxonomy'      => $taxonomy,
				'hide_empty'    => false,
				'include'       => array( $term_id ),
				'number'        => 1,
				'cache_results' => false,
			)
		);
		if ( ! is_array( $terms ) ) {
			return new \WP_Error( $error_code, __( 'Organizer taxonomy data could not be validated.', 'extrachill-events' ), array( 'status' => 503 ) );
		}
		$database_error = (string) $wpdb->last_error;
		if ( '' !== $database_error ) {
			return new \WP_Error( $error_code, __( 'Organizer taxonomy data could not be validated.', 'extrachill-events' ), array( 'status' => 503 ) );
		}
		return empty( $terms ) ? null : reset( $terms );
	}

	/** Lock the event's bounded venue relationship range and derive its venue. */
	private function locked_event_context( int $event_id ) {
		global $wpdb;
		$post = get_post( $event_id );
		if ( ! $post || 'data_machine_events' !== $post->post_type || 'trash' === $post->post_status ) {
			return new \WP_Error( 'invalid_local_support_event', __( 'A valid canonical event is required.', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		$relationships = $wpdb->term_relationships;
		$taxonomy      = $wpdb->term_taxonomy;
		$rows          = $wpdb->get_results( $wpdb->prepare( "SELECT tt.term_id, tr.term_taxonomy_id FROM {$relationships} tr INNER JOIN {$taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tr.object_id = %d AND tt.taxonomy = %s ORDER BY tr.term_taxonomy_id ASC LIMIT 2 FOR UPDATE", $event_id, 'venue' ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- MySQL REPEATABLE READ next-key lock protects the event venue range with one-row overflow probe.
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'local_support_event_venue_lock_failed', __( 'The event venue could not be locked.', 'extrachill-events' ), array( 'status' => 503 ) );
		}
		if ( 1 !== count( (array) $rows ) ) {
			return new \WP_Error( 'invalid_local_support_event_venue', __( 'The event must have one canonical venue.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
		$venue_id = (int) $rows[0]['term_id'];
		$venue    = get_term( $venue_id, 'venue' );
		if ( $venue_id < 1 || ! $venue || is_wp_error( $venue ) || 'venue' !== $venue->taxonomy ) {
			return new \WP_Error( 'invalid_local_support_event_venue', __( 'The event venue binding is invalid.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
		return array(
			'event_id'      => $event_id,
			'venue_term_id' => $venue_id,
		);
	}

	/** Lock one exact mapped Events artist relationship and return attachment state. */
	private function locked_artist_attachment( int $event_id, int $artist_term_id ) {
		global $wpdb;
		if ( ! function_exists( 'extrachill_events_resolve_artist_term' ) ) {
			return new \WP_Error( 'local_support_artist_mapping_unavailable', __( 'Canonical artist mapping is unavailable.', 'extrachill-events' ), array( 'status' => 503 ) );
		}
		$mapped = extrachill_events_resolve_artist_term( $artist_term_id );
		if ( is_wp_error( $mapped ) ) {
			return $mapped;
		}
		$relationships = $wpdb->term_relationships;
		$taxonomy      = $wpdb->term_taxonomy;
		$rows          = $wpdb->get_results( $wpdb->prepare( "SELECT tt.term_id, tr.term_taxonomy_id FROM {$relationships} tr INNER JOIN {$taxonomy} tt ON tt.term_taxonomy_id = tr.term_taxonomy_id WHERE tr.object_id = %d AND tt.taxonomy = %s AND tt.term_id = %d ORDER BY tr.term_taxonomy_id ASC LIMIT 2 FOR UPDATE", $event_id, 'artist', (int) $mapped['term_id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- REPEATABLE READ next-key lock protects the exact event/artist relationship and insertion gap.
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'local_support_event_artists_unavailable', __( 'Event artist bindings could not be locked.', 'extrachill-events' ), array( 'status' => 503 ) );
		}
		if ( count( (array) $rows ) > 1 ) {
			return new \WP_Error( 'local_support_event_artist_binding_corrupt', __( 'The event artist binding is duplicated.', 'extrachill-events' ), array( 'status' => 409 ) );
		}

		$mapping_lock = ArtistMappingLock::acquire( (int) $mapped['term_id'] );
		if ( is_wp_error( $mapping_lock ) ) {
			return new \WP_Error( 'local_support_artist_mapping_lock_failed', __( 'Canonical artist mapping could not be serialized.', 'extrachill-events' ), array( 'status' => 503 ) );
		}
		$this->mapping_locks[] = $mapping_lock;

		$main_blog_id = (int) ec_get_blog_id( 'main' );
		switch_to_blog( $main_blog_id );
		try {
			$claims        = extrachill_events_read_artist_mapping_claims( (int) $mapped['term_id'] );
			$mapping_table = $wpdb->termmeta;
			$mapping_rows  = $wpdb->get_results( $wpdb->prepare( "SELECT meta_id, meta_value FROM {$mapping_table} WHERE term_id = %d AND meta_key = %s ORDER BY meta_id ASC LIMIT 2 FOR UPDATE", $artist_term_id, '_extrachill_events_artist_term_id' ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Locks the canonical-to-Events mapping used to identify the exact relationship.
			$mapping_error = (string) $wpdb->last_error;
		} finally {
			restore_current_blog();
		}
		if ( '' !== $mapping_error ) { // @phpstan-ignore-line
			return new \WP_Error( 'local_support_artist_mapping_unavailable', __( 'Canonical artist mapping could not be locked.', 'extrachill-events' ), array( 'status' => 503 ) );
		}
		if ( is_wp_error( $claims ) || array( $artist_term_id ) !== array_values( array_unique( array_map( 'intval', (array) $claims ) ) ) ) {
			return new \WP_Error( 'local_support_artist_mapping_claims_invalid', __( 'Canonical artist mapping has conflicting claims.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
		$locked_mapping = 1 === count( (array) $mapping_rows ) ? $this->authority_scalar_id( $mapping_rows[0]['meta_value'] ) : null;
		if ( null === $locked_mapping || $locked_mapping !== (int) $mapped['term_id'] ) {
			return new \WP_Error( 'local_support_artist_mapping_changed', __( 'Canonical artist mapping changed during authorization.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
		return 1 === count( (array) $rows );
	}

	/** Lock the actor's unique venue membership natural key and authorize from it. */
	private function authorize_locked_venue( int $user_id, int $venue_term_id ) {
		global $wpdb;
		$table = BookingSchema::memberships_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE venue_term_id = %d AND user_id = %d FOR UPDATE", $venue_term_id, $user_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Unique actor/venue authority natural key.
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'venue_membership_read_failed', __( 'The venue membership could not be locked.', 'extrachill-events' ), array( 'status' => 503 ) );
		}
		return $this->venues->authorize_locked( $user_id, $venue_term_id, VenueAuthorization::ACTION_ACCESS_VENUE, is_array( $row ) ? array( $row ) : array() );
	}

	/** Ensure a lock helper is called only with this authorization's active scope. */
	private function validate_scope( object $scope ) {
		return $this->transaction_scopes->contains( $scope )
			? true
			: new \WP_Error( 'local_support_transaction_scope_required', __( 'Lock-current local support authorization requires its service transaction.', 'extrachill-events' ), array( 'status' => 503 ) );
	}

	/** Decode one bounded relationship collection without repairing corrupt state. */
	private function authority_ids( $stored ) {
		$ids = maybe_unserialize( $stored );
		if ( ! is_array( $ids ) || count( $ids ) > self::MAX_ARTIST_AUTHORITY_IDS ) {
			return new \WP_Error( 'local_support_artist_authority_corrupt', __( 'Artist authority is invalid or exceeds its supported limit.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
		$normalized = array();
		foreach ( $ids as $id ) {
			if ( ( ! is_int( $id ) && ! ( is_string( $id ) && ctype_digit( $id ) ) ) || (int) $id < 1 ) {
				return new \WP_Error( 'local_support_artist_authority_corrupt', __( 'Artist authority contains an invalid identifier.', 'extrachill-events' ), array( 'status' => 409 ) );
			}
			$normalized[] = (int) $id;
		}
		return count( $normalized ) === count( array_unique( $normalized ) ) ? $normalized : new \WP_Error( 'local_support_artist_authority_corrupt', __( 'Artist authority contains duplicate identifiers.', 'extrachill-events' ), array( 'status' => 409 ) );
	}

	/** Decode one positive scalar binding identifier without coercing corrupt data. */
	private function authority_scalar_id( $stored ): ?int {
		$id = maybe_unserialize( $stored );
		return ( is_int( $id ) || ( is_string( $id ) && ctype_digit( $id ) ) ) && (int) $id > 0 ? (int) $id : null;
	}

	/** Return a non-enumerating denial. */
	private function provenance_identity( array $request ): array {
		return array(
			'type' => sanitize_key( (string) ( $request['organizer_type'] ?? '' ) ),
			'id'   => absint( $request['organizer_id'] ?? 0 ),
		);
	}

	/** Register the three concrete consumers without leaking their types into the generic path. */
	private function default_organizers(): LocalSupportOrganizerProviderRegistry {
		$registry = new LocalSupportOrganizerProviderRegistry();
		foreach ( array( new LocalSupportVenueOrganizerProvider(), new LocalSupportArtistOrganizerProvider(), new LocalSupportPromoterOrganizerProvider() ) as $provider ) {
			$registry->register( $provider );
		}
		return $registry;
	}
}
