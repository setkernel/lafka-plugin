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

if ( ! function_exists( 'lafka_combo_deal_amount_for' ) ) {
	/**
	 * Pure: discount value given config + the qualifying subtotal.
	 *
	 * @param array $config
	 * @param float $subtotal
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

if ( ! function_exists( 'lafka_combo_deal_apply' ) ) {
	add_action( 'woocommerce_cart_calculate_fees', 'lafka_combo_deal_apply' );
	/**
	 * Apply the combo discount as a negative cart fee when the pair is present.
	 *
	 * @param \WC_Cart $cart
	 * @return void
	 */
	function lafka_combo_deal_apply( $cart ) {
		if ( is_admin() && ! ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
			return;
		}
		if ( ! is_object( $cart ) ) {
			return;
		}
		$config = lafka_combo_deal_config();
		if ( empty( $config['enabled'] ) ) {
			return;
		}
		if ( ! lafka_combo_cart_has_pair( lafka_combo_deal_cart_categories(), (int) $config['cat_a'], (int) $config['cat_b'] ) ) {
			return;
		}
		$amount = lafka_combo_deal_amount_for( $config, (float) $cart->get_subtotal() );
		if ( $amount > 0 ) {
			$cart->add_fee( __( 'Combo deal', 'lafka-plugin' ), -$amount, false );
		}
	}
}
