<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lafka_Nutrition_Display {
	/**
	 * @var string
	 */
	private $plugin_path;

	/**
	 * Initialize frontend actions.
	 */
	public function __construct() {
		// Nutrition display.
		add_action( 'woocommerce_single_product_summary', array( $this, 'display_nutrition' ), 8 );

		// Weight
		add_filter( 'woocommerce_single_product_summary', array( $this, 'display_weight' ), 7 );
	}

	/**
	 * Get the plugin path.
	 */
	public function plugin_path() {
		return $this->plugin_path = untrailingslashit( plugin_dir_path( dirname( __FILE__ ) ) );
	}

	/**
	 * Display nutrition and allergens info.
	 */
	public function display_nutrition() {
		global /** @var WC_Product $product */
		$product;

		$nutrition_list = $this->get_nutrition_list();
		$allergens      = $product->get_meta( '_lafka_product_allergens' );

		wc_get_template( 'nutrition-info.php', array(
			'lafka_nutrition_list'    => $nutrition_list,
			'lafka_product_allergens' => $allergens
		), 'lafka-plugin', $this->plugin_path() . '/templates/' );

	}

	/**
	 * Display product weight info.
	 */
	public function display_weight() {
		global /** @var WC_Product $product */
		$product;
		$is_quickview = isset($_REQUEST['action']) && $_REQUEST['action'] === 'lafka_quickview';

		if ( is_object( $product ) && ( is_product() || $is_quickview ) ) {
			$available_variation_ids = false;
			$weights                   = array();
			$weight_unit               = get_option( 'woocommerce_weight_unit' );

			if ( function_exists( 'lafka_get_available_variation_ids' ) ) {
				$available_variation_ids = lafka_get_available_variation_ids( $product );
			}

			if ( $product->get_type() === 'simple' && $product->get_weight() ) {
				$weights[]   = array(
					'title'  => '',
					'weight' => $product->get_weight()
				);
			} elseif ( $available_variation_ids ) {
				foreach ( $available_variation_ids as $variation_id ) {
					$variation             = wc_get_product( $variation_id );
					$variation_data        = $variation->get_data();
					$variation_label_array = array();

					if ( isset( $variation_data['attributes'] ) ) {
						foreach ( $variation_data['attributes'] as $attribute_name => $attribute_slug ) {
							/** @var WP_Term $attribute_term_object */
							$attribute_term_object   = get_term_by( 'slug', $attribute_slug, str_replace( 'attribute_', '', $attribute_name ) );
							if(is_a($attribute_term_object, 'WP_Term')) {
								$variation_label_array[] = $attribute_term_object->name;
							}
						}
						if ( $variation->get_weight() ) {
							$weights[] = array(
								'title'  => implode( ' ', $variation_label_array ),
								'weight' => $variation->get_weight()
							);
						}
					}
				}
			}

			wc_get_template( 'weight-info.php', array(
				'lafka_product_weights'     => $weights,
				'lafka_product_weight_unit' => $weight_unit
			), 'lafka-plugin', $this->plugin_path() . '/templates/' );
		}

		return '';
	}

	private function get_nutrition_list() {
		global /** @var WC_Product $product */
		$product;

		$nutrition_list = array();
		foreach ( Lafka_Nutrition_Config::$nutrition_meta_fields as $field_name => $data ) {
			$meta_value = $product->get_meta( '_' . $field_name );
			if ( trim( $meta_value ) !== '' ) {
				$nutrition_list[ $field_name ] = $meta_value;
			}
		}

		return $nutrition_list;
	}

}