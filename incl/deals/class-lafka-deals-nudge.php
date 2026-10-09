<?php
/**
 * The deal nudge: "Add 1 more item to get Any 2 Medium Pizzas for $22.00".
 *
 * When the cart holds ordinary items that would fill slots of a deal that can
 * be ordered right now, the cart says so and links to the deal's builder with
 * those items already chosen. Adding the deal there replaces those cart lines
 * (Lafka_Deals_Builder::convert()), so the customer is never charged twice.
 *
 * Shown in the cart drawer (inside the upsell wrapper, so it refreshes with
 * the drawer's other fragments), on the classic cart, and on the block cart
 * (Store API cart extension `lafka_deals`, drawn by lafka-blocks-checkout.js).
 *
 * @package Lafka\Plugin\Deals
 * @since   10.4.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Lafka_Deals_Nudge' ) ) {

	/**
	 * Finds the deal a cart almost (or already) qualifies for, and shows it.
	 */
	final class Lafka_Deals_Nudge {

		/** Query argument on the deal link: the cart item keys to prefill from. */
		const QUERY_ARG = 'lafka_deal_cart';

		/** Store API cart extension namespace. */
		const NAMESPACE = 'lafka_deals';

		/** Most single units considered (a pathological cart stays cheap). */
		const MAX_UNITS = 24;

		/**
		 * Per-request pools, keyed deal id.
		 *
		 * @var array<int, array<int, array<int, array{product: WC_Product, base: float}>>>
		 */
		private static $pools = array();

		/**
		 * Hook in.
		 *
		 * @return void
		 */
		public static function init(): void {
			add_action( 'lafka_cart_drawer_upsell_start', array( __CLASS__, 'render_drawer' ) );
			add_action( 'woocommerce_before_cart', array( __CLASS__, 'render_cart' ), 20 );
			add_action( 'woocommerce_init', array( __CLASS__, 'register_store_api' ) );
		}

		/* ------------------------------------------------------------------ *
		 *  The cart's single items
		 * ------------------------------------------------------------------ */

		/**
		 * The cart's ordinary lines as single units (a line of 2 is 2 units).
		 *
		 * @param WC_Cart|null $cart       Cart; default the current one.
		 * @param string[]     $only_keys  Only these cart item keys (empty = all).
		 * @return array<int, array{key: string, product_id: int, variation_id: int, attributes: array<string,string>, price: float, name: string, addons: bool}>
		 */
		public static function units( ?WC_Cart $cart = null, array $only_keys = array() ): array {
			$cart  = $cart ?? ( function_exists( 'WC' ) ? WC()->cart : null );
			$units = array();
			if ( ! $cart instanceof WC_Cart ) {
				return $units;
			}
			foreach ( $cart->get_cart() as $key => $item ) {
				if ( Lafka_Deals::is_deal_line( $item ) || ( array() !== $only_keys && ! in_array( (string) $key, $only_keys, true ) ) ) {
					continue;
				}
				$product = wc_get_product( (int) $item['variation_id'] > 0 ? (int) $item['variation_id'] : (int) $item['product_id'] );
				if ( ! $product ) {
					continue;
				}
				$attributes = array();
				foreach ( (array) ( $item['variation'] ?? array() ) as $name => $value ) {
					$attributes[ sanitize_title( preg_replace( '/^attribute_/', '', (string) $name ) ) ] = sanitize_title( (string) $value );
				}
				for ( $i = 0; $i < (int) $item['quantity']; $i++ ) {
					if ( count( $units ) >= self::MAX_UNITS ) {
						break;
					}
					$units[] = array(
						'key'          => (string) $key,
						'product_id'   => (int) $item['product_id'],
						'variation_id' => (int) $item['variation_id'],
						'attributes'   => $attributes,
						'price'        => (float) $product->get_price(),
						'name'         => (string) $product->get_name(),
						'addons'       => array() !== (array) ( $item['addons'] ?? array() ),
					);
				}
			}
			return $units;
		}

		/**
		 * A deal's slot pools, once per request.
		 *
		 * @param WC_Product $deal Deal.
		 * @return array<int, array<int, array{product: WC_Product, base: float}>> Slot index => pool.
		 */
		private static function pools( WC_Product $deal ): array {
			$id = $deal->get_id();
			if ( ! isset( self::$pools[ $id ] ) ) {
				self::$pools[ $id ] = array();
				foreach ( Lafka_Deals::get_slots( $deal ) as $index => $slot ) {
					self::$pools[ $id ][ $index ] = Lafka_Deals::pool( $slot );
				}
			}
			return self::$pools[ $id ];
		}

		/**
		 * Whether a cart unit can fill a slot.
		 *
		 * @param array<string,mixed> $slot Slot.
		 * @param array<int, array{product: WC_Product, base: float}> $pool Slot pool.
		 * @param array<string,mixed> $unit Cart unit.
		 * @return bool
		 */
		private static function fits( array $slot, array $pool, array $unit ): bool {
			if ( ! isset( $pool[ $unit['product_id'] ] ) ) {
				return false;
			}
			if ( $pool[ $unit['product_id'] ]['product']->is_type( 'variable' ) ) {
				$variation = $unit['variation_id'] ? wc_get_product( $unit['variation_id'] ) : null;
				return $variation instanceof WC_Product_Variation && Lafka_Deals::variation_matches( $variation, (array) $slot['attributes'] );
			}
			return true;
		}

		/**
		 * Which unit fills which required slot, as many slots as can be filled.
		 *
		 * @param WC_Product                      $deal  Deal.
		 * @param array<int, array<string,mixed>> $units Cart units.
		 * @return array<int,int> Slot index => unit index.
		 */
		public static function assign( WC_Product $deal, array $units ): array {
			$pools = self::pools( $deal );
			$edges = array();
			foreach ( Lafka_Deals::get_slots( $deal ) as $index => $slot ) {
				if ( ! $slot['required'] ) {
					continue;
				}
				$edges[ $index ] = array();
				foreach ( $units as $u => $unit ) {
					if ( self::fits( $slot, $pools[ $index ] ?? array(), $unit ) ) {
						$edges[ $index ][] = $u;
					}
				}
			}
			$owner = array(); // Unit index => slot index (augmenting paths).
			$try   = static function ( int $slot, array &$seen ) use ( &$try, &$owner, $edges ): bool {
				foreach ( $edges[ $slot ] as $u ) {
					if ( isset( $seen[ $u ] ) ) {
						continue;
					}
					$seen[ $u ] = true;
					if ( ! isset( $owner[ $u ] ) || $try( $owner[ $u ], $seen ) ) {
						$owner[ $u ] = $slot;
						return true;
					}
				}
				return false;
			};
			foreach ( array_keys( $edges ) as $slot ) {
				$seen = array();
				$try( (int) $slot, $seen );
			}
			$assigned = array_flip( $owner );
			ksort( $assigned );
			return $assigned;
		}

		/* ------------------------------------------------------------------ *
		 *  The nudge
		 * ------------------------------------------------------------------ */

		/**
		 * What the current cart almost qualifies for, or null.
		 *
		 * @return array{deal_id: int, deal_name: string, message: string, cta: string, url: string, remaining: int}|null
		 */
		public static function find(): ?array {
			$units = self::units();
			if ( array() === $units ) {
				return null;
			}
			$best = null;
			foreach ( (array) wc_get_products(
				array(
					'type'   => Lafka_Deals::TYPE,
					'status' => 'publish',
					'limit'  => 20,
				)
			) as $deal ) {
				if ( ! $deal instanceof WC_Product || ! $deal->is_purchasable() || '' !== Lafka_Deals_Conditions::builder_error( $deal ) ) {
					continue;
				}
				$required = count( array_filter( array_column( Lafka_Deals::get_slots( $deal ), 'required' ) ) );
				$assigned = self::assign( $deal, $units );
				if ( $required < 1 || array() === $assigned ) {
					continue;
				}
				$remaining = $required - count( $assigned );
				$saving    = 0 === $remaining ? self::saving( $deal, $units, $assigned ) : 0.0;
				if ( 0 === $remaining && $saving <= 0.0 ) {
					continue; // Already cheaper à la carte: no reason to switch.
				}
				$rank = array( 0 === $remaining ? 1 : 0, count( $assigned ), -$remaining, $saving );
				if ( null === $best || $rank > $best['rank'] ) {
					$best = array(
						'rank'      => $rank,
						'deal'      => $deal,
						'assigned'  => $assigned,
						'remaining' => $remaining,
						'saving'    => $saving,
					);
				}
			}
			if ( null === $best ) {
				return null;
			}
			$deal = $best['deal'];
			$keys = array_values( array_unique( array_map( static fn( $u ) => $units[ $u ]['key'], $best['assigned'] ) ) );
			$text = $best['remaining'] > 0
				? sprintf(
					/* translators: 1: number of items to add, 2: deal name, 3: the offer with its separator, e.g. " for $22.00" or ", 20% off". */
					_n( 'Add %1$d more item to get %2$s%3$s.', 'Add %1$d more items to get %2$s%3$s.', $best['remaining'], 'lafka-plugin' ),
					$best['remaining'],
					$deal->get_name(),
					( Lafka_Deals::is_fixed( $deal ) ? ' ' : ', ' ) . Lafka_Deals::offer_text( $deal )
				)
				: sprintf(
					/* translators: 1: deal name, 2: amount saved. */
					__( 'What\'s in your order makes %1$s. Switch to the deal and save %2$s.', 'lafka-plugin' ),
					$deal->get_name(),
					lafka_price_plain( $best['saving'] )
				);
			$nudge = array(
				'deal_id'   => $deal->get_id(),
				'deal_name' => $deal->get_name(),
				'message'   => $text,
				'cta'       => $best['remaining'] > 0 ? __( 'Build the deal', 'lafka-plugin' ) : __( 'Make it a deal', 'lafka-plugin' ),
				'url'       => add_query_arg( self::QUERY_ARG, implode( ',', $keys ), $deal->get_permalink() ),
				'remaining' => $best['remaining'],
			);

			/**
			 * Filter the deal nudge shown in the cart (return null to show none).
			 *
			 * @since 10.4.0
			 * @param array<string,mixed> $nudge Deal id, name, message, button label, url, remaining items.
			 * @param WC_Product          $deal  The deal.
			 */
			$nudge = apply_filters( 'lafka_deal_nudge', $nudge, $deal );
			return is_array( $nudge ) ? $nudge : null;
		}

		/**
		 * What the customer saves by turning the matched items into the deal.
		 *
		 * @param WC_Product                      $deal     Deal.
		 * @param array<int, array<string,mixed>> $units    Cart units.
		 * @param array<int,int>                  $assigned Slot => unit.
		 * @return float
		 */
		private static function saving( WC_Product $deal, array $units, array $assigned ): float {
			$slots   = Lafka_Deals::get_slots( $deal );
			$pools   = self::pools( $deal );
			$current = 0.0;
			$extra   = 0.0;
			$refs    = array();
			foreach ( $assigned as $index => $u ) {
				$price    = $units[ $u ]['price'];
				$current += $price;
				$floor    = Lafka_Deals::pool_floor( $pools[ $index ] );
				if ( $slots[ $index ]['upcharge'] && Lafka_Deals::upcharges_apply( $deal ) ) {
					$extra += max( 0.0, $price - $floor );
					$refs[] = min( $price, $floor );
				} else {
					$refs[] = $price;
				}
			}
			return max( 0.0, round( $current - ( Lafka_Deals::price_for( $deal, $refs ) + $extra ), 2 ) );
		}

		/**
		 * The nudge as markup.
		 *
		 * @param array<string,mixed> $nudge A find() result.
		 * @return string
		 */
		public static function html( array $nudge ): string {
			return '<div class="lafka-deal-nudge lafka-card lafka-card--sm lafka-card--flat" role="status" data-lafka-deal-nudge="' . esc_attr( (string) $nudge['deal_id'] ) . '">'
				. '<p class="lafka-deal-nudge__text">' . esc_html( (string) $nudge['message'] ) . '</p>'
				. '<a class="lafka-deal-nudge__cta lafka-btn lafka-btn--primary lafka-btn--sm" href="' . esc_url( (string) $nudge['url'] ) . '">' . esc_html( (string) $nudge['cta'] ) . '</a>'
				. '</div>';
		}

		/**
		 * The cart drawer: first thing in the upsell wrapper, so it refreshes
		 * with the drawer's other fragments.
		 *
		 * @return void
		 */
		public static function render_drawer(): void {
			$nudge = self::find();
			if ( $nudge ) {
				echo wp_kses( self::html( $nudge ), self::allowed_html() );
			}
		}

		/**
		 * The classic cart page.
		 *
		 * @return void
		 */
		public static function render_cart(): void {
			self::render_drawer();
		}

		/**
		 * The few tags the nudge markup uses.
		 *
		 * @return array<string, array<string, bool>>
		 */
		private static function allowed_html(): array {
			return array(
				'div' => array(
					'class'                 => true,
					'role'                  => true,
					'data-lafka-deal-nudge' => true,
				),
				'p'   => array( 'class' => true ),
				'a'   => array(
					'class' => true,
					'href'  => true,
				),
			);
		}

		/* ------------------------------------------------------------------ *
		 *  Block cart
		 * ------------------------------------------------------------------ */

		/**
		 * Expose the nudge on the Store API cart: extensions.lafka_deals.nudge.
		 *
		 * @return void
		 */
		public static function register_store_api(): void {
			if ( ! function_exists( 'woocommerce_store_api_register_endpoint_data' ) ) {
				return;
			}
			woocommerce_store_api_register_endpoint_data(
				array(
					'endpoint'        => 'cart',
					'namespace'       => self::NAMESPACE,
					'data_callback'   => static function (): array {
						return array( 'nudge' => self::find() );
					},
					'schema_callback' => static function (): array {
						return array(
							'nudge' => array(
								'description' => __( 'The deal the cart almost qualifies for (message, button label, link), or null.', 'lafka-plugin' ),
								'type'        => array( 'object', 'null' ),
								'context'     => array( 'view', 'edit' ),
								'readonly'    => true,
							),
						);
					},
					'schema_type'     => ARRAY_A,
				)
			);
		}
	}
}
