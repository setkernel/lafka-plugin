<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles compatibility with other WC extensions.
 *
 * @class    WC_LafkaCombos_Compatibility
 * @version  6.4.0
 */
class WC_LafkaCombos_Compatibility {

	/**
	 * Min required plugin versions to check.
	 * @var array
	 */
	private $required = array();

	/**
	 * Publicly accessible props for use by compat classes. Still not moved for back-compat.
	 * @var array
	 */
	public static $addons_prefix          = '';
	public static $combo_prefix          = '';
	public static $compat_product         = '';
	public static $compat_combined_product = '';
	public static $stock_data;

	/**
	 * The single instance of the class.
	 * @var WC_LafkaCombos_Compatibility
	 *
	 * @since 5.0.0
	 */
	protected static $_instance = null;

	/**
	 * Main WC_LafkaCombos_Compatibility instance. Ensures only one instance of WC_LafkaCombos_Compatibility is loaded or can be loaded.
	 *
	 * @static
	 * @return WC_LafkaCombos_Compatibility
	 * @since  5.0.0
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
	 * @since 5.0.0
	 */
	public function __clone() {
		_doing_it_wrong( __FUNCTION__, __( 'Foul!', 'lafka-plugin' ), '5.0.0' );
	}

	/**
	 * Unserializing instances of this class is forbidden.
	 *
	 * @since 5.0.0
	 */
	public function __wakeup() {
		_doing_it_wrong( __FUNCTION__, __( 'Foul!', 'lafka-plugin' ), '5.0.0' );
	}

	/**
	 * Setup compatibility class.
	 */
	protected function __construct() {

		// Define dependencies.
		$this->required = array(
			'cp'     => '6.2.0',
			'pao'    => '3.0.14',
			'topatc' => '1.0.3',
			'bd'     => '1.3.1'
		);

		// Initialize.
		$this->load_modules();
	}

	/**
	 * Initialize.
	 *
	 * @since  5.4.0
	 *
	 * @return void
	 */
	protected function load_modules() {

		if ( is_admin() ) {
			// Check plugin min versions.
			add_action( 'admin_init', array( $this, 'add_compatibility_notices' ) );
		}

		// Load modules.
		add_action( 'plugins_loaded', array( $this, 'module_includes' ), 100 );

		// Prevent initialization of deprecated mini-extensions.
		$this->unload_modules();
	}

	/**
	 * Core compatibility functions.
	 *
	 * @return void
	 */
	public static function core_includes() {
		require_once( WC_LafkaCombos_ABSPATH . 'includes/compatibility/core/class-lafka-combos-core-compatibility.php' );
	}

	/**
	 * Prevent deprecated mini-extensions from initializing.
	 *
	 * @since  5.0.0
	 *
	 * @return void
	 */
	protected function unload_modules() {

		// Tabular Layout mini-extension was merged into PB.
		if ( class_exists( 'WC_LafkaCombos_Tabular_Layout' ) ) {
			remove_action( 'plugins_loaded', array( 'WC_LafkaCombos_Tabular_Layout', 'load_plugin' ), 10 );
		}

		// Combo-Sells mini-extension was merged into PB.
		if ( class_exists( 'WC_LafkaCombos_Combo_Sells' ) ) {
			remove_action( 'plugins_loaded', array( 'WC_LafkaCombos_Combo_Sells', 'load_plugin' ), 10 );
		}

		// Combo-Sells mini-extension was merged into PB.
		if ( class_exists( 'WC_LafkaCombos_Min_Max_Items' ) ) {
			remove_action( 'plugins_loaded', array( 'WC_LafkaCombos_Min_Max_Items', 'load_plugin' ), 10 );
		}
	}

