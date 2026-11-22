<?php
/**
 * Rules-only scan (no API key needed). Runs in batches so it never times out.
 *
 * @package AICA
 */

namespace AICA\Agent;

use AICA\Analyzers\Accessibility_Analyzer;
use AICA\Analyzers\Content_Extractor;
use AICA\Analyzers\Freshness_Analyzer;
use AICA\Analyzers\Seo_Analyzer;
use AICA\Repository;
use AICA\Safety\Injection_Guard;
use AICA\Settings;
use AICA\Tools\Tool_Registry;
use WP_Error;

defined( 'ABSPATH' ) || exit;

/**
 * Quick scanner.
 */
class Quick_Scanner {

	const BATCH = 10;

	/**
	 * Start a quick scan.
	 *
	 * @param array $overrides post_types, max_posts.
	 * @return array|WP_Error
	 */
	public static function start( array $overrides = array() ) {
		$s     = Settings::all();
		$scope = array(
			'post_types' => ! empty( $overrides['post_types'] ) ? array_values( array_intersect( (array) $overrides['post_types'], $s['post_types'] ) ) : $s['post_types'],
			'max_posts'  => ! empty( $overrides['max_posts'] ) ? max( 1, min( 500, (int) $overrides['max_posts'] ) ) : max( 50, (int) $s['max_posts'] ),
			'stale_days' => (int) $s['stale_days'],
			'offset'     => 0,
		);
		if ( ! $scope['post_types'] ) {
			$scope['post_types'] = $s['post_types'];
		}
		$id = Repository::create_scan( 'quick', $scope );
		if ( ! Repository::acquire_scan_lock( $id ) ) {
			Repository::update_scan(
				$id,
				array(
					'status' => 'cancelled',
					'error'  => 'Another scan is already running.',
				)
			);
			return new WP_Error( 'aica_scan_running', __( 'Another scan is already running. Wait for it to finish or cancel it.', 'ai-content-auditor' ), array( 'status' => 409 ) );
		}
		Repository::log_event( $id, 'info', sprintf( 'Quick scan started (rules only, up to %d items).', $scope['max_posts'] ) );
		Audit_Agent::schedule_continuation( $id );
		return Repository::get_scan( $id );
	}

