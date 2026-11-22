<?php
/**
 * Activation, deactivation and schema management.
 *
 * @package AICA
 */

namespace AICA;

use AICA\Agent\Audit_Agent;

defined( 'ABSPATH' ) || exit;

/**
 * Creates and upgrades the plugin's custom tables.
 */
class Installer {

	/**
	 * Activation hook.
	 */
	public static function activate() {
		self::install_schema();
		if ( false === get_option( Settings::OPTION ) ) {
			add_option( Settings::OPTION, Settings::defaults(), '', false );
		}
	}

	/**
	 * Deactivation hook: stop background work, keep data.
	 */
	public static function deactivate() {
		wp_unschedule_hook( Audit_Agent::CRON_HOOK );
		delete_option( Repository::LOCK_OPTION );
	}

	/**
	 * Run schema install when the stored DB version is behind.
	 */
	public static function maybe_upgrade() {
		if ( get_option( 'aica_db_version' ) !== AICA_DB_VERSION ) {
			self::install_schema();
		}
	}

	/**
	 * Create / update tables with dbDelta.
	 */
	public static function install_schema() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset = $wpdb->get_charset_collate();
		$scans   = $wpdb->prefix . 'aica_scans';
		$finds   = $wpdb->prefix . 'aica_findings';
		$events  = $wpdb->prefix . 'aica_events';

		dbDelta(
			"CREATE TABLE {$scans} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				mode varchar(20) NOT NULL DEFAULT 'quick',
				status varchar(20) NOT NULL DEFAULT 'running',
				model varchar(64) NOT NULL DEFAULT '',
				started_by bigint(20) unsigned NOT NULL DEFAULT 0,
				scope longtext NULL,
				messages longtext NULL,
				summary longtext NULL,
				error text NULL,
				iterations int(10) unsigned NOT NULL DEFAULT 0,
				posts_scanned int(10) unsigned NOT NULL DEFAULT 0,
				input_tokens bigint(20) unsigned NOT NULL DEFAULT 0,
				output_tokens bigint(20) unsigned NOT NULL DEFAULT 0,
				cache_read_tokens bigint(20) unsigned NOT NULL DEFAULT 0,
				cache_write_tokens bigint(20) unsigned NOT NULL DEFAULT 0,
				cost_usd decimal(10,4) NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				finished_at datetime NULL,
				PRIMARY KEY  (id),
				KEY status (status)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$finds} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				scan_id bigint(20) unsigned NOT NULL,
				post_id bigint(20) unsigned NOT NULL DEFAULT 0,
				category varchar(20) NOT NULL,
				severity varchar(10) NOT NULL,
				check_code varchar(64) NOT NULL DEFAULT '',
				title varchar(255) NOT NULL,
				detail text NULL,
				recommendation text NULL,
				fix_field varchar(32) NOT NULL DEFAULT '',
				fix_target varchar(255) NOT NULL DEFAULT '',
				fix_value text NULL,
				fix_previous longtext NULL,
				source varchar(10) NOT NULL DEFAULT 'rule',
				status varchar(12) NOT NULL DEFAULT 'open',
				fingerprint char(32) NOT NULL,
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY scan_fingerprint (scan_id,fingerprint),
				KEY scan_status (scan_id,status),
				KEY post_id (post_id)
			) {$charset};"
		);

		dbDelta(
			"CREATE TABLE {$events} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				scan_id bigint(20) unsigned NOT NULL,
				type varchar(20) NOT NULL,
				tool varchar(64) NOT NULL DEFAULT '',
				summary varchar(500) NOT NULL DEFAULT '',
				payload longtext NULL,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY scan_id (scan_id,id)
			) {$charset};"
		);

		update_option( 'aica_db_version', AICA_DB_VERSION, false );
	}
}
