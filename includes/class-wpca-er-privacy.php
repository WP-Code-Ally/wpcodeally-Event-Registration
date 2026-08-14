<?php
/**
 * WordPress privacy tools integration.
 *
 * @package WPCA_Event_Registration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers personal data export and erasure hooks.
 */
class WPCA_ER_Privacy {

	/**
	 * Register privacy hooks.
	 *
	 * @return void
	 */
	public static function hooks() {
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );
	}

	/**
	 * Register the personal data exporter.
	 *
	 * @param array $exporters Registered exporters.
	 * @return array
	 */
	public static function register_exporter( $exporters ) {
		$exporters['wpcodeally-event-registration'] = array(
			'exporter_friendly_name' => __( 'WPCodeAlly Event Registration', 'wpcodeally-event-registration' ),
			'callback'               => array( __CLASS__, 'export_personal_data' ),
		);

		return $exporters;
	}

	/**
	 * Register the personal data eraser.
	 *
	 * @param array $erasers Registered erasers.
	 * @return array
	 */
	public static function register_eraser( $erasers ) {
		$erasers['wpcodeally-event-registration'] = array(
			'eraser_friendly_name' => __( 'WPCodeAlly Event Registration', 'wpcodeally-event-registration' ),
			'callback'             => array( __CLASS__, 'erase_personal_data' ),
		);

		return $erasers;
	}

	/**
	 * Export order-registration linkage for a user.
	 *
	 * Does not export Gravity Forms field values; those remain under Gravity Forms privacy tools.
	 *
	 * @param string $email_address User email.
	 * @param int    $page          Export page number.
	 * @return array
	 */
	public static function export_personal_data( $email_address, $page = 1 ) {
		$user = get_user_by( 'email', $email_address );
		if ( ! $user ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array(
				'data' => array(),
				'done' => true,
			);
		}

		$page     = max( 1, (int) $page );
		$per_page = 20;
		$orders   = wc_get_orders(
			array(
				'customer' => (int) $user->ID,
				'limit'    => $per_page,
				'page'     => $page,
				'orderby'  => 'date',
				'order'    => 'DESC',
				'return'   => 'objects',
			)
		);

		$export_items = array();

		foreach ( $orders as $order ) {
			foreach ( $order->get_items() as $item ) {
				if ( ! $item instanceof WC_Order_Item_Product ) {
					continue;
				}

				$form_id = WPCA_ER_Product_Admin::get_registration_form_id_for_order_item( $item );
				if ( ! $form_id ) {
					continue;
				}

				$entry_id = absint( $item->get_meta( WPCA_ER_Order_Bridge::ITEM_META_ENTRY_ID, true ) );
				$product  = $item->get_product();

				$export_items[] = array(
					'group_id'          => 'wpcodeally-event-registration',
					'group_label'       => __( 'Event registrations', 'wpcodeally-event-registration' ),
					'group_description' => __( 'WooCommerce order linkage for event registrations managed by this plugin.', 'wpcodeally-event-registration' ),
					'item_id'           => 'order-' . $order->get_id() . '-item-' . $item->get_id(),
					'data'              => array(
						array(
							'name'  => __( 'Order number', 'wpcodeally-event-registration' ),
							'value' => $order->get_order_number(),
						),
						array(
							'name'  => __( 'Event product', 'wpcodeally-event-registration' ),
							'value' => $product ? $product->get_name() : '',
						),
						array(
							'name'  => __( 'Gravity Forms form ID', 'wpcodeally-event-registration' ),
							'value' => (string) $form_id,
						),
						array(
							'name'  => __( 'Linked entry ID', 'wpcodeally-event-registration' ),
							'value' => $entry_id ? (string) $entry_id : __( 'Not submitted', 'wpcodeally-event-registration' ),
						),
					),
				);
			}
		}

		return array(
			'data' => $export_items,
			'done' => count( $orders ) < $per_page,
		);
	}

	/**
	 * Remove plugin linkage meta from a user's orders.
	 *
	 * Gravity Forms entries are intentionally preserved.
	 *
	 * @param string $email_address User email.
	 * @param int    $page          Erasure page number.
	 * @return array
	 */
	public static function erase_personal_data( $email_address, $page = 1 ) {
		$user = get_user_by( 'email', $email_address );
		if ( ! $user || ! function_exists( 'wc_get_orders' ) ) {
			return array(
				'items_removed'  => false,
				'items_retained' => false,
				'messages'       => array(),
				'done'           => true,
			);
		}

		$page     = max( 1, (int) $page );
		$per_page = 20;
		$orders   = wc_get_orders(
			array(
				'customer' => (int) $user->ID,
				'limit'    => $per_page,
				'page'     => $page,
				'orderby'  => 'date',
				'order'    => 'DESC',
				'return'   => 'objects',
			)
		);

		$removed = false;

		foreach ( $orders as $order ) {
			foreach ( $order->get_items() as $item ) {
				if ( ! $item instanceof WC_Order_Item_Product ) {
					continue;
				}

				$had_entry = $item->get_meta( WPCA_ER_Order_Bridge::ITEM_META_ENTRY_ID, true );
				$had_form  = $item->get_meta( WPCA_ER_Order_Bridge::ITEM_META_FORM_ID, true );

				if ( '' === $had_entry && '' === $had_form ) {
					continue;
				}

				$item->delete_meta_data( WPCA_ER_Order_Bridge::ITEM_META_ENTRY_ID );
				$item->delete_meta_data( WPCA_ER_Order_Bridge::ITEM_META_FORM_ID );
				$item->save();
				$removed = true;
			}
		}

		return array(
			'items_removed'  => $removed,
			'items_retained' => false,
			'messages'       => array(
				__( 'Removed WooCommerce order linkage metadata created by WPCodeAlly Event Registration. Gravity Forms entries were not deleted.', 'wpcodeally-event-registration' ),
			),
			'done'           => count( $orders ) < $per_page,
		);
	}
}