	/**
	 * Load compatibility classes.
	 *
	 * @return void
	 */
	public function module_includes() {

		$module_paths = array();

		// Addons support.
		if ( class_exists( 'Lafka_Product_Addons' ) && defined( 'WC_PRODUCT_ADDONS_VERSION' ) && version_compare( WC_PRODUCT_ADDONS_VERSION, $this->required[ 'pao' ] ) >= 0 ) {
			$module_paths[ 'product_addons' ] = WC_LafkaCombos_ABSPATH . 'includes/compatibility/modules/class-lafka-combos-addons-compatibility.php';
		}

		// NYP support.
		if ( function_exists( 'WC_Name_Your_Price' ) ) {
			$module_paths[ 'name_your_price' ] = WC_LafkaCombos_ABSPATH . 'includes/compatibility/modules/class-lafka-combos-nyp-compatibility.php';
		}

		// Points and Rewards support.
		if ( class_exists( 'WC_Points_Rewards_Product' ) ) {
			$module_paths[ 'points_rewards_products' ] = WC_LafkaCombos_ABSPATH . 'includes/compatibility/modules/class-lafka-combos-pnr-compatibility.php';
		}

		// Pre-orders support.
		if ( class_exists( 'WC_Pre_Orders' ) ) {
			$module_paths[ 'pre_orders' ] = WC_LafkaCombos_ABSPATH . 'includes/compatibility/modules/class-lafka-combos-po-compatibility.php';
		}

		// Composite Products support.
		if ( class_exists( 'WC_Composite_Products' ) && function_exists( 'WC_CP' ) && version_compare( WC_LafkaCombos()->plugin_version( true, WC_CP()->version ), $this->required[ 'cp' ] ) >= 0 ) {
			$module_paths[ 'composite_products' ] = WC_LafkaCombos_ABSPATH . 'includes/compatibility/modules/class-lafka-combos-cp-compatibility.php';
		}

		// One Page Checkout support.
		if ( function_exists( 'is_wcopc_checkout' ) ) {
			$module_paths[ 'one_page_checkout' ] = WC_LafkaCombos_ABSPATH . 'includes/compatibility/modules/class-lafka-combos-opc-compatibility.php';
		}

		// Cost of Goods support.
		if ( class_exists( 'WC_COG' ) ) {
			$module_paths[ 'cost_of_goods' ] = WC_LafkaCombos_ABSPATH . 'includes/compatibility/modules/class-lafka-combos-cog-compatibility.php';
		}

		// QuickView support.
		if ( class_exists( 'WC_Quick_View' ) ) {
			$module_paths[ 'quickview' ] = WC_LafkaCombos_ABSPATH . 'includes/compatibility/modules/class-lafka-combos-qv-compatibility.php';
		}

		// PIP support.
		if ( class_exists( 'WC_PIP' ) ) {
			$module_paths[ 'pip' ] = WC_LafkaCombos_ABSPATH . 'includes/compatibility/modules/class-lafka-combos-pip-compatibility.php';
		}

		// Subscriptions fixes.
		if ( class_exists( 'WC_Subscriptions' ) ) {
			$module_paths[ 'subscriptions' ] = WC_LafkaCombos_ABSPATH . 'includes/compatibility/modules/class-lafka-combos-subscriptions-compatibility.php';
		}

		// Subscriptions fixes.
		if ( class_exists( 'WC_Memberships' ) ) {
			$module_paths[ 'memberships' ] = WC_LafkaCombos_ABSPATH . 'includes/compatibility/modules/class-lafka-combos-members-compatibility.php';
		}

		// Min Max Quantities integration.
		if ( class_exists( 'WC_Min_Max_Quantities' ) ) {
			$module_paths[ 'min_max_quantities' ] = WC_LafkaCombos_ABSPATH . 'includes/compatibility/modules/class-lafka-combos-min-max-compatibility.php';
		}

		// WP Import/Export support -- based on a hack that does not when exporting using WP-CLI.
		if ( ! defined( 'WP_CLI' )  ) {
			$module_paths[ 'wp_import_export' ] = WC_LafkaCombos_ABSPATH . 'includes/compatibility/modules/class-lafka-combos-wp-ie-compatibility.php';
		}

		// WooCommerce Give Products support.
		if ( class_exists( 'WC_Give_Products' ) ) {
			$module_paths[ 'give_products' ] = WC_LafkaCombos_ABSPATH . 'includes/compatibility/modules/class-lafka-combos-give-products-compatibility.php';
		}

		// Shipwire integration.
		if ( class_exists( 'WC_Shipwire' ) ) {
			$module_paths[ 'shipwire' ] = WC_LafkaCombos_ABSPATH . 'includes/compatibility/modules/class-lafka-combos-shipwire-compatibility.php';
		}

		// Wishlists compatibility.
		if ( class_exists( 'WC_Wishlists_Plugin' ) ) {
			$module_paths[ 'wishlists' ] = WC_LafkaCombos_ABSPATH . 'includes/compatibility/modules/class-lafka-combos-wl-compatibility.php';
		}

		// WooCommerce Services compatibility.
		if ( class_exists( 'WC_Connect_Loader' ) ) {
			$module_paths[ 'wc_services' ] = WC_LafkaCombos_ABSPATH . 'includes/compatibility/modules/class-lafka-combos-wc-services-compatibility.php';
		}

		// Shipstation integration.
		$module_paths[ 'shipstation' ] = WC_LafkaCombos_ABSPATH . 'includes/compatibility/modules/class-lafka-combos-shipstation-compatibility.php';

		// Storefront compatibility.
		if ( function_exists( 'wc_is_active_theme' ) && wc_is_active_theme( 'storefront' ) ) {
			$module_paths[ 'storefront' ] = WC_LafkaCombos_ABSPATH . 'includes/compatibility/modules/class-lafka-combos-sf-compatibility.php';
		}

		// Flatsome compatibility.
		if ( function_exists( 'wc_is_active_theme' ) && wc_is_active_theme( 'flatsome' ) ) {
			$module_paths[ 'flatsome' ] = WC_LafkaCombos_ABSPATH . 'includes/compatibility/modules/class-lafka-combos-fs-compatibility.php';
		}

		// Divi compatibility.
		if ( function_exists( 'wc_is_active_theme' ) && wc_is_active_theme( 'Divi' ) ) {
			$module_paths[ 'divi' ] = WC_LafkaCombos_ABSPATH . 'includes/compatibility/modules/class-lafka-combos-et-compatibility.php';
		}

		// Elementor Pro compatibility.
		if ( class_exists('\ElementorPro\Plugin') ) {
			$module_paths[ 'elementor' ] = WC_LafkaCombos_ABSPATH . 'includes/compatibility/modules/class-lafka-combos-elementor-compatibility.php';
		}

		// PayPal Express Checkout compatibility.
		if ( class_exists( 'WC_Gateway_PPEC_Plugin' ) ) {
			$module_paths[ 'ppec' ] = WC_LafkaCombos_ABSPATH . 'includes/compatibility/modules/class-lafka-combos-ppec-compatibility.php';
		}

		// Stripe compatibility.
		if ( class_exists( 'WC_Gateway_Stripe' ) ) {
			$module_paths[ 'stripe' ] = WC_LafkaCombos_ABSPATH . 'includes/compatibility/modules/class-lafka-combos-stripe-compatibility.php';
		}

		// ThemeAlien Variation Swatches for WooCommerce compatibility.
		$module_paths[ 'taws_variation_swatches' ] = WC_LafkaCombos_ABSPATH . 'includes/compatibility/modules/class-lafka-combos-taws-variation-swatches-compatibility.php';

		/**
		 * 'woocommerce_combos_compatibility_modules' filter.
		 *
		 * Use this to filter the required compatibility modules.
		 *
		 * @since  5.7.6
		 * @param  array $module_paths
		 */
		$module_paths = apply_filters( 'woocommerce_combos_compatibility_modules', $module_paths );

		foreach ( $module_paths as $name => $path ) {
			require_once( $path );
		}
	}

