<?php
/**
 * Gravity Forms ↔ WooCommerce order linkage and validation.
 *
 * @package WPCA_Event_Registration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles GF rendering, validation, submission, and entry updates.
 */
class WPCA_ER_Order_Bridge {

	public const ITEM_META_ENTRY_ID = '_wpca_er_entry_id';
	public const ITEM_META_FORM_ID  = '_wpca_er_form_id';

	public const POST_CONTEXT     = 'WPCA_ER_ctx';
	public const QUERY_ITEM_PARAM = 'wpca_er_item';

	/**
	 * Legacy line-item query arg retained for backward compatibility.
	 */
	public const LEGACY_QUERY_ITEM_PARAM = 'ono_item';

	/**
	 * Edit context for My Account entry update flow (current HTTP request, initial render).
	 *
	 * @var array|null
	 */
	private static $edit_context = null;

	/**
	 * Request-level cache for retroactive entry lookups.
	 *
	 * @var array<string, int>
	 */
	private static $retroactive_link_cache = array();

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function hooks() {
		add_filter( 'gform_form_tag', array( $this, 'append_signed_context_field' ), 10, 2 );
		add_filter( 'gform_pre_render', array( $this, 'maybe_populate_edit_defaults' ), 8 );

		add_filter( 'gform_validation', array( $this, 'validate_wc_context' ) );

		add_filter( 'gform_entry_post_save', array( $this, 'entry_post_save_link_order' ), 10, 2 );

		add_action( 'gform_after_submission', array( $this, 'after_submission' ), 10, 2 );

		add_filter( 'gform_get_form_filter', array( $this, 'maybe_replace_form_with_notice' ), 8, 2 );

		add_filter( 'gform_entry_id_pre_save_lead', array( $this, 'filter_entry_id_for_update' ), 10, 2 );

		add_filter( 'gform_confirmation', array( $this, 'append_order_summary_to_confirmation' ), 15, 4 );
	}

	/**
	 * Set edit context for the current request (My Account edit).
	 *
	 * @param int       $order_id  Order ID.
	 * @param int       $item_id   Order item ID.
	 * @param int       $entry_id  Entry ID.
	 * @param int       $form_id   Form ID.
	 * @param \WC_Order $order     Order object.
	 * @return void
	 */
	public static function set_edit_context( $order_id, $item_id, $entry_id, $form_id, $order ) {
		self::$edit_context = array(
			'order_id'  => (int) $order_id,
			'item_id'   => (int) $item_id,
			'entry_id'  => (int) $entry_id,
			'form_id'   => (int) $form_id,
			'order'     => $order,
			'order_key' => $order->get_order_key(),
		);
	}

	/**
	 * Clear edit context.
	 *
	 * @return void
	 */
	public static function clear_edit_context() {
		self::$edit_context = null;
	}

