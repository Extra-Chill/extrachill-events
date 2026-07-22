<?php
/**
 * Private venue booking repository.
 *
 * @package ExtraChillEvents\Core
 */

// phpcs:disable Squiz.Commenting.FunctionComment.MissingParamTag,Squiz.Commenting.FunctionComment.Missing -- Concise internal helpers are typed and named by purpose.
namespace ExtraChillEvents\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Provides bounded access to operational booking records. */
class BookingRepository {

	private const PAYLOAD_VERSION = 1;

	/** Create a booking. */
	public function create( array $data ) {
		global $wpdb;

		$identity = $this->validate_identity( $data );
		if ( is_wp_error( $identity ) ) {
			return $identity;
		}
		$venue_term_id = absint( $data['venue_term_id'] ?? 0 );
		$venue         = get_term( $venue_term_id, 'venue' );
		if ( $venue_term_id < 1 || ! $venue || is_wp_error( $venue ) || 'venue' !== $venue->taxonomy ) {
			return new \WP_Error( 'invalid_booking_venue', __( 'A valid Events venue term is required.', 'extrachill-events' ) );
		}
		$artist_name = sanitize_text_field( (string) ( $data['artist_name'] ?? $identity['artist_name'] ) );
		if ( '' === $artist_name ) {
			return new \WP_Error( 'missing_booking_artist_name', __( 'An artist name is required.', 'extrachill-events' ) );
		}

		$now = gmdate( 'Y-m-d H:i:s' );
		$row = array(
			'public_id'          => wp_generate_uuid4(),
			'venue_term_id'      => $venue_term_id,
			'artist_term_id'     => $identity['artist_term_id'],
			'artist_profile_id'  => $identity['artist_profile_id'],
			'artist_name'        => mb_substr( $artist_name, 0, 255 ),
			'submitter_user_id'  => $this->nullable_id( $data['submitter_user_id'] ?? null ),
			'contact_name'       => $this->nullable_text( $data['contact_name'] ?? null, 255 ),
			'contact_email'      => $this->nullable_email( $data['contact_email'] ?? null ),
			'contact_phone'      => $this->nullable_text( $data['contact_phone'] ?? null, 64 ),
			'space_key'          => $this->nullable_key( $data['space_key'] ?? null ),
			'status'             => $this->bounded_key( $data['status'] ?? 'inquiry', 32, 'inquiry' ),
			'version'            => 1,
			'assignee_user_id'   => $this->nullable_id( $data['assignee_user_id'] ?? null ),
			'requested_start_at' => $this->nullable_datetime( $data['requested_start_at'] ?? null ),
			'requested_end_at'   => $this->nullable_datetime( $data['requested_end_at'] ?? null ),
			'intake_payload'     => $this->encode_payload( $data['intake'] ?? array() ),
			'deal_payload'       => array_key_exists( 'deal', $data ) ? $this->encode_payload( $data['deal'] ) : null,
			'event_id'           => $this->nullable_id( $data['event_id'] ?? null ),
			'created_at'         => $now,
			'updated_at'         => $now,
		);
		if ( is_wp_error( $row['requested_start_at'] ) || is_wp_error( $row['requested_end_at'] ) ) {
			return is_wp_error( $row['requested_start_at'] ) ? $row['requested_start_at'] : $row['requested_end_at'];
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Query is prepared immediately above.
		if ( false === $wpdb->insert( BookingSchema::bookings_table(), $row ) ) {
			return new \WP_Error( 'booking_create_failed', __( 'The booking could not be created.', 'extrachill-events' ) );
		}
		return $this->get( (int) $wpdb->insert_id );
	}

	/** Get a booking by numeric ID or UUID public reference. */
	public function get( $identifier ): ?array {
		global $wpdb;
		$table = BookingSchema::bookings_table();
		if ( is_numeric( $identifier ) && (int) $identifier > 0 ) {
			$sql = $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", (int) $identifier ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		} else {
			$sql = $wpdb->prepare( "SELECT * FROM {$table} WHERE public_id = %s LIMIT 1", (string) $identifier ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Query is prepared immediately above.
		$row = $wpdb->get_row( $sql, ARRAY_A );
		return is_array( $row ) ? $this->hydrate( $row ) : null;
	}

	/** List bookings for one venue with bounded optional filters. */
	public function list( array $filters ) {
		global $wpdb;
		$venue_term_id = absint( $filters['venue_term_id'] ?? 0 );
		if ( $venue_term_id < 1 ) {
			return new \WP_Error( 'missing_booking_venue_filter', __( 'A venue filter is required.', 'extrachill-events' ) );
		}
		$table  = BookingSchema::bookings_table();
		$where  = array( 'venue_term_id = %d' );
		$values = array( $venue_term_id );
		$keys   = array(
			'status'             => '%s',
			'artist_term_id'     => '%d',
			'artist_profile_id'  => '%d',
			'assignee_user_id'   => '%d',
			'requested_start_at' => '%s',
		);
		foreach ( $keys as $key => $placeholder ) {
			if ( ! isset( $filters[ $key ] ) || '' === $filters[ $key ] ) {
				continue;
			}
			$operator = 'requested_start_at' === $key ? '>=' : '=';
			$where[]  = "{$key} {$operator} {$placeholder}";
			$values[] = '%d' === $placeholder ? absint( $filters[ $key ] ) : (string) $filters[ $key ];
		}
		$limit    = max( 1, min( 100, absint( $filters['limit'] ?? 50 ) ) );
		$offset   = max( 0, absint( $filters['offset'] ?? 0 ) );
		$values[] = $limit;
		$values[] = $offset;
		$query    = "SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY created_at DESC, id DESC LIMIT %d OFFSET %d'; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Query is prepared immediately above.
		$rows = $wpdb->get_results( $wpdb->prepare( $query, $values ), ARRAY_A );
		return array_map( array( $this, 'hydrate' ), is_array( $rows ) ? $rows : array() );
	}

	/** Conditionally update a booking at an expected version. */
	public function update( int $id, array $changes, int $expected_version ) {
		global $wpdb;
		if ( $id < 1 || $expected_version < 1 ) {
			return new \WP_Error( 'invalid_booking_update', __( 'A booking ID and expected version are required.', 'extrachill-events' ) );
		}
		if ( array_key_exists( 'artist_term_id', $changes ) || array_key_exists( 'artist_profile_id', $changes ) ) {
			$current = $this->get( $id );
			if ( ! $current ) {
				return new \WP_Error( 'booking_not_found', __( 'The booking was not found.', 'extrachill-events' ) );
			}
			$identity = $this->validate_identity( array_merge( $current, $changes ) );
			if ( is_wp_error( $identity ) ) {
				return $identity;
			}
			$changes['artist_term_id']    = $identity['artist_term_id'];
			$changes['artist_profile_id'] = $identity['artist_profile_id'];
		}
		$allowed = array(
			'artist_term_id',
			'artist_profile_id',
			'artist_name',
			'contact_name',
			'contact_email',
			'contact_phone',
			'space_key',
			'status',
			'assignee_user_id',
			'requested_start_at',
			'requested_end_at',
			'intake',
			'deal',
			'event_id',
		);
		$changes = array_intersect_key( $changes, array_flip( $allowed ) );
		if ( empty( $changes ) ) {
			return new \WP_Error( 'empty_booking_update', __( 'No supported booking fields were supplied.', 'extrachill-events' ) );
		}

		$set    = array();
		$values = array();
		foreach ( $changes as $key => $value ) {
			$column = $key;
			if ( 'intake' === $key || 'deal' === $key ) {
				$column = $key . '_payload';
				$value  = $this->encode_payload( $value );
			} elseif ( in_array( $key, array( 'artist_term_id', 'artist_profile_id', 'assignee_user_id', 'event_id' ), true ) ) {
				$value = $this->nullable_id( $value );
			} elseif ( in_array( $key, array( 'requested_start_at', 'requested_end_at' ), true ) ) {
				$value = $this->nullable_datetime( $value );
				if ( is_wp_error( $value ) ) {
					return $value;
				}
			} elseif ( 'contact_email' === $key ) {
				$value = $this->nullable_email( $value );
			} elseif ( 'space_key' === $key ) {
				$value = $this->nullable_key( $value );
			} else {
				$value = sanitize_text_field( (string) $value );
			}
			if ( null === $value ) {
				$set[] = "{$column} = NULL";
			} elseif ( is_int( $value ) ) {
				$set[]    = "{$column} = %d";
				$values[] = $value;
			} else {
				$set[]    = "{$column} = %s";
				$values[] = $value;
			}
		}
		$set[]    = 'version = version + 1';
		$set[]    = 'updated_at = %s';
		$values[] = gmdate( 'Y-m-d H:i:s' );
		$values[] = $id;
		$values[] = $expected_version;
		$table    = BookingSchema::bookings_table();
		$query    = "UPDATE {$table} SET " . implode( ', ', $set ) . ' WHERE id = %d AND version = %d'; // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- Query is prepared immediately above.
		$result = $wpdb->query( $wpdb->prepare( $query, $values ) );
		if ( false === $result ) {
			return new \WP_Error( 'booking_update_failed', __( 'The booking could not be updated.', 'extrachill-events' ) );
		}
		if ( 0 === $result ) {
			return new \WP_Error( 'booking_version_conflict', __( 'The booking changed since it was read.', 'extrachill-events' ), array( 'status' => 409 ) );
		}
		return $this->get( $id );
	}

	/** Validate optional canonical artist identities without creating mappings. */
	private function validate_identity( array $data ) {
		$term_id    = $this->nullable_id( $data['artist_term_id'] ?? null );
		$profile_id = $this->nullable_id( $data['artist_profile_id'] ?? null );
		$name       = '';
		if ( $term_id ) {
			$main_blog_id = function_exists( 'ec_get_blog_id' ) ? (int) ec_get_blog_id( 'main' ) : 0;
			if ( $main_blog_id < 1 ) {
				return new \WP_Error( 'artist_site_unresolved', __( 'The canonical artist site could not be resolved.', 'extrachill-events' ) );
			}
			switch_to_blog( $main_blog_id );
			$term             = get_term( $term_id, 'artist' );
			$bound_profile_id = $term && ! is_wp_error( $term ) ? absint( get_term_meta( $term_id, '_artist_profile_id', true ) ) : 0;
			restore_current_blog();
			if ( ! $term || is_wp_error( $term ) || 'artist' !== $term->taxonomy ) {
				return new \WP_Error( 'invalid_booking_artist_term', __( 'The canonical artist term is invalid.', 'extrachill-events' ) );
			}
			$name = (string) $term->name;
			if ( $profile_id && $bound_profile_id !== $profile_id ) {
				return new \WP_Error( 'booking_artist_identity_mismatch', __( 'The artist term and profile are not bound.', 'extrachill-events' ) );
			}
		}
		if ( $profile_id ) {
			$artist_blog_id = function_exists( 'ec_get_blog_id' ) ? (int) ec_get_blog_id( 'artist' ) : 0;
			if ( $artist_blog_id < 1 ) {
				return new \WP_Error( 'artist_platform_unresolved', __( 'The Artist Platform could not be resolved.', 'extrachill-events' ) );
			}
			switch_to_blog( $artist_blog_id );
			$profile = get_post( $profile_id );
			restore_current_blog();
			if ( ! $profile || 'artist_profile' !== $profile->post_type ) {
				return new \WP_Error( 'invalid_booking_artist_profile', __( 'The artist profile is invalid.', 'extrachill-events' ) );
			}
			if ( '' === $name ) {
				$name = (string) $profile->post_title;
			}
		}
		return array(
			'artist_term_id'    => $term_id,
			'artist_profile_id' => $profile_id,
			'artist_name'       => $name,
		);
	}

	/** Encode flexible data in a versioned JSON envelope. */
	private function encode_payload( $data ): string {
		$encoded = wp_json_encode(
			array(
				'version' => self::PAYLOAD_VERSION,
				'data'    => $data,
			)
		);
		return false === $encoded ? '{"version":1,"data":{}}' : $encoded;
	}

	/** Hydrate scalar IDs and JSON payloads. */
	public function hydrate( array $row ): array {
		foreach ( array( 'id', 'venue_term_id', 'artist_term_id', 'artist_profile_id', 'submitter_user_id', 'version', 'assignee_user_id', 'event_id' ) as $key ) {
			$row[ $key ] = isset( $row[ $key ] ) ? (int) $row[ $key ] : null;
		}
		foreach ( array( 'intake', 'deal' ) as $key ) {
			$decoded     = json_decode( (string) ( $row[ $key . '_payload' ] ?? '' ), true );
			$row[ $key ] = is_array( $decoded ) ? $decoded : null;
			unset( $row[ $key . '_payload' ] );
		}
		return $row;
	}

	private function nullable_id( $value ): ?int {
		$value = absint( $value );
		return $value > 0 ? $value : null;
	}

	private function nullable_text( $value, int $length ): ?string {
		$value = sanitize_text_field( (string) $value );
		return '' === $value ? null : mb_substr( $value, 0, $length );
	}

	private function nullable_email( $value ): ?string {
		$value = sanitize_email( (string) $value );
		return '' === $value ? null : mb_substr( $value, 0, 255 );
	}

	private function nullable_key( $value ): ?string {
		$value = sanitize_key( (string) $value );
		return '' === $value ? null : mb_substr( $value, 0, 64 );
	}

	private function bounded_key( $value, int $length, string $fallback ): string {
		$value = sanitize_key( (string) $value );
		return '' === $value ? $fallback : mb_substr( $value, 0, $length );
	}

	private function nullable_datetime( $value ) {
		if ( null === $value || '' === $value ) {
			return null;
		}
		$date = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', (string) $value, new \DateTimeZone( 'UTC' ) );
		if ( ! $date || $date->format( 'Y-m-d H:i:s' ) !== $value ) {
			return new \WP_Error( 'invalid_booking_datetime', __( 'Booking datetimes must use UTC Y-m-d H:i:s format.', 'extrachill-events' ) );
		}
		return $date->format( 'Y-m-d H:i:s' );
	}
}
