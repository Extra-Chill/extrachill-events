<?php
/**
 * Local private booking byte-provider tests.
 *
 * @package ExtraChillEvents\Tests
 */

use ExtraChillEvents\Core\BookingAttachmentPolicy;
use ExtraChillEvents\Core\LocalBookingPrivateFileProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Support/BookingTestHarness.php';

final class BookingPrivateFileProviderTest extends TestCase {

	private $base;
	private $root;
	private $incoming;
	private $public;
	private $saved_filters;

	protected function setUp(): void {
		$this->base     = '/tmp/ec-private-provider-' . bin2hex( random_bytes( 8 ) );
		$this->root     = $this->base . '/private';
		$this->incoming = $this->base . '/incoming';
		$this->public   = $this->base . '/public';
		foreach ( array( $this->base, $this->root, $this->incoming, $this->public, $this->public . '/uploads' ) as $directory ) {
			mkdir( $directory, 0700 );
			chmod( $directory, 0700 );
		}
		$GLOBALS['ec_artist_test']['max_upload_size'] = 2 * 1024 * 1024;
		$GLOBALS['ec_artist_test']['uploads_basedir'] = $this->public . '/uploads';
		$_SERVER['DOCUMENT_ROOT']                     = $this->public;
		$this->saved_filters                          = $GLOBALS['ec_test_filters']['extrachill_events_booking_private_file_scan'] ?? array();
		$GLOBALS['ec_test_filters']['extrachill_events_booking_private_file_scan'] = array();
	}

	protected function tearDown(): void {
		$GLOBALS['ec_test_filters']['extrachill_events_booking_private_file_scan'] = $this->saved_filters;
		$this->remove_directory( $this->base );
	}

	public function test_missing_public_uploads_permissions_and_symlink_roots_fail_closed(): void {
		$this->assertSame( 'booking_private_storage_unavailable', ( new LocalBookingPrivateFileProvider() )->configuration_error()->get_error_code() );
		$this->assertSame( 'booking_private_storage_public', ( new LocalBookingPrivateFileProvider( $this->public ) )->configuration_error()->get_error_code() );
		$this->assertSame( 'booking_private_storage_public', ( new LocalBookingPrivateFileProvider( $this->public . '/uploads' ) )->configuration_error()->get_error_code() );

		$permissive = $this->base . '/permissive';
		mkdir( $permissive, 0707 );
		chmod( $permissive, 0707 );
		$this->assertSame( 'booking_private_storage_permissions', ( new LocalBookingPrivateFileProvider( $permissive ) )->configuration_error()->get_error_code() );

		$unwritable = $this->base . '/unwritable';
		mkdir( $unwritable, 0500 );
		chmod( $unwritable, 0500 );
		if ( ! is_writable( $unwritable ) ) {
			$this->assertSame( 'booking_private_storage_unsafe', ( new LocalBookingPrivateFileProvider( $unwritable ) )->configuration_error()->get_error_code() );
		}

		$link = $this->base . '/private-link';
		if ( function_exists( 'symlink' ) && symlink( $this->root, $link ) ) {
			$this->assertSame( 'booking_private_storage_unsafe', ( new LocalBookingPrivateFileProvider( $link ) )->configuration_error()->get_error_code() );
		}
	}

	public function test_provider_owned_symlink_escape_is_rejected(): void {
		if ( ! function_exists( 'symlink' ) ) {
			$this->markTestSkipped( 'Symlinks are unavailable.' );
		}
		$provider = new LocalBookingPrivateFileProvider( $this->root );
		$outside  = $this->base . '/escaped-objects';
		mkdir( $outside, 0700 );
		rmdir( $this->root . '/objects' );
		if ( ! symlink( $outside, $this->root . '/objects' ) ) {
			$this->markTestSkipped( 'Could not create a test symlink.' );
		}
		$result = $provider->stage( $this->source( 'escape.txt', 'escape' ), 'escape.txt', 'epk' );
		$this->assertSame( 'booking_private_stage_failed', $result->get_error_code() );
		$this->assertSame( array(), $this->files_in( $outside ) );
	}

	public function test_stage_generates_opaque_id_and_server_derived_metadata_with_private_permissions(): void {
		$source    = $this->source( 'press-kit.txt', 'booking press kit' );
		$provider  = new LocalBookingPrivateFileProvider( $this->root );
		$reference = $provider->stage( $source, 'press-kit.txt', 'epk' );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $reference );
		$this->assertStringNotContainsString( 'press-kit', $reference );

