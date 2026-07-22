<?php
/**
 * Venue booking persistence foundation tests.
 *
 * @package ExtraChillEvents\Tests
 */

use ExtraChillEvents\Core\BookingActivityRepository;
use ExtraChillEvents\Core\BookingRepository;
use ExtraChillEvents\Core\BookingSchema;
use ExtraChillEvents\Core\VenueBookingConfig;
use PHPUnit\Framework\TestCase;

// phpcs:disable -- Isolated WordPress and database test doubles.
if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $code;
		private $data;
		public function __construct( $code, $message = '', $data = array() ) {
			unset( $message );
			$this->code = $code;
			$this->data = $data;
		}
		public function get_error_code() { return $this->code; }
		public function get_error_data() { return $this->data; }
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $value ) { return $value instanceof WP_Error; }
}
if ( ! function_exists( '__' ) ) {
	function __( $text ) { return $text; }
}
if ( ! function_exists( 'absint' ) ) {
	function absint( $value ) { return abs( (int) $value ); }
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $value ) { return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
}
if ( ! function_exists( 'sanitize_email' ) ) {
	function sanitize_email( $value ) { return filter_var( trim( (string) $value ), FILTER_VALIDATE_EMAIL ) ?: ''; }
}
if ( ! function_exists( 'wp_generate_uuid4' ) ) {
	function wp_generate_uuid4() { return '123e4567-e89b-42d3-a456-426614174000'; }
}
if ( ! function_exists( 'ec_get_blog_id' ) ) {
	function ec_get_blog_id( $site ) { return array( 'main' => 1, 'artist' => 4, 'events' => 7 )[ $site ] ?? 0; }
}
if ( ! function_exists( 'get_current_blog_id' ) ) {
	function get_current_blog_id() { return $GLOBALS['ec_artist_test']['blog_id']; }
}
if ( ! function_exists( 'switch_to_blog' ) ) {
	function switch_to_blog( $blog_id ) {
		$GLOBALS['ec_artist_test']['stack'][] = $GLOBALS['ec_artist_test']['blog_id'];
		$GLOBALS['ec_artist_test']['blog_id'] = (int) $blog_id;
	}
}
if ( ! function_exists( 'restore_current_blog' ) ) {
	function restore_current_blog() { $GLOBALS['ec_artist_test']['blog_id'] = array_pop( $GLOBALS['ec_artist_test']['stack'] ); }
}
if ( ! function_exists( 'get_term' ) ) {
	function get_term( $term_id, $taxonomy = '' ) {
		$state = $GLOBALS['ec_artist_test'] ?? array();
		$term  = $state['terms'][ $state['blog_id'] ][ $term_id ] ?? null;
		return $term && ( '' === $taxonomy || $taxonomy === $term->taxonomy ) ? $term : null;
	}
}
if ( ! function_exists( 'get_term_meta' ) ) {
	function get_term_meta( $term_id, $key, $single = false ) {
		unset( $single );
		$state = $GLOBALS['ec_artist_test'] ?? array();
		return $state['meta'][ $state['blog_id'] ][ $term_id ][ $key ] ?? '';
	}
}
if ( ! function_exists( 'update_term_meta' ) ) {
	function update_term_meta( $term_id, $key, $value ) {
		$GLOBALS['ec_artist_test']['meta'][ get_current_blog_id() ][ $term_id ][ $key ] = $value;
		return true;
	}
}
if ( ! function_exists( 'get_post' ) ) {
	function get_post( $post_id ) {
		$state = $GLOBALS['ec_artist_test'] ?? array();
		return $state['posts'][ $state['blog_id'] ][ $post_id ] ?? null;
	}
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $key, $default = false ) { return $GLOBALS['ec_artist_test']['options'][ $key ] ?? $default; }
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $key, $value, $autoload = null ) {
		unset( $autoload );
		$GLOBALS['ec_artist_test']['options'][ $key ] = $value;
		return true;
	}
}

/** Minimal in-memory wpdb for repository and schema contracts. */
final class BookingWpdb {
	public $prefix = 'wp_7_';
	public $insert_id = 0;
	public $rows = array();
	public $last_query = '';

