<?php
/**
 * WP-CLI commands: `wp aica audit|findings|score|tools`.
 *
 * @package AICA
 */

namespace AICA;

use AICA\Agent\Audit_Agent;
use AICA\Agent\Quick_Scanner;
use AICA\Tools\Tool_Context;
use AICA\Tools\Tool_Registry;
use WP_CLI;

defined( 'ABSPATH' ) || exit;

/**
 * Audit your content for SEO, accessibility and stale-content issues.
 */
class Cli {

	/**
	 * Run an audit to completion.
	 *
	 * ## OPTIONS
	 *
	 * [--mode=<mode>]
	 * : quick (rules only, no API key) or ai (Claude agent).
	 * ---
	 * default: quick
	 * options:
	 *   - quick
	 *   - ai
	 * ---
	 *
	 * [--max-posts=<n>]
	 * : Limit the number of posts audited.
	 *
	 * ## EXAMPLES
	 *
	 *     wp aica audit
	 *     wp aica audit --mode=ai --max-posts=10
	 *
	 * @param array $args  Positional.
	 * @param array $assoc Assoc.
	 */
	public function audit( $args, $assoc ) {
		$mode      = isset( $assoc['mode'] ) ? $assoc['mode'] : 'quick';
		$overrides = isset( $assoc['max-posts'] ) ? array( 'max_posts' => (int) $assoc['max-posts'] ) : array();
		if ( 'ai' === $mode && ! Secrets::has_key() ) {
			WP_CLI::error( 'No API key. Define AICA_ANTHROPIC_API_KEY or set ANTHROPIC_API_KEY.' );
		}
		$scan = 'ai' === $mode ? Audit_Agent::start( $overrides ) : Quick_Scanner::start( $overrides );
		if ( is_wp_error( $scan ) ) {
			WP_CLI::error( $scan->get_error_message() );
		}
		$id     = $scan['id'];
		$cursor = 0;
		WP_CLI::log( "Scan #{$id} started ({$mode})." );
		do {
			$r = 'ai' === $mode ? ( new Audit_Agent() )->step( $id ) : Quick_Scanner::step( $id );
			foreach ( Repository::events( $id, $cursor ) as $e ) {
				$cursor = $e['id'];
				WP_CLI::log( sprintf( '  [%s] %s', str_pad( $e['type'], 11 ), $e['summary'] ) );
			}
			if ( is_wp_error( $r ) ) {
				$data = $r->get_error_data();
				if ( empty( $data['retryable'] ) ) {
					WP_CLI::error( $r->get_error_message() );
				}
				WP_CLI::warning( $r->get_error_message() . ' Retrying in 10s…' );
				sleep( 10 );
			}
			$scan = Repository::get_scan( $id );
		} while ( $scan && ! $scan['is_terminal'] );

		$sum = Scoring::summarize( Repository::finding_stats( $id ), $scan['posts_scanned'] );
		WP_CLI::log( '' );
		if ( $scan['summary'] ) {
			WP_CLI::log( $scan['summary'] );
			WP_CLI::log( '' );
		}
		WP_CLI::success( sprintf( 'Scan #%d %s — score %d (%s), %d findings, %d posts, $%.4f.', $id, $scan['status'], $sum['score'], $sum['grade'], $sum['totals']['total'], $scan['posts_scanned'], $scan['cost_usd'] ) );
	}

	/**
	 * List findings of the latest scan.
	 *
	 * ## OPTIONS
	 *
	 * [--status=<status>]
	 * : open|fixed|dismissed.
	 *
	 * [--format=<format>]
	 * : table, json, csv.
	 * ---
	 * default: table
	 * ---
	 *
	 * @param array $args  Positional.
	 * @param array $assoc Assoc.
	 */
	public function findings( $args, $assoc ) {
		$latest = Repository::latest_scan();
		if ( ! $latest ) {
			WP_CLI::error( 'No scans yet. Run `wp aica audit`.' );
		}
		$res = Repository::query_findings(
			array(
				'scan_id' => $latest['id'],
				'status'  => isset( $assoc['status'] ) ? $assoc['status'] : null,
				'limit'   => 500,
			)
		);
		WP_CLI\Utils\format_items( isset( $assoc['format'] ) ? $assoc['format'] : 'table', $res['items'], array( 'id', 'post_id', 'severity', 'category', 'title', 'status', 'source' ) );
	}

	/**
	 * Print the health score of the latest scan.
	 */
	public function score() {
		$latest = Repository::latest_scan();
		if ( ! $latest ) {
			WP_CLI::error( 'No scans yet.' );
		}
		$sum = Scoring::summarize( Repository::finding_stats( $latest['id'] ), $latest['posts_scanned'] );
		WP_CLI::log( sprintf( 'Overall: %d (%s)', $sum['score'], $sum['grade'] ) );
		foreach ( $sum['categories'] as $c => $v ) {
			WP_CLI::log( sprintf( '  %-14s %3d   open: %d', $c, $v['score'], $v['open'] ) );
		}
	}

	/**
	 * Call a tool directly (handy for debugging the MCP surface).
	 *
	 * ## OPTIONS
	 *
	 * <name>
	 * : Tool name, e.g. check_seo.
	 *
	 * [<json>]
	 * : JSON input, e.g. '{"post_id": 12}'.
	 *
	 * @param array $args Positional.
	 */
	public function tool( $args ) {
		$input = isset( $args[1] ) ? json_decode( $args[1], true ) : array();
		$out   = Tool_Registry::execute( $args[0], is_array( $input ) ? $input : array(), new Tool_Context( 0, 'cli', 50 ) );
		if ( is_wp_error( $out ) ) {
			WP_CLI::error( $out->get_error_message() );
		}
		WP_CLI::log( wp_json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}
}
