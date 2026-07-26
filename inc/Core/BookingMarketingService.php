<?php
/**
 * Approved booking marketing orchestration.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Connects canonical booking facts to owner-registered Data Machine tasks. */
class BookingMarketingService {

	private const TRIGGER      = 'event_converted';
	private const PENDING_KIND = 'extrachill_run_booking_marketing';

	/**
	 * Booking persistence.
	 *
	 * @var BookingRepository
	 */
	private $bookings;
	/**
	 * Append-only orchestration receipts.
	 *
	 * @var BookingActivityRepository
	 */
	private $activity;
	/**
	 * Venue policy.
	 *
	 * @var VenueBookingConfig
	 */
	private $config;
	/**
	 * Venue authority.
	 *
	 * @var VenueAuthorization
	 */
	private $authorization;
	/**
	 * Whether lifecycle hooks were registered.
	 *
	 * @var bool
	 */
	private static bool $registered = false;

	/**
	 * Build the orchestration service.
	 *
	 * @param BookingRepository|null         $bookings      Booking persistence.
	 * @param BookingActivityRepository|null $activity      Activity persistence.
	 * @param VenueBookingConfig|null        $config        Venue configuration.
	 * @param VenueAuthorization|null        $authorization Venue authorization.
	 */
	public function __construct( ?BookingRepository $bookings = null, ?BookingActivityRepository $activity = null, ?VenueBookingConfig $config = null, ?VenueAuthorization $authorization = null ) {
		$this->bookings      = $bookings ? $bookings : new BookingRepository();
		$this->activity      = $activity ? $activity : new BookingActivityRepository();
		$this->authorization = $authorization ? $authorization : new VenueAuthorization();
		$this->config        = $config ? $config : new VenueBookingConfig( $this->authorization );
	}

	/** Register lifecycle observers once. */
	public static function register(): void {
		if ( self::$registered ) {
			return;
		}
		add_action( 'extrachill_events_booking_event_converted', array( self::class, 'on_event_converted' ), 10, 2 );
		add_action( 'datamachine_job_complete', array( self::class, 'on_job_complete' ), 10, 2 );
		add_action( 'datamachine_pending_action_resolved', array( self::class, 'on_pending_action_resolved' ), 10, 3 );
		self::$registered = true;
	}

	/**
	 * Trigger recoverable post-conversion work without rolling back the event.
	 *
	 * @param array $conversion Canonical conversion result.
	 * @param int   $actor_id   Authorized conversion actor.
	 */
	public static function on_event_converted( array $conversion, int $actor_id ): void {
		$result = ( new self() )->trigger( (int) ( $conversion['booking_id'] ?? 0 ), $actor_id );
		if ( is_wp_error( $result ) ) {
			do_action(
				'datamachine_log',
				'error',
				'Booking marketing trigger failed',
				array(
					'booking_id' => $conversion['booking_id'] ?? 0,
					'error'      => $result->get_error_code(),
				)
			);
		}
	}

	/**
	 * Trigger all configured channels independently.
	 *
	 * @param int $booking_id Booking identifier.
	 * @param int $actor_id   Acting user identifier.
	 * @return array|\WP_Error Trigger results.
	 */
	public function trigger( int $booking_id, int $actor_id ) {
		$context = $this->context( $booking_id, $actor_id );
		if ( is_wp_error( $context ) ) {
			return $context;
		}
		if ( empty( $context['config']['enabled'] ) ) {
			return array(
				'booking_id' => $booking_id,
				'event_id'   => $context['booking']['event_id'],
				'channels'   => array(),
			);
		}
		$results = array();
		foreach ( $context['config']['marketing_triggers'] as $trigger ) {
			if ( self::TRIGGER !== $trigger['event'] ) {
				continue;
			}
			foreach ( $trigger['channels'] as $channel ) {
				$results[ $channel['key'] ] = 'required' === $channel['approval']
					? $this->stage( $context, $trigger, $channel, $actor_id )
					: $this->schedule( $context, $trigger, $channel, $actor_id, null );
			}
		}
		return array(
			'booking_id' => $booking_id,
			'event_id'   => $context['booking']['event_id'],
			'channels'   => $results,
		);
	}

