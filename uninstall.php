<?php
/**
 * Removes all plugin data.
 *
 * Runs only when the plugin is deleted from the WordPress admin, which is an
 * explicit request to discard the routing table.
 *
 * @package WPLinker
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

$wplinker_table = $wpdb->prefix . 'custom_routes';

// phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
$wpdb->query( "DROP TABLE IF EXISTS {$wplinker_table}" );

delete_option( 'wplinker_db_version' );

// Per user screen options set by the routes list table.
$wpdb->query( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
	$wpdb->prepare( "DELETE FROM {$wpdb->usermeta} WHERE meta_key = %s", 'wplinker_per_page' )
);
