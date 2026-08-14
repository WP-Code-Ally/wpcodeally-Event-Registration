<?php
/**
 * Loads optional third-party compatibility modules when their dependencies are present.
 *
 * Add new modules under includes/integrations/ and register them in {@see self::register_modules()}.
 *
 * @package WPCA_Event_Registration
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WPCA_ER_PATH . 'includes/integrations/class-wpca-er-compat-base.php';

/**
 * Discovers and boots compatibility modules.
 */
final class WPCA_ER_Compat_Loader {

	/**
	 * Booted module instances keyed by module ID.
	 *
	 * @var array<string, WPCA_ER_Compat_Base>
	 */
	private static $loaded = array();

	/**
	 * Load active compatibility modules.
	 *
	 * @return void
	 */
	public static function init() {
		foreach ( self::get_module_classes() as $class_name ) {
			self::boot_module( $class_name );
		}
	}

	/**
	 * Return a loaded module by ID, if booted this request.
	 *
	 * @param string $id Module ID from {@see WPCA_ER_Compat_Base::get_id()}.
	 * @return WPCA_ER_Compat_Base|null
	 */
	public static function get_module( $id ) {
		$id = sanitize_key( $id );

		return isset( self::$loaded[ $id ] ) ? self::$loaded[ $id ] : null;
	}

	/**
	 * Register built-in compatibility module classes.
	 *
	 * Uncomment or add entries here as real conflicts are identified in support.
	 *
	 * @return string[] Class names extending {@see WPCA_ER_Compat_Base}.
	 */
	private static function register_modules() {
		$modules = array();

		// Example: WPML.
		// if ( defined( 'ICL_SITEPRESS_VERSION' ) ) {
		// require_once WPCA_ER_PATH . 'includes/integrations/class-wpca-er-compat-wpml.php';
		// $modules[] = 'WPCA_ER_Compat_WPML';
		// }

		// Example: Polylang.
		// if ( defined( 'POLYLANG_VERSION' ) ) {
		// require_once WPCA_ER_PATH . 'includes/integrations/class-wpca-er-compat-polylang.php';
		// $modules[] = 'WPCA_ER_Compat_Polylang';
		// }

		/**
		 * Filter compatibility module class names.
		 *
		 * Use this from a site-specific plugin to register custom shims without editing core plugin files.
		 *
		 * @param string[] $modules Class names extending WPCA_ER_Compat_Base.
		 */
		return apply_filters( 'wpca_er_compat_modules', $modules );
	}

	/**
	 * Resolve the final module class list.
	 *
	 * @return string[]
	 */
	private static function get_module_classes() {
		$modules = self::register_modules();

		if ( ! is_array( $modules ) ) {
			return array();
		}

		return array_values(
			array_unique(
				array_filter(
					array_map( 'strval', $modules ),
					static function ( $class_name ) {
						return is_string( $class_name ) && '' !== $class_name;
					}
				)
			)
		);
	}

	/**
	 * Instantiate and boot one module class.
	 *
	 * @param string $class_name Module class name.
	 * @return void
	 */
	private static function boot_module( $class_name ) {
		if ( ! class_exists( $class_name ) ) {
			WPCA_ER_Plugin::log( sprintf( 'Compat module class not found: %s', $class_name ) );
			return;
		}

		$module = new $class_name();

		if ( ! $module instanceof WPCA_ER_Compat_Base ) {
			WPCA_ER_Plugin::log( sprintf( 'Compat module must extend WPCA_ER_Compat_Base: %s', $class_name ) );
			return;
		}

		if ( ! $module->is_active() ) {
			return;
		}

		$module_id = sanitize_key( $module->get_id() );
		if ( '' === $module_id ) {
			WPCA_ER_Plugin::log( sprintf( 'Compat module missing get_id(): %s', $class_name ) );
			return;
		}

		if ( isset( self::$loaded[ $module_id ] ) ) {
			WPCA_ER_Plugin::log( sprintf( 'Compat module ID already loaded: %s', $module_id ) );
			return;
		}

		$module->hooks();

		self::$loaded[ $module_id ] = $module;

		/**
		 * Fires after a compatibility module has been booted.
		 *
		 * @param WPCA_ER_Compat_Base $module     Booted module instance.
		 * @param string              $module_id  Module identifier.
		 */
		do_action( 'wpca_er_compat_module_loaded', $module, $module_id );
	}
}
