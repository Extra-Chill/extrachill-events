<?php
/**
 * Authorized canonical venue profile management.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Owns the member-editable boundary, revisions, and audit for venue profiles. */
class VenueProfile {

	public const STATE_META_KEY   = '_extrachill_venue_profile_state';
	public const HISTORY_META_KEY = '_extrachill_venue_profile_history';
	public const VERSION          = 1;

	private const META_FIELDS = array(
		'address'  => '_venue_address',
		'city'     => '_venue_city',
		'state'    => '_venue_state',
		'zip'      => '_venue_zip',
		'country'  => '_venue_country',
		'phone'    => '_venue_phone',
		'website'  => '_venue_website',
		'capacity' => '_venue_capacity',
	);

	private const PROFILE_FIELDS = array(
		'name',
		'description',
		'address',
		'city',
		'state',
		'zip',
		'country',
		'phone',
		'website',
		'capacity',
	);

	/**
	 * Exact venue membership authorization service.
	 *
	 * @var VenueAuthorization
	 */
	private $authorization;

	/**
	 * Construct the profile service.
	 *
	 * @param VenueAuthorization|null $authorization Optional authorization service.
	 */
	public function __construct( ?VenueAuthorization $authorization = null ) {
		$this->authorization = $authorization ? $authorization : new VenueAuthorization();
	}

	/**
	 * Return the stable member-facing profile document for one canonical venue.
	 *
	 * @param int $venue_term_id Canonical Events-site venue term ID.
	 * @return array|\WP_Error Profile document or an error.
	 */
	public function get( int $venue_term_id ) {
		$venue = $this->venue( $venue_term_id );
		if ( is_wp_error( $venue ) ) {
			return $venue;
		}

		$state = $this->state( $venue_term_id );
		if ( is_wp_error( $state ) ) {
			return $state;
		}

		return $this->document( $venue, $state );
	}

