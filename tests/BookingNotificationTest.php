<?php
/**
 * Booking operator notification tests.
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


/** Covers booking-owned selection, authorization, and delivery evidence. */
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

	/**
	 * Append one source activity fixture.
	 *
	 * @param array  $booking Booking fixture.
	 * @param string $kind    Activity kind.
	 * @param array  $payload Activity payload.
	 * @return array Activity record.
	 */
	private function activity( array $booking, string $kind = 'inquiry_submitted', array $payload = array( 'status' => 'submitted' ) ): array {
		return ( new BookingActivityRepository() )->append(
			array(
				'booking_id' => $booking['id'],
				'kind'       => $kind,
				'actor_type' => 'anonymous',
				'payload'    => $payload,
			)
		);
	}

	/**
	 * Add one membership fixture.
	 *
	 * @param int    $id       Membership ID.
	 * @param int    $venue    Venue term ID.
	 * @param int    $user     User ID.
	 * @param string $status   Membership status.
	 * @param bool   $is_owner Structural ownership flag.
	 */
	private function member( int $id, int $venue, int $user, string $status, bool $is_owner ): void {
		$GLOBALS['wpdb']->rows[ BookingSchema::memberships_table() ][ $id ] = array(
			'id'                 => $id,
			'venue_term_id'      => $venue,
			'user_id'            => $user,
			'is_owner'           => $is_owner ? 1 : 0,
			'status'             => $status,
			'version'            => 1,
			'created_by_user_id' => 1,
			'created_at'         => '2026-01-01 00:00:00',
			'updated_at'         => '2026-01-01 00:00:00',
			'revoked_at'         => VenueAuthorization::STATUS_REVOKED === $status ? '2026-01-02 00:00:00' : null,
		);
	}

	/**
	 * Build a service with isolated dependencies.
	 *
	 * @param callable $notify Users delivery test double.
	 * @return BookingNotificationService Isolated service.
	 */
	private function service( callable $notify ): BookingNotificationService {
		return new BookingNotificationService(
			null,
			null,
			$notify,
			static function (): int {
				return 99;
			},
			static function ( array $booking ): string {
				return 'https://events.example/?booking=' . rawurlencode( $booking['public_id'] ) . '&venue=' . (int) $booking['venue_term_id'];
			}
		);
	}

	/** Active owner and member receive one PII-free Users payload. */
	public function test_exact_active_owner_and_member_receive_safe_digest_payload_once(): void {
		$booking = $this->booking();
		$source  = $this->activity( $booking );
		$this->member( 1, 55, 10, VenueAuthorization::STATUS_ACTIVE, true );
		$this->member( 2, 55, 11, VenueAuthorization::STATUS_ACTIVE, false );
		$this->member( 3, 55, 12, VenueAuthorization::STATUS_INVITED, false );
		$this->member( 4, 55, 13, VenueAuthorization::STATUS_REVOKED, true );
		$this->member( 5, 77, 14, VenueAuthorization::STATUS_ACTIVE, true );
		$calls   = array();
		$notify  = static function ( array $recipients, array $payload ) use ( &$calls ): int {
			$calls[] = array(
				'recipients' => $recipients,
				'payload'    => $payload,
			);
			return count( $recipients );
		};
		$service = $this->service( $notify );

		$receipt = $service->deliver( BookingNotificationService::TYPE_INQUIRY_SUBMITTED, $source['id'] );
		$replay  = $service->deliver( BookingNotificationService::TYPE_INQUIRY_SUBMITTED, $source['id'] );

		$this->assertCount( 1, $calls );
		$this->assertSame( array( 10, 11 ), $calls[0]['recipients'] );
		$this->assertSame( array( 'actor_id', 'type', 'link', 'title', 'item_id' ), array_keys( $calls[0]['payload'] ) );
		$this->assertSame( 99, $calls[0]['payload']['actor_id'] );
		$this->assertSame( 'booking_inquiry_submitted', $calls[0]['payload']['type'] );
		$this->assertSame( 'https://events.example/?booking=' . rawurlencode( $booking['public_id'] ) . '&venue=55', $calls[0]['payload']['link'] );
		$this->assertStringNotContainsString( 'Private', wp_json_encode( $calls[0] ) );
		$this->assertStringNotContainsString( 'private@example.com', wp_json_encode( $receipt ) );
		$this->assertSame( 'delivered', $receipt['payload']['data']['status'] );
		$this->assertSame( $receipt['id'], $replay['id'] );
	}

	/** A member revoked before locked selection is suppressed. */
	public function test_revocation_race_is_resolved_from_locked_membership_rows(): void {
		$booking = $this->booking();
		$source  = $this->activity( $booking );
		$this->member( 1, 55, 10, VenueAuthorization::STATUS_ACTIVE, true );
		$this->member( 2, 55, 11, VenueAuthorization::STATUS_ACTIVE, false );
		$GLOBALS['wpdb']->after_membership_lock = static function (): void {
			$GLOBALS['wpdb']->rows[ BookingSchema::memberships_table() ][2]['status'] = VenueAuthorization::STATUS_REVOKED;
		};
		$recipients                             = array();
		$service                                = $this->service(
			static function ( array $ids ) use ( &$recipients ): int {
				$recipients = $ids;
				return count( $ids );
			}
		);

		$service->deliver( BookingNotificationService::TYPE_INQUIRY_SUBMITTED, $source['id'] );

		$this->assertSame( array( 10 ), $recipients );
	}

	/** An ambiguous Users result is durable and never blindly retried. */
	public function test_unknown_result_is_recorded_and_replay_does_not_redeliver(): void {
		$booking = $this->booking();
		$source  = $this->activity(
			$booking,
			'assignment_changed',
			array(
				'from_assignee_user_id' => null,
				'to_assignee_user_id'   => 10,
			)
		);
		$this->member( 1, 55, 10, VenueAuthorization::STATUS_ACTIVE, true );
		$calls   = 0;
		$service = $this->service(
			static function () use ( &$calls ) {
				++$calls;
				return array( 'unexpected' => true );
			}
		);

		$receipt = $service->deliver( BookingNotificationService::TYPE_ASSIGNMENT_CHANGED, $source['id'] );
		$replay  = $service->deliver( BookingNotificationService::TYPE_ASSIGNMENT_CHANGED, $source['id'] );

		$this->assertSame( 1, $calls );
		$this->assertSame( 'unknown', $receipt['payload']['data']['status'] );
		$this->assertNull( $receipt['payload']['data']['inserted_count'] );
		$this->assertSame( $receipt['id'], $replay['id'] );
	}

	/** Notification types cannot be paired with unrelated activity. */
	public function test_source_contract_and_deep_link_fail_closed(): void {
		$booking = $this->booking();
		$source  = $this->activity(
			$booking,
			'status_changed',
			array(
				'from_status' => 'submitted',
				'to_status'   => 'under_review',
			)
		);
		$service = $this->service(
			static function (): int {
				return 1;
			}
		);

		$result = $service->deliver( BookingNotificationService::TYPE_INFORMATION_RECEIVED, $source['id'] );

		$this->assertSame( 'booking_notification_source_invalid', $result->get_error_code() );
		$this->assertArrayNotHasKey( BookingSchema::memberships_table(), $GLOBALS['wpdb']->rows );
	}

	/** The landed activity contracts emit exactly the five current types. */
	public function test_landed_source_contracts_emit_exact_notification_types(): void {
		$booking = $this->booking();
		$this->member( 1, 55, 10, VenueAuthorization::STATUS_ACTIVE, true );
		$events = array(
			array( BookingNotificationService::TYPE_INQUIRY_SUBMITTED, 'inquiry_submitted', array( 'status' => 'submitted' ) ),
			array( BookingNotificationService::TYPE_ASSIGNMENT_CHANGED, 'assignment_changed', array( 'to_assignee_user_id' => 10 ) ),
			array( BookingNotificationService::TYPE_INFORMATION_RECEIVED, 'status_changed', array( 'from_status' => 'needs_info', 'to_status' => 'submitted' ) ),
			array( BookingNotificationService::TYPE_HOLD_EXPIRED, 'hold_expired', array( 'hold_id' => 20 ) ),
			array( BookingNotificationService::TYPE_EVENT_HANDOFF_FAILED, 'event_conversion_failed', array( 'attempt' => 1 ) ),
		);
		$types   = array();
		$service = $this->service(
			static function ( array $recipients, array $payload ) use ( &$types ): int {
				$types[] = $payload['type'];
				return count( $recipients );
			}
		);

		foreach ( $events as $event ) {
			$source = $this->activity( $booking, $event[1], $event[2] );
			$result = $service->deliver( $event[0], $source['id'] );
			$this->assertSame( 'delivered', $result['payload']['data']['status'] );
		}

		$this->assertSame( array_column( $events, 0 ), $types );
	}
}
