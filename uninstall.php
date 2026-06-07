<?php
/**
 * Uninstall handler for DonatePress.
 *
 * Removes plugin options. Optionally drops all custom tables when
 * the "delete_data_on_uninstall" setting is enabled.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$settings = get_option( 'donatepress_settings', array() );

if ( ! empty( $settings['delete_data_on_uninstall'] ) ) {
	global $wpdb;

	$tables = array(
		$wpdb->prefix . 'dp_audit_logs',
		$wpdb->prefix . 'dp_webhook_events',
		$wpdb->prefix . 'dp_portal_tokens',
		$wpdb->prefix . 'dp_campaign_updates',
		$wpdb->prefix . 'dp_donations',
		$wpdb->prefix . 'dp_subscriptions',
		$wpdb->prefix . 'dp_campaigns',
		$wpdb->prefix . 'dp_forms',
		$wpdb->prefix . 'dp_donors',
	);

	foreach ( $tables as $table ) {
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.SchemaChange,WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
}

delete_option( 'donatepress_settings' );
delete_option( 'donatepress_version' );
delete_option( 'donatepress_db_version' );
delete_option( 'donatepress_setup_completed' );
delete_option( 'donatepress_demo_import' );
