<?php
/**
 * Stale-content detection.
 *
 * @package AICA
 */

namespace AICA\Analyzers;

defined( 'ABSPATH' ) || exit;

/**
 * Freshness analyzer.
 */
class Freshness_Analyzer {

	/**
	 * Phrases that age badly.
	 *
	 * @var string[]
	 */
	const TIME_SENSITIVE = array( 'this year', 'next year', 'upcoming', 'coming soon', 'currently', 'right now', 'latest version', 'new feature', 'register now', 'early bird', 'launching soon', 'stay tuned' );

	/**
	 * Run checks.
	 *
	 * @param array         $doc        Document (needs modified, text, links).
	 * @param int           $now        Current UNIX timestamp.
	 * @param int           $stale_days Threshold.
	 * @param callable|null $resolver   fn(string $href): bool, true when an internal URL resolves.
	 * @return array[] Issues (each may include 'age_days').
	 */
	public static function analyze( array $doc, $now, $stale_days = 365, $resolver = null ) {
		$issues   = array();
		$modified = ! empty( $doc['modified'] ) ? strtotime( $doc['modified'] . ' UTC' ) : false;
		$age      = $modified ? (int) floor( ( $now - $modified ) / 86400 ) : 0;
		$text     = isset( $doc['text'] ) ? (string) $doc['text'] : '';

		if ( $modified && $age >= $stale_days ) {
			$severity = $age >= 2 * $stale_days ? 'high' : 'medium';
			$issues[] = Issue::make(
				'fresh.not_updated',
				'freshness',
				$severity,
				sprintf( 'Not updated in %s', self::human_age( $age ) ),
				sprintf( 'Last modified on %s (%d days ago).', gmdate( 'M j, Y', $modified ), $age ),
				'Review facts, screenshots, prices and links; update the content or add a “last reviewed” note.'
			);
		}

		// Past years mentioned in the text, e.g. "Best tools for 2021".
		$this_year = (int) gmdate( 'Y', $now );
		if ( preg_match_all( '/\b(19[9]\d|20\d{2})\b/', $text . ' ' . ( isset( $doc['title'] ) ? $doc['title'] : '' ), $m ) ) {
			$years    = array_unique( array_map( 'intval', $m[1] ) );
			$old      = array_filter(
				$years,
				static function ( $y ) use ( $this_year ) {
					return $y < $this_year - 1 && $y > 1990;
				}
			);
			$in_title = isset( $doc['title'] ) && preg_match( '/\b(' . implode( '|', $old ? $old : array( 'x' ) ) . ')\b/', $doc['title'] );
			if ( $old ) {
				sort( $old );
				$issues[] = Issue::make(
					'fresh.dated_year',
					'freshness',
					$in_title ? 'high' : 'medium',
					$in_title ? 'Title references a past year' : 'Mentions past years',
					sprintf( 'References %s, which may signal outdated information.', implode( ', ', $old ) ),
					$in_title ? sprintf( 'Update the content and change the year in the title to %d, or remove the year.', $this_year ) : 'Check whether these year references are still accurate or need context.'
				);
			}
		}

		// Time-sensitive phrasing on older content.
		if ( $age >= 180 ) {
			$found = array();
			$lower = strtolower( $text );
			foreach ( self::TIME_SENSITIVE as $phrase ) {
				if ( false !== strpos( $lower, $phrase ) ) {
					$found[] = '“' . $phrase . '”';
				}
			}
			if ( $found ) {
				$issues[] = Issue::make(
					'fresh.time_sensitive',
					'freshness',
					'low',
					'Time-sensitive wording on old content',
					sprintf( 'Uses %s but was last updated %s ago.', implode( ', ', array_slice( $found, 0, 4 ) ), self::human_age( $age ) ),
					'Replace relative time phrases with specific dates, or update the information.'
				);
			}
		}

		// Broken internal links.
		if ( is_callable( $resolver ) ) {
			$broken = array();
			foreach ( isset( $doc['links'] ) ? $doc['links'] : array() as $l ) {
				if ( ! empty( $l['internal'] ) && 'web' === $l['kind'] && ! call_user_func( $resolver, $l['href'] ) ) {
					$broken[ $l['href'] ] = true;
				}
			}
			if ( $broken ) {
				$list     = array_keys( $broken );
				$issues[] = Issue::make(
					'fresh.broken_internal_links',
					'freshness',
					'high',
					sprintf( '%d broken internal link%s', count( $list ), count( $list ) > 1 ? 's' : '' ),
					'Links to pages that no longer exist: ' . implode( ', ', array_map( array( Issue::class, 'excerpt' ), array_slice( $list, 0, 5 ) ) ),
					'Point these links at the current page or remove them; add redirects for moved content.'
				);
			}
		}

		return $issues;
	}

	/**
	 * Age in days → human string.
	 *
	 * @param int $days Days.
	 * @return string
	 */
	public static function human_age( $days ) {
		if ( $days >= 365 ) {
			$y = round( $days / 365, 1 );
			return ( floor( $y ) == $y ? (int) $y : $y ) . ( 1.0 === (float) $y ? ' year' : ' years' ); // phpcs:ignore Universal.Operators.StrictComparisons.LooseEqual
		}
		if ( $days >= 60 ) {
			return (int) round( $days / 30 ) . ' months';
		}
		return $days . ' days';
	}
}
