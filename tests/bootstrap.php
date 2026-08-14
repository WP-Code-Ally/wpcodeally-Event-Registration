<?php
/**
 * PHPUnit bootstrap.
 *
 * @package WPCA_Event_Registration
 */

declare( strict_types=1 );

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __DIR__ ) . '/tests/stubs/' );
}

if ( ! function_exists( 'absint' ) ) {
	/**
	 * Minimal absint stub for unit tests.
	 *
	 * @param mixed $maybeint Value to cast.
	 * @return int
	 */
	function absint( $maybeint ) {
		return abs( (int) $maybeint );
	}
}

if ( ! function_exists( 'wp_unslash' ) ) {
	/**
	 * Minimal wp_unslash stub for unit tests.
	 *
	 * @param mixed $value Value to unslash.
	 * @return mixed
	 */
	function wp_unslash( $value ) {
		if ( is_array( $value ) ) {
			return array_map( 'wp_unslash', $value );
		}

		return is_string( $value ) ? stripslashes( $value ) : $value;
	}
}

if ( ! class_exists( 'WC_Order', false ) ) {
	/**
	 * Minimal WC_Order stub for unit tests.
	 */
	class WC_Order {

		/**
		 * Order key.
		 *
		 * @var string
		 */
		private $order_key;

		/**
		 * Constructor.
		 *
		 * @param string $order_key Order key.
		 */
		public function __construct( $order_key = '' ) {
			$this->order_key = (string) $order_key;
		}

		/**
		 * Return the order key.
		 *
		 * @return string
		 */
		public function get_order_key() {
			return $this->order_key;
		}
	}
}

require_once dirname( __DIR__ ) . '/includes/class-wpca-er-order-bridge.php';
require_once dirname( __DIR__ ) . '/includes/class-wpca-er-checkout-redirect.php';
require_once dirname( __DIR__ ) . '/includes/class-wpca-er-thankyou-bridge.php';
