<?php
/**
 * Venue operator notifications for durable booking activity.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Selects booking events and delegates network notification delivery to Users. */
class BookingNotificationService {

	public const EMIT_HOOK = 'extrachill_events_emit_booking_notification';

	public const TYPE_INQUIRY_SUBMITTED    = 'booking_inquiry_submitted';
	public const TYPE_ASSIGNMENT_CHANGED   = 'booking_assignment_changed';
	public const TYPE_INFORMATION_RECEIVED = 'booking_information_received';
	public const TYPE_HOLD_EXPIRED         = 'booking_hold_expired';
	public const TYPE_EVENT_HANDOFF_FAILED = 'booking_event_handoff_failed';

	// Reserved extension types whose domain producers have not landed yet.
	public const TYPE_ARTIST_REPLIED          = 'booking_artist_replied';
	public const TYPE_HOLD_EXPIRING           = 'booking_hold_expiring';
	public const TYPE_MARKETING_FAILED        = 'booking_marketing_failed';
	public const TYPE_SETTLEMENT_READY        = 'booking_settlement_ready';
	public const TYPE_OPERATOR_ACTION_OVERDUE = 'booking_operator_action_overdue';

	/**
	 * Booking persistence.
	 *
	 * @var BookingRepository
	 */
	private $bookings;
	/**
	 * Activity persistence.
	 *
	 * @var BookingActivityRepository
	 */
	private $activity;
	/**
	 * Optional delivery test double.
	 *
	 * @var callable|null
	 */
	private $notify;
	/**
	 * Optional actor resolver test double.
	 *
	 * @var callable|null
	 */
	private $actor_resolver;
	/**
	 * Optional management-link test double.
	 *
	 * @var callable|null
	 */
	private $link_resolver;
	/**
	 * Whether this instance owns an open transaction.
	 *
	 * @var bool
	 */
	private $transaction_active = false;

	/**
	 * Build the notification coordinator.
	 *
	 * @param BookingRepository|null         $bookings Booking persistence.
	 * @param BookingActivityRepository|null $activity Activity persistence.
	 * @param callable|null                  $notify   Optional delivery callback.
	 * @param callable|null                  $actor_resolver Optional actor resolver.
	 * @param callable|null                  $link_resolver  Optional link resolver.
	 */
	public function __construct( ?BookingRepository $bookings = null, ?BookingActivityRepository $activity = null, $notify = null, $actor_resolver = null, $link_resolver = null ) {
		$this->bookings       = $bookings ? $bookings : new BookingRepository();
		$this->activity       = $activity ? $activity : new BookingActivityRepository();
		$this->notify         = $notify;
		$this->actor_resolver = $actor_resolver;
		$this->link_resolver  = $link_resolver;
	}

	/** Register the explicit producer seam used by later booking domains. */
	public static function register(): void {
		add_action( self::EMIT_HOOK, array( self::class, 'emit' ), 10, 2 );
	}

	/**
	 * Dispatch without changing the owning domain result.
	 *
	 * @param string $type               Notification type.
	 * @param int    $source_activity_id Owning activity ID.
	 */
	public static function emit( string $type, int $source_activity_id ): void {
		if ( ! function_exists( 'ec_users_notify' ) ) {
			return;
		}
		( new self() )->deliver( $type, $source_activity_id );
	}

	/**
	 * Deliver one activity-backed event exactly once.
	 *
	 * @param string $type               Notification type.
	 * @param int    $source_activity_id Owning activity ID.
	 * @return array|\WP_Error Delivery evidence or an error.
	 */
	public function deliver( string $type, int $source_activity_id ) {
		$definition = $this->definition( $type );
		if ( null === $definition || $source_activity_id < 1 ) {
			return new \WP_Error( 'booking_notification_event_invalid', __( 'The booking notification event is invalid.', 'extrachill-events' ) );
		}
		$source = $this->activity->get( $source_activity_id );
		if ( ! is_array( $source ) || ! $this->source_matches( $definition, $source ) ) {
			return new \WP_Error( 'booking_notification_source_invalid', __( 'The booking notification source activity is invalid.', 'extrachill-events' ) );
		}
		$booking = $this->bookings->get( $source['booking_id'] );
		if ( ! is_array( $booking ) ) {
			return is_wp_error( $booking ) ? $booking : new \WP_Error( 'booking_not_found', __( 'The booking was not found.', 'extrachill-events' ) );
		}
		if ( ! $this->notify && ! function_exists( 'ec_users_notify' ) ) {
			return new \WP_Error( 'booking_notification_service_unavailable', __( 'Operator notifications are temporarily unavailable.', 'extrachill-events' ) );
		}

		$started = $this->begin();
		if ( is_wp_error( $started ) ) {
			return $started;
		}
		global $wpdb;
		$members_table = BookingSchema::memberships_table();
		$members       = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$members_table} WHERE venue_term_id = %d ORDER BY id ASC FOR UPDATE", $booking['venue_term_id'] ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Serializes recipient selection with membership changes.
		if ( '' !== (string) $wpdb->last_error ) {
			return $this->rollback( new \WP_Error( 'booking_notification_membership_read_failed', __( 'Venue notification recipients could not be locked.', 'extrachill-events' ), array( 'database_error' => $wpdb->last_error ) ) );
		}
		$locked = $this->bookings->get_for_update( $booking['id'] );
		if ( ! is_array( $locked ) || (int) $locked['venue_term_id'] !== (int) $booking['venue_term_id'] ) {
			return $this->rollback( is_wp_error( $locked ) ? $locked : new \WP_Error( 'booking_notification_booking_changed', __( 'The booking notification target changed.', 'extrachill-events' ) ) );
		}
		$source = $this->activity->get( $source_activity_id );
		if ( ! is_array( $source ) || (int) $source['booking_id'] !== (int) $locked['id'] || ! $this->source_matches( $definition, $source ) ) {
			return $this->rollback( new \WP_Error( 'booking_notification_source_invalid', __( 'The booking notification source activity is invalid.', 'extrachill-events' ) ) );
		}

