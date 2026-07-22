<?php
/**
 * Venue booking table installation.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Owns the site-scoped private booking schema. */
class BookingSchema {

	public const SCHEMA_VERSION = '1';
	public const VERSION_OPTION = 'extrachill_events_booking_schema_version';

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

	/** Create or upgrade both tables with dbDelta(). */
	public static function install(): void {
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
			UNIQUE KEY idempotency_key (idempotency_key),
			KEY booking_occurred (booking_id, occurred_at, id),
			KEY kind_occurred (kind, occurred_at),
			KEY channel_external (channel, external_id)
		) ENGINE=InnoDB {$charset};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $bookings_sql );
		dbDelta( $activity_sql );
		update_option( self::VERSION_OPTION, self::SCHEMA_VERSION, false );
	}

	/** Install only when the site-scoped schema is stale or incomplete. */
	public static function maybe_install(): void {
		if ( self::SCHEMA_VERSION === (string) get_option( self::VERSION_OPTION, '' ) && self::tables_exist() ) {
			return;
		}
		self::install();
	}

	/** Determine whether both current-site tables exist. */
	public static function tables_exist(): bool {
		global $wpdb;
		$bookings = self::bookings_table();
		$activity = self::activity_table();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- Schema health cannot be cached.
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $bookings ) ) === $bookings
			&& $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $activity ) ) === $activity;
	}
}
