<?php
/**
 * Uninstall WPCodeAlly Event Registration.
 *
 * Removes plugin options only. WooCommerce line item meta and Gravity Forms
 * entry meta created by this plugin are left in place so customer records are preserved.
 *
 * @package WPCA_Event_Registration
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

delete_option( 'WPCA_ER_debug' );
delete_option( 'WPCA_ER_registration_page_id' );
delete_option( 'WPCA_ER_internal_version' );

$log_file = WP_CONTENT_DIR . '/wpcodeally-event-registration.log';
if ( file_exists( $log_file ) ) {
	// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.PHP.NoSilencedErrors.Discouraged -- best-effort cleanup.
	@unlink( $log_file );
}
