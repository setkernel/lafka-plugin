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

		/** Product meta: weekdays the deal runs (0 = Sunday … 6; empty = every day). */
		const DAYS_META = '_lafka_deal_days';

		/** Product meta: first and last day the deal runs (Y-m-d, optional). */
		const FROM_META  = '_lafka_deal_from';
		const UNTIL_META = '_lafka_deal_until';

		/** Product meta: how the deal is priced ('fixed', 'percent', 'amount' or 'cheapest') and its value. */
		const MODE_META  = '_lafka_deal_mode';
		const VALUE_META = '_lafka_deal_value';

		/** The pricing modes. */
		const MODES = array( 'fixed', 'percent', 'amount', 'cheapest' );

		/**
		 * Per-request cache of unavailable_reason().
		 *
		 * @var array<string,string>
		 */
		private static $reasons = array();

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
			$tops  = array();
			$extra = 0.0;
			foreach ( self::get_slots( $deal ) as $slot ) {
				if ( ! $slot['required'] ) {
					continue; // Optional items cost their own price: no saving.
				}
				$pool = self::pool( $slot );
				if ( array() === $pool ) {
					return 0.0;
				}
				$top = (float) max( array_column( $pool, 'base' ) );
				if ( $slot['upcharge'] && self::upcharges_apply( $deal ) ) {
					$extra += $top - self::pool_floor( $pool );
					$top    = self::pool_floor( $pool );
				}
				$tops[] = $top;
			}
			$total = (float) array_sum( $tops ) + $extra;
			return max( 0.0, round( $total - self::price_for( $deal, $tops ) - $extra, 2 ) );
		}

		/**
		 * How the deal is priced.
		 *
		 * @since 10.4.0
		 *
		 * @param int|WC_Product $deal Deal.
		 * @return array{mode: string, value: float}
		 */
		public static function pricing( $deal ): array {
			$id    = is_object( $deal ) ? (int) $deal->get_id() : (int) $deal;
			$mode  = (string) get_post_meta( $id, self::MODE_META, true );
			$mode  = in_array( $mode, self::MODES, true ) ? $mode : 'fixed';
			$value = max( 0.0, (float) get_post_meta( $id, self::VALUE_META, true ) );
			return array(
				'mode'  => $mode,
				'value' => 'percent' === $mode ? min( 100.0, $value ) : $value,
			);
		}

		/**
		 * Whether the deal has its own price (the product's Regular / Sale
		 * price) rather than discounting the items the customer chooses.
		 *
		 * @since 10.4.0
		 *
		 * @param int|WC_Product $deal Deal.
		 * @return bool
		 */
		public static function is_fixed( $deal ): bool {
			return 'fixed' === self::pricing( $deal )['mode'];
		}

		/**
		 * Whether premium items pay a difference over the slot's cheapest
		 * item: only a fixed-price deal has a price for them to exceed. The
		 * discount modes charge each item its own price.
		 *
		 * @since 10.4.0
		 *
		 * @param int|WC_Product $deal Deal.
		 * @return bool
		 */
		public static function upcharges_apply( $deal ): bool {
			return self::is_fixed( $deal );
		}

		/**
		 * The price of the deal's required items.
		 *
		 * Fixed: the product's price, whatever the items. Percent / amount off:
		 * the items' à la carte total less the discount. Cheapest free: the
		 * total less the cheapest item. Optional items and add-ons are charged
		 * on top by the caller.
		 *
		 * @since 10.4.0
		 *
		 * @param WC_Product        $deal       Deal.
		 * @param array<int, float> $references À la carte price of each required item (a fixed deal's slot floors).
		 * @return float
		 */
		public static function price_for( WC_Product $deal, array $references ): float {
			$pricing    = self::pricing( $deal );
			$references = array_values( array_filter( array_map( 'floatval', $references ), static fn( $price ) => $price > 0 ) );
			$sum        = (float) array_sum( $references );
			switch ( $pricing['mode'] ) {
				case 'percent':
					$price = $sum * ( 100 - $pricing['value'] ) / 100;
					break;
				case 'amount':
					$price = max( 0.0, $sum - $pricing['value'] );
					break;
				case 'cheapest':
					$price = array() === $references ? 0.0 : $sum - min( $references );
					break;
				default:
					return (float) $deal->get_price( 'edit' );
			}
			return round( $price, 2 );
		}

		/**
		 * The least a discount deal can cost: its cheapest choice in every
		 * required slot. Shown as the deal's "from" price.
		 *
		 * @since 10.4.0
		 *
		 * @param WC_Product $deal Deal.
		 * @return float
		 */
		public static function from_price( WC_Product $deal ): float {
			static $cache = array();
			$id           = $deal->get_id();
			if ( ! isset( $cache[ $id ] ) ) {
				$floors = array();
				foreach ( self::get_slots( $deal ) as $slot ) {
					if ( $slot['required'] ) {
						$floors[] = self::pool_floor( self::pool( $slot ) );
					}
				}
				$cache[ $id ] = self::price_for( $deal, $floors );
			}
			return $cache[ $id ];
		}

		/**
		 * What the customer gets, in a few words: "for $22.00", "20% off",
		 * "$5.00 off", "cheapest free".
		 *
		 * @since 10.4.0
		 *
		 * @param WC_Product $deal Deal.
		 * @return string Plain text.
		 */
		public static function offer_text( WC_Product $deal ): string {
			$pricing = self::pricing( $deal );
			switch ( $pricing['mode'] ) {
				case 'percent':
					/* translators: %s: percentage, e.g. "20". */
					return sprintf( __( '%s%% off', 'lafka-plugin' ), (string) (float) $pricing['value'] );
				case 'amount':
					/* translators: %s: amount, e.g. "$5.00". */
					return sprintf( __( '%s off', 'lafka-plugin' ), lafka_price_plain( $pricing['value'] ) );
				case 'cheapest':
					return __( 'cheapest free', 'lafka-plugin' );
				default:
					/* translators: %s: deal price, e.g. "$22.00". */
					return sprintf( __( 'for %s', 'lafka-plugin' ), lafka_price_plain( (float) $deal->get_price( 'edit' ) ) );
			}
		}

		/**
		 * Why the deal cannot be ordered right now, or '' when it can: it is
		 * not running today, outside its hours, sold out, or the customer has
		 * used it as often as allowed. The one gate behind is_purchasable(), so
		 * the page, the builder, the AJAX endpoints and the cart re-check all
		 * say the same thing. The order type is separate (it can change with
		 * one tap): Lafka_Deals_Conditions::order_type_error().
		 *
		 * @since 10.4.0
		 *
		 * @param int|WC_Product $deal    Deal.
		 * @param bool           $in_cart Judging a deal already in the cart (a customer's own limit is not a reason to remove it).
		 * @return string Plain text.
		 */
		public static function unavailable_reason( $deal, bool $in_cart = false ): string {
			$id  = is_object( $deal ) ? (int) $deal->get_id() : (int) $deal;
			$key = $id . '|' . (int) $in_cart . '|' . ( class_exists( 'Lafka_Deals_Conditions' ) ? Lafka_Deals_Conditions::customer_key() : '' );
			if ( isset( self::$reasons[ $key ] ) ) {
				return self::$reasons[ $key ];
			}
			$reason = '';
			if ( ! self::is_available_today( $id ) ) {
				$when   = self::availability_text( $id );
				$reason = __( 'This deal is not available right now.', 'lafka-plugin' );
				if ( '' !== $when ) {
					/* translators: %s: when the deal runs, e.g. "every Tuesday · until October 31". */
					$reason .= ' ' . sprintf( __( 'It runs %s.', 'lafka-plugin' ), $when );
				}
			} elseif ( class_exists( 'Lafka_Deals_Conditions' ) ) {
				$reason = Lafka_Deals_Conditions::time_or_limit_reason( $id, $in_cart );
			}
			self::$reasons[ $key ] = $reason;
			return $reason;
		}

		/**
		 * Forget the per-request reasons (a test or a long-running job).
		 *
		 * @since 10.4.0
		 *
		 * @return void
		 */
		public static function flush(): void {
			self::$reasons = array();
		}

		/**
		 * When the deal runs.
		 *
		 * @param int|WC_Product $deal Deal.
		 * @return array{days: list<int>, from: string, until: string}
		 */
		public static function availability( $deal ): array {
			$id   = is_object( $deal ) ? (int) $deal->get_id() : (int) $deal;
			$days = get_post_meta( $id, self::DAYS_META, true );
			$days = array_values( array_unique( array_filter( array_map( 'intval', is_array( $days ) ? $days : array() ), static fn( $d ) => $d >= 0 && $d <= 6 ) ) );
			sort( $days );
			$date = static function ( $value ): string {
				$value = (string) $value;
				return preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ? $value : '';
			};
			return array(
				'days'  => $days,
				'from'  => $date( get_post_meta( $id, self::FROM_META, true ) ),
				'until' => $date( get_post_meta( $id, self::UNTIL_META, true ) ),
			);
		}

		/**
		 * Whether the deal runs today (site timezone).
		 *
		 * @param int|WC_Product $deal Deal.
		 * @return bool
		 */
		public static function is_available_today( $deal ): bool {
			$when  = self::availability( $deal );
			$today = wp_date( 'Y-m-d' );
			if ( ( '' !== $when['from'] && $today < $when['from'] ) || ( '' !== $when['until'] && $today > $when['until'] ) ) {
				return false;
			}
			return array() === $when['days'] || in_array( (int) wp_date( 'w' ), $when['days'], true );
		}

		/**
		 * "every Tuesday and Thursday · until October 31", or '' when the deal
		 * runs every day with no dates.
		 *
		 * @param int|WC_Product $deal Deal.
		 * @return string
		 */
		public static function availability_text( $deal ): string {
			global $wp_locale;
			$when  = self::availability( $deal );
			$parts = array();
			if ( array() !== $when['days'] && count( $when['days'] ) < 7 && $wp_locale instanceof WP_Locale ) {
				$names = array_map( static fn( $d ) => $wp_locale->get_weekday( $d ), $when['days'] );
				/* translators: %s: weekdays, e.g. "Tuesday and Thursday". */
				$parts[] = sprintf( __( 'every %s', 'lafka-plugin' ), wp_sprintf( '%l', $names ) );
			}
			$format = (string) get_option( 'date_format', 'F j' );
			if ( '' !== $when['from'] && wp_date( 'Y-m-d' ) < $when['from'] ) {
				/* translators: %s: date. */
				$parts[] = sprintf( __( 'from %s', 'lafka-plugin' ), wp_date( $format, strtotime( $when['from'] . ' 12:00' ) ) );
			}
			if ( '' !== $when['until'] ) {
				/* translators: %s: date. */
				$parts[] = sprintf( __( 'until %s', 'lafka-plugin' ), wp_date( $format, strtotime( $when['until'] . ' 12:00' ) ) );
			}
			return implode( ' · ', $parts );
		}
	}
}
