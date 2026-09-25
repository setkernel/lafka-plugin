<?php
/**
 * Lafka_Delivery_Quote_Guard — never quote delivery from a partial address.
 *
 * Distance-based delivery methods (and any rate that depends on where the
 * order goes) happily compute a price from whatever destination WooCommerce
 * has: before the customer typed a street address that is the store's base
 * province/state, so the quote is measured from the region's centroid — a
 * real "$52.20" delivery fee on a customer who lives two kilometres away.
 * That quote is wrong and it loses the order.
 *
 * This guard withholds every rate that needs an address until the package
 * destination carries a street address line and a postcode (the postcode is
 * skipped for countries whose address format has none). Pickup rates are
 * never touched. While rates are withheld the customer is told why — a row
 * under the shipping options on the classic cart + checkout, the empty-rates
 * message when delivery is the only method, and the `lafka` Store API cart
 * extension (delivery_address_required / delivery_address_message) that the
 * block cart/checkout component renders. The filter runs inside
 * WC_Shipping::calculate_shipping_for_package(), which the classic cart, the
 * classic checkout and the Store API all share, so every path behaves the same.
 *
 * Operator surface:
 *   · Customizer → Lafka — Checkout → "Hide delivery prices until a street
 *     address is entered" (theme_mod `lafka_delivery_quote_guard`, default on)
 *     and the message text (`lafka_delivery_quote_guard_message`).
 *   · `lafka_delivery_quote_guard_enabled` (bool)
 *   · `lafka_delivery_rate_needs_address` (bool, $rate, $package) — opt a
 *     method out (e.g. a flat rate that is the same everywhere).
 *   · `lafka_delivery_quote_required_fields` (string[], $country)
 *   · `lafka_delivery_quote_guard_message` (string)
 *   · Which methods are pickup: `lafka_pickup_shipping_method_ids`.
 *
 * @package Lafka\Plugin\Checkout
 * @since   10.2.0
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/../lafka-shipping-method-helpers.php';

if ( ! class_exists( 'Lafka_Delivery_Quote_Guard' ) ) {

	/**
	 * Withholds address-dependent shipping rates until the address is complete.
	 */
	final class Lafka_Delivery_Quote_Guard {

		/**
		 * Theme mod: guard on/off (default on).
		 */
		const MOD_ENABLED = 'lafka_delivery_quote_guard';

		/**
		 * Theme mod: customer-facing message override ('' = default copy).
		 */
		const MOD_MESSAGE = 'lafka_delivery_quote_guard_message';

		/**
		 * WC session key: what the last rate calculation withheld/kept.
		 */
		const SESSION_KEY = 'lafka_delivery_quote_withheld';

		/**
		 * Wire the rate filter and the classic cart/checkout messages.
		 *
		 * @return void
		 */
		public static function init() {
			// Late, so the rates any other plugin added or adjusted are all seen.
			add_filter( 'woocommerce_package_rates', array( __CLASS__, 'filter_package_rates' ), 50, 2 );
			add_action( 'woocommerce_cart_totals_after_shipping', array( __CLASS__, 'render_notice_row' ) );
			add_action( 'woocommerce_review_order_after_shipping', array( __CLASS__, 'render_notice_row' ) );
			add_filter( 'woocommerce_no_shipping_available_html', array( __CLASS__, 'filter_no_shipping_html' ) );
			add_filter( 'woocommerce_cart_no_shipping_available_html', array( __CLASS__, 'filter_no_shipping_html' ) );
		}

		/**
		 * Whether the guard is on.
		 *
		 * @return bool
		 */
		public static function is_enabled(): bool {
			$enabled = function_exists( 'get_theme_mod' ) ? (bool) get_theme_mod( self::MOD_ENABLED, true ) : true;

			/**
			 * Filter whether delivery rates are withheld until the address is complete.
			 *
			 * @param bool $enabled Customizer value (default true).
			 */
			return (bool) apply_filters( 'lafka_delivery_quote_guard_enabled', $enabled );
		}

		/**
		 * The customer-facing explanation shown while delivery is withheld.
		 *
		 * @return string
		 */
		public static function message(): string {
			$custom  = function_exists( 'get_theme_mod' ) ? trim( (string) get_theme_mod( self::MOD_MESSAGE, '' ) ) : '';
			$message = '' !== $custom ? $custom : __( 'Enter your street address to see the delivery cost.', 'lafka-plugin' );

			/**
			 * Filter the "enter your address to see delivery" message.
			 *
			 * @param string $message Message text (plain text).
			 */
			return (string) apply_filters( 'lafka_delivery_quote_guard_message', $message );
		}

		/**
		 * Destination keys that must be filled before delivery is priced.
		 *
		 * @param string $country Destination country code.
		 * @return string[]
		 */
		public static function required_fields( string $country ): array {
			$fields = array( 'address_1', 'postcode' );
			if ( '' !== $country && ! self::country_uses_postcode( $country ) ) {
				$fields = array( 'address_1' );
			}

			/**
			 * Filter the destination fields a delivery quote needs.
			 *
			 * @param string[] $fields  Package destination keys (address_1, postcode, city, …).
			 * @param string   $country Destination country code.
			 */
			return array_values( array_filter( array_map( 'strval', (array) apply_filters( 'lafka_delivery_quote_required_fields', $fields, $country ) ) ) );
		}

		/**
		 * Whether a country's address format requires a postcode, per
		 * WooCommerce's country locale (e.g. Hong Kong has none).
		 *
		 * @param string $country Country code.
		 * @return bool
		 */
		private static function country_uses_postcode( string $country ): bool {
			$wc = function_exists( 'WC' ) ? WC() : null;
			if ( ! is_object( $wc ) || ! isset( $wc->countries ) || ! is_object( $wc->countries ) || ! method_exists( $wc->countries, 'get_country_locale' ) ) {
				return true;
			}
			$locale   = (array) $wc->countries->get_country_locale();
			$postcode = $locale[ $country ]['postcode'] ?? array();
			if ( ! is_array( $postcode ) ) {
				return true;
			}
			if ( array_key_exists( 'hidden', $postcode ) && $postcode['hidden'] ) {
				return false;
			}

			return ! ( array_key_exists( 'required', $postcode ) && ! $postcode['required'] );
		}

		/**
		 * Whether a package destination is complete enough to price delivery.
		 *
		 * @param array $destination WC package destination.
		 * @return bool
		 */
		public static function destination_is_complete( array $destination ): bool {
			$country = (string) ( $destination['country'] ?? '' );
			foreach ( self::required_fields( $country ) as $key ) {
				$value = trim( (string) ( $destination[ $key ] ?? '' ) );
				// WooCommerce mirrors address_1 as `address` in package destinations.
				if ( '' === $value && 'address_1' === $key ) {
					$value = trim( (string) ( $destination['address'] ?? '' ) );
				}
				if ( '' === $value ) {
					return false;
				}
			}

			return true;
		}

		/**
		 * Whether a rate depends on the delivery address (default: every rate
		 * that is not a customer pickup).
		 *
		 * @param mixed $rate    WC_Shipping_Rate (or a rate-shaped object).
		 * @param array $package WC shipping package.
		 * @return bool
		 */
		public static function rate_needs_address( $rate, array $package = array() ): bool {
			$method_id = '';
			if ( is_object( $rate ) && method_exists( $rate, 'get_method_id' ) ) {
				$method_id = (string) $rate->get_method_id();
			} elseif ( is_object( $rate ) && isset( $rate->method_id ) ) {
				$method_id = (string) $rate->method_id;
			}
			$needs = ! lafka_is_pickup_shipping_method( $method_id );

			/**
			 * Filter whether a shipping rate needs the full delivery address.
			 *
			 * Return false for a method whose price does not depend on the
			 * address (a flat rate or free shipping that is the same everywhere)
			 * so it stays visible before the address is entered.
			 *
			 * @param bool  $needs   Default: true for every non-pickup rate.
			 * @param mixed $rate    WC_Shipping_Rate.
			 * @param array $package WC shipping package.
			 */
			return (bool) apply_filters( 'lafka_delivery_rate_needs_address', $needs, $rate, $package );
		}

		/**
		 * woocommerce_package_rates: drop address-dependent rates while the
		 * destination is partial.
		 *
		 * @param mixed $rates   Rates keyed by rate id.
		 * @param mixed $package WC shipping package.
		 * @return mixed
		 */
		public static function filter_package_rates( $rates, $package ) {
			if ( ! is_array( $rates ) ) {
				return $rates;
			}
			$package = is_array( $package ) ? $package : array();

			if ( ! self::is_enabled() || self::destination_is_complete( (array) ( $package['destination'] ?? array() ) ) ) {
				self::remember( 0, count( $rates ) );
				return $rates;
			}

			$kept     = array();
			$withheld = 0;
			foreach ( $rates as $rate_id => $rate ) {
				if ( self::rate_needs_address( $rate, $package ) ) {
					++$withheld;
					continue;
				}
				$kept[ $rate_id ] = $rate;
			}
			self::remember( $withheld, count( $kept ) );

			return $kept;
		}

		/**
		 * Whether the current cart's delivery quote is being withheld.
		 *
		 * Read from the session because WooCommerce caches package rates per
		 * destination: on a cached read the rate filter does not run again, but
		 * the cache (and so this record) is keyed by the same destination, so
		 * the two cannot disagree.
		 *
		 * @return bool
		 */
		public static function is_withholding(): bool {
			return self::is_enabled() && self::recalled()['withheld'] > 0;
		}

		/**
		 * Classic cart/checkout totals: a row under the shipping options
		 * explaining the missing delivery price (only when some option is still
		 * listed — with none left, filter_no_shipping_html() explains instead).
		 *
		 * @return void
		 */
		public static function render_notice_row() {
			if ( ! self::is_withholding() || self::recalled()['kept'] < 1 ) {
				return;
			}
			printf(
				'<tr class="lafka-delivery-quote-notice"><td colspan="2"><p class="lafka-delivery-quote-notice__text">%s</p></td></tr>',
				esc_html( self::message() )
			);
		}

		/**
		 * The empty-rates copy when delivery was the only option and it is
		 * being withheld.
		 *
		 * @param mixed $html WooCommerce's message.
		 * @return mixed
		 */
		public static function filter_no_shipping_html( $html ) {
			return self::is_withholding() ? esc_html( self::message() ) : $html;
		}

		/**
		 * Record the last calculation in the WC session.
		 *
		 * @param int $withheld Rates withheld.
		 * @param int $kept     Rates kept.
		 * @return void
		 */
		private static function remember( int $withheld, int $kept ) {
			$session = self::session();
			if ( null === $session ) {
				return;
			}
			$session->set(
				self::SESSION_KEY,
				array(
					'withheld' => $withheld,
					'kept'     => $kept,
				)
			);
		}

		/**
		 * The last calculation recorded in the WC session.
		 *
		 * @return array{withheld:int,kept:int}
		 */
		private static function recalled(): array {
			$session = self::session();
			$stored  = null === $session ? null : $session->get( self::SESSION_KEY );

			return array(
				'withheld' => is_array( $stored ) ? (int) ( $stored['withheld'] ?? 0 ) : 0,
				'kept'     => is_array( $stored ) ? (int) ( $stored['kept'] ?? 0 ) : 0,
			);
		}

		/**
		 * The WC session, when there is one.
		 *
		 * @return object|null
		 */
		private static function session() {
			$wc = function_exists( 'WC' ) ? WC() : null;

			return ( is_object( $wc ) && isset( $wc->session ) && is_object( $wc->session ) ) ? $wc->session : null;
		}
	}
}
