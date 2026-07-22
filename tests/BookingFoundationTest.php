<?php
/**
 * Venue booking persistence foundation tests.
 *
 * @package ExtraChillEvents\Tests
 */

use ExtraChillEvents\Core\BookingActivityRepository;
use ExtraChillEvents\Core\BookingLifecycle;
use ExtraChillEvents\Core\BookingRepository;
use ExtraChillEvents\Core\BookingSchema;
use ExtraChillEvents\Core\VenueBookingConfig;
use ExtraChillEvents\Abilities\VenueBookingAbilities;
use ExtraChillEvents\Core\VenueAuthorization;
use PHPUnit\Framework\TestCase;

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}
if ( ! class_exists( 'WP_Error' ) ) {
	class WP_Error {
		private $code;
		private $message;
		private $data;
		public function __construct( $code, $message = '', $data = array() ) {
			$this->code    = $code;
			$this->message = $message;
			$this->data    = $data;
		}
		public function get_error_code() {
			return $this->code; }
		public function get_error_message() {
			return $this->message; }
		public function get_error_data() {
			return $this->data; }
	}
}
if ( ! function_exists( 'is_wp_error' ) ) {
	function is_wp_error( $value ) {
		return $value instanceof WP_Error; }
}
if ( ! function_exists( '__' ) ) {
	function __( $text ) {
		return $text; }
}
if ( ! function_exists( 'absint' ) ) {
	function absint( $value ) {
		return abs( (int) $value ); }
}
if ( ! function_exists( 'sanitize_key' ) ) {
	function sanitize_key( $value ) {
		return preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $value ) ); }
}
if ( ! function_exists( 'sanitize_email' ) ) {
	function sanitize_email( $value ) {
		$sanitized = filter_var( trim( (string) $value ), FILTER_VALIDATE_EMAIL );
		return false === $sanitized ? '' : $sanitized; }
}
if ( ! function_exists( 'wp_generate_uuid4' ) ) {
	function wp_generate_uuid4() {
		++$GLOBALS['ec_artist_test']['uuid'];
		return sprintf( '123e4567-e89b-42d3-a456-%012d', $GLOBALS['ec_artist_test']['uuid'] );
	}
}
if ( ! function_exists( 'wp_salt' ) ) {
	function wp_salt( $scheme = 'auth' ) {
		return 'booking-test-salt:' . $scheme; }
}
if ( ! function_exists( 'ec_get_blog_id' ) ) {
	function ec_get_blog_id( $site ) {
		return array(
			'main'   => 1,
			'artist' => 4,
			'events' => 7,
		)[ $site ] ?? 0; }
}
if ( ! function_exists( 'get_current_blog_id' ) ) {
	function get_current_blog_id() {
		return $GLOBALS['ec_artist_test']['blog_id']; }
}
if ( ! function_exists( 'switch_to_blog' ) ) {
	function switch_to_blog( $blog_id ) {
		$GLOBALS['ec_artist_test']['stack'][] = $GLOBALS['ec_artist_test']['blog_id'];
		$GLOBALS['ec_artist_test']['blog_id'] = (int) $blog_id;
	}
}
if ( ! function_exists( 'restore_current_blog' ) ) {
	function restore_current_blog() {
		$GLOBALS['ec_artist_test']['blog_id'] = array_pop( $GLOBALS['ec_artist_test']['stack'] ); }
}
if ( ! function_exists( 'get_term' ) ) {
	function get_term( $term_id, $taxonomy = '' ) {
		if ( ! empty( $GLOBALS['ec_artist_test']['throw_get_term'] ) ) {
			throw new RuntimeException( 'term read failed' );
		}
		$state = $GLOBALS['ec_artist_test'];
		$term  = $state['terms'][ $state['blog_id'] ][ $term_id ] ?? null;
		return $term && ( '' === $taxonomy || $taxonomy === $term->taxonomy ) ? $term : null;
	}
}
if ( ! function_exists( 'get_term_meta' ) ) {
	function get_term_meta( $term_id, $key, $single = false ) {
		unset( $single );
		$state = $GLOBALS['ec_artist_test'];
		return $state['meta'][ $state['blog_id'] ][ $term_id ][ $key ] ?? '';
	}
}
if ( ! function_exists( 'update_term_meta' ) ) {
	function update_term_meta( $term_id, $key, $value ) {
		$current = get_term_meta( $term_id, $key, true );
		if ( ! empty( $GLOBALS['ec_artist_test']['fail_term_meta'] ) || $current === $value ) {
			return false;
		}
		$GLOBALS['ec_artist_test']['meta'][ get_current_blog_id() ][ $term_id ][ $key ] = $value;
		return true;
	}
}
if ( ! function_exists( 'get_post' ) ) {
	function get_post( $post_id ) {
		if ( ! empty( $GLOBALS['ec_artist_test']['throw_get_post'] ) ) {
			throw new RuntimeException( 'post read failed' );
		}
		$state = $GLOBALS['ec_artist_test'];
		return $state['posts'][ $state['blog_id'] ][ $post_id ] ?? null;
	}
}
if ( ! function_exists( 'get_post_meta' ) ) {
	function get_post_meta( $post_id, $key, $single = false ) {
		unset( $single );
		$state = $GLOBALS['ec_artist_test'];
		return $state['post_meta'][ $state['blog_id'] ][ $post_id ][ $key ] ?? '';
	}
}
if ( ! function_exists( 'get_option' ) ) {
	function get_option( $key, $default = false ) {
		return $GLOBALS['ec_artist_test']['options'][ $key ] ?? $default; }
}
if ( ! function_exists( 'update_option' ) ) {
	function update_option( $key, $value, $autoload = null ) {
		unset( $autoload );
		$GLOBALS['ec_artist_test']['options'][ $key ] = $value;
		return true;
	}
}
if ( ! function_exists( 'delete_option' ) ) {
	function delete_option( $key ) {
		unset( $GLOBALS['ec_artist_test']['options'][ $key ] );
		return true;
	}
}
if ( ! function_exists( 'get_current_user_id' ) ) {
	function get_current_user_id() {
		return 12; }
}
if ( ! function_exists( 'add_action' ) ) {
	function add_action( $hook, $callback ) {
		$GLOBALS['ec_artist_test']['actions'][ $hook ][] = $callback; }
}
if ( ! function_exists( 'wp_register_ability' ) ) {
	function wp_register_ability( $name, $definition ) {
		$GLOBALS['ec_artist_test']['abilities'][ $name ] = $definition; }
}
if ( ! function_exists( 'wp_cache_delete' ) ) {
	function wp_cache_delete( $key, $group = '' ) {
		$GLOBALS['ec_artist_test']['cache_deletes'][] = array( $key, $group );
		return true; }
}

/** Stateful wpdb fake that enforces the persistence contracts under test. */
final class BookingWpdb {
	public $prefix                    = 'wp_7_';
	public $terms                     = 'wp_7_terms';
	public $insert_id                 = 0;
	public $last_error                = '';
	public $rows                      = array();
	public $schemas                   = array();
	public $engines                   = array();
	public $schema_omit               = array();
	public $schema_queries            = 0;
	public $dropped_indexes           = array();
	public $fail_reads                = false;
	public $fail_activity_reads       = false;
	public $fail_inserts              = false;
	public $fail_updates              = false;
	public $fail_engine_repair        = false;
	public $race_activity_insert      = false;
	public $race_booking_insert       = false;
	public $race_booking_hash         = null;
	public $race_activity_read_fail   = false;
	public $race_event_read_fail      = false;
	public $concurrent_role_migration = false;
	public $reads_before_failure      = null;
	public $last_query                = '';
	public $fail_activity_inserts     = false;
	public $fail_transaction_start    = false;
	public $fail_transaction_commit   = false;
	public $fail_transaction_rollback = false;
	public $rollback_queries          = 0;
	public $after_membership_lock     = null;
	public $after_venue_lock          = null;
	public $fail_venue_lock           = false;
	public $venue_lock_queries        = 0;
	public $transaction_active        = false;
	public $natural_key_reads_in_transaction = 0;
	private $transaction_snapshot     = null;

	public function get_charset_collate() {
		return 'DEFAULT CHARACTER SET utf8mb4'; }