		$key      = sprintf( 'notification:%s:%d', $type, $source_activity_id );
		$existing = $this->activity->find_by_idempotency( $locked['id'], $key . ':receipt' );
		if ( is_wp_error( $existing ) ) {
			return $this->rollback( $existing );
		}
		if ( is_array( $existing ) ) {
			$committed = $this->commit();
			return is_wp_error( $committed ) ? $committed : $existing;
		}
		$claim = $this->activity->append(
			array(
				'booking_id'      => $locked['id'],
				'kind'            => 'notification_claimed',
				'actor_type'      => 'system',
				'idempotency_key' => $key . ':claim',
				'payload'         => array(
					'notification_type'  => $type,
					'source_activity_id' => $source_activity_id,
				),
			)
		);
		if ( is_wp_error( $claim ) ) {
			return $this->rollback( $claim );
		}

		$recipient_ids = $this->active_recipient_ids( (array) $members, (int) $locked['venue_term_id'] );
		$actor_id      = $this->actor_id( $source );
		$link          = $this->management_link( $locked );
		$inserted      = null;
		$status        = 'skipped';
		if ( empty( $recipient_ids ) ) {
			$status = 'no_recipients';
		} elseif ( $actor_id < 1 || '' === $link ) {
			$status = 'unavailable';
		} else {
			try {
				$payload = array(
					'actor_id' => $actor_id,
					'type'     => $type,
					'link'     => $link,
					'title'    => $definition['title'],
					'item_id'  => (int) $locked['id'],
				);
				$result  = $this->notify ? call_user_func( $this->notify, $recipient_ids, $payload ) : ec_users_notify( $recipient_ids, $payload );
				if ( is_int( $result ) && 0 <= $result ) {
					$inserted = $result;
					$status   = count( $recipient_ids ) === $result ? 'delivered' : ( 0 === $result ? 'failed' : 'partial' );
				} else {
					$status = 'unknown';
				}
			} catch ( \Throwable $throwable ) {
				$status = 'unknown';
			}
		}

