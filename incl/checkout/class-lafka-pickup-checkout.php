<?php
/**
 * Lafka_Pickup_Checkout — a pickup order asks for name, phone and email only.
 *
 * Most restaurant orders are collected, yet WooCommerce's checkout still asks
 * a pickup customer for a full billing address (eleven fields on a phone).
 * When the order is a pickup — the Lafka order type is "pickup", or every
 * chosen shipping method is a pickup method — and the chosen payment method
 * does not verify the billing address, the address fields (company, street,
 * city, postcode; and country/state when the store sells to its own country
 * only) become optional and are hidden. Name, phone and email stay.
 *
 * The billing address stays REQUIRED whenever it matters:
 *   · delivery orders (with "ship to billing address" it is the delivery
 *     address), and
 *   · gateways that check it — card processors run AVS, and an empty address
 *     is declined. Only offline gateways (cash on delivery, cheque, bank
 *     transfer) skip it by default; everything else keeps the address.
 * Empty billing country/state default to the store base on pickup orders.
 *
 * Classic checkout: the field filter decides requiredness per request (the
 * posted shipping/payment choice when the order is submitted, the session
 * otherwise) and tags the slimmable rows; a small script hides/shows them and
 * flips their required markers as the customer changes shipping or payment
 * method, with an "Add a delivery address" link so a customer on pickup can
 * still reveal the address and get a delivery quote.
 *
 * Block checkout: WooCommerce renders the core address fields from the
 * country locale, shared by the shipping and billing forms and fixed at page
 * load, so they cannot be hidden per shipping method. In blocks mode the
 * address fields are therefore marked optional in the locale, and the rule
 * above is enforced server-side on the Store API place-order request (a
 * delivery or card order without the address is rejected with a clear error).
 *
 * Operator surface:
 *   · Customizer → Lafka — Checkout → "Short checkout for pickup orders"
 *     (theme_mod `lafka_pickup_checkout_slim`, default on)
 *   · `lafka_pickup_checkout_slim_enabled` (bool)
 *   · `lafka_pickup_checkout_hidden_fields` (string[], bool $single_country)
 *   · `lafka_pickup_address_optional_gateways` (string[], default cod/cheque/bacs)
 *   · `lafka_pickup_gateway_needs_billing_address` (bool, string $gateway_id)
 *
 * @package Lafka\Plugin\Checkout
 * @since   10.2.0
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/../lafka-shipping-method-helpers.php';

if ( ! class_exists( 'Lafka_Pickup_Checkout' ) ) {

	/**
	 * Slim checkout for pickup orders (classic + block checkout).
	 */
	final class Lafka_Pickup_Checkout {

		/**
		 * Theme mod: slimming on/off (default on).
		 */
		const MOD_ENABLED = 'lafka_pickup_checkout_slim';

		/**
		 * Script handle for the classic-checkout toggle.
		 */
		const SCRIPT_HANDLE = 'lafka-pickup-checkout';

		/**
		 * Class added to every slimmable classic field row.
		 */
		const FIELD_CLASS = 'lafka-pickup-slim-field';

		/**
		 * Locale keys relaxed on the block checkout.
		 */
		const BLOCK_ADDRESS_KEYS = array( 'address_1', 'city', 'postcode', 'state' );

		/**
		 * Required flag of each slimmable field before this module touched it
		 * (collected by the field filter for the client toggle).
		 *
		 * @var array<string, bool>
		 */
		private static $original_required = array();

		/**
		 * Wire the classic + block checkout hooks.
		 *
		 * @return void
		 */
		public static function init() {
			add_filter( 'woocommerce_checkout_fields', array( __CLASS__, 'filter_checkout_fields' ), 20 );
			add_filter( 'woocommerce_checkout_posted_data', array( __CLASS__, 'fill_base_location' ) );
			add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_script' ) );
			add_action( 'woocommerce_after_checkout_form', array( __CLASS__, 'print_client_config' ) );
			add_filter( 'woocommerce_get_country_locale', array( __CLASS__, 'relax_block_locale' ), 20 );
			add_filter( 'woocommerce_get_country_locale_default', array( __CLASS__, 'relax_block_locale_fields' ), 20 );
			add_filter( 'woocommerce_get_country_locale_base', array( __CLASS__, 'relax_block_locale_fields' ), 20 );
			add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( __CLASS__, 'on_store_api_checkout' ), 20, 2 );
		}

		/* ------------------------------------------------------------------ *
		 *  Decisions
		 * ------------------------------------------------------------------ */

		/**
		 * Whether pickup slimming is on.
		 *
		 * @return bool
		 */
		public static function is_enabled(): bool {
			$enabled = function_exists( 'get_theme_mod' ) ? (bool) get_theme_mod( self::MOD_ENABLED, true ) : true;

			/**
			 * Filter whether pickup orders get the short checkout.
			 *
			 * @param bool $enabled Customizer value (default true).
			 */
			return (bool) apply_filters( 'lafka_pickup_checkout_slim_enabled', $enabled );
		}

		/**
		 * Whether a payment gateway needs the billing address (e.g. card AVS).
		 *
		 * @param string $gateway_id Payment gateway id ('' = not chosen yet).
		 * @return bool
		 */
		public static function gateway_needs_billing_address( string $gateway_id ): bool {
			/**
			 * Filter the gateways that do NOT need a billing address on a pickup
			 * order. Default: the offline core gateways (cash, cheque, bank
			 * transfer). Every other gateway keeps the address, because card
			 * processors verify it (AVS) and decline an empty one.
			 *
			 * @param string[] $gateway_ids Gateway ids.
			 */
			$optional = array_map( 'strval', (array) apply_filters( 'lafka_pickup_address_optional_gateways', array( 'cod', 'cheque', 'bacs' ) ) );
			$needs    = '' === $gateway_id || ! in_array( $gateway_id, $optional, true );

			/**
			 * Filter whether one gateway needs the billing address on a pickup order.
			 *
			 * @param bool   $needs      Default decision.
			 * @param string $gateway_id Gateway id.
			 */
			return (bool) apply_filters( 'lafka_pickup_gateway_needs_billing_address', $needs, $gateway_id );
		}

		/**
		 * Whether the order is a pickup. The Lafka order type (branch/order-type
		 * selector) decides when set; otherwise every chosen shipping method
		 * must be a pickup method.
		 *
		 * @param string[] $chosen_methods Chosen shipping rate ids.
		 * @param string   $order_type     Lafka order type ('pickup', 'delivery' or '').
		 * @return bool
		 */
		public static function is_pickup( array $chosen_methods, string $order_type ): bool {
			return 'pickup' === lafka_fulfilment_type_for( $chosen_methods, $order_type );
		}

		/**
		 * Whether the billing address can be skipped for this order.
		 *
		 * @param bool   $is_pickup     Pickup order.
		 * @param string $gateway_id    Chosen payment gateway.
		 * @param bool   $needs_payment Whether the order is paid at all.
		 * @return bool
		 */
		public static function should_slim( bool $is_pickup, string $gateway_id, bool $needs_payment ): bool {
			if ( ! $is_pickup || ! self::is_enabled() ) {
				return false;
			}

			return ! $needs_payment || ! self::gateway_needs_billing_address( $gateway_id );
		}

		/**
		 * Whether the store sells only to its own base country.
		 *
		 * @return bool
		 */
		public static function sells_to_base_country_only(): bool {
			$countries = self::countries();
			if ( null === $countries ) {
				return false;
			}
			$allowed = array_keys( (array) $countries->get_allowed_countries() );

			return array( (string) $countries->get_base_country() ) === array_map( 'strval', $allowed );
		}

		/**
		 * The billing fields a pickup order can skip.
		 *
		 * @param bool $single_country Store sells to its base country only.
		 * @return string[]
		 */
		public static function slimmable_fields( bool $single_country ): array {
			$fields = array( 'billing_company', 'billing_address_1', 'billing_address_2', 'billing_city', 'billing_postcode' );
			if ( $single_country ) {
				$fields = array_merge( $fields, array( 'billing_country', 'billing_state' ) );
			}

			/**
			 * Filter the billing fields a pickup order hides.
			 *
			 * @param string[] $fields         Field keys (billing_*).
			 * @param bool     $single_country Whether the store sells to its base country only.
			 */
			return array_values( array_map( 'strval', (array) apply_filters( 'lafka_pickup_checkout_hidden_fields', $fields, $single_country ) ) );
		}

		/* ------------------------------------------------------------------ *
		 *  Classic checkout
		 * ------------------------------------------------------------------ */

		/**
		 * woocommerce_checkout_fields: tag the slimmable rows and, for a pickup
		 * order that can skip the address, make them optional.
		 *
		 * @param mixed $fields Checkout fields grouped by fieldset.
		 * @return mixed
		 */
		public static function filter_checkout_fields( $fields ) {
			if ( ! is_array( $fields ) || empty( $fields['billing'] ) || ! is_array( $fields['billing'] ) || ! self::is_enabled() ) {
				return $fields;
			}

			$slim = self::should_slim( self::current_is_pickup(), self::current_gateway(), self::cart_needs_payment() );

			foreach ( self::slimmable_fields( self::sells_to_base_country_only() ) as $key ) {
				if ( ! isset( $fields['billing'][ $key ] ) || ! is_array( $fields['billing'][ $key ] ) ) {
					continue;
				}
				$field = $fields['billing'][ $key ];

				self::$original_required[ $key ] = ! empty( $field['required'] );

				$classes        = isset( $field['class'] ) && is_array( $field['class'] ) ? $field['class'] : array();
				$classes[]      = self::FIELD_CLASS;
				$field['class'] = array_values( array_unique( $classes ) );
				if ( $slim ) {
					$field['required'] = false;
				}
				$fields['billing'][ $key ] = $field;
			}

			return $fields;
		}

		/**
		 * woocommerce_checkout_posted_data: on a pickup order, an empty billing
		 * country/state (hidden fields) falls back to the store base.
		 *
		 * @param mixed $data Posted checkout data.
		 * @return mixed
		 */
		public static function fill_base_location( $data ) {
			if ( ! is_array( $data ) || ! self::is_enabled() || ! self::current_is_pickup() ) {
				return $data;
			}
			$countries = self::countries();
			if ( null === $countries ) {
				return $data;
			}
			$base_country = (string) $countries->get_base_country();

			if ( '' === (string) ( $data['billing_country'] ?? '' ) ) {
				$data['billing_country'] = $base_country;
			}
			if ( '' === (string) ( $data['billing_state'] ?? '' ) && $base_country === $data['billing_country'] ) {
				$data['billing_state'] = (string) $countries->get_base_state();
			}

			return $data;
		}

		/**
		 * Enqueue the classic-checkout toggle (classic checkout page only).
		 *
		 * @return void
		 */
		public static function enqueue_script() {
			if ( ! self::is_enabled() || ! self::is_classic_checkout_page() ) {
				return;
			}
			$min      = 'incl/checkout/assets/js/lafka-pickup-checkout.min.js';
			$relative = function_exists( 'lafka_plugin_script_path' ) ? lafka_plugin_script_path( $min ) : $min;
			wp_enqueue_script(
				self::SCRIPT_HANDLE,
				plugins_url( $relative, LAFKA_PLUGIN_FILE ),
				array( 'jquery' ),
				function_exists( 'lafka_plugin_asset_version' ) ? lafka_plugin_asset_version( $relative ) : null,
				true
			);
		}

		/**
		 * Hand the toggle its configuration once the checkout form (and so the
		 * field filter) has rendered; the script itself prints in the footer.
		 *
		 * @return void
		 */
		public static function print_client_config() {
			if ( ! self::is_enabled() || ! function_exists( 'wp_add_inline_script' ) ) {
				return;
			}
			wp_add_inline_script(
				self::SCRIPT_HANDLE,
				'window.lafkaPickupCheckout = ' . wp_json_encode( self::client_config() ) . ';',
				'before'
			);
		}

		/**
		 * Configuration for the classic-checkout toggle.
		 *
		 * @return array
		 */
		public static function client_config(): array {
			$fields = array();
			foreach ( self::$original_required as $key => $required ) {
				$fields[] = array(
					'id'       => $key,
					'required' => $required,
				);
			}

			$address_gateways = array();
			foreach ( self::available_gateway_ids() as $gateway_id ) {
				if ( self::gateway_needs_billing_address( $gateway_id ) ) {
					$address_gateways[] = $gateway_id;
				}
			}

			return array(
				'enabled'         => true,
				'orderType'       => self::current_order_type(),
				'pickupMethods'   => array_values( array_map( 'strval', (array) apply_filters( 'lafka_pickup_shipping_method_ids', array( 'local_pickup', 'pickup_location' ) ) ) ),
				'addressGateways' => $address_gateways,
				'fields'          => $fields,
				'i18n'            => array(
					'required'   => __( 'required', 'lafka-plugin' ),
					'optional'   => __( '(optional)', 'lafka-plugin' ),
					'addAddress' => __( 'Want delivery? Add your address', 'lafka-plugin' ),
				),
			);
		}

		/* ------------------------------------------------------------------ *
		 *  Block checkout (Store API)
		 * ------------------------------------------------------------------ */

		/**
		 * woocommerce_get_country_locale: in blocks mode, mark the address
		 * fields optional for every country (see the class docblock).
		 *
		 * @param mixed $locale Country locales.
		 * @return mixed
		 */
		public static function relax_block_locale( $locale ) {
			if ( ! is_array( $locale ) || ! self::relaxes_block_locale() ) {
				return $locale;
			}
			foreach ( $locale as $country => $fields ) {
				$locale[ $country ] = self::optional_address_fields( is_array( $fields ) ? $fields : array() );
			}

			return $locale;
		}

		/**
		 * woocommerce_get_country_locale_default / _base: the same relaxation
		 * for the default field set (merged under every country).
		 *
		 * @param mixed $fields Default locale fields.
		 * @return mixed
		 */
		public static function relax_block_locale_fields( $fields ) {
			if ( ! is_array( $fields ) || ! self::relaxes_block_locale() ) {
				return $fields;
			}

			return self::optional_address_fields( $fields );
		}

		/**
		 * Store API place order: enforce the billing-address rule the relaxed
		 * locale no longer enforces, and fill the base country/state on pickup.
		 *
		 * @param mixed $order   WC_Order being placed.
		 * @param mixed $request WP_REST_Request (payment_method).
		 * @return void
		 *
		 * @throws \Automattic\WooCommerce\StoreApi\Exceptions\RouteException When the address is required but missing.
		 */
		public static function on_store_api_checkout( $order, $request ) {
			if ( ! is_object( $order ) || ! method_exists( $order, 'get_address' ) || ! self::relaxes_block_locale() ) {
				return;
			}

			$gateway = '';
			if ( is_object( $request ) && $request instanceof ArrayAccess && isset( $request['payment_method'] ) ) {
				$gateway = sanitize_text_field( (string) $request['payment_method'] );
			} elseif ( method_exists( $order, 'get_payment_method' ) ) {
				$gateway = (string) $order->get_payment_method();
			}

			$is_pickup = self::current_is_pickup();
			$billing   = (array) $order->get_address( 'billing' );

			if ( ! self::should_slim( $is_pickup, $gateway, self::cart_needs_payment() ) ) {
				if ( array() !== self::missing_address_fields( $billing ) ) {
					self::throw_route_exception(
						'lafka_billing_address_required',
						__( 'Please enter your billing street address, city and postcode.', 'lafka-plugin' )
					);
				}
				return;
			}

			if ( ! $is_pickup ) {
				return;
			}
			$countries = self::countries();
			if ( null === $countries ) {
				return;
			}
			$base_country = (string) $countries->get_base_country();
			$country      = (string) ( $billing['country'] ?? '' );
			if ( '' === $country ) {
				$order->set_billing_country( $base_country );
				$country = $base_country;
			}
			if ( '' === (string) ( $billing['state'] ?? '' ) && $country === $base_country ) {
				$order->set_billing_state( (string) $countries->get_base_state() );
			}
		}

		/**
		 * Address keys missing from an address (postcode only where the
		 * country uses one — the delivery quote guard's rule).
		 *
		 * @param array $address WC address array.
		 * @return string[]
		 */
		public static function missing_address_fields( array $address ): array {
			$required = array( 'address_1', 'city', 'postcode' );
			if ( class_exists( 'Lafka_Delivery_Quote_Guard' )
				&& ! in_array( 'postcode', Lafka_Delivery_Quote_Guard::required_fields( (string) ( $address['country'] ?? '' ) ), true ) ) {
				$required = array( 'address_1', 'city' );
			}
			$missing = array();
			foreach ( $required as $key ) {
				if ( '' === trim( (string) ( $address[ $key ] ?? '' ) ) ) {
					$missing[] = $key;
				}
			}

			return $missing;
		}

		/* ------------------------------------------------------------------ *
		 *  Request context
		 * ------------------------------------------------------------------ */

		/**
		 * Whether this request's order is a pickup: the posted shipping choice
		 * when the classic checkout is submitted, the session otherwise.
		 *
		 * @return bool
		 */
		private static function current_is_pickup(): bool {
			return 'pickup' === lafka_current_fulfilment_type();
		}

		/**
		 * The Lafka order type in the session ('' when none was chosen).
		 *
		 * @return string
		 */
		private static function current_order_type(): string {
			$session = self::session();
			$branch  = null === $session ? null : $session->get( 'lafka_branch_location' );

			return is_array( $branch ) ? (string) ( $branch['order_type'] ?? '' ) : '';
		}

		/**
		 * The payment gateway of this request: posted, else the session's,
		 * else the first available one (WooCommerce pre-selects it).
		 *
		 * @return string
		 */
		private static function current_gateway(): string {
			// phpcs:ignore WordPress.Security.NonceVerification.Missing -- read-only decision; WooCommerce verifies the checkout nonce before it validates or saves these fields.
			if ( isset( $_POST['payment_method'] ) ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Missing -- see above.
				return sanitize_text_field( (string) wp_unslash( $_POST['payment_method'] ) );
			}
			$session = self::session();
			$chosen  = null === $session ? '' : (string) $session->get( 'chosen_payment_method' );
			if ( '' !== $chosen ) {
				return $chosen;
			}
			$available = self::available_gateway_ids();

			return (string) ( reset( $available ) ?: '' );
		}

		/**
		 * Ids of the gateways available for this cart.
		 *
		 * @return string[]
		 */
		private static function available_gateway_ids(): array {
			$wc = function_exists( 'WC' ) ? WC() : null;
			if ( ! is_object( $wc ) || ! method_exists( $wc, 'payment_gateways' ) ) {
				return array();
			}
			$gateways = $wc->payment_gateways();
			if ( ! is_object( $gateways ) || ! method_exists( $gateways, 'get_available_payment_gateways' ) ) {
				return array();
			}

			return array_map( 'strval', array_keys( (array) $gateways->get_available_payment_gateways() ) );
		}

		/**
		 * Whether the cart needs payment (a free order skips gateways).
		 *
		 * @return bool
		 */
		private static function cart_needs_payment(): bool {
			$wc = function_exists( 'WC' ) ? WC() : null;
			if ( ! is_object( $wc ) || ! isset( $wc->cart ) || ! is_object( $wc->cart ) || ! method_exists( $wc->cart, 'needs_payment' ) ) {
				return true;
			}

			return (bool) $wc->cart->needs_payment();
		}

		/**
		 * Whether the block-checkout relaxation applies.
		 *
		 * @return bool
		 */
		private static function relaxes_block_locale(): bool {
			return self::is_enabled() && class_exists( 'Lafka_Checkout_Mode' ) && Lafka_Checkout_Mode::is_blocks();
		}

		/**
		 * Whether this is the classic checkout form page (not order-received).
		 *
		 * @return bool
		 */
		private static function is_classic_checkout_page(): bool {
			if ( ! function_exists( 'is_checkout' ) || ! is_checkout() ) {
				return false;
			}
			if ( function_exists( 'is_wc_endpoint_url' ) && is_wc_endpoint_url( 'order-received' ) ) {
				return false;
			}

			return ! ( class_exists( 'Lafka_Checkout_Mode' ) && Lafka_Checkout_Mode::is_blocks() );
		}

		/**
		 * Mark the relaxed address keys optional in one locale entry.
		 *
		 * @param array $fields Locale field overrides.
		 * @return array
		 */
		private static function optional_address_fields( array $fields ): array {
			foreach ( self::BLOCK_ADDRESS_KEYS as $key ) {
				$field             = isset( $fields[ $key ] ) && is_array( $fields[ $key ] ) ? $fields[ $key ] : array();
				$field['required'] = false;
				$fields[ $key ]    = $field;
			}

			return $fields;
		}

		/**
		 * WC_Countries, when available.
		 *
		 * @return object|null
		 */
		private static function countries() {
			$wc = function_exists( 'WC' ) ? WC() : null;

			return ( is_object( $wc ) && isset( $wc->countries ) && is_object( $wc->countries ) ) ? $wc->countries : null;
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

		/**
		 * Throw a Store API error (generic exception without the Store API).
		 *
		 * @param string $code    Error code.
		 * @param string $message Customer-facing message.
		 * @return void
		 *
		 * @throws \Automattic\WooCommerce\StoreApi\Exceptions\RouteException Store API error.
		 * @throws \RuntimeException Fallback.
		 */
		private static function throw_route_exception( string $code, string $message ): void {
			if ( class_exists( '\Automattic\WooCommerce\StoreApi\Exceptions\RouteException' ) ) {
				throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( esc_html( $code ), esc_html( $message ), 400 );
			}

			throw new \RuntimeException( esc_html( $message ) );
		}
	}
}
