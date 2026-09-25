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
 * Why not WooCommerce's own "Hide shipping costs until an address is entered"
 * (woocommerce_shipping_cost_requires_address)? The live store had it ON and
 * still showed the $52.20 quote: WC_Cart::show_shipping() skips the check
 * whenever the blocks Local Pickup method is enabled (so pickup is visible
 * before an address), and on the classic cart it is satisfied by country +
 * state/postcode without a street. When it does apply it hides every rate,
 * pickup included. This guard is the thin layer for exactly that gap: it
 * leaves the core setting alone and removes only the address-dependent rates.
 *
 * "Delivery" stays a choice while its price is withheld (classic cart and
 * checkout): a $0 placeholder rate `lafka_delivery_pending` labelled
 * "Delivery" replaces the withheld rates, so the customer's Pickup/Delivery
 * preference can select delivery before the address exists (the COD title,
 * the address fields and the totals row all read "delivery"). It can never
 * be ordered: the classic checkout validation and the Store API place-order
 * refuse it, and the moment the address is complete the real delivery rates
 * replace it (Lafka_Fulfilment re-defaults to the first of them). Not added
 * on the block checkout, which explains the missing price through the Store
 * API cart extension instead.
 *
 * Operator surface:
 *   · `lafka_delivery_placeholder_rate_enabled` (bool, default true) and
 *     `lafka_delivery_placeholder_label` (string, default "Delivery").
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
		 * Rate id (and method id) of the "Delivery" placeholder.
		 */
		const PLACEHOLDER = 'lafka_delivery_pending';

		/**
		 * Wire the rate filter and the classic cart/checkout messages.
		 *
		 * @return void
		 */
		public static function init() {
			// Late, so the rates any other plugin added or adjusted are all seen.
			add_filter( 'woocommerce_package_rates', array( __CLASS__, 'filter_package_rates' ), 50, 2 );
			add_filter( 'woocommerce_cart_shipping_packages', array( __CLASS__, 'tag_packages' ) );
			add_action( 'woocommerce_cart_totals_after_shipping', array( __CLASS__, 'render_notice_row' ) );
			add_action( 'woocommerce_review_order_after_shipping', array( __CLASS__, 'render_notice_row' ) );
			add_filter( 'woocommerce_no_shipping_available_html', array( __CLASS__, 'filter_no_shipping_html' ) );
			add_filter( 'woocommerce_cart_no_shipping_available_html', array( __CLASS__, 'filter_no_shipping_html' ) );
			add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'validate_classic_checkout' ), 20, 2 );
			add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( __CLASS__, 'validate_store_api_checkout' ), 5 );
		}

		/**
		 * Whether a rate id is the "Delivery" placeholder.
		 *
		 * @param mixed $rate_id Rate id.
		 * @return bool
		 */
		public static function is_placeholder( $rate_id ): bool {
			return is_string( $rate_id ) && self::PLACEHOLDER === strtok( $rate_id, ':' );
		}

		/**
		 * Whether this request may offer the placeholder: the operator filter,
		 * then the classic checkout only (not a Store API request, not a site
		 * whose Checkout page is the block checkout).
		 *
		 * @return bool
		 */
		public static function placeholder_enabled(): bool {
			/**
			 * Filter whether "Delivery" stays selectable (as a $0 placeholder
			 * that cannot be ordered) while its price waits for the address.
			 *
			 * @since 10.3.0
			 * @param bool $enabled Default true.
			 */
			if ( ! apply_filters( 'lafka_delivery_placeholder_rate_enabled', true ) ) {
				return false;
			}
			if ( self::is_store_api_request() ) {
				return false;
			}

			return ! ( class_exists( 'Lafka_Checkout_Mode' ) && Lafka_Checkout_Mode::is_blocks() );
		}

		/**
		 * The placeholder rate.
		 *
		 * @return object|null WC_Shipping_Rate, or null without WooCommerce.
		 */
		private static function placeholder_rate() {
			if ( ! class_exists( 'WC_Shipping_Rate' ) ) {
				return null;
			}

			/**
			 * Filter the label of the "Delivery" choice shown before the address.
			 *
			 * @since 10.3.0
			 * @param string $label Default "Delivery".
			 */
			$label = (string) apply_filters( 'lafka_delivery_placeholder_label', __( 'Delivery', 'lafka-plugin' ) );

			return new WC_Shipping_Rate( self::PLACEHOLDER, $label, 0, array(), self::PLACEHOLDER, 0 );
		}

		/**
		 * woocommerce_cart_shipping_packages: keep the Store API's cached rates
		 * apart from the classic ones (the placeholder is classic-only, and
		 * WooCommerce caches package rates by the package contents).
		 *
		 * @param mixed $packages Shipping packages.
		 * @return mixed
		 */
		public static function tag_packages( $packages ) {
			if ( ! is_array( $packages ) ) {
				return $packages;
			}
			$context = self::is_store_api_request() ? 'store_api' : 'classic';
			foreach ( $packages as $i => $package ) {
				if ( is_array( $package ) ) {
					$packages[ $i ]['lafka_rate_context'] = $context;
				}
			}

			return $packages;
		}

		/**
		 * Whether this is a Store API request.
		 *
		 * @return bool
		 */
		private static function is_store_api_request(): bool {
			$wc = function_exists( 'WC' ) ? WC() : null;
			if ( is_object( $wc ) && method_exists( $wc, 'is_store_api_request' ) ) {
				return (bool) $wc->is_store_api_request();
			}

			return isset( $_SERVER['REQUEST_URI'] ) && false !== strpos( rawurldecode( sanitize_text_field( wp_unslash( (string) $_SERVER['REQUEST_URI'] ) ) ), 'wc/store/' );
		}

		/**
		 * The chosen rate ids in the session.
		 *
		 * @return string[]
		 */
		private static function chosen_rates(): array {
			$session = self::session();

			return null === $session ? array() : array_map( 'strval', (array) $session->get( 'chosen_shipping_methods' ) );
		}

		/**
		 * Whether the placeholder is the chosen rate of any package.
		 *
		 * @return bool
		 */
		public static function placeholder_chosen(): bool {
			foreach ( self::chosen_rates() as $rate_id ) {
				if ( self::is_placeholder( $rate_id ) ) {
					return true;
				}
			}

			return false;
		}

		/**
		 * The refusal shown when the placeholder is still chosen at place-order.
		 *
		 * @return string
		 */
		public static function placeholder_error(): string {
			return __( 'Enter your delivery address to see the delivery cost, or choose pickup.', 'lafka-plugin' );
		}

		/**
		 * woocommerce_after_checkout_validation: never place an order on the
		 * placeholder (the address was still incomplete after the totals).
		 *
		 * @param mixed $data   Posted data.
		 * @param mixed $errors WP_Error.
		 * @return void
		 */
		public static function validate_classic_checkout( $data, $errors ) {
			if ( ! is_object( $errors ) || ! method_exists( $errors, 'add' ) || ! self::placeholder_chosen() ) {
				return;
			}
			// Reported as field_validation by the GX1 observer (codes only).
			$errors->add( 'shipping', self::placeholder_error() );
		}

		/**
		 * Store API place order: the same refusal (a cached placeholder).
		 *
		 * @param mixed $order WC_Order being placed.
		 * @return void
		 *
		 * @throws \Automattic\WooCommerce\StoreApi\Exceptions\RouteException When the placeholder is chosen.
		 * @throws \RuntimeException Fallback without the Store API.
		 */
		public static function validate_store_api_checkout( $order ) {
			$chosen = self::placeholder_chosen();
			if ( ! $chosen && is_object( $order ) && method_exists( $order, 'get_shipping_methods' ) ) {
				foreach ( (array) $order->get_shipping_methods() as $item ) {
					if ( is_object( $item ) && method_exists( $item, 'get_method_id' ) && self::is_placeholder( (string) $item->get_method_id() ) ) {
						$chosen = true;
					}
				}
			}
			if ( ! $chosen ) {
				return;
			}
			if ( class_exists( '\Automattic\WooCommerce\StoreApi\Exceptions\RouteException' ) ) {
				throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'lafka_delivery_address_required', esc_html( self::placeholder_error() ), 400 );
			}
			throw new \RuntimeException( esc_html( self::placeholder_error() ) );
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

			$placeholder = false;
			if ( $withheld > 0 && self::placeholder_enabled() ) {
				$rate = self::placeholder_rate();
				if ( null !== $rate ) {
					$kept[ self::PLACEHOLDER ] = $rate;
					$placeholder               = true;
				}
			}
			self::remember( $withheld, count( $kept ) - ( $placeholder ? 1 : 0 ), $placeholder );

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
			$recalled = self::recalled();
			if ( ! self::is_withholding() ) {
				return;
			}
			// With the "Delivery" placeholder offered, explain only when it is
			// the choice (no delivery hint under a chosen pickup). Without it,
			// only when some other option is still listed.
			if ( $recalled['placeholder'] ? ! self::placeholder_chosen() : $recalled['kept'] < 1 ) {
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
		 * @param int  $withheld    Rates withheld.
		 * @param int  $kept        Real rates kept (placeholder not counted).
		 * @param bool $placeholder Whether the "Delivery" placeholder was added.
		 * @return void
		 */
		private static function remember( int $withheld, int $kept, bool $placeholder = false ) {
			$session = self::session();
			if ( null === $session ) {
				return;
			}
			$session->set(
				self::SESSION_KEY,
				array(
					'withheld'    => $withheld,
					'kept'        => $kept,
					'placeholder' => $placeholder,
				)
			);
		}

		/**
		 * The last calculation recorded in the WC session.
		 *
		 * @return array{withheld:int,kept:int,placeholder:bool}
		 */
		private static function recalled(): array {
			$session = self::session();
			$stored  = null === $session ? null : $session->get( self::SESSION_KEY );

			return array(
				'withheld'    => is_array( $stored ) ? (int) ( $stored['withheld'] ?? 0 ) : 0,
				'kept'        => is_array( $stored ) ? (int) ( $stored['kept'] ?? 0 ) : 0,
				'placeholder' => is_array( $stored ) && ! empty( $stored['placeholder'] ),
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
