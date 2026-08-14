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
	 * Default number of orders fetched per page on the registrations list.
	 */
	public const DEFAULT_ORDERS_PER_PAGE = 20;

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function hooks() {
		add_filter( 'woocommerce_account_menu_items', array( $this, 'menu_items' ) );
		add_filter( 'woocommerce_get_query_vars', array( $this, 'register_account_query_var' ) );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( $this, 'render_endpoint' ) );
		add_filter( 'woocommerce_endpoint_' . self::ENDPOINT . '_title', array( $this, 'endpoint_document_title' ), 10, 3 );
	}

	/**
	 * Query arg for My Account edit mode.
	 */
	public const QUERY_EDIT = 'wpca_er_edit';

	/**
	 * Query arg for order ID in My Account edit mode.
	 */
	public const QUERY_ORDER = 'wpca_er_order';

	/**
	 * Query arg for line item ID in My Account edit mode.
	 */
	public const QUERY_ITEM = 'wpca_er_item';

	/**
	 * Legacy query args retained for backward compatibility with existing links.
	 */
	private const LEGACY_QUERY_EDIT  = 'ono_edit';
	private const LEGACY_QUERY_ORDER = 'ono_order';
	private const LEGACY_QUERY_ITEM  = 'ono_item';

	/**
	 * Whether the current request is an edit-registration deep link.
	 *
	 * @return bool
	 */
	private function is_edit_request() {
		$has_edit  = isset( $_GET[ self::QUERY_EDIT ] ) || isset( $_GET[ self::LEGACY_QUERY_EDIT ] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$has_order = isset( $_GET[ self::QUERY_ORDER ] ) || isset( $_GET[ self::LEGACY_QUERY_ORDER ] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$has_item  = isset( $_GET[ self::QUERY_ITEM ] ) || isset( $_GET[ self::LEGACY_QUERY_ITEM ] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		return $has_edit && $has_order && $has_item && isset( $_GET['_wpnonce'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
	}

	/**
	 * Read an edit-flow query arg, accepting legacy names.
	 *
	 * @param string $current Current param name.
	 * @param string $legacy  Legacy param name.
	 * @return int
	 */
	private function get_edit_query_int( $current, $legacy ) {
		if ( isset( $_GET[ $current ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return absint( wp_unslash( $_GET[ $current ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		if ( isset( $_GET[ $legacy ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return absint( wp_unslash( $_GET[ $legacy ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		return 0;
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
		if ( $this->is_edit_request() ) {
			$this->maybe_render_edit_form();
			return;
		}

		// Complete-registration deep link (order + key): show the form on this endpoint when no per-product page is set.
		if ( isset( $_GET['order'], $_GET['key'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Gravity Forms markup; notices in helper are escaped.
			echo WPCA_ER_Public_Landing::get_registration_markup_from_request();
			$list_url = wc_get_account_endpoint_url( self::ENDPOINT );
			echo '<p class="wpca-er-account-back"><a class="button button-secondary" href="' . esc_url( $list_url ) . '">' . esc_html__( 'Back to all registrations', 'wpcodeally-event-registration' ) . '</a></p>';
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
		$order_id = $this->get_edit_query_int( self::QUERY_ORDER, self::LEGACY_QUERY_ORDER );
		$item_id  = $this->get_edit_query_int( self::QUERY_ITEM, self::LEGACY_QUERY_ITEM );
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
		echo '<p class="wpca-er-account-actions"><a class="button button-secondary" href="' . esc_url( $list_url ) . '">' . esc_html__( 'Back to list', 'wpcodeally-event-registration' ) . '</a></p>';
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
					self::QUERY_EDIT  => '1',
					self::QUERY_ORDER => $order->get_id(),
					self::QUERY_ITEM  => $item_id,
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

		$current_page = absint( get_query_var( self::ENDPOINT ) );
		if ( $current_page < 1 ) {
			$current_page = 1;
		}

		/**
		 * Filter how many customer orders are loaded per page on My Account → Event registrations.
		 *
		 * @param int $per_page Orders per page.
		 */
		$per_page = max( 1, (int) apply_filters( 'wpca_er_registrations_orders_per_page', self::DEFAULT_ORDERS_PER_PAGE ) );

		$order_query = wc_get_orders(
			array(
				'customer' => $user_id,
				'status'   => WPCA_ER_Plugin::registration_order_statuses(),
				'limit'    => $per_page,
				'page'     => $current_page,
				'paginate' => true,
				'orderby'  => 'date',
				'order'    => 'DESC',
			)
		);

		$orders      = array();
		$total_pages = 1;

		if ( is_object( $order_query ) && isset( $order_query->orders ) ) {
			$orders      = is_array( $order_query->orders ) ? $order_query->orders : array();
			$total_pages = max( 1, (int) $order_query->max_num_pages );
		} elseif ( is_array( $order_query ) ) {
			$orders = $order_query;
		}

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
			if ( $current_page > 1 && $total_pages > 1 ) {
				echo '<div class="woocommerce-info"><p>' . esc_html__( 'No event registrations appear on this page.', 'wpcodeally-event-registration' ) . '</p></div>';
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- markup built with escaping helpers.
				echo wpca_er()->account->get_pagination_markup( $current_page, $total_pages );
				return;
			}

			echo '<div class="woocommerce-info wpca-er-account-empty"><p>' . esc_html__( 'Nothing is listed here yet. This area only shows orders that include a product with a Gravity Forms form ID under Product data → Event registration. A separate registration page is optional: if none is set, links use My Account → Event registrations with your order key.', 'wpcodeally-event-registration' ) . '</p><ul>';
			echo '<li>' . esc_html__( 'Orders must be Processing, Completed, or On hold, and be tied to your account (guest orders only appear after you sign in with the same customer record WooCommerce associates with the purchase).', 'wpcodeally-event-registration' ) . '</li>';
			echo '<li>' . esc_html__( 'If the Event registrations menu link or page does not load, open Settings → Permalinks and click Save once to refresh rewrite rules.', 'wpcodeally-event-registration' ) . '</li>';
			echo '</ul></div>';
			return;
		}

		wc_get_template(
			'myaccount/event-registrations.php',
			array(
				'rows'         => $rows,
				'current_page' => $current_page,
				'total_pages'  => $total_pages,
			),
			'',
			WPCA_ER_PATH . 'templates/'
		);
	}

	/**
	 * Build pagination links for the registrations endpoint.
	 *
	 * @param int $current_page Current page number.
	 * @param int $total_pages  Total number of pages.
	 * @return string
	 */
	public function get_pagination_markup( $current_page, $total_pages ) {
		if ( $total_pages <= 1 || ! function_exists( 'wc_get_endpoint_url' ) ) {
			return '';
		}

		$base = wc_get_endpoint_url( self::ENDPOINT, '%#%', wc_get_page_permalink( 'myaccount' ) );

		$links = paginate_links(
			array(
				'base'      => esc_url_raw( trailingslashit( $base ) ),
				'format'    => '%#%',
				'current'   => $current_page,
				'total'     => $total_pages,
				'type'      => 'list',
				'prev_text' => '&larr;',
				'next_text' => '&rarr;',
			)
		);

		if ( ! $links ) {
			return '';
		}

		return '<nav class="woocommerce-pagination wpca-er-account-pagination" aria-label="' . esc_attr__( 'Event registrations pages', 'wpcodeally-event-registration' ) . '">' . $links . '</nav>';
	}
}
