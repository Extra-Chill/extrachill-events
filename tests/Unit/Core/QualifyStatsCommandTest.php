<?php
/**
 * Tests for bounded qualify-stats database paging.
 *
 * @package ExtraChillEvents\Tests\Unit\Core
 */

namespace ExtraChillEvents\Tests\Unit\Core;

use ExtraChillEvents\Cli\QualifyStatsCommand;
use PHPUnit\Framework\TestCase;

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}

require_once dirname( __DIR__, 3 ) . '/inc/Core/QualifyVerdictsTable.php';
require_once dirname( __DIR__, 3 ) . '/inc/Cli/QualifyStatsCommand.php';

/**
 * Covers keyset and page-size constraints on stats reads.
 */
class QualifyStatsCommandTest extends TestCase {

	/** Test the latest-row query binds an exclusive cursor and page limit. */
	public function test_latest_rows_query_is_keyset_paged(): void {
		global $wpdb;
		$original_wpdb = $wpdb ?? null;
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Isolated database test double.
		$wpdb = new class() {
			/**
			 * Database table prefix.
			 *
			 * @var string
			 */
			public string $prefix = 'wp_';
			/**
			 * Captured prepared queries.
			 *
			 * @var array<int,array<string,mixed>>
			 */
			public array $prepared = array();

			/**
			 * Capture a prepared query and its arguments.
			 *
			 * @param string $query Query template.
			 * @param mixed  ...$args Bound arguments.
			 */
			public function prepare( string $query, ...$args ): string {
				$this->prepared[] = array(
					'query' => $query,
					'args'  => $args,
				);
				return $query;
			}

			/**
			 * Return a single latest-row fixture.
			 *
			 * @param string $query  Prepared query.
			 * @param mixed  $output Requested output format.
			 */
			public function get_results( string $query, $output ): array {
				unset( $query, $output );
				return array(
					array(
						'id'          => 124,
						'url'         => 'https://venue.example/events',
						'verdict'     => 'extraction_gap',
						'fingerprint' => '{}',
					),
				);
			}
		};

		try {
			$method = new \ReflectionMethod( QualifyStatsCommand::class, 'latest_rows_page' );
			$method->setAccessible( true );
			$rows = $method->invoke( new QualifyStatsCommand(), 30, 123, 250 );

			$this->assertSame( 124, $rows[0]['id'] );
			$query = end( $wpdb->prepared );
			$this->assertStringContainsString( 'WHERE v.id > %d', $query['query'] );
			$this->assertStringContainsString( 'ORDER BY v.id ASC', $query['query'] );
			$this->assertStringContainsString( 'LIMIT %d', $query['query'] );
			$this->assertSame( array( 123, 250 ), $query['args'] );
		} finally {
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Restore original database object.
			$wpdb = $original_wpdb;
		}
	}
}
