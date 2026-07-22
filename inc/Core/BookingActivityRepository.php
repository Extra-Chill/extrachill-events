<?php
/**
 * Append-only booking activity repository.
 *
 * @package ExtraChillEvents\Core
 */

// phpcs:disable Squiz.Commenting.FunctionComment.MissingParamTag,Squiz.Commenting.FunctionComment.Missing -- Concise internal helpers are typed and named by purpose.
namespace ExtraChillEvents\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Stores immutable operational activity records. */
class BookingActivityRepository {

	/** Append one activity record. */
	public function append( array $data ) {
		global $wpdb;

		$booking_id = absint( $data['booking_id'] ?? 0 );
		$kind       = sanitize_key( (string) ( $data['kind'] ?? '' ) );
		if ( $booking_id < 1 || '' === $kind ) {
			return new \WP_Error( 'invalid_booking_activity', __( 'Booking activity requires a booking and kind.', 'extrachill-events' ) );
		}

		$payload  = wp_json_encode(
			array(
				'version' => 1,
				'data'    => $data['payload'] ?? array(),
			)
		);
		$now      = gmdate( 'Y-m-d H:i:s' );
		$actor_id = absint( $data['actor_id'] ?? 0 );
		$row      = array(
			'booking_id'      => $booking_id,
			'kind'            => mb_substr( $kind, 0, 64 ),
			'actor_type'      => mb_substr( sanitize_key( (string) ( $data['actor_type'] ?? 'system' ) ), 0, 32 ),
			'actor_id'        => 0 < $actor_id ? $actor_id : null,
			'direction'       => $this->nullable_key( $data['direction'] ?? null, 16 ),
			'channel'         => $this->nullable_key( $data['channel'] ?? null, 32 ),
			'payload'         => false === $payload ? '{"version":1,"data":{}}' : $payload,
			'external_id'     => $this->nullable_text( $data['external_id'] ?? null, 191 ),
			'idempotency_key' => $this->nullable_text( $data['idempotency_key'] ?? null, 191 ),
			'occurred_at'     => $this->datetime( $data['occurred_at'] ?? $now ),
			'created_at'      => $now,
		);
		if ( is_wp_error( $row['occurred_at'] ) ) {
			return $row['occurred_at'];
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		if ( false === $wpdb->insert( BookingSchema::activity_table(), $row ) ) {
			return new \WP_Error( 'booking_activity_create_failed', __( 'The booking activity could not be recorded.', 'extrachill-events' ) );
		}
		return $this->get( (int) $wpdb->insert_id );
	}

	/** Get one activity record by ID. */
	public function get( int $id ): ?array {
		global $wpdb;
		$table = BookingSchema::activity_table();
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d LIMIT 1", $id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Private operational table query.
		return is_array( $row ) ? $this->hydrate( $row ) : null;
	}

	/** List recent activity for one booking. */
	public function list_for_booking( int $booking_id, int $limit = 100, int $offset = 0 ): array {
		global $wpdb;
		$table  = BookingSchema::activity_table();
		$limit  = max( 1, min( 200, $limit ) );
		$offset = max( 0, $offset );
		$rows   = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE booking_id = %d ORDER BY occurred_at DESC, id DESC LIMIT %d OFFSET %d", $booking_id, $limit, $offset ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Private operational table query.
		return array_map( array( $this, 'hydrate' ), is_array( $rows ) ? $rows : array() );
	}

	/** Hydrate one activity row. */
	public function hydrate( array $row ): array {
		$row['id']         = (int) $row['id'];
		$row['booking_id'] = (int) $row['booking_id'];
		$row['actor_id']   = isset( $row['actor_id'] ) ? (int) $row['actor_id'] : null;
		$row['payload']    = json_decode( (string) $row['payload'], true );
		return $row;
	}

	private function nullable_key( $value, int $length ): ?string {
		$value = sanitize_key( (string) $value );
		return '' === $value ? null : mb_substr( $value, 0, $length );
	}

	private function nullable_text( $value, int $length ): ?string {
		$value = sanitize_text_field( (string) $value );
		return '' === $value ? null : mb_substr( $value, 0, $length );
	}

	private function datetime( $value ) {
		$date = \DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', (string) $value, new \DateTimeZone( 'UTC' ) );
		return $date && $date->format( 'Y-m-d H:i:s' ) === $value
			? $date->format( 'Y-m-d H:i:s' )
			: new \WP_Error( 'invalid_booking_activity_datetime', __( 'Activity timestamps must use UTC Y-m-d H:i:s format.', 'extrachill-events' ) );
	}
}
