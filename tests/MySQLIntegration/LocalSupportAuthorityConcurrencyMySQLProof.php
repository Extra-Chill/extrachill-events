<?php
/** Genuine two-session Local Support venue-authority MySQL proof. */

require_once __DIR__ . '/BookingAttachmentMySQLIntegrationTest.php';

use ExtraChillEvents\Core\BookingSchema;
use ExtraChillEvents\Core\LocalSupportRepository;
use ExtraChillEvents\Core\LocalSupportAuthorization;
use ExtraChillEvents\Core\LocalSupportSchema;
use ExtraChillEvents\Core\LocalSupportService;

if ( ! function_exists( 'switch_to_blog' ) ) {
	function switch_to_blog( $blog_id ) {
		unset( $blog_id );
		return true; }
	function restore_current_blog() {
		return true; }
}

/** Inject a second-session revocation immediately around production lock-current authorization. */
final class LocalSupportMySQLProbeAuthorization extends LocalSupportAuthorization {
	/** @var mysqli */
	private $contender;
	/** @var string */
	private $revoke_query;
	/** @var string */
	public $mode = '';
	/** @var bool */
	public $revocation_waited = false;

	public function __construct( mysqli $contender, string $revoke_query ) {
		parent::__construct();
		$this->contender    = $contender;
		$this->revoke_query = $revoke_query;
	}

