<?php
/**
 * Local-support candidate matching and durable notification delivery.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Coordinates Events-owned matching with dependency-owned storage and eligibility. */
class LocalSupportNotificationService {

	public const REQUEST_OPENED_HOOK   = 'extrachill_events_local_support_request_opened';
	public const INTEREST_CHANGED_HOOK = 'extrachill_events_local_support_interest_changed';
	public const RECONCILE_HOOK        = 'extrachill_events_reconcile_local_support_notifications';
	public const ADAPTER_FILTER        = 'extrachill_events_local_support_notification_adapter';
	public const ERROR_HOOK            = 'extrachill_events_local_support_notification_error';
	public const ELIGIBILITY_ABILITY   = 'extrachill/resolve-local-support-candidates';
	public const PRODUCER              = 'extrachill-events-local-support';
	public const SCHEDULER_GROUP       = 'extrachill-events-local-support';
	public const MAX_ATTEMPTS          = 5;

	/** @var object|null #420-owned durable adapter. */
	private $adapter;
	/** @var callable|null Artist Platform eligibility resolver. */
	private $eligibility;
	/** @var callable|null Canonical event context resolver. */
	private $event_context;
	/** @var callable|null Structured Users receipt adapter. */
	private $delivery;
	/** @var callable|null Bounded actor resolver. */
	private $actor_resolver;

	/**
	 * Build the integration service.
	 *
	 * The durable adapter owns request/activity persistence and must expose:
	 * append_notification_intent(), pending_notification_intents(),
	 * notification_terminal(), notification_attempt_count(),
	 * record_notification_attempt(), record_notification_terminal(),
	 * organizer_recipient_ids(), and workspace_url().
	 */
	public function __construct( $adapter = null, $eligibility = null, $event_context = null, $delivery = null, $actor_resolver = null ) {
		$this->adapter        = $adapter;
		$this->eligibility    = $eligibility;
		$this->event_context  = $event_context;
		$this->delivery       = $delivery;
		$this->actor_resolver = $actor_resolver;
	}

	/** Register domain event consumers and bounded reconciliation. */
	public static function register(): void {
		add_action( self::REQUEST_OPENED_HOOK, array( self::class, 'handle_request_opened' ), 10, 2 );
		add_action( self::INTEREST_CHANGED_HOOK, array( self::class, 'handle_interest_changed' ), 10, 3 );
		add_action( self::RECONCILE_HOOK, array( self::class, 'reconcile_scheduled' ) );
		add_action( 'init', array( self::class, 'ensure_reconciliation_schedule' ) );
	}

	/** Ensure ambiguous outcomes remain recoverable after a process interruption. */
	public static function ensure_reconciliation_schedule(): void {
		if ( ! function_exists( 'as_schedule_recurring_action' ) || ! function_exists( 'as_next_scheduled_action' ) || as_next_scheduled_action( self::RECONCILE_HOOK, array(), self::SCHEDULER_GROUP ) ) {
			return;
		}
		as_schedule_recurring_action( time() + MINUTE_IN_SECONDS, 5 * MINUTE_IN_SECONDS, self::RECONCILE_HOOK, array(), self::SCHEDULER_GROUP, true );
	}

	/** Consume the stable #420 request-opened event contract. */
	public static function handle_request_opened( array $request, array $activity ): void {
		$service = self::runtime();
		$result  = is_wp_error( $service ) ? $service : $service->queue_request_opened( $request, $activity );
		if ( is_wp_error( $result ) ) {
			do_action( self::ERROR_HOOK, $result, 'request_opened', $activity );
		}
	}

	/** Consume the stable #420 interest-changed event contract. */
	public static function handle_interest_changed( array $request, array $interest, array $activity ): void {
		$service = self::runtime();
		$result  = is_wp_error( $service ) ? $service : $service->queue_interest_changed( $request, $interest, $activity );
		if ( is_wp_error( $result ) ) {
			do_action( self::ERROR_HOOK, $result, 'interest_changed', $activity );
		}
	}