	/**
	 * Get min module version.
	 *
	 * @since  6.0.0
	 * @return bool
	 */
	public function get_required_module_version( $module ) {
		return isset( $this->required[ $module ] ) ? $this->required[ $module ] : null;
	}

	/**
	 * Checks versions of compatible/integrated/deprecated extensions.
	 *
	 * @return void
	 */
	public function add_compatibility_notices() {

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		// CP version check.
		if ( class_exists( 'WC_Composite_Products' ) && function_exists( 'WC_CP' ) ) {
			$required_version = $this->required[ 'cp' ];
			if ( version_compare( WC_LafkaCombos()->plugin_version( true, WC_CP()->version ), $required_version ) < 0 ) {

				$extension      = __( 'Composite Products', 'lafka-plugin' );
				$extension_full = __( 'WooCommerce Composite Products', 'lafka-plugin' );
				$extension_url  = 'https://woocommerce.com/products/composite-products/';
				$notice         = sprintf( __( 'The installed version of <strong>%1$s</strong> is not supported by <strong>Product Combos</strong>. Please update <a href="%2$s" target="_blank">%3$s</a> to version <strong>%4$s</strong> or higher.', 'lafka-plugin' ), $extension, $extension_url, $extension_full, $required_version );

				WC_LafkaCombos_Admin_Notices::add_dismissible_notice( $notice, array( 'dismiss_class' => 'cp_lt_' . $required_version, 'type' => 'warning' ) );
			}
		}

		// Addons version check.
		if ( class_exists( 'WC_Product_Addons' ) ) {

			$required_version = $this->required[ 'pao' ];

			if ( ! defined( 'WC_PRODUCT_ADDONS_VERSION' ) || version_compare( WC_PRODUCT_ADDONS_VERSION, $required_version ) < 0 ) {

				$extension      = __( 'Product Add-Ons', 'lafka-plugin' );
				$extension_full = __( 'WooCommerce Product Add-Ons', 'lafka-plugin' );
				$extension_url  = 'https://woocommerce.com/products/product-add-ons/';
				$notice         = sprintf( __( 'The installed version of <strong>%1$s</strong> is not supported by <strong>Product Combos</strong>. Please update <a href="%2$s" target="_blank">%3$s</a> to version <strong>%4$s</strong> or higher.', 'lafka-plugin' ), $extension, $extension_url, $extension_full, $required_version );

				WC_LafkaCombos_Admin_Notices::add_dismissible_notice( $notice, array( 'dismiss_class' => 'addons_lt_' . $required_version, 'type' => 'warning' ) );
			}
		}

		// Tabular layout mini-extension check.
		if ( class_exists( 'WC_LafkaCombos_Tabular_Layout' ) ) {
			$notice = sprintf( __( 'The <strong>Tabular Layout</strong> mini-extension has been rolled into <strong>Product Combos</strong>. Please deactivate and remove the <strong>Product Combos - Tabular Layout</strong> feature plugin.', 'lafka-plugin' ) );
			WC_LafkaCombos_Admin_Notices::add_notice( $notice, 'warning' );
		}

		// Combo-Sells mini-extension check.
		if ( class_exists( 'WC_LafkaCombos_Combo_Sells' ) ) {
			$notice = sprintf( __( 'The <strong>Combo-Sells</strong> mini-extension has been rolled into <strong>Product Combos</strong>. Please deactivate and remove the <strong>Product Combos - Combo-Sells</strong> feature plugin.', 'lafka-plugin' ) );
			WC_LafkaCombos_Admin_Notices::add_notice( $notice, 'warning' );
		}

		// Min/Max Items mini-extension check.
		if ( class_exists( 'WC_LafkaCombos_Min_Max_Items' ) ) {
			$notice = sprintf( __( 'The <strong>Min/Max Items</strong> mini-extension has been rolled into <strong>Product Combos</strong>. Please deactivate and remove the <strong>Product Combos - Min/Max Items</strong> feature plugin. If you have localized Min/Max Items in your language, please be aware that all localizable strings have been moved into the Product Combos text domain.', 'lafka-plugin' ) );
			WC_LafkaCombos_Admin_Notices::add_notice( $notice, 'warning' );
		}

		// Top Add-to-Cart mini-extension version check.
		if ( class_exists( 'WC_LafkaCombos_Top_Add_To_Cart' ) ) {
			$required_version = $this->required[ 'topatc' ];
			if ( version_compare( WC_LafkaCombos()->plugin_version( true, WC_LafkaCombos_Top_Add_To_Cart::$version ), $required_version ) < 0 ) {

				$extension = __( 'Product Combos - Top Add to Cart Button', 'lafka-plugin' );
				$notice    = sprintf( __( 'The installed version of <strong>%1$s</strong> is not supported by <strong>Product Combos</strong>. Please update <strong>%1$s</strong> to version <strong>%2$s</strong> or higher.', 'lafka-plugin' ), $extension, $required_version );

				WC_LafkaCombos_Admin_Notices::add_notice( $notice, 'warning' );
			}
		}

		// Bulk Discounts mini-extension version check.
		if ( class_exists( 'WC_LafkaCombos_Bulk_Discounts' ) ) {
			$required_version = $this->required[ 'bd' ];
			if ( version_compare( WC_LafkaCombos()->plugin_version( true, WC_LafkaCombos_Bulk_Discounts::$version ), $required_version ) < 0 ) {

				$extension      = $extension_full = __( 'Product Combos - Bulk Discounts', 'lafka-plugin' );
				$extension_url  = 'https://wordpress.org/plugins/product-combos-bulk-discounts-for-woocommerce/';
				$notice         = sprintf( __( 'The installed version of <strong>%1$s</strong> is not supported by <strong>Product Combos</strong>. Please update <a href="%2$s" target="_blank">%3$s</a> to version <strong>%4$s</strong> or higher.', 'lafka-plugin' ), $extension, $extension_url, $extension_full, $required_version );

				WC_LafkaCombos_Admin_Notices::add_notice( $notice, 'warning' );
			}
		}
	}

