<?php
/**
 * Venue claim, account handoff, and invitation delivery policy.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Composes Events-owned onboarding with existing user and email primitives. */
class VenueOnboardingService {

	public const INVITATION_TTL           = 604800;
	private const ACCOUNT_PROVENANCE_META = 'ec_venue_invitation_account';

	/** Venue onboarding persistence. */
	private $repository;

	/** Invitation token service. */
	private $tokens;

	/** Venue membership authorization. */
	private $authorization;

	/** Build the onboarding policy service. */
	public function __construct( ?VenueOnboardingRepository $repository = null, ?VenueInvitationToken $tokens = null, ?VenueAuthorization $authorization = null ) {
		$this->tokens        = $tokens ? $tokens : new VenueInvitationToken();
		$this->authorization = $authorization ? $authorization : new VenueAuthorization();
		$this->repository    = $repository ? $repository : new VenueOnboardingRepository( $this->tokens, $this->authorization );
	}

	/** Submit a venue claim. */
	public function submit_claim( int $actor_user_id, int $venue_term_id ) {
		return $this->repository->submit_claim( $actor_user_id, $venue_term_id );
	}

	/** Review a pending venue claim. */
	public function review_claim( int $actor_user_id, int $claim_id, string $decision, int $expected_version ) {
		return $this->repository->review_claim( $actor_user_id, $claim_id, $decision, $expected_version );
	}

	/** Cancel a pending venue claim. */
	public function cancel_claim( int $actor_user_id, int $claim_id, int $expected_version ) {
		return $this->repository->cancel_claim( $actor_user_id, $claim_id, $expected_version );
	}

	/** List operator-visible venue claims. */
	public function list_claims( int $actor_user_id, string $status = '' ) {
		return $this->repository->list_claims( $actor_user_id, $status );
	}