	public function prepare( $query, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0]; }
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

	public function apply_schema( $sql ) {
		if ( ! preg_match( '/CREATE TABLE ([^\s(]+)/', $sql, $match ) ) {
			return; }
		$table                   = $match[1];
		$this->engines[ $table ] = $this->engines[ $table ] ?? ( preg_match( '/ENGINE=([a-zA-Z0-9_]+)/', $sql, $engine ) ? $engine[1] : '' );
		$this->schemas[ $table ] = $this->schemas[ $table ] ?? array(
			'columns' => array(),
			'indexes' => array(),
		);
		foreach ( preg_split( '/\R/', $sql ) as $line ) {
			$line = trim( rtrim( $line, ',' ) );
			if ( preg_match( '/^(PRIMARY KEY|UNIQUE KEY|KEY)\s*(?:([a-zA-Z0-9_]+))?\s*\(([^)]+)\)/i', $line, $index ) ) {
				$name    = 'PRIMARY KEY' === strtoupper( $index[1] ) ? 'PRIMARY' : $index[2];
				$unique  = 'KEY' !== strtoupper( $index[1] );
				$columns = array_map( 'trim', explode( ',', str_replace( '`', '', $index[3] ) ) );
				if ( ! isset( $this->schemas[ $table ]['indexes'][ $name ] ) && ! in_array( $name, $this->schema_omit[ $table ]['indexes'] ?? array(), true ) ) {
					$this->schemas[ $table ]['indexes'][ $name ] = array(
						'unique'  => $unique,
						'columns' => $columns,
					);
				}
			} elseif ( preg_match( '/^([a-z_]+)\s+(.+)$/', $line, $column ) && ! in_array( $column[1], $this->schema_omit[ $table ]['columns'] ?? array(), true ) ) {
				$definition = $column[2];
				preg_match( '/^(.+?)(?:\s+NOT NULL|\s+NULL|\s+DEFAULT|\s+AUTO_INCREMENT|$)/i', $definition, $type );
				preg_match( "/\sDEFAULT\s+(?:'([^']*)'|([^\s]+))/i", $definition, $default );
				if ( ! isset( $this->schemas[ $table ]['columns'][ $column[1] ] ) ) {
					$this->schemas[ $table ]['columns'][ $column[1] ] = array(
						'Type'    => strtolower( trim( $type[1] ) ),
						'Null'    => false !== stripos( $definition, 'NOT NULL' ) ? 'NO' : 'YES',
						'Default' => isset( $default[1] ) && '' !== $default[1] ? $default[1] : ( $default[2] ?? null ),
						'Extra'   => false !== stripos( $definition, 'AUTO_INCREMENT' ) ? 'auto_increment' : '',
					);
				}
			}
		}
	}

	public function insert( $table, $row ) {
		$this->last_error = '';
		if ( $this->fail_inserts || ( $this->fail_activity_inserts && false !== strpos( $table, 'ec_booking_activity' ) ) ) {
			$this->last_error = 'simulated insert failure';
			return false;
		}
		if ( false !== strpos( $table, 'ec_bookings' ) && null !== $row['inquiry_idempotency_key'] ) {
			foreach ( $this->rows[ $table ] ?? array() as $existing ) {
				if ( (int) $existing['venue_term_id'] === (int) $row['venue_term_id'] && $existing['inquiry_idempotency_key'] === $row['inquiry_idempotency_key'] ) {
					$this->last_error = 'duplicate venue inquiry idempotency key';
					return false;
				}
			}
		}
		if ( false !== strpos( $table, 'ec_booking_activity' ) && null !== $row['idempotency_key'] ) {
			foreach ( $this->rows[ $table ] ?? array() as $existing ) {
				if ( (int) $existing['booking_id'] === (int) $row['booking_id'] && $existing['idempotency_key'] === $row['idempotency_key'] ) {
					$this->last_error = 'duplicate booking idempotency key';
					return false;
				}
			}
		}
		$this->insert_id                          = count( $this->rows[ $table ] ?? array() ) + 1;
		$row['id']                                = $this->insert_id;
		$this->rows[ $table ][ $this->insert_id ] = $row;
		if ( false !== strpos( $table, 'ec_bookings' ) && $this->race_booking_insert ) {
			$this->race_booking_insert = false;
			if ( null !== $this->race_booking_hash ) {
				$this->rows[ $table ][ $this->insert_id ]['inquiry_request_hash'] = $this->race_booking_hash;
				$this->race_booking_hash = null;
			}
			$activity_table = $this->prefix . 'ec_booking_activity';
			$activity_id    = count( $this->rows[ $activity_table ] ?? array() ) + 1;
			$this->rows[ $activity_table ][ $activity_id ] = array(
				'id'              => $activity_id,
				'booking_id'      => $this->insert_id,
				'kind'            => 'inquiry_submitted',
				'actor_type'      => 'anonymous',
				'actor_id'        => null,
				'direction'       => null,
				'channel'         => null,
				'payload'         => '{"version":1,"data":{"status":"submitted"}}',
				'external_id'     => null,
				'idempotency_key' => 'race-winner',
				'occurred_at'     => gmdate( 'Y-m-d H:i:s' ),
				'created_at'      => gmdate( 'Y-m-d H:i:s' ),
			);
			$this->transaction_snapshot = $this->rows;
			$this->last_error          = 'simulated concurrent duplicate';
			return false;
		}
		if ( false !== strpos( $table, 'ec_booking_activity' ) && $this->race_activity_insert ) {
			$this->race_activity_insert = false;
			$this->last_error           = 'simulated concurrent duplicate';
			if ( $this->race_activity_read_fail ) {
				$this->fail_activity_reads = true;
			}
			return false;
		}
		return 1;
	}

	public function get_var( $query ) {
		$this->last_error = '';
		if ( preg_match( '/SELECT term_id FROM .* WHERE term_id = (\d+) FOR UPDATE/', $query, $match ) ) {
			++$this->venue_lock_queries;
			if ( $this->fail_venue_lock ) {
				$this->last_error = 'simulated venue lock failure';
				return null;
			}
			if ( is_callable( $this->after_venue_lock ) ) {
				$callback               = $this->after_venue_lock;
				$this->after_venue_lock = null;
				$callback();
			}
			return isset( $GLOBALS['ec_artist_test']['terms'][7][ (int) $match[1] ] ) ? (int) $match[1] : null;
		}
		if ( preg_match( "/SELECT id FROM .*ec_bookings WHERE venue_term_id = (\d+) AND inquiry_idempotency_key = '([^']+)'/", $query, $match ) ) {
			foreach ( $this->rows[ $this->prefix . 'ec_bookings' ] ?? array() as $row ) {
				if ( (int) $row['venue_term_id'] === (int) $match[1] && $row['inquiry_idempotency_key'] === stripslashes( $match[2] ) ) {
					return $row['id'];
				}
			}
			return null;
		}
		++$this->schema_queries;
		if ( $this->fail_reads ) {
			$this->last_error = 'simulated schema read failure';
			return null; }
		if ( preg_match( "/LIKE '([^']+)'/", $query, $match ) && isset( $this->schemas[ stripslashes( $match[1] ) ] ) ) {
			return stripslashes( $match[1] ); }
		return null;
	}

	public function get_row( $query, $output = null ) {
		unset( $output );
		$this->last_query = $query;
		$this->last_error = '';
		if ( preg_match( "/SHOW TABLE STATUS WHERE Name = '([^']+)'/", $query, $match ) ) {
			$table = stripslashes( $match[1] );
			return isset( $this->engines[ $table ] ) ? array( 'Engine' => $this->engines[ $table ] ) : null;
		}
		$is_activity = false !== strpos( $query, 'ec_booking_activity' );
		if ( null !== $this->reads_before_failure ) {
			if ( 0 === $this->reads_before_failure ) {
				$this->last_error = 'simulated delayed row read failure';
				return null;
			}
			--$this->reads_before_failure;
		}
		if ( $this->fail_reads || ( $is_activity && $this->fail_activity_reads ) ) {
			$this->last_error = 'simulated row read failure';
			return null;
		}
		$table = $is_activity ? $this->prefix . 'ec_booking_activity' : $this->prefix . 'ec_bookings';
		if ( preg_match( '/WHERE id = (\d+)/', $query, $match ) ) {
			return $this->rows[ $table ][ (int) $match[1] ] ?? null; }
		if ( preg_match( "/WHERE public_id = '([^']+)'/", $query, $match ) ) {
			foreach ( $this->rows[ $table ] ?? array() as $row ) {
				if ( stripslashes( $match[1] ) === $row['public_id'] ) {
					return $row; }
			}
		}
		if ( preg_match( "/WHERE venue_term_id = (\d+) AND inquiry_idempotency_key = '([^']+)'/", $query, $match ) ) {
			if ( $this->transaction_active ) {
				++$this->natural_key_reads_in_transaction;
			}
			foreach ( $this->rows[ $table ] ?? array() as $row ) {
				if ( (int) $row['venue_term_id'] === (int) $match[1] && stripslashes( $match[2] ) === $row['inquiry_idempotency_key'] ) {
					return $row;
				}
			}
		}
		if ( preg_match( "/WHERE booking_id = (\d+) AND idempotency_key = '([^']+)'/", $query, $match ) ) {
			foreach ( $this->rows[ $table ] ?? array() as $row ) {
				if ( (int) $row['booking_id'] === (int) $match[1] && stripslashes( $match[2] ) === $row['idempotency_key'] ) {
					return $row; }
			}
		}
		return null;
	}

	public function get_results( $query, $output = null ) {
		unset( $output );
		$this->last_query = $query;
		$this->last_error = '';
		if ( $this->fail_reads ) {
			$this->last_error = 'simulated result read failure';
			return null; }
		if ( preg_match( '/SHOW COLUMNS FROM `([^`]+)`/', $query, $match ) ) {
			++$this->schema_queries;
			$rows = array();
			foreach ( $this->schemas[ $match[1] ]['columns'] ?? array() as $name => $metadata ) {
				$rows[] = array_merge( array( 'Field' => $name ), $metadata );
			}
			return $rows;
		}
		if ( false !== strpos( $query, 'ec_venue_members' ) && false !== strpos( $query, 'FOR UPDATE' ) ) {
			if ( is_callable( $this->after_membership_lock ) ) {
				$callback                    = $this->after_membership_lock;
				$this->after_membership_lock = null;
				$callback();
			}
			return array_values( $this->rows[ $this->prefix . 'ec_venue_members' ] ?? array() );
		}
		if ( preg_match( '/SHOW INDEX FROM `([^`]+)`/', $query, $match ) ) {
			++$this->schema_queries;
			$rows = array();
			foreach ( $this->schemas[ $match[1] ]['indexes'] ?? array() as $name => $index ) {
				foreach ( $index['columns'] as $position => $column ) {
					$rows[] = array(
						'Key_name'     => $name,
						'Non_unique'   => $index['unique'] ? 0 : 1,
						'Seq_in_index' => $position + 1,
						'Column_name'  => $column,
					);
				}
			}
			return $rows;
		}
		$is_activity = false !== strpos( $query, 'ec_booking_activity' );
		$table       = $is_activity ? $this->prefix . 'ec_booking_activity' : $this->prefix . 'ec_bookings';
		$rows        = array_values( $this->rows[ $table ] ?? array() );
		$filters     = array( 'venue_term_id', 'artist_term_id', 'artist_profile_id', 'assignee_user_id', 'booking_id' );
		foreach ( $filters as $field ) {
			if ( preg_match( "/{$field} = (\\d+)/", $query, $filter ) ) {
				$rows = array_values(
					array_filter(
						$rows,
						static function ( $row ) use ( $field, $filter ) {
							return (int) ( $row[ $field ] ?? 0 ) === (int) $filter[1];
						}
					)
				);
			}
		}
		if ( preg_match( "/status = '([^']+)'/", $query, $filter ) ) {
			$rows = array_values(
				array_filter(
					$rows,
					static function ( $row ) use ( $filter ) {
						return stripslashes( $filter[1] ) === $row['status'];
					}
				)
			);
		}
		if ( preg_match( "/requested_start_at >= '([^']+)'/", $query, $filter ) ) {
			$rows = array_values(
				array_filter(
					$rows,
					static function ( $row ) use ( $filter ) {
						return null !== $row['requested_start_at'] && $row['requested_start_at'] >= stripslashes( $filter[1] );
					}
				)
			);
		}
		if ( preg_match( "/requested_end_at <= '([^']+)'/", $query, $filter ) ) {
			$rows = array_values(
				array_filter(
					$rows,
					static function ( $row ) use ( $filter ) {
						return null !== $row['requested_end_at'] && $row['requested_end_at'] <= stripslashes( $filter[1] );
					}
				)
			);
		}
		usort(
			$rows,
			static function ( $a, $b ) {
				$date_order = $b['created_at'] <=> $a['created_at'];
				return 0 !== $date_order ? $date_order : ( $b['id'] <=> $a['id'] );
			}
		);
		if ( preg_match( '/LIMIT (\d+) OFFSET (\d+)/', $query, $page ) ) {
			$rows = array_slice( $rows, (int) $page[2], (int) $page[1] );
		}
		return $rows;
	}

	public function query( $query ) {
		$this->last_query = $query;
		$this->last_error = '';
		if ( 'START TRANSACTION' === $query ) {
			if ( $this->fail_transaction_start ) {
				$this->last_error = 'simulated transaction start failure';
				return false;
			}
			$this->transaction_snapshot = $this->rows;
			$this->transaction_active   = true;
			return 1;
		}
		if ( 'COMMIT' === $query ) {
			if ( $this->fail_transaction_commit ) {
				$this->last_error = 'simulated transaction commit failure';
				return false;
			}
			$this->transaction_snapshot = null;
			$this->transaction_active   = false;
			return 1;
		}
		if ( 'ROLLBACK' === $query ) {
			++$this->rollback_queries;
			if ( $this->fail_transaction_rollback ) {
				$this->last_error = 'simulated transaction rollback failure';
				return false;
			}
			$this->rows                 = $this->transaction_snapshot;
			$this->transaction_snapshot = null;
			$this->transaction_active   = false;
			return 1;
		}
		if ( preg_match( "/UPDATE `([^`]+)` SET is_owner = IF\(role = 'owner', 1, 0\)/", $query, $migration ) ) {
			$this->rows[ $migration[1] ] = $this->rows[ $migration[1] ] ?? array();
			foreach ( $this->rows[ $migration[1] ] as &$row ) {
				$row['is_owner'] = 'owner' === ( $row['role'] ?? '' ) ? 1 : 0;
				if ( $this->concurrent_role_migration ) {
					unset( $row['role'] );
				}
			}
			unset( $row );
			if ( $this->concurrent_role_migration ) {
				$this->concurrent_role_migration = false;
				unset( $this->schemas[ $migration[1] ]['columns']['role'], $this->schemas[ $migration[1] ]['indexes']['venue_status_role'] );
				$this->last_error = 'simulated concurrent migration';
				return false;
			}
			return 1;
		}
		if ( preg_match( '/ALTER TABLE `([^`]+)` ENGINE=([a-zA-Z0-9_]+)/', $query, $engine ) ) {
			if ( $this->fail_engine_repair ) {
				$this->last_error = 'simulated engine conversion failure';
				return false;
			}
			$this->engines[ $engine[1] ] = $engine[2];
			return 1;
		}
		if ( preg_match( '/ALTER TABLE `([^`]+)` DROP COLUMN `([^`]+)`/', $query, $drop_column ) ) {
			unset( $this->schemas[ $drop_column[1] ]['columns'][ $drop_column[2] ] );
			$this->rows[ $drop_column[1] ] = $this->rows[ $drop_column[1] ] ?? array();
			foreach ( $this->rows[ $drop_column[1] ] as &$row ) {
				unset( $row[ $drop_column[2] ] );
			}
			unset( $row );
			return 1;
		}
		if ( preg_match( '/ALTER TABLE `([^`]+)` DROP (?:INDEX `([^`]+)`|PRIMARY KEY)/', $query, $drop ) ) {
			$name = ! empty( $drop[2] ) ? $drop[2] : 'PRIMARY';
			unset( $this->schemas[ $drop[1] ]['indexes'][ $name ] );
			if ( 'PRIMARY' === $name && preg_match( '/ADD PRIMARY KEY \(([^)]+)\)/', $query, $primary ) ) {
				$this->schemas[ $drop[1] ]['indexes']['PRIMARY'] = array(
					'unique'  => true,
					'columns' => array_map( 'trim', explode( ',', str_replace( '`', '', $primary[1] ) ) ),
				);
			}
			$this->dropped_indexes[] = array(
				'table' => $drop[1],
				'index' => $name,
			);
			return 1;
		}
		if ( $this->fail_updates ) {
			$this->last_error = 'simulated update failure';
			return false; }
		if ( ! preg_match( '/WHERE id = (\d+) AND version = (\d+)/', $query, $match ) ) {
			return false; }
		$id       = (int) $match[1];
		$expected = (int) $match[2];
		$table    = $this->prefix . 'ec_bookings';
		if ( ! isset( $this->rows[ $table ][ $id ] ) || (int) $this->rows[ $table ][ $id ]['version'] !== $expected ) {
			if ( $this->race_event_read_fail ) {
				$this->reads_before_failure = 1;
			}
			return 0; }
		if ( false !== strpos( $query, 'event_id IS NULL' ) && null !== $this->rows[ $table ][ $id ]['event_id'] ) {
			return 0; }
		$set = substr( $query, strpos( $query, ' SET ' ) + 5, strpos( $query, ' WHERE ' ) - strpos( $query, ' SET ' ) - 5 );
		preg_match_all( "/([a-z_]+) = (version \\+ 1|NULL|\\d+|'(?:\\\\.|[^'])*')(?=, [a-z_]+ = |$)/", $set, $assignments, PREG_SET_ORDER );
		foreach ( $assignments as $assignment ) {
			$column = $assignment[1];
			$value  = $assignment[2];
			if ( 'version + 1' === $value ) {
				++$this->rows[ $table ][ $id ]['version'];
			} elseif ( 'NULL' === $value ) {
				$this->rows[ $table ][ $id ][ $column ] = null;
			} elseif ( "'" === substr( $value, 0, 1 ) ) {
				$this->rows[ $table ][ $id ][ $column ] = stripslashes( substr( $value, 1, -1 ) );
			} else {
				$this->rows[ $table ][ $id ][ $column ] = (int) $value;
			}
		}
		return 1;
	}
}

