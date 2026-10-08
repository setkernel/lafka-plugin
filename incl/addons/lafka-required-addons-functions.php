<?php
/**
 * Required add-ons helper function.
 *
 * @package Lafka\Plugin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'lafka_product_has_required_addons' ) ) {
	/**
	 * Whether a product has a required add-on group.
	 *
	 * @param int $product_id Product id.
	 * @return bool
	 */
	function lafka_product_has_required_addons( int $product_id ): bool {
		return Lafka_Required_Addons::has_required( $product_id );
	}
}
