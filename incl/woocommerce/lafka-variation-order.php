<?php
/**
 * Variation options in a sensible order.
 *
 * WC_Product_Variable::get_variation_attributes() returns a taxonomy
 * attribute's values in database row order — the order the variations were
 * created — so templates that iterate it directly (the PDP size chips) showed
 * "Medium / Large / Small / X-Large". WooCommerce's own dropdown re-sorts by
 * the attribute's "Default sort order", but an attribute left on custom
 * ordering with no order ever dragged in falls back to arbitrary order there.
 *
 * lafka_sort_variation_options() is the single rule:
 *   1. An explicit order wins: the attribute sorts by name / name (numeric) /
 *      term id, or its terms carry a custom (drag-and-drop) order.
 *   2. Otherwise options go by their lowest variation price, cheapest first
 *      (sizes read Small → X-Large). Equal prices keep WooCommerce's order.
 * Applied to WooCommerce's variation dropdowns + Lafka swatches (through
 * woocommerce_get_product_terms / the dropdown args) and used by theme
 * templates that render their own choices (PDP chips, menu variation rows).
 *
 * Operator surface: Customizer → Lafka — PDP Redesign → "Order size options by
 * price" (`lafka_sort_variation_options`, default yes); filter
 * `lafka_sort_variation_options_by_price` (bool, $attribute, $product).
 *
 * @package Lafka\Plugin\WooCommerce
 * @since   10.2.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'lafka_variation_price_sort_enabled' ) ) {
	/**
	 * Whether options without an explicit order are sorted by price.
	 *
	 * @param mixed  $product   Variable product.
	 * @param string $attribute Attribute name ('' for whole-variation lists).
	 * @return bool
	 */
	function lafka_variation_price_sort_enabled( $product, string $attribute ): bool {
		$on = function_exists( 'get_theme_mod' ) ? 'no' !== get_theme_mod( 'lafka_sort_variation_options', 'yes' ) : true;

		/**
		 * Filter whether variation options without an explicit order are
		 * listed cheapest first.
		 *
		 * @param bool       $on        Customizer value (default true).
		 * @param string     $attribute Attribute name ('' for variation rows).
		 * @param WC_Product $product   Variable product.
		 */
		return (bool) apply_filters( 'lafka_sort_variation_options_by_price', $on, $attribute, $product );
	}
}

if ( ! function_exists( 'lafka_attribute_has_explicit_order' ) ) {
	/**
	 * Whether a taxonomy attribute's terms are explicitly ordered for these
	 * options: sorted by a rule (name, numeric name, id), or given a custom
	 * term order that is not the same for all of them.
	 *
	 * @param string   $taxonomy Attribute taxonomy (pa_*).
	 * @param string[] $slugs    Option slugs.
	 * @return bool
	 */
	function lafka_attribute_has_explicit_order( string $taxonomy, array $slugs ): bool {
		if ( ! taxonomy_exists( $taxonomy ) ) {
			return false;
		}
		$orderby = function_exists( 'wc_attribute_orderby' ) ? (string) wc_attribute_orderby( $taxonomy ) : 'menu_order';
		if ( 'menu_order' !== $orderby ) {
			return true;
		}
		$terms = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'slug'       => array_values( $slugs ),
				'hide_empty' => false,
			)
		);
		if ( ! is_array( $terms ) ) {
			return false;
		}
		$orders = array();
		foreach ( $terms as $term ) {
			$orders[] = (int) get_term_meta( $term->term_id, 'order', true );
		}

		return count( array_unique( $orders ) ) > 1;
	}
}

if ( ! function_exists( 'lafka_variation_option_min_prices' ) ) {
	/**
	 * The lowest variation price for each value of an attribute. Variations
	 * that accept "any" value are ignored.
	 *
	 * @param mixed  $product   Variable product.
	 * @param string $attribute Attribute name (taxonomy or custom label).
	 * @return array<string, float> option => lowest price.
	 */
	function lafka_variation_option_min_prices( $product, string $attribute ): array {
		if ( ! is_object( $product ) || ! method_exists( $product, 'get_variation_prices' ) ) {
			return array();
		}
		$prices = (array) ( $product->get_variation_prices( true )['price'] ?? array() );
		$key    = 'attribute_' . sanitize_title( $attribute );
		$min    = array();
		foreach ( $prices as $variation_id => $price ) {
			$attributes = (array) wc_get_product_variation_attributes( (int) $variation_id );
			$value      = (string) ( $attributes[ $key ] ?? '' );
			if ( '' === $value || '' === (string) $price ) {
				continue;
			}
			$price         = (float) $price;
			$min[ $value ] = isset( $min[ $value ] ) ? min( $min[ $value ], $price ) : $price;
		}

		return $min;
	}
}