require_once dirname( __DIR__ ) . '/inc/Core/BookingSchema.php';
require_once dirname( __DIR__ ) . '/inc/Core/VenueMembershipRepository.php';
require_once dirname( __DIR__ ) . '/inc/Core/VenueAuthorization.php';
require_once dirname( __DIR__ ) . '/inc/Core/BookingRepository.php';
require_once dirname( __DIR__ ) . '/inc/Core/BookingActivityRepository.php';
require_once dirname( __DIR__ ) . '/inc/Core/BookingLifecycle.php';
require_once dirname( __DIR__ ) . '/inc/Core/VenueBookingConfig.php';
require_once dirname( __DIR__ ) . '/inc/Abilities/VenueBookingAbilities.php';

final class BookingTestAuthorization extends VenueAuthorization {
	public $calls = array();
	public $allowed = array();
	public function __construct( array $allowed = array() ) {
		$this->allowed = array_merge( array( '12:55' => true ), $allowed );
	}
	public function authorize( int $user_id, int $venue_term_id, string $action ) {
		$this->calls[] = array( $user_id, $venue_term_id, $action );
		return ! empty( $this->allowed[ $user_id . ':' . $venue_term_id ] ) ? true : new WP_Error( 'venue_action_forbidden' );
	}
}

final class BookingTestConfig extends VenueBookingConfig {
	public function get( int $venue_term_id ) {
		unset( $venue_term_id );
		$GLOBALS['wpdb']->last_error = 'simulated config read failure';
		return array( 'enabled' => true );
	}
}

final class BookingFoundationTest extends TestCase {
	protected function setUp(): void {
		$GLOBALS['ec_artist_test'] = array(
			'blog_id'   => 7,
			'stack'     => array(),
			'uuid'      => 0,
			'options'   => array(),
			'dbdelta'   => array(),
			'abilities' => array(),
			'actions'   => array(),
			'cache_deletes' => array(),
			'terms'     => array(
				1 => array(
					101 => (object) array(
						'term_id'  => 101,
						'taxonomy' => 'artist',
						'name'     => 'Canonical Artist',
					),
				),
				7 => array(
					55 => (object) array(
						'term_id'  => 55,
						'taxonomy' => 'venue',
						'name'     => 'The Room',
					),
					56 => (object) array(
						'term_id'  => 56,
						'taxonomy' => 'artist',
						'name'     => 'Wrong Type',
					),
				),
			),
			'meta'      => array(
				1 => array( 101 => array( '_artist_profile_id' => 501 ) ),
				7 => array( 55 => array( '_extrachill_booking_config' => array( 'enabled' => true ) ) ),
			),
			'posts'     => array(
				4 => array(
					501 => (object) array(
						'ID'          => 501,
						'post_type'   => 'artist_profile',
						'post_status' => 'publish',
						'post_title'  => 'Canonical Artist',
					),
					502 => (object) array(
						'ID'          => 502,
						'post_type'   => 'artist_profile',
						'post_status' => 'publish',
						'post_title'  => 'Unbound Artist',
					),
				),
				7 => array(
					900 => (object) array(
						'ID'          => 900,
						'post_type'   => 'data_machine_events',
						'post_status' => 'publish',
					),
					901 => (object) array(
						'ID'          => 901,
						'post_type'   => 'post',
						'post_status' => 'publish',
					),
				),
			),
			'post_meta' => array( 4 => array( 501 => array( '_artist_term_id' => 101 ) ) ),
		);
		$GLOBALS['wpdb']           = new BookingWpdb();
	}

	private function create_booking( array $overrides = array() ) {
		return ( new BookingRepository() )->create(
			array_merge(
				array(
					'venue_term_id' => 55,
					'artist_name'   => 'New Band',
					'intake'        => array( 'draw' => 100 ),
				),
				$overrides
			)
		);
	}

