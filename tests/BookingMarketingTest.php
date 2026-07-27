<?php
/**
 * Delegated booking marketing contract tests.
 *
 * @package ExtraChillEvents\Tests
 */

use ExtraChillEvents\Abilities\VenueBookingMarketingAbilities;
use ExtraChillEvents\Core\BookingActivityRepository;
use ExtraChillEvents\Core\BookingMarketingService;
use ExtraChillEvents\Core\BookingRepository;
use ExtraChillEvents\Core\BookingSchema;
use ExtraChillEvents\Core\VenueBookingConfig;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Support/BookingTestHarness.php';

final class BookingMarketingPendingStoreFake {
	public static $rows = array();
	public static function get( string $action_id, bool $include_resolved = false ) {
		unset( $include_resolved );
		return self::$rows[ $action_id ] ?? null;
	}
	public function store( $action ): bool {
		self::$rows[ $action->get_action_id() ] = $action;
		return true;
	}
}

final class BookingMarketingDelegatedBackendFake {
	public $calls      = array();
	public $operations = array();
	public $next       = array();
	public $user_id    = 12;

	public function execute( string $verb, array $input ): array {
		$this->calls[] = array( $verb, $input, $this->user_id );
		if ( 'submit' === $verb ) {
			$key         = $input['action'] . ':' . $input['operation_id'];
			$fingerprint = hash( 'sha256', wp_json_encode( array( $input['input'], $input['timestamp'] ) ) );
			if ( isset( $this->operations[ $key ] ) && $this->operations[ $key ]['fingerprint'] !== $fingerprint ) {
				return array(
					'success'    => false,
					'error_code' => 'delegated_operation_conflict',
				);
			}
			if ( ! isset( $this->operations[ $key ] ) ) {
				$this->operations[ $key ] = array(
					'fingerprint'   => $fingerprint,
					'operation_ref' => 'dop_' . hash( 'sha256', $key ),
					'status'        => 'submitted',
					'projection'    => array(),
					'input'         => $input,
				);
			}
			$operation = &$this->operations[ $key ];
			if ( isset( $this->next['submit'] ) ) {
				$operation = array_merge( $operation, $this->next['submit'] );
				unset( $this->next['submit'] );
			}
			return array(
				'success'       => true,
				'operation_ref' => $operation['operation_ref'],
				'status'        => $operation['status'],
				'replayed'      => count( array_filter( $this->calls, static fn( array $call ): bool => 'submit' === $call[0] && $call[1]['operation_id'] === $input['operation_id'] ) ) > 1,
				'projection'    => $operation['projection'],
			);
		}

		foreach ( $this->operations as &$operation ) {
			if ( $operation['operation_ref'] !== ( $input['operation_ref'] ?? '' ) ) {
				continue;
			}
			if ( isset( $this->next[ $verb ] ) ) {
				$operation = array_merge( $operation, $this->next[ $verb ] );
				unset( $this->next[ $verb ] );
			} elseif ( 'retry' === $verb && 'failed' === $operation['status'] ) {
				$operation['status'] = 'executing';
			} elseif ( 'cancel' === $verb && 'submitted' === $operation['status'] ) {
				$operation['status'] = 'cancelled';
			} elseif ( 'cancel' === $verb ) {
				return array(
					'success'    => false,
					'error_code' => 'delegated_operation_not_cancellable',
				);
			}
			return array(
				'success'       => true,
				'operation_ref' => $operation['operation_ref'],
				'status'        => $operation['status'],
				'replayed'      => true,
				'projection'    => $operation['projection'],
			);
		}
		return array(
			'success'    => false,
			'error_code' => 'delegated_operation_not_found',
		);
	}
}

final class BookingMarketingDelegatedAbilityFake {
	private $backend;
	private $verb;
	public function __construct( BookingMarketingDelegatedBackendFake $backend, string $verb ) {
		$this->backend = $backend;
		$this->verb    = $verb;
	}
	public function execute( array $input ): array {
		return $this->backend->execute( $this->verb, $input );
	}
}