	public function authorize_organizer_locked( array $request, int $user_id, object $scope ) {
		if ( 'before' === $this->mode ) {
			$this->contender->query( $this->revoke_query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Independent fixture connection commits before production authority locking.
		}
		$allowed = parent::authorize_organizer_locked( $request, $user_id, $scope );
		if ( 'after' !== $this->mode || true !== $allowed ) {
			return $allowed;
		}
		try {
			$updated                 = $this->contender->query( $this->revoke_query ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Independent fixture connection races the held production authority lock.
			$this->revocation_waited = false === $updated && 1205 === $this->contender->errno;
		} catch ( mysqli_sql_exception $exception ) {
			$this->revocation_waited = 1205 === $exception->getCode();
		}
		return $allowed;
	}
}

final class LocalSupportArtistMySQLAuthorization extends LocalSupportAuthorization {
	private $profile_id;
	public function __construct( int $profile_id ) {
		parent::__construct();
		$this->profile_id = $profile_id; }
	protected function artist_profile_id( int $artist_term_id ) {
		unset( $artist_term_id );
		return $this->profile_id; }
	public function artist_attached_to_event( int $event_id, int $artist_term_id ) {
		unset( $event_id, $artist_term_id );
		return true; }
	public function artist_attached_to_event_locked( int $event_id, int $artist_term_id, object $scope ) {
		unset( $event_id, $artist_term_id, $scope );
		return true; }
	public function authorize_artist( int $artist_term_id, int $user_id ) {
		unset( $artist_term_id, $user_id );
		return true; }
	public function authorize_artist_locked( int $artist_term_id, int $user_id, object $scope ) {
		unset( $artist_term_id, $user_id, $scope );
		return true; }
}

/** Prove both deterministic orders through an actual LocalSupportService mutation. */
final class LocalSupportAuthorityConcurrencyMySQLProof extends BookingAttachmentMySQLIntegrationTest {
	/** Artist binding writers and Local Support acquire binding before membership. */
	private function prove_local_support_artist_binding_and_membership_lock_order_serialize(): void {
		global $wpdb;
		$main_blog_id   = (int) ec_get_blog_id( 'main' );
		$artist_blog_id = (int) ec_get_blog_id( 'artist' );
		if ( ! taxonomy_exists( 'artist' ) ) {
			register_taxonomy( 'artist', 'post', array( 'public' => false ) );
		}
		if ( ! post_type_exists( 'artist_profile' ) ) {
			register_post_type( 'artist_profile', array( 'public' => false ) );
		}
		switch_to_blog( $main_blog_id );
		$term = wp_insert_term( 'Local Support Lock Artist', 'artist' );
		$this->assertNotWPError( $term );
		$artist_term_id = (int) $term['term_id'];
		restore_current_blog();
		switch_to_blog( $artist_blog_id );
		$profile_id = self::factory()->post->create(
			array(
				'post_type'   => 'artist_profile',
				'post_status' => 'publish',
				'post_title'  => 'Local Support Lock Artist',
			)
		);
		update_post_meta( $profile_id, '_artist_term_id', $artist_term_id );
		update_post_meta( $profile_id, '_artist_member_ids', array( $this->actor_id ) );
		restore_current_blog();
		switch_to_blog( $main_blog_id );
		update_term_meta( $artist_term_id, '_artist_profile_id', $profile_id );
		restore_current_blog();
		update_user_meta( $this->actor_id, '_artist_profile_ids', array( $profile_id ) );
		self::commit_transaction();
		$wpdb->query( 'SET autocommit = 1' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exposes fixture and advisory locks outside the test wrapper.

		$authorization = new LocalSupportArtistMySQLAuthorization( $profile_id );
		$scope         = $authorization->prepare_artist_transaction( $artist_term_id, $this->actor_id );
		$this->assertNotWPError( $scope );
		$this->assertSame( '0', (string) $this->contender->query( "SELECT GET_LOCK('ec_artist_binding_v1', 1)" )->fetch_row()[0], 'Artist binding writer did not wait behind Local Support.' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Exact fixed test lock.
		$this->assertTrue( $authorization->close_pretransaction_scope( $scope ) );

		$result = $this->contender->query( "SELECT GET_LOCK('ec_artist_binding_v1', 1)" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Simulates canonical Artist binding writer.
		$this->assertSame( '1', (string) $result->fetch_row()[0] );
		$blocked = $authorization->prepare_artist_transaction( $artist_term_id, $this->actor_id );
		$this->assertWPError( $blocked );
		$this->assertSame( 'local_support_artist_binding_lock_failed', $blocked->get_error_code() );
		$this->contender->query( "SELECT RELEASE_LOCK('ec_artist_binding_v1')" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Releases simulated writer.
		$scope = $authorization->prepare_artist_transaction( $artist_term_id, $this->actor_id );
		$this->assertIsObject( $scope );
		$this->assertTrue( $authorization->close_pretransaction_scope( $scope ) );

		$event_id = self::factory()->post->create(
			array(
				'post_type'   => 'data_machine_events',
				'post_status' => 'publish',
				'post_title'  => 'Artist Local Support Lock Proof',
			)
		);
		wp_set_object_terms( $event_id, array( $this->venue_id ), 'venue', false );
		wp_set_object_terms( $event_id, array( $artist_term_id ), 'artist', false );
		update_term_meta( $artist_term_id, '_extrachill_events_artist_term_id', $artist_term_id );
		$now = gmdate( 'Y-m-d H:i:s' );
		$wpdb->insert(
			BookingSchema::bookings_table(),
			array(
				'public_id'         => wp_generate_uuid4(),
				'venue_term_id'     => $this->venue_id,
				'artist_term_id'    => $artist_term_id,
				'artist_profile_id' => $profile_id,
				'artist_name'       => 'Local Support Lock Artist',
				'submitter_user_id' => $this->actor_id,
				'status'            => 'confirmed',
				'version'           => 1,
				'intake_payload'    => wp_json_encode(
					array(
						'version' => 1,
						'data'    => array(),
					)
				),
				'event_id'          => $event_id,
				'created_at'        => $now,
				'updated_at'        => $now,
			)
		); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Exact disposable confirmed booking fixture.
		$booking_id = (int) $wpdb->insert_id;
		$this->assertGreaterThan( 0, $booking_id );
		$service = new LocalSupportService( new LocalSupportRepository(), $authorization );
		$request = $service->open_request(
			array(
				'event_id'              => $event_id,
				'booking_id'            => $booking_id,
				'organizer_type'        => 'artist',
				'organizer_id'          => $artist_term_id,
				'acting_organizer_type' => 'artist',
				'acting_organizer_id'   => $artist_term_id,
				'idempotency_key'       => 'mysql-artist-local-support-open',
			),
			$this->actor_id
		);
		$this->assertIsArray( $request, is_wp_error( $request ) ? $request->get_error_code() : '' );
		$this->assertSame( 'artist', $request['organizer_type'] );
		$activity = ( new LocalSupportRepository() )->find_activity( $request['id'], 'mysql-artist-local-support-open' );
		$this->assertIsArray( $activity );
		$this->assertStringContainsString( '"type":"artist"', (string) $activity['payload'] );
	}

	/** Remove disposable Local Support state before the parent drops booking tables. */
	public function tear_down(): void {
		global $wpdb;
		foreach ( array( LocalSupportSchema::activity_table(), LocalSupportSchema::interests_table(), LocalSupportSchema::requests_table() ) as $table ) {
			$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Disposable test database cleanup.
		}
		delete_option( LocalSupportSchema::VERSION_OPTION );
		parent::tear_down();
	}

	/** Revocation before lock denies; authorization before revocation makes revocation wait. */
	public function test_local_support_venue_authority_and_revocation_serialize(): void {
		global $wpdb;
		$this->assertTrue( LocalSupportSchema::install() );
		$event_id = self::factory()->post->create(
			array(
				'post_type'   => 'data_machine_events',
				'post_status' => 'publish',
			)
		);
		wp_set_object_terms( $event_id, array( $this->venue_id ), 'venue', false );

		// This proof exercises a production service-owned transaction rather than
		// the wrapper transaction WP_UnitTestCase starts for ordinary tests.
		self::commit_transaction();
		$this->assertNotFalse( $wpdb->query( 'SET autocommit = 1' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Ends the test harness transaction boundary before the production transaction starts.

		$table         = BookingSchema::memberships_table();
		$revoke        = "UPDATE {$table} SET status = 'revoked' WHERE venue_term_id = {$this->venue_id} AND user_id = {$this->actor_id}";
		$activate      = "UPDATE {$table} SET status = 'active', revoked_at = NULL WHERE venue_term_id = {$this->venue_id} AND user_id = {$this->actor_id}";
		$authorization = new LocalSupportMySQLProbeAuthorization( $this->contender, $revoke );
		$service       = new LocalSupportService( null, $authorization );
		$request       = $service->open_request(
			array(
				'event_id'        => $event_id,
				'organizer_type'  => 'venue',
				'organizer_id'    => $this->venue_id,
				'idempotency_key' => 'mysql-local-support-open',
			),
			$this->actor_id
		);
		$this->assertIsArray( $request, is_wp_error( $request ) ? $request->get_error_code() : '' );

		$authorization->mode = 'after';
		$paused              = $service->transition_request( $request['id'], 'paused', 1, 'mysql-local-support-pause', $this->actor_id );
		$this->assertIsArray( $paused, is_wp_error( $paused ) ? $paused->get_error_code() : '' );
		$this->assertTrue( $authorization->revocation_waited, 'Venue revocation did not wait for the Local Support mutation transaction.' );
		$this->assertTrue( $this->contender->query( $revoke ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Revocation completes after mutation commit.

		$this->assertTrue( $this->contender->query( $activate ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- Restores authority for the opposite ordering.
		$authorization->mode = 'before';
		$denied              = $service->transition_request( $request['id'], 'open', 2, 'mysql-local-support-resume', $this->actor_id );
		$this->assertWPError( $denied );
		$this->assertSame( 'venue_action_forbidden', $denied->get_error_code() );
		$this->assertSame( 2, ( new ExtraChillEvents\Core\LocalSupportRepository() )->get_request( $request['id'] )['version'] );
		$this->prove_local_support_artist_binding_and_membership_lock_order_serialize();
	}
}
