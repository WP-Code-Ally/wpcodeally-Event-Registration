<?php
/**
 * Product editor: event registration (page, Gravity Form ID, priority).
 *
 * @package WPCA_Event_Registration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers WooCommerce product fields.
 */
class WPCA_ER_Product_Admin {

	public const META_FORM_ID = '_wpca_er_gf_form_id';

	/**
	 * Page ID (int as string) or full URL — same pattern as Custom Thank You Pages.
	 */
	public const META_REGISTRATION_PAGE = '_wpca_er_registration_page';

	/**
	 * When multiple event lines redirect after checkout, lower numbers go first.
	 */
	public const META_REDIRECT_PRIORITY = '_wpca_er_redirect_priority';

	/**
	 * Resolve the URL customers should open for registration (product-specific, then optional global fallback).
	 *
	 * @param WC_Product $product Product or variation.
	 * @return string Empty when nothing is configured.
	 */
	public static function resolve_registration_landing_base_url( $product ) {
		if ( ! $product instanceof WC_Product ) {
			return '';
		}

		$raw = $product->get_meta( self::META_REGISTRATION_PAGE, true );

		if ( ( '' === $raw || null === $raw ) && $product->is_type( 'variation' ) ) {
			$parent = wc_get_product( $product->get_parent_id() );
			if ( $parent ) {
				$raw = $parent->get_meta( self::META_REGISTRATION_PAGE, true );
			}
		}

		$form_id = self::get_registration_form_id( $product );

		if ( '' !== $raw && null !== $raw ) {
			if ( is_numeric( $raw ) ) {
				$page_id = absint( $raw );
				if ( $page_id > 0 ) {
					$url = get_permalink( $page_id );
					return $url ? esc_url_raw( $url ) : '';
				}
			}

			$url = esc_url_raw( (string) $raw );
			if ( $url ) {
				return $url;
			}
		}

		// No per-product page: optional global fallback when this product still has a form ID.
		if ( $form_id > 0 ) {
			$hub_id = absint( get_option( WPCA_ER_Plugin::OPTION_HUB_PAGE_ID, 0 ) );
			if ( $hub_id ) {
				$url = get_permalink( $hub_id );
				if ( $url ) {
					return esc_url_raw( $url );
				}
			}

			// Last resort: My Account → Event registrations (same endpoint renders the form when ?order=&key= are present).
			if ( function_exists( 'wc_get_account_endpoint_url' ) ) {
				$account_url = wc_get_account_endpoint_url( 'event-registration' );
				if ( $account_url ) {
					return esc_url_raw( $account_url );
				}
			}
		}

		return '';
	}

	/**
	 * Gravity Forms form ID for an order line item’s product (variation reads parent when empty).
	 *
	 * @param \WC_Order_Item_Product $item Order line item.
	 * @return int Form ID or 0.
	 */
	public static function get_registration_form_id_for_order_item( $item ) {
		if ( ! $item instanceof WC_Order_Item_Product ) {
			return 0;
		}

		$product = $item->get_product();
		if ( ! $product ) {
			return 0;
		}

		return self::get_registration_form_id( $product );
	}

	/**
	 * Gravity Forms form ID for a product.
	 *
	 * Resolution: explicit meta → parent meta (variations) → auto-detect from the registration page content.
	 *
	 * @param \WC_Product $product Product or variation.
	 * @return int Form ID or 0.
	 */
	public static function get_registration_form_id( $product ) {
		if ( ! $product instanceof WC_Product ) {
			return 0;
		}

		$form_id = absint( $product->get_meta( self::META_FORM_ID, true ) );
		if ( $form_id ) {
			return $form_id;
		}

		if ( $product->is_type( 'variation' ) ) {
			$parent = wc_get_product( $product->get_parent_id() );
			if ( $parent ) {
				$form_id = absint( $parent->get_meta( self::META_FORM_ID, true ) );
				if ( $form_id ) {
					return $form_id;
				}
			}
		}

		return self::detect_form_id_from_registration_page( $product );
	}

	/**
	 * Scan the registration page chosen for this product and return the first GF form ID found.
	 *
	 * @param \WC_Product $product Product.
	 * @return int Form ID or 0.
	 */
	private static function detect_form_id_from_registration_page( $product ) {
		$raw = $product->get_meta( self::META_REGISTRATION_PAGE, true );

		if ( ( '' === $raw || null === $raw ) && $product->is_type( 'variation' ) ) {
			$parent = wc_get_product( $product->get_parent_id() );
			if ( $parent ) {
				$raw = $parent->get_meta( self::META_REGISTRATION_PAGE, true );
			}
		}

		if ( '' === $raw || null === $raw ) {
			return 0;
		}

		$page_id = 0;
		if ( is_numeric( $raw ) ) {
			$page_id = absint( $raw );
		} else {
			$page_id = function_exists( 'url_to_postid' ) ? url_to_postid( (string) $raw ) : 0;
		}

		if ( ! $page_id ) {
			return 0;
		}

		$post = get_post( $page_id );
		if ( ! $post || empty( $post->post_content ) ) {
			return 0;
		}

		return self::extract_gf_form_id_from_content( $post->post_content );
	}