	public function get_charset_collate() { return 'DEFAULT CHARACTER SET utf8mb4'; }

	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		$i = 0;
		return preg_replace_callback(
			'/%[ds]/',
			static function ( $match ) use ( &$args, &$i ) {
				$value = $args[ $i++ ];
				return '%d' === $match[0] ? (string) (int) $value : "'" . addslashes( (string) $value ) . "'";
			},
			$query
		);
	}

	public function insert( $table, $row ) {
		$this->insert_id = count( $this->rows[ $table ] ?? array() ) + 1;
		$row['id'] = $this->insert_id;
		$this->rows[ $table ][ $this->insert_id ] = $row;
		return 1;
	}

	public function get_row( $query, $output = null ) {
		unset( $output );
		$this->last_query = $query;
		$table = false !== strpos( $query, 'ec_booking_activity' ) ? $this->prefix . 'ec_booking_activity' : $this->prefix . 'ec_bookings';
		if ( preg_match( '/WHERE id = (\d+)/', $query, $match ) ) {
			return $this->rows[ $table ][ (int) $match[1] ] ?? null;
		}
		if ( preg_match( "/WHERE public_id = '([^']+)'/", $query, $match ) ) {
			foreach ( $this->rows[ $table ] ?? array() as $row ) {
				if ( $row['public_id'] === $match[1] ) { return $row; }
			}
		}
		return null;
	}

	public function get_results( $query, $output = null ) {
		unset( $output );
		$this->last_query = $query;
		$table = false !== strpos( $query, 'ec_booking_activity' ) ? $this->prefix . 'ec_booking_activity' : $this->prefix . 'ec_bookings';
		return array_values( $this->rows[ $table ] ?? array() );
	}

	public function query( $query ) {
		$this->last_query = $query;
		if ( ! preg_match( '/WHERE id = (\d+) AND version = (\d+)/', $query, $match ) ) { return false; }
		$id = (int) $match[1];
		$expected = (int) $match[2];
		$table = $this->prefix . 'ec_bookings';
		if ( ! isset( $this->rows[ $table ][ $id ] ) || (int) $this->rows[ $table ][ $id ]['version'] !== $expected ) { return 0; }
		if ( preg_match( "/status = '([^']+)'/", $query, $status ) ) { $this->rows[ $table ][ $id ]['status'] = $status[1]; }
		if ( preg_match( '/artist_term_id = (\d+)/', $query, $term ) ) { $this->rows[ $table ][ $id ]['artist_term_id'] = (int) $term[1]; }
		if ( preg_match( '/artist_profile_id = (\d+)/', $query, $profile ) ) { $this->rows[ $table ][ $id ]['artist_profile_id'] = (int) $profile[1]; }
		$this->rows[ $table ][ $id ]['version']++;
		return 1;
	}

	public function get_var( $query ) {
		if ( preg_match( "/LIKE '([^']+)'/", $query, $match ) ) { return stripslashes( $match[1] ); }
		return null;
	}
}

require_once dirname( __DIR__ ) . '/inc/Core/BookingSchema.php';
require_once dirname( __DIR__ ) . '/inc/Core/BookingRepository.php';
require_once dirname( __DIR__ ) . '/inc/Core/BookingActivityRepository.php';
require_once dirname( __DIR__ ) . '/inc/Core/VenueBookingConfig.php';

