<?php
/**
 * Uninstall WPCodeAlly Event Registration.
 *
 * Removes plugin options only. WooCommerce order meta, line item meta, and Gravity Forms
 * entry meta created by this plugin are left in place so customer records are preserved.
 *
 * @package WPCA_Event_Registration
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/**
 * Delete plugin options for the current site.
 *
 * @return void
 */
function wpca_er_delete_plugin_options() {
	delete_option( 'WPCA_ER_debug' );
	delete_option( 'WPCA_ER_registration_page_id' );
	delete_option( 'WPCA_ER_internal_version' );
}

/**
 * Delete the plugin debug log file when present.
 *
 * @return void
 */
function wpca_er_delete_debug_log_file() {
	$log_file = WP_CONTENT_DIR . '/wpcodeally-event-registration.log';
	if ( file_exists( $log_file ) ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink,WordPress.PHP.NoSilencedErrors.Discouraged -- best-effort cleanup.
		@unlink( $log_file );
	}
}

if ( is_multisite() ) {
	$site_ids = get_sites(
		array(
			'fields' => 'ids',
			'number' => 0,
		)
	);

	foreach ( $site_ids as $site_id ) {
		switch_to_blog( (int) $site_id );
		wpca_er_delete_plugin_options();
		restore_current_blog();
	}

	wpca_er_delete_debug_log_file();
} else {
	wpca_er_delete_plugin_options();
	wpca_er_delete_debug_log_file();
}