	/**
	 * Extract the first Gravity Forms form ID from post content (shortcode or block markup).
	 *
	 * @param string $content Post content.
	 * @return int Form ID or 0.
	 */
	public static function extract_gf_form_id_from_content( $content ) {
		if ( '' === $content ) {
			return 0;
		}

		// Match Gravity Forms shortcode IDs from gravityform/gravityforms shortcodes.
		if ( preg_match( '/\[gravityforms?\s[^\]]*id\s*=\s*["\']?(\d+)/i', $content, $m ) ) {
			return absint( $m[1] );
		}

		// Match Gutenberg gravityforms/form block markup.
		if ( preg_match( '/wp:gravityforms\/form\s*\{[^}]*"formId"\s*:\s*"?(\d+)/i', $content, $m ) ) {
			return absint( $m[1] );
		}

		// Match alternate gravity-forms/form block name.
		if ( preg_match( '/wp:gravity-forms\/form\s*\{[^}]*"formId"\s*:\s*"?(\d+)/i', $content, $m ) ) {
			return absint( $m[1] );
		}

		return 0;
	}

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public function hooks() {
		add_action( 'woocommerce_product_options_general_product_data', array( $this, 'render_field' ) );
		add_action( 'woocommerce_process_product_meta', array( $this, 'save_field' ) );
		add_action( 'woocommerce_product_after_variable_attributes', array( $this, 'render_variation_field' ), 10, 3 );
		add_action( 'woocommerce_save_product_variation', array( $this, 'save_variation_field' ), 10, 2 );
	}

	/**
	 * Output fields on the product edit screen.
	 *
	 * @return void
	 */
	public function render_field() {
		global $post;

		if ( ! $post instanceof WP_Post ) {
			return;
		}

		$product = wc_get_product( $post->ID );
		if ( ! $product ) {
			return;
		}

		$page_value = $product->get_meta( self::META_REGISTRATION_PAGE, true );
		$selected   = is_numeric( $page_value ) ? absint( $page_value ) : 0;
		$url_value  = ( $page_value && ! is_numeric( $page_value ) ) ? esc_url( (string) $page_value ) : '';

		$form_id  = $product->get_meta( self::META_FORM_ID );
		$priority = $product->get_meta( self::META_REDIRECT_PRIORITY, true );

		echo '<div class="options_group ono-er-product-fields">';
		echo '<h4 style="padding:0 12px;margin:1em 0 0;">' . esc_html__( 'Event registration', 'wpcodeally-event-registration' ) . '</h4>';
		echo '<p class="form-field" style="padding:0 12px;color:#646970;">' .
			esc_html__( 'Each event can use its own page after checkout. You may embed Gravity Forms the usual way (block or [gravityform] shortcode)—no extra Ono shortcode required. Optional: use [WPCA_ER_registration] on a shared default page if you prefer one template.', 'wpcodeally-event-registration' ) .
			'</p>';

		echo '<p class="form-field"><label for="ono_reg_registration_page">' . esc_html__( 'Registration page', 'wpcodeally-event-registration' ) . '</label>';
		wp_dropdown_pages(
			array(
				'name'              => 'ono_reg_registration_page',
				'id'                => 'ono_reg_registration_page',
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- consumed by wp_dropdown_pages().
				'selected'          => $selected,
				'show_option_none'  => esc_html__( '&mdash; Select a page &mdash;', 'wpcodeally-event-registration' ),
				'option_none_value' => '0',
				'class'             => 'wc-enhanced-select',
			)
		);
		echo '</p>';

		woocommerce_wp_text_input(
			array(
				'id'          => 'ono_reg_registration_url',
				'name'        => 'ono_reg_registration_url',
				'label'       => __( 'Or registration URL', 'wpcodeally-event-registration' ),
				'placeholder' => 'https://',
				'description' => __( 'Use this instead of the dropdown for an external address. Leave empty to use the page above.', 'wpcodeally-event-registration' ),
				'type'        => 'url',
				'value'       => $url_value,
				'desc_tip'    => true,
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'                => 'ono_reg_gf_form_id',
				'name'              => 'ono_reg_gf_form_id',
				'label'             => __( 'Gravity Forms form ID', 'wpcodeally-event-registration' ),
				'description'       => __( 'Optional. Auto-detected from the registration page when left blank. Set manually only if auto-detection picks the wrong form.', 'wpcodeally-event-registration' ),
				'type'              => 'number',
				'custom_attributes' => array(
					'step' => '1',
					'min'  => '0',
				),
				'value'             => $form_id ? absint( $form_id ) : '',
				'desc_tip'          => true,
			)
		);

		woocommerce_wp_text_input(
			array(
				'id'                => 'ono_reg_redirect_priority',
				'name'              => 'ono_reg_redirect_priority',
				'label'             => __( 'Checkout redirect priority', 'wpcodeally-event-registration' ),
				'description'       => __( 'If one order contains several events, the lowest number is used first for the post-checkout redirect (default 10).', 'wpcodeally-event-registration' ),
				'type'              => 'number',
				'custom_attributes' => array(
					'step' => '1',
					'min'  => '0',
				),
				'value'             => '' !== $priority && null !== $priority ? absint( $priority ) : 10,
				'desc_tip'          => true,
			)
		);

		echo '</div>';
	}