final class BookingMarketingPendingActionFake {
	private $id;
	private $input;
	private $data;
	public function __construct( $id, array $input = array() ) {
		$this->data  = is_array( $id ) ? $id : array(
			'action_id'   => $id,
			'apply_input' => $input,
			'kind'        => 'extrachill_run_booking_marketing',
			'status'      => 'pending',
			'preview'     => array(),
		);
		$this->id    = $this->data['action_id'];
		$this->input = $this->data['apply_input'];
	}
	public static function from_array( array $data ): self {
		return new self( $data );
	}
	public function get_kind(): string {
		return (string) ( $this->data['kind'] ?? 'extrachill_run_booking_marketing' ); }
	public function get_apply_input(): array {
		return $this->input; }
	public function get_action_id(): string {
		return $this->id; }
	public function get_status(): string {
		return (string) ( $this->data['status'] ?? 'pending' ); }
	public function get_preview(): array {
		return (array) ( $this->data['preview'] ?? array() ); }
}

final class BookingMarketingDecisionFake {
	public function is_rejected(): bool {
		return true; }
}

if ( ! class_exists( '\AgentsAPI\AI\Approvals\WP_Agent_Pending_Action' ) ) {
	class_alias( BookingMarketingPendingActionFake::class, '\AgentsAPI\AI\Approvals\WP_Agent_Pending_Action' );
}

final class BookingMarketingTest extends TestCase {
	private BookingMarketingDelegatedBackendFake $backend;
	private $original_wpdb;

	protected function setUp(): void {
		$this->original_wpdb       = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['ec_artist_test'] = array(
			'blog_id'          => 7,
			'stack'            => array(),
			'uuid'             => 0,
			'options'          => array(),
			'dbdelta'          => array(),
			'abilities'        => array(),
			'ability_objects'  => array(),
			'actions'          => array(),
			'fired_actions'    => array(),
			'scheduled'        => array(),
			'cache_deletes'    => array(),
			'permalinks'       => array( 7 => array( 901 => 'https://events.example/show' ) ),
			'attachment_urls'  => array( 7 => array( 301 => 'https://events.example/uploads/event.jpg' ) ),
			'attachment_mimes' => array( 7 => array( 301 => 'image/jpeg' ) ),
			'terms'            => array(
				7 => array(
					55 => (object) array(
						'term_id'  => 55,
						'taxonomy' => 'venue',
						'name'     => 'The Room',
					),
				),
			),
			'meta'             => array( 7 => array( 55 => array() ) ),
			'posts'            => array(
				7 => array(
					901 => (object) array(
						'ID'                => 901,
						'post_type'         => 'data_machine_events',
						'post_status'       => 'publish',
						'post_title'        => 'Test Band at The Room',
						'post_content'      => 'Canonical event details.',
						'post_excerpt'      => '',
						'post_modified_gmt' => '2030-01-01 00:00:00',
					),
					301 => (object) array(
						'ID'                => 301,
						'post_type'         => 'attachment',
						'post_status'       => 'inherit',
						'post_modified_gmt' => '2030-01-01 00:00:00',
					),
				),
			),
			'post_meta'        => array(),
		);
		$GLOBALS['wpdb']           = new BookingWpdb();
		$GLOBALS['wpdb']->rows[ BookingSchema::memberships_table() ][1] = array(
			'id'                 => 1,
			'venue_term_id'      => 55,
			'user_id'            => 12,
			'is_owner'           => 1,
			'status'             => 'active',
			'version'            => 1,
			'created_by_user_id' => 12,
			'created_at'         => '2026-01-01 00:00:00',
			'updated_at'         => '2026-01-01 00:00:00',
			'revoked_at'         => null,
		);
		BookingMarketingPendingStoreFake::$rows                         = array();
		$store = new BookingMarketingPendingStoreFake();
		add_filter( 'wp_agent_pending_action_store', static fn() => $store );
		$this->backend = new BookingMarketingDelegatedBackendFake();
		foreach ( array( 'submit', 'get', 'retry', 'cancel' ) as $verb ) {
			$GLOBALS['ec_artist_test']['ability_objects'][ 'datamachine/' . $verb . '-delegated-operation' ] = new BookingMarketingDelegatedAbilityFake( $this->backend, $verb );
		}
	}

