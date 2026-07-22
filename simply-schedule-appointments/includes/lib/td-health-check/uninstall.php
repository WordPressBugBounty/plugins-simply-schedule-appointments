<?php
/**
 * Perform plugin installation routines.
 *
 * @package Health Check
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

global $wpdb;

// Make sure the uninstall file can't be accessed directly.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	die; // phpcs:ignore
}

// Remove options introduced by the plugin.
delete_option( 'health-check-disable-plugin-hash' );
delete_option( 'health-check-default-theme' );
delete_option( 'health-check-current-theme' );
delete_option( 'health-check-dashboard-notices' );

/*
 * Remove any user meta entries we made, done with a custom query as core
 * does not provide an option to clear them for all users.
 */
// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- One-time uninstall cleanup; core provides no API to delete a meta_key across all users, and a delete run once at uninstall is not cacheable.
$wpdb->delete(
	$wpdb->usermeta,
	array(
		'meta_key' => 'health-check',
	)
);
// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.SlowDBQuery.slow_db_query_meta_key

// Remove the old Must-Use plugin if it was implemented.
if ( file_exists( trailingslashit( WPMU_PLUGIN_DIR ) . 'health-check-disable-plugins.php' ) ) {
	wp_delete_file( trailingslashit( WPMU_PLUGIN_DIR ) . 'health-check-disable-plugins.php' );
}

// Remove the renamed Must-Use plugin if it exists
if ( file_exists( trailingslashit( WPMU_PLUGIN_DIR ) . 'health-check-troubleshooting-mode.php' ) ) {
	wp_delete_file( trailingslashit( WPMU_PLUGIN_DIR ) . 'health-check-troubleshooting-mode.php' );
}
