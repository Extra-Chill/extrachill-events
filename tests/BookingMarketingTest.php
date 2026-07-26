<?php
/**
 * Approved booking marketing orchestration tests.
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

final class BookingMarketingTaskRegistryFake {
	public static $registered = array();
	public static function isRegistered( string $type ): bool {
		return isset( self::$registered[ $type ] );
	}
	public static function getHandler( string $type ) {
		return self::$registered[ $type ] ?? null;
	}
}

final class BookingMarketingTaskFake {
	public function getWorkflow( array $params ): array {
		return array(
			'steps' => array(
				array(
					'step_type'          => 'system_task',
					'flow_step_settings' => array(
						'task_type' => 'owner_task',
						'params'    => $params,
					),
				),
			),
		);
	}
}

final class BookingMarketingAbilityFake {
	public $calls = array();
	public $callback;
	public function __construct( ?callable $callback = null ) {
		$this->callback = $callback;
	}
	public function execute( array $input ) {
		$this->calls[] = $input;
		return $this->callback ? call_user_func( $this->callback, $input, count( $this->calls ) ) : array(
			'success'  => true,
			'job_id'   => 700 + count( $this->calls ),
			'replayed' => false,
		);
	}
}

final class BookingMarketingPendingStoreFake {
	public static $rows = array();
	public static function get( string $action_id, bool $include_resolved = false ) {
		unset( $include_resolved );
		return self::$rows[ $action_id ] ?? null;
	}
}

final class BookingMarketingPendingHelperFake {
	public static function stage( array $args ): array {
		BookingMarketingPendingStoreFake::$rows[ $args['action_id'] ] = array(
			'action_id'   => $args['action_id'],
			'kind'        => $args['kind'],
			'apply_input' => $args['apply_input'],
			'status'      => 'pending',
		);
		return array(
			'staged'    => true,
			'action_id' => $args['action_id'],
		);
	}
}

final class BookingMarketingJobsFake {
	public static $jobs = array();
	public function get_job( int $job_id ) {
		return self::$jobs[ $job_id ] ?? null;
	}
}

final class BookingMarketingPendingActionFake {
	private $id;
	private $input;
	public function __construct( string $id, array $input ) {
		$this->id    = $id;
		$this->input = $input;
	}
	public function get_kind(): string {
		return 'extrachill_run_booking_marketing';
	}
	public function get_apply_input(): array {
		return $this->input;
	}
	public function get_action_id(): string {
		return $this->id;
	}
}

final class BookingMarketingDecisionFake {
	public function is_rejected(): bool {
		return true;
	}
}

if ( ! class_exists( '\DataMachine\Engine\Tasks\TaskRegistry' ) ) {
	class_alias( BookingMarketingTaskRegistryFake::class, '\DataMachine\Engine\Tasks\TaskRegistry' );
}
if ( ! class_exists( '\DataMachine\Engine\AI\Actions\PendingActionStore' ) ) {
	class_alias( BookingMarketingPendingStoreFake::class, '\DataMachine\Engine\AI\Actions\PendingActionStore' );
}
if ( ! class_exists( '\DataMachine\Engine\AI\Actions\PendingActionHelper' ) ) {
	class_alias( BookingMarketingPendingHelperFake::class, '\DataMachine\Engine\AI\Actions\PendingActionHelper' );
}
if ( ! class_exists( '\DataMachine\Core\Database\Jobs\Jobs' ) ) {
	class_alias( BookingMarketingJobsFake::class, '\DataMachine\Core\Database\Jobs\Jobs' );
}

final class BookingMarketingTest extends TestCase {
	private $workflow;

	protected function setUp(): void {
		$GLOBALS['ec_artist_test']                    = array(
			'blog_id'         => 7,
			'stack'           => array(),
			'uuid'            => 0,
			'options'         => array(),
			'dbdelta'         => array(),
			'abilities'       => array(),
			'ability_objects' => array(),
			'actions'         => array(),
			'fired_actions'   => array(),
			'scheduled'       => array(),
			'cache_deletes'   => array(),
			'permalinks'      => array( 7 => array( 901 => 'https://events.example/show' ) ),
			'terms'           => array(
				7 => array(
					55 => (object) array(
						'term_id'  => 55,
						'taxonomy' => 'venue',
						'name'     => 'The Room',
					),
				),
			),
			'meta'            => array( 7 => array( 55 => array( '_venue_city' => 'Charleston' ) ) ),
			'posts'           => array(
				7 => array(
					901 => (object) array(
						'ID'          => 901,
						'post_type'   => 'data_machine_events',
						'post_status' => 'publish',
						'post_title'  => 'Test Band at The Room',
					),
				),
			),
			'post_meta'       => array(),
		);
		$GLOBALS['wpdb']                              = new BookingWpdb();
		BookingMarketingTaskRegistryFake::$registered = array( 'owner_task' => BookingMarketingTaskFake::class );
		BookingMarketingPendingStoreFake::$rows       = array();
		BookingMarketingJobsFake::$jobs               = array();
		$this->workflow                               = new BookingMarketingAbilityFake();
		$GLOBALS['ec_artist_test']['ability_objects']['datamachine/execute-workflow'] = $this->workflow;
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
		$row             =& $GLOBALS['wpdb']->rows[ BookingSchema::bookings_table() ][ $booking['id'] ];
		$row['status']   = 'confirmed';
		$row['event_id'] = 901;
		return ( new BookingRepository() )->get( $booking['id'] );
	}

	private function configure( array $channels ): void {
		$keys = array_map( static fn( array $channel ): string => $channel['key'], $channels );
		$GLOBALS['ec_artist_test']['meta'][7][55][ VenueBookingConfig::META_KEY ] = array(
			'enabled'            => true,
			'marketing_channels' => $keys,
			'marketing_triggers' => array(
				array(
					'key'      => 'announcement',
					'event'    => 'event_converted',
					'channels' => $channels,
				),
			),
		);
	}

	private function channel( string $key, string $approval = 'direct', int $delay = 0 ): array {
		return array(
			'key'           => $key,
			'task_type'     => 'owner_task',
			'agent_id'      => 9,
			'approval'      => $approval,
			'delay_seconds' => $delay,
			'params'        => array( 'audience' => 'configured' ),
			'image'         => null,
		);
	}

	private function service( ?BookingTestAuthorization $authorization = null ): BookingMarketingService {
		return new BookingMarketingService( null, null, null, $authorization ? $authorization : new BookingTestAuthorization() );
	}

	public function test_required_approval_stays_pending_until_accepted_and_denial_is_recorded(): void {
		$booking = $this->booking();
		$this->configure( array( $this->channel( 'channel-a', 'required' ) ) );

		$pending = $this->service()->trigger( $booking['id'], 12 );
		$this->assertSame( 'pending', $pending['channels']['channel-a']['status'] );
		$this->assertCount( 0, $this->workflow->calls );

		$input    = BookingMarketingPendingStoreFake::$rows[ $pending['channels']['channel-a']['approval_id'] ]['apply_input'];
		$approved = $this->service()->apply( $input, 12 );
		$this->assertSame( 'scheduled', $approved['status'] );
		$this->assertCount( 1, $this->workflow->calls );

		BookingMarketingService::on_pending_action_resolved( new BookingMarketingPendingActionFake( $pending['channels']['channel-a']['approval_id'], $input ), new BookingMarketingDecisionFake(), 'user:12' );
		$activities = ( new BookingActivityRepository() )->list_for_booking( $booking['id'] );
		$this->assertContains( 'marketing_channel_denied', array_column( $activities, 'kind' ) );
	}

	public function test_scheduled_execution_uses_stable_operation_key_and_duplicate_retry_does_not_publish_twice(): void {
		$booking = $this->booking();
		$this->configure( array( $this->channel( 'channel-a', 'direct', 3600 ) ) );

		$first  = $this->service()->trigger( $booking['id'], 12 );
		$second = $this->service()->trigger( $booking['id'], 12 );

		$this->assertSame( 'scheduled', $first['channels']['channel-a']['status'] );
		$this->assertSame( $first['channels']['channel-a']['job_id'], $second['channels']['channel-a']['job_id'] );
		$this->assertCount( 1, $this->workflow->calls );
		$this->assertEqualsWithDelta( 3600, $this->workflow->calls[0]['timestamp'] - time(), 2.0 );
		$this->assertStringStartsWith( 'booking-marketing:', $this->workflow->calls[0]['operation_key'] );
	}

	public function test_accepted_approval_recovers_a_missing_local_job_receipt(): void {
		$booking = $this->booking();
		$this->configure( array( $this->channel( 'channel-a', 'required' ) ) );
		$pending = $this->service()->trigger( $booking['id'], 12 );
		BookingMarketingPendingStoreFake::$rows[ $pending['channels']['channel-a']['approval_id'] ]['status'] = 'accepted';

		$recovered = $this->service()->trigger( $booking['id'], 12 );

		$this->assertSame( 'scheduled', $recovered['channels']['channel-a']['status'] );
		$this->assertCount( 1, $this->workflow->calls );
	}

	public function test_partial_channel_failure_does_not_block_other_channels(): void {
		$booking        = $this->booking();
		$this->workflow = new BookingMarketingAbilityFake(
			static function ( array $input, int $call ): array {
				return 1 === $call ? array(
					'success' => false,
					'error'   => 'owner unavailable',
				) : array(
					'success' => true,
					'job_id'  => 802,
				);
			}
		);
		$GLOBALS['ec_artist_test']['ability_objects']['datamachine/execute-workflow'] = $this->workflow;
		$this->configure( array( $this->channel( 'channel-a' ), $this->channel( 'channel-b' ) ) );

		$result = $this->service()->trigger( $booking['id'], 12 );

		$this->assertSame( 'booking_marketing_schedule_failed', $result['channels']['channel-a']->get_error_code() );
		$this->assertSame( 'scheduled', $result['channels']['channel-b']['status'] );
		$this->assertCount( 2, $this->workflow->calls );
		$activities = ( new BookingActivityRepository() )->list_for_booking( $booking['id'] );
		$this->assertContains( 'marketing_channel_schedule_failed', array_column( $activities, 'kind' ) );
	}

	public function test_unauthorized_venue_and_missing_owner_dependencies_fail_closed(): void {
		$booking = $this->booking();
		$this->configure( array( $this->channel( 'channel-a' ) ) );
		$authorization = new BookingTestAuthorization( array( '12:55' => false ) );
		$this->assertSame( 'venue_action_forbidden', $this->service( $authorization )->trigger( $booking['id'], 12 )->get_error_code() );

		BookingMarketingTaskRegistryFake::$registered = array();
		$this->assertSame( 'booking_marketing_task_unavailable', $this->service()->trigger( $booking['id'], 12 )['channels']['channel-a']->get_error_code() );
	}

	public function test_terminal_partial_outcome_keeps_owner_delivery_state_authoritative(): void {
		$booking = $this->booking();
		$this->configure( array( $this->channel( 'channel-a' ) ) );
		$result                                    = $this->service()->trigger( $booking['id'], 12 );
		$job_id                                    = $result['channels']['channel-a']['job_id'];
		$initial                                   = $this->workflow->calls[0]['initial_data'];
		BookingMarketingJobsFake::$jobs[ $job_id ] = array(
			'engine_data' => array_merge(
				$initial,
				array(
					'success_count' => 1,
					'failure_count' => 1,
					'results'       => array( array( 'success' => true ), array( 'success' => false ) ),
				)
			),
		);

		BookingMarketingService::on_job_complete( $job_id, 'completed' );

		$activities = ( new BookingActivityRepository() )->list_for_booking( $booking['id'] );
		$partial    = array_values( array_filter( $activities, static fn( array $activity ): bool => 'marketing_channel_partial' === $activity['kind'] ) );
		$this->assertCount( 1, $partial );
		$this->assertSame( 'owner_authoritative', $partial[0]['payload']['data']['delivery_state'] );
		$this->assertArrayNotHasKey( 'delivered', $partial[0]['payload']['data'] );
	}

	public function test_config_rejects_unselected_channels_and_unregistered_image_references(): void {
		$config  = new VenueBookingConfig();
		$channel = $this->channel( 'channel-a' );
		$result  = $config->normalize(
			array(
				'marketing_channels' => array(),
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

		$channel['image'] = array( 'preset' => 'card' );
		$result           = $config->normalize(
			array(
				'marketing_channels' => array( 'channel-a' ),
				'marketing_triggers' => array(
					array(
						'key'      => 'announcement',
						'event'    => 'event_converted',
						'channels' => array( $channel ),
					),
				),
			)
		);
		$this->assertSame( 'invalid_booking_marketing_image', $result->get_error_code() );
	}

	public function test_ability_contract_reauthorizes_pending_replay(): void {
		$booking = $this->booking();
		$this->configure( array( $this->channel( 'channel-a', 'required' ) ) );
		$abilities = new VenueBookingMarketingAbilities( $this->service(), null, new BookingTestAuthorization() );
		$handlers  = $abilities->pending_action_handlers( array() );
		$payload   = array(
			'apply_input' => array(
				'booking_id'  => $booking['id'],
				'trigger_key' => 'announcement',
				'channel_key' => 'channel-a',
			),
		);

		$this->assertTrue( $handlers['extrachill_run_booking_marketing']['can_resolve']( $payload, 'accepted', 12 ) );
		$this->assertSame( 'venue_action_forbidden', $handlers['extrachill_run_booking_marketing']['can_resolve']( $payload, 'accepted', 99 )->get_error_code() );
	}
}
