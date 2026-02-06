<?php
/**
* Lafka Product Combos
*/

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main plugin class.
 *
 * @class    Lafka_Combos
 * @version  6.8.0
 */
class Lafka_Combos {

	public $version  = '6.8.0';
	public $required = '3.1.0';

	/**
	 * The single instance of the class.
	 * @var Lafka_Combos
	 *
	 * @since 4.11.4
	 */
	protected static $_instance = null;

	/**
	 * Main Lafka_Combos instance. Ensures only one instance of Lafka_Combos is loaded or can be loaded - @see 'WC_LafkaCombos()'.
	 *
	 * @static
	 * @return Lafka_Combos
	 * @since  4.11.4
	 */
	public static function instance() {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	/**
	 * Cloning is forbidden.
	 *
	 * @since 4.11.4
	 */
	public function __clone() {
		_doing_it_wrong( __FUNCTION__, __( 'Foul!', 'lafka-plugin' ), '4.11.4' );
	}

	/**
	 * Unserializing instances of this class is forbidden.
	 *
	 * @since 4.11.4
	 */
	public function __wakeup() {
		_doing_it_wrong( __FUNCTION__, __( 'Foul!', 'lafka-plugin' ), '4.11.4' );
	}

	/**
	 * Make stuff.
	 */
	protected function __construct() {
		// Entry point.
		add_action( 'plugins_loaded', array( $this, 'initialize_plugin' ), 9 );
	}

	/**
	 * Auto-load in-accessible properties.
	 *
	 * @param  mixed  $key
	 * @return mixed
	 */
	public function __get( $key ) {
		if ( in_array( $key, array( 'compatibility', 'modules', 'cart', 'order', 'display' ) ) ) {
			$classname = 'WC_LafkaCombos_' . ucfirst( $key );
			return call_user_func( array( $classname, 'instance' ) );
		}
	}

	/**
	 * Plugin URL getter.
	 *
	 * @return string
	 */
	public function plugin_url() {
		return untrailingslashit( plugins_url( '/', __FILE__ ) );
	}

	/**
	 * Plugin path getter.
	 *
	 * @return string
	 */
	public function plugin_path() {
		return untrailingslashit( plugin_dir_path( __FILE__ ) );
	}

	/**
	 * Plugin base path name getter.
	 *
	 * @return string
	 */
	public function plugin_basename() {
		return plugin_basename( __FILE__ );
	}

	/**
	 * Indicates whether the plugin has been fully initialized.
	 *
	 * @since  6.2.0
	 *
	 * @return boolean
	 */
	public function plugin_initialized() {
		return class_exists( 'WC_LafkaCombos_Helpers' );
	}

	/**
	 * Define constants if not present.
	 *
	 * @since  6.2.0
	 *
	 * @return boolean
	 */
	protected function maybe_define_constant( $name, $value ) {
		if ( ! defined( $name ) ) {
			define( $name, $value );
		}
	}

	/**
	 * Plugin version getter.
	 *
	 * @since  5.8.0
	 *
	 * @param  boolean  $base
	 * @param  string   $version
	 * @return string
	 */
	public function plugin_version( $base = false, $version = '' ) {

		$version = $version ? $version : $this->version;

		if ( $base ) {
			$version_parts = explode( '-', $version );
			$version       = sizeof( $version_parts ) > 1 ? $version_parts[0] : $version;
		}

		return $version;
	}

	/**
	 * Fire in the hole!
	 */
	public function initialize_plugin() {

		$this->define_constants();
		$this->maybe_create_store();

		$this->includes();

		WC_LafkaCombos_Compatibility::instance();
		WC_LafkaCombos_Modules::instance();

		WC_LafkaCombos_Cart::instance();
		$this->modules->load_components( 'cart' );

		WC_LafkaCombos_Order::instance();
		$this->modules->load_components( 'order' );

		WC_LafkaCombos_Display::instance();
		$this->modules->load_components( 'display' );
	}

	/**
	 * Constants.
	 */
	public function define_constants() {

		$this->maybe_define_constant( 'WC_LafkaCombos_VERSION', $this->version );
		$this->maybe_define_constant( 'WC_LafkaCombos_ABSPATH', trailingslashit( plugin_dir_path( __FILE__ ) ) );

		/*
		 * Available debug constants:
		 *
		 * 'WC_LafkaCombos_DEBUG_STOCK_CACHE' - Used to disable combined item stock caching.
		 *
		 * 'WC_LafkaCombos_DEBUG_STOCK_SYNC' - Used to disable combined item stock syncing in the background.
		 *
		 * 'WC_LafkaCombos_DEBUG_STOCK_PARENT_SYNC' - Used to disable stock status and visibility syncing for combo containers.
		 *
		 * 'WC_LafkaCombos_DEBUG_TRANSIENTS' - Used to disable transients caching.
		 *
		 * 'WC_LafkaCombos_DEBUG_OBJECT_CACHE' - Used to disable object caching.
		 *
		 * 'WC_LafkaCombos_DEBUG_RUNTIME_CACHE' - Used to disable runtime object caching.
		 */

		if ( defined( 'WC_LafkaCombos_DEBUG_STOCK_CACHE' ) ) {
			/**
			 * 'WC_LafkaCombos_DEBUG_STOCK_SYNC' constant.
			 *
			 * Used to disable combined product stock meta syncing for combined items.
			 */
			$this->maybe_define_constant( 'WC_LafkaCombos_DEBUG_STOCK_SYNC', true );
		}

		if ( defined( 'WC_LafkaCombos_DEBUG_STOCK_SYNC' ) || ! function_exists( 'WC' ) || version_compare( WC()->version, '3.3.0' ) < 0 ) {
			/**
			 * 'WC_LafkaCombos_DEBUG_STOCK_PARENT_SYNC' constant.
			 *
			 * Used to disable stock status and visibility syncing for combos.
			 * Requires the 'WC_Background_Process' class introduced in WC 3.3.
			 */
			$this->maybe_define_constant( 'WC_LafkaCombos_DEBUG_STOCK_PARENT_SYNC', true );
		}
	}

	/**
	 * A simple dumb datastore for sharing information accross our plugins.
	 *
	 * @since  6.3.0
	 *
	 * @return void
	 */
	private function maybe_create_store() {
		if ( ! isset( $GLOBALS['sw_store'] ) ) {
			$GLOBALS['sw_store'] = array();
		}
	}

	/**
	 * Includes.
	 */
	public function includes() {

		// Extensions compatibility functions and hooks.
		require_once WC_LafkaCombos_ABSPATH . 'includes/compatibility/class-lafka-combos-compatibility.php';

		// Modules.
		require_once WC_LafkaCombos_ABSPATH . 'includes/modules/class-lafka-combos-modules.php';

		// Data classes.
		require_once WC_LafkaCombos_ABSPATH . 'includes/data/class-lafka-combos-data.php';

		// Install.
		require_once WC_LafkaCombos_ABSPATH . 'includes/class-lafka-combos-install.php';

		// Functions (incl deprecated).
		require_once WC_LafkaCombos_ABSPATH . 'includes/wc-pc-functions.php';

		// Helper functions and hooks.
		require_once WC_LafkaCombos_ABSPATH . 'includes/class-lafka-combos-helpers.php';

		// Data syncing between products and combined items.
		require_once WC_LafkaCombos_ABSPATH . 'includes/class-lafka-combos-db-sync.php';

		// Product price filters and price-related functions.
		require_once WC_LafkaCombos_ABSPATH . 'includes/class-lafka-combos-product-prices.php';

		// Combined Item class.
		require_once WC_LafkaCombos_ABSPATH . 'includes/class-wc-combined-item.php';

		// Product Combo class.
		require_once WC_LafkaCombos_ABSPATH . 'includes/class-wc-product-combo.php';

		// Stock mgr class.
		require_once WC_LafkaCombos_ABSPATH . 'includes/class-lafka-combos-stock-manager.php';

		// Cart-related functions and hooks.
		require_once WC_LafkaCombos_ABSPATH . 'includes/class-lafka-combos-cart.php';

		// Order-related functions and hooks.
		require_once WC_LafkaCombos_ABSPATH . 'includes/class-lafka-combos-order.php';

		// Order-again functions and hooks.
		require_once WC_LafkaCombos_ABSPATH . 'includes/class-lafka-combos-order-again.php';

		// Coupon-related functions and hooks.
		require_once WC_LafkaCombos_ABSPATH . 'includes/class-lafka-combos-coupon.php';

		// Front-end filters and templates.
		require_once WC_LafkaCombos_ABSPATH . 'includes/class-lafka-combos-display.php';

		// Front-end AJAX handlers.
		require_once WC_LafkaCombos_ABSPATH . 'includes/class-lafka-combos-ajax.php';

		// Notices handling.
		require_once WC_LafkaCombos_ABSPATH . 'includes/class-lafka-combos-notices.php';

		// Admin includes.
		if ( is_admin() ) {
			$this->admin_includes();
		}
	}

	/**
	 * Admin & AJAX functions and hooks.
	 */
	public function admin_includes() {

		// Admin notices handling.
		require_once WC_LafkaCombos_ABSPATH . 'includes/admin/class-lafka-combos-admin-notices.php';

		// Admin functions and hooks.
		require_once WC_LafkaCombos_ABSPATH . 'includes/admin/class-lafka-combos-admin.php';
	}
}

/**
 * Returns the main instance of Lafka_Combos to prevent the need to use globals.
 *
 * @since  4.11.4
 * @return Lafka_Combos
 */
function WC_LafkaCombos() {
	return Lafka_Combos::instance();
}

$GLOBALS['woocommerce_combos'] = WC_LafkaCombos();
