#!/usr/bin/env php
<?php
/**
 * Two-session booking-attachment row/advisory lock regression.
 *
 * Environment:
 * - EC_EVENTS_MYSQL_TEST_DSN=mysql:host=127.0.0.1;port=3306;dbname=events_test
 * - EC_EVENTS_MYSQL_TEST_USER
 * - EC_EVENTS_MYSQL_TEST_PASSWORD
 *
 * @package ExtraChillEvents\Tests\Integration
 */

$dsn = trim( (string) getenv( 'EC_EVENTS_MYSQL_TEST_DSN' ) );
if ( '' === $dsn ) {
	fwrite( STDOUT, "SKIP: EC_EVENTS_MYSQL_TEST_DSN is unavailable; no MySQL test endpoint was contacted.\n" );
	exit( 0 );
}
if ( ! extension_loaded( 'mysqli' ) ) {
	fwrite( STDERR, "FAIL: EC_EVENTS_MYSQL_TEST_DSN is configured but mysqli is unavailable.\n" );
	exit( 1 );
}

$settings = array();
foreach ( explode( ';', preg_replace( '/^mysql:/i', '', $dsn ) ) as $part ) {
	if ( false === strpos( $part, '=' ) ) {
		continue;
	}
	list( $key, $value )                    = explode( '=', $part, 2 );
	$settings[ strtolower( trim( $key ) ) ] = trim( $value );
}
$database = $settings['dbname'] ?? '';
if ( '' === $database || false === strpos( strtolower( $database ), 'test' ) ) {
	fwrite( STDERR, "FAIL: EC_EVENTS_MYSQL_TEST_DSN must name an explicit database containing 'test'; refusing a possible production endpoint.\n" );
	exit( 1 );
}

$user        = (string) getenv( 'EC_EVENTS_MYSQL_TEST_USER' );
$password    = (string) getenv( 'EC_EVENTS_MYSQL_TEST_PASSWORD' );
$host        = $settings['host'] ?? '127.0.0.1';
$port        = (int) ( $settings['port'] ?? 3306 );
$socket      = $settings['unix_socket'] ?? null;
$suffix      = bin2hex( random_bytes( 6 ) );
$memberships = 'ec_attachment_members_' . $suffix;
$bookings    = 'ec_attachment_bookings_' . $suffix;
$attachments = 'ec_attachment_refs_' . $suffix;
$reference   = 'private_object_integration_123456';
$lock_name   = 'ec_booking_file_' . substr( hash( 'sha256', '7:' . $attachments . ':' . $reference ), 0, 40 );
$owner       = null;
$contender   = null;
$exit_code   = 0;

$connect = static function () use ( $host, $user, $password, $database, $port, $socket ): mysqli {
	$connection = mysqli_init();
	$connection->real_connect( $host, $user, $password, $database, $port, $socket );
	$connection->set_charset( 'utf8mb4' );
	return $connection;
};
$assert = static function ( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
};
$lock = static function ( mysqli $connection, string $name, int $timeout = 0 ): int {
	$statement = $connection->prepare( 'SELECT GET_LOCK(?, ?)' );
	$statement->bind_param( 'si', $name, $timeout );
	$statement->execute();
	return (int) $statement->get_result()->fetch_row()[0];
};
$release = static function ( mysqli $connection, string $name ) {
	$statement = $connection->prepare( 'SELECT RELEASE_LOCK(?)' );
	$statement->bind_param( 's', $name );
	$statement->execute();
	return $statement->get_result()->fetch_row()[0];
};
$async_waiting = static function ( mysqli $connection ): bool {
	$read   = array( $connection );
	$error  = array( $connection );
	$reject = array( $connection );
	return 0 === mysqli_poll( $read, $error, $reject, 0, 0 );
};
$reap = static function ( mysqli $connection ) {
	$result = $connection->reap_async_query();
	if ( false === $result ) {
		throw new RuntimeException( 'Asynchronous query failed: ' . $connection->error );
	}
	return $result;
};

