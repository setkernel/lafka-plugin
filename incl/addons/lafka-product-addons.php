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
		define( 'WC_PRODUCT_ADDONS_VERSION', '3.1.0' ); // WRCS: DEFINED_VERSION.
		add_action( 'plugins_loaded', array( $this, 'init_classes' ) );
		add_action( 'init', array( $this, 'init_post_types' ), 20 );
		// Note: Lafka_Combos integration was retired in v9.0.0 alongside the
		// Lafka Combos fork itself. If a future release ships an addons↔
		// WC Product Bundles bridge, hook it here against
		// `woocommerce_bundles_compatibility_modules`.
	}

	/**
	 * Initializes plugin classes.
	 */
	public function init_classes() {
		// v2 engine bootstrap — declares all engine classes plus the
		// WC_Product_Addons_Helper class_alias.
		require_once __DIR__ . '/engine/lafka-addons-engine-bootstrap.php';

		if ( is_admin() ) {
			$this->init_admin();
		}

		// Cart + display engine instances. Templates and the Combos
		// compatibility module reference these globals; the legacy
		// $Product_Addon_Cart/$Product_Addon_Display dual-globals were
		// retired in v8.18.0 — only the modern Lafka_Engine_* globals
		// remain.
		$GLOBALS['Lafka_Engine_Cart']    = new Lafka_Engine_Cart();
		$GLOBALS['Lafka_Engine_Display'] = new Lafka_Engine_Display();
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

function lafka_get_option_price_on_default_attribute( $product, $option_price ) {
	if ( ! is_array( $option_price ) ) {
		return $option_price;
	}

	$default_attributes = $product->get_default_attributes();
	foreach ( $default_attributes as $tax => $value ) {
		if ( isset( $option_price[ $tax ][ $value ] ) && is_scalar( $option_price[ $tax ][ $value ] ) ) {
			return $option_price[ $tax ][ $value ];
		}
	}

	// No default attribute matched — walk the matrix down to the deepest
	// scalar (depth-bounded against corrupt data). Previously `reset()`
	// returned the first sub-array (e.g. ['small'=>'1.00', 'medium'=>'1.50'])
	// and let an array bubble up to wc_price() / display formatting,
	// producing notices and the literal "Array" in some PDP states.
	return lafka_addons_walk_to_scalar_price( $option_price );
}

/**
 * Coerce a possibly-nested addon price matrix to a scalar by walking the
 * first key at each level. Depth-bounded (10 levels) so a corrupt data
 * structure can't infinite-loop. Returns 0 when the walk doesn't terminate
 * in a scalar.
 *
 * Used by lafka_get_option_price_on_default_attribute() and by
 * Lafka_Engine_Cart's display sites to defensively coerce any leftover
 * array prices before they reach (float) cast or wc_price().
 *
 * @param mixed $price Scalar or possibly-nested array.
 * @return string|int|float
 */
function lafka_addons_walk_to_scalar_price( $price ) {
	$depth = 0;
	while ( is_array( $price ) && ! empty( $price ) && $depth < 10 ) {
		$price = reset( $price );
		++$depth;
	}
	return is_scalar( $price ) ? $price : 0;
}

function lafka_convert_attribute_raw_prices_to_prices( $raw_attribute_prices ) {
	if ( is_array( $raw_attribute_prices ) ) {
		foreach ( $raw_attribute_prices as $attribute => $prices ) {
			if ( is_array( $prices ) ) {
				foreach ( $prices as $attr_value => $price ) {
					if ( is_numeric( $price ) ) {
						$raw_attribute_prices[ $attribute ][ $attr_value ] = Lafka_Engine_Helper::get_product_addon_price_for_display( $price );
					}
				}
			}
		}
	}

	return $raw_attribute_prices;
}