	public function test_schema_health_validates_attributes_and_site_scope(): void {
		$this->assertTrue( BookingSchema::install() );
		$this->assertSame( BookingSchema::SCHEMA_VERSION, get_option( BookingSchema::VERSION_OPTION ) );
		$this->assertTrue( BookingSchema::health() );
		$this->assertArrayHasKey( 'is_owner', $GLOBALS['wpdb']->schemas['wp_7_ec_venue_members']['columns'] );
		$this->assertArrayNotHasKey( 'role', $GLOBALS['wpdb']->schemas['wp_7_ec_venue_members']['columns'] );

		$columns                   =& $GLOBALS['wpdb']->schemas['wp_7_ec_bookings']['columns'];
		$columns['status']['Type'] = 'text';
		$this->assertSame( 'type', BookingSchema::health()->get_error_data()['attribute'] );
		$columns['status']['Type'] = 'varchar(32)';
		$columns['status']['Null'] = 'YES';
		$this->assertSame( 'nullable', BookingSchema::health()->get_error_data()['attribute'] );
		$columns['status']['Null']    = 'NO';
		$columns['status']['Default'] = 'pending';
		$this->assertSame( 'default', BookingSchema::health()->get_error_data()['attribute'] );
		$columns['status']['Default']  = 'submitted';
		$columns['version']['Default'] = '2';
		$this->assertSame( 'default', BookingSchema::health()->get_error_data()['attribute'] );
		$columns['version']['Default'] = '1';
		$columns['id']['Extra']        = '';
		$this->assertSame( 'extra', BookingSchema::health()->get_error_data()['attribute'] );
		$columns['id']['Extra'] = 'auto_increment';
		$this->assertTrue( BookingSchema::health() );

		$GLOBALS['wpdb']->prefix = 'wp_12_';
		$this->assertSame( 'wp_12_ec_bookings', BookingSchema::bookings_table() );
		$this->assertSame( 'wp_12_ec_venue_members', BookingSchema::memberships_table() );
		$this->assertSame( 'booking_schema_table_missing', BookingSchema::health()->get_error_code() );
	}

	public function test_role_schema_migrates_only_structural_ownership(): void {
		$this->assertTrue( BookingSchema::install() );
		$table = BookingSchema::memberships_table();
		$GLOBALS['wpdb']->schemas[ $table ]['columns']['role']                 = array(
			'Type'    => 'varchar(32)',
			'Null'    => 'NO',
			'Default' => null,
			'Extra'   => '',
		);
		$GLOBALS['wpdb']->schemas[ $table ]['indexes']['venue_status_role']    = array(
			'unique'  => false,
			'columns' => array( 'venue_term_id', 'status', 'role' ),
		);
		$GLOBALS['wpdb']->rows[ $table ]                                       = array(
			1 => array(
				'role'     => 'owner',
				'is_owner' => 0,
			),
			2 => array(
				'role'     => 'marketing',
				'is_owner' => 1,
			),
		);
		$GLOBALS['ec_artist_test']['options'][ BookingSchema::VERSION_OPTION ] = '2';

		$this->assertTrue( BookingSchema::maybe_install() );
		$this->assertSame( 1, $GLOBALS['wpdb']->rows[ $table ][1]['is_owner'] );
		$this->assertSame( 0, $GLOBALS['wpdb']->rows[ $table ][2]['is_owner'] );
		$this->assertArrayNotHasKey( 'role', $GLOBALS['wpdb']->rows[ $table ][1] );
		$this->assertArrayNotHasKey( 'role', $GLOBALS['wpdb']->schemas[ $table ]['columns'] );
		$this->assertArrayNotHasKey( 'venue_status_role', $GLOBALS['wpdb']->schemas[ $table ]['indexes'] );
		$this->assertSame( BookingSchema::SCHEMA_VERSION, get_option( BookingSchema::VERSION_OPTION ) );
	}

	public function test_concurrent_completed_role_migration_is_treated_as_success(): void {
		$this->assertTrue( BookingSchema::install() );
		$table = BookingSchema::memberships_table();
		$GLOBALS['wpdb']->schemas[ $table ]['columns']['role']                 = array(
			'Type'    => 'varchar(32)',
			'Null'    => 'NO',
			'Default' => null,
			'Extra'   => '',
		);
		$GLOBALS['wpdb']->schemas[ $table ]['indexes']['venue_status_role']    = array(
			'unique'  => false,
			'columns' => array( 'venue_term_id', 'status', 'role' ),
		);
		$GLOBALS['wpdb']->rows[ $table ]                                       = array(
			1 => array(
				'role'     => 'owner',
				'is_owner' => 0,
			),
		);
		$GLOBALS['wpdb']->concurrent_role_migration                            = true;
		$GLOBALS['ec_artist_test']['options'][ BookingSchema::VERSION_OPTION ] = '2';

		$this->assertTrue( BookingSchema::maybe_install() );
		$this->assertSame( 1, $GLOBALS['wpdb']->rows[ $table ][1]['is_owner'] );
		$this->assertSame( BookingSchema::SCHEMA_VERSION, get_option( BookingSchema::VERSION_OPTION ) );
		$this->assertFalse( get_option( BookingSchema::FAILURE_OPTION, false ) );
	}

	public function test_unrepairable_column_attributes_are_not_stamped(): void {
		$this->assertTrue( BookingSchema::install() );
		$GLOBALS['wpdb']->schemas['wp_7_ec_bookings']['columns']['status']['Null'] = 'YES';
		$GLOBALS['ec_artist_test']['options'][ BookingSchema::VERSION_OPTION ]     = '';
		$result = BookingSchema::maybe_install();
		$this->assertSame( 'booking_schema_column_invalid', $result->get_error_code() );
		$this->assertSame( 'nullable', $result->get_error_data()['attribute'] );
		$this->assertSame( '', get_option( BookingSchema::VERSION_OPTION, '' ) );
	}

	public function test_partial_schema_is_not_stamped_and_repeat_install_repairs_it(): void {
		$GLOBALS['wpdb']->schema_omit['wp_7_ec_bookings']['columns'] = array( 'event_id' );
		$result = BookingSchema::install();
		$this->assertSame( 'booking_schema_columns_missing', $result->get_error_code() );
		$this->assertSame( '', get_option( BookingSchema::VERSION_OPTION, '' ) );
		$this->assertSame( 'booking_schema_columns_missing', get_option( BookingSchema::FAILURE_OPTION )['code'] );
		$GLOBALS['wpdb']->schema_omit = array();
		$this->assertTrue( BookingSchema::maybe_install() );
		$this->assertTrue( BookingSchema::health() );
		$this->assertSame( BookingSchema::SCHEMA_VERSION, get_option( BookingSchema::VERSION_OPTION ) );
	}

	public function test_malformed_required_indexes_are_dropped_and_repaired(): void {
		$this->assertTrue( BookingSchema::install() );
		$bookings = 'wp_7_ec_bookings';
		$activity = 'wp_7_ec_booking_activity';
		$members  = 'wp_7_ec_venue_members';
		$GLOBALS['wpdb']->schemas[ $bookings ]['indexes']['PRIMARY']['columns']             = array( 'id', 'public_id' );
		$GLOBALS['wpdb']->schemas[ $bookings ]['indexes']['public_id']['unique']            = false;
		$GLOBALS['wpdb']->schemas[ $activity ]['indexes']['booking_idempotency']['columns'] = array( 'idempotency_key' );
		$GLOBALS['wpdb']->schemas[ $members ]['indexes']['venue_user']['unique']            = false;
		$GLOBALS['wpdb']->schemas[ $activity ]['indexes']['operator_extra']                 = array(
			'unique'  => false,
			'columns' => array( 'created_at' ),
		);
		$GLOBALS['ec_artist_test']['options'][ BookingSchema::VERSION_OPTION ]              = '';
		$GLOBALS['wpdb']->dropped_indexes = array();

		$this->assertTrue( BookingSchema::maybe_install() );
		$this->assertTrue( BookingSchema::health() );
		$this->assertSame( array( 'id' ), $GLOBALS['wpdb']->schemas[ $bookings ]['indexes']['PRIMARY']['columns'] );
		$this->assertTrue( $GLOBALS['wpdb']->schemas[ $bookings ]['indexes']['public_id']['unique'] );
		$this->assertSame( array( 'booking_id', 'idempotency_key' ), $GLOBALS['wpdb']->schemas[ $activity ]['indexes']['booking_idempotency']['columns'] );
		$this->assertTrue( $GLOBALS['wpdb']->schemas[ $members ]['indexes']['venue_user']['unique'] );
		$this->assertArrayHasKey( 'operator_extra', $GLOBALS['wpdb']->schemas[ $activity ]['indexes'] );
		$this->assertSame(
			array(
				array(
					'table' => $bookings,
					'index' => 'PRIMARY',
				),
				array(
					'table' => $bookings,
					'index' => 'public_id',
				),
				array(
					'table' => $activity,
					'index' => 'booking_idempotency',
				),
				array(
					'table' => $members,
					'index' => 'venue_user',
				),
			),
			$GLOBALS['wpdb']->dropped_indexes
		);
	}

	public function test_current_version_maybe_install_performs_no_schema_queries(): void {
		$GLOBALS['ec_artist_test']['options'][ BookingSchema::VERSION_OPTION ] = BookingSchema::SCHEMA_VERSION;
		$GLOBALS['wpdb']->schema_queries                                       = 0;
		$GLOBALS['ec_artist_test']['dbdelta']                                  = array();
		$this->assertTrue( BookingSchema::maybe_install() );
		$this->assertSame( 0, $GLOBALS['wpdb']->schema_queries );
		$this->assertSame( array(), $GLOBALS['ec_artist_test']['dbdelta'] );
	}