	protected function tearDown(): void {
		$GLOBALS['wpdb'] = $this->original_wpdb;
	}

	private function booking(): array {
		$booking         = ( new BookingRepository() )->create(
			array(
				'venue_term_id'        => 55,
				'artist_name'          => 'Test Band',
				'intake'               => array(),
				'performance_start_at' => '2030-03-10 00:00:00',
				'performance_end_at'   => '2030-03-10 03:00:00',
			)
		);
		$row             = &$GLOBALS['wpdb']->rows[ BookingSchema::bookings_table() ][ $booking['id'] ];
		$row['status']   = 'confirmed';
		$row['event_id'] = 901;
		return ( new BookingRepository() )->get( $booking['id'] );
	}

	private function social( string $key = 'social', string $approval = 'direct', int $delay = 0 ): array {
		return array(
			'key'           => $key,
			'action'        => BookingMarketingService::SOCIAL_ACTION,
			'approval'      => $approval,
			'delay_seconds' => $delay,
			'social'        => array(
				'channels'   => array( 'instagram', 'twitter' ),
				'caption'    => 'Approved event caption',
				'media_kind' => 'image',
				'asset_refs' => array( 301 ),
			),
			'newsletter'    => null,
		);
	}

	private function newsletter( string $key = 'newsletter', string $approval = 'required', int $delay = 0 ): array {
		return array(
			'key'           => $key,
			'action'        => BookingMarketingService::NEWSLETTER_ACTION,
			'approval'      => $approval,
			'delay_seconds' => $delay,
			'social'        => null,
			'newsletter'    => array( 'policy' => 'canonical-post-draft' ),
		);
	}

	private function configure( array $channels, int $revision = 4 ): void {
		$GLOBALS['ec_artist_test']['meta'][7][55][ VenueBookingConfig::META_KEY ] = array(
			'version'            => VenueBookingConfig::VERSION,
			'revision'           => $revision,
			'enabled'            => true,
			'marketing_channels' => array_column( $channels, 'key' ),
			'marketing_triggers' => array(
				array(
					'key'      => 'announcement',
					'event'    => 'event_converted',
					'channels' => $channels,
				),
			),
		);
	}

	private function service( ?BookingTestAuthorization $authorization = null ): BookingMarketingService {
		return new BookingMarketingService( null, null, null, $authorization ?: new BookingTestAuthorization() );
	}

	public function test_submit_uses_exact_public_ability_and_owner_input_with_delay(): void {
		$booking = $this->booking();
		$this->configure( array( $this->social( 'social', 'direct', 3600 ) ) );
		$result = $this->service()->trigger( $booking['id'], 12 );

		$this->assertSame( 'submitted', $result['channels']['social']['status'] );
		$this->assertSame( 'submit', $this->backend->calls[0][0] );
		$input = $this->backend->calls[0][1];
		$this->assertSame( BookingMarketingService::SOCIAL_ACTION, $input['action'] );
		$this->assertSame( 901, $input['input']['post_id'] );
		$this->assertSame( hash( 'sha256', 'Approved event caption' ), $input['input']['content_hash'] );
		$this->assertEqualsWithDelta( 3600, $input['timestamp'] - time(), 2.0 );
		$this->assertArrayNotHasKey( 'workflow', $input );
		$this->assertArrayNotHasKey( 'task_type', $input );
	}

	public function test_required_approval_binds_hashes_and_rejects_stale_policy_content_event_and_booking(): void {
		$booking = $this->booking();
		$this->configure( array( $this->newsletter() ) );
		$pending = $this->service()->trigger( $booking['id'], 12 )['channels']['newsletter'];
		$this->assertSame( 'pending', $pending['status'] );
		$this->assertCount( 0, $this->backend->calls );
		$stored      = BookingMarketingPendingStoreFake::$rows[ $pending['approval_id'] ];
		$apply_input = $stored->get_apply_input();
		$preview     = $stored->get_preview();
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{64}$/', $apply_input['binding_hash'] );
		$this->assertArrayHasKey( 'policy_hash', $preview );
		$this->assertArrayHasKey( 'content_hash', $preview );
		$this->assertArrayHasKey( 'assets_hash', $preview );

		$GLOBALS['ec_artist_test']['posts'][7][901]->post_modified_gmt = '2030-01-02 00:00:00';
		$this->assertSame( 'booking_marketing_binding_stale', $this->service()->apply( $apply_input, 12 )->get_error_code() );
		$GLOBALS['ec_artist_test']['posts'][7][901]->post_modified_gmt = '2030-01-01 00:00:00';
		++$GLOBALS['wpdb']->rows[ BookingSchema::bookings_table() ][ $booking['id'] ]['version'];
		$this->assertSame( 'booking_marketing_binding_stale', $this->service()->apply( $apply_input, 12 )->get_error_code() );
	}

