<?php
/**
 * Tests for request argument helpers.
 *
 * @package WPCA_Event_Registration
 */

declare( strict_types=1 );

use PHPUnit\Framework\TestCase;

/**
 * Covers {@see WPCA_ER_Order_Bridge::get_request_item_id()}.
 */
final class OrderBridgeRequestTest extends TestCase {

	/**
	 * Reset superglobals after each test.
	 *
	 * @return void
	 */
	protected function tearDown(): void {
		unset( $_GET['wpca_er_item'], $_GET['ono_item'] );
		parent::tearDown();
	}

	/**
	 * Returns zero when no item query arg is present.
	 *
	 * @return void
	 */
	public function test_get_request_item_id_returns_zero_when_missing(): void {
		$this->assertSame( 0, WPCA_ER_Order_Bridge::get_request_item_id() );
	}

	/**
	 * Reads the current prefixed query arg.
	 *
	 * @return void
	 */
	public function test_get_request_item_id_reads_current_param(): void {
		$_GET['wpca_er_item'] = '42';

		$this->assertSame( 42, WPCA_ER_Order_Bridge::get_request_item_id() );
	}

	/**
	 * Falls back to the legacy query arg.
	 *
	 * @return void
	 */
	public function test_get_request_item_id_reads_legacy_param(): void {
		$_GET['ono_item'] = '17';

		$this->assertSame( 17, WPCA_ER_Order_Bridge::get_request_item_id() );
	}

	/**
	 * Prefers the current query arg over the legacy name.
	 *
	 * @return void
	 */
	public function test_get_request_item_id_prefers_current_param(): void {
		$_GET['wpca_er_item'] = '5';
		$_GET['ono_item']      = '9';

		$this->assertSame( 5, WPCA_ER_Order_Bridge::get_request_item_id() );
	}
}
