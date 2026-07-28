<?php
/**
 * Complete show-settlement tests.
 *
 * @package ExtraChillEvents\Tests
 */

use ExtraChillEvents\Abilities\ShowSettlementAbilities;
use ExtraChillEvents\Core\BookingActivityRepository;
use ExtraChillEvents\Core\BookingAttachmentRepository;
use ExtraChillEvents\Core\BookingAttachmentService;
use ExtraChillEvents\Core\BookingRepository;
use ExtraChillEvents\Core\BookingSchema;
use ExtraChillEvents\Core\ShowSettlementService;
use ExtraChillEvents\Core\TicketReconciliationService;
use ExtraChillEvents\Core\TicketSettlementService;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Support/BookingTestHarness.php';

// Test fixtures intentionally share this file and replace the WordPress database global.
// phpcs:disable Squiz.Commenting,Generic.Commenting.DocComment.MissingShort,WordPress.Files.FileName,Generic.Files.OneObjectStructurePerFile.MultipleFound,WordPress.WP.GlobalVariablesOverride.Prohibited

/** Supplies authenticated in-memory bytes through the existing service contract. */
final class ShowSettlementAttachmentService extends BookingAttachmentService {
	/** @var array<int,string> */
	public $bytes = array();

	public function download_descriptor( int $booking_id, int $attachment_id, ?int $actor_id = null ) {
		unset( $booking_id, $actor_id );
		return array(
			'stream_token'   => 'stream-' . $attachment_id,
			'correlation_id' => '00000000-0000-4000-8000-000000000001',
		);
	}