	/**
	 * Save product meta.
	 *
	 * @param int $post_id Product post ID.
	 * @return void
	 */
	public function save_field( $post_id ) {
		if ( ! isset( $_POST['woocommerce_meta_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['woocommerce_meta_nonce'] ) ), 'woocommerce_save_data' ) ) {
			return;
		}

		$product = wc_get_product( $post_id );
		if ( ! $product ) {
			return;
		}

		$url = '';
		if ( isset( $_POST['ono_reg_registration_url'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized via esc_url_raw() below.
			$url = esc_url_raw( trim( (string) wp_unslash( $_POST['ono_reg_registration_url'] ) ) );
		}

		if ( $url ) {
			$product->update_meta_data( self::META_REGISTRATION_PAGE, $url );
		} elseif ( isset( $_POST['ono_reg_registration_page'] ) ) {
			$page_id = absint( wp_unslash( $_POST['ono_reg_registration_page'] ) );
			if ( $page_id > 0 ) {
				$product->update_meta_data( self::META_REGISTRATION_PAGE, $page_id );
			} else {
				$product->delete_meta_data( self::META_REGISTRATION_PAGE );
			}
		}

		if ( isset( $_POST['ono_reg_gf_form_id'] ) ) {
			$form_id = absint( wp_unslash( $_POST['ono_reg_gf_form_id'] ) );
			if ( $form_id > 0 ) {
				$product->update_meta_data( self::META_FORM_ID, $form_id );
			} else {
				$product->delete_meta_data( self::META_FORM_ID );
			}
		}

		if ( isset( $_POST['ono_reg_redirect_priority'] ) ) {
			$priority = absint( wp_unslash( $_POST['ono_reg_redirect_priority'] ) );
			if ( $priority > 0 ) {
				$product->update_meta_data( self::META_REDIRECT_PRIORITY, $priority );
			} else {
				$product->delete_meta_data( self::META_REDIRECT_PRIORITY );
			}
		}

		$product->save_meta_data();
	}

	/**
	 * Variation editor fields.
	 *
	 * @param int     $loop           Variation loop index.
	 * @param array   $variation_data Variation data.
	 * @param WP_Post $variation      Variation post.
	 * @return void
	 */
	public function render_variation_field( $loop, $variation_data, $variation ) {
		$product = wc_get_product( $variation->ID );
		if ( ! $product ) {
			return;
		}

		$page_value = $product->get_meta( self::META_REGISTRATION_PAGE, true );
		$selected   = is_numeric( $page_value ) ? absint( $page_value ) : 0;
		$url_value  = ( $page_value && ! is_numeric( $page_value ) ) ? esc_url( (string) $page_value ) : '';

		$value    = $product->get_meta( self::META_FORM_ID );
		$priority = $product->get_meta( self::META_REDIRECT_PRIORITY, true );
		$loop_key = (string) absint( $loop );

		echo '<div class="form-row form-row-full">';
		echo '<label>' . esc_html__( 'Registration page', 'wpcodeally-event-registration' ) . '</label>';
		wp_dropdown_pages(
			array(
				'name'              => 'ono_reg_registration_page_var[' . esc_attr( $loop_key ) . ']',
				// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- consumed by wp_dropdown_pages().
				'selected'          => $selected,
				'show_option_none'  => esc_html__( '&mdash; Same as parent / none &mdash;', 'wpcodeally-event-registration' ),
				'option_none_value' => '0',
				'class'             => 'wc-enhanced-select',
			)
		);
		echo '</div>';

		echo '<div class="form-row form-row-full">';
		echo '<label for="ono_reg_registration_url_var_' . esc_attr( $loop_key ) . '">' . esc_html__( 'Or registration URL', 'wpcodeally-event-registration' ) . '</label>';
		echo '<input type="url" class="short" name="ono_reg_registration_url_var[' . esc_attr( $loop_key ) . ']" id="ono_reg_registration_url_var_' . esc_attr( $loop_key ) . '" value="' . esc_attr( $url_value ) . '" placeholder="https://" />';
		echo '</div>';

		echo '<div class="form-row form-row-full">';
		echo '<label for="ono_reg_gf_form_id_' . esc_attr( $loop_key ) . '">' . esc_html__( 'Gravity Forms form ID', 'wpcodeally-event-registration' ) . '</label>';
		echo '<input type="number" min="0" step="1" class="short" name="ono_reg_gf_form_id_var[' . esc_attr( $loop_key ) . ']" id="ono_reg_gf_form_id_' . esc_attr( $loop_key ) . '" value="' . esc_attr( $value ? (string) absint( $value ) : '' ) . '" />';
		echo '</div>';

		echo '<div class="form-row form-row-full">';
		echo '<label for="ono_reg_redirect_priority_' . esc_attr( $loop_key ) . '">' . esc_html__( 'Redirect priority', 'wpcodeally-event-registration' ) . '</label>';
		echo '<input type="number" min="0" step="1" class="short" name="ono_reg_redirect_priority_var[' . esc_attr( $loop_key ) . ']" id="ono_reg_redirect_priority_' . esc_attr( $loop_key ) . '" value="' . esc_attr( '' !== $priority && null !== $priority ? (string) absint( $priority ) : '10' ) . '" />';
		echo '</div>';
	}

	/**
	 * Save variation meta.
	 *
	 * @param int $variation_id Variation post ID.
	 * @param int $loop         Loop index.
	 * @return void
	 */
	public function save_variation_field( $variation_id, $loop ) {
		if ( ! isset( $_POST['woocommerce_meta_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['woocommerce_meta_nonce'] ) ), 'woocommerce_save_data' ) ) {
			return;
		}

		$product = wc_get_product( $variation_id );
		if ( ! $product ) {
			return;
		}

		$url = '';
		if ( isset( $_POST['ono_reg_registration_url_var'], $_POST['ono_reg_registration_url_var'][ $loop ] ) && is_array( $_POST['ono_reg_registration_url_var'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- values are sanitized after unslashing.
			$posted_urls = wp_unslash( $_POST['ono_reg_registration_url_var'] );
			$url         = isset( $posted_urls[ $loop ] ) ? esc_url_raw( trim( (string) $posted_urls[ $loop ] ) ) : '';
		}

		if ( $url ) {
			$product->update_meta_data( self::META_REGISTRATION_PAGE, $url );
		} elseif ( isset( $_POST['ono_reg_registration_page_var'], $_POST['ono_reg_registration_page_var'][ $loop ] ) && is_array( $_POST['ono_reg_registration_page_var'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- values are sanitized via absint() below.
			$posted = wp_unslash( $_POST['ono_reg_registration_page_var'] );
			$page   = isset( $posted[ $loop ] ) ? absint( $posted[ $loop ] ) : 0;
			if ( $page > 0 ) {
				$product->update_meta_data( self::META_REGISTRATION_PAGE, $page );
			} else {
				$product->delete_meta_data( self::META_REGISTRATION_PAGE );
			}
		}

		if ( isset( $_POST['ono_reg_gf_form_id_var'], $_POST['ono_reg_gf_form_id_var'][ $loop ] ) && is_array( $_POST['ono_reg_gf_form_id_var'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- values are sanitized via absint() below.
			$posted  = wp_unslash( $_POST['ono_reg_gf_form_id_var'] );
			$form_id = isset( $posted[ $loop ] ) ? absint( $posted[ $loop ] ) : 0;
			if ( $form_id > 0 ) {
				$product->update_meta_data( self::META_FORM_ID, $form_id );
			} else {
				$product->delete_meta_data( self::META_FORM_ID );
			}
		}

		if ( isset( $_POST['ono_reg_redirect_priority_var'], $_POST['ono_reg_redirect_priority_var'][ $loop ] ) && is_array( $_POST['ono_reg_redirect_priority_var'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- values are sanitized via absint() below.
			$posted_p = wp_unslash( $_POST['ono_reg_redirect_priority_var'] );
			$priority = isset( $posted_p[ $loop ] ) ? absint( $posted_p[ $loop ] ) : 0;
			if ( $priority > 0 ) {
				$product->update_meta_data( self::META_REDIRECT_PRIORITY, $priority );
			} else {
				$product->delete_meta_data( self::META_REDIRECT_PRIORITY );
			}
		}

		$product->save_meta_data();
	}
}
