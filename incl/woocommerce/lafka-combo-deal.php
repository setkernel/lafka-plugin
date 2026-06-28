<?php
/**
 * Combo deal — standalone, always-loaded.
 *
 * "Any [category A] + any [category B] → save $X" (e.g. pizza + poutine). A
 * category-pair cart rule, so the operator never has to pre-build bundle SKUs:
 * any product from each category pairs automatically. Independently toggled like
 * the other promos; activates only when both categories + an amount are set.
 *
 * Settings (no hardcoded categories — public OSS): options/theme_mods/filters
 *   lafka_combo_deal_cat_a / _cat_b  category term IDs (or slugs)
 *   lafka_combo_deal_amount          number
 *   lafka_combo_deal_type            'fixed' | 'percent'   (default fixed)
 *
 * @package Lafka\Plugin\WooCommerce
 * @since   9.33.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'lafka_combo_deal_term_id' ) ) {
	/**
	 * Resolve a category setting (term ID or slug) to a product_cat term ID.
	 *
	 * @param mixed $value
	 * @return int 0 if unresolved.
	 */
	function lafka_combo_deal_term_id( $value ): int {
		if ( is_numeric( $value ) ) {
			return (int) $value;
		}
		if ( is_string( $value ) && '' !== $value && function_exists( 'get_term_by' ) ) {
			$term = get_term_by( 'slug', $value, 'product_cat' );
			if ( $term && ! is_wp_error( $term ) ) {
				return (int) $term->term_id;
			}
		}
		return 0;
	}
}

if ( ! function_exists( 'lafka_combo_deal_config' ) ) {
	/**
	 * @return array{enabled:bool,cat_a:int,cat_b:int,amount:float,type:string}
	 */
	function lafka_combo_deal_config(): array {
		$get = static function ( $key, $default = '' ) {
			$v = function_exists( 'get_option' ) ? get_option( $key, '' ) : '';
			if ( ( '' === $v || false === $v ) && function_exists( 'get_theme_mod' ) ) {
				$v = get_theme_mod( $key, $default );
			}
			return $v;
		};
		$cat_a  = lafka_combo_deal_term_id( $get( 'lafka_combo_deal_cat_a' ) );
		$cat_b  = lafka_combo_deal_term_id( $get( 'lafka_combo_deal_cat_b' ) );
		$amount = max( 0.0, (float) $get( 'lafka_combo_deal_amount', 0 ) );
		$type   = 'percent' === $get( 'lafka_combo_deal_type', 'fixed' ) ? 'percent' : 'fixed';

		$config = array(
			'cat_a'   => $cat_a,
			'cat_b'   => $cat_b,
			'amount'  => $amount,
			'type'    => $type,
			'enabled' => ( $cat_a > 0 && $cat_b > 0 && $amount > 0 ),
		);
		return (array) apply_filters( 'lafka_combo_deal_config', $config );
	}
}

if ( ! function_exists( 'lafka_combo_cart_has_pair' ) ) {
	/**
	 * Pure: does the cart contain at least one item in cat A AND one in cat B,
	 * satisfied by two DIFFERENT line items (so a single product that happens to
	 * sit in both categories does not self-qualify)?
	 *
	 * @param array $items_categories List of category-id arrays, one per line item.
	 * @param int   $cat_a
	 * @param int   $cat_b
	 * @return bool
	 */
	function lafka_combo_cart_has_pair( array $items_categories, int $cat_a, int $cat_b ): bool {
		if ( $cat_a <= 0 || $cat_b <= 0 ) {
			return false;
		}
		foreach ( $items_categories as $i => $cats_a ) {
			$cats_a = array_map( 'intval', (array) $cats_a );
			if ( ! in_array( $cat_a, $cats_a, true ) ) {
				continue;
			}
			foreach ( $items_categories as $j => $cats_b ) {
				if ( $i === $j ) {
					continue; // must be a different line item
				}
				if ( in_array( $cat_b, array_map( 'intval', (array) $cats_b ), true ) ) {
					return true;
				}
			}
		}
		return false;
	}
}

if ( ! function_exists( 'lafka_combo_find_pair' ) ) {
	/**
	 * Pure: locate the single qualifying combo pair and return its keys + the
	 * pair's single-unit subtotal. The pair is the cheapest line item in cat A
	 * plus the cheapest in cat B, the two being DIFFERENT line items (so a
	 * product that sits in both categories cannot self-qualify). Choosing the
	 * cheapest of each, valued at one unit apiece, is the conservative base the
	 * combo discount applies to — never the whole cart.
	 *
	 * @param array $items List of line items keyed by cart_item_key (or index),
	 *                     each array{cats:int[],price:float}; `price` is the
	 *                     single-unit price of that line item.
	 * @param int   $cat_a
	 * @param int   $cat_b
	 * @return array Empty array when no pair exists, else
	 *               array{a:int|string,b:int|string,subtotal:float}.
	 */
	function lafka_combo_find_pair( array $items, int $cat_a, int $cat_b ): array {
		if ( $cat_a <= 0 || $cat_b <= 0 ) {
			return array();
		}
		$best = null;
		foreach ( $items as $i => $item_a ) {
			$cats_a = array_map( 'intval', (array) ( $item_a['cats'] ?? array() ) );
			if ( ! in_array( $cat_a, $cats_a, true ) ) {
				continue;
			}
			$price_a = max( 0.0, (float) ( $item_a['price'] ?? 0 ) );
			foreach ( $items as $j => $item_b ) {
				if ( $i === $j ) {
					continue; // must be a different line item
				}
				$cats_b = array_map( 'intval', (array) ( $item_b['cats'] ?? array() ) );
				if ( ! in_array( $cat_b, $cats_b, true ) ) {
					continue;
				}
				$sum = $price_a + max( 0.0, (float) ( $item_b['price'] ?? 0 ) );
				if ( null === $best || $sum < $best['sum'] ) {
					$best = array(
						'a'   => $i,
						'b'   => $j,
						'sum' => $sum,
					);
				}
			}
		}
		if ( null === $best ) {
			return array();
		}
		return array(
			'a'        => $best['a'],
			'b'        => $best['b'],
			'subtotal' => round( $best['sum'], 2 ),
		);
	}
}