	public function open_download_stream( int $booking_id, int $attachment_id, string $stream_token, int $actor_id, string $correlation_id ) {
		unset( $booking_id, $stream_token, $actor_id, $correlation_id );
		$stream = fopen( 'php://temp', 'w+b' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- In-memory test evidence stream.
		fwrite( $stream, $this->bytes[ $attachment_id ] ?? '' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- In-memory test evidence stream.
		rewind( $stream );
		return $stream;
	}

	public function record_delivery_outcome( int $booking_id, int $attachment_id, string $correlation_id, string $outcome, int $bytes_sent, int $actor_id ) {
		unset( $booking_id, $attachment_id, $correlation_id, $outcome, $bytes_sent, $actor_id );
		return true;
	}
}

final class ShowSettlementTest extends BookingTestCase {
	/** @var BookingRepository */
	private $bookings;
	/** @var BookingTestAuthorization */
	private $authorization;
	/** @var TicketSettlementService */
	private $commissions;
	/** @var ShowSettlementService */
	private $service;
	/** @var ShowSettlementAttachmentService */
	private $files;
	/** @var TicketReconciliationService */
	private $reconciliation;

	protected function setUp(): void {
		$GLOBALS['ec_artist_test'] = array(
			'blog_id'       => 7,
			'stack'         => array(),
			'uuid'          => 0,
			'options'       => array( BookingSchema::VERSION_OPTION => BookingSchema::SCHEMA_VERSION ),
			'dbdelta'       => array(),
			'abilities'     => array(),
			'actions'       => array(),
			'cache_deletes' => array(),
			'terms'         => array(
				7 => array(
					55 => (object) array(
						'term_id'  => 55,
						'taxonomy' => 'venue',
						'name'     => 'Show Room',
					),
				),
			),
			'meta'          => array(),
			'posts'         => array(
				7 => array(
					900 => (object) array(
						'ID'          => 900,
						'post_type'   => 'data_machine_events',
						'post_status' => 'publish',
					),
				),
			),
			'post_meta'     => array(),
		);
		$GLOBALS['wpdb']           = new BookingWpdb();
		$this->bookings            = new BookingRepository();
		$this->authorization       = new BookingTestAuthorization();
		$activity                  = new BookingActivityRepository();
		$this->reconciliation      = new TicketReconciliationService( $this->bookings, $activity, $this->authorization );
		$this->commissions         = new TicketSettlementService( $this->bookings, $activity, $this->authorization, null, $this->reconciliation );
		$this->files               = new ShowSettlementAttachmentService();
		$this->service             = new ShowSettlementService( $this->bookings, $activity, $this->authorization, $this->commissions, new BookingAttachmentRepository(), $this->files );
	}

	public function test_formula_separates_artist_payout_and_extra_chill_share(): void {
		$result = ShowSettlementService::calculate_amounts(
			array(
				'ticket_gross_minor'        => 100000,
				'door_gross_minor'          => 20000,
				'fees_minor'                => 5000,
				'taxes_minor'               => 3000,
				'refunds_minor'             => 2000,
				'venue_expenses_minor'      => 10000,
				'production_expenses_minor' => 5000,
				'artist_guarantee_minor'    => 30000,
				'artist_split_basis_points' => 7000,
				'adjustments'               => array( array( 'amount_minor' => -1000 ) ),
			),
			20000
		);
		$this->assertSame( 120000, $result['total_gross_minor'] );
		$this->assertSame( 25000, $result['total_deductions_minor'] );
		$this->assertSame( 51800, $result['artist_split_amount_minor'] );
		$this->assertSame( 51800, $result['artist_payout_minor'] );
		$this->assertSame( 20000, $result['extra_chill_share_minor'] );
		$this->assertSame( 22200, $result['venue_net_after_payout_minor'] );
		$this->assertSame(
			'show_settlement_amount_out_of_range',
			ShowSettlementService::calculate_amounts(
				array(
					'ticket_gross_minor'        => PHP_INT_MAX,
					'door_gross_minor'          => 1,
					'fees_minor'                => 0,
					'taxes_minor'               => 0,
					'refunds_minor'             => 0,
					'venue_expenses_minor'      => 0,
					'production_expenses_minor' => 0,
					'artist_guarantee_minor'    => 0,
					'artist_split_basis_points' => 0,
					'adjustments'               => array(),
				),
				0
			)->get_error_code()
		);
	}

	public function test_schema_adds_revision_and_action_tables_without_changing_commission_contract(): void {
		$this->assertTrue( BookingSchema::install() );
		$this->assertTrue( BookingSchema::health() );
		$revisions = $GLOBALS['wpdb']->schemas[ BookingSchema::show_settlements_table() ];
		$actions   = $GLOBALS['wpdb']->schemas[ BookingSchema::show_settlement_actions_table() ];
		$this->assertTrue( $revisions['indexes']['booking_revision']['unique'] );
		$this->assertTrue( $revisions['indexes']['booking_idempotency']['unique'] );
		$this->assertArrayHasKey( 'commission_integrity_hash', $revisions['columns'] );
		$this->assertTrue( $actions['indexes']['revision_version']['unique'] );
		$this->assertTrue( $GLOBALS['wpdb']->schemas[ BookingSchema::settlements_table() ]['indexes']['booking_id']['unique'] );
	}

	public function test_complete_lifecycle_is_immutable_idempotent_and_audited(): void {
		$booking    = $this->booking_with_commission();
		$commission = $this->commissions->get( $booking['id'], 12 );
		$input      = $this->draft_input( $booking['id'], $commission['id'], 'draft-one' );
		$draft      = $this->service->draft( $input, 12 );
		$this->assertSame( 'draft', $draft['status'] );
		$this->assertSame( $draft['id'], $this->service->draft( $input, 12 )['id'] );
		$changed                         = $input;
		$changed['venue_expenses_minor'] = 99999;
		$this->assertSame( 'show_settlement_idempotency_conflict', $this->service->draft( $changed, 12 )->get_error_code() );
		$mismatched                       = $input;
		$mismatched['idempotency_key']    = 'mismatched-ticket-gross';
		$mismatched['ticket_gross_minor'] = 99999;
		$this->assertSame( 'show_settlement_ticket_gross_conflict', $this->service->revise( array_merge( $mismatched, array( 'expected_revision_id' => $draft['id'] ) ), 12 )->get_error_code() );

		$finalized = $this->service->finalize( $this->transition( $booking['id'], $draft, 'finalize-one' ), 12 );
		$this->assertSame( 'finalized', $finalized['status'] );
		$this->assertSame( 2, $finalized['version'] );
		$ack          = $this->transition( $booking['id'], $finalized, 'ack-one' );
		$ack['note']  = 'Artist representative approved the statement.';
		$acknowledged = $this->service->acknowledge( $ack, 12 );
		$this->assertSame( 'acknowledged', $acknowledged['status'] );
		$dispute           = $this->transition( $booking['id'], $acknowledged, 'dispute-one' );
		$dispute['reason'] = 'Door count requires correction.';
		$disputed          = $this->service->dispute( $dispute, 12 );
		$this->assertSame( 'disputed', $disputed['status'] );

		$correction                               = $this->draft_input( $booking['id'], $commission['id'], 'correction-one' );
		$correction['expected_revision_id']       = $draft['id'];
		$correction['expected_version']           = $disputed['version'];
		$correction['reason']                     = 'Corrected signed door count.';
		$correction['door_gross_minor']           = 1000;
		$correction['door_report_attachment_ids'] = array( $this->attachment( $booking['id'], 'door evidence' ) );
		$corrected                                = $this->service->correct( $correction, 12 );
		$this->assertSame( 2, $corrected['revision'] );
		$this->assertSame( $draft['id'], $corrected['corrects_revision_id'] );
		$this->assertSame( 'corrected', $this->service->get( $booking['id'], 12, 1 )['status'] );
		$this->assertSame( 'draft', $corrected['status'] );
		$this->assertSame( $draft['calculation']['extra_chill_share_minor'], $corrected['calculation']['extra_chill_share_minor'] );

		$finalized_two                             = $this->service->finalize( $this->transition( $booking['id'], $corrected, 'finalize-two' ), 12 );
		$booking_row                               =& $GLOBALS['wpdb']->rows[ BookingSchema::bookings_table() ][ $booking['id'] ];
		$booking_row['status']                     = 'completed';
		$booking_row['version']                    = $booking_row['version'] + 1;
		$payout_id                                 = $this->attachment( $booking['id'], 'payout receipt' );
		$payment                                   = $this->transition( $booking['id'], $finalized_two, 'payment-one' );
		$payment['payment_reference']              = 'payout-2026-001';
		$payment['payment_date']                   = '2026-07-28';
		$payment['payout_evidence_attachment_ids'] = array( $payout_id );
		$paid                                      = $this->service->mark_paid( $payment, 12 );
		$this->assertSame( 'paid', $paid['status'] );
		$this->assertArrayNotHasKey( 'content_hash', $paid['evidence'][0] );
		$this->assertArrayNotHasKey( 'request_hash', $paid['actions'][1]['payload']['payout_evidence'][0] );
		$this->assertArrayNotHasKey( 'payment_reference', $paid['actions'][1]['payload'] );
		$this->assertTrue( $paid['actions'][1]['payload']['payment_reference_recorded'] );
		$this->assertSame( $paid['id'], $this->service->mark_paid( $payment, 12 )['id'] );
		$this->assertSame( 'show_settlement_version_conflict', $this->service->void( array_merge( $this->transition( $booking['id'], $paid, 'void-paid' ), array( 'reason' => 'Not allowed.' ) ), 12 )->get_error_code() );
		$action_table = BookingSchema::show_settlement_actions_table();
		$paid_action  = count( $GLOBALS['wpdb']->rows[ $action_table ] );
		$GLOBALS['wpdb']->rows[ $action_table ][ $paid_action ]['actor_user_id'] = 999;
		$this->assertSame( 'show_settlement_action_integrity_failed', $this->service->get( $booking['id'], 12 )->get_error_code() );
		$GLOBALS['wpdb']->rows[ $action_table ][ $paid_action ]['actor_user_id'] = 12;

		$kinds = array_column( ( new BookingActivityRepository() )->list_for_booking( $booking['id'] ), 'kind' );
		foreach ( array( 'show_settlement_drafted', 'show_settlement_finalized', 'show_settlement_acknowledged', 'show_settlement_disputed', 'show_settlement_corrected', 'show_settlement_paid' ) as $kind ) {
			$this->assertContains( $kind, $kinds );
		}
	}

	public function test_tampered_or_cross_venue_finance_data_fails_closed(): void {
		$booking    = $this->booking_with_commission();
		$commission = $this->commissions->get( $booking['id'], 12 );
		$draft      = $this->service->draft( $this->draft_input( $booking['id'], $commission['id'], 'tamper' ), 12 );
		$GLOBALS['wpdb']->rows[ BookingSchema::show_settlements_table() ][ $draft['id'] ]['calculation_payload'] = '{"version":1,"data":{"artist_payout_minor":1}}';
		$this->assertSame( 'show_settlement_integrity_failed', $this->service->get( $booking['id'], 12 )->get_error_code() );
		$this->authorization->allowed['12:55'] = false;
		$this->assertSame( 'venue_action_forbidden', $this->service->get( $booking['id'], 12 )->get_error_code() );
	}

	public function test_abilities_are_private_bounded_and_do_not_accept_account_identity(): void {
		$abilities = new ShowSettlementAbilities( $this->service, $this->bookings, $this->authorization );
		$abilities->register();
		$names      = array( 'draft', 'read', 'revise', 'finalize', 'acknowledge', 'dispute', 'correct', 'mark', 'void' );
		$registered = array_filter( array_keys( $GLOBALS['ec_artist_test']['abilities'] ), static fn( string $name ): bool => false !== strpos( $name, 'show-settlement' ) || false !== strpos( $name, 'artist-payout' ) );
		$this->assertCount( count( $names ), $registered );
		foreach ( $registered as $name ) {
			$schema = $GLOBALS['ec_artist_test']['abilities'][ $name ]['input_schema'];
			$this->assertFalse( $schema['additionalProperties'] );
			$this->assertArrayNotHasKey( 'bank_account', $schema['properties'] );
			$this->assertArrayNotHasKey( 'tax_identity', $schema['properties'] );
		}
	}

	private function booking_with_commission(): array {
		$booking = $this->bookings->create(
			array(
				'venue_term_id' => 55,
				'artist_name'   => 'Settlement Artist',
				'intake'        => array(),
			)
		);
		$booking = $this->bookings->claim_event( $booking['id'], 900, $booking['version'] );
		$source  = $this->reconciliation->register_source(
			array(
				'booking_id' => $booking['id'],
				'provider'   => 'manual',
				'source_key' => 'primary',
				'ticket_url' => 'https://tickets.example.test/show',
			),
			12
		);
		$report  = array(
			'booking_id'         => $booking['id'],
			'ticket_source_id'   => $source['id'],
			'provider'           => 'manual',
			'external_report_id' => 'show-report',
			'source_type'        => 'manual',
			'period_start'       => '2026-07-01 00:00:00',
			'period_end'         => '2026-07-01 23:59:59',
			'tickets_sold'       => 100,
			'tickets_refunded'   => 2,
			'gross_minor'        => 100000,
			'fees_minor'         => 5000,
			'tax_minor'          => 3000,
			'refunds_minor'      => 2000,
			'net_minor'          => 90000,
			'currency'           => 'USD',
			'source'             => array( 'certificate' => 'show-report' ),
		);
		$this->commissions->record_sales( $report, 12 );
		$preview = $this->commissions->calculate(
			array(
				'booking_id'       => $booking['id'],
				'basis'            => 'gross_ticket_sales',
				'basis_points'     => 2000,
				'currency'         => 'USD',
				'adjustment_minor' => 0,
			),
			12
		);
		$this->commissions->finalize(
			array(
				'booking_id'               => $booking['id'],
				'expected_booking_version' => $preview['booking_version'],
				'expected_report_ids'      => $preview['included_report_ids'],
				'expected_evidence_hash'   => $preview['evidence_hash'],
				'basis'                    => $preview['basis'],
				'basis_points'             => $preview['basis_points'],
				'currency'                 => $preview['currency'],
				'formula_version'          => $preview['formula_version'],
				'adjustment_minor'         => 0,
			),
			12
		);
		return $booking;
	}

	private function draft_input( int $booking_id, int $commission_id, string $key ): array {
		return array(
			'booking_id'                 => $booking_id,
			'commission_settlement_id'   => $commission_id,
			'currency'                   => 'USD',
			'ticket_gross_minor'         => 100000,
			'door_gross_minor'           => 0,
			'fees_minor'                 => 5000,
			'taxes_minor'                => 3000,
			'refunds_minor'              => 2000,
			'venue_expenses_minor'       => 10000,
			'production_expenses_minor'  => 5000,
			'artist_guarantee_minor'     => 30000,
			'artist_split_basis_points'  => 7000,
			'adjustments'                => array(
				array(
					'amount_minor' => -1000,
					'reason'       => 'Signed hospitality adjustment',
				),
			),
			'door_report_attachment_ids' => array(),
			'idempotency_key'            => $key,
		);
	}

	private function transition( int $booking_id, array $revision, string $key ): array {
		return array(
			'booking_id'       => $booking_id,
			'revision_id'      => $revision['id'],
			'expected_version' => $revision['version'],
			'idempotency_key'  => $key,
		);
	}

	private function attachment( int $booking_id, string $bytes ): int {
		$table                                  = BookingSchema::attachments_table();
		$id                                     = count( $GLOBALS['wpdb']->rows[ $table ] ?? array() ) + 1;
		$GLOBALS['wpdb']->rows[ $table ][ $id ] = array(
			'id'                     => $id,
			'public_id'              => sprintf( '00000000-0000-4000-8000-%012d', $id ),
			'booking_id'             => $booking_id,
			'uploader_type'          => 'user',
			'uploader_user_id'       => 12,
			'uploader_reference'     => null,
			'artist_term_id'         => null,
			'artist_profile_id'      => null,
			'purpose'                => 'other_private_evidence',
			'original_filename'      => 'evidence.txt',
			'mime_type'              => 'text/plain',
			'byte_size'              => strlen( $bytes ),
			'content_hash'           => hash( 'sha256', $bytes ),
			'storage_reference'      => 'opaque-' . $id,
			'state'                  => 'active',
			'idempotency_key'        => 'evidence-' . $id,
			'request_hash'           => hash( 'sha256', 'request-' . $id ),
			'replaces_attachment_id' => null,
			'retired_at'             => null,
			'purged_at'              => null,
			'created_at'             => '2026-07-28 00:00:00',
			'updated_at'             => '2026-07-28 00:00:00',
		);
		$this->files->bytes[ $id ]              = $bytes;
		return $id;
	}
}
