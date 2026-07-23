<?php
/**
 * Booking-linked communication policy and durable correspondence state.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Records booking correspondence before delegating delivery to Data Machine. */
class BookingCommunicationService {

	public const REMINDER_HOOK  = 'extrachill_events_dispatch_booking_reminder';
	public const SCHEDULER_GROUP = 'extrachill-events-booking-reminders';
	public const TEMPLATES      = array( 'operator_message', 'follow_up', 'hold_expiring' );
	public const TERMINAL_STATUSES = array( 'declined', 'withdrawn', 'cancelled', 'completed' );

	/** @var BookingRepository */
	private $bookings;
	/** @var BookingActivityRepository */
	private $activity;
	/** @var VenueAuthorization */
	private $authorization;
	/** @var callable|null */
	private $queue;
	/** @var callable|null */
	private $schedule;
	/** @var callable|null */
	private $cancel;
	/** @var bool */
	private $transaction_active = false;

	public function __construct( ?BookingRepository $bookings = null, ?BookingActivityRepository $activity = null, ?VenueAuthorization $authorization = null, $queue = null, $schedule = null, $cancel = null ) {
		$this->bookings      = $bookings ? $bookings : new BookingRepository();
		$this->activity      = $activity ? $activity : new BookingActivityRepository();
		$this->authorization = $authorization ? $authorization : new VenueAuthorization();
		$this->queue         = $queue;
		$this->schedule      = $schedule;
		$this->cancel        = $cancel;
	}

	/** Register the policy preflight that runs before a delayed reminder is queued. */
	public static function register(): void {
		add_action( self::REMINDER_HOOK, array( self::class, 'dispatch_scheduled' ), 10, 1 );
	}

	/** Action Scheduler callback. */
	public static function dispatch_scheduled( int $activity_id ): void {
		$result = ( new self() )->dispatch_reminder( $activity_id );
		if ( is_wp_error( $result ) ) {
			throw new \RuntimeException( $result->get_error_message() );
		}
	}

	/** Persist an outbound request, then queue now or schedule a policy preflight. */
	public function request( array $input, int $actor_id ) {
		$normalized = $this->normalize_request( $input );
		if ( is_wp_error( $normalized ) ) {
			return $normalized;
		}
		$booking = $this->bookings->get( $normalized['booking_id'] );
		if ( ! is_array( $booking ) ) {
			return is_wp_error( $booking ) ? $booking : $this->forbidden();
		}
		$allowed = $this->authorization->authorize( $actor_id, $booking['venue_term_id'], VenueAuthorization::ACTION_ACCESS_VENUE );
		if ( true !== $allowed ) {
			return is_wp_error( $allowed ) ? $allowed : $this->forbidden();
		}

		$started = $this->begin_authorized( $booking['venue_term_id'], $actor_id );
		if ( is_wp_error( $started ) ) {
			return $started;
		}
		$booking = $this->bookings->get_for_update( $normalized['booking_id'] );
		if ( ! is_array( $booking ) ) {
			return $this->rollback( is_wp_error( $booking ) ? $booking : $this->forbidden() );
		}
		if ( empty( $booking['contact_email'] ) || strtolower( $booking['contact_email'] ) !== strtolower( $normalized['recipient'] ) ) {
			return $this->rollback( new \WP_Error( 'booking_message_recipient_forbidden', __( 'The recipient must be the current contact for this booking.', 'extrachill-events' ), array( 'status' => 409 ) ) );
		}
		if ( in_array( $booking['status'], self::TERMINAL_STATUSES, true ) ) {
			return $this->rollback( new \WP_Error( 'booking_message_status_forbidden', __( 'Messages cannot be sent for a terminal booking.', 'extrachill-events' ), array( 'status' => 409 ) ) );
		}
		if ( ! empty( $normalized['in_reply_to'] ) && ! $this->thread_belongs_to_booking( $booking['id'], $normalized['in_reply_to'] ) ) {
			return $this->rollback( new \WP_Error( 'booking_message_thread_forbidden', __( 'The referenced message does not belong to this booking.', 'extrachill-events' ), array( 'status' => 409 ) ) );
		}

		$key      = 'booking-message-request:' . $normalized['idempotency_key'];
		$existing = $this->activity->find_by_idempotency( $booking['id'], $key );
		if ( is_wp_error( $existing ) ) {
			return $this->rollback( $existing );
		}
		$hash = $this->request_hash( $normalized, $actor_id );
		if ( is_array( $existing ) ) {
			$committed = $this->commit();
			if ( is_wp_error( $committed ) ) {
				return $committed;
			}
			if ( ! hash_equals( (string) ( $existing['payload']['data']['request_hash'] ?? '' ), $hash ) ) {
				return new \WP_Error( 'booking_message_idempotency_conflict', __( 'The idempotency key was already used for a different message.', 'extrachill-events' ), array( 'status' => 409 ) );
			}
			return $this->resume( $existing );
		}

		$message_id = sprintf( '<booking-%s-%s@extrachill.com>', $booking['public_id'], substr( $hash, 0, 24 ) );
		$intent     = $this->activity->append(
			array(
				'booking_id'      => $booking['id'],
				'kind'            => 'booking_message_requested',
				'actor_type'      => 'user',
				'actor_id'        => $actor_id,
				'direction'       => 'outbound',
				'channel'         => 'email',
				'external_id'     => trim( $message_id, '<>' ),
				'idempotency_key' => $key,
				'payload'         => array_merge(
					$normalized,
					array(
						'request_hash' => $hash,
						'message_id'   => $message_id,
						'cc'           => 'chubes@extrachill.com',
						'from_name'    => 'Extra Chill Bot',
						'identity'     => 'Extra Chill Bot sending on Chris\'s behalf.',
					)
				),
			)
		);
		if ( is_wp_error( $intent ) ) {
			return $this->rollback( $intent );
		}
		$committed = $this->commit();
		return is_wp_error( $committed ) ? $committed : $this->resume( $intent );
	}

