<?php
/**
 * Local-support matching notification tests.
 *
 * @package ExtraChillEvents\Tests
 */

use ExtraChillEvents\Core\LocalSupportNotificationService;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Support/BookingTestHarness.php';

/** In-memory implementation of the #420 durable notification adapter contract. */
final class LocalSupportNotificationAdapterStub {
	public $intents    = array();
	public $attempts   = array();
	public $terminals  = array();
	public $organizers = array( 77 );
	public $workspace  = 'https://events.example/local-support/15';

	public function append_notification_intent( array $intent ) {
		if ( isset( $this->intents[ $intent['idempotency_key'] ] ) ) {
			return $this->intents[ $intent['idempotency_key'] ];
		}
		$this->intents[ $intent['idempotency_key'] ] = $intent;
		return $intent;
	}
	public function pending_notification_intents( int $limit ): array {
		return array_slice( array_values( $this->intents ), 0, $limit );
	}
	public function notification_terminal( string $key ) {
		return $this->terminals[ $key ] ?? null;
	}
	public function notification_attempt_count( string $key ): int {
		return count( $this->attempts[ $key ] ?? array() );
	}
	public function record_notification_attempt( string $key, int $attempt, string $due_at, string $error ): array {
		$this->attempts[ $key ][] = compact( 'attempt', 'due_at', 'error' );
		return array(
			'status'  => 'retrying',
			'attempt' => $attempt,
		);
	}
	public function record_notification_terminal( string $key, string $status, array $details ): array {
		$this->terminals[ $key ] = compact( 'status', 'details' );
		return $this->terminals[ $key ];
	}
	public function organizer_recipient_ids(): array {
		return $this->organizers;
	}
	public function workspace_url(): string {
		return $this->workspace;
	}
}

/** Covers matching, exclusions, authorization, privacy, replay, and dependency failures. */
final class LocalSupportNotificationTest extends TestCase {
	private $adapter;
	private $eligibility_calls;
	private $candidates;
	private $deliveries;

	protected function setUp(): void {
		$GLOBALS['ec_artist_test'] = array(
			'blog_id' => 7,
			'stack'   => array(),
			'uuid'    => 0,
		);
		$this->adapter             = new LocalSupportNotificationAdapterStub();
		$this->eligibility_calls   = array();
		$this->deliveries          = array();
		$this->candidates          = array(
			array(
				'artist_profile_id' => 501,
				'artist_term_id'    => 91,
				'manager_user_ids'  => array( 41, 42 ),
				'email'             => 'must-not-leak@example.com',
			),
			array(
				'artist_profile_id' => 502,
				'artist_term_id'    => 88,
				'manager_user_ids'  => array( 43 ),
			),
		);
	}

	private function service( ?callable $delivery = null ): LocalSupportNotificationService {
		return new LocalSupportNotificationService(
			$this->adapter,
			function ( array $input ): array {
				$this->eligibility_calls[] = $input;
				return array( 'candidates' => $this->candidates );
			},
			static function (): array {
				return array(
					'title'            => 'Touring Band at The Room',
					'location_term_id' => 12,
					'location_slug'    => 'charleston-sc',
					'venue_term_id'    => 55,
					'artist_term_ids'  => array( 88 ),
				);
			},
			$delivery ? $delivery : function ( array $recipients, array $payload ): array {
				$this->deliveries[] = compact( 'recipients', 'payload' );
				return array( 'recipients' => array( $recipients[0] => array( 'status' => 'inserted' ) ) );
			},
			static function (): int {
				return 99;
			}
		);
	}

	private function request(): array {
		return array(
			'id'            => 15,
			'event_id'      => 700,
			'status'        => 'open',
			'genre'         => 'Indie Rock',
			'contact_email' => 'private@example.com',
		);
	}

	public function test_open_request_matches_canonical_scene_excludes_attached_artist_and_is_idempotent(): void {
		$service  = $this->service();
		$activity = array(
			'id'         => 301,
			'kind'       => 'request_opened',
			'request_id' => 15,
		);
		$first    = $service->queue_request_opened( $this->request(), $activity );
		$second   = $service->queue_request_opened( $this->request(), $activity );

		$this->assertCount( 2, $first );
		$this->assertCount( 2, $second );
		$this->assertCount( 2, $this->adapter->intents );
		$this->assertSame( 12, $this->eligibility_calls[0]['location_term_id'] );
		$this->assertSame( 'charleston-sc', $this->eligibility_calls[0]['location_slug'] );
		$this->assertSame( array( 88 ), $this->eligibility_calls[0]['exclude_artist_term_ids'] );
		$this->assertSame( 'Indie Rock', $this->eligibility_calls[0]['genre'] );
		$this->assertSame( array( 41, 42 ), array_column( array_values( $this->adapter->intents ), 'recipient_id' ) );
	}

