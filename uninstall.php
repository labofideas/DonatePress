<?php
/**
 * Uninstall handler for DonatePress.
 *
 * This removes plugin options only.
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'donatepress_settings' );
delete_option( 'donatepress_version' );
delete_option( 'donatepress_setup_completed' );
