<?php
/**
 * Private booking attachment contract tests.
 *
 * @package ExtraChillEvents\Tests
 */

use ExtraChillEvents\Abilities\BookingAttachmentAbilities;
use ExtraChillEvents\Core\BookingAttachmentPolicy;
use ExtraChillEvents\Core\BookingAttachmentRepository;
use ExtraChillEvents\Core\BookingAttachmentService;
use ExtraChillEvents\Core\BookingRepository;
use ExtraChillEvents\Core\BookingSchema;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Support/BookingTestHarness.php';

final class BookingAttachmentTest extends TestCase {

	private $provider;

	protected function setUp(): void {
		$GLOBALS['ec_artist_test']                            = array(
			'blog_id'   => 7,
			'stack'     => array(),
			'uuid'      => 0,
			'options'   => array(),
			'dbdelta'   => array(),
			'abilities' => array(),
			'actions'   => array(),
			'terms'     => array(
				7 => array(
					55 => (object) array(
						'term_id'  => 55,
						'taxonomy' => 'venue',
						'name'     => 'The Room',
					),
					56 => (object) array(
						'term_id'  => 56,
						'taxonomy' => 'venue',
						'name'     => 'Other Room',
					),
				),
			),
			'meta'      => array(),
			'posts'     => array(),
			'post_meta' => array(),
		);
		$GLOBALS['wpdb']                                      = new BookingWpdb();
		$this->provider                                       = new BookingTestPrivateFileProvider();
		$this->provider->objects['private_object_one_123456'] = $this->metadata( 'press-kit.pdf', 'application/pdf' );
		$this->provider->objects['private_object_two_123456'] = $this->metadata( 'stage-plot.pdf', 'application/pdf' );
		$this->provider->objects['private_object_three_1234'] = $this->metadata( 'rider.pdf', 'application/pdf' );
	}

	private function metadata( string $filename, string $mime, int $size = 1024 ): array {
		return array(
			'filename'     => $filename,
			'mime_type'    => $mime,
			'byte_size'    => $size,
			'content_hash' => hash( 'sha256', $filename ),
			'scan_status'  => BookingAttachmentPolicy::requires_malware_scan( $mime ) ? 'clean' : 'not_required',
		);
	}

	private function booking( int $venue = 55, array $overrides = array() ): array {
		return ( new BookingRepository() )->create(
			array_merge(
				array(
					'venue_term_id' => $venue,
					'artist_name'   => 'New Band',
					'intake'        => array(),
				),
				$overrides
			)
		);
	}

	private function input( array $booking, array $overrides = array() ): array {
		return array_merge(
			array(
				'booking_id'        => $booking['id'],
				'storage_reference' => 'private_object_one_123456',
				'idempotency_key'   => 'request-1',
				'purpose'           => 'epk',
				'uploader_type'     => 'anonymous',
			),
			$overrides
		);
	}

	private function service(): BookingAttachmentService {
		return new BookingAttachmentService( null, null, null, null, $this->provider );
	}

	public function test_anonymous_attribution_and_idempotent_retry_do_not_duplicate_rows(): void {
		$booking = $this->booking();
		$first   = $this->service()->attach( $this->input( $booking ) );
		$retry   = $this->service()->attach( $this->input( $booking ) );
		$this->assertIsArray( $retry, is_wp_error( $retry ) ? $retry->get_error_code() : '' );
		$this->assertSame( $first['id'], $retry['id'] );
		$this->assertSame( 'anonymous', $first['uploader_type'] );
		$this->assertNull( $first['uploader_user_id'] );
		$this->assertCount( 1, $GLOBALS['wpdb']->rows[ BookingSchema::attachments_table() ] );
		$this->assertCount(
			1,
			array_filter(
				$GLOBALS['wpdb']->rows[ BookingSchema::activity_table() ],
				static function ( $row ) {
					return 'attachment_added' === $row['kind'];
				}
			)
		);
		$this->assertSame( array(), $this->provider->released );
	}

	public function test_authenticated_artist_reference_can_be_reused_but_anonymous_reuse_is_denied(): void {
		$one     = $this->booking();
		$two     = $this->booking();
		$service = $this->service();
		$user    = array(
			'uploader_type'    => 'user',
			'uploader_user_id' => 12,
		);
		$this->assertIsArray( $service->attach( $this->input( $one, $user ) ) );
		$this->assertIsArray( $service->attach( $this->input( $two, array_merge( $user, array( 'idempotency_key' => 'request-2' ) ) ) ) );
		$three  = $this->booking();
		$result = $service->attach( $this->input( $three, array( 'idempotency_key' => 'request-3' ) ) );
		$this->assertSame( 'booking_attachment_reuse_forbidden', $result->get_error_code() );
	}

