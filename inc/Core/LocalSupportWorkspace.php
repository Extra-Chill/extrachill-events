<?php
/**
 * Private Local Support workspace read model and form actions.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Keeps browser presentation behind the domain's server-only ability boundary. */
class LocalSupportWorkspace {

	public const PRODUCER = 'extrachill-events-local-support';

	/**
	 * Local Support persistence.
	 *
	 * @var LocalSupportRepository
	 */
	private $repository;

	/**
	 * Local Support authorization policy.
	 *
	 * @var LocalSupportAuthorization
	 */
	private $authorization;

	/**
	 * Canonical Local Support domain service.
	 *
	 * @var LocalSupportService
	 */
	private $service;

	/**
	 * Optional candidate resolver used by deterministic integration tests.
	 *
	 * @var callable|null
	 */
	private $candidate_resolver;

	/**
	 * Construct the private workspace adapter.
	 *
	 * @param LocalSupportRepository|null    $repository Persistence implementation.
	 * @param LocalSupportAuthorization|null $authorization Authorization policy.
	 * @param LocalSupportService|null       $service Domain service.
	 * @param callable|null                  $candidate_resolver Candidate resolver override.
	 */
	public function __construct( ?LocalSupportRepository $repository = null, ?LocalSupportAuthorization $authorization = null, ?LocalSupportService $service = null, ?callable $candidate_resolver = null ) {
		$this->repository         = $repository ? $repository : new LocalSupportRepository();
		$this->authorization      = $authorization ? $authorization : new LocalSupportAuthorization();
		$this->service            = $service ? $service : new LocalSupportService( $this->repository, $this->authorization );
		$this->candidate_resolver = $candidate_resolver;
	}

	/**
	 * Build the least-privilege read model for an organizer or eligible artist.
	 *
	 * @param int $request_id Request ID.
	 * @param int $artist_term_id Acting canonical artist term ID, or zero.
	 * @param int $user_id Acting user ID.
	 * @return array|\WP_Error Workspace model or denial.
	 */
	public function read( int $request_id, int $artist_term_id, int $user_id, ?array $organizer_identity = null, string $mode = 'auto' ) {
		$request = $this->repository->get_request( $request_id );
		if ( ! is_array( $request ) ) {
			return is_wp_error( $request ) ? $request : $this->denied();
		}

		$organizer_allowed = 'artist' === $mode ? $this->denied() : ( $organizer_identity
			? $this->authorization->authorize_organizer_action( LocalSupportAuthorization::ACTION_VIEW, $request, $user_id, $organizer_identity )
			: $this->authorization->authorize_organizer( $request, $user_id ) );
		if ( true === $organizer_allowed ) {
			$interests = $this->service->list_interests( $request_id, $user_id, 100, $organizer_identity );
			if ( is_wp_error( $interests ) ) {
				return $interests;
			}
			return array(
				'role'             => 'organizer',
				'request'          => $request,
				'event'            => $this->event_card( $request ),
				'interests'        => array_map( array( $this, 'interest_card' ), $interests ),
				'acting_organizer' => $organizer_identity,
			);
		}
		if ( null !== $organizer_identity && 'artist' !== $mode ) {
			return is_wp_error( $organizer_allowed ) ? $organizer_allowed : $this->denied();
		}

		if ( $artist_term_id < 1 ) {
			$candidates = $this->eligible_candidates( $request, $user_id );
			if ( empty( $candidates ) ) {
				return $this->denied();
			}
			return array(
				'role'       => 'artist_selection',
				'request'    => $request,
				'event'      => $this->event_card( $request ),
				'candidates' => $candidates,
			);
		}

		if ( true !== $this->authorization->authorize_artist( $artist_term_id, $user_id ) ) {
			return $this->denied();
		}
		$interest = $this->repository->get_interest_for_artist( $request_id, $artist_term_id );
		if ( is_wp_error( $interest ) ) {
			return $interest;
		}
		$candidate = $this->eligible_candidate( $request, $artist_term_id, $user_id );
		$eligible  = ! is_wp_error( $candidate );
		if ( ! $eligible && ! is_array( $interest ) ) {
			return $candidate;
		}
		if ( ! $eligible ) {
			$candidate                   = $this->interest_card( $interest )['artist'];
			$candidate['artist_term_id'] = $artist_term_id;
		}
		return array(
			'role'      => 'artist',
			'request'   => $request,
			'event'     => $this->event_card( $request ),
			'artist'    => $candidate,
			'interest'  => $interest,
			'eligible'  => $eligible,
			'interests' => array(),
		);
	}

