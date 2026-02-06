<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}


final class Lafka_WC_Variation_Swatches {
	/**
	 * The single instance of the class
	 *
	 * @var Lafka_WC_Variation_Swatches
	 */
	protected static $instance = null;

	/**
	 * Extra attribute types
	 *
	 * @var array
	 */
	public $types = array();

	/**
	 * Main instance
	 *
	 * @return Lafka_WC_Variation_Swatches
	 */
	public static function instance() {
		if ( null == self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	/**
	 * Class constructor.
	 */
	public function __construct() {
		$this->types = array(
			'color' => esc_html__( 'Color', 'lafka-plugin' ),
			'image' => esc_html__( 'Image', 'lafka-plugin' ),
			'label' => esc_html__( 'Label', 'lafka-plugin' ),
		);

		$this->includes();
		$this->init_hooks();
	}

	/**
	 * Include required core files used in admin and on the frontend.
	 */
	public function includes() {
		require_once( plugin_dir_path( __FILE__ ) . 'classes/class-admin.php' );
		require_once( plugin_dir_path( __FILE__ ) . 'classes/class-frontend.php' );
	}

	/**
	 * Initialize hooks
	 */
	public function init_hooks() {
		add_filter( 'product_attributes_type_selector', array( $this, 'add_attribute_types' ) );

		if ( is_admin() ) {
			add_action( 'init', array( 'Lafka_WC_Variation_Swatches_Admin', 'instance' ) );
		} else {
			add_action( 'init', array( 'Lafka_WC_Variation_Swatches_Frontend', 'instance' ) );
		}
	}

	/**
	 * Add extra attribute types
	 * Add color, image and label type
	 *
	 * @param array $types
	 *
	 * @return array
	 */
	public function add_attribute_types( $types ) {
		$types = array_merge( $types, $this->types );

		return $types;
	}

	/**
	 * Get attribute's properties
	 *
	 * @param string $taxonomy
	 *
	 * @return object
	 */
	public function get_tax_attribute( $taxonomy ) {
		global $wpdb;

		$attr = substr( $taxonomy, 3 );
		$attr = $wpdb->get_row( "SELECT * FROM " . $wpdb->prefix . "woocommerce_attribute_taxonomies WHERE attribute_name = '$attr'" );

		return $attr;
	}

	/**
	 * Instance of admin
	 *
	 * @return Lafka_WC_Variation_Swatches_Admin
	 */
	public function admin() {
		return Lafka_WC_Variation_Swatches_Admin::instance();
	}

	/**
	 * Instance of frontend
	 *
	 * @return Lafka_WC_Variation_Swatches_Frontend
	 */
	public function frontend() {
		return Lafka_WC_Variation_Swatches_Frontend::instance();
	}
}

/**
 * Main instance of plugin
 *
 * @return Lafka_WC_Variation_Swatches
 */
function Lafka_WCVS() {
	return Lafka_WC_Variation_Swatches::instance();
}

/**
 * Construct plugin when plugins loaded in order to make sure WooCommerce API is fully loaded
 * Check if WooCommerce is not activated then show an admin notice
 * or create the main instance of plugin
 */
function lafka_wc_variation_swatches_constructor() {
	if ( function_exists( 'WC' ) ) {
		Lafka_WCVS();
	}
}

add_action( 'plugins_loaded', 'lafka_wc_variation_swatches_constructor' );