		$metadata = $provider->claim( $reference, 'booking:1:retry', 'epk' );
		$this->assertSame( 'press-kit.txt', $metadata['filename'] );
		$this->assertSame( 'text/plain', $metadata['mime_type'] );
		$this->assertSame( strlen( 'booking press kit' ), $metadata['byte_size'] );
		$this->assertSame( hash( 'sha256', 'booking press kit' ), $metadata['content_hash'] );
		$this->assertSame( 'not_required', $metadata['scan_status'] );

		$blobs = $this->files_ending_in( '.blob' );
		$this->assertCount( 1, $blobs );
		$this->assertSame( 0600, fileperms( $blobs[0] ) & 0777 );
		$this->assertSame( array(), $this->files_in( $this->root . '/.tmp' ) );
	}

	public function test_active_documents_require_scanner_and_mime_must_agree_with_extension(): void {
		$source   = $this->source( 'rider.pdf', "%PDF-1.4\n1 0 obj\n<<>>\nendobj\n%%EOF" );
		$provider = new LocalBookingPrivateFileProvider( $this->root );
		$this->assertSame( 'booking_private_scan_required', $provider->stage( $source, 'rider.pdf', 'technical_rider' )->get_error_code() );
		$this->assertSame( 'invalid_booking_attachment_type', $provider->stage( $source, 'rider.txt', 'technical_rider' )->get_error_code() );

		add_filter(
			'extrachill_events_booking_private_file_scan',
			static function () {
				return false;
			},
			10,
			4
		);
		$this->assertSame( 'booking_private_scan_rejected', $provider->stage( $source, 'rider.pdf', 'technical_rider' )->get_error_code() );
		$GLOBALS['ec_test_filters']['extrachill_events_booking_private_file_scan'] = array();
		add_filter(
			'extrachill_events_booking_private_file_scan',
			static function () {
				return true;
			},
			10,
			4
		);
		$reference = $provider->stage( $source, 'rider.pdf', 'technical_rider' );
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $reference );
		$this->assertSame( 'clean', $provider->claim( $reference, 'booking:1:rider', 'technical_rider' )['scan_status'] );
	}

	public function test_traversal_tax_documents_and_effective_platform_limit_are_rejected(): void {
		$source   = $this->source( 'evidence.txt', 'four' );
		$provider = new LocalBookingPrivateFileProvider( $this->root );
		$this->assertSame( 'invalid_booking_attachment_filename', $provider->stage( $source, '../evidence.txt', 'other_private_evidence' )->get_error_code() );
		$this->assertSame( 'booking_tax_document_denied', $provider->stage( $source, 'artist-w-9.txt', 'other_private_evidence' )->get_error_code() );

		$GLOBALS['ec_artist_test']['max_upload_size'] = 3;
		$result                                       = $provider->stage( $source, 'evidence.txt', 'other_private_evidence' );
		$this->assertSame( 'invalid_booking_attachment_size', $result->get_error_code() );
		$this->assertSame( 3, $result->get_error_data()['max_bytes'] );
		$this->assertSame( 3, BookingAttachmentPolicy::max_bytes() );
	}

	public function test_stream_handoff_contains_no_path_or_url_and_opens_exact_bytes(): void {
		$source    = $this->source( 'press.txt', 'private bytes' );
		$provider  = new LocalBookingPrivateFileProvider( $this->root );
		$reference = $provider->stage( $source, 'press.txt', 'press_release' );
		$provider->claim( $reference, 'booking:1:press', 'press_release' );
		$descriptor = $provider->download_descriptor( $reference );
		$this->assertArrayHasKey( 'stream_token', $descriptor );
		$this->assertArrayNotHasKey( 'path', $descriptor );
		$this->assertArrayNotHasKey( 'url', $descriptor );
		$this->assertStringNotContainsString( $this->root, wp_json_encode( $descriptor ) );

		$stream = $provider->open_stream( $descriptor['stream_token'] );
		$this->assertIsResource( $stream );
		$this->assertSame( 'private bytes', stream_get_contents( $stream ) );
		fclose( $stream );
		$this->assertSame( 'booking_private_stream_invalid', $provider->open_stream( $descriptor['stream_token'] . 'tampered' )->get_error_code() );
	}

	public function test_byte_tampering_fails_integrity_checks(): void {
		$provider  = new LocalBookingPrivateFileProvider( $this->root );
		$reference = $provider->stage( $this->source( 'evidence.txt', 'original' ), 'evidence.txt', 'other_private_evidence' );
		$blob      = $this->files_ending_in( '.blob' )[0];
		file_put_contents( $blob, 'tampered' );
		$this->assertSame( 'booking_private_object_corrupt', $provider->claim( $reference, 'booking:1:tampered', 'other_private_evidence' )->get_error_code() );
		$this->assertSame( 'booking_private_object_corrupt', $provider->download_descriptor( $reference )->get_error_code() );
	}

	public function test_claim_is_idempotent_and_exact_retirement_preserves_other_objects(): void {
		$provider = new LocalBookingPrivateFileProvider( $this->root );
		$one      = $provider->stage( $this->source( 'one.txt', 'one' ), 'one.txt', 'epk' );
		$two      = $provider->stage( $this->source( 'two.txt', 'two' ), 'two.txt', 'epk' );
		$this->assertSame( 'booking_private_purpose_mismatch', $provider->claim( $one, 'booking:1:wrong', 'contract' )->get_error_code() );
		$this->assertIsArray( $provider->claim( $one, 'booking:1:same', 'epk' ) );
		$this->assertIsArray( $provider->claim( $one, 'booking:1:same', 'epk' ) );
		$this->assertTrue( $provider->release_claim( $one, 'booking:1:same' ) );
		$this->assertTrue( $provider->retire( $one ) );
		$this->assertTrue( $provider->retire( $one ) );
		$this->assertSame( 'booking_private_object_missing', $provider->claim( $one, 'booking:1:gone', 'epk' )->get_error_code() );
		$this->assertSame( 'two.txt', $provider->claim( $two, 'booking:2:kept', 'epk' )['filename'] );
	}

	public function test_interrupted_provisional_files_are_cleaned_without_scheduling(): void {
		$provider  = new LocalBookingPrivateFileProvider( $this->root );
		$reference = str_repeat( 'a', 64 );
		$directory = $this->root . '/objects/aa/aa';
		mkdir( $directory, 0700, true );
		$orphan        = $directory . '/' . $reference . '.blob';
		$sidecar       = $directory . '/' . str_repeat( 'b', 64 ) . '.json';
		$metadata_temp = $directory . '/.metadata-interrupted';
		$temp          = $this->root . '/.tmp/stage-interrupted';
		file_put_contents( $orphan, 'orphan' );
		file_put_contents( $sidecar, '{}' );
		file_put_contents( $metadata_temp, '{}' );
		file_put_contents( $temp, 'temporary' );
		touch( $orphan, time() - 10 );
		touch( $sidecar, time() - 10 );
		touch( $metadata_temp, time() - 10 );
		touch( $temp, time() - 10 );
		$this->assertSame( 4, $provider->cleanup_provisional( 0 ) );
		$this->assertFileDoesNotExist( $orphan );
		$this->assertFileDoesNotExist( $sidecar );
		$this->assertFileDoesNotExist( $metadata_temp );
		$this->assertFileDoesNotExist( $temp );
	}

	public function test_complete_unclaimed_object_is_provisional_and_cleanup_eligible(): void {
		$provider  = new LocalBookingPrivateFileProvider( $this->root );
		$reference = $provider->stage( $this->source( 'unclaimed.txt', 'unclaimed' ), 'unclaimed.txt', 'epk' );
		foreach ( array_merge( $this->files_ending_in( '.blob' ), $this->files_ending_in( '.json' ) ) as $path ) {
			touch( $path, time() - 10 );
		}
		$this->assertSame( 2, $provider->cleanup_provisional( 0 ) );
		$this->assertSame( 'booking_private_object_missing', $provider->claim( $reference, 'booking:1:late', 'epk' )->get_error_code() );
	}

	private function source( string $filename, string $contents ): string {
		$path = $this->incoming . '/' . $filename;
		file_put_contents( $path, $contents );
		return $path;
	}

	private function files_ending_in( string $suffix ): array {
		$matches  = array();
		$iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $this->root, FilesystemIterator::SKIP_DOTS ) );
		foreach ( $iterator as $file ) {
			if ( $file->isFile() && $suffix === substr( $file->getFilename(), -strlen( $suffix ) ) ) {
				$matches[] = $file->getPathname();
			}
		}
		return $matches;
	}

	private function files_in( string $directory ): array {
		$files = glob( $directory . '/*' );
		return false === $files ? array() : $files;
	}

	private function remove_directory( string $directory ): void {
		if ( ! is_dir( $directory ) && ! is_link( $directory ) ) {
			return;
		}
		$iterator = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $directory, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::CHILD_FIRST
		);
		foreach ( $iterator as $file ) {
			$file->isDir() && ! $file->isLink() ? rmdir( $file->getPathname() ) : unlink( $file->getPathname() );
		}
		rmdir( $directory );
	}
}