	public function test_membership_table_engine_is_repaired_before_version_stamp(): void {
		$this->assertTrue( BookingSchema::install() );
		$table                              = BookingSchema::memberships_table();
		$GLOBALS['wpdb']->engines[ $table ] = 'MyISAM';
		$GLOBALS['ec_artist_test']['options'][ BookingSchema::VERSION_OPTION ] = '';
		$this->assertTrue( BookingSchema::maybe_install() );
		$this->assertSame( 'INNODB', strtoupper( $GLOBALS['wpdb']->engines[ $table ] ) );

		$GLOBALS['wpdb']->engines[ $table ]                                    = 'MyISAM';
		$GLOBALS['wpdb']->fail_engine_repair                                   = true;
		$GLOBALS['ec_artist_test']['options'][ BookingSchema::VERSION_OPTION ] = '';
		$result = BookingSchema::maybe_install();
		$this->assertSame( 'booking_schema_engine_repair_failed', $result->get_error_code() );
		$this->assertSame( '', get_option( BookingSchema::VERSION_OPTION, '' ) );
	}

	public function test_aggregate_table_engines_are_repaired_and_failures_are_not_stamped(): void {
		$this->assertTrue( BookingSchema::install() );
		$bookings = BookingSchema::bookings_table();
		$activity = BookingSchema::activity_table();
		$GLOBALS['wpdb']->engines[ $bookings ] = 'MyISAM';
		$GLOBALS['wpdb']->engines[ $activity ] = 'MyISAM';
		$GLOBALS['ec_artist_test']['options'][ BookingSchema::VERSION_OPTION ] = '';
		$this->assertTrue( BookingSchema::maybe_install() );
		$this->assertSame( 'INNODB', strtoupper( $GLOBALS['wpdb']->engines[ $bookings ] ) );
		$this->assertSame( 'INNODB', strtoupper( $GLOBALS['wpdb']->engines[ $activity ] ) );

		$GLOBALS['wpdb']->engines[ $bookings ] = 'MyISAM';
		$GLOBALS['wpdb']->fail_engine_repair = true;
		$GLOBALS['ec_artist_test']['options'][ BookingSchema::VERSION_OPTION ] = '';
		$this->assertSame( 'booking_schema_engine_repair_failed', BookingSchema::maybe_install()->get_error_code() );
		$this->assertSame( '', get_option( BookingSchema::VERSION_OPTION, '' ) );
	}

	public function test_missing_unique_index_and_schema_read_errors_remain_retryable(): void {
		$GLOBALS['wpdb']->schema_omit['wp_7_ec_booking_activity']['indexes'] = array( 'booking_idempotency' );
		$this->assertSame( 'booking_schema_index_missing', BookingSchema::install()->get_error_code() );
		$this->assertSame( '', get_option( BookingSchema::VERSION_OPTION, '' ) );
		$GLOBALS['wpdb']->fail_reads = true;
		$this->assertSame( 'booking_schema_db_error', BookingSchema::install()->get_error_code() );
	}

	public function test_json_encoding_failure_never_writes_or_increments(): void {
		$recursive         = array();
		$recursive['self'] =& $recursive;
		$result            = $this->create_booking( array( 'intake' => $recursive ) );
		$this->assertSame( 'booking_payload_encode_failed', $result->get_error_code() );
		$this->assertSame( array(), $GLOBALS['wpdb']->rows );

		$booking = $this->create_booking();
		$result  = ( new BookingRepository() )->update( $booking['id'], array( 'deal' => $recursive ), 1 );
		$this->assertSame( 'booking_payload_encode_failed', $result->get_error_code() );
		$this->assertSame( 1, ( new BookingRepository() )->get( $booking['id'] )['version'] );
		$result = ( new BookingActivityRepository() )->append(
			array(
				'booking_id' => $booking['id'],
				'kind'       => 'note',
				'payload'    => $recursive,
			)
		);
		$this->assertSame( 'booking_activity_payload_encode_failed', $result->get_error_code() );
	}

	public function test_corrupt_and_unsupported_json_are_explicit_read_errors(): void {
		$booking = $this->create_booking();
		$table   = BookingSchema::bookings_table();
		$GLOBALS['wpdb']->rows[ $table ][ $booking['id'] ]['intake_payload'] = '{bad';
		$this->assertSame( 'booking_payload_invalid_json', ( new BookingRepository() )->get( $booking['id'] )->get_error_code() );
		$GLOBALS['wpdb']->rows[ $table ][ $booking['id'] ]['intake_payload'] = '{"version":2,"data":{}}';
		$this->assertSame( 'booking_payload_version_unsupported', ( new BookingRepository() )->get( $booking['id'] )->get_error_code() );
		$GLOBALS['wpdb']->rows[ $table ][ $booking['id'] ]['intake_payload'] = '{"version":"1junk","data":{}}';
		$this->assertSame( 'booking_payload_version_unsupported', ( new BookingRepository() )->get( $booking['id'] )->get_error_code() );

		$activity = array(
			'id'         => 1,
			'booking_id' => 1,
			'actor_id'   => null,
			'payload'    => '{bad',
		);
		$this->assertSame( 'booking_activity_payload_invalid_json', ( new BookingActivityRepository() )->hydrate( $activity )->get_error_code() );
		$activity['payload'] = '{"version":9,"data":{}}';
		$this->assertSame( 'booking_activity_payload_version_unsupported', ( new BookingActivityRepository() )->hydrate( $activity )->get_error_code() );
		$activity['payload'] = '{"version":"1junk","data":{}}';
		$this->assertSame( 'booking_activity_payload_version_unsupported', ( new BookingActivityRepository() )->hydrate( $activity )->get_error_code() );
	}

	public function test_artist_identity_states_and_profile_only_resolution(): void {
		$unresolved = $this->create_booking();
		$this->assertNull( $unresolved['artist_term_id'] );
		$this->assertNull( $unresolved['artist_profile_id'] );
		$canonical = $this->create_booking(
			array(
				'artist_term_id' => 101,
				'artist_name'    => '',
			)
		);
		$this->assertSame( 101, $canonical['artist_term_id'] );
		$profile = $this->create_booking(
			array(
				'artist_profile_id' => 501,
				'artist_name'       => '',
			)
		);
		$this->assertSame( 101, $profile['artist_term_id'] );
		$this->assertSame( 501, $profile['artist_profile_id'] );
		$unbound = $this->create_booking(
			array(
				'artist_profile_id' => 502,
				'artist_name'       => '',
			)
		);
		$this->assertNull( $unbound['artist_term_id'] );
		$this->assertSame( 502, $unbound['artist_profile_id'] );
	}

	public function test_profile_must_be_published_and_bindings_must_be_bidirectional(): void {
		$GLOBALS['ec_artist_test']['posts'][4][501]->post_status = 'draft';
		$this->assertSame( 'invalid_booking_artist_profile', $this->create_booking( array( 'artist_profile_id' => 501 ) )->get_error_code() );
		$GLOBALS['ec_artist_test']['posts'][4][501]->post_status = 'trash';
		$this->assertSame( 'invalid_booking_artist_profile', $this->create_booking( array( 'artist_profile_id' => 501 ) )->get_error_code() );
		$GLOBALS['ec_artist_test']['posts'][4][501]->post_status = 'publish';
		unset( $GLOBALS['ec_artist_test']['post_meta'][4][501]['_artist_term_id'] );
		$this->assertSame(
			'booking_artist_identity_mismatch',
			$this->create_booking(
				array(
					'artist_term_id'    => 101,
					'artist_profile_id' => 501,
				)
			)->get_error_code()
		);
		$GLOBALS['ec_artist_test']['post_meta'][4][501]['_artist_term_id'] = 999;
		$this->assertSame(
			'booking_artist_identity_mismatch',
			$this->create_booking(
				array(
					'artist_term_id'    => 101,
					'artist_profile_id' => 501,
				)
			)->get_error_code()
		);
		$GLOBALS['ec_artist_test']['post_meta'][4][501]['_artist_term_id'] = 101;
		$GLOBALS['ec_artist_test']['meta'][1][101]['_artist_profile_id']   = 999;
		$this->assertSame(
			'booking_artist_identity_mismatch',
			$this->create_booking(
				array(
					'artist_term_id'    => 101,
					'artist_profile_id' => 501,
				)
			)->get_error_code()
		);
	}

	public function test_artist_blog_switches_are_restored_on_success_and_exception(): void {
		$this->create_booking( array( 'artist_profile_id' => 501 ) );
		$this->assertSame( 7, get_current_blog_id() );
		$GLOBALS['ec_artist_test']['throw_get_post'] = true;
		try {
			$this->create_booking( array( 'artist_profile_id' => 501 ) );
			$this->fail( 'Expected profile read exception.' );
		} catch ( RuntimeException $exception ) {
			$this->assertSame( 'post read failed', $exception->getMessage() );
		}
		$this->assertSame( 7, get_current_blog_id() );
	}

	public function test_event_handoff_is_null_only_validated_and_idempotent(): void {
		$repository = new BookingRepository();
		$booking    = $this->create_booking();
		$claimed    = $repository->claim_event( $booking['id'], 900, 1 );
		$this->assertSame( 900, $claimed['event_id'] );
		$this->assertSame( 2, $claimed['version'] );
		$this->assertSame( 900, $repository->claim_event( $booking['id'], 900, 1 )['event_id'] );
		$this->assertSame( 'booking_event_already_linked', $repository->claim_event( $booking['id'], 902, 2 )->get_error_code() );
		$unclaimed = $this->create_booking();
		$this->assertSame( 'invalid_booking_event', $repository->claim_event( $unclaimed['id'], 999, 1 )->get_error_code() );
		$this->assertSame( 'invalid_booking_event', $repository->claim_event( $unclaimed['id'], 901, 1 )->get_error_code() );
		$this->assertSame( 'empty_booking_update', $repository->update( $booking['id'], array( 'event_id' => 900 ), 2 )->get_error_code() );
	}

	public function test_event_claim_distinguishes_stale_version_and_missing_booking(): void {
		$repository = new BookingRepository();
		$booking    = $this->create_booking();
		$updated    = $repository->update( $booking['id'], array( 'contact_name' => 'Updated' ), 1 );
		$this->assertSame( 2, $updated['version'] );
		$this->assertSame( 'booking_version_conflict', $repository->claim_event( $booking['id'], 900, 1 )->get_error_code() );
		$GLOBALS['wpdb']->race_event_read_fail = true;
		$this->assertSame( 'booking_read_failed', $repository->claim_event( $booking['id'], 900, 1 )->get_error_code() );
		$GLOBALS['wpdb']->fail_reads           = false;
		$GLOBALS['wpdb']->reads_before_failure = null;
		$this->assertSame( 'booking_not_found', $repository->claim_event( 999, 900, 1 )->get_error_code() );
	}

