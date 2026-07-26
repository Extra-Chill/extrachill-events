<?php
/**
 * Booking notification outbox tests.
 *
 * @package ExtraChillEvents\Tests
 */

use ExtraChillEvents\Core\BookingActivityRepository;
use ExtraChillEvents\Core\BookingNotificationService;
use ExtraChillEvents\Core\BookingRepository;
use ExtraChillEvents\Core\BookingSchema;
use ExtraChillEvents\Core\VenueAuthorization;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Support/BookingTestHarness.php';

/** Covers recovery, authorization, and structured receipt reconciliation. */
final class BookingNotificationTest extends TestCase {
	/** Reset booking persistence. */
	protected function setUp(): void {
		$GLOBALS['ec_artist_test'] = array(
			'blog_id' => 7,
			'stack'   => array(),
			'uuid'    => 0,
			'terms'   => array(
				7 => array(
					55 => (object) array(
						'term_id'  => 55,
						'taxonomy' => 'venue',
						'name'     => 'The Room',
					),
				),
			),
			'meta'    => array(),
			'posts'   => array(),
		);
		$GLOBALS['wpdb']           = new BookingWpdb();
	}

	/** Create one private booking fixture. */
	private function booking(): array {
		return ( new BookingRepository() )->create(
			array(
				'venue_term_id' => 55,
				'artist_name'   => 'Private Artist',
				'contact_name'  => 'Private Contact',
				'contact_email' => 'private@example.com',
				'contact_phone' => '555-0100',
				'intake'        => array( 'private_note' => 'not for notifications' ),
			)
		);
	}

	/** Append one source activity fixture. */
	private function source( array $booking, string $kind = 'inquiry_submitted', array $payload = array( 'status' => 'submitted' ) ): array {
		return ( new BookingActivityRepository() )->append(
			array(
				'booking_id' => $booking['id'],
				'kind'       => $kind,
				'actor_type' => 'anonymous',
				'payload'    => $payload,
			)
		);
	}

	/** Add one membership fixture. */
	private function member( int $id, int $venue, int $user, string $status, bool $owner ): void {
		$GLOBALS['wpdb']->rows[ BookingSchema::memberships_table() ][ $id ] = array(
			'id'                 => $id,
			'venue_term_id'      => $venue,
			'user_id'            => $user,
			'is_owner'           => $owner ? 1 : 0,
			'status'             => $status,
			'version'            => 1,
			'created_by_user_id' => 1,
			'created_at'         => '2026-01-01 00:00:00',
			'updated_at'         => '2026-01-01 00:00:00',
			'revoked_at'         => VenueAuthorization::STATUS_REVOKED === $status ? '2026-01-02 00:00:00' : null,
		);
	}

	/** Build an authorized destination resolver that follows canonical policy. */
	private function destination( array &$resolved ): callable {
		$authorization = new BookingTestAuthorization(
			array(
				'10:55' => true,
				'11:55' => true,
			)
		);
		return static function ( array $booking, array $recipient_ids, array $members ) use ( &$resolved, $authorization ) {
			foreach ( $recipient_ids as $recipient_id ) {
				$allowed = $authorization->authorize_locked( $recipient_id, $booking['venue_term_id'], VenueAuthorization::ACTION_ACCESS_VENUE, $members );
				if ( true !== $allowed ) {
					return $allowed;
				}
			}
			$resolved[] = array(
				'booking_id' => $booking['id'],
				'recipients' => $recipient_ids,
			);
			return 'https://events.example/manage/booking/' . rawurlencode( $booking['public_id'] );
		};
	}

	/** Source recovery creates an idempotent outbox request after emit failure. */
	public function test_recovery_creates_missing_request_once(): void {
		$booking = $this->booking();
		$ignored = $this->source( $booking, 'status_changed', array( 'from_status' => 'submitted', 'to_status' => 'under_review' ) );
		$source  = $this->source( $booking );
		$service = new BookingNotificationService();

		$first  = $service->reconcile_pending();
		$second = $service->reconcile_pending();

		$this->assertSame( 1, $first['recovered'] );
		$this->assertSame( 0, $second['recovered'] );
		$requests = array_values(
			array_filter(
				$GLOBALS['wpdb']->rows[ BookingSchema::activity_table() ],
				static function ( $row ) {
					return 'notification_requested' === $row['kind'];
				}
			)
		);
		$this->assertCount( 1, $requests );
		$this->assertSame( (string) $source['id'], $requests[0]['external_id'] );
		$ignored_rows = array_values( array_filter( $GLOBALS['wpdb']->rows[ BookingSchema::activity_table() ], static function ( $row ) { return 'notification_source_ignored' === $row['kind']; } ) );
		$this->assertSame( (string) $ignored['id'], $ignored_rows[0]['external_id'] );
	}