	/**
	 * Execute one freshly authorized channel after approval.
	 *
	 * @param array $input    Approved replay input.
	 * @param int   $actor_id Resolving user identifier.
	 * @return array|\WP_Error Schedule receipt.
	 */
	public function apply( array $input, int $actor_id ) {
		$context = $this->context( absint( $input['booking_id'] ?? 0 ), $actor_id );
		if ( is_wp_error( $context ) ) {
			return $context;
		}
		$resolved = $this->configured_channel( $context['config'], sanitize_key( (string) ( $input['trigger_key'] ?? '' ) ), sanitize_key( (string) ( $input['channel_key'] ?? '' ) ) );
		if ( is_wp_error( $resolved ) ) {
			return $resolved;
		}
		return $this->schedule( $context, $resolved['trigger'], $resolved['channel'], $actor_id, sanitize_text_field( (string) ( $input['approval_id'] ?? '' ) ) );
	}

	/**
	 * Stage one deterministic pending action or return its existing state.
	 *
	 * @param array $context  Authoritative booking context.
	 * @param array $trigger  Normalized trigger configuration.
	 * @param array $channel  Normalized channel configuration.
	 * @param int   $actor_id Acting user identifier.
	 * @return array|\WP_Error Pending action receipt.
	 */
	private function stage( array $context, array $trigger, array $channel, int $actor_id ) {
		$base      = $this->base_key( $context['booking'], $trigger['key'], $channel['key'] );
		$scheduled = $this->activity->find_by_idempotency( $context['booking']['id'], $base . ':scheduled' );
		if ( is_wp_error( $scheduled ) || is_array( $scheduled ) ) {
			return is_wp_error( $scheduled ) ? $scheduled : $this->activity_result( 'scheduled', $scheduled );
		}
		if ( ! class_exists( '\DataMachine\Engine\AI\Actions\PendingActionHelper' ) || ! class_exists( '\DataMachine\Engine\AI\Actions\PendingActionStore' ) ) {
			return new \WP_Error(
				'booking_marketing_approval_unavailable',
				__( 'The approval substrate is unavailable.', 'extrachill-events' ),
				array(
					'status'    => 503,
					'retryable' => true,
				)
			);
		}

		$action_id = $this->action_id( $base );
		$stored    = \DataMachine\Engine\AI\Actions\PendingActionStore::get( $action_id, true );
		if ( is_array( $stored ) && in_array( (string) ( $stored['status'] ?? '' ), array( 'expired', 'deleted' ), true ) ) {
			$stored = null;
		}
		if ( ! is_array( $stored ) ) {
			$staged = \DataMachine\Engine\AI\Actions\PendingActionHelper::stage(
				array(
					'action_id'     => $action_id,
					'kind'          => self::PENDING_KIND,
					'summary'       => sprintf( 'Schedule %s marketing for event #%d.', $channel['key'], $context['booking']['event_id'] ),
					'apply_input'   => array(
						'booking_id'  => $context['booking']['id'],
						'trigger_key' => $trigger['key'],
						'channel_key' => $channel['key'],
						'approval_id' => $action_id,
					),
					'preview_data'  => array(
						'booking_id'   => $context['booking']['id'],
						'event_id'     => $context['booking']['event_id'],
						'channel'      => $channel['key'],
						'scheduled_at' => $this->scheduled_at( $context, $channel ),
					),
					'user_id'       => $actor_id,
					'authorization' => array(
						'operation' => 'run_booking_marketing',
						'target'    => array(
							'booking_id'    => $context['booking']['id'],
							'venue_term_id' => $context['booking']['venue_term_id'],
						),
					),
				)
			);
			if ( empty( $staged['staged'] ) ) {
				return new \WP_Error(
					'booking_marketing_approval_stage_failed',
					__( 'Marketing approval could not be staged.', 'extrachill-events' ),
					array(
						'status'    => 503,
						'retryable' => true,
						'upstream'  => $staged,
					)
				);
			}
			$stored = array( 'status' => 'pending' );
		}
		$status = (string) ( $stored['status'] ?? 'pending' );
		if ( in_array( $status, array( 'accepted', 'failed' ), true ) ) {
			return $this->schedule( $context, $trigger, $channel, $actor_id, $action_id );
		}

		$activity = $this->activity->append(
			array(
				'booking_id'      => $context['booking']['id'],
				'kind'            => 'marketing_channel_approval_pending',
				'actor_type'      => 'user',
				'actor_id'        => $actor_id,
				'channel'         => $channel['key'],
				'external_id'     => $action_id,
				'idempotency_key' => $base . ':approval',
				'payload'         => array(
					'trigger'     => $trigger['key'],
					'channel'     => $channel['key'],
					'approval_id' => $action_id,
				),
			)
		);
		return is_wp_error( $activity ) ? $activity : array(
			'status'      => $status,
			'approval_id' => $action_id,
			'activity_id' => $activity['id'],
		);
	}

