<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * PB Modules Loader
 *
 * @version  5.8.0
 */
class WC_LafkaCombos_Modules {

	/**
	 * The single instance of the class.
	 * @var WC_LafkaCombos_Modules
	 */
	protected static $_instance = null;

	/**
	 * Modules to instantiate.
	 * @var array
	 */
	protected $modules = array();

	/**
	 * Main WC_LafkaCombos_Modules instance. Ensures only one instance of WC_LafkaCombos_Modules is loaded or can be loaded.
	 *
	 * @static
	 * @return WC_LafkaCombos_Modules
	 */
	public static function instance() {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	/**
	 * Cloning is forbidden.
	 */
	public function __clone() {
		_doing_it_wrong( __FUNCTION__, __( 'Foul!', 'lafka-plugin' ), '5.8.0' );
	}

	/**
	 * Unserializing instances of this class is forbidden.
	 */
	public function __wakeup() {
		_doing_it_wrong( __FUNCTION__, __( 'Foul!', 'lafka-plugin' ), '5.8.0' );
	}

	/**
	 * Handles module initialization.
	 *
	 * @return void
	 */
	public function __construct() {

		// Abstract modules container class.
		require_once( 'abstract/class-lafka-combos-abstract-module.php' );

		// Combo-Sells module.
		require_once( 'combo-sells/class-lafka-combos-bs-module.php' );

		// Min/Max Items module.
		require_once( 'min-max-items/class-lafka-combos-mmi-module.php' );

		$module_names = apply_filters( 'woocommerce_combos_modules', array(
			'WC_LafkaCombos_BS_Module',
			'WC_LafkaCombos_MMI_Module'
		) );

		foreach ( $module_names as $module_name ) {
			$this->modules[] = new $module_name();
		}
	}

	/**
	 * Loads module functionality associated with a named component.
	 *
	 * @param  string  $name
	 */
	public function load_components( $name ) {

		foreach ( $this->modules as $module ) {
			$module->load_component( $name );
		}
	}
}
