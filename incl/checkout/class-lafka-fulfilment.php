<?php
/**
 * Lafka_Fulfilment: one pickup/delivery preference that preselects the
 * matching WooCommerce shipping rate (GX4).
 *
 * The customer says "Pickup" or "Delivery" once, in the header, the cart
 * drawer or the menu page. A theme writes that choice to the cookie
 * `lafka_order_method` (the name the analytics page context already reads).
 * When WooCommerce picks a default shipping rate for the cart, it takes the
 * first rate that matches the preference, on the classic cart/checkout and the
 * block (Store API) checkout alike, because both resolve the default through
 * wc_get_default_shipping_method_for_package().
 *
 * Never over the customer's own choice. WooCommerce only asks for a default
 * when nothing valid is chosen or the rate list changed. The default this
 * class last handed back is remembered in the session as automatic; a chosen
 * rate that differs from it was picked by the customer and stays. So:
 *   · delivery preferred, delivery rates withheld until a street address
 *     exists (GX0 quote guard): pickup is the automatic default; when the
 *     address arrives and delivery rates appear, delivery takes over;
 *   · the customer picks pickup at checkout: kept, whatever the preference.
 * When the preference changes (the toggle), the next full page load releases
 * an automatic choice so WooCommerce re-defaults through the preference.
 * That release never runs on a POST, an AJAX call or a REST request, so an
 * order being submitted is never touched.
 *
 * Branch selection: when the operator collects branch + order type (the
 * branch modal), the session's validated order type wins over the cookie.
 * That session was checked against the branch's capability and delivery
 * area. The cookie never rewrites it; a pickup order type already turns
 * shipping off (Lafka_Branch_Locations::enable_shipping_only_for_delivery).
 *
 * Public API:
 *   · lafka_fulfilment_modes(): string[]  offered modes, subset of
 *     ['pickup', 'delivery'] in that order ([] = no choice to offer).
 *     Filter `lafka_fulfilment_modes`.
 *   · lafka_fulfilment_preference(): string  'pickup', 'delivery' or ''.
 *     Filter `lafka_fulfilment_preference`.
 *   · Lafka_Fulfilment::COOKIE  the cookie name a theme writes.
 *   · Lafka_Fulfilment::pickup_method_ids(): string[]  the shipping method ids
 *     that are a customer pickup, the one list (and filter) every PHP reader and
 *     the scripts (through their config) use.
 *   · Lafka_Fulfilment::current_mode(): string  the mode in force for this
 *     visitor: their preference, else the first mode the store offers.
 *   · Filter `lafka_fulfilment_preselect_enabled` (bool, default true): turn
 *     the rate preselection off and keep WooCommerce's own default.
 *   · Which methods are pickup: `lafka_pickup_shipping_method_ids` (GX0), read
 *     through pickup_method_ids().
 *
 * The preference is per visitor. A page-cached template should not print it
 * server-side as the only source; read the cookie in the browser too.
 *
 * @package Lafka\Plugin\Checkout
 * @since   10.2.0
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/../lafka-shipping-method-helpers.php';

if ( ! class_exists( 'Lafka_Fulfilment' ) ) {

	/**
	 * Fulfilment modes, the customer's preference, and rate preselection.
	 */
	final class Lafka_Fulfilment {

		/**
		 * Cookie holding the preference ('pickup' | 'delivery').
		 */
		const COOKIE = 'lafka_order_method';

		/**
		 * Transient caching the shipping-zone scan.
		 */
		const MODES_TRANSIENT = 'lafka_fulfilment_modes';

		/**
		 * WC session: the rate id this class last handed back as the automatic
		 * default. A chosen rate that differs from it was picked by the customer.
		 */
		const SESSION_AUTO = 'lafka_fulfilment_auto_rates';

		/**
		 * WC session: the preference the last automatic default was made for.
		 */
		const SESSION_APPLIED = 'lafka_fulfilment_applied';

		/**
		 * The two modes, in display order.
		 */
		const ALL_MODES = array( 'pickup', 'delivery' );

		/**
		 * Per-request modes cache.
		 *
		 * @var string[]|null
		 */
		private static $modes = null;

		/**
		 * Wire the rate preselection and the cache busting.
		 *
		 * @return void
		 */
		public static function init() {
			add_filter( 'woocommerce_shipping_chosen_method', array( __CLASS__, 'filter_chosen_method' ), 20, 3 );
			add_action( 'woocommerce_cart_loaded_from_session', array( __CLASS__, 'maybe_release_automatic_choice' ), 20 );
			add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'validate_classic_checkout' ), 25, 2 );
			// Block checkout: the same refusal, through the Store API place-order action
			// (after the quote guard at 5, before Lafka_Store_Api's own work at 10).
			add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( __CLASS__, 'validate_store_api_checkout' ), 6 );

			foreach ( array(
				'woocommerce_shipping_zone_method_added',
				'woocommerce_shipping_zone_method_deleted',
				'woocommerce_shipping_zone_method_status_toggled',
				'woocommerce_delete_shipping_zone',
				'woocommerce_update_options_shipping',
				'update_option_woocommerce_ship_to_countries',
				'update_option_woocommerce_pickup_location_settings',
				'update_option_pickup_location_pickup_locations',
				'update_option_lafka_shipping_areas_branches',
			) as $hook ) {
				add_action( $hook, array( __CLASS__, 'bust_modes_cache' ) );
			}
		}

		/* ------------------------------------------------------------------ *
		 *  Pickup methods
		 * ------------------------------------------------------------------ */

		/**
		 * The shipping method ids that are a customer pickup: WooCommerce's
		 * classic `local_pickup` and the blocks checkout's `pickup_location`.
		 * The one list: lafka_is_pickup_shipping_method(), the checkout form,
		 * the cart templates and the scripts (through their config) read it.
		 *
		 * @since 10.4.0
		 *
		 * @return string[]
		 */
		public static function pickup_method_ids(): array {
			/**
			 * Filter which shipping method ids are a customer pickup.
			 *
			 * @since 10.1.0
			 * @param string[] $ids Method ids.
			 */
			return array_values( array_map( 'strval', (array) apply_filters( 'lafka_pickup_shipping_method_ids', array( 'local_pickup', 'pickup_location' ) ) ) );
		}

		/* ------------------------------------------------------------------ *
		 *  Modes
		 * ------------------------------------------------------------------ */

		/**
		 * The fulfilment modes the store offers.
		 *
		 * @return string[]
		 */
		public static function modes(): array {
			if ( null !== self::$modes ) {
				return self::$modes;
			}

			$modes = self::branch_selection_active() ? self::branch_modes() : self::shipping_modes();

			/**
			 * Filter the fulfilment modes the store offers.
			 *
			 * @since 10.2.0
			 * @param string[] $modes Subset of ['pickup', 'delivery'].
			 */
			$modes = (array) apply_filters( 'lafka_fulfilment_modes', $modes );

			self::$modes = array_values( array_intersect( self::ALL_MODES, array_map( 'strval', $modes ) ) );

			return self::$modes;
		}

		/**
		 * Whether the operator collects branch + order type.
		 *
		 * @return bool
		 */
		private static function branch_selection_active(): bool {
			return class_exists( 'Lafka_Checkout_Fields' ) && Lafka_Checkout_Fields::is_branch_selection_active();
		}

		/**
		 * Modes from the site-wide order types of the branch module.
		 *
		 * @return string[]
		 */
		private static function branch_modes(): array {
			return array_values( array_intersect( self::ALL_MODES, Lafka_Checkout_Fields::get_site_order_types() ) );
		}

		/**
		 * Modes from the enabled shipping methods of every zone (and the
		 * block-checkout pickup locations). Cached in a transient keyed by
		 * WooCommerce's "shipping" transient version, which it bumps on every
		 * zone/method change; the hooks in init() delete it as well.
		 *
		 * @return string[]
		 */
		private static function shipping_modes(): array {
			if ( 'disabled' === get_option( 'woocommerce_ship_to_countries' ) ) {
				return array();
			}

			$version = class_exists( 'WC_Cache_Helper' ) ? (string) WC_Cache_Helper::get_transient_version( 'shipping' ) : '';
			$cached  = get_transient( self::MODES_TRANSIENT );
			if ( is_array( $cached ) && isset( $cached['version'], $cached['modes'] ) && $cached['version'] === $version && is_array( $cached['modes'] ) ) {
				return $cached['modes'];
			}

			$found = array();
			foreach ( self::enabled_method_ids() as $method_id ) {
				$found[ lafka_is_pickup_shipping_method( $method_id ) ? 'pickup' : 'delivery' ] = true;
			}
			if ( self::blocks_pickup_enabled() ) {
				$found['pickup'] = true;
			}
			$modes = array_values( array_intersect( self::ALL_MODES, array_keys( $found ) ) );

			set_transient(
				self::MODES_TRANSIENT,
				array(
					'version' => $version,
					'modes'   => $modes,
				),
				DAY_IN_SECONDS
			);

			return $modes;
		}

		/**
		 * Ids of the enabled shipping methods across all zones.
		 *
		 * @return string[]
		 */
		private static function enabled_method_ids(): array {
			if ( ! class_exists( 'WC_Shipping_Zones' ) ) {
				return array();
			}
			$methods = array();
			foreach ( (array) WC_Shipping_Zones::get_zones() as $zone ) {
				foreach ( (array) ( $zone['shipping_methods'] ?? array() ) as $method ) {
					$methods[] = $method;
				}
			}
			$rest_of_world = WC_Shipping_Zones::get_zone( 0 );
			if ( is_object( $rest_of_world ) && method_exists( $rest_of_world, 'get_shipping_methods' ) ) {
				foreach ( (array) $rest_of_world->get_shipping_methods( true ) as $method ) {
					$methods[] = $method;
				}
			}

			$ids = array();
			foreach ( $methods as $method ) {
				if ( ! is_object( $method ) || empty( $method->id ) ) {
					continue;
				}
				$enabled = method_exists( $method, 'is_enabled' ) ? $method->is_enabled() : ( 'yes' === ( $method->enabled ?? '' ) );
				if ( $enabled ) {
					$ids[] = (string) $method->id;
				}
			}

			return $ids;
		}

		/**
		 * Whether the block checkout's Local Pickup is on with a location.
		 *
		 * @return bool
		 */
		private static function blocks_pickup_enabled(): bool {
			$settings = get_option( 'woocommerce_pickup_location_settings', array() );
			if ( ! is_array( $settings ) || 'yes' !== ( $settings['enabled'] ?? 'no' ) ) {
				return false;
			}
			foreach ( (array) get_option( 'pickup_location_pickup_locations', array() ) as $location ) {
				if ( is_array( $location ) && ! empty( $location['enabled'] ) ) {
					return true;
				}
			}

			return false;
		}

		/**
		 * Drop the cached zone scan (shipping settings changed).
		 *
		 * @return void
		 */
		public static function bust_modes_cache() {
			delete_transient( self::MODES_TRANSIENT );
			self::$modes = null;
		}

		/**
		 * Forget the per-request caches.
		 *
		 * @return void
		 */
		public static function flush(): void {
			self::$modes = null;
		}

		/* ------------------------------------------------------------------ *
		 *  Preference
		 * ------------------------------------------------------------------ */

		/**
		 * The customer's preference: the branch session's validated order type,
		 * else the cookie; '' when unset or not a mode the store offers.
		 *
		 * @return string
		 */
		public static function preference(): string {
			$preference = self::branch_session_order_type();
			if ( '' === $preference && isset( $_COOKIE[ self::COOKIE ] ) ) {
				$preference = sanitize_key( wp_unslash( (string) $_COOKIE[ self::COOKIE ] ) );
			}
			if ( ! in_array( $preference, self::ALL_MODES, true ) ) {
				$preference = '';
			}
			if ( '' !== $preference && ! in_array( $preference, self::modes(), true ) ) {
				$preference = '';
			}

			/**
			 * Filter the customer's fulfilment preference.
			 *
			 * @since 10.2.0
			 * @param string $preference 'pickup', 'delivery' or ''.
			 */
			$preference = (string) apply_filters( 'lafka_fulfilment_preference', $preference );

			return in_array( $preference, self::ALL_MODES, true ) ? $preference : '';
		}

		/**
		 * The mode in force for this visitor: their preference when set (and
		 * offered), else the first mode the store offers, '' when it offers none.
		 *
		 * @since 10.4.0
		 *
		 * @return string 'pickup', 'delivery' or ''.
		 */
		public static function current_mode(): string {
			$mode = self::preference();
			if ( '' !== $mode ) {
				return $mode;
			}
			$modes = self::modes();

			return array() === $modes ? '' : (string) $modes[0];
		}

		/**
		 * Order type of the branch session ('' when none).
		 *
		 * @return string
		 */
		private static function branch_session_order_type(): string {
			$session = self::session();
			$branch  = null === $session ? null : $session->get( 'lafka_branch_location' );

			return is_array( $branch ) ? (string) ( $branch['order_type'] ?? '' ) : '';
		}

		/* ------------------------------------------------------------------ *
		 *  Rate preselection
		 * ------------------------------------------------------------------ */

		/**
		 * woocommerce_shipping_chosen_method: the default rate for a package.
		 * WooCommerce calls it only when nothing valid is chosen or the rates
		 * changed.
		 *
		 * @param mixed $default       WooCommerce's default rate id.
		 * @param mixed $rates         Package rates keyed by rate id.
		 * @param mixed $chosen_method Previously chosen rate id (or false).
		 * @return mixed
		 */
		public static function filter_chosen_method( $default_method, $rates, $chosen_method = false ) {
			if ( ! is_array( $rates ) || empty( $rates ) ) {
				return $default_method;
			}

			$pick       = $default_method;
			$preference = self::preselect_enabled() ? self::preference() : '';
			// The "Delivery" placeholder (price waiting for the address) was the
			// choice and the real delivery rates just replaced it: the customer
			// chose delivery, so the first real delivery rate takes over.
			if ( class_exists( 'Lafka_Delivery_Quote_Guard' ) && Lafka_Delivery_Quote_Guard::is_placeholder( $chosen_method ) && ! array_key_exists( (string) $chosen_method, $rates ) ) {
				$preference    = 'delivery';
				$chosen_method = false;
			}
			if ( '' !== $preference && ! self::is_customer_choice( $chosen_method, $rates ) ) {
				$match = self::first_rate_for( $preference, $rates );
				if ( '' !== $match ) {
					$pick = $match;
				}
			}
			self::remember_automatic( $pick, $preference );

			return $pick;
		}

		/**
		 * Whether the preference preselects shipping rates at all.
		 *
		 * @return bool
		 */
		public static function preselect_enabled(): bool {
			/**
			 * Filter whether the fulfilment preference preselects the matching
			 * shipping rate (default true).
			 *
			 * @since 10.2.0
			 * @param bool $enabled Preselect on/off.
			 */
			return (bool) apply_filters( 'lafka_fulfilment_preselect_enabled', true );
		}

		/**
		 * Whether a chosen rate is still offered and was picked by the customer
		 * (not the automatic default this class last handed back).
		 *
		 * @param mixed $chosen_method Chosen rate id.
		 * @param array $rates         Package rates keyed by rate id.
		 * @return bool
		 */
		private static function is_customer_choice( $chosen_method, array $rates ): bool {
			if ( ! is_string( $chosen_method ) || '' === $chosen_method || ! array_key_exists( $chosen_method, $rates ) ) {
				return false;
			}

			return self::automatic_rate() !== $chosen_method;
		}

		/**
		 * First rate id matching a mode.
		 *
		 * @param string $mode  'pickup' | 'delivery'.
		 * @param array  $rates Package rates keyed by rate id.
		 * @return string
		 */
		private static function first_rate_for( string $mode, array $rates ): string {
			foreach ( $rates as $rate_id => $rate ) {
				$method_id = is_object( $rate ) && method_exists( $rate, 'get_method_id' ) ? (string) $rate->get_method_id() : (string) $rate_id;
				if ( ( 'pickup' === $mode ) === lafka_is_pickup_shipping_method( $method_id ) ) {
					return (string) $rate_id;
				}
			}

			return '';
		}

		/**
		 * Remember an automatic default and the preference it was made for.
		 *
		 * @param mixed  $rate_id    Rate id handed back.
		 * @param string $preference Preference at the time.
		 * @return void
		 */
		private static function remember_automatic( $rate_id, string $preference ): void {
			$session = self::session();
			if ( null === $session || ! is_string( $rate_id ) || '' === $rate_id ) {
				return;
			}
			if ( self::automatic_rate() !== $rate_id ) {
				$session->set( self::SESSION_AUTO, $rate_id );
			}
			if ( $session->get( self::SESSION_APPLIED ) !== $preference ) {
				$session->set( self::SESSION_APPLIED, $preference );
			}
		}

		/**
		 * The rate id last handed back as the automatic default ('' = none).
		 *
		 * @return string
		 */
		private static function automatic_rate(): string {
			$session = self::session();
			$auto    = null === $session ? '' : $session->get( self::SESSION_AUTO );

			return is_string( $auto ) ? $auto : '';
		}

		/**
		 * woocommerce_cart_loaded_from_session: after the preference changed,
		 * release a chosen rate that was only ever an automatic default, so
		 * WooCommerce re-defaults it through the preference. Page loads only.
		 *
		 * @return void
		 */
		public static function maybe_release_automatic_choice() {
			if ( ! self::is_page_load() || ! self::preselect_enabled() ) {
				return;
			}
			$session = self::session();
			if ( null === $session ) {
				return;
			}
			$preference = self::preference();
			if ( '' === $preference || $session->get( self::SESSION_APPLIED ) === $preference ) {
				return;
			}
			$chosen = $session->get( 'chosen_shipping_methods' );
			if ( ! is_array( $chosen ) || array() === $chosen ) {
				return;
			}
			$auto = self::automatic_rate();
			foreach ( $chosen as $rate_id ) {
				if ( '' === $auto || $rate_id !== $auto ) {
					return;
				}
			}
			$session->set( 'chosen_shipping_methods', array() );
		}

		/**
		 * Whether a customer who chose Delivery would silently get a pickup order:
		 * their preference is delivery, every rate they chose is a pickup rate, and
		 * no package offers a delivery rate at all (out of range, or the distance
		 * lookup failed). WooCommerce falls back to the only rate left, Pickup,
		 * without a word, and the kitchen would hold the food while the customer
		 * waits at home. A customer who picked Pickup over an offered delivery rate
		 * is not stopped. One decision for the classic and the block checkout.
		 *
		 * @since 10.4.0
		 *
		 * @param string[] $chosen_rate_ids Rate ids chosen for the order's packages.
		 * @return bool
		 */
		public static function delivery_would_become_pickup( array $chosen_rate_ids ): bool {
			if ( 'delivery' !== self::preference() || array() === $chosen_rate_ids ) {
				return false;
			}
			foreach ( $chosen_rate_ids as $rate_id ) {
				if ( ! lafka_is_pickup_shipping_method( (string) $rate_id ) ) {
					return false;
				}
			}

			return ! lafka_shipping_has_delivery_rate();
		}

		/**
		 * Why delivery is not on offer, for the customer: the distance method's own
		 * reason (we cannot find that address / we do not deliver that far) when it
		 * left one, else the plain "we can't deliver to this address". '' while a
		 * delivery rate exists, or when the customer has not asked for delivery and
		 * no method left a reason. The classic checkout's error, the block
		 * checkout's notice (Lafka's `lafka` cart extension) and the block
		 * checkout's place-order refusal all read this.
		 *
		 * @since 10.4.0
		 *
		 * @return string Plain text.
		 */
		public static function delivery_unavailable_message(): string {
			$reason = class_exists( 'Lafka_Distance_Shipping' ) ? Lafka_Distance_Shipping::reason_message() : '';
			if ( '' !== $reason ) {
				return $reason;
			}
			if ( 'delivery' !== self::preference() || lafka_shipping_has_delivery_rate() ) {
				return '';
			}

			return __( 'We can\'t deliver to this address. Check the street and postcode, or choose Pickup to collect your order.', 'lafka-plugin' );
		}

		/**
		 * Classic checkout: refuse a Delivery choice that would become a pickup order.
		 *
		 * @param array    $data   Posted checkout data.
		 * @param WP_Error $errors Validation errors.
		 * @return void
		 */
		public static function validate_classic_checkout( $data, $errors ) {
			if ( ! is_object( $errors ) || ! method_exists( $errors, 'add' ) ) {
				return;
			}
			$chosen = is_array( $data ) && isset( $data['shipping_method'] ) ? array_map( 'strval', (array) $data['shipping_method'] ) : array();
			if ( self::delivery_would_become_pickup( $chosen ) ) {
				$errors->add( 'shipping', self::delivery_unavailable_message() );
			}
		}

		/**
		 * Block (Store API) checkout: the same refusal at place-order, so a Delivery
		 * choice never silently becomes a pickup order there either.
		 *
		 * @since 10.4.0
		 *
		 * @param mixed $order WC_Order being placed.
		 * @return void
		 *
		 * @throws \Automattic\WooCommerce\StoreApi\Exceptions\RouteException When delivery was chosen but only pickup is on offer.
		 * @throws \RuntimeException Fallback without the Store API.
		 */
		public static function validate_store_api_checkout( $order ) {
			if ( ! is_object( $order ) || ! method_exists( $order, 'get_shipping_methods' ) ) {
				return;
			}
			$chosen = array();
			foreach ( (array) $order->get_shipping_methods() as $item ) {
				if ( is_object( $item ) && method_exists( $item, 'get_method_id' ) ) {
					$chosen[] = (string) $item->get_method_id();
				}
			}
			if ( ! self::delivery_would_become_pickup( $chosen ) ) {
				return;
			}
			$message = self::delivery_unavailable_message();
			if ( class_exists( '\Automattic\WooCommerce\StoreApi\Exceptions\RouteException' ) ) {
				throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'lafka_delivery_unavailable', esc_html( $message ), 400 );
			}
			throw new \RuntimeException( esc_html( $message ) );
		}

		/**
		 * A plain GET page load: not a form POST, AJAX or REST request.
		 *
		 * @return bool
		 */
		private static function is_page_load(): bool {
			$method = isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( (string) $_SERVER['REQUEST_METHOD'] ) ) ) : '';
			if ( 'GET' !== $method ) {
				return false;
			}
			if ( wp_doing_ajax() ) {
				return false;
			}

			return ! ( defined( 'REST_REQUEST' ) && REST_REQUEST );
		}

		/**
		 * The WooCommerce session, when there is one.
		 *
		 * @return object|null
		 */
		private static function session() {
			$wc = function_exists( 'WC' ) ? WC() : null;

			return ( is_object( $wc ) && isset( $wc->session ) && is_object( $wc->session ) ) ? $wc->session : null;
		}
	}
}

require_once __DIR__ . '/lafka-fulfilment-functions.php';
