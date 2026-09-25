<?php
/**
 * Store API product data (GX4): `extensions.lafka` on /wc/store/v1/products.
 *
 *   · serves              (int)  people the product feeds, 0 = not set
 *                                (lafka_get_product_serves()).
 *   · has_required_addons (bool) the product has a required add-on group, so
 *                                a listing sends the customer to the product
 *                                page instead of a quick add
 *                                (lafka_product_has_required_addons()).
 *
 * Read-only; registered on woocommerce_init like the cart extension in
 * class-lafka-store-api.php.
 *
 * @package Lafka\Plugin\StoreApi
 * @since   10.2.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'lafka_store_api_product_data' ) ) {
	/**
	 * `extensions.lafka` for one product.
	 *
	 * @param mixed $product WC_Product.
	 * @return array{serves:int,has_required_addons:bool}
	 */
	function lafka_store_api_product_data( $product ): array {
		$data = array(
			'serves'              => 0,
			'has_required_addons' => false,
		);
		if ( ! is_object( $product ) || ! method_exists( $product, 'get_id' ) ) {
			return $data;
		}
		if ( function_exists( 'lafka_get_product_serves' ) ) {
			$data['serves'] = (int) lafka_get_product_serves( $product );
		}
		if ( function_exists( 'lafka_product_has_required_addons' ) ) {
			$parent                      = method_exists( $product, 'get_parent_id' ) ? (int) $product->get_parent_id() : 0;
			$data['has_required_addons'] = lafka_product_has_required_addons( $parent > 0 ? $parent : (int) $product->get_id() );
		}

		return $data;
	}
}

if ( ! function_exists( 'lafka_store_api_product_schema' ) ) {
	/**
	 * Schema of `extensions.lafka` on the product endpoint.
	 *
	 * @return array
	 */
	function lafka_store_api_product_schema(): array {
		return array(
			'serves'              => array(
				'description' => __( 'How many people the product feeds (0 = not set).', 'lafka-plugin' ),
				'type'        => 'integer',
				'context'     => array( 'view', 'edit' ),
				'readonly'    => true,
			),
			'has_required_addons' => array(
				'description' => __( 'Whether the product has a required add-on group (choose on the product page).', 'lafka-plugin' ),
				'type'        => 'boolean',
				'context'     => array( 'view', 'edit' ),
				'readonly'    => true,
			),
		);
	}
}

if ( ! function_exists( 'lafka_store_api_product_register' ) ) {
	/**
	 * Register the product extension (woocommerce_init).
	 *
	 * @return void
	 */
	function lafka_store_api_product_register() {
		if ( ! function_exists( 'woocommerce_store_api_register_endpoint_data' ) ) {
			return;
		}
		woocommerce_store_api_register_endpoint_data(
			array(
				'endpoint'        => 'product',
				'namespace'       => 'lafka',
				'data_callback'   => 'lafka_store_api_product_data',
				'schema_callback' => 'lafka_store_api_product_schema',
				'schema_type'     => ARRAY_A,
			)
		);
	}
}
