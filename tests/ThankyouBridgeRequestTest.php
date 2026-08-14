<?php
/**
 * Tests for thank-you bridge request helpers.
 *
 * @package WPCA_Event_Registration
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;

/**
 * Covers {@see WPCA_ER_Thankyou_Bridge::is_checkout_flag_present()} and {@see WPCA_ER_Thankyou_Bridge::order_matches_key()}.
 */
final class ThankyouBridgeRequestTest extends TestCase {

	/**
	 * Flag is absent when the query arg is missing.
	 *
	 * @return void
	 */
	public function test_is_checkout_flag_present_returns_false_when_missing(): void {
		$this->assertFalse( WPCA_ER_Thankyou_Bridge::is_checkout_flag_present( array() ) );
	}

	/**
	 * Flag is present only when the value is 1.
	 *
	 * @return void
	 */
	public function test_is_checkout_flag_present_accepts_one(): void {
		$this->assertTrue(
			WPCA_ER_Thankyou_Bridge::is_checkout_flag_present(
				array( WPCA_ER_Thankyou_Bridge::QUERY_FLAG => '1' )
			)
		);
	}

	/**
	 * Other values do not count as a checkout return.
	 *
	 * @return void
	 */
	public function test_is_checkout_flag_present_rejects_other_values(): void {
		$this->assertFalse(
			WPCA_ER_Thankyou_Bridge::is_checkout_flag_present(
				array( WPCA_ER_Thankyou_Bridge::QUERY_FLAG => '0' )
			)
		);
		$this->assertFalse(
			WPCA_ER_Thankyou_Bridge::is_checkout_flag_present(
				array( WPCA_ER_Thankyou_Bridge::QUERY_FLAG => 'yes' )
			)
		);
	}

	/**
	 * Matching order key returns true.
	 *
	 * @return void
	 */
	public function test_order_matches_key_accepts_matching_key(): void {
		$order = new WC_Order( 'wc_order_abc123' );

		$this->assertTrue( WPCA_ER_Thankyou_Bridge::order_matches_key( $order, 'wc_order_abc123' ) );
	}

	/**
	 * Empty key, non-order, and mismatched key fail closed.
	 *
	 * @return void
	 */
	public function test_order_matches_key_rejects_invalid_input(): void {
		$order = new WC_Order( 'wc_order_abc123' );

		$this->assertFalse( WPCA_ER_Thankyou_Bridge::order_matches_key( $order, '' ) );
		$this->assertFalse( WPCA_ER_Thankyou_Bridge::order_matches_key( $order, 'wc_order_other' ) );
		$this->assertFalse( WPCA_ER_Thankyou_Bridge::order_matches_key( null, 'wc_order_abc123' ) );
	}
}
