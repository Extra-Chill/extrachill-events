<?php
/**
 * Venue booking table installation.
 *
 * Storage is intentionally scoped to the current Events-site route through
 * `$wpdb->prefix`. Callers must execute on the Events site; repositories do
 * not switch blogs around operational queries.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Owns and verifies the site-scoped private booking schema. */
class BookingSchema {

	public const SCHEMA_VERSION = '1';
	public const VERSION_OPTION = 'extrachill_events_booking_schema_version';
	public const FAILURE_OPTION = 'extrachill_events_booking_schema_error';

	/** Get the bookings table for the current site. */
	public static function bookings_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'ec_bookings';
	}

	/** Get the booking activity table for the current site. */
	public static function activity_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'ec_booking_activity';
	}

	/** Create or repair both tables, stamping the version only after verification. */
	public static function install() {
		global $wpdb;

		$bookings = self::bookings_table();
		$activity = self::activity_table();
		$charset  = $wpdb->get_charset_collate();

		$bookings_sql = "CREATE TABLE {$bookings} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			public_id CHAR(36) NOT NULL,
			venue_term_id BIGINT UNSIGNED NOT NULL,
			artist_term_id BIGINT UNSIGNED NULL,
			artist_profile_id BIGINT UNSIGNED NULL,
			artist_name VARCHAR(255) NOT NULL,
			submitter_user_id BIGINT UNSIGNED NULL,
			contact_name VARCHAR(255) NULL,
			contact_email VARCHAR(255) NULL,
			contact_phone VARCHAR(64) NULL,
			space_key VARCHAR(64) NULL,
			status VARCHAR(32) NOT NULL DEFAULT 'inquiry',
			version BIGINT UNSIGNED NOT NULL DEFAULT 1,
			assignee_user_id BIGINT UNSIGNED NULL,
			requested_start_at DATETIME NULL,
			requested_end_at DATETIME NULL,
			intake_payload LONGTEXT NOT NULL,
			deal_payload LONGTEXT NULL,
			event_id BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY public_id (public_id),
			UNIQUE KEY event_id (event_id),
			KEY venue_status_created (venue_term_id, status, created_at),
			KEY venue_requested_start (venue_term_id, requested_start_at),
			KEY artist_term_created (artist_term_id, created_at),
			KEY artist_profile_created (artist_profile_id, created_at),
			KEY assignee_status (assignee_user_id, status),
			KEY status_updated (status, updated_at)
		) ENGINE=InnoDB {$charset};";

		$activity_sql = "CREATE TABLE {$activity} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			booking_id BIGINT UNSIGNED NOT NULL,
			kind VARCHAR(64) NOT NULL,
			actor_type VARCHAR(32) NOT NULL DEFAULT 'system',
			actor_id BIGINT UNSIGNED NULL,
			direction VARCHAR(16) NULL,
			channel VARCHAR(32) NULL,
			payload LONGTEXT NOT NULL,
			external_id VARCHAR(191) NULL,
			idempotency_key VARCHAR(191) NULL,
			occurred_at DATETIME NOT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY (id),
			UNIQUE KEY booking_idempotency (booking_id, idempotency_key),
			KEY booking_occurred (booking_id, occurred_at, id),
			KEY kind_occurred (kind, occurred_at),
			KEY channel_external (channel, external_id)
		) ENGINE=InnoDB {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $bookings_sql );
		dbDelta( $activity_sql );

		$health = self::health();
		if ( is_wp_error( $health ) ) {
			update_option(
				self::FAILURE_OPTION,
				array(
					'code'        => $health->get_error_code(),
					'message'     => $health->get_error_message(),
					'data'        => $health->get_error_data(),
					'failed_at'   => gmdate( 'Y-m-d H:i:s' ),
					'site_prefix' => $wpdb->prefix,
				),
				false
			);
			return $health;
		}

		update_option( self::VERSION_OPTION, self::SCHEMA_VERSION, false );
		delete_option( self::FAILURE_OPTION );
		return true;
	}

	/** Install when the version is stale or verified schema health is incomplete. */
	public static function maybe_install() {
		if ( self::SCHEMA_VERSION === (string) get_option( self::VERSION_OPTION, '' ) ) {
			$health = self::health();
			if ( true === $health ) {
				return true;
			}
		}
		return self::install();
	}

	/** Verify tables, critical columns, indexes, and uniqueness contracts. */
	public static function health() {
		global $wpdb;

		$contracts = array(
			self::bookings_table() => array(
				'columns' => array( 'id', 'public_id', 'venue_term_id', 'artist_term_id', 'artist_profile_id', 'artist_name', 'submitter_user_id', 'contact_name', 'contact_email', 'contact_phone', 'space_key', 'status', 'version', 'assignee_user_id', 'requested_start_at', 'requested_end_at', 'intake_payload', 'deal_payload', 'event_id', 'created_at', 'updated_at' ),
				'types'   => array(
					'id'             => 'bigint unsigned',
					'public_id'      => 'char(36)',
					'venue_term_id'  => 'bigint unsigned',
					'status'         => 'varchar(32)',
					'version'        => 'bigint unsigned',
					'intake_payload' => 'longtext',
					'event_id'       => 'bigint unsigned',
				),
				'indexes' => array(
					'PRIMARY'                => array( true, array( 'id' ) ),
					'public_id'              => array( true, array( 'public_id' ) ),
					'event_id'               => array( true, array( 'event_id' ) ),
					'venue_status_created'   => array( false, array( 'venue_term_id', 'status', 'created_at' ) ),
					'venue_requested_start'  => array( false, array( 'venue_term_id', 'requested_start_at' ) ),
					'artist_term_created'    => array( false, array( 'artist_term_id', 'created_at' ) ),
					'artist_profile_created' => array( false, array( 'artist_profile_id', 'created_at' ) ),
					'assignee_status'        => array( false, array( 'assignee_user_id', 'status' ) ),
					'status_updated'         => array( false, array( 'status', 'updated_at' ) ),
				),
			),
			self::activity_table() => array(
				'columns' => array( 'id', 'booking_id', 'kind', 'actor_type', 'actor_id', 'direction', 'channel', 'payload', 'external_id', 'idempotency_key', 'occurred_at', 'created_at' ),
				'types'   => array(
					'id'              => 'bigint unsigned',
					'booking_id'      => 'bigint unsigned',
					'kind'            => 'varchar(64)',
					'payload'         => 'longtext',
					'idempotency_key' => 'varchar(191)',
					'occurred_at'     => 'datetime',
				),
				'indexes' => array(
					'PRIMARY'             => array( true, array( 'id' ) ),
					'booking_idempotency' => array( true, array( 'booking_id', 'idempotency_key' ) ),
					'booking_occurred'    => array( false, array( 'booking_id', 'occurred_at', 'id' ) ),
					'kind_occurred'       => array( false, array( 'kind', 'occurred_at' ) ),
					'channel_external'    => array( false, array( 'channel', 'external_id' ) ),
				),
			),
		);

		foreach ( $contracts as $table => $contract ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Installation health cannot be cached.
			$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) );
			if ( '' !== (string) $wpdb->last_error ) {
				return new \WP_Error(
					'booking_schema_db_error',
					__( 'Could not inspect the booking tables.', 'extrachill-events' ),
					array(
						'table'          => $table,
						'database_error' => $wpdb->last_error,
					)
				);
			}
			if ( $found !== $table ) {
				return new \WP_Error( 'booking_schema_table_missing', __( 'A required booking table is missing.', 'extrachill-events' ), array( 'table' => $table ) );
			}

			$columns = $wpdb->get_results( "SHOW COLUMNS FROM `{$table}`", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Trusted current-prefix table.
			if ( '' !== (string) $wpdb->last_error ) {
				return new \WP_Error(
					'booking_schema_db_error',
					__( 'Could not inspect booking columns.', 'extrachill-events' ),
					array(
						'table'          => $table,
						'database_error' => $wpdb->last_error,
					)
				);
			}
			$column_names = array_column( (array) $columns, 'Field' );
			$missing      = array_values( array_diff( $contract['columns'], $column_names ) );
			if ( ! empty( $missing ) ) {
				return new \WP_Error(
					'booking_schema_columns_missing',
					__( 'Required booking columns are missing.', 'extrachill-events' ),
					array(
						'table'   => $table,
						'columns' => $missing,
					)
				);
			}
			$column_types = array();
			foreach ( (array) $columns as $column ) {
				$column_types[ $column['Field'] ] = preg_replace( '/\(20\)/', '', strtolower( (string) $column['Type'] ) );
			}
			foreach ( $contract['types'] as $column => $required_type ) {
				if ( ! isset( $column_types[ $column ] ) || $required_type !== $column_types[ $column ] ) {
					return new \WP_Error(
						'booking_schema_column_invalid',
						__( 'A critical booking column has an incompatible type.', 'extrachill-events' ),
						array(
							'table'  => $table,
							'column' => $column,
							'type'   => $required_type,
						)
					);
				}
			}

			$indexes = $wpdb->get_results( "SHOW INDEX FROM `{$table}`", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Trusted current-prefix table.
			if ( '' !== (string) $wpdb->last_error ) {
				return new \WP_Error(
					'booking_schema_db_error',
					__( 'Could not inspect booking indexes.', 'extrachill-events' ),
					array(
						'table'          => $table,
						'database_error' => $wpdb->last_error,
					)
				);
			}
			$found_indexes = array();
			foreach ( (array) $indexes as $index ) {
				$name                             = $index['Key_name'];
				$found_indexes[ $name ]['unique'] = 0 === (int) $index['Non_unique'];
				$found_indexes[ $name ]['columns'][ (int) $index['Seq_in_index'] ] = $index['Column_name'];
			}
			foreach ( $found_indexes as &$found_index ) {
				ksort( $found_index['columns'] );
				$found_index['columns'] = array_values( $found_index['columns'] );
			}
			unset( $found_index );
			foreach ( $contract['indexes'] as $name => $required_index ) {
				$must_be_unique   = $required_index[0];
				$required_columns = $required_index[1];
				if ( ! isset( $found_indexes[ $name ] ) || $must_be_unique !== $found_indexes[ $name ]['unique'] || $required_columns !== $found_indexes[ $name ]['columns'] ) {
					return new \WP_Error(
						'booking_schema_index_missing',
						__( 'A required booking index is missing or invalid.', 'extrachill-events' ),
						array(
							'table'   => $table,
							'index'   => $name,
							'unique'  => $must_be_unique,
							'columns' => $required_columns,
						)
					);
				}
			}
		}
		return true;
	}

	/** Backward-compatible table existence check backed by full schema health. */
	public static function tables_exist(): bool {
		return true === self::health();
	}
}