	public function test_approved_newsletter_submission_contains_only_owner_contract_fields(): void {
		$booking = $this->booking();
		$this->configure( array( $this->newsletter() ) );
		$pending = $this->service()->trigger( $booking['id'], 12 )['channels']['newsletter'];
		$input   = BookingMarketingPendingStoreFake::$rows[ $pending['approval_id'] ]->get_apply_input();
		$result  = $this->service()->apply( $input, 12 );

		$this->assertSame( 'submitted', $result['status'] );
		$request = $this->backend->calls[0][1];
		$this->assertSame( BookingMarketingService::NEWSLETTER_ACTION, $request['action'] );
		$this->assertSame(
			array(
				'source' => array(
					'site_id' => 7,
					'post_id' => 901,
				),
				'policy' => 'canonical-post-draft',
			),
			$request['input']
		);
		$this->assertArrayNotHasKey( 'recipients', $request['input'] );
		$this->assertArrayNotHasKey( 'content', $request['input'] );
	}

	public function test_duplicate_submission_is_cross_user_stable_and_changed_request_conflicts(): void {
		$booking = $this->booking();
		$this->configure( array( $this->social() ) );
		$first                  = $this->service()->trigger( $booking['id'], 12 )['channels']['social'];
		$this->backend->user_id = 13;
		$second                 = $this->service( new BookingTestAuthorization( array( '13:55' => true ) ) )->trigger( $booking['id'], 13 )['channels']['social'];
		$this->assertSame( $first['operation_ref'], $second['operation_ref'] );
		$this->assertCount( 1, $this->backend->operations );

		$changed                      = $this->social();
		$changed['social']['caption'] = 'Changed after first submission';
		$this->configure( array( $changed ), 5 );
		$conflict = $this->service()->trigger( $booking['id'], 12 )['channels']['social'];
		$this->assertSame( 'booking_marketing_operation_conflict', $conflict->get_error_code() );
	}

	public function test_partial_social_failure_and_owner_refs_are_preserved_without_diagnostics(): void {
		$booking = $this->booking();
		$this->configure( array( $this->social() ) );
		$this->backend->next['submit'] = array(
			'status'     => 'failed',
			'projection' => array(
				'classification' => 'partial',
				'effect_count'   => 1,
				'share_refs'     => array(
					array(
						'channel'          => 'instagram',
						'platform_post_id' => 'ig-123',
						'url'              => 'https://provider.invalid/private',
					),
				),
				'error_codes'    => array(
					array(
						'channel'    => 'twitter',
						'code'       => 'undelivered',
						'diagnostic' => 'token=secret',
					),
				),
				'credentials'    => 'secret',
			),
		);
		$result                        = $this->service()->trigger( $booking['id'], 12 )['channels']['social'];
		$this->assertSame( 'failed', $result['status'] );
		$this->assertTrue( $result['retryable'] );
		$this->assertSame( 'partial', $result['projection']['classification'] );
		$this->assertSame( 'ig-123', $result['projection']['share_refs'][0]['platform_post_id'] );
		$this->assertStringNotContainsString( 'secret', serialize( ( new BookingActivityRepository() )->list_for_booking( $booking['id'] ) ) );
		$this->assertStringNotContainsString( 'provider.invalid', serialize( $result ) );
	}