	/** Retry the incomplete side effect for one durable intent. */
	private function resume( array $intent ) {
		$state = $this->state_for_intent( $intent );
		if ( is_wp_error( $state ) ) {
			return $state;
		}
		if ( in_array( $state['status'], array( 'scheduled', 'queued', 'sent', 'suppressed', 'cancelled' ), true ) ) {
			return $state;
		}
		$data = $intent['payload']['data'];
		if ( ! empty( $data['send_at'] ) ) {
			return $this->schedule_intent( $intent );
		}
		return $this->queue_intent( $intent );
	}

	/** Schedule only the booking-policy preflight; email payload stays in the ledger. */
	private function schedule_intent( array $intent ) {
		$claimed = $this->claim_side_effect( $intent, 'scheduling', array( 'requested', 'failed' ) );
		if ( true !== $claimed ) {
			return $claimed;
		}
		$timestamp = strtotime( $intent['payload']['data']['send_at'] . ' UTC' );
		try {
			$action_id = $this->schedule
				? call_user_func( $this->schedule, $timestamp, self::REMINDER_HOOK, array( $intent['id'] ), self::SCHEDULER_GROUP )
				: as_schedule_single_action( $timestamp, self::REMINDER_HOOK, array( $intent['id'] ), self::SCHEDULER_GROUP, true );
		} catch ( \Throwable $throwable ) {
			$action_id = 0;
		}
		if ( ! $action_id ) {
			$this->append_state( $intent, 'booking_message_failed', 'schedule', array( 'retryable' => true ) );
			return new \WP_Error( 'booking_reminder_schedule_failed', __( 'The booking reminder could not be scheduled.', 'extrachill-events' ), array( 'status' => 503 ) );
		}
		$event = $this->append_state( $intent, 'booking_reminder_scheduled', 'scheduled', array( 'action_id' => (int) $action_id ), (string) $action_id );
		return is_wp_error( $event ) ? $event : $this->state_for_intent( $intent );
	}

