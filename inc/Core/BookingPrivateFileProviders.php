<?php
/**
 * Private booking file provider resolver.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Resolves an owner-approved provider without falling back to public uploads. */
final class BookingPrivateFileProviders {

	/** Return the registered private provider or fail closed. */
	public static function resolve() {
		$provider = apply_filters( 'extrachill_events_booking_private_file_provider', null );
		return $provider instanceof BookingPrivateFileProvider
			? $provider
			: new \WP_Error(
				'booking_private_storage_unavailable',
				__( 'Private booking file storage is not configured.', 'extrachill-events' ),
				array( 'status' => 503 )
			);
	}
}
