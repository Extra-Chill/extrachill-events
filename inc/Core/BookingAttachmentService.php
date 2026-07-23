<?php
/**
 * Transactional booking attachment lifecycle.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Coordinates private object claims, metadata, authorization inputs, and audit activity. */
class BookingAttachmentService {

	/** Attachment metadata repository.
	 *
	 * @var BookingAttachmentRepository
	 */
	private $attachments;
	/** Booking repository.
	 *
	 * @var BookingRepository
	 */
	private $bookings;
	/** Activity repository.
	 *
	 * @var BookingActivityRepository
	 */
	private $activity;
	/** Attachment policy.
	 *
	 * @var BookingAttachmentPolicy
	 */
	private $policy;
	/** Private provider or setup error.
	 *
	 * @var BookingPrivateFileProvider|\WP_Error
	 */
	private $provider;

	/**
	 * Build the booking attachment aggregate.
	 *
	 * @param BookingAttachmentRepository|null $attachments Attachment repository.
	 * @param BookingRepository|null           $bookings    Booking repository.
	 * @param BookingActivityRepository|null   $activity    Activity repository.
	 * @param BookingAttachmentPolicy|null     $policy      Attachment policy.
	 * @param mixed                            $provider    Private provider.
	 */
	public function __construct( ?BookingAttachmentRepository $attachments = null, ?BookingRepository $bookings = null, ?BookingActivityRepository $activity = null, ?BookingAttachmentPolicy $policy = null, $provider = null ) {
		$this->attachments = $attachments ? $attachments : new BookingAttachmentRepository();
		$this->bookings    = $bookings ? $bookings : new BookingRepository();
		$this->activity    = $activity ? $activity : new BookingActivityRepository();
		$this->policy      = $policy ? $policy : new BookingAttachmentPolicy();
		$resolved          = null !== $provider ? $provider : BookingPrivateFileProviders::resolve();
		$this->provider    = $resolved instanceof BookingPrivateFileProvider || is_wp_error( $resolved )
			? $resolved
			: new \WP_Error( 'booking_private_storage_invalid_provider', __( 'The private booking file provider is invalid.', 'extrachill-events' ), array( 'status' => 503 ) );
	}

	/**
	 * Attach one previously admitted opaque private object.
	 *
	 * @param array $input Attachment input.
	 */
	public function attach( array $input ) {
		if ( is_wp_error( $this->provider ) ) {
			return $this->provider;
		}
		$booking = $this->booking( (int) ( $input['booking_id'] ?? 0 ) );
		if ( is_wp_error( $booking ) ) {
			return $booking;
		}
		$normalized = $this->normalize_input( $input, $booking );
		if ( is_wp_error( $normalized ) ) {
			return $normalized;
		}

		$claim_key = 'booking:' . $booking['id'] . ':' . $normalized['idempotency_key'];
		$metadata  = $this->provider->claim( $normalized['storage_reference'], $claim_key, $normalized['purpose'] );
		if ( is_wp_error( $metadata ) ) {
			return $metadata;
		}
		if ( ! is_array( $metadata ) ) {
			$this->provider->release_claim( $normalized['storage_reference'], $claim_key );
			return new \WP_Error( 'booking_private_storage_invalid_response', __( 'Private storage returned invalid attachment metadata.', 'extrachill-events' ), array( 'status' => 502 ) );
		}
		$validated = $this->policy->validate( $metadata, $normalized['purpose'] );
		if ( is_wp_error( $validated ) ) {
			$this->provider->release_claim( $normalized['storage_reference'], $claim_key );
			return $validated;
		}
		if ( BookingAttachmentPolicy::requires_malware_scan( $validated['mime_type'] ) && 'clean' !== ( $metadata['scan_status'] ?? '' ) ) {
			$this->provider->release_claim( $normalized['storage_reference'], $claim_key );
			return new \WP_Error( 'booking_private_scan_required', __( 'This document type requires an approved malware scanner before attachment.', 'extrachill-events' ), array( 'status' => 503 ) );
		}
		if ( $this->policy->is_default_denied_filename( $validated['original_filename'] ) ) {
			$this->provider->release_claim( $normalized['storage_reference'], $claim_key );
			return new \WP_Error( 'booking_tax_document_denied', __( 'Tax identity documents require an approved secure vault and are not accepted here.', 'extrachill-events' ), array( 'status' => 400 ) );
		}

		$reuse = $this->validate_reuse( $normalized, $booking );
		if ( is_wp_error( $reuse ) ) {
			$this->provider->release_claim( $normalized['storage_reference'], $claim_key );
			return $reuse;
		}

		$data                 = array_merge( $normalized, $validated );
		$data['request_hash'] = $this->request_hash( $data );
		$result               = $this->transaction(
			function () use ( $data ) {
				$attachment = $this->attachments->create( $data );
				if ( is_wp_error( $attachment ) ) {
					return $attachment;
				}
				$activity = $this->activity->append(
					array(
						'booking_id'      => $attachment['booking_id'],
						'kind'            => 'attachment_added',
						'actor_type'      => $attachment['uploader_type'],
						'actor_id'        => $attachment['uploader_user_id'],
						'idempotency_key' => 'attachment-added:' . $attachment['idempotency_key'],
						'payload'         => $this->audit_payload( $attachment ),
					)
				);
				return is_wp_error( $activity ) ? $activity : $attachment;
			}
		);
		if ( is_wp_error( $result ) ) {
			$this->provider->release_claim( $normalized['storage_reference'], $claim_key );
		}
		return $result;
	}

