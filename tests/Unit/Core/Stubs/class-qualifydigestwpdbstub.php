<?php
/**
 * Database stub for qualify digest latest-verdict tests.
 *
 * @package ExtraChillEvents\Tests\Unit\Core
 */

namespace ExtraChillEvents\Tests\Unit\Core;

use ExtraChillEvents\Core\QualifyVerdict;

/**
 * Emulates canonical latest-row selection independently from production SQL.
 */
class QualifyDigestWpdbStub {

	/**
	 * WordPress site table prefix.
	 *
	 * @var string
	 */
	public string $prefix = 'c8c_';

	/**
	 * Prepared arguments for the unsupported query.
	 *
	 * @var array<int,mixed>
	 */
	public array $unsupported_args = array();

	/**
	 * Captured unsupported query.
	 *
	 * @var string
	 */
	public string $unsupported_query = '';

	/**
	 * Seeded verdict history.
	 *
	 * @var array<int,array<string,mixed>>
	 */
	private array $verdict_rows;

	/**
	 * Arguments keyed by prepared SQL.
	 *
	 * @var array<string,array<int,mixed>>
	 */
	private array $prepared_args = array();

	/**
	 * Seed verdict history.
	 *
	 * @param array<int,array<string,mixed>> $verdict_rows Seeded verdict rows.
	 */
	public function __construct( array $verdict_rows ) {
		$this->verdict_rows = $verdict_rows;
	}

	/**
	 * Naively interpolate placeholders and retain their original arguments.
	 *
	 * @param string $sql  SQL template.
	 * @param mixed  ...$args Placeholder values.
	 * @return string Interpolated SQL.
	 */
	public function prepare( string $sql, ...$args ): string {
		foreach ( $args as $arg ) {
			$sql = preg_replace( '/%[sd]/', is_string( $arg ) ? "'{$arg}'" : (string) $arg, $sql, 1 );
		}
		$this->prepared_args[ $sql ] = $args;
		return $sql;
	}

	/**
	 * Return no flow or extraction-gap rows for digest setup queries.
	 *
	 * @param string $sql    SQL query.
	 * @param mixed  $output Output mode.
	 * @return array Empty rows.
	 */
	public function get_results( string $sql, $output = ARRAY_A ): array {
		unset( $sql, $output );
		return array();
	}

	/**
	 * Resolve table checks and emulate the latest-verdict count contract.
	 *
	 * @param string $sql Prepared SQL.
	 * @return int|string Query result.
	 */
	public function get_var( string $sql ) {
		if ( 0 === strpos( $sql, 'SHOW TABLES LIKE' ) ) {
			return $this->prefix . 'dme_qualify_verdicts';
		}
		if ( false === strpos( $sql, "current_verdict.verdict = '" . QualifyVerdict::UNSUPPORTED_SOURCE . "'" ) ) {
			return 0;
		}

		$this->unsupported_query = $sql;
		$args                    = $this->prepared_args[ $sql ];
		$this->unsupported_args  = $args;
		$latest                  = array();
		foreach ( $this->verdict_rows as $row ) {
			$hash = $row['url_hash'];
			if ( ! isset( $latest[ $hash ] )
				|| $row['qualified_at'] > $latest[ $hash ]['qualified_at']
				|| ( $row['qualified_at'] === $latest[ $hash ]['qualified_at'] && $row['id'] > $latest[ $hash ]['id'] ) ) {
				$latest[ $hash ] = $row;
			}
		}

		$count = 0;
		foreach ( $latest as $row ) {
			if ( QualifyVerdict::UNSUPPORTED_SOURCE === $row['verdict']
				&& $row['qualified_at'] >= $args[1]
				&& $row['qualified_at'] < $args[2] ) {
				++$count;
			}
		}
		return $count;
	}
}
