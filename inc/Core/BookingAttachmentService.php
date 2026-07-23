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
	/** Exact venue authorization policy.
	 *
	 * @var VenueAuthorization
	 */
	private $authorization;

	/**
	 * Build the booking attachment aggregate.
	 *
	 * @param BookingAttachmentRepository|null $attachments Attachment repository.
	 * @param BookingRepository|null           $bookings    Booking repository.
	 * @param BookingActivityRepository|null   $activity    Activity repository.
	 * @param BookingAttachmentPolicy|null     $policy      Attachment policy.
	 * @param mixed                            $provider      Private provider.
	 * @param VenueAuthorization|null          $authorization Exact venue authorization.
	 */
	public function __construct( ?BookingAttachmentRepository $attachments = null, ?BookingRepository $bookings = null, ?BookingActivityRepository $activity = null, ?BookingAttachmentPolicy $policy = null, $provider = null, ?VenueAuthorization $authorization = null ) {
		$this->attachments   = $attachments ? $attachments : new BookingAttachmentRepository();
		$this->bookings      = $bookings ? $bookings : new BookingRepository();
		$this->activity      = $activity ? $activity : new BookingActivityRepository();
		$this->policy        = $policy ? $policy : new BookingAttachmentPolicy();
		$this->authorization = $authorization ? $authorization : new VenueAuthorization();
		$resolved            = null !== $provider ? $provider : BookingPrivateFileProviders::resolve();
		$this->provider      = $resolved instanceof BookingPrivateFileProvider || is_wp_error( $resolved )
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

		return $this->with_reference_lock(
			$normalized['storage_reference'],
			function () use ( $booking, $normalized ) {
				return $this->attach_locked( $booking, $normalized );
			}
		);
	}

	/**
	 * Claim and persist one reference while its cross-store lock is held.
	 *
	 * @param array $booking    Current booking.
	 * @param array $normalized Normalized attachment input.
	 */
	private function attach_locked( array $booking, array $normalized ) {
		$claim_key = 'booking:' . $booking['id'] . ':' . $normalized['idempotency_key'];
		$metadata  = $this->provider->claim( $normalized['storage_reference'], $claim_key, $normalized['purpose'] );
		if ( is_wp_error( $metadata ) ) {
			return $metadata;
		}
		if ( ! is_array( $metadata ) ) {
			return $this->compensate_claim( $normalized['storage_reference'], $claim_key, new \WP_Error( 'booking_private_storage_invalid_response', __( 'Private storage returned invalid attachment metadata.', 'extrachill-events' ), array( 'status' => 502 ) ) );
		}
		$validated = $this->policy->validate( $metadata, $normalized['purpose'] );
		if ( is_wp_error( $validated ) ) {
			return $this->compensate_claim( $normalized['storage_reference'], $claim_key, $validated );
		}
		if ( BookingAttachmentPolicy::requires_malware_scan( $validated['mime_type'] ) && 'clean' !== ( $metadata['scan_status'] ?? '' ) ) {
			return $this->compensate_claim( $normalized['storage_reference'], $claim_key, new \WP_Error( 'booking_private_scan_required', __( 'This document type requires an approved malware scanner before attachment.', 'extrachill-events' ), array( 'status' => 503 ) ) );
		}
		if ( $this->policy->is_default_denied_filename( $validated['original_filename'] ) ) {
			return $this->compensate_claim( $normalized['storage_reference'], $claim_key, new \WP_Error( 'booking_tax_document_denied', __( 'Tax identity documents require an approved secure vault and are not accepted here.', 'extrachill-events' ), array( 'status' => 400 ) ) );
		}

		$data                 = array_merge( $normalized, $validated );
		$data['request_hash'] = $this->request_hash( $data );
		$result               = $this->transaction(
			function () use ( $data, $booking ) {
				$authorized = $this->lock_and_authorize( $booking, $data['uploader_user_id'] );
				if ( is_wp_error( $authorized ) ) {
					return $authorized;
				}
				$reuse = $this->validate_reuse( $data, $booking );
				if ( is_wp_error( $reuse ) ) {
					return $reuse;
				}
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
			return $this->compensate_claim( $normalized['storage_reference'], $claim_key, $result );
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
		$retired = $this->with_reference_lock(
			$prior['storage_reference'],
			function () use ( $prior, $replacement ) {
				return $this->transaction(
					function () use ( $prior, $replacement ) {
						$booking    = $this->booking( $prior['booking_id'] );
						$authorized = is_wp_error( $booking ) ? $booking : $this->lock_and_authorize( $booking, (int) $replacement['uploader_user_id'] );
						if ( is_wp_error( $authorized ) ) {
							return $authorized;
						}
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
									'attachment_id' => $prior['public_id'],
									'replacement_attachment_id' => $replacement['public_id'],
								),
							)
						);
						return is_wp_error( $activity ) ? $activity : $updated;
					}
				);
			}
		);
		if ( is_wp_error( $retired ) ) {
			$abandoned = $this->transaction(
				function () use ( $replacement ) {
					return $this->attachments->retire( $replacement['id'], 'abandoned' );
				}
			);
			return is_wp_error( $abandoned )
				? new \WP_Error(
					'booking_attachment_replacement_compensation_failed',
					__( 'Attachment replacement failed and its new reference requires operator recovery.', 'extrachill-events' ),
					array(
						'cause'        => $retired->get_error_code(),
						'compensation' => $abandoned->get_error_code(),
					)
				)
				: $retired;
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
				$booking    = $this->booking( $booking_id );
				$authorized = is_wp_error( $booking ) ? $booking : $this->lock_and_authorize( $booking, $actor_id );
				if ( is_wp_error( $authorized ) ) {
					return $authorized;
				}
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
	 * @param array $policy Explicit retention days and legal-hold callback.
	 */
	public function cleanup( array $policy = array() ) {
		if ( is_wp_error( $this->provider ) ) {
			return $this->provider;
		}
		$retention_days = absint( $policy['retention_days'] ?? 0 );
		$legal_hold     = $policy['legal_hold_callback'] ?? null;
		if ( $retention_days < 1 || ! is_callable( $legal_hold ) ) {
			return new \WP_Error( 'booking_attachment_cleanup_policy_required', __( 'Destructive cleanup requires an explicit approved retention and legal-hold policy.', 'extrachill-events' ), array( 'status' => 503 ) );
		}
		$cutoff     = gmdate( 'Y-m-d H:i:s', time() - ( $retention_days * DAY_IN_SECONDS ) );
		$candidates = $this->attachments->list_retired_before( $cutoff );
		if ( is_wp_error( $candidates ) ) {
			return $candidates;
		}
		$purged  = 0;
		$visited = array();
		foreach ( $candidates as $candidate ) {
			$reference = $candidate['storage_reference'];
			if ( isset( $visited[ $reference ] ) ) {
				continue;
			}
			$visited[ $reference ] = true;
			$result                = $this->with_reference_lock(
				$reference,
				function () use ( $reference, $legal_hold ) {
					$prepared = $this->transaction(
						function () use ( $reference, $legal_hold ) {
							$references = $this->attachments->list_by_storage_reference( $reference, true );
							if ( is_wp_error( $references ) ) {
								return $references;
							}
							if ( empty( $references ) ) {
								return new \WP_Error( 'booking_attachment_cleanup_uncertain', __( 'Cleanup found no authoritative attachment references.', 'extrachill-events' ) );
							}
							foreach ( $references as $attachment ) {
								$booking = $this->bookings->get( $attachment['booking_id'] );
								if ( is_wp_error( $booking ) || ! is_array( $booking ) ) {
									return is_wp_error( $booking ) ? $booking : new \WP_Error( 'booking_attachment_cleanup_uncertain', __( 'Cleanup could not prove the booking retention state.', 'extrachill-events' ) );
								}
								$held = $legal_hold( $attachment, $booking );
								if ( is_wp_error( $held ) ) {
									return $held;
								}
								if ( 'active' === $attachment['state'] || false !== $held || $this->policy->requires_audit_retention( $attachment, $booking ) ) {
									return false;
								}
							}
							foreach ( $references as $attachment ) {
								$marked = $this->attachments->retire( $attachment['id'], 'purging' );
								if ( is_wp_error( $marked ) ) {
									return $marked;
								}
							}
							return $references;
						}
					);
					if ( false === $prepared || is_wp_error( $prepared ) ) {
						return $prepared;
					}
					$retired = $this->provider->retire( $reference );
					if ( true !== $retired ) {
						return is_wp_error( $retired ) ? $retired : new \WP_Error( 'booking_private_retirement_uncertain', __( 'Private object retirement could not be confirmed.', 'extrachill-events' ) );
					}
					return $this->transaction(
						function () use ( $prepared ) {
							$count = 0;
							foreach ( $prepared as $attachment ) {
								$marked = $this->attachments->retire( $attachment['id'], 'purged' );
								if ( is_wp_error( $marked ) ) {
									return $marked;
								}
								++$count;
							}
							return $count;
						}
					);
				}
			);
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$purged += is_int( $result ) ? $result : 0;
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
	 * @param int $actor_id      Authorized user ID.
	 */
	public function download_descriptor( int $booking_id, int $attachment_id, ?int $actor_id = null ) {
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
		$actor_id   = null === $actor_id ? get_current_user_id() : $actor_id;
		$booking    = $this->booking( $booking_id );
		$descriptor = $this->transaction(
			function () use ( $attachment, $booking, $actor_id ) {
				if ( is_wp_error( $booking ) ) {
					return $booking;
				}
				$authorized = $this->lock_and_authorize( $booking, $actor_id );
				if ( is_wp_error( $authorized ) ) {
					return $authorized;
				}
				$current = $this->attachments->get_for_booking( $booking['id'], $attachment['id'] );
				if ( is_wp_error( $current ) || 'active' !== $current['state'] ) {
					return is_wp_error( $current ) ? $current : new \WP_Error( 'booking_attachment_inactive', __( 'The attachment is no longer downloadable.', 'extrachill-events' ), array( 'status' => 410 ) );
				}
				$claim_key  = 'booking:' . $booking['id'] . ':' . $current['idempotency_key'];
				$descriptor = $this->provider->download_descriptor( $current['storage_reference'], $current['public_id'], $actor_id, $current['purpose'], $claim_key );
				if ( is_wp_error( $descriptor ) ) {
					return $descriptor;
				}
				$activity = $this->activity->append(
					array(
						'booking_id' => $booking['id'],
						'kind'       => 'attachment_downloaded',
						'actor_type' => 'user',
						'actor_id'   => $actor_id,
						'payload'    => $this->audit_payload( $current ),
					)
				);
				return is_wp_error( $activity ) ? $activity : $descriptor;
			}
		);
		if ( is_wp_error( $descriptor ) ) {
			return $descriptor;
		}
		if ( ! is_array( $descriptor ) || empty( $descriptor['stream_token'] ) || ! is_string( $descriptor['stream_token'] ) || empty( $descriptor['expires_at'] ) || ! is_string( $descriptor['expires_at'] ) ) {
			return new \WP_Error( 'booking_private_storage_invalid_response', __( 'Private storage did not return a secure stream handoff.', 'extrachill-events' ), array( 'status' => 502 ) );
		}
		return array(
			'stream_token' => $descriptor['stream_token'],
			'expires_at'   => $descriptor['expires_at'],
			'filename'     => $attachment['original_filename'],
			'mime_type'    => $attachment['mime_type'],
		);
	}

	/**
	 * Reauthorize and consume an opaque one-time download handoff.
	 *
	 * @param int    $booking_id    Booking ID.
	 * @param int    $attachment_id Attachment ID.
	 * @param string $stream_token  Opaque handoff.
	 * @param int    $actor_id      Current user ID.
	 */
	public function open_download_stream( int $booking_id, int $attachment_id, string $stream_token, int $actor_id ) {
		if ( is_wp_error( $this->provider ) ) {
			return $this->provider;
		}
		$booking = $this->booking( $booking_id );
		if ( is_wp_error( $booking ) ) {
			return $booking;
		}
		$opened = null;
		$result = $this->transaction(
			function () use ( $booking, $attachment_id, $stream_token, $actor_id, &$opened ) {
				$authorized = $this->lock_and_authorize( $booking, $actor_id );
				if ( is_wp_error( $authorized ) ) {
					return $authorized;
				}
				$attachment = $this->attachments->get_for_booking( $booking['id'], $attachment_id );
				if ( is_wp_error( $attachment ) || 'active' !== $attachment['state'] ) {
					return is_wp_error( $attachment ) ? $attachment : new \WP_Error( 'booking_attachment_inactive', __( 'The attachment is no longer downloadable.', 'extrachill-events' ), array( 'status' => 410 ) );
				}
				$opened = $this->provider->open_stream( $stream_token, $attachment['public_id'], $actor_id, $attachment['purpose'] );
				return $opened;
			}
		);
		if ( is_wp_error( $result ) && is_resource( $opened ) ) {
			fclose( $opened );
		}
		return $result;
	}

	/**
	 * Resolve one booking.
	 *
	 * @param int $booking_id Booking ID.
	 */
	private function booking( int $booking_id ) {
		$booking = $this->bookings->get( $booking_id );
		return is_wp_error( $booking ) || is_array( $booking ) ? $booking : new \WP_Error( 'booking_not_found', __( 'The booking was not found.', 'extrachill-events' ), array( 'status' => 404 ) );
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
		if ( is_wp_error( $references ) ) {
			return $references;
		}
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
		if ( null === $booking['artist_term_id'] && null === $booking['artist_profile_id'] ) {
			return new \WP_Error( 'booking_attachment_artist_unresolved', __( 'Attachment reuse requires a canonical artist identity.', 'extrachill-events' ), array( 'status' => 403 ) );
		}
		return new \WP_Error( 'booking_attachment_reuse_forbidden', __( 'Cross-booking reuse is disabled until canonical artist authority can be proven.', 'extrachill-events' ), array( 'status' => 403 ) );
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
	 * Serialize filesystem claims and database references for one object.
	 *
	 * @param string   $reference Opaque storage reference.
	 * @param callable $callback  Serialized operation.
	 * @throws \Throwable Propagates unexpected callback failures after releasing the lock.
	 */
	private function with_reference_lock( string $reference, callable $callback ) {
		global $wpdb;
		$name     = 'ec_booking_file_' . substr( hash( 'sha256', $reference ), 0, 40 );
		$acquired = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 10)', $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Cross-store object serialization.
		if ( '' !== (string) $wpdb->last_error || 1 !== (int) $acquired ) {
			return new \WP_Error( 'booking_attachment_reference_lock_failed', __( 'The private attachment reference could not be locked.', 'extrachill-events' ), array( 'database_error' => $wpdb->last_error ) );
		}
		try {
			$result = $callback();
		} catch ( \Throwable $throwable ) {
			$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Prevents a request-local lock leak before propagating an unexpected failure.
			throw $throwable;
		}
		$released = $wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $name ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Releases cross-store object serialization.
		if ( 1 !== (int) $released ) {
			return new \WP_Error( 'booking_attachment_reference_unlock_failed', __( 'The private attachment reference lock release could not be confirmed.', 'extrachill-events' ), array( 'database_error' => $wpdb->last_error ) );
		}
		return $result;
	}

	/**
	 * Lock the current booking and venue membership range, then reauthorize.
	 *
	 * @param array    $booking  Previously resolved booking.
	 * @param int|null $actor_id Acting user, or null for a trusted admission adapter.
	 */
	private function lock_and_authorize( array $booking, ?int $actor_id ) {
		global $wpdb;
		$bookings_table = BookingSchema::bookings_table();
		$locked         = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$bookings_table} WHERE id = %d FOR UPDATE", $booking['id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Locks current aggregate state.
		if ( '' !== (string) $wpdb->last_error || ! is_array( $locked ) || (int) $locked['venue_term_id'] !== (int) $booking['venue_term_id'] ) {
			return new \WP_Error( 'booking_attachment_booking_lock_failed', __( 'The booking could not be locked for attachment authorization.', 'extrachill-events' ), array( 'database_error' => $wpdb->last_error ) );
		}
		if ( null === $actor_id ) {
			return true;
		}
		$memberships = BookingSchema::memberships_table();
		$wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$memberships} WHERE venue_term_id = %d ORDER BY id ASC FOR UPDATE", $booking['venue_term_id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Locks exact venue authority range.
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'booking_authorization_lock_failed', __( 'Venue booking authority could not be locked.', 'extrachill-events' ), array( 'database_error' => $wpdb->last_error ) );
		}
		$allowed = $this->authorization->authorize( $actor_id, $booking['venue_term_id'], VenueAuthorization::ACTION_ACCESS_VENUE );
		return true === $allowed ? true : ( is_wp_error( $allowed ) ? $allowed : new \WP_Error( 'venue_action_forbidden', __( 'You are not authorized to perform this venue action.', 'extrachill-events' ), array( 'status' => 403 ) ) );
	}

	/**
	 * Preserve an explicit recovery marker and surface failed compensation.
	 *
	 * @param string    $reference Opaque storage reference.
	 * @param string    $claim_key Exact claim key.
	 * @param \WP_Error $cause     Original failure.
	 */
	private function compensate_claim( string $reference, string $claim_key, \WP_Error $cause ) {
		if ( 'booking_attachment_transaction_commit_uncertain' === $cause->get_error_code() ) {
			return $cause;
		}
		$released = $this->provider->release_claim( $reference, $claim_key );
		return true === $released
			? $cause
			: new \WP_Error(
				'booking_attachment_claim_compensation_failed',
				__( 'The attachment failed and its private claim requires operator recovery.', 'extrachill-events' ),
				array(
					'cause'        => $cause->get_error_code(),
					'compensation' => is_wp_error( $released ) ? $released->get_error_code() : 'unconfirmed',
				)
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
			if ( false === $wpdb->query( 'ROLLBACK' ) ) {
				return new \WP_Error(
					'booking_attachment_transaction_rollback_failed',
					__( 'The attachment transaction could not be rolled back.', 'extrachill-events' ),
					array(
						'cause'          => $result->get_error_code(),
						'database_error' => $wpdb->last_error,
					)
				);
			}
			return $result;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Commits private metadata and audit writes.
		if ( false === $wpdb->query( 'COMMIT' ) ) {
			return new \WP_Error( 'booking_attachment_transaction_commit_uncertain', __( 'The attachment transaction outcome could not be confirmed.', 'extrachill-events' ), array( 'database_error' => $wpdb->last_error ) );
		}
		return $result;
	}
}