	/**
	 * Rendering a PIP document?
	 *
	 * @since  5.5.0
	 *
	 * @param  string  $type
	 * @return boolean
	 */
	public function is_pip( $type = '' ) {
		return class_exists( 'WC_LafkaCombos_PIP_Compatibility' ) && WC_LafkaCombos_PIP_Compatibility::rendering_document( $type );
	}

	/**
	 * Tells if a product is a Name Your Price product, provided that the extension is installed.
	 *
	 * @param  mixed  $product_id
	 * @return boolean
	 */
	public function is_nyp( $product_id ) {

		if ( ! class_exists( 'WC_Name_Your_Price_Helpers' ) ) {
			return false;
		}

		if ( WC_Name_Your_Price_Helpers::is_nyp( $product_id ) ) {
			return true;
		}

		return false;
	}

	/**
	 * Tells if a product is a subscription, provided that Subs is installed.
	 *
	 * @param  mixed  $product
	 * @return boolean
	 */
	public function is_subscription( $product ) {

		if ( ! class_exists( 'WC_Subscriptions' ) ) {
			return false;
		}

		return WC_Subscriptions_Product::is_subscription( $product );
	}

	/**
	 * Tells if an order item is a subscription, provided that Subs is installed.
	 *
	 * @param  mixed     $order
	 * @param  WC_Prder  $order
	 * @return boolean
	 */
	public function is_item_subscription( $order, $item ) {

		if ( ! class_exists( 'WC_Subscriptions_Order' ) ) {
			return false;
		}

		return WC_Subscriptions_Order::is_item_subscription( $order, $item );
	}

