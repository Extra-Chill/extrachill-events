<?php
/**
 * `wp extrachill venues qualify-stats` — verdict histogram.
 *
 * Aggregates the latest verdict per canonical URL from
 * <prefix>_dme_qualify_verdicts and reports a histogram by verdict, optionally
 * broken down by detected platform. The JSON format surfaces full verdict
 * counts plus agent_guidance so external agents can read the output
 * programmatically.
 *
 * @package ExtraChillEvents\Cli
 */

namespace ExtraChillEvents\Cli;

use ExtraChillEvents\Core\QualifyCohortDeriver;
use ExtraChillEvents\Core\QualifyVerdict;
use ExtraChillEvents\Core\QualifyVerdictsTable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class QualifyStatsCommand {

	/**
	 * Show a verdict histogram from the persistent verdict log.
	 *
	 * Aggregates over the LATEST verdict per canonical URL so historical
	 * verdicts do not double-count. Use --days to limit how far back to look.
	 *
	 * ## OPTIONS
	 *
	 * [--days=<days>]
	 * : Only include verdicts from the last N days.
	 * ---
	 * default: 30
	 * ---
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp extrachill venues qualify-stats
	 *     wp extrachill venues qualify-stats --days=7
	 *     wp extrachill venues qualify-stats --format=json
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Named arguments.
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		if ( ! defined( 'WP_CLI' ) || ! \WP_CLI ) {
			return;
		}

		if ( ! QualifyVerdictsTable::table_exists() ) {
			\WP_CLI::error( 'Qualify verdicts table does not exist on this site. Deactivate/reactivate extrachill-events to install it.' );
			return;
		}

		$days   = max( 1, (int) ( $assoc_args['days'] ?? 30 ) );
		$format = $assoc_args['format'] ?? 'table';

		$latest_rows = $this->latest_rows( $days );
		$rows        = $this->aggregate_latest( $latest_rows );
		$cohorts     = QualifyCohortDeriver::group( $latest_rows );

		if ( 'json' === $format ) {
			\WP_CLI::log(
				wp_json_encode(
					array(
						'days'           => $days,
						'total_urls'     => array_sum( array_column( $rows, 'count' ) ),
						'verdicts'       => array_values( $rows ),
						'cohorts'        => $cohorts,
						'guidance_index' => $this->guidance_index(),
					),
					JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
				)
			);
			return;
		}

		// Stable display order: matches QualifyVerdict::all() — qualified
		// verdicts first, then fixable, then permanent disqualifications.
		$ordered = array();
		foreach ( QualifyVerdict::all() as $v ) {
			if ( isset( $rows[ $v ] ) ) {
				$ordered[] = $rows[ $v ];
			}
		}
		foreach ( $rows as $verdict => $row ) {
			if ( ! in_array( $verdict, QualifyVerdict::all(), true ) ) {
				$ordered[] = $row;
			}
		}

		$table_rows = array();
		foreach ( $ordered as $row ) {
			$table_rows[] = array(
				'verdict'       => $row['verdict'],
				'count'         => $row['count'],
				'top_platforms' => $this->format_platforms( $row['platforms'] ),
			);
		}

		\WP_CLI::log( sprintf( 'Verdicts in the last %d day(s): %d URLs', $days, array_sum( array_column( $ordered, 'count' ) ) ) );
		\WP_CLI::log( '' );

		if ( empty( $table_rows ) ) {
			\WP_CLI::log( 'No verdicts logged yet.' );
			return;
		}

		\WP_CLI\Utils\format_items( $format, $table_rows, array( 'verdict', 'count', 'top_platforms' ) );

		if ( empty( $cohorts ) ) {
			return;
		}

		$cohort_rows = array_map(
			static function ( array $cohort ): array {
				$cohort['representative_urls'] = implode( ', ', $cohort['representative_urls'] );
				return $cohort;
			},
			$cohorts
		);
		\WP_CLI::log( '' );
		\WP_CLI::log( 'Remediation cohorts (latest verdict per URL):' );
		\WP_CLI::log( '' );
		\WP_CLI\Utils\format_items(
			$format,
			$cohort_rows,
			array( 'category', 'platform', 'structured_signal', 'page_shape', 'extractor', 'reason', 'count', 'representative_urls' )
		);
	}

	/**
	 * Read one current row per canonical URL.
	 *
	 * The query keeps history-aware semantics: many runs against the same URL
	 * collapse to a single "current" verdict (the most recent one).
	 *
	 * @param int $days Lookback window in days.
	 * @return array<int,array<string,mixed>>
	 */
	private function latest_rows( int $days ): array {
		global $wpdb;
		$table = QualifyVerdictsTable::table_name();

		$since_sql = $days > 0 ? $wpdb->prepare( ' AND qualified_at >= DATE_SUB(NOW(), INTERVAL %d DAY)', $days ) : '';

		// Latest verdict per url_hash. The subquery picks max(id) per hash —
		// id is autoincrement so it's a stable proxy for "most recent".
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared
		// Table name is a trusted internal identifier built from $wpdb->prefix; $since_sql is itself a $wpdb->prepare() fragment with a bound %d placeholder.
		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results(
			"SELECT v.url, v.verdict, v.fingerprint
			FROM {$table} v
			INNER JOIN (
				SELECT url_hash, MAX(id) AS max_id
				FROM {$table}
				WHERE 1=1{$since_sql}
				GROUP BY url_hash
			) latest ON latest.max_id = v.id",
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Build a verdict histogram from latest-verdict rows.
	 *
	 * @param array<int,array<string,mixed>> $rows Latest verdict rows.
	 * @return array<string,array{verdict:string,count:int,platforms:array<string,int>,agent_guidance:string}>
	 */
	private function aggregate_latest( array $rows ): array {

		$histogram = array();
		foreach ( $rows as $row ) {
			$verdict = (string) ( $row['verdict'] ?? '' );
			if ( '' === $verdict ) {
				continue;
			}
			if ( ! isset( $histogram[ $verdict ] ) ) {
				$histogram[ $verdict ] = array(
					'verdict'        => $verdict,
					'count'          => 0,
					'platforms'      => array(),
					'agent_guidance' => QualifyVerdict::guidance_for( $verdict ),
				);
			}
			++$histogram[ $verdict ]['count'];

			$decoded = json_decode( (string) ( $row['fingerprint'] ?? '' ), true );
			if ( is_array( $decoded ) && ! empty( $decoded['platforms_detected'] )
				&& is_array( $decoded['platforms_detected'] ) ) {
				foreach ( $decoded['platforms_detected'] as $p ) {
					$p = (string) $p;
					if ( '' === $p ) {
						continue;
					}
					if ( ! isset( $histogram[ $verdict ]['platforms'][ $p ] ) ) {
						$histogram[ $verdict ]['platforms'][ $p ] = 0;
					}
					++$histogram[ $verdict ]['platforms'][ $p ];
				}
			}
		}

		// Sort platforms by frequency and then slug for deterministic output.
		foreach ( $histogram as &$row ) {
			uksort(
				$row['platforms'],
				static function ( string $a, string $b ) use ( $row ): int {
					$count_order = $row['platforms'][ $b ] <=> $row['platforms'][ $a ];
					return 0 !== $count_order ? $count_order : strcmp( $a, $b );
				}
			);
		}
		unset( $row );

		return $histogram;
	}

	/**
	 * Compact "top platforms" string for table output. Caps at 3 entries plus
	 * an "(other)" bucket so the column stays readable.
	 */
	private function format_platforms( array $platforms ): string {
		if ( empty( $platforms ) ) {
			return '(n/a)';
		}

		$parts = array();
		$shown = 0;
		$other = 0;
		foreach ( $platforms as $name => $count ) {
			if ( $shown < 3 ) {
				$parts[] = $name . '(' . $count . ')';
				++$shown;
			} else {
				$other += $count;
			}
		}
		if ( $other > 0 ) {
			$parts[] = '(other)(' . $other . ')';
		}
		return implode( ' ', $parts );
	}

	/**
	 * Verdict → guidance string. Bundled in the JSON output so agents
	 * reading the stats programmatically get the canonical guidance for
	 * every verdict in one payload.
	 */
	private function guidance_index(): array {
		$out = array();
		foreach ( QualifyVerdict::all() as $verdict ) {
			$out[ $verdict ] = QualifyVerdict::guidance_for( $verdict );
		}
		return $out;
	}
}