	/** Production reconciliation calls the landed Users receipt contract. */
	public function test_production_users_receipt_payload_is_idempotent_and_complete(): void {
		$booking = $this->booking();
		$source  = $this->source( $booking );
		$this->member( 1, 55, 10, VenueAuthorization::STATUS_ACTIVE, true );
		$request = ( new BookingNotificationService() )->request( BookingNotificationService::TYPE_INQUIRY_SUBMITTED, $source['id'] );
		$captured = array();
		$GLOBALS['ec_artist_test']['users_receipt'] = static function ( array $recipients, array $payload ) use ( &$captured ): array {
			$captured = compact( 'recipients', 'payload' );
			return array( 'recipients' => array( 10 => array( 'status' => 'inserted', 'notification_id' => 100 ) ) );
		};
		$result = ( new BookingNotificationService( null, null, null, null, static function (): int { return 99; } ) )->reconcile( $request['id'] );

		$this->assertSame( 'notification_delivered', $result['kind'] );
		$this->assertSame( array( 10 ), $captured['recipients'] );
		$this->assertSame( 'extrachill-events-booking', $captured['payload']['producer'] );
		$this->assertSame( 'notification-request:booking_inquiry_submitted:' . $source['id'], $captured['payload']['idempotency_key'] );
		$this->assertSame( 'https://events.example/venue/55', $captured['payload']['link'] );
	}

	/** Partial and zero delivery receipts remain retryable until every user resolves. */
	public function test_partial_and_zero_receipts_retry_safely_to_completion(): void {
		$booking = $this->booking();
		$source  = $this->source( $booking );
		$this->member( 1, 55, 10, VenueAuthorization::STATUS_ACTIVE, true );
		$this->member( 2, 55, 11, VenueAuthorization::STATUS_ACTIVE, true );
		$this->member( 3, 55, 12, VenueAuthorization::STATUS_INVITED, false );
		$this->member( 4, 77, 13, VenueAuthorization::STATUS_ACTIVE, true );
		$request  = ( new BookingNotificationService() )->request( BookingNotificationService::TYPE_INQUIRY_SUBMITTED, $source['id'] );
		$resolved = array();
		$calls    = 0;
		$delivery = function ( array $recipients, array $payload, string $producer, string $key ) use ( &$calls ): array {
			++$calls;
			$this_call = $calls;
			if ( 1 === $this_call ) {
				return array(
					'recipients' => array(
						10 => array( 'status' => 'failed' ),
						11 => array( 'status' => 'failed' ),
					),
				);
			}
			if ( 2 === $this_call ) {
				return array(
					'recipients' => array(
						10 => array(
							'status'          => 'inserted',
							'notification_id' => 100,
						),
						11 => array( 'status' => 'failed' ),
					),
				);
			}
			$this->assertSame( array( 10, 11 ), $recipients );
			$this->assertSame( 'extrachill-events-booking', $producer );
			$this->assertStringStartsWith( 'notification-request:', $key );
			$this->assertStringNotContainsString( 'Private', wp_json_encode( $payload ) );
			return array(
				'recipients' => array(
					10 => array(
						'status'          => 'existing',
						'notification_id' => 100,
					),
					11 => array(
						'status'          => 'inserted',
						'notification_id' => 101,
					),
				),
			);
		};
		$service  = new BookingNotificationService(
			null,
			null,
			$delivery,
			$this->destination( $resolved ),
			static function (): int {
				return 99;
			}
		);

		$zero     = $service->reconcile( $request['id'] );
		$partial  = $service->reconcile( $request['id'] );
		$complete = $service->reconcile( $request['id'] );
		$replay   = $service->reconcile( $request['id'] );

		$this->assertSame( 'notification_delivery_attempted', $zero['kind'] );
		$this->assertSame( 2, $zero['payload']['data']['failed_count'] );
		$this->assertSame( 'notification_delivery_attempted', $partial['kind'] );
		$this->assertSame( 'notification_delivered', $complete['kind'] );
		$this->assertSame( $complete['id'], $replay['id'] );
		$this->assertSame( 3, $calls );
		$this->assertCount( 3, $resolved );
	}

	/** Event definitions select owners and assignees instead of every member. */
	public function test_recipient_policy_is_role_and_event_type_aware(): void {
		$booking = $this->booking();
		$this->member( 1, 55, 10, VenueAuthorization::STATUS_ACTIVE, true );
		$this->member( 2, 55, 11, VenueAuthorization::STATUS_ACTIVE, false );
		$this->member( 3, 55, 12, VenueAuthorization::STATUS_ACTIVE, false );
		$deliveries = array();
		$service    = new BookingNotificationService(
			null,
			null,
			static function ( array $recipients ) use ( &$deliveries ): array {
				$deliveries[] = $recipients;
				$rows = array();
				foreach ( $recipients as $recipient ) {
					$rows[ $recipient ] = array( 'status' => 'inserted' );
				}
				return array( 'recipients' => $rows );
			},
			$this->destination( $deliveries ),
			static function (): int { return 99; }
		);
		$inquiry   = $this->source( $booking );
		$assignment = $this->source( $booking, 'assignment_changed', array( 'to_assignee_user_id' => 11 ) );
		$information = $this->source( $booking, 'status_changed', array( 'from_status' => 'needs_info', 'to_status' => 'submitted' ) );
		$GLOBALS['wpdb']->rows[ BookingSchema::bookings_table() ][ $booking['id'] ]['assignee_user_id'] = 11;

		$service->reconcile( $service->request( BookingNotificationService::TYPE_INQUIRY_SUBMITTED, $inquiry['id'] )['id'] );
		$service->reconcile( $service->request( BookingNotificationService::TYPE_ASSIGNMENT_CHANGED, $assignment['id'] )['id'] );
		$service->reconcile( $service->request( BookingNotificationService::TYPE_INFORMATION_RECEIVED, $information['id'] )['id'] );

		$this->assertSame( array( 10 ), $deliveries[1] );
		$this->assertSame( array( 10, 11 ), $deliveries[3] );
		$this->assertSame( array( 11 ), $deliveries[5] );
	}

