<?php
/**
 * Plugin Name:       WPCodeAlly Event Registration
 * Plugin URI:        https://www.wpcodeally.com/
 * Description:       Links Gravity Forms registrations to WooCommerce orders. Redirects customers to a per-product registration page after checkout, pre-fills the form on return visits, and adds Edit registration buttons to My Account → Orders.
 * Version:           1.0.9
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            WPCodeAlly
 * Author URI:        https://www.wpcodeally.com/
 * Text Domain:       wpcodeally-event-registration
 * Domain Path:       /languages
 *
 * @package WPCA_Event_Registration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WPCA_ER_VERSION', '1.0.9' );
define( 'WPCA_ER_PLUGIN_FILE', __FILE__ );
define( 'WPCA_ER_PATH', plugin_dir_path( __FILE__ ) );
define( 'WPCA_ER_URL', plugin_dir_url( __FILE__ ) );
define( 'WPCA_ER_BASENAME', plugin_basename( __FILE__ ) );

require_once WPCA_ER_PATH . 'includes/class-wpca-er-plugin.php';

/**
 * Return the main plugin instance.
 *
 * @return WPCA_ER_Plugin
 */
function wpca_er() {
	return WPCA_ER_Plugin::instance();
}

wpca_er();

register_activation_hook(
	WPCA_ER_PLUGIN_FILE,
	static function () {
		WPCA_ER_Plugin::activate();
	}
);

register_deactivation_hook(
	WPCA_ER_PLUGIN_FILE,
	static function () {
		WPCA_ER_Plugin::deactivate();
	}
);
