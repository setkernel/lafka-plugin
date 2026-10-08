<?php
/**
 * The deals category: the one answer to "which product category holds the
 * deals?". Used by the menu page (the deals section), the cart-drawer upsell
 * (never suggest a deal as "a little extra") and anything else that needs it.
 *
 * The operator picks it under WooCommerce → Settings → Restaurant → Promotions
 * (option `lafka_deals_category`); with no pick, a category whose slug is
 * "deals", "combos" or "specials" is used.
 *
 * @package Lafka\Plugin\Deals
 * @since   10.4.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'lafka_get_deals_category_id' ) ) {
	/**
	 * Term id of the deals product category, 0 when there is none. Cached for
	 * the request.
	 *
	 * @since 10.4.0
	 * @return int
	 */
	function lafka_get_deals_category_id(): int {
		static $cache = array();
		$picked       = absint( get_option( 'lafka_deals_category', 0 ) );
		if ( isset( $cache[ $picked ] ) ) {
			return $cache[ $picked ];
		}

		$id = 0;
		if ( $picked > 0 && term_exists( $picked, 'product_cat' ) ) {
			$id = $picked;
		} else {
			/**
			 * Slugs of the category treated as the deals category when the
			 * operator has not picked one.
			 *
			 * @since 10.4.0
			 * @param string[] $slugs Category slugs, first match wins.
			 */
			$slugs = array_map( 'strval', (array) apply_filters( 'lafka_deals_category_slugs', array( 'deals', 'combos', 'specials' ) ) );
			foreach ( $slugs as $slug ) {
				$term = get_term_by( 'slug', $slug, 'product_cat' );
				if ( $term instanceof WP_Term ) {
					$id = (int) $term->term_id;
					break;
				}
			}
		}

		/**
		 * Filter the deals category term id.
		 *
		 * @since 10.4.0
		 * @param int $id Term id (0 = none).
		 */
		$id = (int) apply_filters( 'lafka_deals_category_id', $id );

		$cache[ $picked ] = $id;
		return $id;
	}
}