	/**
	 * Resolve or create the exactly-bound user, persist the invitation, and queue delivery.
	 *
	 * @param int    $actor_user_id Actor user ID.
	 * @param int    $venue_term_id Venue term ID.
	 * @param string $email         Invitee email address.
	 * @param bool   $is_owner      Whether structural owner authority is invited.
	 */
	public function invite( int $actor_user_id, int $venue_term_id, string $email, bool $is_owner ) {
		$email = strtolower( sanitize_email( $email ) );
		if ( ! is_email( $email ) ) {
			return new \WP_Error( 'invalid_venue_invitation_email', __( 'A valid invitation email is required.', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		$allowed = $this->authorization->authorize( $actor_user_id, $venue_term_id, VenueAuthorization::ACTION_MANAGE_MEMBERS );
		if ( true !== $allowed ) {
			return $allowed;
		}

		$account = $this->resolve_account( $email );
		if ( is_wp_error( $account ) ) {
			return $account;
		}

		$token      = $this->tokens->generate();
		$email_hash = hash_hmac( 'sha256', $email, wp_salt( 'auth' ) );
		$invitation = $this->repository->create_invitation( $actor_user_id, $venue_term_id, $account['user_id'], $is_owner, $email_hash, $token, self::INVITATION_TTL, $account['created'] );
		if ( is_wp_error( $invitation ) ) {
			return $this->compensate_initial_account( $account, $email, $invitation );
		}
		if ( $account['created'] && ! $this->persist_account_provenance( $account['user_id'], $invitation['public_id'] ) ) {
			return $this->compensate_persisted_invitation( $invitation, $actor_user_id, $email, new \WP_Error( 'venue_invitation_provenance_failed', __( 'The invitation account provenance could not be saved.', 'extrachill-events' ), array( 'status' => 500 ) ), 'invitation_provenance_failed' );
		}

		$queued = $this->queue_delivery( $invitation, $token, $account['claim_url'] );
		if ( is_wp_error( $queued ) ) {
			return $this->compensate_persisted_invitation( $invitation, $actor_user_id, $email, $queued, 'invitation_delivery_failed' );
		}
		$this->repository->audit_delivery( $invitation, $actor_user_id, true );
		$result                    = $this->repository->public_invitation( $invitation );
		$result['delivery_queued'] = true;
		$result['account_created'] = $account['created'];
		return $result;
	}

	/**
	 * Rotate the token before issuing another delivery request.
	 *
	 * @param int $actor_user_id   Actor user ID.
	 * @param int $invitation_id   Invitation row ID.
	 * @param int $expected_version Expected invitation version.
	 */
	public function resend( int $actor_user_id, int $invitation_id, int $expected_version ) {
		$token      = $this->tokens->generate();
		$invitation = $this->repository->resend_invitation( $actor_user_id, $invitation_id, $expected_version, $token, self::INVITATION_TTL );
		if ( is_wp_error( $invitation ) ) {
			return $invitation;
		}
		$user = get_userdata( $invitation['user_id'] );
		if ( ! $user ) {
			return new \WP_Error( 'venue_invitation_user_missing', __( 'The invitation user no longer exists.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
		$claim_url = '1' === (string) get_user_meta( $user->ID, 'ec_unclaimed', true ) ? $this->build_claim_url( $user ) : '';
		if ( is_wp_error( $claim_url ) ) {
			return $this->compensate_persisted_invitation( $invitation, $actor_user_id, strtolower( sanitize_email( $user->user_email ) ), $claim_url, 'invitation_reset_key_failed' );
		}
		$queued = $this->queue_delivery( $invitation, $token, $claim_url );
		if ( is_wp_error( $queued ) ) {
			return $this->compensate_persisted_invitation( $invitation, $actor_user_id, strtolower( sanitize_email( $user->user_email ) ), $queued, 'invitation_delivery_failed' );
		}
		$this->repository->audit_delivery( $invitation, $actor_user_id, true );
		$result                    = $this->repository->public_invitation( $invitation );
		$result['delivery_queued'] = $queued;
		return $result;
	}

	/** Cancel a pending invitation. */
	public function cancel_invitation( int $actor_user_id, int $invitation_id, int $expected_version ) {
		$result = $this->repository->cancel_invitation( $actor_user_id, $invitation_id, $expected_version );
		if ( is_array( $result ) ) {
			$cleanup = $this->cleanup_invited_account( $result );
			return is_wp_error( $cleanup ) ? $cleanup : $this->repository->public_invitation( $result );
		}
		return $result;
	}

	/** Accept or reject an exactly-bound invitation. */
	public function respond( int $actor_user_id, string $public_id, string $token, int $venue_term_id, bool $is_owner, int $expected_version, string $decision ) {
		$result = $this->repository->respond_to_invitation( $actor_user_id, $public_id, $token, $venue_term_id, $is_owner, $expected_version, $decision );
		if ( is_wp_error( $result ) && 'venue_invitation_expired' === $result->get_error_code() ) {
			$data       = $result->get_error_data();
			$invitation = is_array( $data ) ? ( $data['_cleanup_invitation'] ?? null ) : null;
			if ( is_array( $invitation ) ) {
				$cleanup = $this->cleanup_invited_account( $invitation );
				if ( is_wp_error( $cleanup ) ) {
					return $cleanup;
				}
			}
			return new \WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => 410 ) );
		}
		if ( is_array( $result ) && VenueOnboardingRepository::INVITE_ACCEPTED !== $result['status'] ) {
			$cleanup = $this->cleanup_invited_account( $result );
			if ( is_wp_error( $cleanup ) ) {
				return $cleanup;
			}
		}
		return is_array( $result ) ? $this->repository->public_invitation( $result ) : $result;
	}

	/** List invitations for a managed venue. */
	public function list_invitations( int $actor_user_id, int $venue_term_id ) {
		$result = $this->repository->list_invitations( $actor_user_id, $venue_term_id );
		if ( ! is_array( $result ) ) {
			return $result;
		}
		return array_map( array( $this->repository, 'public_invitation' ), $result );
	}

	/**
	 * Reuse the canonical network user creation ability for unclaimed accounts.
	 *
	 * @param string $email Verified invitee email.
	 */
	private function resolve_account( string $email ) {
		$user = get_user_by( 'email', $email );
		if ( $user instanceof \WP_User ) {
			$claim_url = '1' === (string) get_user_meta( $user->ID, 'ec_unclaimed', true ) ? $this->build_claim_url( $user ) : '';
			if ( is_wp_error( $claim_url ) ) {
				return $claim_url;
			}
			return array(
				'user_id'   => (int) $user->ID,
				'created'   => false,
				'claim_url' => $claim_url,
			);
		}
		$create = function_exists( 'wp_get_ability' ) ? wp_get_ability( 'extrachill/create-user' ) : null;
		if ( ! $create ) {
			return new \WP_Error( 'venue_invitation_account_unavailable', __( 'Network account creation is unavailable.', 'extrachill-events' ), array( 'status' => 503 ) );
		}
		$local_part = strstr( $email, '@', true );
		$username   = function_exists( 'ec_generate_username_from_email' )
			? ec_generate_username_from_email( $email )
			: sanitize_title( substr( false !== $local_part ? $local_part : 'venue', 0, 50 ) );
		$user_id    = $create->execute(
			array(
				'email'               => $email,
				'password'            => wp_generate_password( 32 ),
				'username'            => $username,
				'role'                => 'subscriber',
				'unclaimed'           => true,
				'registration_source' => 'venue_invitation',
				'registration_method' => 'standard',
			)
		);
		if ( is_wp_error( $user_id ) || ! $user_id ) {
			return is_wp_error( $user_id ) ? $user_id : new \WP_Error( 'venue_invitation_account_failed', __( 'The invited account could not be created.', 'extrachill-events' ) );
		}
		$user = get_userdata( (int) $user_id );
		if ( ! $user ) {
			return new \WP_Error( 'venue_invitation_account_missing', __( 'The invited account could not be resolved.', 'extrachill-events' ) );
		}
		$claim_url = $this->build_claim_url( $user );
		if ( is_wp_error( $claim_url ) ) {
			return $this->compensate_initial_account(
				array(
					'user_id' => (int) $user_id,
					'created' => true,
				),
				$email,
				$claim_url
			);
		}
		return array(
			'user_id'   => (int) $user_id,
			'created'   => true,
			'claim_url' => $claim_url,
		);
	}

	/**
	 * Generate the existing WordPress one-time password handoff URL.
	 *
	 * @param \WP_User $user Invited network user.
	 */
	private function build_claim_url( \WP_User $user ) {
		$key = get_password_reset_key( $user );
		if ( is_wp_error( $key ) ) {
			return new \WP_Error( 'venue_invitation_reset_key_failed', __( 'The invited account handoff could not be created.', 'extrachill-events' ), array( 'status' => 500 ) );
		}
		$base = function_exists( 'ec_get_site_url' ) ? ec_get_site_url( 'community' ) : network_site_url();
		return trailingslashit( $base ) . 'wp-login.php?action=rp&key=' . rawurlencode( $key ) . '&login=' . rawurlencode( $user->user_login );
	}

	/**
	 * Queue through Data Machine without logging or returning the raw token.
	 *
	 * @param array  $invitation Internal invitation record.
	 * @param string $token      Raw one-time token.
	 * @param string $claim_url  Existing account-claim URL, when needed.
	 */
	private function queue_delivery( array $invitation, string $token, string $claim_url ) {
		$user  = get_userdata( $invitation['user_id'] );
		$venue = get_term( $invitation['venue_term_id'], 'venue' );
		if ( ! $user || ! $venue || is_wp_error( $venue ) || ! function_exists( 'ec_send_email_queued' ) ) {
			return new \WP_Error( 'venue_invitation_delivery_unavailable', __( 'The invitation delivery could not be prepared.', 'extrachill-events' ), array( 'status' => 503 ) );
		}
		$accept_url = add_query_arg(
			array(
				'action'        => 'ec_accept_venue_invitation',
				'invitation_id' => $invitation['public_id'],
				'token'         => $token,
				'venue_term_id' => $invitation['venue_term_id'],
				'is_owner'      => $invitation['is_owner'] ? 1 : 0,
				'version'       => $invitation['version'],
			),
			admin_url( 'admin-post.php' )
		);
		$cta_url    = $claim_url ? $claim_url : $accept_url;
		/* translators: %s: Venue name. */
		$body = '<p>' . esc_html( sprintf( __( 'You have been invited to help manage %s on Extra Chill.', 'extrachill-events' ), $venue->name ) ) . '</p>';
		if ( $claim_url ) {
			$body .= '<p>' . esc_html__( 'Set your account password first, then return to the invitation link below to accept.', 'extrachill-events' ) . '</p>';
			$body .= '<p><a href="' . esc_url( $accept_url ) . '">' . esc_html__( 'Accept venue invitation', 'extrachill-events' ) . '</a></p>';
		}
		$body   .= '<p>' . esc_html__( 'This message was sent by Extra Chill Bot acting on Chris Huber\'s behalf.', 'extrachill-events' ) . '</p>';
		$payload = array(
			'to'        => $user->user_email,
			'cc'        => 'chubes@extrachill.com',
			/* translators: %s: Venue name. */
			'subject'   => sprintf( __( 'Invitation to manage %s', 'extrachill-events' ), $venue->name ),
			'from_name' => 'Extra Chill Bot',
			'template'  => 'extrachill/branded',
			'context'   => array(
				'recipient_name' => $user->display_name,
				'body_html'      => $body,
				'cta_url'        => $cta_url,
				'cta_label'      => $claim_url ? __( 'Set Your Password', 'extrachill-events' ) : __( 'Accept Invitation', 'extrachill-events' ),
			),
		);
		$send    = static function () use ( $payload ) {
			return ec_send_email_queued( $payload );
		};
		$result  = class_exists( '\DataMachine\Abilities\PermissionHelper' )
			? \DataMachine\Abilities\PermissionHelper::run_as_authenticated( $send, $invitation['invited_by_user_id'] )
			: $send();
		return is_array( $result ) && ! empty( $result['success'] )
			? true
			: new \WP_Error( 'venue_invitation_delivery_failed', __( 'The invitation delivery could not be queued.', 'extrachill-events' ), array( 'status' => 503 ) );
	}

	/** Persist exact invitation provenance on a newly created account. */
	private function persist_account_provenance( int $user_id, string $public_id ): bool {
		update_user_meta( $user_id, self::ACCOUNT_PROVENANCE_META, $public_id );
		return hash_equals( $public_id, (string) get_user_meta( $user_id, self::ACCOUNT_PROVENANCE_META, true ) );
	}

	/** Compensate a newly created account before invitation persistence. */
	private function compensate_initial_account( array $account, string $email, \WP_Error $error ) {
		if ( empty( $account['created'] ) ) {
			return $error;
		}
		return $this->rollback_account_if_safe( (int) $account['user_id'], $email, '' ) ? $error : $this->reconciliation_error( $error );
	}

	/** Cancel failed persisted state, then compensate its created account. */
	private function compensate_persisted_invitation( array $invitation, int $actor_user_id, string $email, \WP_Error $error, string $event ) {
		$cancelled = $this->repository->abandon_invitation( $invitation, $actor_user_id, $event );
		if ( is_wp_error( $cancelled ) ) {
			return $this->reconciliation_error( $error );
		}
		$public_id = 'invitation_provenance_failed' === $event ? '' : $invitation['public_id'];
		if ( ! empty( $invitation['_account_created'] ) && ! $this->rollback_account_if_safe( $invitation['user_id'], $email, $public_id, $cancelled ) ) {
			return $this->reconciliation_error( $error );
		}
		return $error;
	}

	/** Delete only the still-unused account proven to belong to this invitation. */
	private function cleanup_invited_account( array $invitation ) {
		if ( empty( $invitation['_account_created'] ) ) {
			return true;
		}
		$user = get_userdata( $invitation['user_id'] );
		if ( ! $user ) {
			return true;
		}
		$email = strtolower( sanitize_email( $user->user_email ) );
		if ( ! $this->rollback_account_if_safe( $invitation['user_id'], $email, $invitation['public_id'], $invitation ) ) {
			return $this->reconciliation_error( new \WP_Error( 'venue_invitation_account_cleanup_failed', __( 'The unused invitation account could not be removed.', 'extrachill-events' ) ) );
		}
		return true;
	}

	/** Apply strict provenance and usage checks before calling the Users rollback primitive. */
	private function rollback_account_if_safe( int $user_id, string $email, string $public_id, ?array $invitation = null ): bool {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return true;
		}
		$matches = '1' === (string) get_user_meta( $user_id, 'ec_unclaimed', true )
			&& 'venue_invitation' === (string) get_user_meta( $user_id, 'registration_source', true )
			&& strtolower( sanitize_email( $user->user_email ) ) === $email;
		if ( '' !== $public_id ) {
			$matches = $matches && hash_equals( $public_id, (string) get_user_meta( $user_id, self::ACCOUNT_PROVENANCE_META, true ) );
		}
		if ( ! $matches || ( is_array( $invitation ) && ! $this->repository->account_is_exclusive_to_invitation( $invitation ) ) ) {
			return true;
		}
		return function_exists( 'extrachill_users_rollback_created_user' ) && extrachill_users_rollback_created_user( $user_id );
	}

	/** Return a bounded manual-reconciliation error without leaking account data. */
	private function reconciliation_error( \WP_Error $cause ): \WP_Error {
		return new \WP_Error(
			'venue_invitation_account_reconciliation_required',
			__( 'The invitation failed and its unused account could not be safely reconciled.', 'extrachill-events' ),
			array(
				'status' => 500,
				'cause'  => $cause->get_error_code(),
			)
		);
	}
}
