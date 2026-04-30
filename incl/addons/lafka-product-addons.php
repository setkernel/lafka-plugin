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
		// Product Combos compatibility
		add_filter( 'woocommerce_combos_compatibility_modules', array( $this, 'init_pb_compatibility_module' ) );
	}

	/**
	 * Initializes plugin classes.
	 */
	public function init_classes() {
		// Core (models)
		include_once __DIR__ . '/includes/groups/class-lafka-product-addon-group-validator.php';
		include_once __DIR__ . '/includes/groups/class-lafka-product-addon-global-group.php';
		include_once __DIR__ . '/includes/groups/class-lafka-product-addon-product-group.php';
		include_once __DIR__ . '/includes/groups/class-lafka-product-addon-groups.php';

		// Per-request groups cache invalidation on save/trash/delete of an
		// addon CPT post — without this, the admin list page rendered on
		// the same request after a save shows stale (pre-save) data.
		Lafka_Product_Addon_Groups::bootstrap();

		// Admin
		if ( is_admin() ) {
			$this->init_admin();
		}

		// Front-side
		include_once __DIR__ . '/includes/class-lafka-product-addon-display.php';
		include_once __DIR__ . '/includes/class-lafka-product-addon-cart.php';
		// Helper class used by other plugins for compatibility
		include_once __DIR__ . '/includes/class-lafka-product-addons-helper.php';

		// Phase 1 (v8.13.0): the new addon engine. Loaded alongside legacy code;
		// remains dormant until Phase 2 admin form rewires to it.
		require_once __DIR__ . '/engine/lafka-addons-engine-bootstrap.php';

		$GLOBALS['Product_Addon_Display'] = new Lafka_Product_Addon_Display();
		$GLOBALS['Product_Addon_Cart']    = new Lafka_Product_Addon_Cart();
	}

	/**
	 * Initializes plugin admin.
	 */
	protected function init_admin() {
		include_once __DIR__ . '/admin/class-lafka-product-addon-admin.php';
		$GLOBALS['Lafka_Product_Addon_Admin'] = new Lafka_Product_Addon_Admin();
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

	public function init_pb_compatibility_module( $module_paths ) {
		// Include the compatibility class for the addons of Product Bundles plugin
		if ( class_exists( 'WC_Combos' ) ) {
			$module_paths['product_addons'] = 'modules/class-wc-pb-addons-compatibility.php';
		}

		return $module_paths;
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
 * Lafka_Product_Addon_Cart's display sites to defensively coerce any
 * leftover array prices before they reach (float) cast or wc_price().
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
						$raw_attribute_prices[ $attribute ][ $attr_value ] = WC_Product_Addons_Helper::get_product_addon_price_for_display( $price );
					}
				}
			}
		}
	}

	return $raw_attribute_prices;
}
