<?php
/**
 * Example compatibility module — copy and rename; do not load in production as-is.
 *
 * @package WPCA_Event_Registration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Template for third-party compatibility shims.
 *
 * 1. Copy this file to class-wpca-er-compat-your-plugin.php
 * 2. Rename the class (e.g. WPCA_ER_Compat_WPML)
 * 3. Register it in WPCA_ER_Compat_Loader::register_modules()
 *    or via the wpca_er_compat_modules filter
 */
class WPCA_ER_Compat_Template extends WPCA_ER_Compat_Base {

	/**
	 * Module identifier.
	 *
	 * @return string
	 */
	public function get_id() {
		return 'template';
	}

	/**
	 * Whether the integrated plugin or theme is active.
	 *
	 * @return bool
	 */
	public function is_active() {
		return false;
	}

	/**
	 * Register compatibility hooks.
	 *
	 * @return void
	 */
	public function hooks() {
		// add_filter( 'wpca_er_checkout_redirect_candidates', array( $this, 'filter_redirect_candidates' ), 10, 2 );
	}
}