		$receipt = $this->activity->append(
			array(
				'booking_id'      => $locked['id'],
				'kind'            => 'notification_delivery_recorded',
				'actor_type'      => 'system',
				'idempotency_key' => $key . ':receipt',
				'payload'         => array(
					'notification_type'  => $type,
					'source_activity_id' => $source_activity_id,
					'claim_activity_id'  => (int) $claim['id'],
					'recipient_user_ids' => $recipient_ids,
					'expected_count'     => count( $recipient_ids ),
					'inserted_count'     => $inserted,
					'status'             => $status,
				),
			)
		);
		if ( is_wp_error( $receipt ) ) {
			return $this->rollback( $receipt );
		}
		$committed = $this->commit();
		return is_wp_error( $committed ) ? $committed : $receipt;
	}

	/**
	 * Return landed definitions and explicit future producer seams.
	 *
	 * @param string $type Notification type.
	 * @return array|null Event definition.
	 */
	private function definition( string $type ): ?array {
		$definitions = array(
			self::TYPE_INQUIRY_SUBMITTED       => array(
				'kind'  => 'inquiry_submitted',
				'title' => __( 'New booking inquiry', 'extrachill-events' ),
			),
			self::TYPE_ASSIGNMENT_CHANGED      => array(
				'kind'  => 'assignment_changed',
				'title' => __( 'Booking assignment changed', 'extrachill-events' ),
			),
			self::TYPE_INFORMATION_RECEIVED    => array(
				'kind'  => 'status_changed',
				'title' => __( 'Booking information received', 'extrachill-events' ),
				'from'  => 'needs_info',
				'to'    => 'submitted',
			),
			self::TYPE_HOLD_EXPIRED            => array(
				'kind'  => 'hold_expired',
				'title' => __( 'Booking hold expired', 'extrachill-events' ),
			),
			self::TYPE_EVENT_HANDOFF_FAILED    => array(
				'kind'  => 'event_conversion_failed',
				'title' => __( 'Booking event handoff failed', 'extrachill-events' ),
			),
			self::TYPE_ARTIST_REPLIED          => array(
				'kind'  => 'artist_replied',
				'title' => __( 'Artist replied about a booking', 'extrachill-events' ),
			),
			self::TYPE_HOLD_EXPIRING           => array(
				'kind'  => 'hold_expiring',
				'title' => __( 'Booking hold expiring', 'extrachill-events' ),
			),
			self::TYPE_MARKETING_FAILED        => array(
				'kind'  => 'marketing_action_failed',
				'title' => __( 'Booking marketing action failed', 'extrachill-events' ),
			),
			self::TYPE_SETTLEMENT_READY        => array(
				'kind'  => 'settlement_ready',
				'title' => __( 'Booking settlement ready', 'extrachill-events' ),
			),
			self::TYPE_OPERATOR_ACTION_OVERDUE => array(
				'kind'  => 'operator_action_overdue',
				'title' => __( 'Booking action overdue', 'extrachill-events' ),
			),
		);
		return $definitions[ $type ] ?? null;
	}

	/**
	 * Validate that an activity owns the requested event.
	 *
	 * @param array $definition Event definition.
	 * @param array $source     Source activity.
	 * @return bool Whether the activity matches.
	 */
	private function source_matches( array $definition, array $source ): bool {
		if ( ( $source['kind'] ?? '' ) !== $definition['kind'] ) {
			return false;
		}
		$data = $source['payload']['data'] ?? array();
		return ! isset( $definition['from'] ) || ( ( $data['from_status'] ?? null ) === $definition['from'] && ( $data['to_status'] ?? null ) === $definition['to'] );
	}

	/**
	 * Resolve only current exact active venue members from locked rows.
	 *
	 * @param array $rows          Locked membership rows.
	 * @param int   $venue_term_id Exact booking venue.
	 * @return int[] Recipient user IDs.
	 */
	private function active_recipient_ids( array $rows, int $venue_term_id ): array {
		$ids        = array();
		$repository = new VenueMembershipRepository();
		foreach ( $rows as $row ) {
			if ( (int) ( $row['venue_term_id'] ?? 0 ) !== $venue_term_id || VenueAuthorization::STATUS_ACTIVE !== ( $row['status'] ?? '' ) ) {
				continue;
			}
			$membership = $repository->hydrate( $row );
			if ( ! is_wp_error( $membership ) && get_userdata( $membership['user_id'] ) ) {
				$ids[] = (int) $membership['user_id'];
			}
		}
		return array_values( array_unique( $ids ) );
	}

	/**
	 * Resolve the user actor or bounded network bot actor.
	 *
	 * @param array $source Source activity.
	 * @return int Valid network user ID, or zero.
	 */
	private function actor_id( array $source ): int {
		if ( $this->actor_resolver ) {
			return absint( call_user_func( $this->actor_resolver, $source ) );
		}
		$actor_id = absint( $source['actor_id'] ?? 0 );
		if ( 0 < $actor_id && get_userdata( $actor_id ) ) {
			return $actor_id;
		}
		$actor_id = function_exists( 'ec_get_network_bot_user_id' ) ? absint( ec_get_network_bot_user_id() ) : 0;
		return 0 < $actor_id && get_userdata( $actor_id ) ? $actor_id : 0;
	}

	/**
	 * Build a same-site opaque booking management link.
	 *
	 * @param array $booking Booking record.
	 * @return string Safe management URL, or empty string.
	 */
	private function management_link( array $booking ): string {
		if ( $this->link_resolver ) {
			return (string) call_user_func( $this->link_resolver, $booking );
		}
		$base = function_exists( 'ec_get_site_url' ) ? ec_get_site_url( 'events' ) : home_url( '/' );
		if ( ! is_string( $base ) || ! preg_match( '#^https?://#i', $base ) ) {
			return '';
		}
		return esc_url_raw(
			add_query_arg(
				array(
					'booking' => (string) $booking['public_id'],
					'venue'   => (int) $booking['venue_term_id'],
				),
				trailingslashit( $base )
			)
		);
	}

	/** Start the recipient claim transaction. */
	private function begin() {
		global $wpdb;
		if ( false === $wpdb->query( 'START TRANSACTION' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Notification claim transaction.
			return new \WP_Error( 'booking_notification_transaction_failed', __( 'The booking notification transaction could not start.', 'extrachill-events' ), array( 'database_error' => $wpdb->last_error ) );
		}
		$this->transaction_active = true;
		return true;
	}

	/** Commit notification delivery and evidence together. */
	private function commit() {
		global $wpdb;
		$result                   = $wpdb->query( 'COMMIT' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Notification claim transaction.
		$this->transaction_active = false;
		return false === $result ? new \WP_Error( 'booking_notification_commit_uncertain', __( 'The booking notification outcome could not be confirmed.', 'extrachill-events' ), array( 'database_error' => $wpdb->last_error ) ) : true;
	}

	/**
	 * Roll back notification delivery while preserving its cause.
	 *
	 * @param \WP_Error $cause Delivery failure.
	 * @return \WP_Error Original failure.
	 */
	private function rollback( \WP_Error $cause ) {
		global $wpdb;
		if ( $this->transaction_active ) {
			$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Notification claim transaction.
			$this->transaction_active = false;
		}
		return $cause;
	}
}