	/** Run one scheduler reconciliation page. */
	public static function reconcile_scheduled(): void {
		$service = self::runtime();
		$result  = is_wp_error( $service ) ? $service : $service->reconcile_pending();
		if ( is_wp_error( $result ) ) {
			throw new \RuntimeException( $result->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Scheduler diagnostic.
		}
	}

	/** Resolve the #420 adapter only at runtime so either dependency may load later. */
	private static function runtime() {
		$adapter = apply_filters( self::ADAPTER_FILTER, null );
		if ( ! is_object( $adapter ) ) {
			return new \WP_Error( 'local_support_domain_adapter_unavailable', __( 'Local-support notification storage is unavailable; activate the request domain integration.', 'extrachill-events' ) );
		}
		return new self( $adapter );
	}

	/** Queue one deterministic candidate intent per eligible artist manager. */
	public function queue_request_opened( array $request, array $activity ) {
		$valid = $this->validate_source( $request, $activity, 'request_opened' );
		if ( true !== $valid ) {
			return $valid;
		}
		if ( 'open' !== ( $request['status'] ?? '' ) ) {
			return new \WP_Error( 'local_support_request_not_open', __( 'Candidate notifications require an open local-support request.', 'extrachill-events' ) );
		}
		$context = $this->resolve_event_context( (int) $request['event_id'] );
		if ( is_wp_error( $context ) ) {
			return $context;
		}
		$candidates = $this->resolve_candidates( $context, (string) ( $request['genre'] ?? '' ) );
		if ( is_wp_error( $candidates ) ) {
			return $candidates;
		}

		$queued = array();
		foreach ( $candidates as $candidate ) {
			foreach ( $candidate['manager_user_ids'] as $recipient_id ) {
				$key    = sprintf( 'local-support:request-opened:%d:%d:%d', $activity['id'], $candidate['artist_term_id'], $recipient_id );
				$intent = $this->append_intent(
					array(
						'idempotency_key'    => $key,
						'kind'               => 'candidate_request_opened',
						'source_activity_id' => (int) $activity['id'],
						'request_id'         => (int) $request['id'],
						'event_id'           => (int) $request['event_id'],
						'genre'              => sanitize_text_field( (string) ( $request['genre'] ?? '' ) ),
						'artist_term_id'     => $candidate['artist_term_id'],
						'recipient_id'       => $recipient_id,
						'payload'            => array(
							'type'    => 'local_support_request_opened',
							/* translators: %s: public event title. */
							'title'   => sprintf( __( 'Local support wanted for %s', 'extrachill-events' ), $context['title'] ),
							'item_id' => (int) $request['event_id'],
						),
					)
				);
				if ( is_wp_error( $intent ) ) {
					return $intent;
				}
				$queued[] = $intent;
			}
		}
		$this->schedule_reconciliation();
		return $queued;
	}

	/** Queue deterministic organizer intents after an artist interest mutation. */
	public function queue_interest_changed( array $request, array $interest, array $activity ) {
		$valid = $this->validate_source( $request, $activity, 'interest_changed' );
		if ( true !== $valid ) {
			return $valid;
		}
		$artist_term_id = absint( $interest['artist_term_id'] ?? 0 );
		if ( $artist_term_id < 1 ) {
			return new \WP_Error( 'local_support_artist_binding_invalid', __( 'The local-support interest requires a canonical artist binding.', 'extrachill-events' ) );
		}
		$context    = $this->resolve_event_context( (int) $request['event_id'] );
		$recipients = $this->organizer_recipients( $request );
		if ( is_wp_error( $context ) || is_wp_error( $recipients ) ) {
			return is_wp_error( $context ) ? $context : $recipients;
		}
		$queued = array();
		foreach ( $recipients as $recipient_id ) {
			$key    = sprintf( 'local-support:interest-changed:%d:%d', $activity['id'], $recipient_id );
			$intent = $this->append_intent(
				array(
					'idempotency_key'    => $key,
					'kind'               => 'organizer_interest_changed',
					'source_activity_id' => (int) $activity['id'],
					'request_id'         => (int) $request['id'],
					'event_id'           => (int) $request['event_id'],
					'artist_term_id'     => $artist_term_id,
					'recipient_id'       => $recipient_id,
					'payload'            => array(
						'type'    => 'local_support_interest_changed',
						/* translators: %s: public event title. */
						'title'   => sprintf( __( 'Artist interest updated for %s', 'extrachill-events' ), $context['title'] ),
						'item_id' => (int) $request['event_id'],
					),
				)
			);
			if ( is_wp_error( $intent ) ) {
				return $intent;
			}
			$queued[] = $intent;
		}
		$this->schedule_reconciliation();
		return $queued;
	}

	/** Reconcile a bounded page supplied by #420's durable activity store. */
	public function reconcile_pending( int $limit = 50 ) {
		$method = $this->adapter_method( 'pending_notification_intents' );
		if ( is_wp_error( $method ) ) {
			return $method;
		}
		$limit   = max( 1, min( 100, $limit ) );
		$intents = $this->adapter->pending_notification_intents( $limit );
		if ( is_wp_error( $intents ) || ! is_array( $intents ) ) {
			return is_wp_error( $intents ) ? $intents : new \WP_Error( 'local_support_pending_intents_invalid', __( 'Local-support pending notification storage returned an invalid result.', 'extrachill-events' ) );
		}
		$completed = 0;
		foreach ( array_slice( $intents, 0, $limit ) as $intent ) {
			$result = $this->reconcile_intent( $intent );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			if ( in_array( $result['status'] ?? '', array( 'delivered', 'suppressed' ), true ) ) {
				++$completed;
			}
		}
		if ( count( $intents ) >= $limit ) {
			$this->schedule_reconciliation();
		}
		return compact( 'completed' );
	}

	/** Revalidate authorization and reconcile one idempotent Users receipt. */
	public function reconcile_intent( array $intent ) {
		$valid = $this->validate_intent( $intent );
		if ( true !== $valid ) {
			return $valid;
		}
		$terminal = $this->call_adapter( 'notification_terminal', array( $intent['idempotency_key'] ) );
		if ( is_wp_error( $terminal ) || is_array( $terminal ) ) {
			return $terminal;
		}
		$authorized = $this->recipient_is_authorized( $intent );
		if ( is_wp_error( $authorized ) ) {
			return $this->record_failure( $intent, $authorized );
		}
		if ( ! $authorized ) {
			return $this->record_terminal( $intent, 'suppressed', array( 'reason' => 'recipient_no_longer_authorized' ) );
		}
		$link = $this->call_adapter( 'workspace_url', array( (int) $intent['request_id'], (int) $intent['recipient_id'] ) );
		if ( is_wp_error( $link ) || ! is_string( $link ) || ! $this->valid_url( $link ) ) {
			$error = is_wp_error( $link ) ? $link : new \WP_Error( 'local_support_workspace_url_invalid', __( 'The authorized local-support workspace URL is unavailable.', 'extrachill-events' ) );
			return $this->record_failure( $intent, $error );
		}
		$actor_id = $this->actor_id( $intent );
		if ( $actor_id < 1 ) {
			return $this->record_failure( $intent, new \WP_Error( 'local_support_notification_actor_unavailable', __( 'A bounded notification actor is unavailable.', 'extrachill-events' ) ) );
		}
		$payload = array(
			'actor_id'        => $actor_id,
			'type'            => $intent['payload']['type'],
			'title'           => $intent['payload']['title'],
			'link'            => $link,
			'item_id'         => (int) $intent['payload']['item_id'],
			'producer'        => self::PRODUCER,
			'idempotency_key' => (string) $intent['idempotency_key'],
		);
		try {
			$receipt = $this->deliver( array( (int) $intent['recipient_id'] ), $payload );
		} catch ( \Throwable $throwable ) {
			unset( $throwable );
			return $this->record_failure( $intent, new \WP_Error( 'local_support_delivery_uncertain', __( 'The idempotent notification delivery outcome is uncertain.', 'extrachill-events' ) ) );
		}
		if ( is_wp_error( $receipt ) ) {
			return $this->record_failure( $intent, $receipt );
		}
		$status = $receipt['recipients'][ (int) $intent['recipient_id'] ]['status'] ?? 'failed';
		return in_array( $status, array( 'inserted', 'existing' ), true )
			? $this->record_terminal( $intent, 'delivered', array( 'receipt_status' => $status ) )
			: $this->record_failure( $intent, new \WP_Error( 'local_support_delivery_failed', __( 'The notification recipient receipt failed.', 'extrachill-events' ) ) );
	}

	/** Validate a request-domain source without reading dependency storage. */
	private function validate_source( array $request, array $activity, string $kind ) {
		if ( absint( $request['id'] ?? 0 ) < 1 || absint( $request['event_id'] ?? 0 ) < 1 || absint( $activity['id'] ?? 0 ) < 1 || ( $activity['kind'] ?? '' ) !== $kind || absint( $activity['request_id'] ?? 0 ) !== absint( $request['id'] ?? 0 ) ) {
			return new \WP_Error( 'local_support_source_invalid', __( 'The local-support notification source contract is invalid.', 'extrachill-events' ) );
		}
		return true;
	}

	/** Resolve and strictly normalize Artist Platform candidates. */
	private function resolve_candidates( array $context, string $genre ) {
		$input = array(
			'producer'                => self::PRODUCER,
			'location_term_id'        => $context['location_term_id'],
			'location_slug'           => $context['location_slug'],
			'exclude_artist_term_ids' => $context['artist_term_ids'],
		);
		if ( '' !== $genre ) {
			$input['genre'] = sanitize_text_field( $genre );
		}
		if ( $this->eligibility ) {
			$result = call_user_func( $this->eligibility, $input );
		} else {
			$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( self::ELIGIBILITY_ABILITY ) : null;
			if ( ! $ability || ! is_callable( array( $ability, 'execute' ) ) ) {
				return new \WP_Error( 'local_support_eligibility_unavailable', __( 'The private Artist Platform local-support eligibility contract is unavailable.', 'extrachill-events' ) );
			}
			$result = $ability->execute( $input );
		}
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$rows = is_array( $result['candidates'] ?? null ) ? $result['candidates'] : null;
		if ( null === $rows ) {
			return new \WP_Error( 'local_support_eligibility_invalid', __( 'The Artist Platform eligibility response is invalid.', 'extrachill-events' ) );
		}
		$candidates = array();
		foreach ( $rows as $row ) {
			$term_id  = absint( $row['artist_term_id'] ?? 0 );
			$profile  = absint( $row['artist_profile_id'] ?? 0 );
			$managers = array_values( array_unique( array_filter( array_map( 'absint', (array) ( $row['manager_user_ids'] ?? array() ) ) ) ) );
			if ( $term_id < 1 || $profile < 1 || in_array( $term_id, $context['artist_term_ids'], true ) ) {
				if ( in_array( $term_id, $context['artist_term_ids'], true ) ) {
					continue;
				}
				return new \WP_Error( 'local_support_artist_binding_invalid', __( 'Artist Platform returned a candidate without a canonical artist binding.', 'extrachill-events' ) );
			}
			foreach ( $managers as $manager_id ) {
				if ( ! get_userdata( $manager_id ) ) {
					return new \WP_Error( 'local_support_manager_invalid', __( 'Artist Platform returned an invalid manager recipient.', 'extrachill-events' ) );
				}
			}
			if ( empty( $managers ) ) {
				continue;
			}
			$candidates[] = array(
				'artist_profile_id' => $profile,
				'artist_term_id'    => $term_id,
				'manager_user_ids'  => $managers,
			);
		}
		return $candidates;
	}

	/** Recheck current candidate eligibility or organizer authority before delivery. */
	private function recipient_is_authorized( array $intent ) {
		if ( 'candidate_request_opened' === $intent['kind'] ) {
			$context    = $this->resolve_event_context( (int) $intent['event_id'] );
			$candidates = is_wp_error( $context ) ? $context : $this->resolve_candidates( $context, (string) ( $intent['genre'] ?? '' ) );
			if ( is_wp_error( $candidates ) ) {
				return $candidates;
			}
			foreach ( $candidates as $candidate ) {
				if ( (int) $candidate['artist_term_id'] === (int) $intent['artist_term_id'] && in_array( (int) $intent['recipient_id'], $candidate['manager_user_ids'], true ) ) {
					return true;
				}
			}
			return false;
		}
		$recipients = $this->call_adapter( 'organizer_recipient_ids', array( (int) $intent['request_id'] ) );
		if ( is_wp_error( $recipients ) ) {
			return $recipients;
		}
		return in_array( (int) $intent['recipient_id'], array_map( 'absint', (array) $recipients ), true );
	}

	/** Resolve currently authorized organizers for the source request. */
	private function organizer_recipients( array $request ) {
		$recipients = $this->call_adapter( 'organizer_recipient_ids', array( (int) $request['id'] ) );
		if ( is_wp_error( $recipients ) ) {
			return $recipients;
		}
		$ids = array_values( array_unique( array_filter( array_map( 'absint', (array) $recipients ) ) ) );
		if ( empty( $ids ) ) {
			return new \WP_Error( 'local_support_organizer_unavailable', __( 'The request has no currently authorized organizer notification recipients.', 'extrachill-events' ) );
		}
		foreach ( $ids as $user_id ) {
			if ( ! get_userdata( $user_id ) ) {
				return new \WP_Error( 'local_support_organizer_invalid', __( 'The request domain returned an invalid organizer recipient.', 'extrachill-events' ) );
			}
		}
		return $ids;
	}

	/** Resolve canonical event, venue, location, and attached artist terms. */
	private function resolve_event_context( int $event_id ) {
		if ( $this->event_context ) {
			return call_user_func( $this->event_context, $event_id );
		}
		$events_blog_id = function_exists( 'ec_get_blog_id' ) ? absint( ec_get_blog_id( 'events' ) ) : 0;
		if ( $events_blog_id < 1 ) {
			return new \WP_Error( 'local_support_events_site_unavailable', __( 'The canonical Events site is unavailable.', 'extrachill-events' ) );
		}
		$switched = get_current_blog_id() !== $events_blog_id;
		if ( $switched ) {
			switch_to_blog( $events_blog_id );
		}
		try {
			$post      = get_post( $event_id );
			$locations = $post ? wp_get_object_terms( $event_id, 'location' ) : array();
			$venues    = $post ? wp_get_object_terms( $event_id, 'venue', array( 'fields' => 'ids' ) ) : array();
			$artists   = $post ? wp_get_object_terms( $event_id, 'artist', array( 'fields' => 'ids' ) ) : array();
			if ( ! $post || 'data_machine_events' !== $post->post_type || is_wp_error( $locations ) || 1 !== count( $locations ) ) {
				return new \WP_Error( 'local_support_event_location_invalid', __( 'The event requires one canonical Events location.', 'extrachill-events' ) );
			}
			if ( is_wp_error( $venues ) || 1 !== count( $venues ) ) {
				return new \WP_Error( 'local_support_event_venue_invalid', __( 'The event requires one canonical Events venue.', 'extrachill-events' ) );
			}
			if ( is_wp_error( $artists ) ) {
				return new \WP_Error( 'local_support_event_artists_unavailable', __( 'The event artist bindings could not be resolved.', 'extrachill-events' ) );
			}
			return array(
				'title'            => get_the_title( $post ),
				'location_term_id' => (int) $locations[0]->term_id,
				'location_slug'    => (string) $locations[0]->slug,
				'venue_term_id'    => (int) $venues[0],
				'artist_term_ids'  => array_values( array_unique( array_map( 'absint', $artists ) ) ),
			);
		} finally {
			if ( $switched ) {
				restore_current_blog();
			}
		}
	}

	/** Validate the private, contact-free durable intent shape. */
	private function validate_intent( array $intent ) {
		$required = array( 'idempotency_key', 'kind', 'request_id', 'event_id', 'artist_term_id', 'recipient_id', 'payload' );
		foreach ( $required as $key ) {
			if ( empty( $intent[ $key ] ) ) {
				return new \WP_Error( 'local_support_notification_intent_invalid', __( 'The durable local-support notification intent is invalid.', 'extrachill-events' ) );
			}
		}
		return true;
	}

	/** Append through #420's idempotent activity contract. */
	private function append_intent( array $intent ) {
		return $this->call_adapter( 'append_notification_intent', array( $intent ) );
	}

	/** Record retry metadata or poison after the bounded attempt count. */
	private function record_failure( array $intent, \WP_Error $error ) {
		$count = $this->call_adapter( 'notification_attempt_count', array( $intent['idempotency_key'] ) );
		if ( is_wp_error( $count ) ) {
			return $count;
		}
		$attempt = absint( $count ) + 1;
		if ( $attempt >= self::MAX_ATTEMPTS ) {
			return $this->record_terminal(
				$intent,
				'suppressed',
				array(
					'reason'     => 'delivery_poisoned',
					'attempt'    => $attempt,
					'error_code' => $error->get_error_code(),
				)
			);
		}
		$due_at = gmdate( 'Y-m-d H:i:s', time() + min( HOUR_IN_SECONDS, MINUTE_IN_SECONDS * ( 2 ** max( 0, $attempt - 1 ) ) ) );
		$result = $this->call_adapter( 'record_notification_attempt', array( $intent['idempotency_key'], $attempt, $due_at, $error->get_error_code() ) );
		$this->schedule_reconciliation( strtotime( $due_at . ' UTC' ) );
		return $result;
	}

	/** Record one idempotent terminal activity through #420's adapter. */
	private function record_terminal( array $intent, string $status, array $details ) {
		return $this->call_adapter( 'record_notification_terminal', array( $intent['idempotency_key'], $status, $details ) );
	}

	/** Call one required adapter method with an actionable dependency error. */
	private function call_adapter( string $method, array $args ) {
		$valid = $this->adapter_method( $method );
		return is_wp_error( $valid ) ? $valid : call_user_func_array( array( $this->adapter, $method ), $args );
	}

	/** Ensure a #420 adapter method is callable. */
	private function adapter_method( string $method ) {
		if ( is_object( $this->adapter ) && is_callable( array( $this->adapter, $method ) ) ) {
			return true;
		}
		/* translators: %s: required local-support domain adapter method. */
		return new \WP_Error( 'local_support_domain_contract_unavailable', sprintf( __( 'The local-support request domain must provide %s().', 'extrachill-events' ), $method ) );
	}

	/** Delegate to Extra Chill Users without producer-specific generic changes. */
	private function deliver( array $recipient_ids, array $payload ) {
		if ( $this->delivery ) {
			return call_user_func( $this->delivery, $recipient_ids, $payload );
		}
		return function_exists( 'ec_users_notify_with_receipts' )
			? ec_users_notify_with_receipts( $recipient_ids, $payload )
			: new \WP_Error( 'local_support_notification_receipts_unavailable', __( 'Idempotent Users notification receipts are unavailable.', 'extrachill-events' ) );
	}

	/** Resolve a valid notification actor without exposing a contact identity. */
	private function actor_id( array $intent ): int {
		$actor_id = $this->actor_resolver ? absint( call_user_func( $this->actor_resolver, $intent ) ) : 0;
		if ( $actor_id < 1 && function_exists( 'ec_get_network_bot_user_id' ) ) {
			$actor_id = absint( ec_get_network_bot_user_id() );
		}
		return $actor_id > 0 && get_userdata( $actor_id ) ? $actor_id : 0;
	}

	/** Validate an absolute HTTP(S) workspace URL. */
	private function valid_url( string $url ): bool {
		return function_exists( 'wp_http_validate_url' ) ? (bool) wp_http_validate_url( $url ) : 1 === preg_match( '#^https?://#i', $url );
	}

	/** Best-effort unique Action Scheduler wakeup. */
	private function schedule_reconciliation( ?int $timestamp = null ): void {
		if ( ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}
		try {
			as_schedule_single_action( $timestamp ? max( time() + 1, $timestamp ) : time() + MINUTE_IN_SECONDS, self::RECONCILE_HOOK, array(), self::SCHEDULER_GROUP, true );
		} catch ( \Throwable $throwable ) {
			unset( $throwable );
		}
	}
}
