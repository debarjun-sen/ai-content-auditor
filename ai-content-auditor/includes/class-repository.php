<?php
/**
 * Data access for scans, findings and activity events.
 *
 * @package AICA
 */

namespace AICA;

defined( 'ABSPATH' ) || exit;

/**
 * Thin wrapper around $wpdb. All queries are prepared.
 */
class Repository {

	const LOCK_OPTION = 'aica_active_scan';

	const TERMINAL = array( 'completed', 'failed', 'cancelled', 'budget_exhausted' );

	/**
	 * Table name helper.
	 *
	 * @param string $name scans|findings|events.
	 * @return string
	 */
	public static function table( $name ) {
		global $wpdb;
		return $wpdb->prefix . 'aica_' . $name;
	}

	/**
	 * Current UTC MySQL datetime.
	 *
	 * @return string
	 */
	private static function now() {
		return gmdate( 'Y-m-d H:i:s' );
	}

	// Section: Scans.

	/**
	 * Create a scan row.
	 *
	 * @param string $mode  quick|ai|mcp.
	 * @param array  $scope Scope (post types, limits, cursor).
	 * @param string $model Model ID (ai mode).
	 * @return int Scan ID.
	 */
	public static function create_scan( $mode, array $scope, $model = '' ) {
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			self::table( 'scans' ),
			array(
				'mode'       => $mode,
				'status'     => 'running',
				'model'      => $model,
				'started_by' => get_current_user_id(),
				'scope'      => wp_json_encode( $scope ),
				'messages'   => wp_json_encode( array() ),
				'created_at' => self::now(),
				'updated_at' => self::now(),
			)
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Fetch a scan with decoded JSON columns.
	 *
	 * @param int  $id               Scan ID.
	 * @param bool $include_messages Include the (large) conversation.
	 * @return array|null
	 */
	public static function get_scan( $id, $include_messages = false ) {
		global $wpdb;
		$table = self::table( 'scans' );
		$row   = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $table, $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		if ( ! $row ) {
			return null;
		}
		return self::hydrate_scan( $row, $include_messages );
	}

	/**
	 * Normalize a scan row.
	 *
	 * @param array $row              DB row.
	 * @param bool  $include_messages Keep messages.
	 * @return array
	 */
	private static function hydrate_scan( array $row, $include_messages ) {
		$row['scope'] = json_decode( (string) $row['scope'], true );
		$row['scope'] = is_array( $row['scope'] ) ? $row['scope'] : array();
		if ( $include_messages ) {
			$msgs            = json_decode( (string) $row['messages'], true );
			$row['messages'] = is_array( $msgs ) ? $msgs : array();
		} else {
			unset( $row['messages'] );
		}
		foreach ( array( 'id', 'started_by', 'iterations', 'posts_scanned', 'input_tokens', 'output_tokens', 'cache_read_tokens', 'cache_write_tokens' ) as $int ) {
			$row[ $int ] = (int) $row[ $int ];
		}
		$row['cost_usd']    = (float) $row['cost_usd'];
		$row['is_terminal'] = in_array( $row['status'], self::TERMINAL, true );
		return $row;
	}

	/**
	 * Update scan columns.
	 *
	 * @param int   $id     Scan ID.
	 * @param array $fields Column => value. Arrays are JSON-encoded.
	 */
	public static function update_scan( $id, array $fields ) {
		global $wpdb;
		foreach ( array( 'scope', 'messages' ) as $json ) {
			if ( isset( $fields[ $json ] ) && is_array( $fields[ $json ] ) ) {
				$fields[ $json ] = wp_json_encode( $fields[ $json ] );
			}
		}
		$fields['updated_at'] = self::now();
		if ( isset( $fields['status'] ) && in_array( $fields['status'], self::TERMINAL, true ) ) {
			$fields['finished_at'] = self::now();
		}
		$wpdb->update( self::table( 'scans' ), $fields, array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Recent scans (no messages).
	 *
	 * @param int $limit Limit.
	 * @return array
	 */
	public static function recent_scans( $limit = 10 ) {
		global $wpdb;
		$table = self::table( 'scans' );
		$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i ORDER BY id DESC LIMIT %d', $table, $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return array_map(
			static function ( $r ) {
				return self::hydrate_scan( $r, false );
			},
			(array) $rows
		);
	}

	/**
	 * Most recent finished (or running) scan of mode quick/ai.
	 *
	 * @return array|null
	 */
	public static function latest_scan() {
		global $wpdb;
		$table = self::table( 'scans' );
		$row   = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE mode IN ('quick','ai') AND status <> 'cancelled' ORDER BY id DESC LIMIT 1", $table ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return $row ? self::hydrate_scan( $row, false ) : null;
	}

	// Section: Locks.

	/**
	 * Try to take the global single-scan lock. Stale locks (>30 min idle) are released.
	 *
	 * @param int $scan_id Scan ID.
	 * @return bool
	 */
	public static function acquire_scan_lock( $scan_id ) {
		$current = get_option( self::LOCK_OPTION );
		if ( is_array( $current ) && ! empty( $current['scan_id'] ) ) {
			$scan = self::get_scan( (int) $current['scan_id'] );
			$idle = $scan ? time() - strtotime( $scan['updated_at'] . ' UTC' ) : PHP_INT_MAX;
			if ( $scan && ! $scan['is_terminal'] && $idle < 30 * MINUTE_IN_SECONDS ) {
				return false;
			}
			delete_option( self::LOCK_OPTION );
		}
		return add_option(
			self::LOCK_OPTION,
			array(
				'scan_id' => (int) $scan_id,
				'since'   => time(),
			),
			'',
			false
		);
	}

	/**
	 * Active scan ID or 0.
	 *
	 * @return int
	 */
	public static function active_scan_id() {
		$current = get_option( self::LOCK_OPTION );
		return is_array( $current ) ? (int) $current['scan_id'] : 0;
	}

	/**
	 * Release the lock if held by this scan.
	 *
	 * @param int $scan_id Scan ID.
	 */
	public static function release_scan_lock( $scan_id ) {
		if ( self::active_scan_id() === (int) $scan_id ) {
			delete_option( self::LOCK_OPTION );
		}
	}

	/**
	 * Per-scan step mutex so two tabs/cron can't run the same step concurrently.
	 * add_option() is atomic thanks to the unique option_name index.
	 *
	 * @param int $scan_id Scan ID.
	 * @return bool
	 */
	public static function acquire_step_lock( $scan_id ) {
		$name  = 'aica_step_lock_' . (int) $scan_id;
		$taken = get_option( $name );
		if ( $taken && time() - (int) $taken > 5 * MINUTE_IN_SECONDS ) {
			delete_option( $name ); // Crashed step; recover.
		}
		return add_option( $name, time(), '', false );
	}

	/**
	 * Release step mutex.
	 *
	 * @param int $scan_id Scan ID.
	 */
	public static function release_step_lock( $scan_id ) {
		delete_option( 'aica_step_lock_' . (int) $scan_id );
	}

	// Section: Findings.

	/**
	 * Insert a finding unless an identical one exists for the scan.
	 *
	 * @param int   $scan_id Scan ID.
	 * @param array $f       Finding fields.
	 * @return array{id:int,duplicate:bool}
	 */
	public static function add_finding( $scan_id, array $f ) {
		global $wpdb;
		$fingerprint = md5(
			implode(
				'|',
				array(
					(int) $f['post_id'],
					$f['category'],
					! empty( $f['check_code'] ) ? $f['check_code'] : strtolower( $f['title'] ),
					isset( $f['fix_target'] ) ? $f['fix_target'] : '',
				)
			)
		);
		$table       = self::table( 'findings' );
		$existing    = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i WHERE scan_id = %d AND fingerprint = %s', $table, $scan_id, $fingerprint ) ); // phpcs:ignore WordPress.DB
		if ( $existing ) {
			// The AI may refine a rule finding: let richer text / a fix overwrite it.
			$update = array_filter(
				array(
					'detail'         => isset( $f['detail'] ) ? $f['detail'] : null,
					'recommendation' => isset( $f['recommendation'] ) ? $f['recommendation'] : null,
					'fix_field'      => isset( $f['fix_field'] ) ? $f['fix_field'] : null,
					'fix_target'     => isset( $f['fix_target'] ) ? $f['fix_target'] : null,
					'fix_value'      => isset( $f['fix_value'] ) ? $f['fix_value'] : null,
				),
				static function ( $v ) {
					return null !== $v && '' !== $v;
				}
			);
			if ( $update ) {
				$update['updated_at'] = self::now();
				$wpdb->update( $table, $update, array( 'id' => $existing ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
			}
			return array(
				'id'        => $existing,
				'duplicate' => true,
			);
		}
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$table,
			array(
				'scan_id'        => (int) $scan_id,
				'post_id'        => (int) $f['post_id'],
				'category'       => $f['category'],
				'severity'       => $f['severity'],
				'check_code'     => isset( $f['check_code'] ) ? $f['check_code'] : '',
				'title'          => $f['title'],
				'detail'         => isset( $f['detail'] ) ? $f['detail'] : '',
				'recommendation' => isset( $f['recommendation'] ) ? $f['recommendation'] : '',
				'fix_field'      => isset( $f['fix_field'] ) ? $f['fix_field'] : '',
				'fix_target'     => isset( $f['fix_target'] ) ? $f['fix_target'] : '',
				'fix_value'      => isset( $f['fix_value'] ) ? $f['fix_value'] : '',
				'source'         => isset( $f['source'] ) ? $f['source'] : 'rule',
				'status'         => 'open',
				'fingerprint'    => $fingerprint,
				'created_at'     => self::now(),
				'updated_at'     => self::now(),
			)
		);
		return array(
			'id'        => (int) $wpdb->insert_id,
			'duplicate' => false,
		);
	}

	/**
	 * Single finding.
	 *
	 * @param int $id Finding ID.
	 * @return array|null
	 */
	public static function get_finding( $id ) {
		global $wpdb;
		$table = self::table( 'findings' );
		$row   = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', $table, $id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return $row ? self::hydrate_finding( $row ) : null;
	}

	/**
	 * Normalize a finding row for output.
	 *
	 * @param array $row Row.
	 * @return array
	 */
	private static function hydrate_finding( array $row ) {
		$row['id']       = (int) $row['id'];
		$row['scan_id']  = (int) $row['scan_id'];
		$row['post_id']  = (int) $row['post_id'];
		$row['has_fix']  = '' !== $row['fix_field'] && '' !== (string) $row['fix_value'];
		$row['can_undo'] = 'fixed' === $row['status'] && null !== $row['fix_previous'] && '' !== $row['fix_previous'];
		unset( $row['fix_previous'], $row['fingerprint'] );
		return $row;
	}

	/**
	 * Query findings.
	 *
	 * @param array $args scan_id, category, severity, status, post_id, search, limit, offset.
	 * @return array{items:array,total:int}
	 */
	public static function query_findings( array $args ) {
		global $wpdb;
		$table = self::table( 'findings' );
		$where = array( '1=1' );
		$vals  = array();
		foreach ( array(
			'scan_id'  => '%d',
			'post_id'  => '%d',
			'category' => '%s',
			'severity' => '%s',
			'status'   => '%s',
		) as $col => $ph ) {
			if ( isset( $args[ $col ] ) && '' !== $args[ $col ] && null !== $args[ $col ] ) {
				$where[] = "{$col} = {$ph}";
				$vals[]  = $args[ $col ];
			}
		}
		if ( ! empty( $args['search'] ) ) {
			$where[] = '(title LIKE %s OR detail LIKE %s)';
			$like    = '%' . $wpdb->esc_like( $args['search'] ) . '%';
			$vals[]  = $like;
			$vals[]  = $like;
		}
		$where_sql = implode( ' AND ', $where );
		$limit     = isset( $args['limit'] ) ? max( 1, min( 500, (int) $args['limit'] ) ) : 100;
		$offset    = isset( $args['offset'] ) ? max( 0, (int) $args['offset'] ) : 0;

		$order = "FIELD(status,'open','fixed','dismissed'), FIELD(severity,'high','medium','low'), id ASC";
		// phpcs:disable WordPress.DB
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE {$where_sql}", array_merge( array( $table ), $vals ) ) );
		$vals2 = array_merge( array( $table ), $vals, array( $limit, $offset ) );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE {$where_sql} ORDER BY {$order} LIMIT %d OFFSET %d", $vals2 ), ARRAY_A );
		// phpcs:enable

		return array(
			'items' => array_map( array( __CLASS__, 'hydrate_finding' ), (array) $rows ),
			'total' => $total,
		);
	}

	/**
	 * Raw fix_previous value (for undo).
	 *
	 * @param int $id Finding ID.
	 * @return string|null
	 */
	public static function get_fix_previous( $id ) {
		global $wpdb;
		$table = self::table( 'findings' );
		return $wpdb->get_var( $wpdb->prepare( 'SELECT fix_previous FROM %i WHERE id = %d', $table, $id ) ); // phpcs:ignore WordPress.DB
	}

	/**
	 * Update a finding.
	 *
	 * @param int   $id     Finding ID.
	 * @param array $fields Columns.
	 */
	public static function update_finding( $id, array $fields ) {
		global $wpdb;
		$fields['updated_at'] = self::now();
		$wpdb->update( self::table( 'findings' ), $fields, array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Counts per category/severity/status for a scan.
	 *
	 * @param int $scan_id Scan ID.
	 * @return array
	 */
	public static function finding_stats( $scan_id ) {
		global $wpdb;
		$table = self::table( 'findings' );
		$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT category, severity, status, COUNT(*) AS n FROM %i WHERE scan_id = %d GROUP BY category, severity, status', $table, $scan_id ), ARRAY_A ); // phpcs:ignore WordPress.DB
		return (array) $rows;
	}

	// Section: Events (activity timeline).

	/**
	 * Log an activity event.
	 *
	 * @param int    $scan_id Scan ID.
	 * @param string $type    tool_call|tool_result|assistant|info|safety|error|usage.
	 * @param string $summary Human summary (plain text).
	 * @param array  $payload Extra data (truncated).
	 * @param string $tool    Tool name.
	 * @return int
	 */
	public static function log_event( $scan_id, $type, $summary, array $payload = array(), $tool = '' ) {
		global $wpdb;
		$json = wp_json_encode( $payload );
		if ( strlen( (string) $json ) > 20000 ) {
			$json = wp_json_encode( array( 'truncated' => true ) );
		}
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			self::table( 'events' ),
			array(
				'scan_id'    => (int) $scan_id,
				'type'       => $type,
				'tool'       => $tool,
				'summary'    => function_exists( 'mb_substr' ) ? mb_substr( $summary, 0, 500 ) : substr( $summary, 0, 500 ),
				'payload'    => $json,
				'created_at' => self::now(),
			)
		);
		return (int) $wpdb->insert_id;
	}

	/**
	 * Events after a cursor.
	 *
	 * @param int $scan_id Scan ID.
	 * @param int $after   Event ID cursor.
	 * @param int $limit   Limit.
	 * @return array
	 */
	public static function events( $scan_id, $after = 0, $limit = 200 ) {
		global $wpdb;
		$table = self::table( 'events' );
		$rows  = $wpdb->get_results( $wpdb->prepare( 'SELECT id, type, tool, summary, payload, created_at FROM %i WHERE scan_id = %d AND id > %d ORDER BY id ASC LIMIT %d', $table, $scan_id, $after, $limit ), ARRAY_A ); // phpcs:ignore WordPress.DB
		foreach ( $rows as &$r ) {
			$r['id']      = (int) $r['id'];
			$r['payload'] = json_decode( (string) $r['payload'], true );
		}
		return (array) $rows;
	}
}