	/**
	 * Process one batch.
	 *
	 * @param int $scan_id Scan.
	 * @return array|WP_Error
	 */
	public static function step( $scan_id ) {
		$scan = Repository::get_scan( $scan_id );
		if ( ! $scan || 'quick' !== $scan['mode'] ) {
			return new WP_Error( 'aica_scan_missing', __( 'Scan not found.', 'ai-content-auditor' ), array( 'status' => 404 ) );
		}
		if ( $scan['is_terminal'] || ! Repository::acquire_step_lock( $scan_id ) ) {
			return $scan;
		}

		try {
			$scope = $scan['scope'];
			$left  = (int) $scope['max_posts'] - (int) $scope['offset'];
			$posts = $left > 0 ? get_posts(
				array(
					'post_type'        => $scope['post_types'],
					'post_status'      => Tool_Registry::statuses(),
					'has_password'     => false,
					'posts_per_page'   => min( self::BATCH, $left ),
					'offset'           => (int) $scope['offset'],
					'orderby'          => 'ID',
					'order'            => 'ASC',
					'suppress_filters' => false,
				)
			) : array();

			$resolver = Tool_Registry::link_resolver();
			foreach ( $posts as $post ) {
				$n = self::audit_post( $scan_id, $post, (int) $scope['stale_days'], $resolver );
				Repository::log_event( $scan_id, 'tool_result', sprintf( 'Checked “%s” — %d issue%s', html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ), $n, 1 === $n ? '' : 's' ), array( 'post_id' => $post->ID ), 'quick_scan' );
			}

			$scope['offset'] = (int) $scope['offset'] + count( $posts );
			Repository::update_scan(
				$scan_id,
				array(
					'scope'         => $scope,
					'iterations'    => $scan['iterations'] + 1,
					'posts_scanned' => $scope['offset'],
				)
			);

			if ( count( $posts ) < self::BATCH || $scope['offset'] >= (int) $scope['max_posts'] ) {
				Repository::update_scan( $scan_id, array( 'summary' => self::summary( $scan_id, $scope['offset'] ) ) );
				Audit_Agent::finish( $scan_id, 'completed' );
				Repository::log_event( $scan_id, 'done', 'Quick scan complete.' );
			} else {
				Audit_Agent::schedule_continuation( $scan_id );
			}
		} finally {
			Repository::release_step_lock( $scan_id );
		}
		return Repository::get_scan( $scan_id );
	}

	/**
	 * Run every analyzer on a post and store findings.
	 *
	 * @param int      $scan_id    Scan.
	 * @param \WP_Post $post       Post.
	 * @param int      $stale_days Threshold.
	 * @param callable $resolver   Link resolver.
	 * @return int Findings added.
	 */
	public static function audit_post( $scan_id, \WP_Post $post, $stale_days, $resolver ) {
		$doc    = Content_Extractor::from_post( $post );
		$issues = array_merge(
			Seo_Analyzer::analyze( $doc ),
			Accessibility_Analyzer::analyze( $doc ),
			Freshness_Analyzer::analyze( $doc, time(), $stale_days, $resolver )
		);
		$hits   = Injection_Guard::detect( $doc['raw_content'] . ' ' . $doc['title'] );
		if ( $hits ) {
			$issues[] = array(
				'check_code'     => 'security.prompt_injection',
				'category'       => 'security',
				'severity'       => 'high',
				'title'          => 'Content contains instructions aimed at AI systems',
				'detail'         => 'Possible prompt-injection text: “' . $hits[0]['match'] . '”.',
				'recommendation' => 'Review this text. If you did not add it, it may have been injected (e.g. via a compromised account or imported content) — remove it and check user accounts.',
			);
		}

		$count = 0;
		foreach ( $issues as $issue ) {
			$finding = array(
				'post_id'        => $post->ID,
				'category'       => $issue['category'],
				'severity'       => $issue['severity'],
				'check_code'     => $issue['check_code'],
				'title'          => $issue['title'],
				'detail'         => $issue['detail'] . ( ! empty( $issue['wcag'] ) ? ' (WCAG ' . $issue['wcag'] . ')' : '' ),
				'recommendation' => $issue['recommendation'],
				'source'         => 'rule',
			);
			// Deterministic fix suggestion where one is safe to make: meta description from the excerpt/lead text.
			if ( 'seo.meta_description_missing' === $issue['check_code'] ) {
				$suggest = self::suggest_description( $doc );
				if ( $suggest ) {
					$finding['fix_field'] = 'meta_description';
					$finding['fix_value'] = $suggest;
				}
			} elseif ( ! empty( $issue['fixable']['target'] ) ) {
				$finding['fix_target'] = $issue['fixable']['target'];
			}
			$r = Repository::add_finding( $scan_id, $finding );
			if ( ! $r['duplicate'] ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * First ~155 characters of the excerpt or body, cut on a word boundary.
	 *
	 * @param array $doc Document.
	 * @return string
	 */
	public static function suggest_description( array $doc ) {
		$src = '';
		// Prefer a hand-written excerpt, but fall back to the body when the excerpt is too short to describe the page.
		foreach ( array( 'excerpt', 'text' ) as $key ) {
			$candidate = isset( $doc[ $key ] ) ? trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( (string) $doc[ $key ] ) ) ) : '';
			if ( Content_Extractor::len( $candidate ) >= 50 ) {
				$src = $candidate;
				break;
			}
		}
		if ( '' === $src ) {
			return '';
		}
		if ( Content_Extractor::len( $src ) <= 155 ) {
			return $src;
		}
		$cut   = mb_substr( $src, 0, 152 );
		$words = preg_replace( '/\s+\S*$/u', '', $cut ); // Drop the partial last word.
		return rtrim( '' !== $words ? $words : $cut, ' ,;:-' ) . '…';
	}

	/**
	 * Markdown summary for a finished quick scan.
	 *
	 * @param int $scan_id Scan.
	 * @param int $posts   Posts scanned.
	 * @return string
	 */
	private static function summary( $scan_id, $posts ) {
		$by = array();
		foreach ( Repository::finding_stats( $scan_id ) as $row ) {
			$by[ $row['category'] ] = ( isset( $by[ $row['category'] ] ) ? $by[ $row['category'] ] : 0 ) + (int) $row['n'];
		}
		$parts = array();
		foreach ( $by as $cat => $n ) {
			$parts[] = "{$n} {$cat}";
		}
		return sprintf(
			"Rules-only scan of %d item%s found %s.\n\nRun an **AI audit** to have Claude verify these, prioritize them, and write ready-to-approve fixes.",
			$posts,
			1 === $posts ? '' : 's',
			$parts ? implode( ', ', $parts ) . ' issue(s)' : 'no issues'
		);
	}
}
