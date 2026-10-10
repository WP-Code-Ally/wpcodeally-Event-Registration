<?php
/**
 * Restores WooCommerce thank-you tracking on the first checkout-return registration landing.
 *
 * @package WPCA_Event_Registration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Treats the first post-checkout registration page as Woo’s thank-you page for trackers.
 *
 * Does not change the visible landing. My Account registration URLs omit the checkout flag
 * and never fire Purchase. Refresh after the order meta stamp does not spoof or emit again.
 */
class WPCA_ER_Thankyou_Bridge {

	/**
	 * Checkout-return query flag (same name as the redirect URL builder).
	 */
	public const QUERY_FLAG = WPCA_ER_Checkout_Redirect::QUERY_CHECKOUT_FLAG;

	/**
	 * Order meta: thank-you already emitted for this shop order.
	 */
	public const META_EMITTED = '_wpca_er_thankyou_emitted';

	/**
	 * Whether request validation has run this request.
	 *
	 * @var bool
	 */
	private $resolved = false;

	/**
	 * Validated checkout-return order, or null when this request is not a first landing.
	 *
	 * @var WC_Order|null
	 */
	private $order = null;

	/**
	 * Whether this request should spoof thank-you and emit woocommerce_thankyou once.
	 *
	 * @var bool
	 */
	private $should_emit = false;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function hooks() {
		add_action( 'wp', array( $this, 'maybe_spoof_thankyou_context' ), 1 );
		add_action( 'wp_footer', array( $this, 'maybe_emit_thankyou' ), 1 );
	}

	/**
	 * Whether the checkout-return flag is present and set to 1.
	 *
	 * @param array $get Request query args (typically $_GET).
	 * @return bool
	 */
	public static function is_checkout_flag_present( $get ) {
		if ( ! is_array( $get ) || ! isset( $get[ self::QUERY_FLAG ] ) ) {
			return false;
		}

		return '1' === (string) $get[ self::QUERY_FLAG ];
	}

	/**
	 * Whether the supplied key matches the order key.
	 *
	 * @param mixed  $order Order object.
	 * @param string $key   Candidate order key.
	 * @return bool
	 */
	public static function order_matches_key( $order, $key ) {
		if ( ! $order instanceof WC_Order || ! is_string( $key ) || '' === $key ) {
			return false;
		}

		return hash_equals( (string) $order->get_order_key(), $key );
	}

	/**
	 * On a first checkout-return landing, restore Woo thank-you query vars and filters.
	 *
	 * @return void
	 */
	public function maybe_spoof_thankyou_context() {
		$order = $this->get_validated_checkout_order();
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		if ( 'yes' === (string) $order->get_meta( self::META_EMITTED, true ) ) {
			return;
		}

		$order_id = (string) $order->get_id();

		// Do NOT set $wp->query_vars['order-received']. That makes is_wc_endpoint_url() true on a
		// non-checkout page, and wc_template_redirect() then 404s / redirects to home — so the
		// registration form never appears after checkout.
		//
		// PYS wooGetOrderIdFromRequest() typically reads order-received query var and/or GET;
		// pysWooGetSubmittedOrderKey() already looks at key / order_key (already in the URL).
		$_GET['order-received'] = $order_id; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		add_filter( 'woocommerce_is_order_received_page', '__return_true' );
		add_filter( 'woocommerce_thankyou_order_id', array( $this, 'filter_thankyou_order_id' ) );
		add_filter( 'woocommerce_thankyou_order_key', array( $this, 'filter_thankyou_order_key' ) );
		// Belt-and-suspenders if another plugin stamps the endpoint query var.
		add_filter( 'woocommerce_account_endpoint_page_not_found', array( $this, 'allow_registration_landing_endpoint' ) );

		$this->should_emit = true;
	}

	/**
	 * Prevent Woo from treating the registration landing as a misplaced account/checkout endpoint.
	 *
	 * @param bool $not_found Whether to trigger the endpoint-not-found redirect/404.
	 * @return bool
	 */
	public function allow_registration_landing_endpoint( $not_found ) {
		if ( $this->should_emit || $this->get_validated_checkout_order() instanceof WC_Order ) {
			return false;
		}

		return $not_found;
	}

	/**
	 * Fire woocommerce_thankyou once and stamp order meta so refresh does not double.
	 *
	 * Runs in the footer so the registration form stays first in the HTML.
	 *
	 * @return void
	 */
	public function maybe_emit_thankyou() {
		if ( ! $this->should_emit || ! $this->order instanceof WC_Order ) {
			return;
		}

		if ( 'yes' === (string) $this->order->get_meta( self::META_EMITTED, true ) ) {
			return;
		}

		$order_id = $this->order->get_id();

		do_action( 'woocommerce_thankyou', $order_id ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- existing Woo hook.

		$this->order->update_meta_data( self::META_EMITTED, 'yes' );
		$this->order->save();

		WPCA_ER_Plugin::log( sprintf( 'Thank-you emitted for order %d', $order_id ) );
	}

	/**
	 * Provide the validated order ID to Woo thank-you consumers.
	 *
	 * @param int $order_id Incoming order ID.
	 * @return int
	 */
	public function filter_thankyou_order_id( $order_id ) {
		if ( $this->order instanceof WC_Order ) {
			return $this->order->get_id();
		}

		return $order_id;
	}

	/**
	 * Provide the validated order key to Woo thank-you consumers.
	 *
	 * @param string $order_key Incoming order key.
	 * @return string
	 */
	public function filter_thankyou_order_key( $order_key ) {
		if ( $this->order instanceof WC_Order ) {
			return $this->order->get_order_key();
		}

		return $order_key;
	}

	/**
	 * Resolve and cache the checkout-return order for this request.
	 *
	 * @return WC_Order|null
	 */
	private function get_validated_checkout_order() {
		if ( $this->resolved ) {
			return $this->order;
		}

		$this->resolved = true;
		$this->order    = $this->resolve_checkout_return_order();

		return $this->order;
	}

	/**
	 * Validate flag, order, and key. Fail closed without leaking order existence.
	 *
	 * @return WC_Order|null
	 */
	private function resolve_checkout_return_order() {
		if ( is_admin() || wp_doing_cron() || wp_doing_ajax() ) {
			return null;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- public checkout-return landing.
		if ( ! self::is_checkout_flag_present( $_GET ) ) {
			return null;
		}

		$order_id = isset( $_GET['order'] ) ? absint( wp_unslash( $_GET['order'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$key      = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( $order_id < 1 || '' === $key ) {
			return null;
		}

		$order = wc_get_order( $order_id );
		if ( ! self::order_matches_key( $order, $key ) ) {
			return null;
		}

		return $order;
	}
}