	public function test_activity_idempotency_is_booking_scoped_and_orphans_are_rejected(): void {
		$activity = new BookingActivityRepository();
		$one      = $this->create_booking();
		$two      = $this->create_booking();
		$input    = array(
			'booking_id'      => $one['id'],
			'kind'            => 'inquiry_received',
			'idempotency_key' => 'intake:request-1',
			'payload'         => array( 'source' => 'form' ),
		);
		$first    = $activity->append( $input );
		$retry    = $activity->append( $input );
		$this->assertSame( $first['id'], $retry['id'] );
		$other = $activity->append( array_merge( $input, array( 'booking_id' => $two['id'] ) ) );
		$this->assertNotSame( $first['id'], $other['id'] );
		$this->assertSame( 'booking_activity_orphan', $activity->append( array_merge( $input, array( 'booking_id' => 999 ) ) )->get_error_code() );
	}

	public function test_activity_duplicate_insert_race_returns_winner_or_read_error(): void {
		$booking                               = $this->create_booking();
		$activity                              = new BookingActivityRepository();
		$GLOBALS['wpdb']->race_activity_insert = true;
		$result                                = $activity->append(
			array(
				'booking_id'      => $booking['id'],
				'kind'            => 'note',
				'idempotency_key' => 'note:race-1',
			)
		);
		$this->assertIsArray( $result );
		$this->assertSame( 'note:race-1', $result['idempotency_key'] );

		$GLOBALS['wpdb']->race_activity_insert    = true;
		$GLOBALS['wpdb']->race_activity_read_fail = true;
		$result                                   = $activity->append(
			array(
				'booking_id'      => $booking['id'],
				'kind'            => 'note',
				'idempotency_key' => 'note:race-2',
			)
		);
		$this->assertSame( 'booking_activity_read_failed', $result->get_error_code() );
	}

	public function test_activity_reports_read_and_write_failures_distinctly(): void {
		$booking                              = $this->create_booking();
		$activity                             = new BookingActivityRepository();
		$GLOBALS['wpdb']->fail_activity_reads = true;
		$result                               = $activity->append(
			array(
				'booking_id'      => $booking['id'],
				'kind'            => 'note',
				'idempotency_key' => 'note:1',
			)
		);
		$this->assertSame( 'booking_activity_read_failed', $result->get_error_code() );
		$GLOBALS['wpdb']->fail_activity_reads = false;
		$GLOBALS['wpdb']->fail_inserts        = true;
		$result                               = $activity->append(
			array(
				'booking_id' => $booking['id'],
				'kind'       => 'note',
			)
		);
		$this->assertSame( 'booking_activity_write_failed', $result->get_error_code() );
		$GLOBALS['wpdb']->fail_inserts = false;
		$GLOBALS['wpdb']->fail_reads   = true;
		$result                        = $activity->append(
			array(
				'booking_id' => $booking['id'],
				'kind'       => 'note',
			)
		);
		$this->assertSame( 'booking_activity_booking_read_failed', $result->get_error_code() );
	}

	public function test_config_handles_unchanged_values_and_rejects_wrong_term_or_versions(): void {
		$service = new VenueBookingConfig();
		$config  = $service->normalize( array( 'enabled' => true ) );
		$this->assertIsArray( $config );
		$this->assertSame( $config, $service->normalize( $config ) );
		$this->assertSame( 'invalid_booking_config_venue', $service->get( 56 )->get_error_code() );
		$this->assertSame( 'booking_config_version_unsupported', $service->normalize( array( 'version' => 2 ) )->get_error_code() );
		$this->assertSame( 'booking_config_version_unsupported', $service->normalize( array( 'version' => '1junk' ) )->get_error_code() );
		$this->assertSame( 'booking_config_section_version_unsupported', $service->normalize( array( 'intake' => array( 'version' => 2 ) ) )->get_error_code() );
		$GLOBALS['ec_artist_test']['meta'][7][55][ VenueBookingConfig::META_KEY ] = array( 'version' => 99 );
		$this->assertSame( 'booking_config_version_unsupported', $service->get( 55 )->get_error_code() );
	}

	public function test_config_detects_truncated_collisions_and_validates_channels_currency(): void {
		$service = new VenueBookingConfig();
		$prefix  = str_repeat( 'a', 64 );
		$result  = $service->normalize(
			array(
				'spaces' => array(
					array(
						'key'  => $prefix . 'x',
						'name' => 'One',
					),
					array(
						'key'  => $prefix . 'y',
						'name' => 'Two',
					),
				),
			)
		);
		$this->assertSame( 'invalid_booking_space', $result->get_error_code() );
		$result = $service->normalize( array( 'intake' => array( 'fields' => array( array( 'key' => $prefix . 'x' ), array( 'key' => $prefix . 'y' ) ) ) ) );
		$this->assertSame( 'invalid_booking_intake_field', $result->get_error_code() );
		$this->assertSame(
			'invalid_booking_intake_field',
			$service->normalize(
				array(
					'intake' => array(
						'fields' => array(
							array(
								'key'   => 'bio',
								'label' => '<b></b>',
							),
						),
					),
				)
			)->get_error_code()
		);
		$this->assertSame( 'invalid_booking_marketing_channels', $service->normalize( array( 'marketing_channels' => array_fill( 0, 21, 'email' ) ) )->get_error_code() );
		$this->assertSame( 'invalid_booking_marketing_channel', $service->normalize( array( 'marketing_channels' => array( $prefix . 'x', $prefix . 'y' ) ) )->get_error_code() );
		$this->assertSame( 'invalid_booking_currency', $service->normalize( array( 'default_deal' => array( 'currency' => 'US1' ) ) )->get_error_code() );
	}

	public function test_repository_rejects_invalid_ids_dates_filters_and_normalizes_updates(): void {
		$repository = new BookingRepository();
		$this->assertSame( 'invalid_booking_id', $this->create_booking( array( 'venue_term_id' => -55 ) )->get_error_code() );
		$this->assertSame( 'invalid_booking_id', $this->create_booking( array( 'assignee_user_id' => -2 ) )->get_error_code() );
		$this->assertSame(
			'invalid_booking_date_range',
			$this->create_booking(
				array(
					'requested_start_at' => '2026-08-02 00:00:00',
					'requested_end_at'   => '2026-08-01 00:00:00',
				)
			)->get_error_code()
		);
		$this->assertSame(
			'invalid_booking_datetime',
			$repository->list(
				array(
					'venue_term_id'      => 55,
					'requested_start_at' => 'tomorrow',
				)
			)->get_error_code()
		);
		$booking = $this->create_booking();
		$updated = $repository->update(
			$booking['id'],
			array(
				'artist_name' => str_repeat( 'x', 300 ),
				'space_key'   => str_repeat( 'y', 80 ),
				'intake'      => array(
					'one' => 1,
					'two' => 2,
				),
			),
			1
		);
		$this->assertSame( 255, strlen( $updated['artist_name'] ) );
		$this->assertSame( 64, strlen( $updated['space_key'] ) );
		$this->assertSame( 'submitted', $updated['status'] );
		$this->assertSame(
			array(
				'one' => 1,
				'two' => 2,
			),
			$updated['intake']['data']
		);
	}

	public function test_repository_list_applies_filters_order_and_bounds(): void {
		$repository = new BookingRepository();
		$first      = $this->create_booking(
			array(
				'status'             => 'submitted',
				'requested_start_at' => '2026-08-01 00:00:00',
				'requested_end_at'   => '2026-08-02 00:00:00',
			)
		);
		$confirmed = $this->create_booking(
			array(
				'requested_start_at' => '2026-09-01 00:00:00',
				'requested_end_at'   => '2026-09-02 00:00:00',
			)
		);
		$GLOBALS['wpdb']->rows[ BookingSchema::bookings_table() ][ $confirmed['id'] ]['status'] = 'confirmed';
		$latest = $this->create_booking(
			array(
				'status'             => 'submitted',
				'requested_start_at' => '2026-10-01 00:00:00',
				'requested_end_at'   => '2026-10-02 00:00:00',
				'artist_term_id'     => 101,
			)
		);
		$rows   = $repository->list(
			array(
				'venue_term_id' => 55,
				'status'        => 'submitted',
				'limit'         => 1,
			)
		);
		$this->assertCount( 1, $rows );
		$this->assertSame( $latest['id'], $rows[0]['id'] );
		$rows = $repository->list(
			array(
				'venue_term_id'      => 55,
				'artist_term_id'     => 101,
				'requested_start_at' => '2026-09-30 00:00:00',
			)
		);
		$this->assertSame( array( $latest['id'] ), array_column( $rows, 'id' ) );
		$rows = $repository->list(
			array(
				'venue_term_id'    => 55,
				'requested_end_at' => '2026-08-31 00:00:00',
			)
		);
		$this->assertSame( array( $first['id'] ), array_column( $rows, 'id' ) );
	}

	public function test_repository_distinguishes_not_found_conflict_and_read_failure(): void {
		$repository = new BookingRepository();
		$this->assertSame( 'booking_not_found', $repository->update( 999, array( 'contact_name' => 'Reviewing' ), 1 )->get_error_code() );
		$booking = $this->create_booking();
		$repository->update( $booking['id'], array( 'contact_name' => 'Reviewing' ), 1 );
		$this->assertSame( 'booking_version_conflict', $repository->update( $booking['id'], array( 'contact_name' => 'Accepted' ), 1 )->get_error_code() );
		$GLOBALS['wpdb']->fail_reads = true;
		$this->assertSame( 'booking_read_failed', $repository->get( $booking['id'] )->get_error_code() );
		$this->assertSame( 'booking_list_failed', $repository->list( array( 'venue_term_id' => 55 ) )->get_error_code() );
	}

