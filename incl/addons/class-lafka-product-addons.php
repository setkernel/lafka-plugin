<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Main class.
 */
class Lafka_Product_Addons {

	protected $groups_controller;

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'plugins_loaded', array( $this, 'init_classes' ) );
		add_action( 'init', array( $this, 'init_post_types' ), 20 );
	}

	/**
	 * Initializes plugin classes.
	 */
	public function init_classes() {
		// v2 engine bootstrap — declares all engine classes.
		require_once __DIR__ . '/engine/lafka-addons-engine-bootstrap.php';

		if ( is_admin() ) {
			$this->init_admin();
		}

		// Cart + display engine instances. Templates and the bundles
		// compatibility module reference these globals; the legacy
		// $Product_Addon_Cart/$Product_Addon_Display dual-globals were
		// retired in v8.18.0 — only the modern Lafka_Engine_* globals
		// remain.
		$GLOBALS['Lafka_Engine_Cart']    = new Lafka_Engine_Cart();
		$GLOBALS['Lafka_Engine_Display'] = new Lafka_Engine_Display();

		// NX1-04c: carry addon selections through the Store API add-to-cart path
		// (block cart / headless). Reuses the engine cart instance above so its
		// cart-lifecycle hooks are never double-registered.
		$GLOBALS['Lafka_Engine_Store_Api'] = new Lafka_Engine_Store_Api( $GLOBALS['Lafka_Engine_Cart'] );

		// Bridge: addon engine ↔ WooCommerce Product Bundles. Loads only when
		// WC PB is active. Restores the toppings-on-bundled-pizzas capability
		// that the deleted Lafka Combos fork (v9.0.0) used to provide.
		if ( class_exists( 'WC_Bundled_Item' ) ) {
			require_once __DIR__ . '/engine/compat/class-lafka-bundles-addons-compatibility.php';
			Lafka_Bundles_Addons_Compatibility::init();
		}
	}

	/**
	 * Initializes plugin admin.
	 *
	 * Phase 2 (v8.13.1): v2 engine admin replaces the legacy global addons
	 * surface.
	 *
	 * Phase 3 (v8.13.2): per-product addon panel on the WC product editor
	 * also uses the engine. Legacy `incl/addons/admin/` directory deleted.
	 */
	protected function init_admin() {
		// Engine bootstrap is required before instantiating the admin since
		// the engine classes must be loaded. The bootstrap is also required
		// from init_classes() above for runtime; this is defense-in-depth
		// in case admin runs without init_classes (theoretically impossible
		// but safer).
		require_once __DIR__ . '/engine/lafka-addons-engine-bootstrap.php';
		$GLOBALS['Lafka_Engine_Admin'] = new Lafka_Engine_Admin();
	}

	/**
	 * Init post types used for addons.
	 */
	public function init_post_types() {
		register_post_type(
			'lafka_glb_addon',
			array(
				'public'              => false,
				'show_ui'             => false,
				'capability_type'     => 'product',
				'map_meta_cap'        => true,
				'publicly_queryable'  => false,
				'exclude_from_search' => true,
				'hierarchical'        => false,
				'rewrite'             => false,
				'query_var'           => false,
				'supports'            => array( 'title' ),
				'show_in_nav_menus'   => false,
				// REST exposure for admin tooling / block editor; capability gated to manage_woocommerce
				'show_in_rest'        => true,
				'rest_base'           => 'lafka-global-addons',
			)
		);

		register_taxonomy_for_object_type( 'product_cat', 'lafka_glb_addon' );
	}
}

new Lafka_Product_Addons();

require_once __DIR__ . '/lafka-product-addons-functions.php';