	public function test_no_op_and_zero_result_remain_truthful(): void {
		$booking = $this->booking();
		$this->configure( array( $this->newsletter( 'newsletter', 'direct' ) ) );
		$this->backend->next['submit'] = array(
			'status'     => 'no-op',
			'projection' => array(
				'classification' => 'no-op',
				'effect_count'   => 0,
				'error_code'     => 'newsletter_campaign_empty_source',
			),
		);
		$result                        = $this->service()->trigger( $booking['id'], 12 )['channels']['newsletter'];
		$this->assertSame( 'no-op', $result['status'] );
		$this->assertSame( 0, $result['projection']['effect_count'] );
		$this->assertSame( 'no-op', $result['projection']['classification'] );
	}

	public function test_failed_operation_is_exposed_and_owner_approved_retry_reuses_reference(): void {
		$booking = $this->booking();
		$this->configure( array( $this->social() ) );
		$this->backend->next['submit'] = array(
			'status'     => 'failed',
			'projection' => array(
				'classification' => 'failure',
				'effect_count'   => 0,
				'error_codes'    => array(
					array(
						'channel' => 'twitter',
						'code'    => 'undelivered',
					),
				),
			),
		);
		$failed                        = $this->service()->trigger( $booking['id'], 12 )['channels']['social'];
		$retry                         = $this->service()->manage( 'retry', $booking['id'], $failed['operation_ref'], 12 );
		$this->assertSame( 'executing', $retry['status'] );
		$this->assertSame( $failed['operation_ref'], $retry['operation_ref'] );
		$this->assertSame( 'retry', $this->backend->calls[1][0] );
	}

	public function test_cancellation_succeeds_only_before_execution(): void {
		$booking = $this->booking();
		$this->configure( array( $this->social() ) );
		$submitted = $this->service()->trigger( $booking['id'], 12 )['channels']['social'];
		$cancelled = $this->service()->manage( 'cancel', $booking['id'], $submitted['operation_ref'], 12 );
		$this->assertSame( 'cancelled', $cancelled['status'] );

		$this->backend->next['cancel'] = array( 'status' => 'executing' );
		$denied                        = $this->service()->manage( 'cancel', $booking['id'], $submitted['operation_ref'], 12 );
		$this->assertSame( 'executing', $denied['status'] );
	}

	public function test_delayed_operation_can_be_cancelled_after_booking_and_event_become_stale(): void {
		BookingSchema::install();
		$booking = $this->booking();
		$this->configure( array( $this->social() ) );
		$submitted = $this->service()->trigger( $booking['id'], 12 )['channels']['social'];
		$request   = $this->backend->calls[0][1];
		$GLOBALS['wpdb']->rows[ BookingSchema::bookings_table() ][ $booking['id'] ]['status'] = 'cancelled';
		$GLOBALS['ec_artist_test']['posts'][7][901]->post_status                              = 'draft';
		$cancel_context = array(
			'phase'         => 'cancel',
			'action'        => BookingMarketingService::SOCIAL_ACTION,
			'operation_ref' => $submitted['operation_ref'],
			'actor'         => array( 'user_id' => 12 ),
			'input'         => $request['input'],
		);
		$this->assertTrue( BookingMarketingService::authorize_social_operation( false, $cancel_context ) );
		$this->assertSame( 'cancelled', $this->service()->manage( 'cancel', $booking['id'], $submitted['operation_ref'], 12 )['status'] );
	}

	public function test_owner_action_rechecks_exact_actor_resource_and_frozen_binding(): void {
		BookingSchema::install();
		$booking = $this->booking();
		$this->configure( array( $this->social() ) );
		$submitted              = $this->service()->trigger( $booking['id'], 12 )['channels']['social'];
		$request                = $this->backend->calls[0][1];
		$normalized_owner_input = array_merge(
			$request['input'],
			array(
				'images'        => array( 'owner-private-normalized-data' ),
				'video_url'     => null,
				'cover_url'     => null,
				'share_to_feed' => true,
			)
		);
		$context                = array(
			'phase'         => 'effect',
			'action'        => BookingMarketingService::SOCIAL_ACTION,
			'operation_ref' => $submitted['operation_ref'],
			'actor'         => array( 'user_id' => 12 ),
			'input'         => $normalized_owner_input,
		);
		$this->assertSame( $booking['id'], ( new BookingRepository() )->get_by_event( 901 )['id'] );
		$this->assertTrue( BookingMarketingService::authorize_social_operation( false, $context ) );
		$context['actor']['user_id'] = 99;
		$this->assertSame( 'booking_marketing_owner_forbidden', BookingMarketingService::authorize_social_operation( false, $context )->get_error_code() );
		$context['actor']['user_id']                            = 12;
		$GLOBALS['ec_artist_test']['posts'][7][901]->post_title = 'Changed event';
		$this->assertSame( 'booking_marketing_binding_stale', BookingMarketingService::authorize_social_operation( false, $context )->get_error_code() );
	}

