<?php
/**
 * Core bootstrap for WPCodeAlly Event Registration.
 *
 * @package WPCA_Event_Registration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main plugin class.
 */
final class WPCA_ER_Plugin {

	/**
	 * Option name for debug flag.
	 */
	public const OPTION_DEBUG = 'WPCA_ER_debug';

	/**
	 * Option: page ID for optional default post-checkout registration page (embed GF normally or use [WPCA_ER_registration]).
	 */
	public const OPTION_HUB_PAGE_ID = 'WPCA_ER_registration_page_id';

	/**
	 * Tracks shipped plugin version so rewrite rules can be flushed after an update.
	 */
	public const OPTION_INTERNAL_VERSION = 'WPCA_ER_internal_version';

	/**
	 * Singleton instance.
	 *
	 * @var WPCA_ER_Plugin|null
	 */
	private static $instance = null;

	/**
	 * Order bridge service instance.
	 *
	 * @var WPCA_ER_Order_Bridge|null
	 */
	public $bridge;

	/**
	 * Account endpoint service instance.
	 *
	 * @var WPCA_ER_Account|null
	 */
	public $account;

	/**
	 * Product admin service instance.
	 *
	 * @var WPCA_ER_Product_Admin|null
	 */
	public $product_admin;

	/**
	 * Checkout redirect service instance.
	 *
	 * @var WPCA_ER_Checkout_Redirect|null
	 */
	public $checkout_redirect;

	/**
	 * Public landing service instance.
	 *
	 * @var WPCA_ER_Public_Landing|null
	 */
	public $public_landing;

	/**
	 * My Account orders integration service instance.
	 *
	 * @var WPCA_ER_My_Orders_Registration|null
	 */
	public $my_orders_registration;

	/**
	 * Admin settings service instance.
	 *
	 * @var WPCA_ER_Admin_Settings|null
	 */
	public $admin_settings;

	/**
	 * Get singleton instance.
	 *
	 * @return WPCA_ER_Plugin
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * WPCA_ER_Plugin constructor.
	 */
	private function __construct() {
		add_action( 'before_woocommerce_init', array( $this, 'declare_hpos_compatibility' ) );
		add_action( 'plugins_loaded', array( $this, 'init' ), 20 );
	}

	/**
	 * Declare compatibility with WooCommerce custom order tables (HPOS).
	 *
	 * @return void
	 */
	public function declare_hpos_compatibility() {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', WPCA_ER_PLUGIN_FILE, true );
		}
	}

	/**
	 * Loads modules after WooCommerce and Gravity Forms are available.
	 *
	 * @return void
	 */
	public function init() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				static function () {
					echo '<div class="notice notice-error"><p>' . esc_html__( 'WPCodeAlly Event Registration requires WooCommerce to be active.', 'wpcodeally-event-registration' ) . '</p></div>';
				}
			);
			return;
		}

		if ( ! class_exists( 'GFForms' ) ) {
			add_action(
				'admin_notices',
				static function () {
					echo '<div class="notice notice-error"><p>' . esc_html__( 'WPCodeAlly Event Registration requires Gravity Forms to be active.', 'wpcodeally-event-registration' ) . '</p></div>';
				}
			);
			return;
		}

		require_once WPCA_ER_PATH . 'includes/class-wpca-er-order-bridge.php';
		require_once WPCA_ER_PATH . 'includes/class-wpca-er-product-admin.php';
		require_once WPCA_ER_PATH . 'includes/class-wpca-er-checkout-redirect.php';
		require_once WPCA_ER_PATH . 'includes/class-wpca-er-public-landing.php';
		require_once WPCA_ER_PATH . 'includes/class-wpca-er-account.php';
		require_once WPCA_ER_PATH . 'includes/class-wpca-er-my-orders-registration.php';

		$this->bridge                 = new WPCA_ER_Order_Bridge();
		$this->product_admin          = new WPCA_ER_Product_Admin();
		$this->checkout_redirect      = new WPCA_ER_Checkout_Redirect();
		$this->public_landing         = new WPCA_ER_Public_Landing();
		$this->account                = new WPCA_ER_Account();
		$this->my_orders_registration = new WPCA_ER_My_Orders_Registration();

		$this->bridge->hooks();
		$this->product_admin->hooks();
		$this->checkout_redirect->hooks();
		$this->public_landing->hooks();
		$this->account->hooks();
		$this->my_orders_registration->hooks();

		add_action( 'admin_init', array( $this, 'maybe_flush_rewrite_rules_on_upgrade' ) );

		if ( is_admin() ) {
			require_once WPCA_ER_PATH . 'includes/class-wpca-er-admin-settings.php';
			$this->admin_settings = new WPCA_ER_Admin_Settings();
			$this->admin_settings->hooks();
		}
	}

	/**
	 * Activation: flush rewrite rules for My Account endpoint.
	 *
	 * @return void
	 */
	public static function activate() {
		add_rewrite_endpoint( 'event-registration', EP_ROOT | EP_PAGES );
		flush_rewrite_rules( false );
		update_option( self::OPTION_INTERNAL_VERSION, WPCA_ER_VERSION );
	}

	/**
	 * Deactivation: flush rewrite rules.
	 *
	 * @return void
	 */
	public static function deactivate() {
		flush_rewrite_rules( false );
	}

	/**
	 * Whether debug logging is enabled (no PII in logs).
	 *
	 * @return bool
	 */
	public static function is_debug() {
		return (bool) get_option( self::OPTION_DEBUG, false );
	}

	/**
	 * Log a debug message when debug mode is on.
	 *
	 * @param string $message Message.
	 * @return void
	 */
	public static function log( $message ) {
		if ( ! self::is_debug() ) {
			return;
		}

		$file     = WP_CONTENT_DIR . '/wpcodeally-event-registration.log';
		$max_size = 1024 * 1024; // 1 MB

		// Rotate when the file exceeds the size limit.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_exists
		if ( file_exists( $file ) && filesize( $file ) >= $max_size ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename,WordPress.PHP.NoSilencedErrors.Discouraged -- best-effort log rotation.
			@rename( $file, $file . '.old' );
		}

		$line = gmdate( 'Y-m-d H:i:s' ) . ' ' . $message . "\n";

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents,WordPress.PHP.NoSilencedErrors.Discouraged -- best-effort debug logging.
		@file_put_contents( $file, $line, FILE_APPEND | LOCK_EX );
	}

	/**
	 * Flush permalinks once after a plugin version change so the My Account endpoint stays registered.
	 *
	 * @return void
	 */
	public function maybe_flush_rewrite_rules_on_upgrade() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$saved = (string) get_option( self::OPTION_INTERNAL_VERSION, '' );
		if ( WPCA_ER_VERSION === $saved ) {
			return;
		}

		update_option( self::OPTION_INTERNAL_VERSION, WPCA_ER_VERSION );
		flush_rewrite_rules( false );
	}
}