	public function test_lifecycle_statuses_and_every_transition_edge_are_explicit(): void {
		$this->assertSame(
			array( 'submitted', 'needs_info', 'under_review', 'negotiating', 'held', 'confirmed', 'declined', 'withdrawn', 'cancelled', 'completed' ),
			BookingLifecycle::STATUSES
		);
		$this->assertSame( BookingRepository::STATUSES, BookingLifecycle::STATUSES );
		$allowed = array(
			'submitted'    => array( 'needs_info', 'under_review', 'declined', 'withdrawn' ),
			'needs_info'   => array( 'submitted', 'under_review', 'declined', 'withdrawn' ),
			'under_review' => array( 'needs_info', 'negotiating', 'declined', 'withdrawn' ),
			'negotiating'  => array( 'needs_info', 'under_review', 'held', 'confirmed', 'declined', 'withdrawn' ),
			'held'         => array( 'negotiating', 'confirmed', 'declined', 'withdrawn', 'cancelled' ),
			'confirmed'    => array( 'cancelled', 'completed' ),
			'declined'     => array(),
			'withdrawn'    => array(),
			'cancelled'    => array(),
			'completed'    => array(),
		);
		$lifecycle = new BookingLifecycle();
		foreach ( BookingLifecycle::STATUSES as $from ) {
			foreach ( BookingLifecycle::STATUSES as $to ) {
				$result = $lifecycle->validate_transition(
					array(
						'status'             => $from,
						'requested_start_at' => '2026-08-01 20:00:00',
						'requested_end_at'   => '2026-08-01 23:00:00',
						'space_key'          => 'main-room',
						'deal'               => array( 'version' => 1, 'data' => array( 'type' => 'guarantee' ) ),
					),
					$to
				);
				if ( in_array( $to, $allowed[ $from ], true ) ) {
					$this->assertNotSame( 'booking_transition_forbidden', is_wp_error( $result ) ? $result->get_error_code() : null, "Expected {$from} -> {$to} to be explicit." );
				} else {
					$this->assertSame( 'booking_transition_forbidden', $result->get_error_code(), "Expected {$from} -> {$to} to be forbidden." );
				}
			}
		}
	}

	public function test_missing_hold_and_conflict_substrates_fail_closed(): void {
		$lifecycle = new BookingLifecycle();
		$booking   = array( 'status' => 'negotiating', 'requested_start_at' => null, 'requested_end_at' => null, 'space_key' => null, 'deal' => null );
		$this->assertSame( 'booking_hold_repository_unavailable', $lifecycle->validate_transition( $booking, 'held' )->get_error_code() );
		$this->assertSame( 'booking_confirmation_selection_required', $lifecycle->validate_transition( $booking, 'confirmed' )->get_error_code() );
		$booking['requested_start_at'] = '2026-08-01 20:00:00';
		$booking['requested_end_at']   = '2026-08-01 23:00:00';
		$booking['space_key']          = 'main-room';
		$this->assertSame( 'booking_confirmation_deal_required', $lifecycle->validate_transition( $booking, 'confirmed' )->get_error_code() );
		$booking['deal'] = array( 'version' => 1, 'data' => array( 'type' => 'guarantee' ) );
		$this->assertSame( 'booking_conflict_repository_unavailable', $lifecycle->validate_transition( $booking, 'confirmed' )->get_error_code() );
	}

	public function test_inquiry_creation_is_atomic_anonymous_and_race_idempotent(): void {
		$lifecycle = new BookingLifecycle();
		$input     = array(
			'idempotency_key' => 'request-298',
			'venue_term_id'   => 55,
			'artist_name'     => 'New Band',
			'intake'          => array( 'draw' => 100 ),
		);
		$first = $lifecycle->create_inquiry( $input );
		$this->assertSame( 'submitted', $first['status'] );
		$this->assertNull( $first['submitter_user_id'] );
		$this->assertSame( $first['id'], $lifecycle->create_inquiry( $input )['id'] );
		$reordered = array( 'intake' => array( 'draw' => 100 ), 'artist_name' => 'New Band', 'venue_term_id' => 55, 'idempotency_key' => 'request-298' );
		$this->assertSame( $first['id'], $lifecycle->create_inquiry( $reordered )['id'] );
		$conflict = $lifecycle->create_inquiry( array_merge( $input, array( 'artist_name' => 'Different Band' ) ) );
		$this->assertSame( 'booking_idempotency_conflict', $conflict->get_error_code() );
		$this->assertSame( array( 'status' => 409 ), $conflict->get_error_data() );
		$this->assertCount( 1, $GLOBALS['wpdb']->rows[ BookingSchema::bookings_table() ] );
		$this->assertCount( 1, $GLOBALS['wpdb']->rows[ BookingSchema::activity_table() ] );

		$GLOBALS['wpdb']->race_booking_insert = true;
		$race = $lifecycle->create_inquiry( array_merge( $input, array( 'idempotency_key' => 'request-race' ) ) );
		$this->assertIsArray( $race );
		$this->assertSame( 0, $GLOBALS['wpdb']->natural_key_reads_in_transaction, 'The loser must resolve its winner only after rollback.' );
		$this->assertCount( 2, $GLOBALS['wpdb']->rows[ BookingSchema::bookings_table() ] );
		$this->assertCount( 2, $GLOBALS['wpdb']->rows[ BookingSchema::activity_table() ] );
		$this->assertSame( 'booking_idempotency_conflict', $lifecycle->create_inquiry( array_merge( $input, array( 'idempotency_key' => 'request-race', 'contact_email' => 'other@example.com' ) ) )->get_error_code() );
		$GLOBALS['wpdb']->race_booking_insert = true;
		$GLOBALS['wpdb']->race_booking_hash   = str_repeat( '0', 64 );
		$race_conflict = $lifecycle->create_inquiry( array_merge( $input, array( 'idempotency_key' => 'request-race-mismatch' ) ) );
		$this->assertSame( 'booking_idempotency_conflict', $race_conflict->get_error_code() );
		$this->assertSame( array( 'status' => 409 ), $race_conflict->get_error_data() );
		$this->assertSame( 'booking_idempotency_conflict', $lifecycle->create_inquiry( $input, 12 )->get_error_code(), 'Authenticated actor identity must be part of the fingerprint.' );

		$GLOBALS['wpdb']->fail_activity_inserts = true;
		$result = $lifecycle->create_inquiry( array_merge( $input, array( 'idempotency_key' => 'request-fails' ) ) );
		$this->assertSame( 'booking_activity_write_failed', $result->get_error_code() );
		$this->assertCount( 3, $GLOBALS['wpdb']->rows[ BookingSchema::bookings_table() ] );
	}

	public function test_failed_idempotent_insert_without_winner_preserves_database_error(): void {
		$lifecycle = new BookingLifecycle();
		$GLOBALS['wpdb']->fail_inserts = true;
		$result = $lifecycle->create_inquiry( array( 'idempotency_key' => 'no-winner', 'venue_term_id' => 55, 'intake' => array(), 'artist_name' => 'Band' ) );
		$this->assertSame( 'booking_create_failed', $result->get_error_code() );
		$this->assertSame( array( 'database_error' => 'simulated insert failure' ), $result->get_error_data() );
		$this->assertSame( 0, $GLOBALS['wpdb']->natural_key_reads_in_transaction );
	}

	public function test_inquiry_admission_requires_enabled_config_but_retry_survives_disable(): void {
		$lifecycle = new BookingLifecycle();
		$input     = array( 'idempotency_key' => 'enabled-request', 'venue_term_id' => 55, 'intake' => array(), 'artist_name' => 'Band' );
		$created   = $lifecycle->create_inquiry( $input );
		$this->assertIsArray( $created );
		$this->assertSame( 1, $GLOBALS['wpdb']->venue_lock_queries );
		$this->assertContains( array( 55, 'term_meta' ), $GLOBALS['ec_artist_test']['cache_deletes'] );
		$GLOBALS['ec_artist_test']['meta'][7][55]['_extrachill_booking_config'] = array( 'enabled' => false );
		$this->assertSame( $created['id'], $lifecycle->create_inquiry( $input )['id'] );
		$this->assertSame( 1, $GLOBALS['wpdb']->venue_lock_queries, 'Matching retries must resolve before admission locking.' );
		$this->assertSame( 'booking_inquiry_admission_disabled', $lifecycle->create_inquiry( array_merge( $input, array( 'idempotency_key' => 'disabled-request' ) ) )->get_error_code() );

		$GLOBALS['ec_artist_test']['meta'][7][55]['_extrachill_booking_config'] = array( 'enabled' => true );
		$GLOBALS['wpdb']->after_venue_lock = static function () {
			$GLOBALS['ec_artist_test']['meta'][7][55]['_extrachill_booking_config'] = array( 'enabled' => false );
		};
		$this->assertSame( 'booking_inquiry_admission_disabled', $lifecycle->create_inquiry( array_merge( $input, array( 'idempotency_key' => 'disabled-during-lock' ) ) )->get_error_code() );

		$GLOBALS['wpdb']->fail_venue_lock = true;
		$this->assertSame( 'booking_inquiry_venue_lock_failed', $lifecycle->create_inquiry( array_merge( $input, array( 'idempotency_key' => 'lock-failure' ) ) )->get_error_code() );
	}

	public function test_inquiry_config_read_failure_rolls_back_after_venue_lock(): void {
		$lifecycle = new BookingLifecycle( null, null, null, new BookingTestConfig() );
		$result    = $lifecycle->create_inquiry( array( 'idempotency_key' => 'read-failure', 'venue_term_id' => 55, 'intake' => array(), 'artist_name' => 'Band' ) );
		$this->assertSame( 'booking_inquiry_config_read_failed', $result->get_error_code() );
		$this->assertSame( 1, $GLOBALS['wpdb']->venue_lock_queries );
		$this->assertSame( 1, $GLOBALS['wpdb']->rollback_queries );
		$this->assertSame( array(), $GLOBALS['wpdb']->rows );
	}