final class BookingFoundationTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['ec_artist_test'] = array(
			'blog_id' => 7,
			'stack'   => array(),
			'options' => array(),
			'dbdelta' => array(),
			'terms'   => array(
				1 => array( 101 => (object) array( 'term_id' => 101, 'taxonomy' => 'artist', 'name' => 'Canonical Artist' ) ),
				7 => array( 55 => (object) array( 'term_id' => 55, 'taxonomy' => 'venue', 'name' => 'The Room' ) ),
			),
			'meta'    => array( 1 => array( 101 => array( '_artist_profile_id' => 501 ) ) ),
			'posts'   => array( 4 => array( 501 => (object) array( 'ID' => 501, 'post_type' => 'artist_profile', 'post_title' => 'Canonical Artist' ) ) ),
		);
		unset( $GLOBALS['ec_booking_test'] );
		$GLOBALS['wpdb'] = new BookingWpdb();
	}

	public function test_schema_is_site_scoped_idempotent_and_indexed(): void {
		$this->assertSame( 'wp_7_ec_bookings', BookingSchema::bookings_table() );
		$this->assertSame( 'wp_7_ec_booking_activity', BookingSchema::activity_table() );
		BookingSchema::install();
		$this->assertCount( 2, $GLOBALS['ec_artist_test']['dbdelta'] );
		$sql = implode( "\n", $GLOBALS['ec_artist_test']['dbdelta'] );
		$this->assertStringContainsString( 'UNIQUE KEY event_id', $sql );
		$this->assertStringContainsString( 'venue_status_created', $sql );
		$this->assertStringContainsString( 'UNIQUE KEY idempotency_key', $sql );
		$GLOBALS['ec_artist_test']['dbdelta'] = array();
		BookingSchema::maybe_install();
		$this->assertSame( array(), $GLOBALS['ec_artist_test']['dbdelta'] );
		$GLOBALS['wpdb']->prefix = 'wp_12_';
		$this->assertSame( 'wp_12_ec_bookings', BookingSchema::bookings_table() );
	}

	public function test_config_normalizes_integer_basis_points_and_default_space(): void {
		$config = ( new VenueBookingConfig() )->normalize(
			array(
				'enabled' => true,
				'spaces' => array( array( 'key' => 'Main Room', 'name' => 'Main Room' ) ),
				'default_deal' => array( 'revenue_share_basis_points' => 1750, 'revenue_share_basis' => 'net_ticket_sales' ),
				'marketing_channels' => array( 'Email', 'instagram' ),
			)
		);
		$this->assertSame( 1750, $config['default_deal']['revenue_share_basis_points'] );
		$this->assertTrue( $config['spaces'][0]['is_default'] );
		$this->assertSame( array( 'email', 'instagram' ), $config['marketing_channels'] );
		$this->assertTrue( is_wp_error( ( new VenueBookingConfig() )->normalize( array( 'default_deal' => array( 'revenue_share_basis_points' => 10001 ) ) ) ) );
	}

	public function test_unresolved_canonical_and_profile_backed_identity_states(): void {
		$repository = new BookingRepository();
		$unresolved = $repository->create( array( 'venue_term_id' => 55, 'artist_name' => 'New Band', 'contact_email' => 'band@example.com', 'intake' => array( 'draw' => 100 ) ) );
		$this->assertNull( $unresolved['artist_term_id'] );
		$this->assertNull( $unresolved['artist_profile_id'] );
		$this->assertSame( 1, $unresolved['intake']['version'] );

		$canonical = $repository->create( array( 'venue_term_id' => 55, 'artist_term_id' => 101 ) );
		$this->assertSame( 101, $canonical['artist_term_id'] );
		$this->assertNull( $canonical['artist_profile_id'] );

		$profile = $repository->create( array( 'venue_term_id' => 55, 'artist_term_id' => 101, 'artist_profile_id' => 501 ) );
		$this->assertSame( 501, $profile['artist_profile_id'] );
		$this->assertSame( 'Canonical Artist', $profile['artist_name'] );
	}

	public function test_later_binding_and_optimistic_version_conflict(): void {
		$repository = new BookingRepository();
		$booking = $repository->create( array( 'venue_term_id' => 55, 'artist_name' => 'New Band' ) );
		$bound = $repository->update( $booking['id'], array( 'artist_term_id' => 101, 'artist_profile_id' => 501, 'status' => 'reviewing' ), 1 );
		$this->assertSame( 2, $bound['version'] );
		$this->assertSame( 101, $bound['artist_term_id'] );
		$conflict = $repository->update( $booking['id'], array( 'status' => 'accepted' ), 1 );
		$this->assertSame( 'booking_version_conflict', $conflict->get_error_code() );
	}

	public function test_lists_are_bounded_and_activity_payloads_are_versioned(): void {
		$repository = new BookingRepository();
		$repository->create( array( 'venue_term_id' => 55, 'artist_name' => 'New Band' ) );
		$this->assertCount( 1, $repository->list( array( 'venue_term_id' => 55, 'limit' => 999 ) ) );
		$this->assertStringContainsString( 'LIMIT 100', $GLOBALS['wpdb']->last_query );
		$activity = ( new BookingActivityRepository() )->append( array( 'booking_id' => 1, 'kind' => 'inquiry_received', 'payload' => array( 'source' => 'form' ), 'idempotency_key' => 'inquiry-1' ) );
		$this->assertSame( 1, $activity['payload']['version'] );
		$this->assertSame( 'form', $activity['payload']['data']['source'] );
	}
}
// phpcs:enable

