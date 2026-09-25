<?php
/**
 * Lafka_Promotions — BOGO 50% + delivery-minimum + promo banner.
 *
 * Migrated from `lafka-child/functions.php` (P2-01). Math lifted from the
 * child's former `inc/lafka-promotions.php` pure helpers; lafka-child 6.0.0
 * removed its copy, so this module is the only implementation.
 *
 * GATING: hook wiring is conditional on `is_lafka_promotions()` (reads
 * `Lafka_Options::is_enabled('promotions')`). Default OFF. NOTE: the child
 * theme's implementation was REMOVED in lafka-child 6.x (thin layer) — there
 * is no fallback. A site that previously used BOGO/delivery-minimum must
 * enable this module explicitly; Lafka_Promotions_Admin surfaces a migration
 * notice for the lafka-child cohort when the module is off.
 *
 * KNOBS (defaults below; Lafka_Promotions_Admin overrides them via the
 * `lafka_promotions_options` option, read through knob()):
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

require_once __DIR__ . '/../lafka-shipping-method-helpers.php';
// `lafka_checkout_blocked` vocabulary (GX1) — the delivery minimum reports refusals.
require_once __DIR__ . '/../observability/class-lafka-checkout-block-reasons.php';

if ( ! class_exists( 'Lafka_Promotions' ) ) {

	final class Lafka_Promotions {

		const DELIVERY_MIN  = 30;
		const BOGO_DISCOUNT = 0.5;
		const PROMO_KEY     = 'bogo50_feb2026';
		const DISMISS_DAYS  = 7;
		const OPTION_KEY    = 'lafka_promotions_options';

		/**
		 * Per-request cache of the `lafka_promotions_options` array.
		 *
		 * @var array|null
		 */
		private static $knobs = null;

		/**
		 * Drop the cached knobs (after the option changes, and in tests).
		 *
		 * @return void
		 */
		public static function flush_knobs(): void {
			self::$knobs = null;
		}

		/**
		 * Read a knob from `lafka_promotions_options` with the constant as fallback.
		 * Admin UI (Lafka_Promotions_Admin) writes to this option.
		 */
		public static function knob( $name ) {
			if ( null === self::$knobs ) {
				$opts        = get_option( self::OPTION_KEY, array() );
				self::$knobs = is_array( $opts ) ? $opts : array();
			}
			$opts = self::$knobs;
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

		/**
		 * The configured BOGO discount as shopper-facing text: "Free" at 100%,
		 * otherwise "<n>% Off" — so the cart label and banner always match the
		 * discount actually charged.
		 *
		 * @return string
		 */
		public static function bogo_offer_label(): string {
			$fraction = min( 1.0, max( 0.0, (float) self::knob( 'bogo_discount' ) ) );
			if ( $fraction >= 1.0 ) {
				return __( 'Free', 'lafka-plugin' );
			}
			/* translators: %s: discount percentage, e.g. 50 */
			return sprintf( __( '%s%% Off', 'lafka-plugin' ), (string) round( $fraction * 100, 1 ) );
		}

		/**
		 * The deal in plain words: "Buy 1, get 1 free" / "Buy 1, get 1 50% off"
		 * (banner and cart line; no emoji, sentence case).
		 *
		 * @return string
		 */
		public static function bogo_offer_phrase(): string {
			$fraction = min( 1.0, max( 0.0, (float) self::knob( 'bogo_discount' ) ) );
			if ( $fraction >= 1.0 ) {
				return __( 'Buy 1, get 1 free', 'lafka-plugin' );
			}
			/* translators: %s: discount percentage, e.g. 50 */
			return sprintf( __( 'Buy 1, get 1 %s%% off', 'lafka-plugin' ), (string) round( $fraction * 100, 1 ) );
		}

		/**
		 * localStorage key that remembers a dismissed banner. ONE source for the
		 * pre-paint head check and lafka-promotions.js (a new promo key re-arms it).
		 *
		 * @return string
		 */
		public static function dismiss_key(): string {
			return 'lafka_bogo_dismissed_' . (string) self::knob( 'promo_key' );
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
			// Hide a dismissed banner before first paint (the banner is rendered
			// visible, so showing it never shifts the layout — H-05).
			add_action( 'wp_head', array( $this, 'print_prepaint_dismiss_check' ), 1 );
			// In the page flow at the top of <body> (pushes the header down, never
			// covers it or sticky bars). Themes without wp_body_open get the
			// legacy fixed overlay from wp_footer instead.
			add_action( 'wp_body_open', array( $this, 'render_banner_inline' ), 5 );
			add_action( 'wp_footer', array( $this, 'render_banner_fallback' ) );
		}

		/** @var bool Whether the banner was already printed this request. */
		private $banner_rendered = false;

		/** wp_body_open: the banner as an in-flow strip at the top of the page. */
		public function render_banner_inline(): void {
			$this->banner_rendered = true;
			$this->render_banner( 'inline' );
		}

		/** wp_footer: fixed overlay, only when the theme never fired wp_body_open. */
		public function render_banner_fallback(): void {
			if ( $this->banner_rendered ) {
				return;
			}
			$this->banner_rendered = true;
			$this->render_banner( 'fixed' );
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
			// `bogo_discount` is the fraction taken OFF (0.5 = 50% off, 1 = free),
			// so each discounted unit is CHARGED its complement ( 1 - discount ).
			// This keeps the blended charge reconciled with the cart's displayed
			// subtotal/savings, which also key off the same fraction-off knob.
			return ( $full_units * $orig + $disc_qty * $orig * ( 1 - (float) self::knob( 'bogo_discount' ) ) ) / $qty;
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

		/**
		 * The single comparison base for the delivery minimum: the cart's
		 * post-coupon, ex-tax contents total.
		 *
		 * This equals the $package['contents_cost'] that apply_delivery_minimum()
		 * gates on for the typical single-package cart, so routing every
		 * delivery-minimum decision through one helper guarantees the customer
		 * notice can never disagree with whether the delivery rates were actually
		 * removed — even with WC coupons / BOGO discounts active.
		 *
		 * @return float
		 */
		private function get_delivery_base() {
			if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
				return 0.0;
			}
			return (float) WC()->cart->get_cart_contents_total();
		}

		public function apply_delivery_minimum( $rates, $package ) {
			// $package['contents_cost'] is the post-coupon, ex-tax line-total sum
			// for this package — the same value get_delivery_base() reports for a
			// single-package cart, which is what render_delivery_notice() keys off.
			if ( ! self::should_block_delivery( $package['contents_cost'] ) ) {
				return $rates;
			}
			$withheld = false;
			foreach ( $rates as $rate_id => $rate ) {
				if ( ! lafka_is_pickup_shipping_method( $rate->method_id ) ) {
					unset( $rates[ $rate_id ] );
					$withheld = true;
				}
			}
			if ( $withheld ) {
				self::report_below_minimum();
			}
			return $rates;
		}

		/**
		 * Report `below_delivery_minimum` once per customer session per day —
		 * package rates are recalculated many times while a cart is edited, and
		 * one customer seeing "delivery unavailable" is one refusal.
		 *
		 * @return void
		 */
		private static function report_below_minimum(): void {
			$session = function_exists( 'did_action' ) && did_action( 'woocommerce_init' ) && function_exists( 'WC' )
				&& is_object( WC() ) && isset( WC()->session ) && is_object( WC()->session ) ? WC()->session : null;
			$today   = gmdate( 'Y-m-d' );
			if ( $session && method_exists( $session, 'get' ) && $today === $session->get( 'lafka_below_min_reported' ) ) {
				return;
			}
			if ( $session && method_exists( $session, 'set' ) ) {
				$session->set( 'lafka_below_min_reported', $today );
			}
			Lafka_Checkout_Block_Reasons::emit(
				Lafka_Checkout_Block_Reasons::BELOW_DELIVERY_MINIMUM,
				array(
					'path'  => defined( 'REST_REQUEST' ) && REST_REQUEST ? 'store_api' : 'classic',
					'stage' => 'cart',
					'code'  => 'delivery_minimum',
				)
			);
		}

		public function render_delivery_notice() {
			if ( ! function_exists( 'WC' ) || ! WC()->cart || WC()->cart->is_empty() ) {
				return;
			}

			// Use the same post-coupon base the rate-hiding gate uses, via the
			// shared helper, so the show/hide decision and the $remaining figure
			// can never drift from apply_delivery_minimum()'s rate-stripping
			// decision — even when WC coupons / BOGO discounts are active.
			$base = $this->get_delivery_base();

			if ( ! self::should_block_delivery( $base ) ) {
				return;
			}

			$remaining = (float) self::knob( 'delivery_min' ) - $base;
			// One text node (theme notice layouts flex their children — O-12).
			printf(
				'<div class="woocommerce-info lafka-delivery-min-notice" role="status"><span class="lafka-delivery-min-notice__text">%s</span></div>',
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
			$value   = self::bogo_offer_phrase();
			$savings = isset( $cart_item['_bogo_savings'] ) ? (float) $cart_item['_bogo_savings'] : 0.0;
			if ( $savings > 0 && function_exists( 'wc_price' ) ) {
				$saved = html_entity_decode( wp_strip_all_tags( wc_price( $savings ) ), ENT_QUOTES, 'UTF-8' );
				/* translators: 1: the deal, e.g. "Buy 1, get 1 50% off", 2: amount saved, e.g. "$2.00" */
				$value = sprintf( __( '%1$s — saved %2$s', 'lafka-plugin' ), $value, $saved );
			}
			// Plain words, no emoji (O-20): "Deal: Buy 1, get 1 50% off — saved $2.00".
			$item_data[] = array(
				'name'  => esc_html__( 'Deal', 'lafka-plugin' ),
				'value' => esc_html( $value ),
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
					'dismissKey'  => self::dismiss_key(),
					'dismissDays' => (int) self::knob( 'dismiss_days' ),
				)
			);
		}

		/**
		 * The pre-paint check: a tiny inline head script that reads the SAME
		 * localStorage key as lafka-promotions.js and, when the banner was
		 * dismissed recently, marks <html> so the (visible-by-default) banner is
		 * hidden before first paint. Its CSS rule is inline too, because theme
		 * stylesheets may load asynchronously.
		 *
		 * @return string Script body.
		 */
		public static function prepaint_script(): string {
			$days = max( 1, (int) self::knob( 'dismiss_days' ) );

			return '(function(){try{var t=window.localStorage.getItem(' . wp_json_encode( self::dismiss_key() ) . ');'
				. 'if(t&&Date.now()-parseInt(t,10)<' . $days . '*864e5){document.documentElement.classList.add("lafka-bogo-dismissed");}}catch(e){}})();';
		}

		/**
		 * wp_head: print the pre-paint dismissal check (H-05).
		 *
		 * @return void
		 */
		public function print_prepaint_dismiss_check(): void {
			echo '<style id="lafka-bogo-prepaint">.lafka-bogo-dismissed #lafka-bogo-banner{display:none}</style>' . "\n";
			if ( function_exists( 'wp_print_inline_script_tag' ) ) {
				wp_print_inline_script_tag( self::prepaint_script(), array( 'id' => 'lafka-bogo-prepaint-js' ) );
				return;
			}
			echo '<script id="lafka-bogo-prepaint-js">' . self::prepaint_script() . "</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- static script; the only variable part is wp_json_encode()d.
		}

		/**
		 * Print the dismissible BOGO banner.
		 *
		 * The in-flow variant renders VISIBLE (no `hidden`, no JS reveal), so it
		 * never shifts the page after load; a recent dismissal hides it before
		 * paint (print_prepaint_dismiss_check). The legacy fixed overlay covers
		 * the page instead of pushing it, so it keeps its JS slide-in.
		 *
		 * @param string $placement `inline` (in the page flow) or `fixed` (legacy overlay).
		 */
		public function render_banner( string $placement = 'fixed' ) {
			$placement = 'inline' === $placement ? 'inline' : 'fixed';

			/**
			 * Filter where the banner's offer text links to ('' = no link).
			 *
			 * @since 10.3.0
			 * @param string $url Default: the menu page.
			 */
			$link = (string) apply_filters( 'lafka_bogo_banner_link', function_exists( 'lafka_get_menu_url' ) ? lafka_get_menu_url() : '' );
			?>
			<div id="lafka-bogo-banner" class="lafka-bogo-banner--<?php echo esc_attr( $placement ); ?>" role="region" aria-label="<?php esc_attr_e( 'Promotion', 'lafka-plugin' ); ?>"<?php echo 'fixed' === $placement ? ' hidden' : ''; ?>>
				<div class="lafka-bogo-inner">
					<?php if ( '' !== $link ) : ?>
						<a class="lafka-bogo-link" href="<?php echo esc_url( $link ); ?>"><?php echo esc_html( self::bogo_offer_phrase() ); ?></a>
					<?php else : ?>
						<?php echo esc_html( self::bogo_offer_phrase() ); ?>
					<?php endif; ?>
				</div>
				<button type="button" class="lafka-bogo-close" aria-label="<?php esc_attr_e( 'Close banner', 'lafka-plugin' ); ?>"><span aria-hidden="true">&times;</span></button>
			</div>
			<?php
		}
	}

	// Auto-instantiate when WP runtime is present (skipped in tests so the
	// class can be loaded standalone for static-method assertions) AND the
	// module gate is on. The admin settings screen requires this file for
	// knob() reads even while the module is OFF — that load must not wire
	// any front/cart hooks.
	if ( function_exists( 'add_action' )
		&& ( ! function_exists( 'is_lafka_promotions' ) || is_lafka_promotions() ) ) {
		Lafka_Promotions::instance();
	}
}

if ( ! function_exists( 'lafka_delivery_minimum' ) ) {
	/**
	 * The order subtotal below which delivery is not offered (0 = none), for
	 * display (the drawer's Delivery note). 0 while the Promotions module is off.
	 *
	 * @return float
	 */
	function lafka_delivery_minimum(): float {
		$minimum = ( ! function_exists( 'is_lafka_promotions' ) || is_lafka_promotions() ) ? max( 0.0, (float) Lafka_Promotions::knob( 'delivery_min' ) ) : 0.0;

		/**
		 * Filter the delivery minimum shown to customers.
		 *
		 * @since 10.3.0
		 * @param float $minimum Order subtotal needed for delivery (0 = none).
		 */
		return (float) apply_filters( 'lafka_delivery_minimum', $minimum );
	}
}
