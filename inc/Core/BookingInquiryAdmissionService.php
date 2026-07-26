<?php
/**
 * Atomic public booking inquiry and attachment admission.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

// phpcs:disable WordPress.Files.FileName.NotHyphenatedLowercase,WordPress.Files.FileName.InvalidClassFileName -- Core classes follow the repository's established PSR-style filenames.

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Owns the staged-file saga behind the hidden inquiry ability. */
final class BookingInquiryAdmissionService {

	public const MAX_FILES           = 5;
	public const MAX_AGGREGATE_BYTES = 52428800;

	/**
	 * Inquiry lifecycle.
	 *
	 * @var BookingLifecycle
	 */
	private $lifecycle;
	/**
	 * Attachment reads.
	 *
	 * @var BookingAttachmentRepository
	 */
	private $attachments;
	/**
	 * Attachment writes.
	 *
	 * @var BookingAttachmentService
	 */
	private $attachment_service;
	/**
	 * Private provider or configuration failure.
	 *
	 * @var BookingPrivateFileProvider|\WP_Error
	 */
	private $provider;
	/**
	 * Attachment policy.
	 *
	 * @var BookingAttachmentPolicy
	 */
	private $policy;

	/**
	 * Build the admission coordinator from existing domain services.
	 *
	 * @param BookingLifecycle|null            $lifecycle          Inquiry lifecycle.
	 * @param BookingAttachmentRepository|null $attachments        Attachment reads.
	 * @param BookingAttachmentService|null    $attachment_service Attachment writes.
	 * @param mixed                            $provider            Private provider.
	 * @param BookingAttachmentPolicy|null     $policy              File policy.
	 */
	public function __construct( ?BookingLifecycle $lifecycle = null, ?BookingAttachmentRepository $attachments = null, ?BookingAttachmentService $attachment_service = null, $provider = null, ?BookingAttachmentPolicy $policy = null ) {
		$this->lifecycle          = $lifecycle ? $lifecycle : new BookingLifecycle();
		$this->attachments        = $attachments ? $attachments : new BookingAttachmentRepository();
		$this->policy             = $policy ? $policy : new BookingAttachmentPolicy();
		$resolved                 = null !== $provider ? $provider : BookingPrivateFileProviders::resolve();
		$this->provider           = $resolved instanceof BookingPrivateFileProvider || is_wp_error( $resolved )
			? $resolved
			: new \WP_Error( 'booking_private_storage_invalid_provider', __( 'The private booking file provider is invalid.', 'extrachill-events' ) );
		$this->attachment_service = $attachment_service ? $attachment_service : new BookingAttachmentService( $this->attachments, null, null, $this->policy, $this->provider );
	}

	/**
	 * Admit one inquiry and its ordered attachment slots idempotently.
	 *
	 * @param array    $input    Hidden ability input.
	 * @param int|null $actor_id Actual authenticated caller, when present.
	 */
	public function admit( array $input, ?int $actor_id = null ) {
		$scope = $this->validate_scope();
		if ( is_wp_error( $scope ) ) {
			return $this->public_error( $scope );
		}

		$files = $this->preflight_files( $input['attachments'] ?? array() );
		if ( is_wp_error( $files ) ) {
			return $this->public_error( $files );
		}
		unset( $input['attachments'] );
		unset( $input['user_id'], $input['uploader_user_id'], $input['submitter_user_id'] );

		$manifest = array_map(
			static function ( array $file ): array {
				return $file['manifest'];
			},
			$files
		);
		$booking  = $this->lifecycle->create_inquiry( $input, $actor_id, $manifest ? array( 'attachments' => $manifest ) : array() );
		if ( is_wp_error( $booking ) ) {
			return $this->public_error( $booking );
		}

		$pending = array();
		foreach ( $files as $index => $file ) {
			$key      = $this->attachment_key( (string) $input['idempotency_key'], $index, $file['manifest'] );
			$existing = $this->attachments->get_idempotent( (int) $booking['id'], $key );
			if ( is_wp_error( $existing ) ) {
				return $this->public_error( $existing );
			}
			if ( is_array( $existing ) ) {
				if ( 'active' === $existing['state'] ) {
					continue;
				}
				return $this->public_error( new \WP_Error( 'booking_inquiry_attachment_reconciliation_required', __( 'The inquiry attachment state requires reconciliation.', 'extrachill-events' ) ) );
			}
			$file['idempotency_key'] = $key;
			$pending[]               = $file;
		}

		$staged = array();
		foreach ( $pending as $file ) {
			$reference = $this->provider_error_or_stage( $file );
			if ( is_wp_error( $reference ) ) {
				$cleanup = $this->retire_staged( $staged );
				if ( is_wp_error( $cleanup ) ) {
					return $this->public_error( $cleanup );
				}
				return $this->public_error( $reference );
			}
			$file['storage_reference'] = $reference;
			$staged[]                  = $file;
		}

		foreach ( $staged as $index => $file ) {
			$attached = $this->attachment_service->attach_admitted(
				array(
					'booking_id'         => (int) $booking['id'],
					'storage_reference'  => $file['storage_reference'],
					'idempotency_key'    => $file['idempotency_key'],
					'purpose'            => $file['purpose'],
					'uploader_type'      => null === $actor_id ? 'anonymous' : 'user',
					'uploader_user_id'   => $actor_id,
					'uploader_reference' => 'inquiry:' . $booking['public_id'],
				)
			);
			if ( is_wp_error( $attached ) ) {
				if ( $this->is_uncertain( $attached ) ) {
					return $this->public_error( $attached );
				}
				$cleanup = $this->retire_staged( array_slice( $staged, $index ) );
				if ( is_wp_error( $cleanup ) ) {
					return $this->public_error( $cleanup );
				}
				return $this->public_error( $attached );
			}
		}

		return array(
			'public_id'     => $booking['public_id'],
			'venue_term_id' => $booking['venue_term_id'],
			'submitted_at'  => $booking['created_at'],
		);
	}

