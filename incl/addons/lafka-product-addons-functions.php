<?php
/**
 * Product add-on price helper functions.
 *
 * @package Lafka\Plugin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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
