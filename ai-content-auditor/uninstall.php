<?php
/**
 * Uninstall: only removes data when the owner opted in (Settings → Delete data on uninstall).
 *
 * @package AICA
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$aica_settings = get_option( 'aica_settings', array() );
if ( empty( $aica_settings['delete_on_uninstall'] ) ) {
	return;
}

global $wpdb;
foreach ( array( 'aica_scans', 'aica_findings', 'aica_events' ) as $aica_table ) {
	$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', $wpdb->prefix . $aica_table ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
}
$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE option_name LIKE %s', $wpdb->options, $wpdb->esc_like( 'aica_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
wp_unschedule_hook( 'aica_continue_scan' );
// Approved meta descriptions are user content: kept on purpose.