	/**
	 * Execute a nonce-checked UI action through the canonical domain service.
	 *
	 * @param string $action UI action key.
	 * @param array  $input Sanitized form input.
	 * @param int    $user_id Acting user ID.
	 * @return array|\WP_Error Updated record or error.
	 */
	public function act( string $action, array $input, int $user_id ) {
		$request_id         = absint( $input['request_id'] ?? 0 );
		$artist_id          = absint( $input['artist_term_id'] ?? 0 );
		$key                = sanitize_key( (string) ( $input['idempotency_key'] ?? '' ) );
		$organizer_identity = $this->organizer_identity( $input );
		if ( is_wp_error( $organizer_identity ) ) {
			return $organizer_identity;
		}
		switch ( $action ) {
			case 'open':
				$booking_id = absint( $input['booking_id'] ?? 0 );
				$open_input = array(
					'event_id'        => absint( $input['event_id'] ?? 0 ),
					'booking_id'      => $booking_id > 0 ? $booking_id : null,
					'organizer_type'  => sanitize_key( (string) ( $input['organizer_type'] ?? '' ) ),
					'organizer_id'    => absint( $input['organizer_id'] ?? 0 ),
					'idempotency_key' => $key,
				);
				if ( $organizer_identity ) {
					$open_input['acting_organizer_type'] = $organizer_identity['type'];
					$open_input['acting_organizer_id']   = $organizer_identity['id'];
				}
				return $this->service->open_request(
					$open_input,
					$user_id
				);
			case 'request':
				return $this->service->transition_request( $request_id, sanitize_key( (string) ( $input['to_status'] ?? '' ) ), absint( $input['expected_version'] ?? 0 ), $key, $user_id, $organizer_identity );
			case 'interest':
				$workspace = $this->read( $request_id, $artist_id, $user_id );
				return is_wp_error( $workspace ) ? $workspace : $this->service->express_interest( $request_id, $artist_id, $key, $user_id );
			case 'interest_status':
				return $this->service->transition_interest( absint( $input['interest_id'] ?? 0 ), sanitize_key( (string) ( $input['to_status'] ?? '' ) ), absint( $input['expected_version'] ?? 0 ), $key, $user_id, $organizer_identity );
			case 'consent':
				$submitted_interest_id = absint( $input['interest_id'] ?? 0 );
				if ( ! empty( $input['granted'] ) ) {
					$workspace = $this->read( $request_id, $artist_id, $user_id );
					$interest  = is_array( $workspace ) ? ( $workspace['interest'] ?? null ) : null;
					if ( ! is_array( $workspace ) || empty( $workspace['eligible'] ) || ! is_array( $interest ) || (int) $interest['id'] !== $submitted_interest_id || ! in_array( $interest['status'], array( 'interested', 'shortlisted', 'selected' ), true ) ) {
						return $this->denied();
					}
				}
				$fields  = array_values( array_intersect( array( 'name', 'email', 'phone' ), array_map( 'sanitize_key', (array) ( $input['fields'] ?? array() ) ) ) );
				$contact = array(
					'name'  => sanitize_text_field( (string) ( $input['contact_name'] ?? '' ) ),
					'email' => sanitize_email( (string) ( $input['contact_email'] ?? '' ) ),
					'phone' => sanitize_text_field( (string) ( $input['contact_phone'] ?? '' ) ),
				);
				return $this->service->set_contact_consent( $submitted_interest_id, ! empty( $input['granted'] ), $contact, $fields, absint( $input['expected_version'] ?? 0 ), $key, $user_id );
		}
		return new \WP_Error( 'local_support_action_invalid', __( 'That local support action is not available.', 'extrachill-events' ), array( 'status' => 400 ) );
	}