	/** Recheck lifecycle and reply suppression before delegating delivery. */
	public function dispatch_reminder( int $activity_id ) {
		$intent = $this->activity->get( $activity_id );
		if ( ! is_array( $intent ) || 'booking_message_requested' !== $intent['kind'] || empty( $intent['payload']['data']['send_at'] ) ) {
			return new \WP_Error( 'booking_reminder_invalid', __( 'The booking reminder intent is invalid.', 'extrachill-events' ) );
		}
		$state = $this->state_for_intent( $intent );
		if ( is_wp_error( $state ) ) {
			return $state;
		}
		if ( in_array( $state['status'], array( 'queued', 'sent', 'suppressed', 'cancelled' ), true ) ) {
			return $state;
		}
		$booking = $this->bookings->get( $intent['booking_id'] );
		$data    = $intent['payload']['data'];
		$reason  = null;
		if ( ! is_array( $booking ) || in_array( $booking['status'], self::TERMINAL_STATUSES, true ) || ! in_array( $booking['status'], $data['expected_statuses'], true ) ) {
			$reason = 'booking_status_changed';
		} elseif ( $this->has_qualifying_reply( $intent ) ) {
			$reason = 'human_reply_received';
		} elseif ( strtolower( (string) $booking['contact_email'] ) !== strtolower( $data['recipient'] ) ) {
			$reason = 'booking_recipient_changed';
		}
		if ( $reason ) {
			$event = $this->append_state( $intent, 'booking_reminder_suppressed', 'suppressed', array( 'reason' => $reason ) );
			return is_wp_error( $event ) ? $event : $this->state_for_intent( $intent );
		}
		return $this->queue_intent( $intent );
	}

	/** Delegate one prepared request to Data Machine's queued email ability. */
	private function queue_intent( array $intent ) {
		$claimed = $this->claim_side_effect( $intent, 'dispatching', array( 'requested', 'failed', 'scheduled' ) );
		if ( true !== $claimed ) {
			return $claimed;
		}
		$data = $intent['payload']['data'];
		$body = $this->render_body( $data['template'], $data['message'] );
		$input = array(
			'to'           => $data['recipient'],
			'cc'           => $data['cc'],
			'subject'      => $data['subject'],
			'body'         => $body,
			'content_type' => 'text/plain',
			'from_name'    => $data['from_name'],
			'reply_to'     => $data['reply_to'],
			'mail_site_id' => get_current_blog_id(),
		);
		if ( $this->queue ) {
			$result = call_user_func( $this->queue, $input );
		} else {
			$ability = wp_get_ability( 'datamachine/send-email-queued' );
			$result  = $ability ? $ability->execute( $input ) : new \WP_Error( 'booking_email_ability_unavailable' );
		}
		if ( is_wp_error( $result ) || ! is_array( $result ) || empty( $result['success'] ) || empty( $result['action_id'] ) ) {
			$event = $this->append_state( $intent, 'booking_message_failed', 'queue', array( 'retryable' => true, 'error' => is_wp_error( $result ) ? $result->get_error_code() : ( $result['error'] ?? 'invalid_result' ) ) );
			return is_wp_error( $event ) ? $event : new \WP_Error( 'booking_message_queue_failed', __( 'Data Machine did not accept the booking message.', 'extrachill-events' ), array( 'status' => 503 ) );
		}
		$event = $this->append_state( $intent, 'booking_message_queued', 'queued', array( 'action_id' => (int) $result['action_id'] ), (string) $result['action_id'] );
		return is_wp_error( $event ) ? $event : $this->state_for_intent( $intent );
	}

