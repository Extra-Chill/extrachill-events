<?php
/**
 * Private byte-storage boundary for booking attachments.
 *
 * @package ExtraChillEvents\Core
 */

namespace ExtraChillEvents\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Domain-facing contract implemented only by an approved private store. */
interface BookingPrivateFileProvider {

	/**
	 * Idempotently claim an object and return trusted metadata.
	 *
	 * @param string $storage_reference Opaque object reference.
	 * @param string $claim_key         Consumer claim key.
	 */
	public function claim( string $storage_reference, string $claim_key );

	/**
	 * Release a failed metadata claim without deleting another consumer's claim.
	 *
	 * @param string $storage_reference Opaque object reference.
	 * @param string $claim_key         Consumer claim key.
	 */
	public function release_claim( string $storage_reference, string $claim_key );

	/**
	 * Return an authorized stream handoff without a public URL or filesystem path.
	 *
	 * @param string $storage_reference Opaque object reference.
	 */
	public function download_descriptor( string $storage_reference );

	/**
	 * Permanently retire one exact private object.
	 *
	 * @param string $storage_reference Opaque object reference.
	 */
	public function retire( string $storage_reference );
}