try {
	$owner     = $connect();
	$contender = $connect();
	foreach ( array( $owner, $contender ) as $connection ) {
		$connection->query( "SET SESSION sql_mode = 'STRICT_ALL_TABLES'" );
	}
	$owner->query( "CREATE TABLE `{$memberships}` (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, venue_term_id BIGINT UNSIGNED NOT NULL, user_id BIGINT UNSIGNED NOT NULL, status VARCHAR(32) NOT NULL, KEY venue_members (venue_term_id, id)) ENGINE=InnoDB" );
	$owner->query( "CREATE TABLE `{$bookings}` (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, venue_term_id BIGINT UNSIGNED NOT NULL, status VARCHAR(32) NOT NULL) ENGINE=InnoDB" );
	$owner->query( "CREATE TABLE `{$attachments}` (id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY, booking_id BIGINT UNSIGNED NOT NULL, storage_reference VARCHAR(255) NOT NULL, state VARCHAR(32) NOT NULL, KEY storage_state (storage_reference, state)) ENGINE=InnoDB" );
	$owner->query( "INSERT INTO `{$memberships}` (venue_term_id, user_id, status) VALUES (55, 12, 'active')" );
	$owner->query( "INSERT INTO `{$bookings}` (venue_term_id, status) VALUES (55, 'submitted')" );

	// A membership revocation cannot pass the exact rows used by authorize_locked().
	$owner->begin_transaction();
	$locked_membership = $owner->query( "SELECT * FROM `{$memberships}` WHERE venue_term_id = 55 ORDER BY id ASC FOR UPDATE" )->fetch_assoc();
	$assert( 'active' === $locked_membership['status'], 'The attachment session did not read the active locked membership.' );
	$owner->query( "SELECT * FROM `{$bookings}` WHERE id = 1 FOR UPDATE" );
	$assert( 1 === $lock( $owner, $lock_name ), 'The attachment session did not acquire its reference lock.' );
	$contender->query( "UPDATE `{$memberships}` SET status = 'revoked' WHERE venue_term_id = 55 AND user_id = 12", MYSQLI_ASYNC );
	usleep( 250000 );
	$assert( $async_waiting( $contender ), 'Membership revocation did not wait behind the locked authorization rows.' );
	$owner->commit();
	$assert( 1 === $release( $owner, $lock_name ), 'The attachment session did not release its reference lock after commit.' );
	$reap( $contender );
	$assert( 1 === $contender->affected_rows, 'Membership revocation did not complete after attachment authorization committed.' );
	$contender->query( "UPDATE `{$memberships}` SET status = 'active' WHERE venue_term_id = 55 AND user_id = 12" );

	// Cleanup waits on the same reference lock and must observe a newly committed active reference.
	$owner->begin_transaction();
	$owner->query( "SELECT * FROM `{$memberships}` WHERE venue_term_id = 55 ORDER BY id ASC FOR UPDATE" );
	$owner->query( "SELECT * FROM `{$bookings}` WHERE id = 1 FOR UPDATE" );
	$assert( 1 === $lock( $owner, $lock_name ), 'The attachment writer did not reacquire its reference lock.' );
	$owner->query( "INSERT INTO `{$attachments}` (booking_id, storage_reference, state) VALUES (1, '{$reference}', 'active')" );
	$escaped_lock = $contender->real_escape_string( $lock_name );
	$contender->query( "SELECT GET_LOCK('{$escaped_lock}', 3)", MYSQLI_ASYNC );
	usleep( 250000 );
	$assert( $async_waiting( $contender ), 'Cleanup did not wait behind active-reference creation.' );
	$owner->commit();
	$assert( 1 === $release( $owner, $lock_name ), 'The attachment writer did not release its cleanup lock.' );
	$lock_result = $reap( $contender );
	$assert( $lock_result instanceof mysqli_result && 1 === (int) $lock_result->fetch_row()[0], 'Cleanup did not acquire the lock after the writer committed.' );
	$active = $contender->query( "SELECT COUNT(*) FROM `{$attachments}` WHERE storage_reference = '{$reference}' AND state = 'active' FOR UPDATE" )->fetch_row();
	$assert( 1 === (int) $active[0], 'Cleanup did not observe the active reference committed while it waited.' );
	$assert( 1 === $release( $contender, $lock_name ), 'Cleanup did not release its acquired reference lock.' );

	// MySQL ownership is connection-local, recursive, and released by disconnect on uncertainty.
	$ownership_lock = 'ec_attachment_owner_' . $suffix;
	$assert( 1 === $lock( $owner, $ownership_lock ), 'Owner did not acquire the ownership lock.' );
	$assert( 0 === (int) $release( $contender, $ownership_lock ), 'A non-owner unexpectedly released another connection\'s lock.' );
	$assert( null === $release( $contender, 'ec_attachment_missing_' . $suffix ), 'Missing named lock did not return NULL.' );
	$assert( 1 === $lock( $owner, $ownership_lock ), 'Recursive owner acquisition failed.' );
	$assert( 1 === (int) $release( $owner, $ownership_lock ), 'First recursive release failed.' );
	$used_by = $owner->query( "SELECT IS_USED_LOCK('{$ownership_lock}')" )->fetch_row()[0];
	$assert( (int) $owner->thread_id === (int) $used_by, 'One recursive release incorrectly dropped all ownership.' );
	$assert( 1 === (int) $release( $owner, $ownership_lock ), 'Final recursive release failed.' );
	$assert( null === $owner->query( "SELECT IS_USED_LOCK('{$ownership_lock}')" )->fetch_row()[0], 'Final recursive release left the lock owned.' );

	$quarantine_lock = 'ec_attachment_quarantine_' . $suffix;
	$assert( 1 === $lock( $owner, $quarantine_lock ), 'Owner did not acquire the quarantine lock.' );
	$owner->begin_transaction();
	$owner->query( "SELECT * FROM `{$bookings}` WHERE id = 1 FOR UPDATE" );
	$owner->close();
	$owner = null;
	$assert( 1 === $lock( $contender, $quarantine_lock, 1 ), 'Disconnect did not release the uncertain transaction and named lock.' );
	$assert( 1 === (int) $release( $contender, $quarantine_lock ), 'Contender did not release the recovered quarantine lock.' );

	fwrite( STDOUT, "PASS: two MySQL sessions proved locked-row authorization, attach-vs-cleanup rechecks, named-lock ownership, recursion, and disconnect quarantine.\n" );
} catch ( Throwable $throwable ) {
	fwrite( STDERR, 'FAIL: ' . $throwable->getMessage() . "\n" );
	$exit_code = 1;
} finally {
	if ( $contender instanceof mysqli ) {
		foreach ( array( $attachments, $bookings, $memberships ) as $table ) {
			$contender->query( "DROP TABLE IF EXISTS `{$table}`" );
		}
		$contender->close();
	}
	if ( $owner instanceof mysqli ) {
		$owner->close();
	}
}

exit( $exit_code );
