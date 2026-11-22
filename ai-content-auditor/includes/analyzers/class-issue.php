<?php
/**
 * Issue value builder shared by all analyzers.
 *
 * @package AICA
 */

namespace AICA\Analyzers;

defined( 'ABSPATH' ) || exit;

/**
 * Builds normalized issue arrays.
 */
class Issue {

	const SEVERITIES = array( 'high', 'medium', 'low' );
	const CATEGORIES = array( 'seo', 'accessibility', 'freshness', 'security' );

	/**
	 * Create an issue.
	 *
	 * @param string     $code           Stable check code, e.g. "a11y.img_missing_alt".
	 * @param string     $category       seo|accessibility|freshness|security.
	 * @param string     $severity       high|medium|low.
	 * @param string     $title          One-line title.
	 * @param string     $detail         What was found (evidence).
	 * @param string     $recommendation How to fix.
	 * @param array|null $fix            Optional fix hint: field, target.
	 * @param string     $wcag           Optional WCAG success criterion.
	 * @return array
	 */
	public static function make( $code, $category, $severity, $title, $detail, $recommendation, $fix = null, $wcag = '' ) {
		$issue = array(
			'check_code'     => $code,
			'category'       => $category,
			'severity'       => $severity,
			'title'          => $title,
			'detail'         => $detail,
			'recommendation' => $recommendation,
		);
		if ( $wcag ) {
			$issue['wcag'] = $wcag;
		}
		if ( $fix ) {
			$issue['fixable'] = $fix;
		}
		return $issue;
	}

	/**
	 * Truncate for evidence strings.
	 *
	 * @param string $s   String.
	 * @param int    $max Max chars.
	 * @return string
	 */
	public static function excerpt( $s, $max = 80 ) {
		$s = (string) $s;
		if ( Content_Extractor::len( $s ) <= $max ) {
			return $s;
		}
		return ( function_exists( 'mb_substr' ) ? mb_substr( $s, 0, $max - 1, 'UTF-8' ) : substr( $s, 0, $max - 1 ) ) . '…';
	}
}
