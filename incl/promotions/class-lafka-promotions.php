<?php
/**
 * Lafka_Promotions — BOGO 50% + delivery-minimum + promo banner.
 *
 * Migrated from `lafka-child/functions.php` (P2-01). Math lifted from the
 * child's `inc/lafka-promotions.php` pure helpers (which remain there for
 * back-compat during rollout — the child file gates itself off when this
 * plugin module is enabled).
 *
 * GATING: load is conditional on `is_lafka_promotions()` (set in
 * lafka-plugin.php), which reads `Lafka_Options::is_enabled('promotions')`.
 * Default OFF: existing sites keep the child's implementation until an admin
 * explicitly opts into the plugin version.
 *
 * KNOBS (currently hardcoded — admin UI tracked as P2-01a):
 *   - DELIVERY_MIN     = 30      cart subtotal threshold below which delivery
 *                                 rates get hidden (only local pickup remains).
 *   - BOGO_DISCOUNT    = 0.5     fraction off cheapest units. Half-off = 0.5.
 *   - PROMO_KEY        = 'bogo50_feb2026'  banner dismiss-localStorage key.
 *   - DISMISS_DAYS     = 7       how long a dismissed banner stays dismissed.
 *
 * @package Lafka\Promotions
 * @since   8.7.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Lafka_Promotions' ) ) {

	final class Lafka_Promotions {

		const DELIVERY_MIN  = 30;
		const BOGO_DISCOUNT = 0.5;
		const PROMO_KEY     = 'bogo50_feb2026';
		const DISMISS_DAYS  = 7;
		const OPTION_KEY    = 'lafka_promotions_options';

		/**
		 * Read a knob from `lafka_promotions_options` with the constant as fallback.
		 * Admin UI (Lafka_Promotions_Admin) writes to this option.
		 */
		public static function knob( $name ) {
			static $opts = null;
			if ( null === $opts ) {
				$opts = get_option( self::OPTION_KEY, array() );
				if ( ! is_array( $opts ) ) {
					$opts = array();
				}
			}
			$defaults = array(
				'delivery_min'  => self::DELIVERY_MIN,
				'bogo_discount' => self::BOGO_DISCOUNT,
				'promo_key'     => self::PROMO_KEY,
				'dismiss_days'  => self::DISMISS_DAYS,
			);
			if ( isset( $opts[ $name ] ) && '' !== $opts[ $name ] ) {
				return $opts[ $name ];
			}
			return isset( $defaults[ $name ] ) ? $defaults[ $name ] : null;
		}

		/** @var Lafka_Promotions|null */
		private static $instance = null;

		public static function instance() {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		private function __construct() {
			// Delivery minimum
			add_filter( 'woocommerce_package_rates', array( $this, 'apply_delivery_minimum' ), 10, 2 );
			add_action( 'woocommerce_before_cart', array( $this, 'render_delivery_notice' ) );
			add_action( 'woocommerce_before_checkout_form', array( $this, 'render_delivery_notice' ) );

			// BOGO 50%
			add_action( 'woocommerce_before_calculate_totals', array( $this, 'apply_bogo_to_cart' ), 20, 1 );
			add_filter( 'woocommerce_get_item_data', array( $this, 'render_bogo_label' ), 10, 2 );
			add_filter( 'woocommerce_cart_item_price', array( $this, 'render_bogo_unit_price' ), 10, 3 );
			add_filter( 'woocommerce_cart_item_subtotal', array( $this, 'render_bogo_subtotal' ), 10, 3 );

			// Banner
			add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_banner_assets' ) );
			add_action( 'wp_footer', array( $this, 'render_banner' ) );
		}

		// ─── Pure math helpers (also used by tests) ──────────────────────────

		/**
		 * Distribute the BOGO 50%-off discount across line-item keys.
		 *
		 * Floor(total / 2) cheapest units get the discount. Sort happens
		 * inside the helper, so callers may pass units in any order.
		 *
		 * @param array $units Array of ['key' => string, 'price' => float|int].
		 * @return array<string,int> Map of cart-item key => discounted unit count.
		 */
		public static function distribute_discounts( array $units ) {
			$total = count( $units );
			if ( $total < 2 ) {
				return array();
			}

			usort(
				$units,
				static fn( $a, $b ) => $a['price'] <=> $b['price']
			);

			$discount_count = (int) floor( $total / 2 );
			$distribution   = array();

			for ( $i = 0; $i < $discount_count; $i++ ) {
				$k = $units[ $i ]['key'];
				if ( ! isset( $distribution[ $k ] ) ) {
					$distribution[ $k ] = 0;
				}
				++$distribution[ $k ];
			}

			return $distribution;
		}

		/**
		 * Blended per-unit price after discounting `$disc_qty` of `$qty` units.
		 *
		 * @param float|int $orig     Original unit price.
		 * @param int       $qty      Total units in the line item.
		 * @param int       $disc_qty Units to discount.
		 * @return float
		 */
		public static function blended_price( $orig, $qty, $disc_qty ) {
			$orig     = (float) $orig;
			$qty      = (int) $qty;
			$disc_qty = (int) $disc_qty;

			if ( $qty <= 0 || $disc_qty <= 0 ) {
				return $orig;
			}

			$full_units = $qty - $disc_qty;
			return ( $full_units * $orig + $disc_qty * $orig * (float) self::knob( 'bogo_discount' ) ) / $qty;
		}

		/**
		 * Whether the cart's package contents are below the delivery minimum.
		 *
		 * Boundary semantics: `<` not `<=` — exactly at the threshold ALLOWS delivery.
		 */
		public static function should_block_delivery( $contents_cost ) {
			return (float) $contents_cost < (float) (float) self::knob( 'delivery_min' );
		}

		// ─── Delivery-minimum hooks ──────────────────────────────────────────

		public function apply_delivery_minimum( $rates, $package ) {
			if ( ! self::should_block_delivery( $package['contents_cost'] ) ) {
				return $rates;
			}
			foreach ( $rates as $rate_id => $rate ) {
				if ( 'local_pickup' !== $rate->method_id ) {
					unset( $rates[ $rate_id ] );
				}
			}
			return $rates;
		}

		public function render_delivery_notice() {
			if ( ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty() ) {
				return;
			}

			$subtotal = 0;
			foreach ( WC()->cart->get_cart() as $item ) {
				$subtotal += $item['line_subtotal'];
			}

			if ( $subtotal >= (float) self::knob( 'delivery_min' ) ) {
				return;
			}

			$remaining = (float) self::knob( 'delivery_min' ) - $subtotal;
			printf(
				'<div class="woocommerce-info lafka-delivery-min-notice">%s</div>',
				sprintf(
					/* translators: 1: minimum in store currency, 2: remaining amount */
					esc_html__( 'Delivery is available on orders over %1$s. Add %2$s more to your cart for delivery.', 'lafka-plugin' ),
					wp_kses_post( wc_price( (float) self::knob( 'delivery_min' ) ) ),
					wp_kses_post( wc_price( $remaining ) )
				)
			);
		}

		// ─── BOGO hooks ──────────────────────────────────────────────────────

		public function apply_bogo_to_cart( $cart ) {
			if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
				return;
			}
			if ( did_action( 'woocommerce_before_calculate_totals' ) > 1 ) {
				return;
			}

			// Reset everything and store original prices.
			$total_quantity = 0;
			foreach ( $cart->get_cart() as $key => $cart_item ) {
				if ( isset( $cart->cart_contents[ $key ]['_bogo_original_price'] ) ) {
					$cart_item['data']->set_price( $cart->cart_contents[ $key ]['_bogo_original_price'] );
				} else {
					$cart->cart_contents[ $key ]['_bogo_original_price'] = (float) $cart_item['data']->get_price();
				}
				unset( $cart->cart_contents[ $key ]['_bogo_50'] );
				unset( $cart->cart_contents[ $key ]['_bogo_discounted_qty'] );
				unset( $cart->cart_contents[ $key ]['_bogo_savings'] );
				$total_quantity += $cart_item['quantity'];
			}

			if ( $total_quantity < 2 ) {
				return;
			}

			// Expand into individual units; helper sorts internally.
			$units = array();
			foreach ( $cart->get_cart() as $key => $cart_item ) {
				$price = (float) $cart->cart_contents[ $key ]['_bogo_original_price'];
				for ( $i = 0; $i < $cart_item['quantity']; $i++ ) {
					$units[] = array(
						'key'   => $key,
						'price' => $price,
					);
				}
			}

			$discounts_per_key = self::distribute_discounts( $units );

			foreach ( $discounts_per_key as $key => $disc_qty ) {
				$item = $cart->cart_contents[ $key ];
				$qty  = (int) $item['quantity'];
				$orig = (float) $item['_bogo_original_price'];

				$savings = $orig * (float) self::knob( 'bogo_discount' ) * $disc_qty;
				$blended = self::blended_price( $orig, $qty, $disc_qty );

				$item['data']->set_price( $blended );

				$cart->cart_contents[ $key ]['_bogo_50']             = true;
				$cart->cart_contents[ $key ]['_bogo_discounted_qty'] = $disc_qty;
				$cart->cart_contents[ $key ]['_bogo_savings']        = $savings;
			}
		}

		public function render_bogo_label( $item_data, $cart_item ) {
			if ( empty( $cart_item['_bogo_50'] ) ) {
				return $item_data;
			}
			$disc_qty    = (int) $cart_item['_bogo_discounted_qty'];
			$item_data[] = array(
				'name'  => esc_html__( '🎉 Promotion', 'lafka-plugin' ),
				'value' => sprintf(
					/* translators: %d: number of units to which the discount applies */
					esc_html__( 'BOGO 50%% Off applied to %d unit(s)', 'lafka-plugin' ),
					$disc_qty
				),
			);
			return $item_data;
		}

		public function render_bogo_unit_price( $price_html, $cart_item, $cart_item_key ) {
			if ( ! empty( $cart_item['_bogo_50'] ) && isset( $cart_item['_bogo_original_price'] ) ) {
				return wc_price( (float) $cart_item['_bogo_original_price'] );
			}
			return $price_html;
		}

		public function render_bogo_subtotal( $subtotal_html, $cart_item, $cart_item_key ) {
			if ( empty( $cart_item['_bogo_50'] ) || ! isset( $cart_item['_bogo_savings'] ) ) {
				return $subtotal_html;
			}
			$orig_subtotal = (float) $cart_item['_bogo_original_price'] * (int) $cart_item['quantity'];
			$savings       = (float) $cart_item['_bogo_savings'];
			$new_subtotal  = $orig_subtotal - $savings;

			$out  = '<del>' . wc_price( $orig_subtotal ) . '</del> ';
			$out .= wc_price( $new_subtotal );
			$out .= '<br><small class="lafka-bogo-savings">';
			/* translators: %s: amount saved in store currency */
			$out .= sprintf( esc_html__( 'You save %s', 'lafka-plugin' ), wc_price( $savings ) );
			$out .= '</small>';
			return $out;
		}

		// ─── Banner ──────────────────────────────────────────────────────────

		public function enqueue_banner_assets() {
			$base_url = plugins_url( 'assets/', __FILE__ );
			$base_dir = __DIR__ . '/assets/';

			wp_enqueue_style(
				'lafka-promotions',
				$base_url . 'css/lafka-promotions.css',
				array(),
				file_exists( $base_dir . 'css/lafka-promotions.css' ) ? (string) filemtime( $base_dir . 'css/lafka-promotions.css' ) : '1.0.0'
			);

			wp_enqueue_script(
				'lafka-promotions',
				$base_url . 'js/lafka-promotions.js',
				array(),
				file_exists( $base_dir . 'js/lafka-promotions.js' ) ? (string) filemtime( $base_dir . 'js/lafka-promotions.js' ) : '1.0.0',
				true
			);

			wp_localize_script(
				'lafka-promotions',
				'LAFKA_PROMO',
				array(
					'promoKey'    => self::knob( 'promo_key' ),
					'dismissDays' => (int) self::knob( 'dismiss_days' ),
				)
			);
		}

		public function render_banner() {
			?>
			<div id="lafka-bogo-banner" role="banner" hidden>
				<div class="lafka-bogo-inner">
					🔥 <?php esc_html_e( 'Buy 1, Get 1 50% Off', 'lafka-plugin' ); ?>
				</div>
				<button class="lafka-bogo-close" aria-label="<?php esc_attr_e( 'Close banner', 'lafka-plugin' ); ?>">&times;</button>
			</div>
			<?php
		}
	}

	Lafka_Promotions::instance();
}