	public function test_policy_rejects_mime_size_filename_and_tax_documents_and_releases_claims(): void {
		$booking = $this->booking();
		$cases   = array(
			'bad_mime_object_123456789' => array( $this->metadata( 'press-kit.pdf', 'image/jpeg' ), 'invalid_booking_attachment_type' ),
			'oversize_object_123456789' => array( $this->metadata( 'press-kit.pdf', 'application/pdf', BookingAttachmentPolicy::MAX_BYTES + 1 ), 'invalid_booking_attachment_size' ),
			'unsafe_name_object_123456' => array( $this->metadata( '../press kit.pdf', 'application/pdf' ), 'invalid_booking_attachment_filename' ),
			'tax_form_object_123456789' => array( $this->metadata( 'artist-w-9.pdf', 'application/pdf' ), 'booking_tax_document_denied' ),
		);
		foreach ( $cases as $reference => $case ) {
			$this->provider->objects[ $reference ] = $case[0];
			$result                                = $this->service()->attach(
				$this->input(
					$booking,
					array(
						'storage_reference' => $reference,
						'idempotency_key'   => $reference,
					)
				)
			);
			$this->assertSame( $case[1], $result->get_error_code(), $reference );
		}
		$this->assertCount( 4, $this->provider->released );
		$this->assertArrayNotHasKey( BookingSchema::attachments_table(), $GLOBALS['wpdb']->rows );
	}

	/** Private storage has no public-upload fallback and references cannot be paths or URLs. */
	public function test_storage_is_unavailable_by_default_and_reference_shape_rejects_paths(): void {
		$booking = $this->booking();
		$this->assertSame( 'booking_private_storage_unavailable', ( new BookingAttachmentService() )->attach( $this->input( $booking ) )->get_error_code() );
		$result = $this->service()->attach( $this->input( $booking, array( 'storage_reference' => '/uploads/private/file.pdf' ) ) );
		$this->assertSame( 'invalid_booking_attachment_reference', $result->get_error_code() );
		$this->assertSame( array(), $this->provider->claims );
	}

	public function test_cross_booking_access_fails_and_presenter_never_leaks_private_reference(): void {
		$one        = $this->booking();
		$two        = $this->booking( 56 );
		$attachment = $this->service()->attach( $this->input( $one ) );
		$this->assertSame( 'booking_attachment_not_found', $this->service()->download_descriptor( $two['id'], $attachment['id'] )->get_error_code() );

		$abilities = new BookingAttachmentAbilities( null, null, $this->service(), new BookingTestAuthorization( array( '12:56' => false ) ) );
		$this->assertTrue( $abilities->can_access_booking( array( 'booking_id' => $one['id'] ) ) );
		$this->assertSame( 'venue_action_forbidden', $abilities->can_access_booking( array( 'booking_id' => $two['id'] ) )->get_error_code() );
		$presented = $abilities->present( $attachment );
		$this->assertArrayNotHasKey( 'storage_reference', $presented );
		$this->assertArrayNotHasKey( 'content_hash', $presented );
	}

	public function test_replacement_and_deletion_preserve_audit_evidence_and_rollback_on_activity_failure(): void {
		$booking = $this->booking();
		$service = $this->service();
		$first   = $service->attach( $this->input( $booking ) );
		$second  = $service->replace(
			$this->input(
				$booking,
				array(
					'attachment_id'     => $first['id'],
					'storage_reference' => 'private_object_two_123456',
					'idempotency_key'   => 'replacement-1',
				)
			)
		);
		$this->assertSame( 'replaced', ( new BookingAttachmentRepository() )->get( $first['id'] )['state'] );
		$this->assertSame( $first['id'], $second['replaces_attachment_id'] );
		$this->assertSame( 'deleted', $service->delete( $booking['id'], $second['id'], 12 )['state'] );

		$third = $service->attach(
			$this->input(
				$booking,
				array(
					'storage_reference' => 'private_object_three_1234',
					'idempotency_key'   => 'third',
					'uploader_type'     => 'user',
					'uploader_user_id'  => 12,
				)
			)
		);
		$this->assertIsArray( $third );
		$GLOBALS['wpdb']->fail_activity_inserts = true;
		$result                                 = $service->delete( $booking['id'], $third['id'], 12 );
		$this->assertSame( 'booking_activity_write_failed', $result->get_error_code() );
		$this->assertSame( 'active', ( new BookingAttachmentRepository() )->get( $third['id'] )['state'] );
	}

	public function test_cleanup_skips_active_reuse_and_contract_audit_hold_then_purges_orphans(): void {
		$booking = $this->booking();
		$service = $this->service();
		$file    = $service->attach( $this->input( $booking, array( 'purpose' => 'other_private_evidence' ) ) );
		$service->delete( $booking['id'], $file['id'], 12 );
		$table = BookingSchema::attachments_table();
		$GLOBALS['wpdb']->rows[ $table ][ $file['id'] ]['retired_at'] = '2020-01-01 00:00:00';
		$result = $service->cleanup( 1 );
		$this->assertSame( 1, $result['purged'] );
		$this->assertSame( array( 'private_object_one_123456' ), $this->provider->retired );

		$contract = $service->attach(
			$this->input(
				$booking,
				array(
					'storage_reference' => 'private_object_two_123456',
					'idempotency_key'   => 'contract',
					'purpose'           => 'contract',
				)
			)
		);
		$service->delete( $booking['id'], $contract['id'], 12 );
		$GLOBALS['wpdb']->rows[ $table ][ $contract['id'] ]['retired_at']                     = '2020-01-01 00:00:00';
		$GLOBALS['wpdb']->rows[ BookingSchema::bookings_table() ][ $booking['id'] ]['status'] = 'confirmed';
		$this->assertSame( 0, $service->cleanup( 1 )['purged'] );
		$this->assertSame( 'deleted', ( new BookingAttachmentRepository() )->get( $contract['id'] )['state'] );
	}
}
