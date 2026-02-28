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
				// PERF-C07: Prime post + meta caches for all variations in one batch query,
				// instead of calling wc_get_product() per variation (N+1).
				_prime_post_caches( $available_variation_ids, true, true );
				update_meta_cache( 'post', $available_variation_ids );

				// PERF-C07: Batch-fetch all attribute terms across all variations at once.
				$lafka_nutrition_term_lookup = array();
				$lafka_nutrition_slugs_by_tax = array();
				foreach ( $available_variation_ids as $variation_id ) {
					$variation = wc_get_product( $variation_id ); // now served from cache
					if ( ! $variation ) {
						continue;
					}
					$variation_data = $variation->get_data();
					if ( isset( $variation_data['attributes'] ) ) {
						foreach ( $variation_data['attributes'] as $attribute_name => $attribute_slug ) {
							if ( $attribute_slug ) {
								$taxonomy = str_replace( 'attribute_', '', $attribute_name );
								$lafka_nutrition_slugs_by_tax[ $taxonomy ][ $attribute_slug ] = true;
							}
						}
					}
				}
				foreach ( $lafka_nutrition_slugs_by_tax as $taxonomy => $slugs ) {
					$terms = get_terms( array(
						'taxonomy'   => $taxonomy,
						'slug'       => array_keys( $slugs ),
						'hide_empty' => false,
					) );
					if ( ! is_wp_error( $terms ) ) {
						foreach ( $terms as $term ) {
							$lafka_nutrition_term_lookup[ $taxonomy . '|' . $term->slug ] = $term->name;
						}
					}
				}

				foreach ( $available_variation_ids as $variation_id ) {
					$variation             = wc_get_product( $variation_id ); // served from cache
					if ( ! $variation ) {
						continue;
					}
					$variation_data        = $variation->get_data();
					$variation_label_array = array();

					if ( isset( $variation_data['attributes'] ) ) {
						foreach ( $variation_data['attributes'] as $attribute_name => $attribute_slug ) {
							// PERF-C07: Use pre-fetched term lookup instead of get_term_by()
							$taxonomy = str_replace( 'attribute_', '', $attribute_name );
							$lookup_key = $taxonomy . '|' . $attribute_slug;
							if ( isset( $lafka_nutrition_term_lookup[ $lookup_key ] ) ) {
								$variation_label_array[] = $lafka_nutrition_term_lookup[ $lookup_key ];
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