	/**
	 * Persist a stable request and enqueue the owner task.
	 *
	 * @param array       $context     Authoritative booking context.
	 * @param array       $trigger     Normalized trigger configuration.
	 * @param array       $channel     Normalized channel configuration.
	 * @param int         $actor_id    Acting user identifier.
	 * @param string|null $approval_id Approval reference, when required.
	 * @return array|\WP_Error Schedule receipt.
	 */
	private function schedule( array $context, array $trigger, array $channel, int $actor_id, ?string $approval_id ) {
		$booking   = $context['booking'];
		$base      = $this->base_key( $booking, $trigger['key'], $channel['key'] );
		$scheduled = $this->activity->find_by_idempotency( $booking['id'], $base . ':scheduled' );
		if ( is_wp_error( $scheduled ) || is_array( $scheduled ) ) {
			return is_wp_error( $scheduled ) ? $scheduled : $this->activity_result( 'scheduled', $scheduled );
		}
		$request = $this->activity->append(
			array(
				'booking_id'      => $booking['id'],
				'kind'            => 'marketing_channel_requested',
				'actor_type'      => 'user',
				'actor_id'        => $actor_id,
				'channel'         => $channel['key'],
				'idempotency_key' => $base . ':requested',
				'payload'         => array(
					'trigger'      => $trigger['key'],
					'channel'      => $channel,
					'scheduled_at' => $this->scheduled_at( $context, $channel ),
					'approval_id'  => $approval_id,
				),
			)
		);
		if ( is_wp_error( $request ) ) {
			return $request;
		}
		$request_data = $request['payload']['data'];
		$asset        = $this->render_asset( $context, $request_data['channel']['image'], $base );
		if ( is_wp_error( $asset ) ) {
			return $this->record_schedule_failure( $booking, $channel, $base, $actor_id, $asset );
		}
		$workflow = $this->workflow( $request_data['channel']['task_type'], $this->task_params( $context, $request_data['channel']['params'], $asset ) );
		if ( is_wp_error( $workflow ) ) {
			return $this->record_schedule_failure( $booking, $channel, $base, $actor_id, $workflow );
		}
		$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( 'datamachine/execute-workflow' ) : null;
		if ( ! is_object( $ability ) || ! is_callable( array( $ability, 'execute' ) ) ) {
			$error = new \WP_Error(
				'booking_marketing_workflow_unavailable',
				__( 'The workflow execution substrate is unavailable.', 'extrachill-events' ),
				array(
					'status'    => 503,
					'retryable' => true,
				)
			);
			return $this->record_schedule_failure( $booking, $channel, $base, $actor_id, $error );
		}
		$task_context = array(
			'booking_marketing' => array(
				'booking_id'  => $booking['id'],
				'trigger_key' => $trigger['key'],
				'channel_key' => $channel['key'],
				'base_key'    => $base,
			),
		);
		$result       = $ability->execute(
			array(
				'workflow'      => $workflow,
				'timestamp'     => $request_data['scheduled_at'],
				'operation_key' => $base,
				'initial_data'  => array(
					'task_type'    => $channel['task_type'],
					'task_context' => $task_context,
					'agent_id'     => $channel['agent_id'],
					'job'          => array( 'agent_id' => $channel['agent_id'] ),
					'job_source'   => 'booking_marketing',
					'job_label'    => 'Booking marketing: ' . $channel['key'],
				),
			)
		);
		if ( is_wp_error( $result ) || empty( $result['success'] ) || empty( $result['job_id'] ) ) {
			$error = is_wp_error( $result ) ? $result : new \WP_Error(
				'booking_marketing_schedule_failed',
				__( 'The owner workflow could not be scheduled.', 'extrachill-events' ),
				array(
					'status'    => 503,
					'retryable' => true,
					'upstream'  => $result,
				)
			);
			return $this->record_schedule_failure( $booking, $channel, $base, $actor_id, $error );
		}
		$receipt = $this->activity->append(
			array(
				'booking_id'      => $booking['id'],
				'kind'            => 'marketing_channel_scheduled',
				'actor_type'      => 'user',
				'actor_id'        => $actor_id,
				'channel'         => $channel['key'],
				'external_id'     => (string) $result['job_id'],
				'idempotency_key' => $base . ':scheduled',
				'payload'         => array(
					'trigger'        => $trigger['key'],
					'channel'        => $channel['key'],
					'task_type'      => $channel['task_type'],
					'job_id'         => (int) $result['job_id'],
					'approval_id'    => $approval_id,
					'asset'          => $asset,
					'scheduled_at'   => $request_data['scheduled_at'],
					'replayed'       => ! empty( $result['replayed'] ),
					'delivery_state' => 'owner_authoritative',
				),
			)
		);
		return is_wp_error( $receipt ) ? $receipt : $this->activity_result( 'scheduled', $receipt );
	}

