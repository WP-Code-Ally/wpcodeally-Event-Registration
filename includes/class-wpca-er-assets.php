<?php
/**
 * Registers plugin styles on the screens that need them.
 *
 * @package WPCA_Event_Registration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Conditional asset loading for admin and front-end contexts.
 */
class WPCA_ER_Assets {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function hooks() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_frontend' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin' ) );
	}

	/**
	 * Enqueue front-end stylesheet when registration UI may appear.
	 *
	 * @return void
	 */
	public static function enqueue_frontend() {
		if ( ! self::should_load_frontend_styles() ) {
			return;
		}

		wp_enqueue_style(
			'wpca-er-frontend',
			WPCA_ER_URL . 'assets/css/frontend.css',
			array(),
			WPCA_ER_VERSION
		);
	}

	/**
	 * Enqueue admin stylesheet on product and settings screens.
	 *
	 * @param string $hook_suffix Current admin page hook suffix.
	 * @return void
	 */
	public static function enqueue_admin( $hook_suffix ) {
		unset( $hook_suffix );

		if ( ! self::should_load_admin_styles() ) {
			return;
		}

		wp_enqueue_style(
			'wpca-er-admin',
			WPCA_ER_URL . 'assets/css/admin.css',
			array(),
			WPCA_ER_VERSION
		);
	}

	/**
	 * Whether the front-end stylesheet should load on this request.
	 *
	 * @return bool
	 */
	private static function should_load_frontend_styles() {
		if ( function_exists( 'is_account_page' ) && is_account_page() ) {
			if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( WPCA_ER_Account::ENDPOINT ) ) {
				return true;
			}

			if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'orders' ) ) {
				return true;
			}
		}

		if ( isset( $_GET['order'], $_GET['key'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return true;
		}

		global $post;

		if ( $post instanceof WP_Post ) {
			if ( has_shortcode( $post->post_content, 'WPCA_ER_registration' ) || has_shortcode( $post->post_content, 'wpca_er_registration' ) ) {
				return true;
			}

			$hub_id = absint( get_option( WPCA_ER_Plugin::OPTION_HUB_PAGE_ID, 0 ) );
			if ( $hub_id && (int) $post->ID === $hub_id ) {
				return true;
			}
		}

		return (bool) apply_filters( 'wpca_er_enqueue_frontend_styles', false );
	}

	/**
	 * Whether the admin stylesheet should load on this request.
	 *
	 * @return bool
	 */
	private static function should_load_admin_styles() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

		if ( $screen && 'product' === $screen->id ) {
			return true;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page detection.
		if ( isset( $_GET['page'] ) && 'wpcodeally-event-registration' === sanitize_text_field( wp_unslash( $_GET['page'] ) ) ) {
			return true;
		}

		return false;
	}
}