	/** Parse one explicit managed organizer identity without inference. */
	private function organizer_identity( array $input ) {
		$has_type = array_key_exists( 'acting_organizer_type', $input );
		$has_id   = array_key_exists( 'acting_organizer_id', $input );
		if ( ! $has_type && ! $has_id ) {
			return null;
		}
		if ( $has_type !== $has_id ) {
			return new \WP_Error( 'local_support_organizer_identity_incomplete', __( 'Both organizer identity fields are required together.', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		$type = sanitize_key( (string) $input['acting_organizer_type'] );
		$id   = absint( $input['acting_organizer_id'] );
		if ( '' === $type || $id < 1 ) {
			return new \WP_Error( 'local_support_organizer_identity_invalid', __( 'A valid organizer identity is required.', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		return array(
			'type' => $type,
			'id'   => $id,
		);
	}

	/**
	 * Return exact-venue request links after reauthorizing every row.
	 *
	 * @param int $venue_term_id Exact venue term ID.
	 * @param int $user_id Acting user ID.
	 * @return array Authorized requests.
	 */
	public function venue_requests( int $venue_term_id, int $user_id ): array {
		$rows = $this->repository->list_requests_for_venue( $venue_term_id );
		if ( ! is_array( $rows ) ) {
			return array();
		}
		return array_values(
			array_filter(
				$rows,
				function ( array $request ) use ( $user_id ): bool {
					return true === $this->authorization->authorize_organizer( $request, $user_id );
				}
			)
		);
	}

	/**
	 * Return active opportunities visible to one exact managed Artist.
	 *
	 * @param int $artist_term_id Canonical Artist term ID.
	 * @param int $user_id Acting manager user ID.
	 * @return array|\WP_Error Exact Artist participation cards or denial.
	 */
	public function artist_opportunities( int $artist_term_id, int $user_id ) {
		$authorized = $this->authorization->authorize_artist( $artist_term_id, $user_id );
		if ( true !== $authorized ) {
			return is_wp_error( $authorized ) ? $authorized : $this->denied();
		}
		$opportunities     = array();
		$before_id         = 0;
		$scanned           = 0;
		$opportunity_count = 0;
		while ( $scanned < 500 && $opportunity_count < 100 ) {
			$requests = $this->repository->list_participation_requests( min( 101, 501 - $scanned ), $before_id );
			if ( ! is_array( $requests ) ) {
				return $requests;
			}
			$page = array_slice( $requests, 0, min( 100, 500 - $scanned ) );
			foreach ( $page as $request ) {
				++$scanned;
				$model = $this->read( (int) $request['id'], $artist_term_id, $user_id, null, 'artist' );
				if ( is_array( $model ) && 'artist' === ( $model['role'] ?? '' ) ) {
					$opportunities[] = $model;
					++$opportunity_count;
				}
			}
			if ( count( $requests ) <= count( $page ) ) {
				return $opportunities;
			}
			$last      = end( $page );
			$before_id = (int) $last['id'];
		}
		return $scanned >= 500
			? new \WP_Error(
				'local_support_artist_opportunity_scan_overflow',
				__( 'Artist opportunity discovery exceeded its safe scan bound.', 'extrachill-events' ),
				array(
					'status'  => 503,
					'maximum' => 500,
				)
			)
			: $opportunities;
	}

	/**
	 * Resolve one currently eligible candidate through Artist Platform.
	 *
	 * @param array $request Local Support request.
	 * @param int   $artist_term_id Canonical artist term ID.
	 * @param int   $user_id Acting manager user ID.
	 * @return array|\WP_Error Public artist card or denial.
	 */
	private function eligible_candidate( array $request, int $artist_term_id, int $user_id ) {
		$eligible = $this->service->artist_eligible( $request, $artist_term_id, $user_id );
		if ( true !== $eligible ) {
			return is_wp_error( $eligible ) ? $eligible : $this->denied();
		}
		foreach ( $this->eligible_candidates( $request, $user_id ) as $candidate ) {
			if ( (int) ( $candidate['artist_term_id'] ?? 0 ) === $artist_term_id ) {
				return $candidate;
			}
		}
		return $this->denied();
	}

	/**
	 * Resolve all currently eligible artists managed by the acting user.
	 *
	 * @param array $request Local Support request.
	 * @param int   $user_id Acting manager user ID.
	 * @return array Public candidate cards.
	 */
	private function eligible_candidates( array $request, int $user_id ): array {
		if ( $this->candidate_resolver ) {
			$candidates = call_user_func( $this->candidate_resolver, $request, $user_id );
			return is_array( $candidates ) ? $candidates : array();
		}
		$locations = wp_get_object_terms( (int) $request['event_id'], 'location', array( 'fields' => 'ids' ) );
		$location  = 1 === count( (array) $locations ) ? get_term( (int) reset( $locations ), 'location' ) : null;
		$ability   = function_exists( 'wp_get_ability' ) ? wp_get_ability( 'extrachill/artist-query-local-support-candidates' ) : null;
		if ( ! $location instanceof \WP_Term || ! $ability ) {
			return array();
		}
		$result = $ability->execute(
			array(
				'producer'   => self::PRODUCER,
				'scene_slug' => $location->slug,
			)
		);
		if ( is_wp_error( $result ) ) {
			return array();
		}
		$matches = array();
		foreach ( (array) ( $result['candidates'] ?? array() ) as $candidate ) {
			$artist_term_id = absint( $candidate['artist_term_id'] ?? 0 );
			$attached       = $artist_term_id ? $this->authorization->artist_attached_to_event( (int) $request['event_id'], $artist_term_id ) : true;
			if ( false === $attached && in_array( $user_id, array_map( 'absint', (array) ( $candidate['manager_user_ids'] ?? array() ) ), true ) ) {
				unset( $candidate['manager_user_ids'] );
				$matches[] = $candidate;
			}
		}
		return $matches;
	}

	/**
	 * Add only canonical public artist-card data to an interest.
	 *
	 * @param array $interest Interest record.
	 * @return array Enriched interest.
	 */
	public function interest_card( array $interest ): array {
		$profile_id = 0;
		$main_id    = function_exists( 'ec_get_blog_id' ) ? absint( ec_get_blog_id( 'main' ) ) : 0;
		$artist_id  = function_exists( 'ec_get_blog_id' ) ? absint( ec_get_blog_id( 'artist' ) ) : 0;
		if ( $main_id ) {
			switch_to_blog( $main_id );
			try {
				$profile_id = absint( get_term_meta( (int) $interest['artist_term_id'], '_artist_profile_id', true ) );
			} finally {
				restore_current_blog();
			}
		}
		$card = array( 'name' => __( 'Artist', 'extrachill-events' ) );
		if ( $artist_id && $profile_id ) {
			switch_to_blog( $artist_id );
			try {
				$post = get_post( $profile_id );
				if ( $post instanceof \WP_Post && 'publish' === $post->post_status ) {
					$genre      = get_post_meta( $profile_id, '_genre', true );
					$local_city = get_post_meta( $profile_id, '_local_city', true );
					$image_url  = get_the_post_thumbnail_url( $profile_id, 'medium' );
					$card       = array(
						'name'              => $post->post_title,
						'permalink'         => get_permalink( $profile_id ),
						'genre'             => $genre ? $genre : null,
						'local_city'        => $local_city ? $local_city : null,
						'profile_image_url' => $image_url ? $image_url : null,
					);
				}
			} finally {
				restore_current_blog();
			}
		}
		$interest['artist'] = $card;
		return $interest;
	}

	/**
	 * Present public event context without exposing request candidates.
	 *
	 * @param array $request Local Support request.
	 * @return array Public event card.
	 */
	private function event_card( array $request ): array {
		$post  = get_post( (int) $request['event_id'] );
		$venue = get_term( (int) $request['venue_term_id'], 'venue' );
		return array(
			'id'        => (int) $request['event_id'],
			'title'     => $post instanceof \WP_Post ? $post->post_title : __( 'Event', 'extrachill-events' ),
			'permalink' => get_permalink( (int) $request['event_id'] ),
			'venue'     => $venue instanceof \WP_Term ? $venue->name : '',
		);
	}

	/** Return a non-enumerating workspace denial. */
	private function denied(): \WP_Error {
		return new \WP_Error( 'local_support_forbidden', __( 'This private local support workspace is unavailable.', 'extrachill-events' ), array( 'status' => 403 ) );
	}
}