	public function test_transition_and_assignment_are_atomic_and_optimistic(): void {
		$authorization = new BookingTestAuthorization( array( '20:55' => true ) );
		$lifecycle     = new BookingLifecycle( null, null, $authorization );
		$booking       = $this->create_booking();
		$reviewing     = $lifecycle->transition( $booking['id'], 'under_review', 1, 12, 'Review started' );
		$this->assertSame( 'under_review', $reviewing['status'] );
		$this->assertSame( 2, $reviewing['version'] );
		$this->assertSame( 'booking_version_conflict', $lifecycle->transition( $booking['id'], 'needs_info', 1, 12 )->get_error_code() );

		$assigned = $lifecycle->assign( $booking['id'], 20, 2, 12 );
		$this->assertSame( 20, $assigned['assignee_user_id'] );
		$this->assertSame( 3, $assigned['version'] );

		$GLOBALS['wpdb']->fail_activity_inserts = true;
		$result = $lifecycle->transition( $booking['id'], 'negotiating', 3, 12 );
		$this->assertSame( 'booking_activity_write_failed', $result->get_error_code() );
		$current = ( new BookingRepository() )->get( $booking['id'] );
		$this->assertSame( 'under_review', $current['status'] );
		$this->assertSame( 3, $current['version'] );
	}

	public function test_assignment_requires_target_access_to_the_exact_booking_venue(): void {
		$authorization = new BookingTestAuthorization(
			array(
				'20:55' => true,
				'21:56' => true,
			)
		);
		$lifecycle     = new BookingLifecycle( null, null, $authorization );
		$booking       = $this->create_booking();

		$this->assertSame( 'invalid_booking_assignee', $lifecycle->assign( $booking['id'], 21, 1, 12 )->get_error_code(), 'Access to a different venue must not permit assignment.' );
		$this->assertSame( 'invalid_booking_assignee', $lifecycle->assign( $booking['id'], 22, 1, 12 )->get_error_code(), 'An unauthorized target must not permit assignment.' );
		$this->assertSame( 1, ( new BookingRepository() )->get( $booking['id'] )['version'] );

		$assigned = $lifecycle->assign( $booking['id'], 20, 1, 12 );
		$this->assertSame( 20, $assigned['assignee_user_id'] );
		$this->assertSame( 2, $assigned['version'] );
		$unassigned = $lifecycle->assign( $booking['id'], null, 2, 12 );
		$this->assertNull( $unassigned['assignee_user_id'] );
		$this->assertSame( 3, $unassigned['version'] );
		$this->assertSame(
			array(
				array( 21, 55, VenueAuthorization::ACTION_ACCESS_VENUE ),
				array( 22, 55, VenueAuthorization::ACTION_ACCESS_VENUE ),
				array( 20, 55, VenueAuthorization::ACTION_ACCESS_VENUE ),
				array( 12, 55, VenueAuthorization::ACTION_ACCESS_VENUE ),
				array( 20, 55, VenueAuthorization::ACTION_ACCESS_VENUE ),
				array( 12, 55, VenueAuthorization::ACTION_ACCESS_VENUE ),
			),
			$authorization->calls,
			'Unassignment must not attempt target authorization.'
		);
	}

	public function test_transaction_lock_reauthorizes_actor_and_atomic_artist_binding(): void {
		$authorization = new BookingTestAuthorization();
		$lifecycle     = new BookingLifecycle( null, null, $authorization );
		$booking       = $this->create_booking();
		$GLOBALS['wpdb']->after_membership_lock = static function () use ( $authorization ) {
			unset( $authorization->allowed['12:55'] );
		};
		$denied = $lifecycle->transition( $booking['id'], 'under_review', 1, 12 );
		$this->assertSame( 'venue_action_forbidden', $denied->get_error_code() );
		$this->assertSame( 1, ( new BookingRepository() )->get( $booking['id'] )['version'] );

		$authorization->allowed['12:55'] = true;
		$bound = $lifecycle->bind_artist( $booking['id'], 101, 501, 1, 12 );
		$this->assertSame( 101, $bound['artist_term_id'] );
		$this->assertSame( 501, $bound['artist_profile_id'] );
		$this->assertSame( 'Canonical Artist', $bound['artist_name'] );
		$this->assertSame( 2, $bound['version'] );
		$this->assertSame( 'booking_artist_already_bound', $lifecycle->bind_artist( $booking['id'], null, 502, 2, 12 )->get_error_code() );
		$activities = ( new BookingActivityRepository() )->list_for_booking( $booking['id'] );
		$this->assertSame( 'artist_bound', $activities[0]['kind'] );

		$term_only = $this->create_booking( array( 'artist_term_id' => 101 ) );
		$completed = $lifecycle->bind_artist( $term_only['id'], null, 501, 1, 12 );
		$this->assertSame( 101, $completed['artist_term_id'] );
		$this->assertSame( 501, $completed['artist_profile_id'] );
	}

	public function test_assignment_target_is_reauthorized_after_venue_lock(): void {
		$authorization = new BookingTestAuthorization( array( '20:55' => true ) );
		$lifecycle     = new BookingLifecycle( null, null, $authorization );
		$booking       = $this->create_booking();
		$GLOBALS['wpdb']->after_membership_lock = static function () use ( $authorization ) {
			unset( $authorization->allowed['20:55'] );
		};
		$result = $lifecycle->assign( $booking['id'], 20, 1, 12 );
		$this->assertSame( 'invalid_booking_assignee', $result->get_error_code() );
		$current = ( new BookingRepository() )->get( $booking['id'] );
		$this->assertNull( $current['assignee_user_id'] );
		$this->assertSame( 1, $current['version'] );
	}

	public function test_transaction_control_failures_are_explicit(): void {
		$lifecycle = new BookingLifecycle();
		$input     = array( 'idempotency_key' => 'transaction-test', 'venue_term_id' => 55, 'artist_name' => 'New Band', 'intake' => array() );
		$GLOBALS['wpdb']->fail_transaction_start = true;
		$this->assertSame( 'booking_transaction_start_failed', $lifecycle->create_inquiry( $input )->get_error_code() );

		$GLOBALS['wpdb']->fail_transaction_start  = false;
		$GLOBALS['wpdb']->fail_transaction_commit = true;
		$rollbacks_before = $GLOBALS['wpdb']->rollback_queries;
		$this->assertSame( 'booking_transaction_commit_uncertain', $lifecycle->create_inquiry( $input )->get_error_code() );
		$this->assertSame( $rollbacks_before, $GLOBALS['wpdb']->rollback_queries );

		$GLOBALS['wpdb']->fail_transaction_commit   = false;
		$GLOBALS['wpdb']->fail_activity_inserts     = true;
		$GLOBALS['wpdb']->fail_transaction_rollback = true;
		$this->assertSame( 'booking_transaction_rollback_failed', $lifecycle->create_inquiry( array_merge( $input, array( 'idempotency_key' => 'rollback-test' ) ) )->get_error_code() );
	}

	public function test_ability_contracts_are_strict_hidden_publicly_and_exactly_venue_scoped(): void {
		$authorization = new BookingTestAuthorization();
		$abilities     = new VenueBookingAbilities( new BookingRepository(), new BookingLifecycle(), $authorization );
		$abilities->register();
		$registered = $GLOBALS['ec_artist_test']['abilities'];
		$this->assertSame(
			array(
				'extrachill/create-booking-inquiry',
				'extrachill/list-venue-bookings',
				'extrachill/get-venue-booking',
				'extrachill/assign-venue-booking',
				'extrachill/transition-venue-booking',
				'extrachill/bind-venue-booking-artist',
			),
			array_keys( $registered )
		);
		$this->assertFalse( $registered['extrachill/create-booking-inquiry']['meta']['show_in_rest'] );
		$this->assertTrue( $registered['extrachill/list-venue-bookings']['meta']['show_in_rest'] );
		foreach ( $registered as $definition ) {
			$this->assertFalse( $definition['input_schema']['additionalProperties'] );
			$this->assertFalse( $definition['output_schema']['additionalProperties'] ?? false );
		}
		$this->assertSame( BookingLifecycle::STATUSES, $registered['extrachill/transition-venue-booking']['input_schema']['properties']['to_status']['enum'] );
		$this->assertSame( array( 'idempotency_key', 'venue_term_id', 'intake' ), $registered['extrachill/create-booking-inquiry']['input_schema']['required'] );
		$this->assertSame( array( 'venue_term_id' ), $registered['extrachill/list-venue-bookings']['input_schema']['required'] );
		$this->assertSame( array( 'booking_id', 'to_status', 'expected_version' ), $registered['extrachill/transition-venue-booking']['input_schema']['required'] );
		$this->assertSame( array( 'booking_id', 'expected_version' ), $registered['extrachill/bind-venue-booking-artist']['input_schema']['required'] );
		$this->assertSame( array( 'public_id', 'venue_term_id', 'submitted_at' ), $registered['extrachill/create-booking-inquiry']['output_schema']['required'] );
		$receipt_input = array( 'idempotency_key' => 'public-receipt', 'venue_term_id' => 55, 'intake' => array(), 'artist_name' => 'Private Band', 'contact_email' => 'private@example.com' );
		$receipt       = call_user_func( $registered['extrachill/create-booking-inquiry']['execute_callback'], $receipt_input );
		$this->assertSame( array( 'public_id', 'venue_term_id', 'submitted_at' ), array_keys( $receipt ) );
		$this->assertArrayNotHasKey( 'id', $receipt );
		$this->assertArrayNotHasKey( 'status', $receipt );
		$this->assertArrayNotHasKey( 'contact_email', $receipt );
		$stored = reset( $GLOBALS['wpdb']->rows[ BookingSchema::bookings_table() ] );
		$GLOBALS['wpdb']->rows[ BookingSchema::bookings_table() ][ $stored['id'] ]['status'] = 'under_review';
		$GLOBALS['wpdb']->rows[ BookingSchema::bookings_table() ][ $stored['id'] ]['contact_email'] = 'changed@example.com';
		$this->assertSame( $receipt, call_user_func( $registered['extrachill/create-booking-inquiry']['execute_callback'], $receipt_input ) );

		$booking = $this->create_booking();
		$this->assertTrue( $abilities->can_access_booking( array( 'booking_id' => $booking['id'] ) ) );
		$this->assertSame( array( array( 12, 55, VenueAuthorization::ACTION_ACCESS_VENUE ) ), $authorization->calls );
		$this->assertSame( 'venue_action_forbidden', $abilities->can_access_booking( array( 'booking_id' => 999 ) )->get_error_code() );
		$this->assertSame( array( array( 12, 55, VenueAuthorization::ACTION_ACCESS_VENUE ) ), $authorization->calls, 'Missing bookings must not reach authorization with a guessed venue.' );
	}
}
