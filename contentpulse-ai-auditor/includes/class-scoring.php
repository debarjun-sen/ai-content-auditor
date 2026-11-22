<?php
/**
 * Health score: 100 when nothing is open, decaying with weighted open findings per post.
 *
 * @package AICA
 */

namespace AICA;

defined( 'ABSPATH' ) || exit;

/**
 * Scoring.
 */
class Scoring {

	const WEIGHTS = array(
		'high'   => 8.0,
		'medium' => 4.0,
		'low'    => 1.5,
	);

	/**
	 * Score from penalty per post.
	 *
	 * @param float $penalty Weighted sum of open findings.
	 * @param int   $posts   Posts scanned.
	 * @return int 0–100.
	 */
	public static function score( $penalty, $posts ) {
		$per_post = $penalty / max( 1, (int) $posts );
		return (int) max( 0, min( 100, round( 100 * exp( -$per_post / 15 ) ) ) );
	}

	/**
	 * Letter grade.
	 *
	 * @param int $score Score.
	 * @return string
	 */
	public static function grade( $score ) {
		if ( $score >= 90 ) {
			return 'A';
		}
		if ( $score >= 75 ) {
			return 'B';
		}
		if ( $score >= 60 ) {
			return 'C';
		}
		if ( $score >= 40 ) {
			return 'D';
		}
		return 'F';
	}

	/**
	 * Stats for a scan from Repository::finding_stats() rows.
	 *
	 * @param array $rows  Rows: category, severity, status, n.
	 * @param int   $posts Posts scanned.
	 * @return array
	 */
	public static function summarize( array $rows, $posts ) {
		$cats = array();
		foreach ( Analyzers\Issue::CATEGORIES as $c ) {
			$cats[ $c ] = array(
				'open'    => 0,
				'fixed'   => 0,
				'total'   => 0,
				'penalty' => 0.0,
				'high'    => 0,
				'medium'  => 0,
				'low'     => 0,
			);
		}
		$total = array(
			'open'      => 0,
			'fixed'     => 0,
			'dismissed' => 0,
			'total'     => 0,
			'penalty'   => 0.0,
		);
		foreach ( $rows as $r ) {
			$c = $r['category'];
			$n = (int) $r['n'];
			if ( ! isset( $cats[ $c ] ) ) {
				continue;
			}
			$cats[ $c ]['total'] += $n;
			$total['total']      += $n;
			if ( isset( $total[ $r['status'] ] ) ) {
				$total[ $r['status'] ] += $n;
			}
			if ( 'open' === $r['status'] ) {
				$cats[ $c ]['open']           += $n;
				$cats[ $c ][ $r['severity'] ] += $n;
				$w                             = isset( self::WEIGHTS[ $r['severity'] ] ) ? self::WEIGHTS[ $r['severity'] ] : 1;
				$cats[ $c ]['penalty']        += $w * $n;
				$total['penalty']             += $w * $n;
			} elseif ( 'fixed' === $r['status'] ) {
				$cats[ $c ]['fixed'] += $n;
			}
		}
		foreach ( $cats as $c => $v ) {
			$cats[ $c ]['score'] = self::score( $v['penalty'], $posts );
			unset( $cats[ $c ]['penalty'] );
		}
		$score = self::score( $total['penalty'], $posts );
		unset( $total['penalty'] );
		return array(
			'score'      => $score,
			'grade'      => self::grade( $score ),
			'totals'     => $total,
			'categories' => $cats,
		);
	}
}