	/**
	 * Read the line-item query arg from the current request, accepting legacy names.
	 *
	 * @return int
	 */
	public static function get_request_item_id() {
		if ( isset( $_GET[ self::QUERY_ITEM_PARAM ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return absint( wp_unslash( $_GET[ self::QUERY_ITEM_PARAM ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		if ( isset( $_GET[ self::LEGACY_QUERY_ITEM_PARAM ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return absint( wp_unslash( $_GET[ self::LEGACY_QUERY_ITEM_PARAM ] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}

		return 0;
	}

	/**
	 * Whether this form is configured on the product for the given line item.
	 *
	 * @param int                    $form_id Form ID.
	 * @param \WC_Order              $order   Order.
	 * @param \WC_Order_Item_Product $item    Order line item.
	 * @return bool
	 */
	public static function form_matches_product( $form_id, $order, $item ) {
		unset( $order );

		$configured = WPCA_ER_Product_Admin::get_registration_form_id_for_order_item( $item );

		return $configured > 0 && $configured === (int) $form_id;
	}

	/**
	 * Append a signed WC context field to the opening form tag.
	 *
	 * @param string $form_tag Opening <form> tag HTML.
	 * @param array  $form     Form definition.
	 * @return string
	 */
	public function append_signed_context_field( $form_tag, $form ) {
		if ( empty( $form['id'] ) || ( is_admin() && ! wp_doing_ajax() ) ) {
			return $form_tag;
		}

		$form_id = (int) $form['id'];
		$blob    = $this->build_context_blob_for_display( $form_id );

		if ( '' === $blob ) {
			return $form_tag;
		}

		$field = '<input type="hidden" name="' . esc_attr( self::POST_CONTEXT ) . '" value="' . esc_attr( $blob ) . '" />';

		return preg_replace( '/>/', '>' . $field, $form_tag, 1 );
	}

	/**
	 * Populate defaults when editing from My Account.
	 *
	 * @param array $form Form.
	 * @return array
	 */
	public function maybe_populate_edit_defaults( $form ) {
		if ( ! is_array( $form ) || empty( $form['id'] ) ) {
			return $form;
		}

		if ( ! class_exists( 'GFAPI' ) ) {
			return $form;
		}

		$form_id  = (int) $form['id'];
		$entry_id = 0;

		// My Account edit flow (set_edit_context was called).
		if ( self::$edit_context && (int) self::$edit_context['form_id'] === $form_id ) {
			$entry_id = (int) self::$edit_context['entry_id'];
		}

		// Front-end registration page opened via ?order=&key=&ono_item= with an existing entry.
		if ( ! $entry_id ) {
			$resolved = $this->resolve_from_get( $form_id );
			if ( $resolved ) {
				$existing = absint( $resolved['item']->get_meta( self::ITEM_META_ENTRY_ID, true ) );
				if ( ! $existing ) {
					$existing = self::maybe_retroactively_link_entry( $resolved['item'], $form_id );
				}
				if ( $existing ) {
					$entry_id = $existing;
				}
			}
		}

		if ( ! $entry_id ) {
			return $form;
		}

		$entry = GFAPI::get_entry( $entry_id );
		if ( is_wp_error( $entry ) || empty( $entry ) ) {
			return $form;
		}

		WPCA_ER_Plugin::log( sprintf( 'Pre-populating form %d with entry %d for edit.', $form_id, $entry_id ) );

		// Field types that cannot or should not be pre-populated.
		$skip_types = array( 'html', 'section', 'page', 'button', 'captcha', 'fileupload', 'signature' );

		foreach ( $form['fields'] as &$field ) {
			if ( ! is_object( $field ) ) {
				continue;
			}
			if ( in_array( $field->type, $skip_types, true ) ) {
				continue;
			}
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Gravity Forms field API uses camelCase.
			if ( ! empty( $field->displayOnly ) ) {
				continue;
			}

			$field_id = (string) $field->id;

			// Multi-input fields (name, address, date, time, checkbox, etc.) store each
			// sub-value under its own decimal key (e.g. "1.3", "1.6"). Set defaultValue
			// on every input individually so GF renders each sub-field correctly.
			if ( ! empty( $field->inputs ) && is_array( $field->inputs ) ) {
				foreach ( $field->inputs as &$input ) {
					$input_id = (string) $input['id'];
					if ( isset( $entry[ $input_id ] ) && '' !== $entry[ $input_id ] ) {
						$input['defaultValue'] = $entry[ $input_id ]; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Gravity Forms input schema uses camelCase.
					}
				}
				unset( $input );
				continue;
			}

			// Single-value fields: text, textarea, email, phone, number, select, radio, etc.
			if ( isset( $entry[ $field_id ] ) && '' !== $entry[ $field_id ] ) {
				$field->defaultValue = $entry[ $field_id ]; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- Gravity Forms field API uses camelCase.
			}
		}
		unset( $field );

		return $form;
	}

	/**
	 * Prepend an informational notice when the visitor is editing an existing registration.
	 *
	 * The form remains fully editable so the user can update their entry in place.
	 *
	 * @param string $markup Markup.
	 * @param array  $form   Form.
	 * @return string
	 */
	public function maybe_replace_form_with_notice( $markup, $form ) {
		if ( empty( $form['id'] ) || is_admin() ) {
			return $markup;
		}

		if ( self::$edit_context && (int) self::$edit_context['form_id'] === (int) $form['id'] ) {
			$notice = '<div class="wpca-er-registration-notice woocommerce-info"><p>' .
				esc_html__( 'You are editing your existing registration. Update the fields below and submit to save changes.', 'wpcodeally-event-registration' ) .
				'</p></div>';
			return $notice . $markup;
		}

		$resolved = $this->resolve_from_get( (int) $form['id'] );
		if ( ! $resolved ) {
			return $markup;
		}

		$existing = absint( $resolved['item']->get_meta( self::ITEM_META_ENTRY_ID, true ) );
		if ( ! $existing ) {
			$existing = self::maybe_retroactively_link_entry( $resolved['item'], (int) $form['id'] );
		}
		if ( ! $existing ) {
			return $markup;
		}

		$notice = '<div class="wpca-er-registration-notice woocommerce-info"><p>' .
			esc_html__( 'You are editing your existing registration. Update the fields below and submit to save changes.', 'wpcodeally-event-registration' ) .
			'</p></div>';
		return $notice . $markup;
	}

	/**
	 * Validate WC context before GF saves the entry.
	 *
	 * @param array $validation_result GF validation result.
	 * @return array
	 */
	public function validate_wc_context( $validation_result ) {
		$form = $validation_result['form'];
		if ( empty( $form['id'] ) ) {
			return $validation_result;
		}

		$resolved = $this->resolve_order_context( (int) $form['id'], $form );
		if ( ! $resolved ) {
			return $validation_result;
		}

		$order = $resolved['order'];
		$item  = $resolved['item'];

		$existing_entry = absint( $item->get_meta( self::ITEM_META_ENTRY_ID, true ) );
		$posted_entry   = (int) $resolved['entry_id'];
		$is_update      = $existing_entry > 0 && $posted_entry > 0 && $existing_entry === $posted_entry;

		if ( $existing_entry && ! $is_update ) {
			$validation_result['is_valid'] = false;
			$validation_result['message']  = __( 'This order already has a registration on file. Please use My Account to edit your registration.', 'wpcodeally-event-registration' );
			return $validation_result;
		}

		$status = $order->get_status();
		if ( ! WPCA_ER_Plugin::order_allows_registration( $order ) ) {
			$validation_result['is_valid'] = false;
			$validation_result['message']  = __( 'Registration is only available for paid or processing orders.', 'wpcodeally-event-registration' );
			return $validation_result;
		}

		return $validation_result;
	}

	/**
	 * Persist WC order linkage as soon as the entry exists (before confirmation is built).
	 *
	 * @param array $entry Entry.
	 * @param array $form  Form.
	 * @return array
	 */
	public function entry_post_save_link_order( $entry, $form ) {
		$this->persist_registration_linkage( $entry, $form );

		return $entry;
	}

	/**
	 * After GF submission: same linkage (idempotent); keeps compatibility with add-ons expecting this hook.
	 *
	 * @param array $entry Entry.
	 * @param array $form  Form.
	 * @return void
	 */
	public function after_submission( $entry, $form ) {
		$this->persist_registration_linkage( $entry, $form, false );
	}

	/**
	 * Write order line item meta and GF entry meta when this submission carries a verified WC context.
	 *
	 * @param array $entry Entry.
	 * @param array $form  Form.
	 * @param bool  $log   Whether to write a debug log line when linkage succeeds.
	 * @return void
	 */
	private function persist_registration_linkage( $entry, $form, $log = true ) {
		if ( empty( $form['id'] ) || empty( $entry['id'] ) ) {
			return;
		}

		if ( ! empty( $entry['status'] ) && 'spam' === $entry['status'] ) {
			return;
		}

		$resolved = $this->resolve_order_context( (int) $form['id'], $form );
		if ( ! $resolved ) {
			return;
		}

		$item = $resolved['item'];
		$item->update_meta_data( self::ITEM_META_ENTRY_ID, absint( $entry['id'] ) );
		$item->update_meta_data( self::ITEM_META_FORM_ID, absint( $form['id'] ) );
		$item->save();

		if ( function_exists( 'gform_update_meta' ) ) {
			gform_update_meta( absint( $entry['id'] ), 'wpca_er_wc_order_id', $resolved['order']->get_id(), absint( $form['id'] ) );
			gform_update_meta( absint( $entry['id'] ), 'wpca_er_wc_order_item_id', (int) $item->get_id(), absint( $form['id'] ) );
		}

		if ( $log ) {
			WPCA_ER_Plugin::log( sprintf( 'Linked entry %d to order %d item %d.', (int) $entry['id'], $resolved['order']->get_id(), (int) $item->get_id() ) );
		}
	}

	/**
	 * Append a compact WooCommerce order summary after the confirmation message (text confirmations only).
	 *
	 * @param string|array $confirmation Message HTML or redirect array.
	 * @param array        $form         Form.
	 * @param array        $entry        Entry.
	 * @param bool         $ajax         Whether submission was AJAX.
	 * @return string|array
	 */
	public function append_order_summary_to_confirmation( $confirmation, $form, $entry, $ajax ) {
		unset( $ajax );

		if ( is_array( $confirmation ) ) {
			return $confirmation;
		}

		if ( ! is_string( $confirmation ) ) {
			return $confirmation;
		}

		if ( false !== strpos( $confirmation, 'wpca-er-order-summary' ) ) {
			return $confirmation;
		}

		if ( empty( $form['id'] ) || ! function_exists( 'wc_get_order' ) ) {
			return $confirmation;
		}

		$form_id = (int) $form['id'];

		$resolved = null;

		// Prefer entry meta (written in gform_entry_post_save before confirmation).
		if ( ! empty( $entry['id'] ) && function_exists( 'gform_get_meta' ) ) {
			$order_id = (int) gform_get_meta( (int) $entry['id'], 'wpca_er_wc_order_id' );
			if ( $order_id ) {
				$order   = wc_get_order( $order_id );
				$item_id = (int) gform_get_meta( (int) $entry['id'], 'wpca_er_wc_order_item_id' );
				if ( $order ) {
					$resolved = array(
						'order'   => $order,
						'item'    => null,
						'item_id' => $item_id,
					);
				}
			}
		}

		if ( ! $resolved ) {
			$resolved = $this->resolve_order_context( $form_id, $form );
		}

		if ( ! $resolved ) {
			$from_get = $this->resolve_from_get( $form_id );
			if ( $from_get ) {
				$resolved = array(
					'order'   => $from_get['order'],
					'item'    => $from_get['item'],
					'item_id' => (int) $from_get['item_id'],
				);
			}
		}

		if ( ! $resolved || empty( $resolved['order'] ) ) {
			return $confirmation;
		}

		$order   = $resolved['order'];
		$item_id = isset( $resolved['item'] ) && $resolved['item'] instanceof WC_Order_Item_Product
			? (int) $resolved['item']->get_id()
			: (int) ( $resolved['item_id'] ?? 0 );

		/**
		 * Whether to append the order summary block to the Gravity Forms confirmation.
		 *
		 * @param bool     $show  Default true for message confirmations.
		 * @param array    $form  Form.
		 * @param array    $entry Entry.
		 * @param WC_Order $order Order.
		 */
		if ( ! apply_filters( 'wpca_er_show_order_summary_on_confirmation', true, $form, $entry, $order ) ) {
			return $confirmation;
		}

		$html = self::build_order_summary_markup( $order, $item_id );
		if ( '' === $html ) {
			return $confirmation;
		}

		return $confirmation . $html;
	}

	/**
	 * Build escaped HTML for order summary (registration context).
	 *
	 * @param WC_Order $order   Order.
	 * @param int      $item_id Linked order item ID (0 = omit highlight row).
	 * @return string
	 */
	public static function build_order_summary_markup( $order, $item_id = 0 ) {
		if ( ! $order instanceof WC_Order ) {
			return '';
		}

		$rows = '';
		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
			$name      = $item->get_name();
			$qty       = $item->get_quantity();
			$total     = $order->get_formatted_line_subtotal( $item );
			$highlight = $item_id && (int) $item->get_id() === (int) $item_id;
			$row_class = $highlight ? 'wpca-er-order-summary__row wpca-er-order-summary__row--highlight' : 'wpca-er-order-summary__row';

			$rows .= '<tr class="' . esc_attr( $row_class ) . '">';
			$rows .= '<td>' . esc_html( wp_strip_all_tags( $name ) ) . ' × ' . esc_html( (string) $qty ) . '</td>';
			$rows .= '<td class="wpca-er-order-summary__amount">' . wp_kses_post( $total ) . '</td>';
			$rows .= '</tr>';
		}

		if ( '' === $rows ) {
			return '';
		}

		$order_number = $order->get_order_number();
		$date         = wc_format_datetime( $order->get_date_created() );
		$total        = $order->get_formatted_order_total();
		$payment      = $order->get_payment_method_title();
		$billing_name = trim( $order->get_formatted_billing_full_name() );

		ob_start();
		?>
		<div class="wpca-er-order-summary woocommerce">
			<h3 class="wpca-er-order-summary__title"><?php esc_html_e( 'Your order', 'wpcodeally-event-registration' ); ?></h3>
			<p class="wpca-er-order-summary__meta">
				<strong><?php esc_html_e( 'Order', 'wpcodeally-event-registration' ); ?>:</strong>
				#<?php echo esc_html( (string) $order_number ); ?>
				<span class="wpca-er-order-summary__sep"> · </span>
				<strong><?php esc_html_e( 'Date', 'wpcodeally-event-registration' ); ?>:</strong>
				<?php echo esc_html( $date ); ?>
			</p>
			<?php if ( $billing_name ) : ?>
				<p class="wpca-er-order-summary__billing">
					<strong><?php esc_html_e( 'Billing', 'wpcodeally-event-registration' ); ?>:</strong>
					<?php echo esc_html( $billing_name ); ?>
				</p>
			<?php endif; ?>
			<table class="shop_table wpca-er-order-summary__table">
				<tbody>
					<?php echo $rows; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- cells built with escaping above. ?>
				</tbody>
				<tfoot>
					<tr>
						<th scope="row"><?php esc_html_e( 'Total', 'wpcodeally-event-registration' ); ?></th>
						<td class="wpca-er-order-summary__amount"><?php echo wp_kses_post( $total ); ?></td>
					</tr>
					<?php if ( $payment ) : ?>
						<tr>
							<th scope="row"><?php esc_html_e( 'Payment', 'wpcodeally-event-registration' ); ?></th>
							<td class="wpca-er-order-summary__amount"><?php echo esc_html( $payment ); ?></td>
						</tr>
					<?php endif; ?>
				</tfoot>
			</table>
		</div>
		<?php

		return (string) ob_get_clean();
	}

	/**
	 * Force GF to update an existing entry when the signed context includes an entry ID.
	 *
	 * @param int|null $entry_id Entry ID.
	 * @param array    $form     Form.
	 * @return int|null
	 */
	public function filter_entry_id_for_update( $entry_id, $form ) {
		if ( empty( $form['id'] ) ) {
			return $entry_id;
		}

		$resolved = $this->resolve_order_context( (int) $form['id'], $form );
		if ( ! $resolved || empty( $resolved['entry_id'] ) ) {
			return $entry_id;
		}

		return (int) $resolved['entry_id'];
	}

	/**
	 * Resolve order + line item from GET (thank-you / direct link).
	 *
	 * @param int $form_id Form ID.
	 * @return array{order:\WC_Order,item:\WC_Order_Item_Product,item_id:int}|null
	 */
	public function resolve_from_get( $form_id ) {
		$order_id  = isset( $_GET['order'] ) ? absint( wp_unslash( $_GET['order'] ) ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$order_key = isset( $_GET['key'] ) ? sanitize_text_field( wp_unslash( $_GET['key'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended

		if ( ! $order_id || ! $order_key ) {
			return null;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order || ! hash_equals( (string) $order->get_order_key(), (string) $order_key ) ) {
			return null;
		}

		$item_id = self::get_request_item_id();
		$item    = null;

		if ( $item_id ) {
			$candidate = $order->get_item( $item_id );
			if ( $candidate instanceof WC_Order_Item_Product && self::form_matches_product( $form_id, $order, $candidate ) ) {
				$item = $candidate;
			}
		} else {
			$item = $this->find_matching_line_item( $order, $form_id );
		}

		if ( ! $item instanceof WC_Order_Item_Product ) {
			return null;
		}

		return array(
			'order'   => $order,
			'item'    => $item,
			'item_id' => (int) $item->get_id(),
		);
	}

	/**
	 * Resolve order context from POSTed signed blob (AJAX-safe).
	 *
	 * @param int   $form_id Form ID.
	 * @param array $form    Unused; GF passes it for symmetry with other hooks.
	 * @return array{order:\WC_Order,item:\WC_Order_Item_Product,item_id:int,entry_id:int}|null
	 */
	public function resolve_order_context( $form_id, $form = null ) {
		unset( $form );

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- GF handles submission nonce.
		$blob = isset( $_POST[ self::POST_CONTEXT ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::POST_CONTEXT ] ) ) : '';
		if ( '' === $blob ) {
			return null;
		}

		$data = $this->parse_context_blob( $blob );
		if ( ! is_array( $data ) ) {
			return null;
		}

		if ( (int) $data['fid'] !== (int) $form_id ) {
			return null;
		}

		if ( time() > (int) $data['exp'] ) {
			return null;
		}

		$order = wc_get_order( (int) $data['oid'] );
		if ( ! $order || ! hash_equals( (string) $order->get_order_key(), (string) $data['okey'] ) ) {
			return null;
		}

		$item = $order->get_item( (int) $data['iid'] );
		if ( ! $item instanceof WC_Order_Item_Product || ! self::form_matches_product( $form_id, $order, $item ) ) {
			return null;
		}

		if ( ! empty( $data['uid'] ) ) {
			$uid = (int) $data['uid'];
			if ( ! is_user_logged_in() || (int) get_current_user_id() !== $uid ) {
				return null;
			}
			if ( (int) $order->get_user_id() && (int) $order->get_user_id() !== $uid ) {
				return null;
			}
		}

		return array(
			'order'    => $order,
			'item'     => $item,
			'item_id'  => (int) $item->get_id(),
			'entry_id' => isset( $data['eid'] ) ? (int) $data['eid'] : 0,
		);
	}

	/**
	 * Build a context blob for the opening form tag.
	 *
	 * @param int $form_id Form ID.
	 * @return string Empty string when this form should not be linked.
	 */
	private function build_context_blob_for_display( $form_id ) {
		if ( self::$edit_context && (int) self::$edit_context['form_id'] === (int) $form_id ) {
			$order = self::$edit_context['order'];
			return $this->sign_context(
				array(
					'fid'  => (int) $form_id,
					'oid'  => (int) self::$edit_context['order_id'],
					'okey' => (string) self::$edit_context['order_key'],
					'iid'  => (int) self::$edit_context['item_id'],
					'eid'  => (int) self::$edit_context['entry_id'],
					'uid'  => (int) get_current_user_id(),
					'exp'  => time() + DAY_IN_SECONDS,
				)
			);
		}

		$resolved = $this->resolve_from_get( $form_id );
		if ( ! $resolved ) {
			return '';
		}

		$existing = absint( $resolved['item']->get_meta( self::ITEM_META_ENTRY_ID, true ) );
		if ( ! $existing ) {
			$existing = self::maybe_retroactively_link_entry( $resolved['item'], $form_id );
		}

		return $this->sign_context(
			array(
				'fid'  => (int) $form_id,
				'oid'  => (int) $resolved['order']->get_id(),
				'okey' => (string) $resolved['order']->get_order_key(),
				'iid'  => (int) $resolved['item_id'],
				'eid'  => $existing ? (int) $existing : 0,
				'uid'  => 0,
				'exp'  => time() + DAY_IN_SECONDS,
			)
		);
	}

	/**
	 * Sign and encode a context payload.
	 *
	 * @param array $payload Payload.
	 * @return string
	 */
	private function sign_context( array $payload ) {
		$json = wp_json_encode( $payload );
		$sig  = hash_hmac( 'sha256', $json, $this->get_hmac_key() );

		return $this->base64url_encode( $json . '|' . $sig );
	}

	/**
	 * Parse and verify a context blob from the client.
	 *
	 * @param string $blob Encoded blob.
	 * @return array|null
	 */
	private function parse_context_blob( $blob ) {
		$raw = $this->base64url_decode( $blob );
		if ( '' === $raw || strpos( $raw, '|' ) === false ) {
			return null;
		}

		list( $json, $sig ) = explode( '|', $raw, 2 );
		$expected           = hash_hmac( 'sha256', $json, $this->get_hmac_key() );
		if ( ! hash_equals( $expected, $sig ) ) {
			return null;
		}

		$data = json_decode( $json, true );
		if ( ! is_array( $data ) ) {
			return null;
		}

		$required = array( 'fid', 'oid', 'okey', 'iid', 'exp' );
		foreach ( $required as $key ) {
			if ( ! array_key_exists( $key, $data ) ) {
				return null;
			}
		}

		return $data;
	}

	/**
	 * HMAC key for context tokens.
	 *
	 * @return string
	 */
	private function get_hmac_key() {
		if ( function_exists( 'wp_salt' ) ) {
			return wp_salt( 'WPCA_ER_registration' );
		}

		return NONCE_KEY;
	}

	/**
	 * URL-safe base64 encode.
	 *
	 * @param string $data Raw string.
	 * @return string
	 */
	private function base64url_encode( $data ) {
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- URL-safe transport encoding for signed context payloads.
		return rtrim( strtr( base64_encode( $data ), '+/', '-_' ), '=' );
	}

	/**
	 * URL-safe base64 decode.
	 *
	 * @param string $data Encoded string.
	 * @return string
	 */
	private function base64url_decode( $data ) {
		$padding = strlen( $data ) % 4;
		if ( $padding ) {
			$data .= str_repeat( '=', 4 - $padding );
		}

		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- URL-safe transport decoding for signed context payloads.
		$decoded = base64_decode( strtr( $data, '-_', '+/' ), true );

		return is_string( $decoded ) ? $decoded : '';
	}

	/**
	 * Find a line item whose product maps to the given GF form ID.
	 *
	 * @param \WC_Order $order   Order.
	 * @param int       $form_id Form ID.
	 * @return \WC_Order_Item_Product|null
	 */
	private function find_matching_line_item( $order, $form_id ) {
		$matches = array();

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof WC_Order_Item_Product ) {
				continue;
			}
			if ( self::form_matches_product( $form_id, $order, $item ) ) {
				$matches[] = $item;
			}
		}

		if ( count( $matches ) === 1 ) {
			return $matches[0];
		}

		if ( count( $matches ) > 1 ) {
			/**
			 * Resolve which order line item should bind when multiple products share the same form ID.
			 *
			 * @param WC_Order_Item_Product|null $chosen  Default null (ambiguous).
			 * @param WC_Order_Item_Product[]    $matches Matching items.
			 * @param \WC_Order                  $order   Order.
			 * @param int                        $form_id Form ID.
			 */
			$chosen = apply_filters( 'wpca_er_resolve_duplicate_form_line_item', null, $matches, $order, $form_id );
			if ( $chosen instanceof WC_Order_Item_Product ) {
				return $chosen;
			}
		}

		return null;
	}

	/**
	 * Try to find and link a GF entry that was submitted for a specific order item
	 * but never got its `_wpca_er_entry_id` written back (happens when `form_id` was unresolved at submission time).
	 *
	 * Searches GF entry meta for `wpca_er_wc_order_item_id` matching the item.
	 * When found, writes the linkage meta on the order item and returns the entry ID.
	 *
	 * @param WC_Order_Item_Product $item    Order line item.
	 * @param int                   $form_id GF form ID configured for this product.
	 * @return int Entry ID if found and linked, 0 otherwise.
	 */
	public static function maybe_retroactively_link_entry( $item, $form_id ) {
		$item_id  = (int) $item->get_id();
		$form_id  = (int) $form_id;
		$cache_key = $item_id . ':' . $form_id;

		if ( isset( self::$retroactive_link_cache[ $cache_key ] ) ) {
			return self::$retroactive_link_cache[ $cache_key ];
		}

		$existing = absint( $item->get_meta( self::ITEM_META_ENTRY_ID, true ) );

		if ( $existing ) {
			self::$retroactive_link_cache[ $cache_key ] = $existing;
			return $existing;
		}

		if ( ! $form_id || ! class_exists( 'GFAPI' ) ) {
			self::$retroactive_link_cache[ $cache_key ] = 0;
			return 0;
		}

		$entry_id = 0;
		$entries  = GFAPI::get_entries(
			$form_id,
			array(
				'status'        => 'active',
				'field_filters' => array(
					array(
						'key'   => 'wpca_er_wc_order_item_id',
						'value' => (string) $item_id,
					),
				),
			),
			array(
				'key'       => 'id',
				'direction' => 'DESC',
			),
			array(
				'offset'    => 0,
				'page_size' => 1,
			)
		);

		if ( ! is_wp_error( $entries ) && ! empty( $entries ) ) {
			$first    = reset( $entries );
			$entry_id = isset( $first['id'] ) ? absint( $first['id'] ) : 0;
		}

		if ( ! $entry_id ) {
			self::$retroactive_link_cache[ $cache_key ] = 0;
			return 0;
		}

		$item->update_meta_data( self::ITEM_META_ENTRY_ID, $entry_id );
		$item->update_meta_data( self::ITEM_META_FORM_ID, $form_id );
		$item->save();

		WPCA_ER_Plugin::log(
			sprintf(
				'Retroactively linked entry %d to order item %d (form %d).',
				$entry_id,
				$item_id,
				$form_id
			)
		);

		self::$retroactive_link_cache[ $cache_key ] = $entry_id;

		return $entry_id;
	}
}