	public function test_candidate_delivery_revalidates_manager_and_discloses_no_private_fields(): void {
		$intent = $this->service()->queue_request_opened(
			$this->request(),
			array(
				'id'         => 301,
				'kind'       => 'request_opened',
				'request_id' => 15,
			)
		)[0];
		$result = $this->service()->reconcile_intent( $intent );

		$this->assertSame( 'delivered', $result['status'] );
		$this->assertCount( 2, $this->eligibility_calls );
		$payload = $this->deliveries[0]['payload'];
		$this->assertSame( 'extrachill-events-local-support', $payload['producer'] );
		$this->assertSame( 'https://events.example/local-support/15', $payload['link'] );
		$this->assertStringNotContainsString( 'private@example.com', wp_json_encode( $payload ) );
		$this->assertStringNotContainsString( 'must-not-leak@example.com', wp_json_encode( $payload ) );
	}

	public function test_interest_change_notifies_only_current_organizers_and_rechecks_on_delivery(): void {
		$service = $this->service();
		$queued  = $service->queue_interest_changed(
			$this->request(),
			array(
				'artist_term_id' => 91,
				'contact_phone'  => '555-0100',
			),
			array(
				'id'         => 401,
				'kind'       => 'interest_changed',
				'request_id' => 15,
			)
		);

		$this->assertSame( array( 77 ), array_column( $queued, 'recipient_id' ) );
		$this->adapter->organizers = array();
		$result                    = $service->reconcile_intent( $queued[0] );
		$this->assertSame( 'suppressed', $result['status'] );
		$this->assertSame( 'recipient_no_longer_authorized', $result['details']['reason'] );
		$this->assertEmpty( $this->deliveries );
	}

	public function test_failed_receipts_retry_boundedly_and_replay_terminal_result(): void {
		$service = $this->service(
			static function ( array $recipients ): array {
				return array( 'recipients' => array( $recipients[0] => array( 'status' => 'failed' ) ) );
			}
		);
		$intent  = $service->queue_request_opened(
			$this->request(),
			array(
				'id'         => 301,
				'kind'       => 'request_opened',
				'request_id' => 15,
			)
		)[0];
		for ( $attempt = 1; $attempt <= LocalSupportNotificationService::MAX_ATTEMPTS; ++$attempt ) {
			$result = $service->reconcile_intent( $intent );
		}
		$replay = $service->reconcile_intent( $intent );

		$this->assertSame( 'suppressed', $result['status'] );
		$this->assertSame( 'delivery_poisoned', $result['details']['reason'] );
		$this->assertSame( LocalSupportNotificationService::MAX_ATTEMPTS, $result['details']['attempt'] );
		$this->assertSame( $result, $replay );
	}

	public function test_missing_dependency_contracts_fail_closed_with_actionable_errors(): void {
		$missing_domain = ( new LocalSupportNotificationService(
			null,
			null,
			static function (): array {
				return array(
					'title'            => 'Show',
					'location_term_id' => 12,
					'location_slug'    => 'charleston-sc',
					'venue_term_id'    => 55,
					'artist_term_ids'  => array(),
				);
			}
		) )->queue_interest_changed(
			$this->request(),
			array( 'artist_term_id' => 91 ),
			array(
				'id'         => 401,
				'kind'       => 'interest_changed',
				'request_id' => 15,
			)
		);
		$missing_artist = ( new LocalSupportNotificationService(
			$this->adapter,
			null,
			static function (): array {
				return array(
					'title'            => 'Show',
					'location_term_id' => 12,
					'location_slug'    => 'charleston-sc',
					'venue_term_id'    => 55,
					'artist_term_ids'  => array(),
				);
			}
		) )->queue_request_opened(
			$this->request(),
			array(
				'id'         => 301,
				'kind'       => 'request_opened',
				'request_id' => 15,
			)
		);

		$this->assertSame( 'local_support_domain_contract_unavailable', $missing_domain->get_error_code() );
		$this->assertStringContainsString( 'organizer_recipient_ids', $missing_domain->get_error_message() );
		$this->assertSame( 'local_support_eligibility_unavailable', $missing_artist->get_error_code() );
	}
}
