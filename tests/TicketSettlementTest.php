<?php
/**
 * Ticket-sales evidence and booking settlement tests.
 *
 * @package ExtraChillEvents\Tests
 */

use ExtraChillEvents\Abilities\TicketSettlementAbilities;
use ExtraChillEvents\Core\BookingActivityRepository;
use ExtraChillEvents\Core\BookingRepository;
use ExtraChillEvents\Core\BookingSchema;
use ExtraChillEvents\Core\TicketSettlementService;
use ExtraChillEvents\Core\VenueAuthorization;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Support/BookingTestHarness.php';

final class TicketSettlementTest extends TestCase {
	/** @var BookingRepository */
	private $bookings;
	/** @var BookingTestAuthorization */
	private $authorization;
	/** @var TicketSettlementService */
	private $service;

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
						'name'     => 'Settlement Room',
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
		$this->service             = new TicketSettlementService( $this->bookings, new BookingActivityRepository(), $this->authorization );
	}

	public function test_schema_installs_signed_evidence_and_frozen_settlement_contracts(): void {
		$this->assertTrue( BookingSchema::install() );
		$this->assertTrue( BookingSchema::health() );
		$sales       = $GLOBALS['wpdb']->schemas[ BookingSchema::sales_reports_table() ];
		$settlements = $GLOBALS['wpdb']->schemas[ BookingSchema::settlements_table() ];
		$this->assertSame( 'bigint', $sales['columns']['gross_minor']['Type'] );
		$this->assertSame( 'bigint', $sales['columns']['tickets_sold']['Type'] );
		$this->assertTrue( $sales['indexes']['provider_external_report']['unique'] );
		$this->assertTrue( $settlements['indexes']['booking_id']['unique'] );
		$this->assertArrayHasKey( 'evidence_hash', $settlements['columns'] );
		$this->assertArrayHasKey( 'payment_reference', $settlements['columns'] );
	}

	public function test_reports_are_immutable_idempotent_and_corrected_by_signed_observations(): void {
		$booking = $this->create_event_booking();
		$input   = $this->report_input( $booking['id'], 'report-1' );
		$first   = $this->service->record_sales( $input, 12 );
		$retry   = $this->service->record_sales( $input, 12 );
		$this->assertSame( $first['id'], $retry['id'] );
		$this->assertSame( 1, count( $this->service->list_sales( $booking['id'], 12 ) ) );

		$conflict                = $input;
		$conflict['gross_minor'] = 99999;
		$this->assertSame( 'sales_report_idempotency_conflict', $this->service->record_sales( $conflict, 12 )->get_error_code() );

		$correction                       = $this->report_input( $booking['id'], 'report-1-correction' );
		$correction['corrects_report_id'] = $first['id'];
		$correction['tickets_sold']       = -1;
		$correction['tickets_refunded']   = -1;
		$correction['gross_minor']        = -1;
		$correction['fees_minor']         = -20;
		$correction['tax_minor']          = -10;
		$correction['refunds_minor']      = -1000;
		$correction['net_minor']          = -1000;
		$recorded                         = $this->service->record_sales( $correction, 12 );
		$this->assertSame( $first['id'], $recorded['corrects_report_id'] );
		$this->assertSame( -1, $recorded['gross_minor'] );
		$this->assertCount( 2, $this->service->list_sales( $booking['id'], 12 ) );
	}

	public function test_calculation_handles_refunds_corrections_currencies_and_rounding(): void {
		$booking = $this->create_event_booking();
		$this->service->record_sales( $this->report_input( $booking['id'], 'usd-base' ), 12 );
		$correction                     = $this->report_input( $booking['id'], 'usd-correction' );
		$correction['gross_minor']      = -1;
		$correction['net_minor']        = -1000;
		$correction['tickets_sold']     = -1;
		$correction['tickets_refunded'] = 1;
		$correction['refunds_minor']    = 1000;
		$this->service->record_sales( $correction, 12 );
		$eur             = $this->report_input( $booking['id'], 'eur-report' );
		$eur['currency'] = 'EUR';
		$this->service->record_sales( $eur, 12 );

		$gross = $this->service->calculate(
			array(
				'booking_id'       => $booking['id'],
				'basis'            => 'gross_ticket_sales',
				'basis_points'     => 2000,
				'currency'         => 'USD',
				'adjustment_minor' => -25,
			),
			12
		);
		$this->assertSame( 10000, $gross['basis_amount_minor'] );
		$this->assertSame( 2000, $gross['share_amount_minor'] );
		$this->assertSame( 1975, $gross['amount_due_minor'] );
		$this->assertCount( 2, $gross['included_report_ids'], 'EUR evidence must never be mixed into a USD settlement.' );

		$net = $this->service->calculate(
			array(
				'booking_id'   => $booking['id'],
				'basis'        => 'net_ticket_sales',
				'basis_points' => 2000,
				'currency'     => 'USD',
			),
			12
		);
		$this->assertSame( 7000, $net['basis_amount_minor'] );
		$this->assertSame( 1400, $net['amount_due_minor'] );
		$this->assertSame( 1, TicketSettlementService::basis_points_amount( 1, 5000 ) );
		$this->assertSame( -1, TicketSettlementService::basis_points_amount( -1, 5000 ) );
		$this->assertSame( 1, TicketSettlementService::basis_points_amount( 2, 2500 ) );
		$this->assertSame( 'settlement_amount_out_of_range', TicketSettlementService::basis_points_amount( PHP_INT_MIN, 1 )->get_error_code() );
	}

	public function test_finalization_rejects_stale_evidence_and_freezes_formula_and_rate(): void {
		$booking = $this->create_event_booking();
		$this->service->record_sales( $this->report_input( $booking['id'], 'first' ), 12 );
		$preview = $this->preview( $booking['id'] );
		$this->service->record_sales( $this->report_input( $booking['id'], 'late' ), 12 );
		$stale = $this->service->finalize( $this->finalize_input( $preview ), 12 );
		$this->assertSame( 'settlement_evidence_conflict', $stale->get_error_code() );

		$preview                        = $this->preview( $booking['id'] );
		$old_formula                    = $this->finalize_input( $preview );
		$old_formula['formula_version'] = 999;
		$this->assertSame( 'settlement_formula_version_conflict', $this->service->finalize( $old_formula, 12 )->get_error_code() );
		$settlement = $this->service->finalize( $this->finalize_input( $preview ), 12 );
		$this->assertSame( 'finalized', $settlement['status'] );
		$this->assertSame( 2000, $settlement['basis_points'] );
		$this->assertSame( TicketSettlementService::FORMULA_VERSION, $settlement['formula_version'] );
		$this->assertSame( $preview['included_report_ids'], $settlement['included_report_ids'] );
		$this->assertSame( $settlement['id'], $this->service->finalize( $this->finalize_input( $preview ), 12 )['id'] );
		$changed_rate                 = $this->finalize_input( $preview );
		$changed_rate['basis_points'] = 1500;
		$this->assertSame( 'settlement_already_finalized', $this->service->finalize( $changed_rate, 12 )->get_error_code() );

		$this->service->record_sales( $this->report_input( $booking['id'], 'after-finalization' ), 12 );
		$frozen = $this->service->get( $booking['id'], 12 );
		$this->assertSame( $settlement['included_report_ids'], $frozen['included_report_ids'] );
		$this->assertSame( $settlement['amount_due_minor'], $frozen['amount_due_minor'] );
	}

	public function test_stale_booking_and_terminal_payment_writes_are_rejected_and_audited(): void {
		$booking = $this->create_event_booking();
		$this->service->record_sales( $this->report_input( $booking['id'], 'payment' ), 12 );
		$preview = $this->preview( $booking['id'] );
		$this->bookings->update( $booking['id'], array( 'artist_name' => 'Changed Artist' ), $preview['booking_version'] );
		$this->assertSame( 'settlement_booking_version_conflict', $this->service->finalize( $this->finalize_input( $preview ), 12 )->get_error_code() );

		$preview    = $this->preview( $booking['id'] );
		$settlement = $this->service->finalize( $this->finalize_input( $preview ), 12 );
		$paid       = $this->service->mark_paid(
			array(
				'booking_id'        => $booking['id'],
				'expected_version'  => 1,
				'payment_reference' => 'ach-2026-001',
			),
			12
		);
		$this->assertSame( 'paid', $paid['status'] );
		$this->assertSame( 2, $paid['version'] );
		$this->assertSame( 12, $paid['paid_by_user_id'] );
		$this->assertSame( 'ach-2026-001', $paid['payment_reference'] );
		$this->assertNotNull( $paid['paid_at'] );
		$this->assertSame(
			'settlement_version_conflict',
			$this->service->mark_paid(
				array(
					'booking_id'        => $booking['id'],
					'expected_version'  => 1,
					'payment_reference' => 'duplicate',
				),
				12
			)->get_error_code()
		);
		$this->assertSame(
			'settlement_status_conflict',
			$this->service->void(
				array(
					'booking_id'       => $booking['id'],
					'expected_version' => 2,
					'reason'           => 'Cannot void paid settlement',
				),
				12
			)->get_error_code()
		);

		$activity = ( new BookingActivityRepository() )->list_for_booking( $booking['id'] );
		$kinds    = array_column( $activity, 'kind' );
		$this->assertContains( 'booking_settlement_finalized', $kinds );
		$this->assertContains( 'booking_settlement_paid', $kinds );
		$this->assertSame( $settlement['amount_due_minor'], $paid['amount_due_minor'] );

		$void_booking = $this->create_event_booking();
		$this->service->record_sales( $this->report_input( $void_booking['id'], 'void-evidence' ), 12 );
		$void_preview = $this->preview( $void_booking['id'] );
		$this->service->finalize( $this->finalize_input( $void_preview ), 12 );
		$voided = $this->service->void(
			array(
				'booking_id'       => $void_booking['id'],
				'expected_version' => 1,
				'reason'           => 'Certified report withdrawn.',
			),
			12
		);
		$this->assertSame( 'void', $voided['status'] );
		$this->assertSame( 12, $voided['voided_by_user_id'] );
		$this->assertSame( 'Certified report withdrawn.', $voided['void_reason'] );
		$this->assertNotNull( $voided['voided_at'] );
	}

	public function test_finance_authority_and_ability_contracts_are_explicit(): void {
		$this->assertContains( VenueAuthorization::ACTION_MANAGE_FINANCES, VenueAuthorization::actions() );
		$abilities = new TicketSettlementAbilities( $this->service, $this->bookings, $this->authorization );
		$abilities->register();
		$names = array(
			'extrachill/record-booking-ticket-sales',
			'extrachill/list-booking-ticket-sales',
			'extrachill/calculate-booking-settlement',
			'extrachill/finalize-booking-settlement',
			'extrachill/mark-booking-settlement-paid',
			'extrachill/void-booking-settlement',
		);
		foreach ( $names as $name ) {
			$this->assertArrayHasKey( $name, $GLOBALS['ec_artist_test']['abilities'] );
			$this->assertTrue( $GLOBALS['ec_artist_test']['abilities'][ $name ]['meta']['show_in_rest'] );
		}
		$this->assertFalse( $GLOBALS['ec_artist_test']['abilities']['extrachill/finalize-booking-settlement']['meta']['annotations']['idempotent'] );
		$this->assertSame( array( 'manual', 'csv_certified' ), $GLOBALS['ec_artist_test']['abilities']['extrachill/record-booking-ticket-sales']['input_schema']['properties']['source_type']['enum'] );
	}

	private function create_event_booking(): array {
		$booking = $this->bookings->create(
			array(
				'venue_term_id' => 55,
				'artist_name'   => 'Settlement Artist',
				'intake'        => array(),
			)
		);
		return $this->bookings->claim_event( $booking['id'], 900, $booking['version'] );
	}

	private function report_input( int $booking_id, string $external_id ): array {
		return array(
			'booking_id'         => $booking_id,
			'provider'           => 'manual-certified',
			'external_report_id' => $external_id,
			'source_type'        => 'manual',
			'period_start'       => '2026-07-01 00:00:00',
			'period_end'         => '2026-07-31 23:59:59',
			'tickets_sold'       => 101,
			'tickets_refunded'   => 1,
			'gross_minor'        => 10001,
			'fees_minor'         => 1000,
			'tax_minor'          => 1,
			'refunds_minor'      => 1000,
			'net_minor'          => 8000,
			'currency'           => 'USD',
			'source'             => array( 'certificate' => $external_id ),
		);
	}

	private function preview( int $booking_id ): array {
		return $this->service->calculate(
			array(
				'booking_id'       => $booking_id,
				'basis'            => 'gross_ticket_sales',
				'basis_points'     => 2000,
				'currency'         => 'USD',
				'adjustment_minor' => 0,
			),
			12
		);
	}

	private function finalize_input( array $preview ): array {
		return array(
			'booking_id'               => $preview['booking_id'],
			'expected_booking_version' => $preview['booking_version'],
			'expected_report_ids'      => $preview['included_report_ids'],
			'basis'                    => $preview['basis'],
			'basis_points'             => $preview['basis_points'],
			'currency'                 => $preview['currency'],
			'formula_version'          => $preview['formula_version'],
			'adjustment_minor'         => $preview['adjustment_minor'],
		);
	}
}