	/**
	 * Atomically replace all member-editable fields at one expected revision.
	 *
	 * @param int   $venue_term_id     Canonical Events-site venue term ID.
	 * @param array $profile           Complete member-editable profile.
	 * @param int   $expected_revision Revision observed by the caller.
	 * @param int   $actor_user_id     Network user performing the update.
	 * @return array|\WP_Error Updated profile document or an error.
	 */
	public function update( int $venue_term_id, array $profile, int $expected_revision, int $actor_user_id ) {
		global $wpdb;

		$venue = $this->venue( $venue_term_id );
		if ( is_wp_error( $venue ) ) {
			return $venue;
		}
		if ( $expected_revision < 0 ) {
			return new \WP_Error( 'invalid_venue_profile_revision', __( 'The expected venue profile revision must be zero or greater.', 'extrachill-events' ) );
		}
		$normalized = $this->normalize( $profile );
		if ( is_wp_error( $normalized ) ) {
			return $normalized;
		}

		if ( false === $wpdb->query( 'START TRANSACTION' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Serializes authorization, canonical fields, revision, and audit.
			return new \WP_Error( 'venue_profile_transaction_failed', __( 'The venue profile transaction could not start.', 'extrachill-events' ), array( 'database_error' => $wpdb->last_error ) );
		}

		$memberships = BookingSchema::memberships_table();
		$wpdb->get_var( $wpdb->prepare( "SELECT id FROM {$memberships} WHERE venue_term_id = %d AND user_id = %d FOR UPDATE", $venue_term_id, $actor_user_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Locks this actor's exact venue authority.
		if ( '' !== (string) $wpdb->last_error ) {
			return $this->rollback_error( 'venue_profile_authorization_lock_failed', __( 'Venue profile authority could not be locked.', 'extrachill-events' ), $venue_term_id );
		}
		$allowed = $this->authorization->authorize( $actor_user_id, $venue_term_id, VenueAuthorization::ACTION_ACCESS_VENUE );
		if ( is_wp_error( $allowed ) ) {
			$this->rollback( $venue_term_id );
			return $allowed;
		}

		$locked_term = $wpdb->get_var( $wpdb->prepare( "SELECT term_id FROM {$wpdb->terms} WHERE term_id = %d FOR UPDATE", $venue_term_id ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- One venue lock serializes all profile revisions.
		if ( '' !== (string) $wpdb->last_error || $venue_term_id !== (int) $locked_term ) {
			return $this->rollback_error( 'venue_profile_lock_failed', __( 'The venue profile could not be locked.', 'extrachill-events' ), $venue_term_id );
		}
		$wpdb->get_results( $wpdb->prepare( "SELECT meta_id FROM {$wpdb->termmeta} WHERE term_id = %d FOR UPDATE", $venue_term_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Locks the venue's existing metadata range before the complete profile snapshot.
		if ( '' !== (string) $wpdb->last_error ) {
			return $this->rollback_error( 'venue_profile_lock_failed', __( 'The venue profile metadata could not be locked.', 'extrachill-events' ), $venue_term_id );
		}

		$this->clear_cache( $venue_term_id );
		$current = $this->get( $venue_term_id );
		if ( is_wp_error( $current ) ) {
			$this->rollback( $venue_term_id );
			return $current;
		}
		if ( $current['revision'] !== $expected_revision ) {
			$this->rollback( $venue_term_id );
			return new \WP_Error(
				'venue_profile_revision_conflict',
				__( 'The venue profile changed since it was read.', 'extrachill-events' ),
				array(
					'status'           => 409,
					'current_revision' => $current['revision'],
				)
			);
		}

		$changed_fields = $this->changed_fields( $current['profile'], $normalized );
		if ( empty( $changed_fields ) ) {
			if ( false === $wpdb->query( 'COMMIT' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Releases locks after a validated no-op.
				$this->clear_cache( $venue_term_id );
				return new \WP_Error( 'venue_profile_commit_uncertain', __( 'The venue profile transaction outcome could not be confirmed.', 'extrachill-events' ), array( 'database_error' => $wpdb->last_error ) );
			}
			$this->clear_cache( $venue_term_id );
			return $current;
		}

		if ( in_array( 'name', $changed_fields, true ) ) {
			$result = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Canonical term write must commit with revision and audit.
				$wpdb->terms,
				array( 'name' => $normalized['name'] ),
				array( 'term_id' => $venue_term_id ),
				array( '%s' ),
				array( '%d' )
			);
			if ( false === $result || 1 !== $result ) {
				return $this->rollback_error( 'venue_profile_save_failed', __( 'The venue profile name could not be saved.', 'extrachill-events' ), $venue_term_id );
			}
		}
		if ( in_array( 'description', $changed_fields, true ) ) {
			$result = $wpdb->update( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Canonical description write must commit with revision and audit.
				$wpdb->term_taxonomy,
				array( 'description' => $normalized['description'] ),
				array(
					'term_id'  => $venue_term_id,
					'taxonomy' => 'venue',
				),
				array( '%s' ),
				array( '%d', '%s' )
			);
			if ( false === $result || 1 !== $result ) {
				return $this->rollback_error( 'venue_profile_save_failed', __( 'The venue profile description could not be saved.', 'extrachill-events' ), $venue_term_id );
			}
		}

		$meta_changes = array();
		foreach ( self::META_FIELDS as $field => $meta_key ) {
			if ( ! in_array( $field, $changed_fields, true ) ) {
				continue;
			}
			$value       = 'capacity' === $field && null !== $normalized[ $field ] ? (string) $normalized[ $field ] : $normalized[ $field ];
			$meta_change = $this->write_meta( $venue_term_id, $meta_key, $value );
			if ( is_wp_error( $meta_change ) ) {
				return $this->rollback_error( 'venue_profile_save_failed', __( 'The venue profile could not be saved.', 'extrachill-events' ), $venue_term_id );
			}
			$meta_changes[] = $meta_change;
		}

		$address_changed = (bool) array_intersect( array( 'address', 'city', 'state', 'zip', 'country' ), $changed_fields );
		if ( $address_changed ) {
			$coordinate_change = $this->write_meta( $venue_term_id, '_venue_coordinates', '' );
			if ( is_wp_error( $coordinate_change ) ) {
				return $this->rollback_error( 'venue_profile_save_failed', __( 'The venue profile coordinates could not be invalidated.', 'extrachill-events' ), $venue_term_id );
			}
			if ( 'none' !== $coordinate_change['operation'] ) {
				$meta_changes[] = $coordinate_change;
			}
		}

		$now          = gmdate( 'Y-m-d H:i:s' );
		$state        = array(
			'version'            => self::VERSION,
			'revision'           => $current['revision'] + 1,
			'updated_by_user_id' => $actor_user_id,
			'updated_at'         => $now,
		);
		$state_change = $this->write_meta( $venue_term_id, self::STATE_META_KEY, $state );
		if ( is_wp_error( $state_change ) ) {
			return $this->rollback_error( 'venue_profile_state_failed', __( 'The venue profile revision could not be saved.', 'extrachill-events' ), $venue_term_id );
		}
		$meta_changes[] = $state_change;

		$audit         = array(
			'version'           => 1,
			'previous_revision' => $current['revision'],
			'revision'          => $state['revision'],
			'actor_user_id'     => $actor_user_id,
			'changed_fields'    => $changed_fields,
			'occurred_at'       => $now,
		);
		$audit_result  = $wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Audit must commit atomically with canonical profile fields.
			$wpdb->termmeta,
			array(
				'term_id'    => $venue_term_id,
				'meta_key'   => self::HISTORY_META_KEY, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Canonical private audit key.
				'meta_value' => maybe_serialize( $audit ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Canonical serialized audit document.
			),
			array( '%d', '%s', '%s' )
		);
		$audit_meta_id = (int) $wpdb->insert_id;
		if ( false === $audit_result || 1 !== $audit_result || 1 > $audit_meta_id ) {
			return $this->rollback_error( 'venue_profile_audit_failed', __( 'The venue profile audit record could not be saved.', 'extrachill-events' ), $venue_term_id );
		}
		if ( false === $wpdb->query( 'COMMIT' ) ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Commits canonical fields, revision, and audit together.
			$this->clear_cache( $venue_term_id );
			return new \WP_Error( 'venue_profile_commit_uncertain', __( 'The venue profile transaction outcome could not be confirmed.', 'extrachill-events' ), array( 'database_error' => $wpdb->last_error ) );
		}

		$this->clear_cache( $venue_term_id );
		$this->dispatch_meta_changes( $venue_term_id, $meta_changes );
		do_action( 'added_term_meta', $audit_meta_id, $venue_term_id, self::HISTORY_META_KEY, $audit );
		if ( $address_changed && class_exists( '\DataMachineEvents\Core\Venue_Taxonomy' ) ) {
			\DataMachineEvents\Core\Venue_Taxonomy::maybe_geocode_venue( $venue_term_id );
		}
		if ( function_exists( 'extrachill_events_invalidate_location_venue_cache' ) ) {
			extrachill_events_invalidate_location_venue_cache( $venue_term_id );
		}
		do_action( 'extrachill_events_venue_profile_updated', $venue_term_id, $actor_user_id, $current['profile'], $normalized, $audit );

		return array(
			'version'            => self::VERSION,
			'venue_term_id'      => $venue_term_id,
			'revision'           => $state['revision'],
			'updated_by_user_id' => $actor_user_id,
			'updated_at'         => $now,
			'profile'            => $normalized,
		);
	}

	/**
	 * Normalize and validate the complete member-editable profile.
	 *
	 * @param array $profile Complete candidate profile.
	 * @return array|\WP_Error Normalized profile or an error.
	 */
	public function normalize( array $profile ) {
		$keys       = array_keys( $profile );
		$unexpected = array_diff( $keys, self::PROFILE_FIELDS );
		$missing    = array_diff( self::PROFILE_FIELDS, $keys );
		if ( ! empty( $unexpected ) || ! empty( $missing ) ) {
			return new \WP_Error(
				'invalid_venue_profile_fields',
				__( 'The venue profile must contain exactly the member-editable fields.', 'extrachill-events' ),
				array(
					'unexpected_fields' => array_values( $unexpected ),
					'missing_fields'    => array_values( $missing ),
				)
			);
		}

		$name = $this->text( $profile['name'], 'name', 191, false );
		if ( is_wp_error( $name ) ) {
			return $name;
		}
		$description = $this->description( $profile['description'] );
		if ( is_wp_error( $description ) ) {
			return $description;
		}

		$limits = array(
			'address' => 255,
			'city'    => 191,
			'state'   => 100,
			'zip'     => 32,
			'country' => 100,
			'phone'   => 64,
		);
		$result = array(
			'name'        => $name,
			'description' => $description,
		);
		foreach ( $limits as $field => $limit ) {
			$value = $this->text( $profile[ $field ], $field, $limit, true );
			if ( is_wp_error( $value ) ) {
				return $value;
			}
			$result[ $field ] = $value;
		}
		if ( '' !== $result['phone'] && ( ! preg_match( '/[0-9]/', $result['phone'] ) || preg_match( '/[^0-9+(). xX#\-]/', $result['phone'] ) ) ) {
			return new \WP_Error( 'invalid_venue_profile_value', __( 'The venue phone number is invalid.', 'extrachill-events' ), array( 'field' => 'phone' ) );
		}

		if ( ! is_string( $profile['website'] ) ) {
			return new \WP_Error( 'invalid_venue_profile_value', __( 'The venue website must be a string.', 'extrachill-events' ), array( 'field' => 'website' ) );
		}
		$website = trim( $profile['website'] );
		if ( '' !== $website ) {
			$website = esc_url_raw( $website, array( 'http', 'https' ) );
			if ( '' === $website || strlen( $website ) > 2048 || ! filter_var( $website, FILTER_VALIDATE_URL ) ) {
				return new \WP_Error( 'invalid_venue_profile_value', __( 'The venue website must be a valid HTTP or HTTPS URL.', 'extrachill-events' ), array( 'field' => 'website' ) );
			}
		}
		$result['website'] = $website;

		$capacity = $profile['capacity'];
		if ( null !== $capacity && ( ! is_int( $capacity ) || $capacity < 1 || $capacity > 1000000 ) ) {
			return new \WP_Error( 'invalid_venue_profile_value', __( 'Venue capacity must be null or an integer between 1 and 1000000.', 'extrachill-events' ), array( 'field' => 'capacity' ) );
		}
		$result['capacity'] = $capacity;

		return $result;
	}

	/**
	 * Return only public contract metadata and canonical editable fields.
	 *
	 * @param \WP_Term $venue Canonical venue term.
	 * @param array    $state Profile revision state.
	 * @return array Stable profile document.
	 */
	private function document( $venue, array $state ): array {
		$capacity = get_term_meta( $venue->term_id, self::META_FIELDS['capacity'], true );
		$profile  = array(
			'name'        => (string) $venue->name,
			'description' => (string) $venue->description,
		);
		foreach ( self::META_FIELDS as $field => $meta_key ) {
			if ( 'capacity' !== $field ) {
				$profile[ $field ] = (string) get_term_meta( $venue->term_id, $meta_key, true );
			}
		}
		$profile['capacity'] = '' === $capacity || null === $capacity ? null : ( ctype_digit( (string) $capacity ) ? (int) $capacity : null );

		return array(
			'version'            => self::VERSION,
			'venue_term_id'      => (int) $venue->term_id,
			'revision'           => $state['revision'],
			'updated_by_user_id' => $state['updated_by_user_id'],
			'updated_at'         => $state['updated_at'],
			'profile'            => $profile,
		);
	}

	/**
	 * Read and validate private profile revision state.
	 *
	 * @param int $venue_term_id Canonical venue term ID.
	 * @return array|\WP_Error Revision state or an error.
	 */
	private function state( int $venue_term_id ) {
		$stored = get_term_meta( $venue_term_id, self::STATE_META_KEY, true );
		if ( '' === $stored || null === $stored ) {
			return array(
				'version'            => self::VERSION,
				'revision'           => 0,
				'updated_by_user_id' => null,
				'updated_at'         => null,
			);
		}
		if ( ! is_array( $stored ) || self::VERSION !== ( $stored['version'] ?? null ) || ! is_int( $stored['revision'] ?? null ) || $stored['revision'] < 0 ) {
			return new \WP_Error( 'invalid_venue_profile_state', __( 'Stored venue profile state is malformed.', 'extrachill-events' ) );
		}
		$actor = $stored['updated_by_user_id'] ?? null;
		$time  = $stored['updated_at'] ?? null;
		if ( ( null !== $actor && ( ! is_int( $actor ) || $actor < 1 ) ) || ( null !== $time && ! is_string( $time ) ) ) {
			return new \WP_Error( 'invalid_venue_profile_state', __( 'Stored venue profile state is malformed.', 'extrachill-events' ) );
		}
		return array(
			'version'            => self::VERSION,
			'revision'           => $stored['revision'],
			'updated_by_user_id' => $actor,
			'updated_at'         => $time,
		);
	}

	/**
	 * Resolve a venue only in the canonical Events-site context.
	 *
	 * @param int $venue_term_id Candidate venue term ID.
	 * @return \WP_Term|\WP_Error Canonical venue term or an error.
	 */
	private function venue( int $venue_term_id ) {
		if ( ! function_exists( 'ec_get_blog_id' ) || ! function_exists( 'get_current_blog_id' ) ) {
			return new \WP_Error( 'canonical_events_site_required', __( 'The canonical Events site could not be resolved.', 'extrachill-events' ), array( 'status' => 500 ) );
		}
		$events_blog_id = (int) ec_get_blog_id( 'events' );
		if ( 1 > $events_blog_id || (int) get_current_blog_id() !== $events_blog_id ) {
			return new \WP_Error( 'canonical_events_site_required', __( 'Venue profiles must be resolved on the canonical Events site.', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		$venue = get_term( $venue_term_id, 'venue' );
		if ( 1 > $venue_term_id || ! $venue || is_wp_error( $venue ) || 'venue' !== $venue->taxonomy ) {
			return new \WP_Error( 'invalid_venue_profile_venue', __( 'A valid Events venue term is required.', 'extrachill-events' ), array( 'status' => 400 ) );
		}
		return $venue;
	}

	/**
	 * Validate and sanitize one bounded plain-text field.
	 *
	 * @param mixed  $value       Candidate value.
	 * @param string $field       Public field name.
	 * @param int    $limit       Maximum character count.
	 * @param bool   $allow_empty Whether an empty value is valid.
	 * @return string|\WP_Error Sanitized text or an error.
	 */
	private function text( $value, string $field, int $limit, bool $allow_empty ) {
		if ( ! is_string( $value ) ) {
			return new \WP_Error( 'invalid_venue_profile_value', __( 'Venue profile text fields must be strings.', 'extrachill-events' ), array( 'field' => $field ) );
		}
		$value = sanitize_text_field( $value );
		if ( ( ! $allow_empty && '' === $value ) || mb_strlen( $value ) > $limit ) {
			return new \WP_Error( 'invalid_venue_profile_value', __( 'A venue profile field has an invalid length.', 'extrachill-events' ), array( 'field' => $field ) );
		}
		return $value;
	}

	/**
	 * Validate and sanitize the bounded rich-text description.
	 *
	 * @param mixed $value Candidate description.
	 * @return string|\WP_Error Sanitized description or an error.
	 */
	private function description( $value ) {
		if ( ! is_string( $value ) ) {
			return new \WP_Error( 'invalid_venue_profile_value', __( 'The venue description must be a string.', 'extrachill-events' ), array( 'field' => 'description' ) );
		}
		$value = wp_kses_post( $value );
		return mb_strlen( $value ) <= 10000
			? $value
			: new \WP_Error( 'invalid_venue_profile_value', __( 'The venue description is too long.', 'extrachill-events' ), array( 'field' => 'description' ) );
	}

	/**
	 * List fields changed by a complete replacement.
	 *
	 * @param array $current     Current normalized profile.
	 * @param array $replacement Replacement normalized profile.
	 * @return string[] Changed public field names.
	 */
	private function changed_fields( array $current, array $replacement ): array {
		$changed = array();
		foreach ( self::PROFILE_FIELDS as $field ) {
			if ( $current[ $field ] !== $replacement[ $field ] ) {
				$changed[] = $field;
			}
		}
		return $changed;
	}

	/**
	 * Persist one term-meta value without publishing pre-commit hooks or caches.
	 *
	 * @param int    $venue_term_id Venue term ID.
	 * @param string $meta_key      Canonical private meta key.
	 * @param mixed  $value         Value to persist, or empty to delete.
	 * @return array|\WP_Error Deferred hook context or an error.
	 */
	private function write_meta( int $venue_term_id, string $meta_key, $value ) {
		global $wpdb;

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT meta_id, meta_value FROM {$wpdb->termmeta} WHERE term_id = %d AND meta_key = %s ORDER BY meta_id ASC LIMIT 1 FOR UPDATE", $venue_term_id, $meta_key ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Reads and locks canonical metadata inside the profile transaction.
		if ( '' !== (string) $wpdb->last_error ) {
			return new \WP_Error( 'venue_profile_meta_read_failed' );
		}
		$old_value = is_array( $row ) ? maybe_unserialize( $row['meta_value'] ) : '';
		if ( '' === $value || null === $value ) {
			if ( ! is_array( $row ) ) {
				return array(
					'operation' => 'none',
					'meta_id'   => 0,
					'key'       => $meta_key,
					'value'     => $old_value,
				);
			}
			$result = $wpdb->delete( $wpdb->termmeta, array( 'meta_id' => (int) $row['meta_id'] ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Transactional canonical metadata delete.
			return false === $result || 1 !== $result
				? new \WP_Error( 'venue_profile_meta_delete_failed' )
				: array(
					'operation' => 'delete',
					'meta_id'   => (int) $row['meta_id'],
					'key'       => $meta_key,
					'value'     => $old_value,
				);
		}

		$serialized = maybe_serialize( $value );
		if ( is_array( $row ) ) {
			$result  = $wpdb->update( $wpdb->termmeta, array( 'meta_value' => $serialized ), array( 'meta_id' => (int) $row['meta_id'] ), array( '%s' ), array( '%d' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Transactional canonical metadata update.
			$meta_id = (int) $row['meta_id'];
			$action  = 'update';
		} else {
			// Transactional canonical metadata insert; the key and value are private, bounded profile state.
			// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			$result = $wpdb->insert(
				$wpdb->termmeta,
				array(
					'term_id'    => $venue_term_id,
					'meta_key'   => $meta_key,
					'meta_value' => $serialized,
				),
				array( '%d', '%s', '%s' )
			);
			// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.SlowDBQuery.slow_db_query_meta_key,WordPress.DB.SlowDBQuery.slow_db_query_meta_value
			$meta_id = (int) $wpdb->insert_id;
			$action  = 'add';
		}
		if ( false === $result || 1 !== $result || 1 > $meta_id ) {
			return new \WP_Error( 'venue_profile_meta_write_failed' );
		}
		return array(
			'operation' => $action,
			'meta_id'   => $meta_id,
			'key'       => $meta_key,
			'value'     => $value,
		);
	}

	/**
	 * Publish standard metadata hooks only after the transaction commits.
	 *
	 * @param int   $venue_term_id Venue term ID.
	 * @param array $changes       Deferred metadata hook contexts.
	 */
	private function dispatch_meta_changes( int $venue_term_id, array $changes ): void {
		foreach ( $changes as $change ) {
			if ( 'add' === $change['operation'] ) {
				do_action( 'added_term_meta', $change['meta_id'], $venue_term_id, $change['key'], $change['value'] );
			} elseif ( 'update' === $change['operation'] ) {
				do_action( 'updated_term_meta', $change['meta_id'], $venue_term_id, $change['key'], $change['value'] );
			} elseif ( 'delete' === $change['operation'] ) {
				do_action( 'deleted_term_meta', array( $change['meta_id'] ), $venue_term_id, $change['key'], $change['value'] );
			}
		}
	}

	/**
	 * Roll back and return a database-backed profile error.
	 *
	 * @param string $code          Error code.
	 * @param string $message       Public error message.
	 * @param int    $venue_term_id Venue whose caches must be cleared.
	 * @return \WP_Error Rollback error.
	 */
	private function rollback_error( string $code, string $message, int $venue_term_id ): \WP_Error {
		global $wpdb;
		$database_error = $wpdb->last_error;
		$this->rollback( $venue_term_id );
		return new \WP_Error( $code, $message, array( 'database_error' => $database_error ) );
	}

	/**
	 * Roll back the active transaction and clear venue caches.
	 *
	 * @param int $venue_term_id Venue whose caches must be cleared.
	 */
	private function rollback( int $venue_term_id ): void {
		global $wpdb;
		$wpdb->query( 'ROLLBACK' ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Rolls back the complete profile mutation.
		$this->clear_cache( $venue_term_id );
	}

	/**
	 * Clear canonical term and metadata caches.
	 *
	 * @param int $venue_term_id Venue term ID.
	 */
	private function clear_cache( int $venue_term_id ): void {
		wp_cache_delete( $venue_term_id, 'term_meta' );
		clean_term_cache( $venue_term_id, 'venue' );
	}
}
