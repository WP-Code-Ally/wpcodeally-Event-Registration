<?php
/**
 * Base class for optional third-party compatibility modules.
 *
 * @package WPCA_Event_Registration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Extend this class for plugin- or theme-specific compatibility shims.
 */
abstract class WPCA_ER_Compat_Base {

	/**
	 * Unique module identifier (e.g. wpml, polylang, theme-flatsome).
	 *
	 * @return string
	 */
	abstract public function get_id();

	/**
	 * Whether this compatibility module should load on the current site.
	 *
	 * @return bool
	 */
	abstract public function is_active();

	/**
	 * Register hooks and filters for the integrated plugin or theme.
	 *
	 * @return void
	 */
	abstract public function hooks();
}
