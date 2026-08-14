<?php
/**
 * My Account → Orders table: registration links next to core actions.
 *
 * @package WPCA_Event_Registration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Adds “Event registration” / “Edit registration” buttons to the orders list.
 */
class WPCA_ER_My_Orders_Registration {

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function hooks() {
		add_filter( 'woocommerce_my_account_my_orders_actions', array( $this, 'add_registration_actions' ), 15, 2 );
		add_action( 'wp', array( $this, 'maybe_append_orders_column_registration_links' ), 20 );
	}

	/**
	 * When the orders table uses a custom `order-actions` column callback, WooCommerce may never call
	 * `wc_get_account_orders_actions()`, so filters on `woocommerce_my_account_my_orders_actions` never run.
	 * Append the same registration buttons from that column hook when needed.
	 *
	 * @return void
	 */
	public function maybe_append_orders_column_registration_links() {
		if ( ! function_exists( 'is_account_page' ) || ! is_account_page() ) {
			return;
		}

		if ( ! function_exists( 'is_wc_endpoint_url' ) || ! is_wc_endpoint_url( 'orders' ) ) {
			return;
		}

		if ( false === has_action( 'woocommerce_my_account_my_orders_column_order-actions' ) ) {
			return;
		}

		// Default false: many themes that hook this column still call `wc_get_account_orders_actions()`, so our
		// filter already runs; enabling this would duplicate buttons. Set true only when links are missing entirely.
		if ( ! apply_filters( 'wpca_er_append_registration_links_orders_actions_column', false ) ) {
			return;
		}

		add_action( 'woocommerce_my_account_my_orders_column_order-actions', array( $this, 'echo_column_registration_links' ), 100, 1 );
	}

	/**
	 * Echo registration buttons (used when the actions column is fully overridden by a theme).
	 *
	 * @param WC_Order $order Order.
	 * @return void
	 */
	public function echo_column_registration_links( $order ) {
		if ( ! $order instanceof WC_Order ) {
			return;
		}

		$actions = $this->build_registration_actions( $order );
		if ( empty( $actions ) ) {
			return;
		}

		$wp_button_class = function_exists( 'wc_wp_theme_get_element_class_name' ) ? ' ' . wc_wp_theme_get_element_class_name( 'button' ) : '';

		foreach ( $actions as $key => $action ) {
			if ( empty( $action['url'] ) || empty( $action['name'] ) ) {
				continue;
			}
			$aria = ! empty( $action['aria-label'] ) ? $action['aria-label'] : $action['name'];
			echo '<a href="' . esc_url( $action['url'] ) . '" class="woocommerce-button' . esc_attr( $wp_button_class ) . ' button wpca-er-orders-reg-link ' . esc_attr( sanitize_html_class( $key ) ) . '" aria-label="' . esc_attr( $aria ) . '">' . esc_html( $action['name'] ) . '</a> ';
		}
	}

	/**
	 * Append registration actions for each event line on this order.
	 *
	 * @param array    $actions Keyed action definitions for WooCommerce.
	 * @param WC_Order $order   Order.
	 * @return array
	 */
	public function add_registration_actions( $actions, $order ) {
		if ( ! is_array( $actions ) || ! $order instanceof WC_Order ) {
			return $actions;
		}

		$extra = $this->build_registration_actions( $order );

		if ( empty( $extra ) ) {
			return $actions;
		}

		/**
		 * Filter registration actions appended to the My Account orders table.
		 *
		 * @param array    $actions Incoming actions (core + plugin).
		 * @param WC_Order $order   Order.
		 */
		return apply_filters( 'wpca_er_my_account_order_registration_actions', array_merge( $actions, $extra ), $order );
	}

	/**
	 * Build registration action rows for one order.
	 *
	 * @param WC_Order $order Order.
	 * @return array<string, array{url:string,name:string,aria-label?:string}>
	 */
	private function build_registration_actions( $order ) {
		$actions = array();

		if ( ! $this->order_allows_registration( $order ) ) {
			return $actions;
		}

		$account  = wpca_er()->account;
		$checkout = wpca_er()->checkout_redirect;
		if ( ! $account instanceof WPCA_ER_Account || ! $checkout instanceof WPCA_ER_Checkout_Redirect ) {
			return $actions;
		}

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}

			$form_id = WPCA_ER_Product_Admin::get_registration_form_id_for_order_item( $item );

			if ( ! $form_id ) {
				continue;
			}

			$item_id  = (int) $item->get_id();
			$entry_id = absint( $item->get_meta( WPCA_ER_Order_Bridge::ITEM_META_ENTRY_ID, true ) );
			if ( ! $entry_id ) {
				$entry_id = WPCA_ER_Order_Bridge::maybe_retroactively_link_entry( $item, $form_id );
			}
			$product   = $item->get_product();
			$prod_name = $product ? wp_strip_all_tags( $product->get_name() ) : '';

			if ( $entry_id ) {
				$url = $account->get_edit_registration_url( $order, $item_id );
				if ( '' === $url ) {
					continue;
				}
				$key             = 'WPCA_ER_edit_' . $item_id;
				$actions[ $key ] = array(
					'url'        => $url,
					'name'       => __( 'Edit registration', 'wpcodeally-event-registration' ),
					'aria-label' => $prod_name
						/* translators: %1$s: product name, %2$s: order number. */
						? sprintf( __( 'Edit registration for %1$s, order number %2$s', 'wpcodeally-event-registration' ), $prod_name, $order->get_order_number() )
						/* translators: %s: order number. */
						: sprintf( __( 'Edit registration, order number %s', 'wpcodeally-event-registration' ), $order->get_order_number() ),
				);
			} else {
				$url = $checkout->build_registration_url_for_order_item( $order, $item_id );

				if ( '' === $url ) {
					WPCA_ER_Plugin::log( sprintf( 'Order %d, item %d (%s): form_id=%d but no registration URL resolved.', $order->get_id(), $item_id, $item->get_name(), $form_id ) );
					continue;
				}
				$key             = 'WPCA_ER_reg_' . $item_id;
				$actions[ $key ] = array(
					'url'        => $url,
					'name'       => __( 'Event registration', 'wpcodeally-event-registration' ),
					'aria-label' => $prod_name
						/* translators: %1$s: product name, %2$s: order number. */
						? sprintf( __( 'Complete event registration for %1$s, order number %2$s', 'wpcodeally-event-registration' ), $prod_name, $order->get_order_number() )
						/* translators: %s: order number. */
						: sprintf( __( 'Complete event registration, order number %s', 'wpcodeally-event-registration' ), $order->get_order_number() ),
				);
			}
		}

		return $actions;
	}

	/**
	 * Whether registration links should be offered for this order status.
	 *
	 * @param WC_Order $order Order.
	 * @return bool
	 */
	private function order_allows_registration( $order ) {
		return WPCA_ER_Plugin::order_allows_registration( $order );
	}
}