	/** Record a provider/runtime callback idempotently without claiming queued means sent. */
	public function record_delivery( int $intent_id, string $status, string $callback_id, ?string $provider_id, int $actor_id ) {
		if ( ! in_array( $status, array( 'sent', 'failed' ), true ) || '' === trim( $callback_id ) ) {
			return new \WP_Error( 'booking_delivery_callback_invalid', __( 'The delivery callback is invalid.', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		$intent = $this->activity->get( $intent_id );
		if ( ! is_array( $intent ) || 'booking_message_requested' !== $intent['kind'] ) {
			return $this->forbidden();
		}
		$booking = $this->bookings->get( $intent['booking_id'] );
		$allowed = is_array( $booking ) ? $this->authorization->authorize( $actor_id, $booking['venue_term_id'], VenueAuthorization::ACTION_ACCESS_VENUE ) : false;
		if ( true !== $allowed ) {
			return is_wp_error( $allowed ) ? $allowed : $this->forbidden();
		}
		$started = $this->begin_authorized( $booking['venue_term_id'], $actor_id );
		if ( is_wp_error( $started ) ) {
			return $started;
		}
		$locked = $this->bookings->get_for_update( $intent['booking_id'] );
		if ( ! is_array( $locked ) || (int) $locked['venue_term_id'] !== (int) $booking['venue_term_id'] ) {
			return $this->rollback( $this->forbidden() );
		}
		$event = $this->activity->append(
			array(
				'booking_id'      => $intent['booking_id'],
				'kind'            => 'sent' === $status ? 'booking_message_sent' : 'booking_message_failed',
				'actor_type'      => 'system',
				'direction'       => 'outbound',
				'channel'         => 'email',
				'external_id'     => $provider_id,
				'idempotency_key' => 'booking-delivery-callback:' . mb_substr( sanitize_text_field( $callback_id ), 0, 150 ),
				'payload'         => array( 'intent_id' => $intent_id, 'status' => $status, 'provider_id' => $provider_id ),
			)
		);
		if ( is_wp_error( $event ) ) {
			return $this->rollback( $event );
		}
		$committed = $this->commit();
		return is_wp_error( $committed ) ? $committed : $this->state_for_intent( $intent );
	}

	/** Record a participant reply and suppress still-pending follow-ups. */
	public function record_reply( int $booking_id, string $participant, string $message_id, ?string $in_reply_to, int $actor_id ) {
		$booking = $this->bookings->get( $booking_id );
		if ( ! is_array( $booking ) ) {
			return $this->forbidden();
		}
		$allowed = $this->authorization->authorize( $actor_id, $booking['venue_term_id'], VenueAuthorization::ACTION_ACCESS_VENUE );
		if ( true !== $allowed ) {
			return is_wp_error( $allowed ) ? $allowed : $this->forbidden();
		}
		$participant = sanitize_email( $participant );
		$message_id  = $this->message_id( $message_id );
		$in_reply_to = null === $in_reply_to ? null : $this->message_id( $in_reply_to );
		if ( '' === $participant || strtolower( $participant ) !== strtolower( (string) $booking['contact_email'] ) || '' === trim( $message_id ) || ( $in_reply_to && ! $this->thread_belongs_to_booking( $booking_id, $in_reply_to ) ) ) {
			return new \WP_Error( 'booking_reply_not_qualified', __( 'The reply could not be linked to this booking participant and thread.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
		$started = $this->begin_authorized( $booking['venue_term_id'], $actor_id );
		if ( is_wp_error( $started ) ) {
			return $started;
		}
		$locked = $this->bookings->get_for_update( $booking_id );
		if ( ! is_array( $locked ) || (int) $locked['venue_term_id'] !== (int) $booking['venue_term_id'] || strtolower( (string) $locked['contact_email'] ) !== strtolower( $participant ) || ( $in_reply_to && ! $this->thread_belongs_to_booking( $booking_id, $in_reply_to ) ) ) {
			return $this->rollback( new \WP_Error( 'booking_reply_not_qualified', __( 'The reply could not be linked to this booking participant and thread.', 'extrachill-events' ), array( 'status' => 409 ) ) );
		}
		$event = $this->activity->append(
			array(
				'booking_id'      => $booking_id,
				'kind'            => 'booking_message_received',
				'actor_type'      => 'contact',
				'direction'       => 'inbound',
				'channel'         => 'email',
				'external_id'     => mb_substr( sanitize_text_field( $message_id ), 0, 191 ),
				'idempotency_key' => 'booking-reply:' . hash( 'sha256', $message_id ),
				'payload'         => array( 'participant' => $participant, 'message_id' => $message_id, 'in_reply_to' => $in_reply_to ),
			)
		);
		if ( is_wp_error( $event ) ) {
			return $this->rollback( $event );
		}
		$committed = $this->commit();
		if ( is_wp_error( $committed ) ) {
			return $committed;
		}
		$this->suppress_pending_reminders( $booking_id, 'human_reply_received' );
		return $event;
	}

	/** Authorized correspondence read model for later Roadie and UI consumers. */
	public function list_for_booking( int $booking_id, int $actor_id ) {
		$booking = $this->bookings->get( $booking_id );
		if ( ! is_array( $booking ) ) {
			return $this->forbidden();
		}
		$started = $this->begin_authorized( $booking['venue_term_id'], $actor_id );
		if ( is_wp_error( $started ) ) {
			return $started;
		}
		$locked = $this->bookings->get_for_update( $booking_id );
		if ( ! is_array( $locked ) || (int) $locked['venue_term_id'] !== (int) $booking['venue_term_id'] ) {
			return $this->rollback( $this->forbidden() );
		}
		$rows = $this->activity->communication_state_rows( $booking_id );
		if ( is_wp_error( $rows ) ) {
			return $this->rollback( $rows );
		}
		$committed = $this->commit();
		if ( is_wp_error( $committed ) ) {
			return $committed;
		}
		$rows = array_values( array_filter( $rows, array( $this, 'is_communication_activity' ) ) );
		return array_slice( array_reverse( $rows ), 0, 200 );
	}

	public function is_communication_activity( array $activity ): bool {
		return 0 === strpos( $activity['kind'], 'booking_message_' ) || 0 === strpos( $activity['kind'], 'booking_reminder_' );
	}

	private function suppress_pending_reminders( int $booking_id, string $reason ): void {
		$rows = $this->activity->communication_state_rows( $booking_id );
		if ( ! is_array( $rows ) ) {
			return;
		}
		foreach ( $rows as $row ) {
			if ( 'booking_message_requested' !== $row['kind'] || empty( $row['payload']['data']['send_at'] ) ) {
				continue;
			}
			$state = $this->state_for_intent( $row );
			if ( is_wp_error( $state ) ) {
				return;
			}
			if ( 'scheduled' !== $state['status'] ) {
				continue;
			}
			if ( ! empty( $state['action_id'] ) ) {
				if ( $this->cancel ) {
					call_user_func( $this->cancel, $state['action_id'] );
				} elseif ( class_exists( '\ActionScheduler' ) ) {
					\ActionScheduler::store()->cancel_action( $state['action_id'] );
				}
			}
			$this->append_state( $row, 'booking_reminder_suppressed', 'suppressed', array( 'reason' => $reason ) );
		}
	}

	private function append_state( array $intent, string $kind, string $stage, array $payload, ?string $external_id = null ) {
		if ( 'booking_message_failed' === $kind ) {
			$payload['status'] = 'failed';
		}
		$attempt = 1;
		$rows = $this->activity->communication_state_rows( $intent['booking_id'] );
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}
		foreach ( $rows as $row ) {
			if ( (int) ( $row['payload']['data']['intent_id'] ?? 0 ) === (int) $intent['id'] && $kind === $row['kind'] ) {
				++$attempt;
			}
		}
		return $this->activity->append(
			array(
				'booking_id'      => $intent['booking_id'],
				'kind'            => $kind,
				'actor_type'      => 'system',
				'direction'       => 'outbound',
				'channel'         => 'email',
				'external_id'     => $external_id,
				'idempotency_key' => sprintf( 'booking-message:%d:%s:%d', $intent['id'], $stage, $attempt ),
				'payload'         => array_merge( array( 'intent_id' => $intent['id'], 'stage' => $stage, 'attempt' => $attempt ), $payload ),
			)
		);
	}

	private function state_for_intent( array $intent ) {
		$state = array( 'intent_id' => $intent['id'], 'booking_id' => $intent['booking_id'], 'message_id' => $intent['payload']['data']['message_id'], 'status' => 'requested', 'action_id' => null );
		$rows  = $this->activity->communication_state_rows( $intent['booking_id'] );
		if ( is_wp_error( $rows ) ) {
			return $rows;
		}
		usort( $rows, static function ( array $left, array $right ): int { return $left['id'] <=> $right['id']; } );
		foreach ( $rows as $row ) {
			if ( (int) ( $row['payload']['data']['intent_id'] ?? 0 ) !== (int) $intent['id'] ) {
				continue;
			}
			$status = $row['payload']['data']['status'] ?? ( $row['payload']['data']['stage'] ?? null );
			if ( $status ) {
				$state['status'] = $status;
			}
			if ( isset( $row['payload']['data']['action_id'] ) ) {
				$state['action_id'] = (int) $row['payload']['data']['action_id'];
			}
		}
		return $state;
	}

	private function has_qualifying_reply( array $intent ): bool {
		$rows = $this->activity->communication_state_rows( $intent['booking_id'] );
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( 'booking_message_received' === $row['kind'] && $row['occurred_at'] >= $intent['occurred_at'] ) {
				return true;
			}
		}
		return false;
	}

	private function thread_belongs_to_booking( int $booking_id, string $message_id ): bool {
		$rows = $this->activity->communication_state_rows( $booking_id );
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			if ( 'booking_message_requested' === $row['kind'] && hash_equals( (string) ( $row['payload']['data']['message_id'] ?? '' ), $message_id ) ) {
				return true;
			}
		}
		return false;
	}

	/** Serialize the external side effect and leave a durable retry boundary. */
	private function claim_side_effect( array $intent, string $stage, array $allowed_statuses ) {
		global $wpdb;
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return new \WP_Error( 'booking_communication_transaction_start_failed', __( 'The booking communication transaction could not start.', 'extrachill-events' ) );
		}
		$this->transaction_active = true;
		$booking                  = $this->bookings->get_for_update( $intent['booking_id'] );
		if ( ! is_array( $booking ) ) {
			return $this->rollback( is_wp_error( $booking ) ? $booking : new \WP_Error( 'booking_not_found', __( 'The booking was not found.', 'extrachill-events' ) ) );
		}
		$state = $this->state_for_intent( $intent );
		if ( is_wp_error( $state ) ) {
			return $this->rollback( $state );
		}
		if ( ! in_array( $state['status'], $allowed_statuses, true ) ) {
			$committed = $this->commit();
			return is_wp_error( $committed ) ? $committed : $state;
		}
		$event = $this->append_state( $intent, 'scheduling' === $stage ? 'booking_reminder_scheduling' : 'booking_message_dispatching', $stage, array() );
		if ( is_wp_error( $event ) ) {
			return $this->rollback( $event );
		}
		$committed = $this->commit();
		return is_wp_error( $committed ) ? $committed : true;
	}

	private function normalize_request( array $input ) {
		$booking_id = absint( $input['booking_id'] ?? 0 );
		$key        = mb_substr( sanitize_text_field( (string) ( $input['idempotency_key'] ?? '' ) ), 0, 120 );
		$template   = sanitize_key( (string) ( $input['template'] ?? '' ) );
		$recipient  = sanitize_email( (string) ( $input['recipient'] ?? '' ) );
		$subject    = mb_substr( sanitize_text_field( (string) ( $input['subject'] ?? '' ) ), 0, 200 );
		$message    = mb_substr( sanitize_textarea_field( (string) ( $input['message'] ?? '' ) ), 0, 10000 );
		$reply_to   = sanitize_email( (string) ( $input['reply_to'] ?? '' ) );
		if ( $booking_id < 1 || '' === $key || ! in_array( $template, self::TEMPLATES, true ) || '' === $recipient || '' === $subject || '' === $message || '' === $reply_to ) {
			return new \WP_Error( 'booking_message_invalid', __( 'The booking message request is invalid.', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		$send_at = $input['send_at'] ?? null;
		if ( null !== $send_at && ( ! is_string( $send_at ) || ! $this->valid_datetime( $send_at ) || strtotime( $send_at . ' UTC' ) <= time() ) ) {
			return new \WP_Error( 'booking_reminder_datetime_invalid', __( 'Reminder time must be a future UTC datetime.', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		$statuses = array_values( array_unique( array_map( 'sanitize_key', (array) ( $input['expected_statuses'] ?? array() ) ) ) );
		if ( null !== $send_at && ( empty( $statuses ) || array_diff( $statuses, BookingRepository::STATUSES ) ) ) {
			return new \WP_Error( 'booking_reminder_statuses_invalid', __( 'Scheduled reminders require valid expected booking statuses.', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		$in_reply_to = empty( $input['in_reply_to'] ) ? null : $this->message_id( $input['in_reply_to'] );
		if ( ! empty( $input['in_reply_to'] ) && '' === $in_reply_to ) {
			return new \WP_Error( 'booking_message_thread_invalid', __( 'The referenced message identifier is invalid.', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		return array(
			'booking_id'        => $booking_id,
			'idempotency_key'   => $key,
			'template'          => $template,
			'recipient'         => $recipient,
			'subject'           => $subject,
			'message'           => $message,
			'reply_to'          => $reply_to,
			'send_at'           => $send_at,
			'expected_statuses' => $statuses,
			'in_reply_to'       => $in_reply_to,
		);
	}

	private function message_id( $value ): string {
		$value = mb_substr( trim( (string) $value ), 0, 191 );
		return false === strpos( $value, "\r" ) && false === strpos( $value, "\n" ) ? $value : '';
	}

	private function render_body( string $template, string $message ): string {
		$lead = array(
			'operator_message' => 'A message from the Extra Chill booking team:',
			'follow_up'        => 'Following up on your booking inquiry:',
			'hold_expiring'    => 'A reminder about your booking hold:',
		)[ $template ];
		return $lead . "\n\n" . $message . "\n\nExtra Chill Bot sending on Chris's behalf.";
	}

	private function request_hash( array $request, int $actor_id ): string {
		ksort( $request );
		return hash_hmac( 'sha256', wp_json_encode( array( 'actor_id' => $actor_id, 'request' => $request ) ), wp_salt( 'auth' ) );
	}

	private function valid_datetime( string $value ): bool {
		$date = \DateTimeImmutable::createFromFormat( '!Y-m-d H:i:s', $value, new \DateTimeZone( 'UTC' ) );
		return false !== $date && $date->format( 'Y-m-d H:i:s' ) === $value;
	}

	private function begin_authorized( int $venue_id, int $actor_id ) {
		global $wpdb;
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) {
			return new \WP_Error( 'booking_communication_transaction_start_failed', __( 'The booking communication transaction could not start.', 'extrachill-events' ) );
		}
		$this->transaction_active = true;
		$table  = BookingSchema::memberships_table();
		$locked = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE venue_term_id = %d ORDER BY id ASC FOR UPDATE", $venue_id ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error ) {
			return $this->rollback( new \WP_Error( 'booking_communication_authorization_lock_failed', __( 'Venue booking authority could not be locked.', 'extrachill-events' ) ) );
		}
		$allowed = $this->authorization->authorize_locked( $actor_id, $venue_id, VenueAuthorization::ACTION_ACCESS_VENUE, (array) $locked );
		return true === $allowed ? true : $this->rollback( is_wp_error( $allowed ) ? $allowed : $this->forbidden() );
	}

	private function commit() {
		global $wpdb;
		$result                   = $wpdb->query( 'COMMIT' );
		$this->transaction_active = false;
		return false === $result ? new \WP_Error( 'booking_communication_commit_uncertain', __( 'The booking communication transaction outcome is uncertain.', 'extrachill-events' ) ) : true;
	}

	private function rollback( \WP_Error $error ) {
		global $wpdb;
		if ( $this->transaction_active ) {
			$wpdb->query( 'ROLLBACK' );
			$this->transaction_active = false;
		}
		return $error;
	}

	private function forbidden(): \WP_Error {
		return new \WP_Error( 'venue_action_forbidden', __( 'You are not authorized to perform this venue action.', 'extrachill-events' ), array( 'status' => 403 ) );
	}
}