	/**
	 * Replace an active attachment while preserving the prior audit reference.
	 *
	 * @param array $input Replacement input.
	 */
	public function replace( array $input ) {
		$prior = $this->attachments->get_for_booking( (int) ( $input['booking_id'] ?? 0 ), (int) ( $input['attachment_id'] ?? 0 ) );
		if ( is_wp_error( $prior ) ) {
			return $prior;
		}
		if ( 'active' !== $prior['state'] ) {
			return new \WP_Error( 'booking_attachment_inactive', __( 'Only active attachments can be replaced.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
		$input['purpose']                = $input['purpose'] ?? $prior['purpose'];
		$input['replaces_attachment_id'] = $prior['id'];
		$replacement                     = $this->attach( $input );
		if ( is_wp_error( $replacement ) || $replacement['id'] === $prior['id'] ) {
			return $replacement;
		}
		$retired = $this->transaction(
			function () use ( $prior, $replacement ) {
				$updated = $this->attachments->retire( $prior['id'], 'replaced' );
				if ( is_wp_error( $updated ) ) {
					return $updated;
				}
				$activity = $this->activity->append(
					array(
						'booking_id'      => $prior['booking_id'],
						'kind'            => 'attachment_replaced',
						'actor_type'      => $replacement['uploader_type'],
						'actor_id'        => $replacement['uploader_user_id'],
						'idempotency_key' => 'attachment-replaced:' . $replacement['id'],
						'payload'         => array(
							'attachment_id'             => $prior['public_id'],
							'replacement_attachment_id' => $replacement['public_id'],
						),
					)
				);
				return is_wp_error( $activity ) ? $activity : $updated;
			}
		);
		if ( is_wp_error( $retired ) ) {
			$this->attachments->retire( $replacement['id'], 'deleted' );
			return $retired;
		}
		return $replacement;
	}

	/**
	 * Logically delete a booking reference; physical cleanup remains retention-gated.
	 *
	 * @param int $booking_id    Booking ID.
	 * @param int $attachment_id Attachment ID.
	 * @param int $actor_id      Acting user ID.
	 */
	public function delete( int $booking_id, int $attachment_id, int $actor_id ) {
		$attachment = $this->attachments->get_for_booking( $booking_id, $attachment_id );
		if ( is_wp_error( $attachment ) ) {
			return $attachment;
		}
		return $this->transaction(
			function () use ( $attachment, $attachment_id, $booking_id, $actor_id ) {
				$retired = $this->attachments->retire( $attachment_id, 'deleted' );
				if ( is_wp_error( $retired ) ) {
					return $retired;
				}
				$activity = $this->activity->append(
					array(
						'booking_id'      => $booking_id,
						'kind'            => 'attachment_deleted',
						'actor_type'      => 'user',
						'actor_id'        => $actor_id,
						'idempotency_key' => 'attachment-deleted:' . $attachment_id,
						'payload'         => $this->audit_payload( $retired ),
					)
				);
				return is_wp_error( $activity ) ? $activity : $retired;
			}
		);
	}

	/**
	 * Purge retired bytes only when every reference is inactive and no audit hold applies.
	 *
	 * @param int $retention_days Minimum retired age.
	 */
	public function cleanup( int $retention_days = 30 ) {
		if ( is_wp_error( $this->provider ) ) {
			return $this->provider;
		}
		$cutoff     = gmdate( 'Y-m-d H:i:s', time() - ( max( 1, $retention_days ) * DAY_IN_SECONDS ) );
		$candidates = $this->attachments->list_retired_before( $cutoff );
		$purged     = 0;
		$visited    = array();
		foreach ( $candidates as $candidate ) {
			$reference = $candidate['storage_reference'];
			if ( isset( $visited[ $reference ] ) ) {
				continue;
			}
			$visited[ $reference ] = true;
			$references            = $this->attachments->list_by_storage_reference( $reference );
			$eligible              = true;
			foreach ( $references as $attachment ) {
				$booking = $this->bookings->get( $attachment['booking_id'] );
				if ( 'active' === $attachment['state'] || ! is_array( $booking ) || $this->policy->requires_audit_retention( $attachment, $booking ) ) {
					$eligible = false;
					break;
				}
			}
			if ( ! $eligible || true !== $this->provider->retire( $reference ) ) {
				continue;
			}
			foreach ( $references as $attachment ) {
				if ( ! is_wp_error( $this->attachments->retire( $attachment['id'], 'purged' ) ) ) {
					++$purged;
				}
			}
		}
		return array(
			'purged' => $purged,
			'cutoff' => $cutoff,
		);
	}

	/**
	 * Resolve an authorized stream token without returning storage references or paths.
	 *
	 * @param int $booking_id    Booking ID.
	 * @param int $attachment_id Attachment ID.
	 */
	public function download_descriptor( int $booking_id, int $attachment_id ) {
		if ( is_wp_error( $this->provider ) ) {
			return $this->provider;
		}
		$attachment = $this->attachments->get_for_booking( $booking_id, $attachment_id );
		if ( is_wp_error( $attachment ) ) {
			return $attachment;
		}
		if ( 'active' !== $attachment['state'] ) {
			return new \WP_Error( 'booking_attachment_inactive', __( 'The attachment is no longer downloadable.', 'extrachill-events' ), array( 'status' => 410 ) );
		}
		$descriptor = $this->provider->download_descriptor( $attachment['storage_reference'] );
		if ( ! is_array( $descriptor ) || empty( $descriptor['stream_token'] ) || ! is_string( $descriptor['stream_token'] ) || empty( $descriptor['expires_at'] ) || ! is_string( $descriptor['expires_at'] ) ) {
			return new \WP_Error( 'booking_private_storage_invalid_response', __( 'Private storage did not return a secure stream handoff.', 'extrachill-events' ), array( 'status' => 502 ) );
		}
		$activity = $this->activity->append(
			array(
				'booking_id' => $booking_id,
				'kind'       => 'attachment_downloaded',
				'actor_type' => 'user',
				'actor_id'   => get_current_user_id(),
				'payload'    => $this->audit_payload( $attachment ),
			)
		);
		if ( is_wp_error( $activity ) ) {
			return $activity;
		}
		return array(
			'stream_token' => $descriptor['stream_token'],
			'expires_at'   => $descriptor['expires_at'],
			'filename'     => $attachment['original_filename'],
			'mime_type'    => $attachment['mime_type'],
		);
	}

	/**
	 * Resolve one booking.
	 *
	 * @param int $booking_id Booking ID.
	 */
	private function booking( int $booking_id ) {
		$booking = $this->bookings->get( $booking_id );
		return is_array( $booking ) ? $booking : new \WP_Error( 'booking_not_found', __( 'The booking was not found.', 'extrachill-events' ), array( 'status' => 404 ) );
	}

	/**
	 * Normalize attribution and identity against the stored booking.
	 *
	 * @param array $input   Attachment input.
	 * @param array $booking Stored booking.
	 */
	private function normalize_input( array $input, array $booking ) {
		$type = sanitize_key( (string) ( $input['uploader_type'] ?? '' ) );
		if ( ! in_array( $type, BookingAttachmentPolicy::UPLOADERS, true ) ) {
			return new \WP_Error( 'invalid_booking_attachment_uploader', __( 'The attachment uploader attribution is invalid.', 'extrachill-events' ) );
		}
		$user_id = empty( $input['uploader_user_id'] ) ? null : absint( $input['uploader_user_id'] );
		if ( ( 'user' === $type ) !== ( null !== $user_id ) ) {
			return new \WP_Error( 'invalid_booking_attachment_uploader', __( 'Authenticated uploads require a user identity and anonymous uploads must not claim one.', 'extrachill-events' ) );
		}
		$term_id    = empty( $input['artist_term_id'] ) ? $booking['artist_term_id'] : absint( $input['artist_term_id'] );
		$profile_id = empty( $input['artist_profile_id'] ) ? $booking['artist_profile_id'] : absint( $input['artist_profile_id'] );
		if ( $term_id !== $booking['artist_term_id'] || $profile_id !== $booking['artist_profile_id'] ) {
			return new \WP_Error( 'booking_attachment_artist_mismatch', __( 'Attachment artist identity must match the booking.', 'extrachill-events' ) );
		}
		$reference = sanitize_text_field( (string) ( $input['storage_reference'] ?? '' ) );
		$key       = sanitize_text_field( (string) ( $input['idempotency_key'] ?? '' ) );
		if ( 1 !== preg_match( '/^[A-Za-z0-9:_-]{24,191}$/', $reference ) || '' === $key || strlen( $key ) > 191 ) {
			return new \WP_Error( 'invalid_booking_attachment_reference', __( 'Opaque storage and idempotency references are required.', 'extrachill-events' ) );
		}
		return array(
			'booking_id'             => $booking['id'],
			'uploader_type'          => $type,
			'uploader_user_id'       => $user_id,
			'uploader_reference'     => empty( $input['uploader_reference'] ) ? null : mb_substr( sanitize_text_field( $input['uploader_reference'] ), 0, 191 ),
			'artist_term_id'         => $term_id,
			'artist_profile_id'      => $profile_id,
			'purpose'                => sanitize_key( (string) ( $input['purpose'] ?? '' ) ),
			'storage_reference'      => $reference,
			'idempotency_key'        => $key,
			'replaces_attachment_id' => empty( $input['replaces_attachment_id'] ) ? null : absint( $input['replaces_attachment_id'] ),
		);
	}

	/**
	 * Enforce authenticated, same-artist reuse.
	 *
	 * @param array $input   Normalized input.
	 * @param array $booking Stored booking.
	 */
	private function validate_reuse( array $input, array $booking ) {
		$references = $this->attachments->list_by_storage_reference( $input['storage_reference'] );
		$references = array_values(
			array_filter(
				$references,
				static function ( array $reference ) use ( $input, $booking ): bool {
					return $reference['booking_id'] !== $booking['id'] || $reference['idempotency_key'] !== $input['idempotency_key'];
				}
			)
		);
		if ( empty( $references ) ) {
			return true;
		}
		if ( 'user' !== $input['uploader_type'] ) {
			return new \WP_Error( 'booking_attachment_reuse_forbidden', __( 'Only an authenticated uploader may reuse an existing artist attachment.', 'extrachill-events' ), array( 'status' => 403 ) );
		}
		foreach ( $references as $reference ) {
			if ( $reference['uploader_user_id'] !== $input['uploader_user_id'] || $reference['artist_term_id'] !== $booking['artist_term_id'] || $reference['artist_profile_id'] !== $booking['artist_profile_id'] ) {
				return new \WP_Error( 'booking_attachment_reuse_forbidden', __( 'The stored object belongs to a different uploader or artist identity.', 'extrachill-events' ), array( 'status' => 403 ) );
			}
		}
		return true;
	}

	/**
	 * Build an exact idempotency fingerprint.
	 *
	 * @param array $data Normalized attachment data.
	 */
	private function request_hash( array $data ): string {
		unset( $data['request_hash'] );
		ksort( $data );
		return hash_hmac( 'sha256', wp_json_encode( $data ), wp_salt( 'auth' ) );
	}

	/**
	 * Build activity data without private references or tokens.
	 *
	 * @param array $attachment Attachment metadata.
	 */
	private function audit_payload( array $attachment ): array {
		return array(
			'attachment_id' => $attachment['public_id'],
			'purpose'       => $attachment['purpose'],
			'filename'      => $attachment['original_filename'],
			'state'         => $attachment['state'],
		);
	}

	/**
	 * Run one metadata and activity mutation atomically.
	 *
	 * @param callable $callback Transaction body.
	 */
	private function transaction( callable $callback ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Coordinates private metadata and audit writes.
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return new \WP_Error( 'booking_attachment_transaction_failed', __( 'The attachment transaction could not start.', 'extrachill-events' ) );
		}
		$result = $callback();
		if ( is_wp_error( $result ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Rolls back private metadata and audit writes.
			$wpdb->query( 'ROLLBACK' );
			return $result;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Commits private metadata and audit writes.
		if ( false === $wpdb->query( 'COMMIT' ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Best-effort rollback after commit failure.
			$wpdb->query( 'ROLLBACK' );
			return new \WP_Error( 'booking_attachment_transaction_failed', __( 'The attachment transaction could not commit.', 'extrachill-events' ) );
		}
		return $result;
	}
}
