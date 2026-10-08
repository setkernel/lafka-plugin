<?php
/**
 * Deals: "any 2 pizzas for $20" where the customer picks each item.
 *
 * A Deal is a WooCommerce product type (`lafka_deal`). Its price is the deal
 * price (the product's own regular/sale price, so WooCommerce scheduling and
 * reports apply), and its slots say what the customer chooses:
 *
 *   slot = {
 *     label:      "Pizza 1",
 *     categories: [product_cat ids]   pool by category,
 *     products:   [product ids]       or by hand (added to the pool),
 *     exclude:    [product ids],
 *     attributes: { pa_size: medium } locked variation attributes,
 *     required:   true,
 *     upcharge:   true                premium items pay the difference over
 *                                     the slot's cheapest item
 *   }
 *
 * The customer builds the deal on its product page (Lafka_Deals_Builder);
 * each chosen item becomes a normal cart line carrying `lafka_deal` data, so
 * stock, tax, add-ons, kitchen tickets and refunds keep working per item.
 * Lafka_Deals_Cart splits the deal price across the lines and keeps the
 * group whole.
 *
 * @package Lafka\Plugin\Deals
 * @since   10.4.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Lafka_Deals' ) ) {

	/**
	 * Deal product type, slot data and item pools.
	 */
	final class Lafka_Deals {

		/** Product type slug. */
		const TYPE = 'lafka_deal';

		/** Product meta holding the slots. */
		const SLOTS_META = '_lafka_deal_slots';

		/** Cart item / order item key for deal membership. */
		const CART_KEY = 'lafka_deal';

		/** Most items a slot pool lists. */
		const POOL_LIMIT = 100;

		/**
		 * Hook the product type in.
		 *
		 * @return void
		 */
		public static function init(): void {
			add_filter( 'product_type_selector', array( __CLASS__, 'add_type' ) );
			add_filter( 'woocommerce_product_class', array( __CLASS__, 'product_class' ), 10, 2 );
			add_filter( 'woocommerce_data_stores', array( __CLASS__, 'data_store' ) );
		}

		/**
		 * Whether the Deals module is on (default on; Lafka → Modules).
		 *
		 * @return bool
		 */
		public static function enabled(): bool {
			return ! class_exists( 'Lafka_Options' ) || Lafka_Options::is_enabled( 'deals' );
		}

		/**
		 * Add "Deal" to the product type select.
		 *
		 * @param array<string,string> $types Types.
		 * @return array<string,string>
		 */
		public static function add_type( $types ): array {
			$types               = (array) $types;
			$types[ self::TYPE ] = __( 'Deal (customer picks the items)', 'lafka-plugin' );
			return $types;
		}

		/**
		 * Map the type to its class.
		 *
		 * @param string $classname    Class WooCommerce resolved.
		 * @param string $product_type Product type.
		 * @return string
		 */
		public static function product_class( $classname, $product_type ) {
			return self::TYPE === $product_type ? 'WC_Product_Lafka_Deal' : $classname;
		}

		/**
		 * Deals store like simple products.
		 *
		 * @param array<string,string> $stores Data stores.
		 * @return array<string,string>
		 */
		public static function data_store( $stores ): array {
			$stores                            = (array) $stores;
			$stores[ 'product-' . self::TYPE ] = 'WC_Product_Data_Store_CPT';
			return $stores;
		}

		/**
		 * Whether a product is a deal.
		 *
		 * @param mixed $product Product or id.
		 * @return bool
		 */
		public static function is_deal( $product ): bool {
			$product = is_object( $product ) ? $product : wc_get_product( (int) $product );
			return $product instanceof WC_Product && $product->is_type( self::TYPE );
		}

		/**
		 * Whether a cart item is part of a deal. Other promotions skip these
		 * lines: a deal is already its own price.
		 *
		 * @param array<string,mixed> $item Cart item.
		 * @return bool
		 */
		public static function is_deal_line( array $item ): bool {
			return ! empty( $item[ self::CART_KEY ]['group'] );
		}

		/**
		 * A deal's slots, normalised.
		 *
		 * @param int|WC_Product $deal Deal.
		 * @return array<int, array<string,mixed>>
		 */
		public static function get_slots( $deal ): array {
			$id  = is_object( $deal ) ? (int) $deal->get_id() : (int) $deal;
			$raw = get_post_meta( $id, self::SLOTS_META, true );
			return self::normalize_slots( is_array( $raw ) ? $raw : array() );
		}

		/**
		 * Normalise slot rows from storage or the admin form.
		 *
		 * @param array<int|string, mixed> $rows Rows.
		 * @return array<int, array<string,mixed>>
		 */
		public static function normalize_slots( array $rows ): array {
			$slots = array();
			foreach ( $rows as $row ) {
				if ( ! is_array( $row ) ) {
					continue;
				}
				$ids        = static function ( $values ): array {
					return array_values( array_unique( array_filter( array_map( 'absint', (array) $values ) ) ) );
				};
				$attributes = array();
				foreach ( (array) ( $row['attributes'] ?? array() ) as $taxonomy => $term ) {
					$taxonomy = wc_sanitize_taxonomy_name( (string) $taxonomy );
					$term     = sanitize_title( (string) $term );
					if ( '' !== $taxonomy && '' !== $term ) {
						$attributes[ $taxonomy ] = $term;
					}
				}
				$slot = array(
					'label'      => sanitize_text_field( (string) ( $row['label'] ?? '' ) ),
					'categories' => $ids( $row['categories'] ?? array() ),
					'products'   => $ids( $row['products'] ?? array() ),
					'exclude'    => $ids( $row['exclude'] ?? array() ),
					'attributes' => $attributes,
					'required'   => ! isset( $row['required'] ) || (bool) $row['required'],
					'upcharge'   => ! empty( $row['upcharge'] ),
				);
				if ( array() === $slot['categories'] && array() === $slot['products'] ) {
					continue;
				}
				if ( '' === $slot['label'] ) {
					/* translators: %d: slot number. */
					$slot['label'] = sprintf( __( 'Item %d', 'lafka-plugin' ), count( $slots ) + 1 );
				}
				$slots[] = $slot;
			}
			return $slots;
		}

		/**
		 * The items a slot offers: purchasable, visible products from its
		 * categories and hand-picked list, never another deal, each resolved
		 * to the variation that matches the locked attributes when the
		 * product is variable.
		 *
		 * @param array<string,mixed> $slot Slot.
		 * @return array<int, array{product: WC_Product, base: float}> Keyed by product id.
		 */
		public static function pool( array $slot ): array {
			$ids = array_map( 'absint', (array) $slot['products'] );
			if ( array() !== $slot['categories'] ) {
				$terms = get_terms(
					array(
						'taxonomy'   => 'product_cat',
						'include'    => $slot['categories'],
						'hide_empty' => false,
						'fields'     => 'slugs',
					)
				);
				if ( is_array( $terms ) && array() !== $terms ) {
					$ids = array_merge(
						$ids,
						array_map(
							'absint',
							wc_get_products(
								array(
									'status'   => 'publish',
									'limit'    => self::POOL_LIMIT,
									'category' => $terms,
									'return'   => 'ids',
									'orderby'  => 'menu_order',
									'order'    => 'ASC',
								)
							)
						)
					);
				}
			}

			$pool = array();
			foreach ( array_unique( $ids ) as $id ) {
				if ( in_array( $id, (array) $slot['exclude'], true ) ) {
					continue;
				}
				$product = wc_get_product( $id );
				if ( ! $product || self::is_deal( $product ) || 'publish' !== $product->get_status() || ! $product->is_purchasable() || ! $product->is_in_stock() ) {
					continue;
				}
				$base = self::base_price( $product, (array) $slot['attributes'] );
				if ( null === $base ) {
					continue;
				}
				$pool[ $id ] = array(
					'product' => $product,
					'base'    => $base,
				);
			}
			return $pool;
		}

		/**
		 * The price an item counts at in a deal: a simple product's price, or
		 * the cheapest in-stock variation matching the locked attributes.
		 * Null when no variation matches (the item is not offered).
		 *
		 * @param WC_Product           $product    Product.
		 * @param array<string,string> $attributes Locked attributes (taxonomy => term slug).
		 * @return float|null
		 */
		public static function base_price( WC_Product $product, array $attributes ): ?float {
			if ( ! $product->is_type( 'variable' ) ) {
				return (float) $product->get_price();
			}
			$best = null;
			foreach ( $product->get_available_variations( 'objects' ) as $variation ) {
				if ( ! $variation instanceof WC_Product_Variation || ! $variation->is_purchasable() || ! $variation->is_in_stock() ) {
					continue;
				}
				if ( ! self::variation_matches( $variation, $attributes ) ) {
					continue;
				}
				$price = (float) $variation->get_price();
				$best  = null === $best ? $price : min( $best, $price );
			}
			return $best;
		}

		/**
		 * Whether a variation satisfies locked attributes ("any" counts).
		 *
		 * @param WC_Product_Variation $variation  Variation.
		 * @param array<string,string> $attributes Locked attributes.
		 * @return bool
		 */
		public static function variation_matches( WC_Product_Variation $variation, array $attributes ): bool {
			// Taxonomy attributes store slugs ("medium"); product-level ones
			// store the text ("Medium"): compare both as slugs.
			$own = array();
			foreach ( $variation->get_attributes() as $key => $value ) {
				$own[ sanitize_title( $key ) ] = sanitize_title( (string) $value );
			}
			foreach ( $attributes as $name => $term ) {
				$value = $own[ sanitize_title( $name ) ] ?? null;
				if ( null === $value ) {
					// pa_size locks a product-level "size" attribute too.
					$value = $own[ sanitize_title( preg_replace( '/^pa_/', '', (string) $name ) ) ] ?? '';
				}
				if ( '' !== $value && sanitize_title( (string) $term ) !== $value ) {
					return false;
				}
			}
			return true;
		}

		/**
		 * The slot's reference price: its cheapest item. Upcharged slots
		 * charge each item's price above it.
		 *
		 * @param array<int, array{product: WC_Product, base: float}> $pool Pool.
		 * @return float
		 */
		public static function pool_floor( array $pool ): float {
			$bases = array_column( $pool, 'base' );
			return array() === $bases ? 0.0 : (float) min( $bases );
		}

		/**
		 * The most a customer can save: the dearest choice in every slot at
		 * à la carte prices, minus the deal price and the upcharges those
		 * choices would carry. Never negative; 0 when unknown.
		 *
		 * @param WC_Product $deal Deal.
		 * @return float
		 */
		public static function max_saving( WC_Product $deal ): float {
			$total = 0.0;
			$extra = 0.0;
			foreach ( self::get_slots( $deal ) as $slot ) {
				if ( ! $slot['required'] ) {
					continue; // Optional items cost their own price: no saving.
				}
				$pool = self::pool( $slot );
				if ( array() === $pool ) {
					return 0.0;
				}
				$top    = (float) max( array_column( $pool, 'base' ) );
				$total += $top;
				if ( $slot['upcharge'] ) {
					$extra += $top - self::pool_floor( $pool );
				}
			}
			return max( 0.0, round( $total - (float) $deal->get_price() - $extra, 2 ) );
		}
	}
}