if ( ! function_exists( 'lafka_combo_deal_amount_for' ) ) {
	/**
	 * Pure: discount value given config + the qualifying-PAIR subtotal (the
	 * cheapest item in each category, one unit apiece — never the whole cart).
	 * Percent returns subtotal × amount/100; fixed returns amount capped at the
	 * pair subtotal.
	 *
	 * @param array $config
	 * @param float $subtotal Qualifying-pair subtotal the discount is based on.
	 * @return float
	 */
	function lafka_combo_deal_amount_for( array $config, float $subtotal ): float {
		$amount = (float) ( $config['amount'] ?? 0 );
		if ( $amount <= 0 ) {
			return 0.0;
		}
		if ( 'percent' === ( $config['type'] ?? 'fixed' ) ) {
			return $subtotal > 0 ? round( $subtotal * ( min( 100.0, $amount ) / 100 ), 2 ) : 0.0;
		}
		return round( min( $amount, max( 0.0, $subtotal ) ), 2 ); // fixed, never exceeds subtotal
	}
}

if ( ! function_exists( 'lafka_combo_deal_cart_categories' ) ) {
	/**
	 * Impure: category IDs per cart line item (one array per item).
	 *
	 * @return array
	 */
	function lafka_combo_deal_cart_categories(): array {
		$out = array();
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return $out;
		}
		foreach ( WC()->cart->get_cart() as $item ) {
			$pid   = (int) ( $item['product_id'] ?? 0 );
			$out[] = $pid ? wc_get_product_term_ids( $pid, 'product_cat' ) : array();
		}
		return $out;
	}
}

if ( ! function_exists( 'lafka_combo_deal_cart_items' ) ) {
	/**
	 * Impure: per cart line item, its product-category IDs and single-unit price
	 * (line subtotal ÷ quantity), keyed by cart_item_key. Used to locate AND
	 * price the combo pair so the discount applies to the pair, not the cart.
	 *
	 * @return array<string,array{cats:int[],price:float}>
	 */
	function lafka_combo_deal_cart_items(): array {
		$out = array();
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return $out;
		}
		foreach ( WC()->cart->get_cart() as $key => $item ) {
			$pid           = (int) ( $item['product_id'] ?? 0 );
			$qty           = max( 1, (int) ( $item['quantity'] ?? 1 ) );
			$line_subtotal = isset( $item['line_subtotal'] ) ? (float) $item['line_subtotal'] : 0.0;
			$out[ $key ]   = array(
				'cats'  => $pid ? wc_get_product_term_ids( $pid, 'product_cat' ) : array(),
				'price' => round( max( 0.0, $line_subtotal ) / $qty, 2 ),
			);
		}
		return $out;
	}
}

if ( ! function_exists( 'lafka_combo_deal_component' ) ) {
	add_filter( 'lafka_order_discount_components', 'lafka_combo_deal_component', 10, 2 );
	/**
	 * Feed the combo deal into the shared order-discount coordinator
	 * (lafka_order_discount_apply) instead of adding its own cart fee.
	 *
	 * The combo is a category-PAIR deal, so it discounts only the qualifying
	 * pair (the cheapest item in each category, one unit apiece) — NOT the whole
	 * cart. Both amount types are therefore resolved here to a concrete dollar
	 * figure against the pair's subtotal: a percent combo is percent × the pair
	 * subtotal, a fixed combo is capped at that same pair subtotal. That dollar
	 * amount is contributed as a 'fixed' component, because the coordinator has
	 * no per-component base and would apply a 'percent' component against the
	 * whole-cart subtotal — the over-discount bug this avoids (a 20% combo would
	 * otherwise take 20% off every unrelated item once one pair existed). The
	 * amount still joins the coordinator's fixed pool and is bounded by the
	 * single combined cap, so it can never drive the order to a free/negative total.
	 *
	 * @param array         $components Discount components collected so far.
	 * @param \WC_Cart|null $cart      Current cart (unused; pair test reads WC()->cart).
	 * @return array
	 */
	function lafka_combo_deal_component( $components, $cart = null ) {
		if ( ! is_array( $components ) ) {
			$components = array();
		}
		$config = lafka_combo_deal_config();
		if ( empty( $config['enabled'] ) ) {
			return $components;
		}
		$pair = lafka_combo_find_pair( lafka_combo_deal_cart_items(), (int) $config['cat_a'], (int) $config['cat_b'] );
		if ( array() === $pair ) {
			return $components;
		}
		// Discount only the qualifying pair: percent × pair subtotal, or a fixed
		// amount capped at the pair subtotal (see lafka_combo_deal_amount_for).
		$discount = lafka_combo_deal_amount_for( $config, (float) ( $pair['subtotal'] ?? 0 ) );
		if ( $discount > 0 ) {
			$components[] = array(
				'source' => 'combo_deal',
				'type'   => 'fixed',
				'value'  => $discount,
				'label'  => __( 'Combo deal', 'lafka-plugin' ),
			);
		}
		return $components;
	}
}
