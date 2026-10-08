<?php
/**
 * Lafka_Address_Autocomplete: checkout address suggestions through
 * WooCommerce's own provider system, on the classic and the block checkout.
 *
 * WooCommerce 10.1+ ships the framework: the `woocommerce_address_providers`
 * filter lists providers, the setting in WooCommerce → Settings → General
 * turns suggestions on, and the checkout draws the list (keyboard and screen
 * reader support included). Lafka adds one provider:
 *
 *   server  Lafka_Address_Provider (this folder) names it; Lafka_Address_Search
 *           answers the searches (Google Places with the one Maps key, else
 *           Photon) through lafka/v1 REST routes.
 *   browser assets/js/lafka-address-autocomplete.js registers the matching JS
 *           provider with wc.addressAutocomplete.
 *
 * The chosen suggestion carries a point. It reaches the delivery price the way
 * the checkout pin does, so the same rules apply (the distance resolver
 * trusts it only within `lafka_distance_pin_tolerance_km` of the server's own
 * geocode of the address):
 *   - classic: the hidden `lafka_picked_delivery_geocoded` field of the form,
 *     which Lafka_Distance_Shipping reads on every order-review refresh;
 *   - block: a Store API update (`extensionCartUpdate`, namespace
 *     `lafka-address-autocomplete`) saving the pin in the same WC session slot,
 *     tied to the address it was chosen for.
 *
 * @package Lafka\Plugin\AddressAutocomplete
 * @since   10.4.0
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-lafka-address-search.php';

if ( ! class_exists( 'Lafka_Address_Autocomplete' ) ) {

	/**
	 * Wiring between WooCommerce's provider system and Lafka's search.
	 */
	final class Lafka_Address_Autocomplete {

		/** Store API update namespace. */
		const NAMESPACE = 'lafka-address-autocomplete';

		/** The provider id (also WooCommerce's setting value and the JS provider id). */
		const PROVIDER_ID = 'lafka';

		/** Script handle. */
		const SCRIPT_HANDLE = 'lafka-address-autocomplete';

		/**
		 * Hook everything.
		 *
		 * @return void
		 */
		public static function init(): void {
			add_filter( 'woocommerce_address_providers', array( __CLASS__, 'register_provider' ) );
			add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_script' ), 20 );
			add_action( 'woocommerce_init', array( __CLASS__, 'register_update_callback' ) );
		}

		/**
		 * Add Lafka to WooCommerce's provider list (while the module is on).
		 *
		 * @param mixed $providers Providers (class names or instances).
		 * @return mixed
		 */
		public static function register_provider( $providers ) {
			if ( ! Lafka_Address_Search::module_enabled() || ! is_array( $providers ) ) {
				return $providers;
			}
			require_once __DIR__ . '/class-lafka-address-provider.php';
			if ( class_exists( 'Lafka_Address_Provider' ) ) {
				$providers[] = new Lafka_Address_Provider();
			}

			return $providers;
		}

		/**
		 * Whether this request shows an address form WooCommerce draws
		 * suggestions on and Lafka is the provider it can use.
		 *
		 * @return bool
		 */
		private static function applies(): bool {
			if ( ! Lafka_Address_Search::module_enabled() || ! Lafka_Address_Search::woocommerce_enabled() ) {
				return false;
			}
			if ( ! function_exists( 'is_checkout' ) || ! ( is_checkout() || is_account_page() ) ) {
				return false;
			}
			if ( ! function_exists( 'wc_get_container' ) ) {
				return false;
			}
			$controller = wc_get_container()->get( \Automattic\WooCommerce\Internal\AddressProvider\AddressProviderController::class );

			return is_object( $controller ) && $controller->is_provider_available( self::PROVIDER_ID );
		}

		/**
		 * Load the browser provider after WooCommerce's shared registration
		 * script, on the checkout (either kind) and the account address forms.
		 *
		 * @return void
		 */
		public static function enqueue_script(): void {
			if ( ! self::applies() ) {
				return;
			}
			$min      = 'assets/js/lafka-address-autocomplete.min.js';
			$relative = function_exists( 'lafka_plugin_script_path' ) ? lafka_plugin_script_path( $min ) : $min;
			$deps     = array( 'wc-address-autocomplete-common' );
			// The block checkout's scripts the provider reads at run time (declared so WooCommerce does not warn).
			if ( has_block( 'woocommerce/checkout' ) ) {
				foreach ( array( 'wc-settings', 'wc-blocks-data-store', 'wc-blocks-checkout' ) as $handle ) {
					if ( wp_script_is( $handle, 'registered' ) ) {
						$deps[] = $handle;
					}
				}
			}
			wp_enqueue_script(
				self::SCRIPT_HANDLE,
				plugins_url( $relative, LAFKA_PLUGIN_FILE ),
				$deps,
				function_exists( 'lafka_plugin_asset_version' ) ? lafka_plugin_asset_version( $relative ) : null,
				true
			);
			wp_add_inline_script(
				self::SCRIPT_HANDLE,
				'window.lafkaAddressAutocomplete = ' . wp_json_encode( self::client_config() ) . ';',
				'before'
			);
		}

		/**
		 * What the browser provider needs.
		 *
		 * @return array<string,mixed>
		 */
		public static function client_config(): array {
			/**
			 * Filter the milliseconds the browser waits after the last keystroke
			 * before searching (never below 400: Photon's fair use asks for it).
			 *
			 * @since 10.4.0
			 * @param int $ms Default 400.
			 */
			$debounce = max( 400, (int) apply_filters( 'lafka_address_autocomplete_debounce', 400 ) );

			return array(
				'id'         => self::PROVIDER_ID,
				'suggestUrl' => rest_url( Lafka_Address_Search::REST_NAMESPACE . '/address/suggest' ),
				'placeUrl'   => rest_url( Lafka_Address_Search::REST_NAMESPACE . '/address/place' ),
				'countries'  => Lafka_Address_Search::countries(),
				'minChars'   => Lafka_Address_Search::MIN_CHARS,
				'debounce'   => $debounce,
				'namespace'  => self::NAMESPACE,
				'token'      => Lafka_Address_Search::page_token(),
			);
		}

		/**
		 * Block checkout: let the browser hand over the chosen point.
		 *
		 * @return void
		 */
		public static function register_update_callback(): void {
			if ( ! function_exists( 'woocommerce_store_api_register_update_callback' ) ) {
				return;
			}
			woocommerce_store_api_register_update_callback(
				array(
					'namespace' => self::NAMESPACE,
					'callback'  => array( __CLASS__, 'store_pin' ),
				)
			);
		}

		/**
		 * Save the chosen suggestion's point as the delivery pin, tied to the
		 * address it was chosen for (the same session slot and fingerprint the
		 * classic checkout pin uses, so Lafka_Distance_Resolver treats both
		 * alike, tolerance check included). An address edited afterwards no
		 * longer matches the fingerprint, which ends the pin.
		 *
		 * @param mixed $data lat, lng and address{country,state,postcode,city,address_1}.
		 * @return void
		 */
		public static function store_pin( $data ): void {
			if ( ! is_array( $data ) || ! class_exists( 'Lafka_Distance_Resolver' ) ) {
				return;
			}
			$wc = function_exists( 'WC' ) ? WC() : null;
			if ( ! is_object( $wc ) || ! isset( $wc->session ) || ! is_object( $wc->session ) ) {
				return;
			}
			$point = lafka_geo_point( $data['lat'] ?? null, $data['lng'] ?? null );
			if ( null === $point ) {
				$wc->session->set( Lafka_Distance_Resolver::SESSION_PIN, null );
				return;
			}
			$address = isset( $data['address'] ) && is_array( $data['address'] ) ? $data['address'] : array();
			$parts   = array();
			foreach ( array( 'country', 'state', 'postcode', 'city', 'address_1' ) as $part ) {
				$parts[ $part ] = isset( $address[ $part ] ) && is_scalar( $address[ $part ] ) ? sanitize_text_field( (string) $address[ $part ] ) : '';
			}
			$wc->session->set(
				Lafka_Distance_Resolver::SESSION_PIN,
				array(
					'lat' => $point['lat'],
					'lng' => $point['lng'],
					'fp'  => Lafka_Distance_Resolver::fingerprint( $parts ),
				)
			);
		}
	}

	Lafka_Address_Autocomplete::init();
}