	/**
	 * Resolve and authorize canonical booking, event, and venue policy.
	 *
	 * @param int $booking_id Booking identifier.
	 * @param int $actor_id   Acting user identifier.
	 * @return array|\WP_Error Authorized context.
	 */
	private function context( int $booking_id, int $actor_id ) {
		$booking = $this->bookings->get( $booking_id );
		if ( ! is_array( $booking ) ) {
			return is_wp_error( $booking ) ? $booking : new \WP_Error( 'booking_not_found', __( 'The booking was not found.', 'extrachill-events' ), array( 'status' => 404 ) );
		}
		$allowed = $this->authorization->authorize( $actor_id, $booking['venue_term_id'], VenueAuthorization::ACTION_ACCESS_VENUE );
		if ( true !== $allowed ) {
			return is_wp_error( $allowed ) ? $allowed : new \WP_Error( 'venue_action_forbidden', __( 'You are not authorized to perform this venue action.', 'extrachill-events' ), array( 'status' => 403 ) );
		}
		$event = null === $booking['event_id'] ? null : get_post( $booking['event_id'] );
		$type  = defined( 'DATA_MACHINE_EVENTS_POST_TYPE' ) ? DATA_MACHINE_EVENTS_POST_TYPE : 'data_machine_events';
		if ( 'confirmed' !== $booking['status'] || ! $event || ( $event->post_type ?? null ) !== $type || 'publish' !== ( $event->post_status ?? null ) ) {
			return new \WP_Error( 'booking_marketing_event_unavailable', __( 'Marketing requires a confirmed booking linked to a published canonical event.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
		$config = $this->config->get( $booking['venue_term_id'] );
		return is_wp_error( $config ) ? $config : array(
			'booking' => $booking,
			'event'   => $event,
			'config'  => $config,
		);
	}

	/**
	 * Resolve one channel from current venue policy for fresh replay authorization.
	 *
	 * @param array  $config      Current normalized venue configuration.
	 * @param string $trigger_key Trigger key.
	 * @param string $channel_key Channel key.
	 * @return array|\WP_Error Resolved configuration.
	 */
	private function configured_channel( array $config, string $trigger_key, string $channel_key ) {
		foreach ( $config['marketing_triggers'] as $trigger ) {
			if ( $trigger_key !== $trigger['key'] || self::TRIGGER !== $trigger['event'] ) {
				continue;
			}
			foreach ( $trigger['channels'] as $channel ) {
				if ( $channel_key === $channel['key'] ) {
					return array(
						'trigger' => $trigger,
						'channel' => $channel,
					);
				}
			}
		}
		return new \WP_Error( 'booking_marketing_channel_unavailable', __( 'The configured marketing channel is no longer available.', 'extrachill-events' ), array( 'status' => 409 ) );
	}

	/**
	 * Resolve the workflow declared by an owner-registered task.
	 *
	 * @param string $task_type Registered task type.
	 * @param array  $params    Task parameters.
	 * @return array|\WP_Error Workflow specification.
	 */
	private function workflow( string $task_type, array $params ) {
		if ( ! class_exists( '\DataMachine\Engine\Tasks\TaskRegistry' ) || ! \DataMachine\Engine\Tasks\TaskRegistry::isRegistered( $task_type ) ) {
			return new \WP_Error(
				'booking_marketing_task_unavailable',
				__( 'The configured marketing task is not registered.', 'extrachill-events' ),
				array(
					'status'    => 503,
					'retryable' => true,
					'task_type' => $task_type,
				)
			);
		}
		$class = \DataMachine\Engine\Tasks\TaskRegistry::getHandler( $task_type );
		if ( ! is_string( $class ) || ! class_exists( $class ) ) {
			return new \WP_Error(
				'booking_marketing_task_unavailable',
				__( 'The configured marketing task handler is unavailable.', 'extrachill-events' ),
				array(
					'status'    => 503,
					'retryable' => true,
				)
			);
		}
		$handler  = new $class();
		$workflow = is_callable( array( $handler, 'getWorkflow' ) ) ? $handler->getWorkflow( $params ) : null;
		return is_array( $workflow ) && ! empty( $workflow['steps'] ) ? $workflow : new \WP_Error(
			'booking_marketing_workflow_invalid',
			__( 'The configured marketing task returned no workflow.', 'extrachill-events' ),
			array(
				'status'    => 503,
				'retryable' => true,
			)
		);
	}

	/**
	 * Render an optional stable public asset through a registered template.
	 *
	 * @param array      $context Authoritative booking context.
	 * @param array|null $image   Image configuration.
	 * @param string     $base    Idempotency base.
	 * @return array|null|\WP_Error Asset reference.
	 */
	private function render_asset( array $context, ?array $image, string $base ) {
		if ( null === $image ) {
			return null;
		}
		$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( 'datamachine/render-image-template' ) : null;
		if ( ! is_object( $ability ) || ! is_callable( array( $ability, 'execute' ) ) ) {
			return new \WP_Error(
				'booking_marketing_image_unavailable',
				__( 'The registered image-template substrate is unavailable.', 'extrachill-events' ),
				array(
					'status'    => 503,
					'retryable' => true,
				)
			);
		}
		$venue = get_term( $context['booking']['venue_term_id'], 'venue' );
		$input = array(
			'template_id' => $image['template_id'],
			'data'        => array(
				'event_name' => $context['event']->post_title,
				'date_label' => gmdate( 'M j, Y', strtotime( $context['booking']['performance_start_at'] . ' UTC' ) ),
				'venue'      => $venue ? $venue->name : '',
				'city'       => (string) get_term_meta( $context['booking']['venue_term_id'], '_venue_city', true ),
			),
			'format'      => $image['format'],
			'output'      => 'cached_file',
			'cache'       => array(
				'bucket' => 'booking-marketing',
				'key'    => substr( hash( 'sha256', $base . ':' . $image['template_id'] . ':' . $image['preset'] . ':' . $image['format'] ), 0, 40 ),
			),
		);
		if ( '' !== $image['preset'] ) {
			$input['preset'] = $image['preset'];
		}
		$result = $ability->execute( $input );
		if ( is_wp_error( $result ) || empty( $result['success'] ) || empty( $result['cached_urls'][0] ) ) {
			return is_wp_error( $result ) ? $result : new \WP_Error(
				'booking_marketing_image_failed',
				__( 'The registered image template produced no public asset.', 'extrachill-events' ),
				array(
					'status'    => 503,
					'retryable' => true,
					'upstream'  => $result,
				)
			);
		}
		return array(
			'template_id' => $image['template_id'],
			'url'         => $result['cached_urls'][0],
		);
	}

	/**
	 * Merge venue parameters with authoritative event references.
	 *
	 * @param array      $context    Authoritative booking context.
	 * @param array      $configured Venue-configured task parameters.
	 * @param array|null $asset      Generated asset reference.
	 * @return array Owner task parameters.
	 */
	private function task_params( array $context, array $configured, ?array $asset ): array {
		$params               = $configured;
		$params['booking_id'] = $context['booking']['id'];
		$params['event_id']   = $context['booking']['event_id'];
		$params['event_url']  = get_permalink( $context['booking']['event_id'] );
		$params['post_id']    = $context['booking']['event_id'];
		if ( empty( $params['caption'] ) ) {
			$params['caption'] = $context['event']->post_title . "\n\n" . $params['event_url'];
		}
		if ( null !== $asset ) {
			$params['images'] = array( array( 'url' => $asset['url'] ) );
		}
		return $params;
	}

	/**
	 * Derive a stable schedule from the canonical conversion time.
	 *
	 * @param array $context Authoritative booking context.
	 * @param array $channel Normalized channel configuration.
	 * @return int Unix timestamp.
	 */
	private function scheduled_at( array $context, array $channel ): int {
		$state = $this->activity->event_conversion_state( $context['booking']['id'], $context['booking']['public_id'] );
		$time  = is_array( $state ) && ! empty( $state['completed']['occurred_at'] ) ? strtotime( $state['completed']['occurred_at'] . ' UTC' ) : time();
		return max( 1, (int) $time + (int) $channel['delay_seconds'] );
	}

	/**
	 * Build the booking, event, trigger, and channel idempotency scope.
	 *
	 * @param array  $booking     Booking record.
	 * @param string $trigger_key Trigger key.
	 * @param string $channel_key Channel key.
	 * @return string Stable operation key.
	 */
	private function base_key( array $booking, string $trigger_key, string $channel_key ): string {
		return sprintf( 'booking-marketing:%s:%d:%s:%s', $booking['public_id'], $booking['event_id'], $trigger_key, $channel_key );
	}

	/**
	 * Build the deterministic UUID-shaped pending action identifier.
	 *
	 * @param string $base Idempotency base.
	 * @return string Pending action identifier.
	 */
	private function action_id( string $base ): string {
		$hash = substr( hash( 'sha256', $base . ':approval' ), 0, 32 );
		return sprintf( 'act_%s-%s-%s-%s-%s', substr( $hash, 0, 8 ), substr( $hash, 8, 4 ), substr( $hash, 12, 4 ), substr( $hash, 16, 4 ), substr( $hash, 20, 12 ) );
	}

	/**
	 * Normalize an activity into the public channel result.
	 *
	 * @param string $status   Orchestration status.
	 * @param array  $activity Activity record.
	 * @return array Channel result.
	 */
	private function activity_result( string $status, array $activity ): array {
		return array(
			'status'      => $status,
			'activity_id' => $activity['id'],
			'job_id'      => isset( $activity['payload']['data']['job_id'] ) ? (int) $activity['payload']['data']['job_id'] : null,
		);
	}

	/**
	 * Persist a retryable scheduling failure without replacing owner delivery state.
	 *
	 * @param array     $booking  Booking record.
	 * @param array     $channel  Channel configuration.
	 * @param string    $base     Idempotency base.
	 * @param int       $actor_id Acting user identifier.
	 * @param \WP_Error $error    Scheduling failure.
	 * @return \WP_Error Original failure.
	 */
	private function record_schedule_failure( array $booking, array $channel, string $base, int $actor_id, \WP_Error $error ): \WP_Error {
		$this->activity->append(
			array(
				'booking_id'      => $booking['id'],
				'kind'            => 'marketing_channel_schedule_failed',
				'actor_type'      => 'user',
				'actor_id'        => $actor_id,
				'channel'         => $channel['key'],
				'idempotency_key' => $base . ':failed:' . substr( hash( 'sha256', $error->get_error_code() ), 0, 16 ),
				'payload'         => array(
					'error_code'     => $error->get_error_code(),
					'error_data'     => $error->get_error_data(),
					'retryable'      => true,
					'delivery_state' => 'not_started',
				),
			)
		);
		return $error;
	}

	/**
	 * Record terminal workflow evidence without claiming external delivery.
	 *
	 * @param int    $job_id Data Machine job identifier.
	 * @param string $status Terminal job status.
	 */
	public static function on_job_complete( int $job_id, string $status ): void {
		if ( ! class_exists( '\DataMachine\Core\Database\Jobs\Jobs' ) ) {
			return;
		}
		$job    = ( new \DataMachine\Core\Database\Jobs\Jobs() )->get_job( $job_id );
		$engine = is_array( $job['engine_data'] ?? null ) ? $job['engine_data'] : array();
		$route  = $engine['task_context']['booking_marketing'] ?? null;
		if ( ! is_array( $route ) || empty( $route['booking_id'] ) || empty( $route['base_key'] ) ) {
			return;
		}
		$successes = (int) ( $engine['success_count'] ?? 0 );
		$failures  = (int) ( $engine['failure_count'] ?? 0 );
		$outcome   = $failures > 0 ? ( $successes > 0 ? 'partial' : 'failed' ) : ( 0 === strpos( $status, 'failed' ) ? 'failed' : 'completed' );
		( new BookingActivityRepository() )->append(
			array(
				'booking_id'      => (int) $route['booking_id'],
				'kind'            => 'marketing_channel_' . $outcome,
				'actor_type'      => 'system',
				'channel'         => sanitize_key( (string) ( $route['channel_key'] ?? '' ) ),
				'external_id'     => (string) $job_id,
				'idempotency_key' => sanitize_text_field( (string) $route['base_key'] ) . ':job:' . $job_id . ':outcome',
				'payload'         => array(
					'job_id'         => $job_id,
					'job_status'     => $status,
					'outcome'        => $outcome,
					'success_count'  => $successes,
					'failure_count'  => $failures,
					'owner_results'  => $engine['results'] ?? null,
					'delivery_state' => 'owner_authoritative',
				),
			)
		);
	}

	/**
	 * Record denied approvals; accepted approvals use their job receipt.
	 *
	 * @param object $action   Pending action value object.
	 * @param object $decision Approval decision value object.
	 * @param string $resolver Resolver principal.
	 */
	public static function on_pending_action_resolved( $action, $decision, string $resolver ): void {
		unset( $resolver );
		if ( ! is_object( $action ) || ! is_callable( array( $action, 'get_kind' ) ) || self::PENDING_KIND !== $action->get_kind() || ! is_object( $decision ) || ! is_callable( array( $decision, 'is_rejected' ) ) || ! $decision->is_rejected() ) {
			return;
		}
		$input = $action->get_apply_input();
		if ( ! is_array( $input ) ) {
			return;
		}
		$service = new self();
		$booking = $service->bookings->get( absint( $input['booking_id'] ?? 0 ) );
		if ( ! is_array( $booking ) ) {
			return;
		}
		$base = $service->base_key( $booking, sanitize_key( (string) ( $input['trigger_key'] ?? '' ) ), sanitize_key( (string) ( $input['channel_key'] ?? '' ) ) );
		$service->activity->append(
			array(
				'booking_id'      => $booking['id'],
				'kind'            => 'marketing_channel_denied',
				'actor_type'      => 'user',
				'actor_id'        => get_current_user_id(),
				'channel'         => sanitize_key( (string) ( $input['channel_key'] ?? '' ) ),
				'external_id'     => $action->get_action_id(),
				'idempotency_key' => $base . ':denied',
				'payload'         => array(
					'approval_id' => $action->get_action_id(),
					'trigger'     => $input['trigger_key'] ?? '',
					'channel'     => $input['channel_key'] ?? '',
				),
			)
		);
	}
}