	/**
	 * Checks if a product has any required addons.
	 *
	 * @since  5.9.2
	 *
	 * @param  mixed    $product
	 * @param  boolean  $required
	 * @return boolean
	 */
	public function has_addons( $product, $required = false ) {

		if ( ! class_exists( 'WC_LafkaCombos_Addons_Compatibility' ) ) {
			return false;
		}

		return WC_LafkaCombos_Addons_Compatibility::has_addons( $product, $required );
	}

	/**
	 * Alias to 'wc_cp_is_composited_cart_item'.
	 *
	 * @since  5.0.0
	 *
	 * @param  array  $item
	 * @return boolean
	 */
	public function is_composited_cart_item( $item ) {

		$is = false;

		if ( function_exists( 'wc_cp_is_composited_cart_item' ) ) {
			$is = wc_cp_is_composited_cart_item( $item );
		}

		return $is;
	}

	/**
	 * Alias to 'wc_cp_is_composited_order_item'.
	 *
	 * @since  5.0.0
	 *
	 * @param  array     $item
	 * @param  WC_Order  $order
	 * @return boolean
	 */
	public function is_composited_order_item( $item, $order ) {

		$is = false;

		if ( function_exists( 'wc_cp_is_composited_order_item' ) ) {
			$is = wc_cp_is_composited_order_item( $item, $order );
		}

		return $is;
	}

	/*
	|--------------------------------------------------------------------------
	| Deprecated methods.
	|--------------------------------------------------------------------------
	*/

	/**
	 * Checks if a product has required addons.
	 *
	 * @param  mixed  $product
	 * @return boolean
	 */
	public function has_required_addons( $product ) {
		_deprecated_function( __METHOD__ . '()', '5.9.2', __CLASS__ . '::has_addons()' );
		return $this->has_addons( $product, true );
	}
}

WC_LafkaCombos_Compatibility::core_includes();
