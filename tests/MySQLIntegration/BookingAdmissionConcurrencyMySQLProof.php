<?php
/** Genuine two-process booking-admission MySQL integration proof. */

require_once __DIR__ . '/BookingAttachmentMySQLIntegrationTest.php';

use ExtraChillEvents\Core\BookingActivityRepository;
use ExtraChillEvents\Core\BookingAttachmentRepository;
use ExtraChillEvents\Core\BookingInquiryAdmissionService;
use ExtraChillEvents\Core\BookingLifecycle;
use ExtraChillEvents\Core\BookingRepository;
use ExtraChillEvents\Core\BookingSchema;
use ExtraChillEvents\Core\VenueBookingConfig;

/** Prove overlapping application processes converge on one complete winner. */
final class BookingAdmissionConcurrencyMySQLProof extends BookingAttachmentMySQLIntegrationTest {
	/** Prove the loser replays the completed exact winner without duplicate effects. */
	public function test_concurrent_exact_inquiry_retry_reuses_one_complete_winner(): void {
		global $wpdb;
		$this->assertTrue( function_exists( 'pcntl_fork' ), 'The MySQL concurrency proof requires pcntl_fork().' );
		$config_service    = new VenueBookingConfig();
		$config            = $config_service->get( $this->venue_id );
		$config['enabled'] = true;
		$config            = $config_service->update( $this->venue_id, $config, 0, $this->actor_id );
		$this->assertIsArray( $config, is_wp_error( $config ) ? $config->get_error_code() : 'booking config was not committed' );
		add_filter( 'extrachill_events_allow_test_booking_file', '__return_true' );
		$path        = wp_tempnam( 'booking-inquiry.txt' );
		$event_log   = wp_tempnam( 'booking-inquiry-events.txt' );
		$staging     = $path . '.staging';
		$contending  = $path . '.contending';
		$result_file = $path . '.result';
		file_put_contents( $path, 'concurrent inquiry' );
		$this->provider->event_log = $event_log;
		$input = array(
			'idempotency_key' => 'mysql-concurrent-inquiry',
			'venue_term_id'   => $this->venue_id,
			'artist_name'     => 'Concurrent Artist',
			'intake'          => array(),
			'attachments'     => array(
				array(
					'name'     => 'booking-inquiry.txt',
					'tmp_name' => $path,
					'error'    => UPLOAD_ERR_OK,
					'size'     => filesize( $path ),
					'purpose'  => 'press_release',
				),
			),
		);
		$this->provider->stage_probe = function () use ( $staging, $contending ): void {
			file_put_contents( $staging, 'ready', LOCK_EX );
			$deadline = microtime( true ) + 5;
			while ( ! file_exists( $contending ) && microtime( true ) < $deadline ) {
				usleep( 10000 );
			}
			if ( ! file_exists( $contending ) ) {
				throw new RuntimeException( 'The contender did not reach the staging barrier.' );
			}
			usleep( 250000 );
		};
		$bookings    = new BookingRepository();
		$attachments = new BookingAttachmentRepository();
		$service     = new BookingInquiryAdmissionService( new BookingLifecycle( $bookings ), $attachments, null, $this->provider, null, $bookings, new BookingActivityRepository() );

		$pid = pcntl_fork();
		$this->assertGreaterThanOrEqual( 0, $pid, 'The contender process could not be created.' );
		if ( 0 === $pid ) {
			$this->reconnect_wordpress_database();
			$deadline = microtime( true ) + 10;
			while ( ! file_exists( $staging ) && microtime( true ) < $deadline ) {
				usleep( 10000 );
			}
			file_put_contents( $contending, 'ready', LOCK_EX );
			$provider            = new BookingAttachmentMySQLProbeProvider();
			$provider->event_log = $event_log;
			$child_bookings      = new BookingRepository();
			$child_service       = new BookingInquiryAdmissionService( new BookingLifecycle( $child_bookings ), new BookingAttachmentRepository(), null, $provider, null, $child_bookings, new BookingActivityRepository() );
			$result              = $child_service->admit( $input );
			file_put_contents(
				$result_file,
				wp_json_encode( is_wp_error( $result ) ? array( 'error' => $result->get_error_code(), 'data' => $result->get_error_data() ) : array( 'receipt' => $result ) ),
				LOCK_EX
			);
			exit( 0 );
		}

		$winner = $service->admit( $input );
		$status = 0;
		pcntl_waitpid( $pid, $status );
		$child = json_decode( (string) file_get_contents( $result_file ), true );
		$retry = $child['receipt'] ?? new WP_Error( (string) ( $child['error'] ?? 'missing_contender_receipt' ), '', $child['data'] ?? array() );

		$this->assertIsArray( $winner, is_wp_error( $winner ) ? $winner->get_error_code() : 'winner was not a receipt' );
		$this->assertIsArray( $retry, is_wp_error( $retry ) ? $retry->get_error_code() : 'retry was not a receipt' );
		$this->assertTrue( pcntl_wifexited( $status ) && 0 === pcntl_wexitstatus( $status ), 'The contender process did not exit cleanly.' );
		$this->assertSame( $winner, $retry );
		$this->assertSame( 1, $this->provider->stage_count );
		$provider_events = file( $event_log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES );
		$this->assertSame( array( 'stage' ), $provider_events, 'The overlapping loser staged or retired winner bytes.' );
		$this->assertSame( array(), $this->provider->retired );
		$bookings_table    = BookingSchema::bookings_table();
		$attachments_table = BookingSchema::attachments_table();
		$activity_table    = BookingSchema::activity_table();
		$this->assertSame( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$bookings_table} WHERE inquiry_idempotency_key = %s", $input['idempotency_key'] ) ) );
		$this->assertSame( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$attachments_table} a INNER JOIN {$bookings_table} b ON b.id = a.booking_id WHERE b.inquiry_idempotency_key = %s", $input['idempotency_key'] ) ) );
		$this->assertSame( 1, (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$activity_table} a INNER JOIN {$bookings_table} b ON b.id = a.booking_id WHERE b.inquiry_idempotency_key = %s AND a.kind = 'inquiry_submitted'", $input['idempotency_key'] ) ) );
		unlink( $path );
		unlink( $event_log );
		unlink( $staging );
		unlink( $contending );
		unlink( $result_file );
		remove_filter( 'extrachill_events_allow_test_booking_file', '__return_true' );
	}
}
