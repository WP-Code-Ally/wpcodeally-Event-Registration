<?php
/**
 * My Account: event registration rows.
 *
 * @var array[] $rows Each row: order, item, product, form_id, entry_id, start_url, edit_url, order_date, order_total.
 *
 * @package WPCA_Event_Registration
 */

defined( 'ABSPATH' ) || exit;
?>
<table class="woocommerce-orders-table woocommerce-MyAccount-orders shop_table shop_table_responsive my_account_orders account-orders-table">
	<thead>
		<tr>
			<th class="woocommerce-orders-table__header"><span class="nobr"><?php esc_html_e( 'Order', 'wpcodeally-event-registration' ); ?></span></th>
			<th class="woocommerce-orders-table__header"><span class="nobr"><?php esc_html_e( 'Order date', 'wpcodeally-event-registration' ); ?></span></th>
			<th class="woocommerce-orders-table__header"><span class="nobr"><?php esc_html_e( 'Order total', 'wpcodeally-event-registration' ); ?></span></th>
			<th class="woocommerce-orders-table__header"><span class="nobr"><?php esc_html_e( 'Event', 'wpcodeally-event-registration' ); ?></span></th>
			<th class="woocommerce-orders-table__header"><span class="nobr"><?php esc_html_e( 'Registration', 'wpcodeally-event-registration' ); ?></span></th>
			<th class="woocommerce-orders-table__header"><span class="nobr"><?php esc_html_e( 'Actions', 'wpcodeally-event-registration' ); ?></span></th>
		</tr>
	</thead>
	<tbody>
		<?php foreach ( $rows as $row ) : ?>
			<?php
			/** Row order instance. */
			$row_order   = $row['order'];
			$item        = $row['item'];
			$product     = $row['product'];
			$entry_id    = (int) $row['entry_id'];
			$start_url   = isset( $row['start_url'] ) ? (string) $row['start_url'] : '';
			$edit_url    = isset( $row['edit_url'] ) ? (string) $row['edit_url'] : '';
			$order_date  = isset( $row['order_date'] ) ? (string) $row['order_date'] : '';
			$order_total = isset( $row['order_total'] ) ? (string) $row['order_total'] : '';
			?>
			<tr class="woocommerce-orders-table__row order">
				<td class="woocommerce-orders-table__cell" data-title="<?php esc_attr_e( 'Order', 'wpcodeally-event-registration' ); ?>">
					<a href="<?php echo esc_url( $row_order->get_view_order_url() ); ?>">#<?php echo esc_html( (string) $row_order->get_order_number() ); ?></a>
				</td>
				<td class="woocommerce-orders-table__cell" data-title="<?php esc_attr_e( 'Order date', 'wpcodeally-event-registration' ); ?>">
					<?php echo esc_html( $order_date ); ?>
				</td>
				<td class="woocommerce-orders-table__cell" data-title="<?php esc_attr_e( 'Order total', 'wpcodeally-event-registration' ); ?>">
					<?php echo wp_kses_post( $order_total ); ?>
				</td>
				<td class="woocommerce-orders-table__cell" data-title="<?php esc_attr_e( 'Event', 'wpcodeally-event-registration' ); ?>">
					<?php echo esc_html( $product->get_name() ); ?>
				</td>
				<td class="woocommerce-orders-table__cell" data-title="<?php esc_attr_e( 'Registration', 'wpcodeally-event-registration' ); ?>">
					<?php
					if ( $entry_id ) {
						esc_html_e( 'Submitted', 'wpcodeally-event-registration' );
					} else {
						esc_html_e( 'Not submitted', 'wpcodeally-event-registration' );
					}
					?>
				</td>
				<td class="woocommerce-orders-table__cell" data-title="<?php esc_attr_e( 'Actions', 'wpcodeally-event-registration' ); ?>">
					<?php if ( $entry_id && $edit_url ) : ?>
						<a class="woocommerce-button button" href="<?php echo esc_url( $edit_url ); ?>"><?php esc_html_e( 'Edit', 'wpcodeally-event-registration' ); ?></a>
					<?php elseif ( $start_url ) : ?>
						<a class="woocommerce-button button" href="<?php echo esc_url( $start_url ); ?>"><?php esc_html_e( 'Complete registration', 'wpcodeally-event-registration' ); ?></a>
					<?php endif; ?>
				</td>
			</tr>
		<?php endforeach; ?>
	</tbody>
</table>
