<?php
/**
 * Qualify Verdict — taxonomy + URL canonicalizer.
 *
 * Phase 1 of qualify v2: this file currently exposes only the URL canonicalizer
 * that both the persistence layer and CLI commands depend on. The full verdict
 * taxonomy constants and the resolver decision tree are added in the next
 * commit (feat(core): QualifyVerdict taxonomy + verdict resolver decision
 * tree).
 *
 * Keeping the canonicalizer co-located with the taxonomy ensures every consumer
 * (qualify execution, persistence, requalify-pending lookups, audit commands)
 * uses identical URL normalization rules.
 *
 * @package ExtraChillEvents\Core
 * @since   0.20.0
 */

namespace ExtraChillEvents\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class QualifyVerdict {

	/**
	 * Canonicalize a URL for verdict lookup / dedup.
	 *
	 * Rules:
	 *  - lowercase host
	 *  - drop trailing slash on path (but preserve a single "/" for bare hosts)
	 *  - drop fragment
	 *  - keep query string ONLY when it materially identifies the events
	 *    page (e.g. ?view=calendar / ?page=events). Default: drop query.
	 *
	 * Both the persistence layer and the requalify-pending command call this
	 * helper so the same input URL always produces the same canonical form,
	 * regardless of trailing-slash / casing / fragment variations.
	 *
	 * @param string $url Raw URL.
	 * @return string Canonical URL, or '' if the input is unparseable.
	 */
	public static function canonicalize_url( string $url ): string {
		$url = trim( $url );
		if ( '' === $url ) {
			return '';
		}

		// Add scheme if missing — wp_parse_url chokes on bare hostnames.
		if ( ! preg_match( '#^https?://#i', $url ) ) {
			$url = 'https://' . ltrim( $url, '/' );
		}

		$parts = wp_parse_url( $url );
		if ( ! is_array( $parts ) || empty( $parts['host'] ) ) {
			return '';
		}

		$scheme = strtolower( $parts['scheme'] ?? 'https' );
		$host   = strtolower( $parts['host'] );
		$path   = $parts['path'] ?? '';
		$query  = $parts['query'] ?? '';

		// Drop trailing slash on path. A bare "/" collapses to '' so
		// "https://example.com" and "https://example.com/" canonicalize identically.
		if ( '/' === $path ) {
			$path = '';
		} elseif ( '' !== $path ) {
			$path = rtrim( $path, '/' );
		}

		// Keep query keys that materially identify the events page; drop all
		// others (tracking params, session ids, etc.).
		$identifying_params = self::identifying_query_params();
		$kept_query         = '';
		if ( '' !== $query ) {
			parse_str( $query, $parsed );
			$keep = array();
			foreach ( $parsed as $k => $v ) {
				if ( in_array( strtolower( (string) $k ), $identifying_params, true ) ) {
					$keep[ $k ] = $v;
				}
			}
			if ( ! empty( $keep ) ) {
				ksort( $keep );
				$kept_query = http_build_query( $keep );
			}
		}

		$canonical = $scheme . '://' . $host . $path;
		if ( '' !== $kept_query ) {
			$canonical .= '?' . $kept_query;
		}

		return $canonical;
	}

	/**
	 * Query parameters worth keeping during canonicalization.
	 *
	 * These are parameters that actually identify which page on the venue's
	 * site is being looked at. Tracking junk (utm_*, fbclid, etc.) is dropped.
	 *
	 * Lowercase comparison only. Extend via the
	 * `extrachill_events_qualify_identifying_query_params` filter.
	 *
	 * @return array<int,string>
	 */
	private static function identifying_query_params(): array {
		$defaults = array(
			'view',
			'page',
			'page_id',
			'p',
			'cat',
			'tag',
			'taxonomy',
			'term',
			'eventdisplay',
			'event_display',
			'tribe_event_display',
		);

		/**
		 * Filter the list of query parameters preserved during canonicalization.
		 *
		 * @param array $params Default list of identifying parameter names (lowercase).
		 */
		$filtered = apply_filters( 'extrachill_events_qualify_identifying_query_params', $defaults );

		return is_array( $filtered ) ? array_map( 'strtolower', $filtered ) : $defaults;
	}

	/**
	 * SHA1 hash of the canonical URL, used as the lookup index in the
	 * verdicts table. Returns '' when the URL cannot be canonicalized.
	 *
	 * @param string $url Raw URL.
	 * @return string 40-char hex hash, or empty string.
	 */
	public static function url_hash( string $url ): string {
		$canonical = self::canonicalize_url( $url );
		return '' === $canonical ? '' : sha1( $canonical );
	}
}