	/** Permanent dependency failures become terminal so later requests can advance. */
	public function test_permanent_failure_is_poisoned_after_bounded_attempts(): void {
		$booking = $this->booking();
		$source  = $this->source( $booking );
		$this->member( 1, 55, 10, VenueAuthorization::STATUS_ACTIVE, true );
		$request = ( new BookingNotificationService() )->request( BookingNotificationService::TYPE_INQUIRY_SUBMITTED, $source['id'] );
		$service = new BookingNotificationService( null, null, null, static function () { return new WP_Error( 'permanent_route_failure' ); } );
		for ( $attempt = 0; $attempt < BookingNotificationService::MAX_ATTEMPTS; ++$attempt ) {
			$service->reconcile_pending();
		}
		$terminal = ( new BookingActivityRepository() )->notification_terminal( $request['id'] );
		$this->assertSame( 'notification_suppressed', $terminal['kind'] );
		$this->assertSame( 'delivery_poisoned', $terminal['payload']['data']['reason'] );
		$this->assertSame( BookingNotificationService::MAX_ATTEMPTS, $terminal['payload']['data']['attempt'] );
	}

	/** Revocation between attempts suppresses the user before retry delivery. */
	public function test_revocation_race_reselects_exact_active_recipients(): void {
		$booking = $this->booking();
		$source  = $this->source( $booking, 'assignment_changed', array( 'to_assignee_user_id' => 11 ) );
		$this->member( 1, 55, 10, VenueAuthorization::STATUS_ACTIVE, true );
		$this->member( 2, 55, 11, VenueAuthorization::STATUS_ACTIVE, false );
		$request    = ( new BookingNotificationService() )->request( BookingNotificationService::TYPE_ASSIGNMENT_CHANGED, $source['id'] );
		$deliveries = array();
		$resolved   = array();
		$service    = new BookingNotificationService(
			null,
			null,
			static function ( array $recipients ) use ( &$deliveries ): array {
				$deliveries[] = $recipients;
				$rows         = array();
				foreach ( $recipients as $recipient ) {
					$rows[ $recipient ] = array( 'status' => 1 === count( $deliveries ) ? 'failed' : 'existing' );
				}
				return array( 'recipients' => $rows );
			},
			$this->destination( $resolved ),
			static function (): int {
				return 99; }
		);

		$service->reconcile( $request['id'] );
		$GLOBALS['wpdb']->rows[ BookingSchema::memberships_table() ][2]['status'] = VenueAuthorization::STATUS_REVOKED;
		$service->reconcile( $request['id'] );

		$this->assertSame( array( 10, 11 ), $deliveries[0] );
		$this->assertSame( array( 10 ), $deliveries[1] );
	}

	/** Recovery emits only the five landed event mappings. */
	public function test_landed_and_future_source_seams_remain_explicit(): void {
		$booking = $this->booking();
		$events  = array(
			array( BookingNotificationService::TYPE_INQUIRY_SUBMITTED, 'inquiry_submitted', array() ),
			array( BookingNotificationService::TYPE_ASSIGNMENT_CHANGED, 'assignment_changed', array() ),
			array(
				BookingNotificationService::TYPE_INFORMATION_RECEIVED,
				'status_changed',
				array(
					'from_status' => 'needs_info',
					'to_status'   => 'submitted',
				),
			),
			array( BookingNotificationService::TYPE_HOLD_EXPIRED, 'hold_expired', array() ),
			array( BookingNotificationService::TYPE_EVENT_HANDOFF_FAILED, 'event_conversion_failed', array() ),
		);
		$service = new BookingNotificationService();
		foreach ( $events as $event ) {
			$source = $this->source( $booking, $event[1], $event[2] );
			$this->assertSame( $event[0], $service->request( $event[0], $source['id'] )['payload']['data']['notification_type'] );
		}
		$future = $this->source( $booking, 'settlement_ready', array() );
		$this->assertSame( BookingNotificationService::TYPE_SETTLEMENT_READY, $service->request( BookingNotificationService::TYPE_SETTLEMENT_READY, $future['id'] )['payload']['data']['notification_type'] );
	}
}