if ( ! function_exists( 'lafka_sort_variation_options' ) ) {
	/**
	 * Order one attribute's options (see the file docblock for the rule).
	 *
	 * @param mixed    $product   Variable product.
	 * @param string   $attribute Attribute name as get_variation_attributes() keys it.
	 * @param string[] $options   Option values (slugs for taxonomy attributes).
	 * @return string[]
	 */
	function lafka_sort_variation_options( $product, string $attribute, array $options ): array {
		$options = array_values( $options );
		if ( count( $options ) < 2 || ! is_object( $product ) || ! method_exists( $product, 'is_type' ) || ! $product->is_type( 'variable' ) ) {
			return $options;
		}

		if ( lafka_attribute_has_explicit_order( $attribute, $options ) ) {
			$ordered = array_values( array_intersect( (array) wc_get_product_terms( $product->get_id(), $attribute, array( 'fields' => 'slugs' ) ), $options ) );

			return array_values( array_unique( array_merge( $ordered, $options ) ) );
		}

		if ( ! lafka_variation_price_sort_enabled( $product, $attribute ) ) {
			return $options;
		}

		// Match custom-attribute values the way variations store them.
		$min_prices = lafka_variation_option_min_prices( $product, $attribute );
		$lookup     = static function ( string $option ) use ( $min_prices ) {
			if ( isset( $min_prices[ $option ] ) ) {
				return $min_prices[ $option ];
			}
			$slug = sanitize_title( $option );
			return $min_prices[ $slug ] ?? null;
		};

		$positions = array_flip( $options );
		usort(
			$options,
			static function ( $a, $b ) use ( $lookup, $positions ) {
				$pa = $lookup( (string) $a );
				$pb = $lookup( (string) $b );
				if ( null !== $pa && null !== $pb && $pa !== $pb ) {
					return $pa <=> $pb;
				}
				if ( null === $pa xor null === $pb ) {
					return null === $pa ? 1 : -1;
				}
				return $positions[ $a ] <=> $positions[ $b ];
			}
		);

		return $options;
	}
}

if ( ! function_exists( 'lafka_filter_product_terms_order' ) ) {
	/**
	 * woocommerce_get_product_terms: order a variable product's attribute
	 * terms like lafka_sort_variation_options() (WooCommerce's variation
	 * dropdown and Lafka swatches read terms through here).
	 *
	 * @param mixed  $terms      Terms (WP_Term objects or slugs).
	 * @param int    $product_id Product id.
	 * @param string $taxonomy   Taxonomy.
	 * @param array  $args       Query args.
	 * @return mixed
	 */
	function lafka_filter_product_terms_order( $terms, $product_id, $taxonomy, $args = array() ) {
		if ( ! is_array( $terms ) || count( $terms ) < 2 || 0 !== strpos( (string) $taxonomy, 'pa_' ) ) {
			return $terms;
		}
		$fields = is_array( $args ) ? (string) ( $args['fields'] ?? 'all' ) : 'all';
		if ( ! in_array( $fields, array( 'all', 'slugs' ), true ) ) {
			return $terms;
		}
		$product = wc_get_product( $product_id );
		if ( ! is_object( $product ) || ! $product->is_type( 'variable' ) ) {
			return $terms;
		}

		$by_slug = array();
		foreach ( $terms as $term ) {
			$slug = is_object( $term ) ? (string) ( $term->slug ?? '' ) : (string) $term;
			if ( '' === $slug ) {
				return $terms;
			}
			$by_slug[ $slug ] = $term;
		}
		$slugs = array_keys( $by_slug );
		// An explicit order is WooCommerce's own: leave it (and never recurse).
		if ( lafka_attribute_has_explicit_order( (string) $taxonomy, $slugs ) || ! lafka_variation_price_sort_enabled( $product, (string) $taxonomy ) ) {
			return $terms;
		}

		$sorted = array();
		foreach ( lafka_sort_variation_options( $product, (string) $taxonomy, $slugs ) as $slug ) {
			$sorted[] = $by_slug[ $slug ];
		}

		return $sorted;
	}
}

if ( ! function_exists( 'lafka_sort_dropdown_variation_options' ) ) {
	/**
	 * woocommerce_dropdown_variation_attribute_options_args: order custom
	 * (non-taxonomy) options, which the dropdown prints in $args['options']
	 * order.
	 *
	 * @param mixed $args Dropdown args.
	 * @return mixed
	 */
	function lafka_sort_dropdown_variation_options( $args ) {
		if ( ! is_array( $args ) || empty( $args['options'] ) || ! is_array( $args['options'] ) || empty( $args['product'] ) ) {
			return $args;
		}
		$args['options'] = lafka_sort_variation_options( $args['product'], (string) ( $args['attribute'] ?? '' ), $args['options'] );

		return $args;
	}
}

if ( ! function_exists( 'lafka_sort_variation_rows' ) ) {
	/**
	 * Order whole-variation rows (e.g. get_available_variations() output the
	 * menu lists one row per size): cheapest first, unless the operator
	 * ordered the variations (differing menu_order).
	 *
	 * @param mixed $product Variable product.
	 * @param array $rows    Rows with variation_id + display_price.
	 * @return array
	 */
	function lafka_sort_variation_rows( $product, array $rows ): array {
		if ( count( $rows ) < 2 || ! lafka_variation_price_sort_enabled( $product, '' ) ) {
			return $rows;
		}
		$orders = array();
		foreach ( $rows as $row ) {
			$orders[] = (int) get_post_field( 'menu_order', (int) ( $row['variation_id'] ?? 0 ) );
		}
		if ( count( array_unique( $orders ) ) > 1 ) {
			return $rows;
		}

		$indexed = array_values( $rows );
		$keys    = array_keys( $indexed );
		usort(
			$keys,
			static function ( $a, $b ) use ( $indexed ) {
				$pa = (float) ( $indexed[ $a ]['display_price'] ?? 0 );
				$pb = (float) ( $indexed[ $b ]['display_price'] ?? 0 );
				return $pa === $pb ? $a <=> $b : $pa <=> $pb;
			}
		);

		return array_map( static fn( $key ) => $indexed[ $key ], $keys );
	}
}

if ( ! function_exists( 'lafka_variation_order_init' ) ) {
	/**
	 * Hook WooCommerce's variation dropdown + attribute terms.
	 *
	 * @return void
	 */
	function lafka_variation_order_init() {
		add_filter( 'woocommerce_get_product_terms', 'lafka_filter_product_terms_order', 20, 4 );
		add_filter( 'woocommerce_dropdown_variation_attribute_options_args', 'lafka_sort_dropdown_variation_options', 20 );
	}
}
