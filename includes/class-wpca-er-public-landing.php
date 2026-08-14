<?php
/**
 * Front-end shortcode for the registration landing page.
 *
 * @package WPCA_Event_Registration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Optional shortcode [WPCA_ER_registration] for shared/default landing pages; embedded Gravity Forms also work without it.
 */
class WPCA_ER_Public_Landing {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function hooks() {
		add_shortcode( 'WPCA_ER_registration', array( $this, 'shortcode_registration' ) );
		add_shortcode( 'wpca_er_registration', array( $this, 'shortcode_registration' ) );
	}

	/**
	 * Shortcode wrapper for {@see self::get_registration_markup_from_request()}.
	 *
	 * @param string[] $atts Shortcode attributes (unused).
	 * @return string
	 */
	public function shortcode_registration( $atts ) {
		unset( $atts );

		return self::get_registration_markup_from_request();
	}

	/**
	 * Gravity Form markup for the current HTTP request when `order` and `key` query args are valid.
	 *
	 * Used by the shortcode and by My Account → Event registrations when opened with registration query args.
	 *
	 * @return string HTML.
	 */
	public static function get_registration_markup_from_request() {
		$order_id  = isset( $_GET['order'] ) ? absint( wp_unslash( $_GET['order'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$order_key = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! $order_id || ! $order_key ) {
			return '<div class="wpca-er-landing woocommerce-info"><p>' . esc_html__( 'Your registration link will appear after you complete an order.', 'wpcodeally-event-registration' ) . '</p></div>';
		}

		$order = wc_get_order( $order_id );
		if ( ! $order || ! hash_equals( (string) $order->get_order_key(), (string) $order_key ) ) {
			return '<div class="wpca-er-landing woocommerce-error"><p>' . esc_html__( 'This registration link is invalid or has expired.', 'wpcodeally-event-registration' ) . '</p></div>';
		}

		if ( ! WPCA_ER_Plugin::order_allows_registration( $order ) ) {
			return '<div class="wpca-er-landing woocommerce-error"><p>' . esc_html__( 'Registration is only available for paid or processing orders.', 'wpcodeally-event-registration' ) . '</p></div>';
		}

		$item_id = WPCA_ER_Order_Bridge::get_request_item_id();
		$item    = null;

		if ( $item_id ) {
			$candidate = $order->get_item( $item_id );
			if ( $candidate instanceof WC_Order_Item_Product ) {
				$item = $candidate;
			}
		} else {
			$item = self::find_first_registration_item( $order );
		}

		if ( ! $item instanceof WC_Order_Item_Product ) {
			return '<div class="wpca-er-landing woocommerce-error"><p>' . esc_html__( 'No registration was found for this order.', 'wpcodeally-event-registration' ) . '</p></div>';
		}

		$product = $item->get_product();
		if ( ! $product ) {
			return '<div class="wpca-er-landing woocommerce-error"><p>' . esc_html__( 'The product for this registration could not be found.', 'wpcodeally-event-registration' ) . '</p></div>';
		}

		$form_id = WPCA_ER_Product_Admin::get_registration_form_id_for_order_item( $item );
		if ( ! $form_id ) {
			return '<div class="wpca-er-landing woocommerce-error"><p>' . esc_html__( 'No registration form is configured for this purchase.', 'wpcodeally-event-registration' ) . '</p></div>';
		}

		if ( ! function_exists( 'gravity_form' ) ) {
			return '<div class="wpca-er-landing woocommerce-error"><p>' . esc_html__( 'Gravity Forms is required to display the registration form.', 'wpcodeally-event-registration' ) . '</p></div>';
		}

		ob_start();
		gravity_form( $form_id, true, true, false, null, true, 1, true );

		return ob_get_clean();
	}

	/**
	 * First order line that has a GF registration form ID.
	 *
	 * @param WC_Order $order Order.
	 * @return WC_Order_Item_Product|null
	 */
	private static function find_first_registration_item( $order ) {
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
			$form_id = WPCA_ER_Product_Admin::get_registration_form_id_for_order_item( $item );
			if ( $form_id ) {
				return $item;
			}
		}

		return null;
	}
}