	public function test_owner_action_rejects_same_attachment_id_after_asset_replacement(): void {
		BookingSchema::install();
		$booking = $this->booking();
		$this->configure( array( $this->social() ) );
		$submitted = $this->service()->trigger( $booking['id'], 12 )['channels']['social'];
		$request   = $this->backend->calls[0][1];
		$context   = array(
			'phase'         => 'effect',
			'action'        => BookingMarketingService::SOCIAL_ACTION,
			'operation_ref' => $submitted['operation_ref'],
			'actor'         => array( 'user_id' => 12 ),
			'input'         => $request['input'],
		);
		$GLOBALS['ec_artist_test']['attachment_urls'][7][301] = 'https://events.example/uploads/replaced.jpg';
		$this->assertSame( 'booking_marketing_binding_stale', BookingMarketingService::authorize_social_operation( false, $context )->get_error_code() );
	}

	public function test_owner_newsletter_record_is_bounded_and_preserved(): void {
		$booking = $this->booking();
		$this->configure( array( $this->newsletter( 'newsletter', 'direct' ) ) );
		$this->backend->next['submit'] = array(
			'status'     => 'executed',
			'projection' => array(
				'classification' => 'executed',
				'effect_count'   => 1,
				'record'         => array(
					'newsletter_post_id' => 77,
					'campaign_id'        => 'campaign-42',
					'state'              => 'provider-private',
				),
			),
		);
		$result                        = $this->service()->trigger( $booking['id'], 12 )['channels']['newsletter'];
		$this->assertSame(
			array(
				'newsletter_post_id' => 77,
				'campaign_id'        => 'campaign-42',
			),
			$result['projection']['record']
		);
		$this->assertArrayNotHasKey( 'state', $result['projection']['record'] );
	}

	public function test_ability_registration_exposes_recovery_lifecycle_without_generic_names(): void {
		$booking   = $this->booking();
		$abilities = new VenueBookingMarketingAbilities( $this->service(), null, new BookingTestAuthorization() );
		$abilities->register();
		$this->assertArrayHasKey( 'extrachill/get-booking-marketing-operation', $GLOBALS['ec_artist_test']['abilities'] );
		$this->assertArrayHasKey( 'extrachill/retry-booking-marketing-operation', $GLOBALS['ec_artist_test']['abilities'] );
		$this->assertArrayHasKey( 'extrachill/cancel-booking-marketing-operation', $GLOBALS['ec_artist_test']['abilities'] );
		$this->assertTrue( $abilities->can_access( array( 'booking_id' => $booking['id'] ) ) );
		$this->assertArrayNotHasKey( 'task_type', $GLOBALS['ec_artist_test']['abilities']['extrachill/trigger-booking-marketing']['input_schema']['properties'] );
	}

	public function test_denial_records_only_bounded_approval_reference(): void {
		$booking = $this->booking();
		$this->configure( array( $this->newsletter() ) );
		$pending = $this->service()->trigger( $booking['id'], 12 )['channels']['newsletter'];
		$input   = BookingMarketingPendingStoreFake::$rows[ $pending['approval_id'] ]->get_apply_input();
		BookingMarketingService::on_pending_action_resolved( new BookingMarketingPendingActionFake( $pending['approval_id'], $input ), new BookingMarketingDecisionFake(), 'user:12' );
		$activities = ( new BookingActivityRepository() )->list_for_booking( $booking['id'] );
		$denied     = array_values( array_filter( $activities, static fn( array $activity ): bool => 'marketing_operation_denied' === $activity['kind'] ) );
		$this->assertCount( 1, $denied );
		$this->assertSame( array( 'approval_id' => $pending['approval_id'] ), $denied[0]['payload']['data'] );
	}

