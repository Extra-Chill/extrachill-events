<?php
/**
 * Bootstrap the native-process booking-mysql host proof.
 *
 * Chains the same WordPress-core-plus-DME boot `host-wordpress-bootstrap.php`
 * already provides (extrachill-events#882) with the multisite blog-7 booking
 * and promoter-authority schema provisioning `booking-schema-multisite-bootstrap.php`
 * provides — this suite's `BookingSchemaMultisiteTest` and
 * `PromoterAuthoritySchemaMultisiteTest` both assert against tables already
 * installed on blog 7 before their own test bodies run, the same precondition
 * the managed sandbox's `preload_files` already gives them there
 * (extrachill-events#870/#884).
 *
 * @package ExtraChillEvents\Tests\MySQLIntegration
 */

require_once __DIR__ . '/host-wordpress-bootstrap.php';
require_once __DIR__ . '/booking-schema-multisite-bootstrap.php';

// BookingEventSyncMySQLIntegrationTest exercises the real public
// `data-machine-events/upsert-event` ability. host-wordpress-bootstrap.php
// only cherry-picks the DME classes InternalBookingHoldConcurrencyMySQLProof
// needs, and does so *after* wp_abilities_api_init has already fired once
// during WordPress's own bootstrap — too late for a class that registers
// itself on that hook in its constructor. Load DME's own Composer autoloader
// (if present) for its transitive class dependencies, require the ability
// class directly, and re-fire wp_abilities_api_init so its constructor's
// add_action() callback gets a chance to run.
$dme_directory = rtrim( (string) getenv( 'DME_PLUGIN_DIR' ), '/\\' );
if ( '' !== $dme_directory ) {
	if ( is_file( $dme_directory . '/vendor/autoload.php' ) ) {
		require_once $dme_directory . '/vendor/autoload.php';
	}
	$upsert_event_ability = $dme_directory . '/inc/Abilities/EventUpsertAbilities.php';
	if ( is_file( $upsert_event_ability ) ) {
		require_once $upsert_event_ability;
		new \DataMachineEvents\Abilities\EventUpsertAbilities();
		do_action( 'wp_abilities_api_init' );
	}
}
