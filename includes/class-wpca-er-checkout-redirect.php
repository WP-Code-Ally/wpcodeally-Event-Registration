<?php
/**
 * Post-checkout redirect to the per-product registration page (or optional global fallback).
 *
 * @package WPCA_Event_Registration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Replaces the post-checkout URL when line items have a registration landing (page/URL or global fallback with form ID).
 */
class WPCA_ER_Checkout_Redirect {

	/**
	 * After typical thank-you plugins (e.g. priority 10) on the order-received URL.
	 */
	public const ORDER_RECEIVED_PRIORITY = 25;

	/**
	 * Block checkout and many gateways filter `woocommerce_get_return_url` instead of (or after) order-received.
	 */
	public const RETURN_URL_PRIORITY = 100;

	/**
	 * Query flag marking a post-checkout / gateway return (not My Account).
	 */
	public const QUERY_CHECKOUT_FLAG = 'wpca_er_checkout';

	/**
	 * Tracks order IDs already logged this request to prevent duplicate log entries.
	 *
	 * @var int[]
	 */
	private static $logged_orders = array();

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function hooks() {
		add_filter( 'woocommerce_get_checkout_order_received_url', array( $this, 'filter_order_received_url' ), self::ORDER_RECEIVED_PRIORITY, 2 );
		add_filter( 'woocommerce_get_return_url', array( $this, 'filter_return_url' ), self::RETURN_URL_PRIORITY, 2 );
	}

	/**
	 * Redirect successful checkout to the winning line item's registration page.
	 *
	 * @param string   $url   Default order received URL.
	 * @param WC_Order $order Order object.
	 * @return string
	 */
	public function filter_order_received_url( $url, $order ) {
		return $this->maybe_replace_thankyou_url( $url, $order );
	}

	/**
	 * Same redirect logic for payment gateways and Checkout block `redirectUrl`.
	 *
	 * @param string        $url   Default return URL.
	 * @param WC_Order|null $order Order object.
	 * @return string
	 */
	public function filter_return_url( $url, $order ) {
		return $this->maybe_replace_thankyou_url( $url, $order );
	}

	/**
	 * Replace thank-you URL when this order has a registration landing configured.
	 *
	 * @param string        $url   Original URL.
	 * @param WC_Order|null $order Order.
	 * @return string
	 */
	private function maybe_replace_thankyou_url( $url, $order ) {
		if ( ! $order instanceof WC_Order ) {
			return $url;
		}

		$target = $this->get_registration_url_for_order( $order );
		if ( '' === $target ) {
			return $url;
		}

		$order_id = $order->get_id();
		if ( ! in_array( $order_id, self::$logged_orders, true ) ) {
			self::$logged_orders[] = $order_id;
			WPCA_ER_Plugin::log( sprintf( 'Registration thank-you URL for order %d: %s', $order_id, $target ) );
		}

		return $target;
	}

	/**
	 * Full registration URL for the first eligible line item (by priority).
	 *
	 * @param WC_Order $order Order.
	 * @return string
	 */
	public function get_registration_url_for_order( $order ) {
		$candidates = $this->get_redirect_candidates( $order );
		if ( empty( $candidates ) ) {
			return '';
		}

		usort(
			$candidates,
			static function ( $a, $b ) {
				return (int) $a['priority'] - (int) $b['priority'];
			}
		);

		$first = reset( $candidates );

		return esc_url_raw(
			add_query_arg(
				array(
					'order'                                => $order->get_id(),
					'key'                                  => $order->get_order_key(),
					WPCA_ER_Order_Bridge::QUERY_ITEM_PARAM => (int) $first['item_id'],
					self::QUERY_CHECKOUT_FLAG              => '1',
				),
				$first['base_url']
			)
		);
	}

	/**
	 * Line items that have a resolvable registration landing (per-product page/URL, or global default when form ID is set).
	 *
	 * @param WC_Order $order Order.
	 * @return array<int, array{item_id:int, priority:int, base_url:string}>
	 */
	private function get_redirect_candidates( $order ) {
		$out = array();

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}

			$product = $item->get_product();
			if ( ! $product ) {
				continue;
			}

			$base = WPCA_ER_Product_Admin::resolve_registration_landing_base_url( $product );
			if ( '' === $base ) {
				continue;
			}

			$priority = absint( $product->get_meta( WPCA_ER_Product_Admin::META_REDIRECT_PRIORITY, true ) );
			if ( ! $priority && $product->is_type( 'variation' ) ) {
				$parent = wc_get_product( $product->get_parent_id() );
				if ( $parent ) {
					$priority = absint( $parent->get_meta( WPCA_ER_Product_Admin::META_REDIRECT_PRIORITY, true ) );
				}
			}
			if ( ! $priority ) {
				$priority = 10;
			}

			$out[] = array(
				'item_id'  => (int) $item->get_id(),
				'priority' => $priority,
				'base_url' => $base,
			);
		}

		/**
		 * Filter redirect candidates before sorting and picking the first URL.
		 *
		 * @param array[]  $out   Each element: item_id, priority, base_url.
		 * @param WC_Order $order Order.
		 */
		return apply_filters( 'wpca_er_checkout_redirect_candidates', $out, $order );
	}

	/**
	 * Registration URL for one order line (My Account “complete registration”).
	 *
	 * @param WC_Order $order   Order.
	 * @param int      $item_id Order item ID.
	 * @return string Empty when not configured.
	 */
	public function build_registration_url_for_order_item( $order, $item_id ) {
		$item = $order->get_item( $item_id );
		if ( ! $item instanceof WC_Order_Item_Product ) {
			return '';
		}

		$product = $item->get_product();
		if ( ! $product ) {
			return '';
		}

		$base = WPCA_ER_Product_Admin::resolve_registration_landing_base_url( $product );
		if ( '' === $base ) {
			return '';
		}

		return esc_url_raw(
			add_query_arg(
				array(
					'order'                                => $order->get_id(),
					'key'                                  => $order->get_order_key(),
					WPCA_ER_Order_Bridge::QUERY_ITEM_PARAM => (int) $item_id,
				),
				$base
			)
		);
	}
}