	public function test_config_rejects_arbitrary_actions_handlers_and_mixed_owner_policy(): void {
		$channel              = $this->social();
		$channel['task_type'] = 'arbitrary_handler';
		$config               = new VenueBookingConfig();
		$result               = $config->normalize(
			array(
				'marketing_channels' => array( 'social' ),
				'marketing_triggers' => array(
					array(
						'key'      => 'announcement',
						'event'    => 'event_converted',
						'channels' => array( $channel ),
					),
				),
			)
		);
		$this->assertSame( 'invalid_booking_marketing_trigger_channel', $result->get_error_code() );

		$channel           = $this->social();
		$channel['action'] = 'attacker/arbitrary-action';
		$result            = $config->normalize(
			array(
				'marketing_channels' => array( 'social' ),
				'marketing_triggers' => array(
					array(
						'key'      => 'announcement',
						'event'    => 'event_converted',
						'channels' => array( $channel ),
					),
				),
			)
		);
		$this->assertSame( 'invalid_booking_marketing_trigger_channel', $result->get_error_code() );

		$channel               = $this->social();
		$channel['newsletter'] = array( 'policy' => 'canonical-post-draft' );
		$result                = $config->normalize(
			array(
				'marketing_channels' => array( 'social' ),
				'marketing_triggers' => array(
					array(
						'key'      => 'announcement',
						'event'    => 'event_converted',
						'channels' => array( $channel ),
					),
				),
			)
		);
		$this->assertSame( 'invalid_booking_marketing_social', $result->get_error_code() );
	}

	public function test_existing_accepted_approval_must_match_current_frozen_binding(): void {
		$booking = $this->booking();
		$this->configure( array( $this->newsletter() ) );
		$pending               = $this->service()->trigger( $booking['id'], 12 )['channels']['newsletter'];
		$stored                = BookingMarketingPendingStoreFake::$rows[ $pending['approval_id'] ];
		$input                 = $stored->get_apply_input();
		$input['binding_hash'] = str_repeat( '0', 64 );
		BookingMarketingPendingStoreFake::$rows[ $pending['approval_id'] ] = BookingMarketingPendingActionFake::from_array(
			array(
				'action_id'   => $pending['approval_id'],
				'kind'        => 'extrachill_run_booking_marketing',
				'status'      => 'accepted',
				'apply_input' => $input,
				'preview'     => array(),
			)
		);
		$this->assertSame( 'booking_marketing_approval_conflict', $this->service()->trigger( $booking['id'], 12 )['channels']['newsletter']->get_error_code() );
		$this->assertCount( 0, $this->backend->calls );
	}

	public function test_automatic_trigger_records_and_logs_each_channel_failure(): void {
		BookingSchema::install();
		$booking = $this->booking();
		$this->configure( array( $this->social() ) );
		unset( $GLOBALS['ec_artist_test']['attachment_urls'][7][301] );
		BookingMarketingService::on_event_converted( array( 'booking_id' => $booking['id'] ), 12 );
		$activities = ( new BookingActivityRepository() )->list_for_booking( $booking['id'] );
		$this->assertContains( 'marketing_operation_trigger_failed', array_column( $activities, 'kind' ) );
		$this->assertNotEmpty( $GLOBALS['ec_artist_test']['fired_actions']['datamachine_log'] ?? array() );
	}

	public function test_config_rejects_duplicate_channel_keys_across_triggers(): void {
		$channel = $this->social();
		$result  = ( new VenueBookingConfig() )->normalize(
			array(
				'marketing_channels' => array( 'social' ),
				'marketing_triggers' => array(
					array(
						'key'      => 'first',
						'event'    => 'event_converted',
						'channels' => array( $channel ),
					),
					array(
						'key'      => 'second',
						'event'    => 'event_converted',
						'channels' => array( $channel ),
					),
				),
			)
		);
		$this->assertSame( 'invalid_booking_marketing_trigger_channel', $result->get_error_code() );
	}
}
