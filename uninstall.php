<?php
/**
 * Uninstall Filtron — removes tables and options only when opted in.
 *
 * @package Filtron
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

$settings = get_option( 'filtron_settings', array() );
$delete_data = ! empty( $settings['delete_data_on_uninstall'] ) || ! empty( $settings['remove_data_on_uninstall'] );
if ( ! $delete_data ) {
	exit;
}

$tables = array(
	$wpdb->prefix . 'filtron_analytics',
	$wpdb->prefix . 'filtron_index',
	$wpdb->prefix . 'filtron_items',
	$wpdb->prefix . 'filtron_groups',
);

foreach ( $tables as $table ) {
	// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- DROP with escaped table name from prefix.
	$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" );
}

$option_pattern = $wpdb->esc_like( 'filtron_' ) . '%';
// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name; pattern bound via prepare.
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s", $option_pattern ) );
