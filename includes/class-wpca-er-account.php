<?php
/**
 * WooCommerce My Account integration.
 *
 * @package WPCA_Event_Registration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers the My Account endpoint and listing UI.
 */
class WPCA_ER_Account {

	public const ENDPOINT = 'event-registration';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function hooks() {
		add_filter( 'woocommerce_get_query_vars', array( $this, 'register_account_query_var' ) );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( $this, 'render_endpoint' ) );
		add_filter( 'woocommerce_endpoint_' . self::ENDPOINT . '_title', array( $this, 'endpoint_document_title' ), 10, 3 );
	}

	/**
	 * Register the My Account endpoint with WooCommerce so rewrite rules, query vars, and menu URLs stay in sync.
	 *
	 * @param array $vars Endpoint key => rewrite slug (same as core tabs).
	 * @return array
	 */
	public function register_account_query_var( $vars ) {
		$vars[ self::ENDPOINT ] = self::ENDPOINT;

		return $vars;
	}

	/**
	 * Browser / document title for the Event registrations endpoint.
	 *
	 * @param string $title    Default title (empty for unknown endpoints).
	 * @param string $endpoint Endpoint key.
	 * @param string $action   Optional sub-action.
	 * @return string
	 */
	public function endpoint_document_title( $title, $endpoint, $action ) {
		unset( $action );

		if ( self::ENDPOINT !== $endpoint ) {
			return $title;
		}

		if ( '' !== $title ) {
			return $title;
		}

		return __( 'Event registrations', 'wpcodeally-event-registration' );
	}

	/**
	 * Add navigation item.
	 *
	 * @param array $items Menu slug => label.
	 * @return array
	 */
	public function menu_items( $items ) {
		$new = array(
			self::ENDPOINT => __( 'Event registrations', 'wpcodeally-event-registration' ),
		);

		$logout = array();
		if ( isset( $items['customer-logout'] ) ) {
			$logout = array( 'customer-logout' => $items['customer-logout'] );
			unset( $items['customer-logout'] );
		}

		return array_merge( $items, $new, $logout );
	}

	/**
	 * My Account page output.
	 *
	 * @return void
	 */
	public function render_endpoint() {
		if ( isset( $_GET['ono_edit'], $_GET['ono_order'], $_GET['ono_item'], $_GET['_wpnonce'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			$this->maybe_render_edit_form();
			return;
		}

		// Complete-registration deep link (order + key): show the form on this endpoint when no per-product page is set.
		if ( isset( $_GET['order'], $_GET['key'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Gravity Forms markup; notices in helper are escaped.
			echo WPCA_ER_Public_Landing::get_registration_markup_from_request();
			$list_url = wc_get_account_endpoint_url( self::ENDPOINT );
			echo '<p class="wpca-er-account-back" style="margin-top:1.5em;"><a class="button button-secondary" href="' . esc_url( $list_url ) . '">' . esc_html__( 'Back to all registrations', 'wpcodeally-event-registration' ) . '</a></p>';
			return;
		}

		$this->render_registration_list();
	}

	/**
	 * Show Gravity Form in update mode when query args check out.
	 *
	 * @return void
	 */
	private function maybe_render_edit_form() {
		$order_id = isset( $_GET['ono_order'] ) ? absint( wp_unslash( $_GET['ono_order'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$item_id  = isset( $_GET['ono_item'] ) ? absint( wp_unslash( $_GET['ono_item'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$nonce    = isset( $_GET['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! $order_id || ! $item_id || ! wp_verify_nonce( $nonce, 'WPCA_ER_edit_' . $order_id ) ) {
			wc_print_notice( esc_html__( 'Invalid edit link. Please open registrations from your orders page.', 'wpcodeally-event-registration' ), 'error' );
			return;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wc_print_notice( esc_html__( 'Order not found.', 'wpcodeally-event-registration' ), 'error' );
			return;
		}

		if ( (int) $order->get_user_id() !== (int) get_current_user_id() ) {
			wc_print_notice( esc_html__( 'You do not have permission to edit this registration.', 'wpcodeally-event-registration' ), 'error' );
			return;
		}

		$item = $order->get_item( $item_id );
		if ( ! $item instanceof WC_Order_Item_Product ) {
			wc_print_notice( esc_html__( 'Line item not found.', 'wpcodeally-event-registration' ), 'error' );
			return;
		}

		$product = $item->get_product();
		if ( ! $product ) {
			wc_print_notice( esc_html__( 'Product not found.', 'wpcodeally-event-registration' ), 'error' );
			return;
		}

		$form_id = WPCA_ER_Product_Admin::get_registration_form_id_for_order_item( $item );

		if ( ! $form_id ) {
			wc_print_notice( esc_html__( 'No registration form is configured for this product.', 'wpcodeally-event-registration' ), 'error' );
			return;
		}

		$entry_id = absint( $item->get_meta( WPCA_ER_Order_Bridge::ITEM_META_ENTRY_ID, true ) );
		if ( ! $entry_id ) {
			$url = $this->get_registration_start_url( $order, $item_id );
			echo '<p>' . esc_html__( 'Registration has not been submitted yet.', 'wpcodeally-event-registration' ) . '</p>';
			if ( $url ) {
				echo '<p><a class="button" href="' . esc_url( $url ) . '">' . esc_html__( 'Complete registration', 'wpcodeally-event-registration' ) . '</a></p>';
			}
			return;
		}

		WPCA_ER_Order_Bridge::set_edit_context( $order_id, $item_id, $entry_id, $form_id, $order );

		echo '<h3>' . esc_html__( 'Edit registration', 'wpcodeally-event-registration' ) . '</h3>';

		if ( function_exists( 'gravity_form' ) ) {
			gravity_form( $form_id, true, true, false, null, true, 1, true );
		}

		WPCA_ER_Order_Bridge::clear_edit_context();

		$list_url = wc_get_account_endpoint_url( self::ENDPOINT );
		echo '<p style="margin-top:1.5em;"><a class="button button-secondary" href="' . esc_url( $list_url ) . '">' . esc_html__( 'Back to list', 'wpcodeally-event-registration' ) . '</a></p>';
	}

	/**
	 * Public URL to open the registration hub with order context for one line item.
	 *
	 * @param \WC_Order $order   Order.
	 * @param int       $item_id Line item ID.
	 * @return string Empty when the hub page or form ID is not configured.
	 */
	public function get_registration_start_url( $order, $item_id ) {
		$checkout = wpca_er()->checkout_redirect;
		if ( ! $checkout instanceof WPCA_ER_Checkout_Redirect ) {
			return '';
		}

		return $checkout->build_registration_url_for_order_item( $order, $item_id );
	}

	/**
	 * URL to edit an existing registration (My Account endpoint + nonce).
	 *
	 * @param \WC_Order $order   Order.
	 * @param int       $item_id Order item ID.
	 * @return string
	 */
	public function get_edit_registration_url( $order, $item_id ) {
		if ( ! $order instanceof WC_Order ) {
			return '';
		}

		$item_id = absint( $item_id );
		if ( ! $item_id ) {
			return '';
		}

		return wp_nonce_url(
			add_query_arg(
				array(
					'ono_edit'  => '1',
					'ono_order' => $order->get_id(),
					'ono_item'  => $item_id,
				),
				wc_get_account_endpoint_url( self::ENDPOINT )
			),
			'WPCA_ER_edit_' . $order->get_id()
		);
	}

	/**
	 * List purchased event products with registration status.
	 *
	 * @return void
	 */
	private function render_registration_list() {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			echo '<p>' . esc_html__( 'Please sign in to view your registrations.', 'wpcodeally-event-registration' ) . '</p>';
			return;
		}

		$orders = wc_get_orders(
			array(
				'customer' => $user_id,
				'status'   => array( 'processing', 'completed', 'on-hold' ),
				'limit'    => 100,
				'orderby'  => 'date',
				'order'    => 'DESC',
			)
		);

		$rows = array();

		foreach ( $orders as $order ) {
			foreach ( $order->get_items() as $item ) {
				if ( ! $item instanceof WC_Order_Item_Product ) {
					continue;
				}

				$product = $item->get_product();
				if ( ! $product ) {
					continue;
				}

				$form_id = WPCA_ER_Product_Admin::get_registration_form_id_for_order_item( $item );

				if ( ! $form_id ) {
					continue;
				}

				$entry_id = absint( $item->get_meta( WPCA_ER_Order_Bridge::ITEM_META_ENTRY_ID, true ) );
				if ( ! $entry_id ) {
					$entry_id = WPCA_ER_Order_Bridge::maybe_retroactively_link_entry( $item, $form_id );
				}

				$rows[] = array(
					'order'       => $order,
					'item'        => $item,
					'product'     => $product,
					'form_id'     => $form_id,
					'entry_id'    => $entry_id,
					'start_url'   => $entry_id ? '' : $this->get_registration_start_url( $order, $item->get_id() ),
					'edit_url'    => $entry_id ? $this->get_edit_registration_url( $order, $item->get_id() ) : '',
					'order_date'  => $order->get_date_created() ? wc_format_datetime( $order->get_date_created() ) : '',
					'order_total' => $order->get_formatted_order_total(),
				);
			}
		}

		echo '<h3>' . esc_html__( 'Your event registrations', 'wpcodeally-event-registration' ) . '</h3>';

		if ( empty( $rows ) ) {
			echo '<div class="woocommerce-info ono-er-account-empty"><p>' . esc_html__( 'Nothing is listed here yet. This area only shows orders that include a product with a Gravity Forms form ID under Product data → Event registration. A separate registration page is optional: if none is set, links use My Account → Event registrations with your order key.', 'wpcodeally-event-registration' ) . '</p><ul>';
			echo '<li>' . esc_html__( 'Orders must be Processing, Completed, or On hold, and be tied to your account (guest orders only appear after you sign in with the same customer record WooCommerce associates with the purchase).', 'wpcodeally-event-registration' ) . '</li>';
			echo '<li>' . esc_html__( 'If the Event registrations menu link or page does not load, open Settings → Permalinks and click Save once to refresh rewrite rules.', 'wpcodeally-event-registration' ) . '</li>';
			echo '</ul></div>';
			return;
		}

		wc_get_template(
			'myaccount/event-registrations.php',
			array(
				'rows' => $rows,
			),
			'',
			WPCA_ER_PATH . 'templates/'
		);
	}
}