	/** Fail closed before a current-prefix repository can touch the wrong site. */
	private function validate_scope() {
		$events_blog_id = function_exists( 'ec_get_blog_id' ) ? (int) ec_get_blog_id( 'events' ) : 7;
		return 0 < $events_blog_id && (int) get_current_blog_id() === $events_blog_id
			? true
			: new \WP_Error( 'booking_inquiry_wrong_site', __( 'Booking inquiries must execute on the Events site.', 'extrachill-events' ) );
	}

	/**
	 * Validate every cheap transport and policy bound before storage or database work.
	 *
	 * @param mixed $files Staged upload records.
	 */
	private function preflight_files( $files ) {
		if ( ! is_array( $files ) || count( $files ) > self::MAX_FILES ) {
			return new \WP_Error( 'invalid_booking_attachment_count', __( 'Too many booking attachments were submitted.', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		$normalized = array();
		$aggregate  = 0;
		foreach ( array_values( $files ) as $file ) {
			if ( ! is_array( $file ) || UPLOAD_ERR_OK !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) ) {
				return new \WP_Error( 'booking_attachment_upload_failed', __( 'A booking attachment could not be received completely.', 'extrachill-events' ), array( 'status' => 400 ) );
			}
			$path     = (string) ( $file['tmp_name'] ?? '' );
			$filename = (string) ( $file['name'] ?? '' );
			$purpose  = sanitize_key( (string) ( $file['purpose'] ?? '' ) );
			$trusted  = '' !== $path && is_readable( $path ) && ( is_uploaded_file( $path ) || true === apply_filters( 'extrachill_events_allow_test_booking_file', false, $file ) );
			if ( ! $trusted ) {
				return new \WP_Error( 'booking_attachment_upload_failed', __( 'A booking attachment could not be verified.', 'extrachill-events' ), array( 'status' => 400 ) );
			}
			$safe_filename = sanitize_file_name( $filename );
			$filetype      = wp_check_filetype( $safe_filename, BookingAttachmentPolicy::allowed_mimes() );
			$checked       = function_exists( 'wp_check_filetype_and_ext' ) ? wp_check_filetype_and_ext( $path, $safe_filename, BookingAttachmentPolicy::allowed_mimes() ) : $filetype;
			$actual_size   = filesize( $path );
			$declared_size = $file['size'] ?? null;
			if ( '' === $safe_filename || $safe_filename !== $filename || basename( $safe_filename ) !== $safe_filename ) {
				return new \WP_Error( 'invalid_booking_attachment_filename', __( 'The attachment filename is unsafe.', 'extrachill-events' ), array( 'status' => 400 ) );
			}
			if ( ! in_array( $purpose, BookingAttachmentPolicy::PURPOSES, true ) ) {
				return new \WP_Error( 'invalid_booking_attachment_purpose', __( 'The attachment purpose is not supported.', 'extrachill-events' ), array( 'status' => 400 ) );
			}
			if ( empty( $filetype['ext'] ) || empty( $filetype['type'] ) || empty( $checked['ext'] ) || empty( $checked['type'] ) || $filetype['type'] !== $checked['type'] ) {
				return new \WP_Error( 'invalid_booking_attachment_type', __( 'The attachment type is not supported.', 'extrachill-events' ), array( 'status' => 400 ) );
			}
			if ( false === $actual_size || ! is_int( $declared_size ) || $declared_size !== $actual_size || $actual_size < 1 || $actual_size > BookingAttachmentPolicy::max_bytes() ) {
				return new \WP_Error( 'invalid_booking_attachment_size', __( 'The attachment size is outside the allowed range.', 'extrachill-events' ), array( 'status' => 413 ) );
			}
			if ( $this->policy->is_default_denied_filename( $safe_filename ) ) {
				return new \WP_Error( 'booking_tax_document_denied', __( 'Tax identity documents are not accepted here.', 'extrachill-events' ), array( 'status' => 400 ) );
			}
			$aggregate += $actual_size;
			if ( $aggregate > self::MAX_AGGREGATE_BYTES ) {
				return new \WP_Error( 'invalid_booking_attachment_aggregate_size', __( 'The combined attachment size is too large.', 'extrachill-events' ), array( 'status' => 413 ) );
			}
			$hash = hash_file( 'sha256', $path );
			if ( false === $hash ) {
				return new \WP_Error( 'booking_attachment_upload_failed', __( 'A booking attachment could not be fingerprinted.', 'extrachill-events' ), array( 'status' => 400 ) );
			}
			$normalized[] = array(
				'path'     => $path,
				'filename' => $safe_filename,
				'purpose'  => $purpose,
				'manifest' => array(
					'filename'  => $safe_filename,
					'purpose'   => $purpose,
					'byte_size' => $actual_size,
					'sha256'    => $hash,
				),
			);
		}
		return $normalized;
	}

	/**
	 * Stage one preflighted file without exposing its source in errors.
	 *
	 * @param array $file Preflighted file.
	 */
	private function provider_error_or_stage( array $file ) {
		if ( is_wp_error( $this->provider ) ) {
			return $this->provider;
		}
		try {
			return $this->provider->stage( $file['path'], $file['filename'], $file['purpose'] );
		} catch ( \Throwable $throwable ) {
			return new \WP_Error( 'booking_private_stage_failed', __( 'The private attachment could not be staged.', 'extrachill-events' ) );
		}
	}

	/**
	 * Retire only objects whose attachment outcome is confirmed uncommitted.
	 *
	 * @param array $files Staged files containing private references.
	 */
	private function retire_staged( array $files ) {
		foreach ( $files as $file ) {
			try {
				$retired = $this->provider->retire( (string) $file['storage_reference'] );
			} catch ( \Throwable $throwable ) {
				$retired = false;
			}
			if ( true !== $retired ) {
				return new \WP_Error( 'booking_inquiry_attachment_cleanup_uncertain', __( 'The failed inquiry attachment requires reconciliation.', 'extrachill-events' ) );
			}
		}
		return true;
	}

	/**
	 * Build a stable booking-scoped slot key.
	 *
	 * @param string $inquiry_key Inquiry idempotency key.
	 * @param int    $index       Ordered slot index.
	 * @param array  $manifest    Canonical file manifest.
	 */
	private function attachment_key( string $inquiry_key, int $index, array $manifest ): string {
		return 'inquiry-attachment:' . hash_hmac( 'sha256', $inquiry_key . "\0" . $index . "\0" . wp_json_encode( $manifest ), wp_salt( 'auth' ) );
	}

	/**
	 * Never compensate a cross-store outcome that may have committed.
	 *
	 * @param \WP_Error $error Internal failure.
	 */
	private function is_uncertain( \WP_Error $error ): bool {
		$data = (array) $error->get_error_data();
		return in_array(
			$error->get_error_code(),
			array(
				'booking_attachment_transaction_commit_uncertain',
				'booking_attachment_reference_unlock_uncertain',
				'booking_attachment_claim_compensation_failed',
			),
			true
		) || true === ( $data['lock_uncertain'] ?? false ) || true === ( $data['connection_quarantined'] ?? false );
	}

	/**
	 * Return only stable facade-safe error codes and data.
	 *
	 * @param \WP_Error $error Internal failure.
	 */
	private function public_error( \WP_Error $error ): \WP_Error {
		$code = $error->get_error_code();
		if ( $this->is_uncertain( $error ) || in_array( $code, array( 'booking_transaction_commit_uncertain', 'booking_transaction_rollback_failed', 'booking_inquiry_attachment_reconciliation_required', 'booking_inquiry_attachment_cleanup_uncertain' ), true ) ) {
			return new \WP_Error(
				'booking_inquiry_reconciliation_required',
				__( 'The inquiry outcome requires reconciliation before retrying.', 'extrachill-events' ),
				array(
					'status'                  => 503,
					'retryable'               => true,
					'reconciliation_required' => true,
				)
			);
		}
		$data   = (array) $error->get_error_data();
		$status = (int) ( $data['status'] ?? 0 );
		$safe   = in_array( $status, array( 400, 403, 409, 413 ), true );
		return new \WP_Error(
			$safe ? $code : 'booking_inquiry_unavailable',
			$safe ? $error->get_error_message() : __( 'Booking inquiry processing is temporarily unavailable.', 'extrachill-events' ),
			array(
				'status'    => $safe ? $status : 503,
				'retryable' => ! $safe,
			)
		);
	}
}
