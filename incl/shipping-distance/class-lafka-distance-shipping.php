<?php
/**
 * Distance-priced delivery wiring: registers the `lafka_distance` shipping
 * method, carries the classic checkout delivery pin into the shipping package,
 * and tells the customer why no delivery rate is offered.
 *
 * The pin (hidden field `lafka_picked_delivery_geocoded` of the checkout pin
 * map) is read from the order-review refresh and from the place-order post,
 * kept in the WC session together with a fingerprint of the address it was
 * placed for, and added to the shipping package, so WooCommerce's own rate
 * cache is keyed by it. A pin never outlives an edit of the address.
 *
 * @package Lafka\Plugin\ShippingDistance
 * @since   10.4.0
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/class-lafka-distance-resolver.php';

if ( ! class_exists( 'Lafka_Distance_Shipping' ) ) {

	/**
	 * Registration and checkout glue for the `lafka_distance` method.
	 */
	final class Lafka_Distance_Shipping {

		/** WC session key: why the last calculation offered no rate. */
		const SESSION_REASON = 'lafka_distance_reason';

		/**
		 * Hook everything.
		 *
		 * @return void
		 */
		public static function init(): void {
			add_action( 'woocommerce_shipping_init', array( __CLASS__, 'load_method' ) );
			add_filter( 'woocommerce_shipping_methods', array( __CLASS__, 'register_method' ) );

			add_action( 'woocommerce_checkout_update_order_review', array( __CLASS__, 'capture_pin_from_review' ) );
			add_action( 'woocommerce_checkout_process', array( __CLASS__, 'capture_pin_from_checkout' ), 1 );
			add_filter( 'woocommerce_cart_shipping_packages', array( __CLASS__, 'add_pin_to_packages' ), 20 );

			add_filter( 'woocommerce_package_rates', array( __CLASS__, 'label_rates' ), 10, 1 );
			add_action( 'woocommerce_after_shipping_rate', array( __CLASS__, 'render_distance_note' ) );
			add_action( 'woocommerce_cart_totals_after_shipping', array( __CLASS__, 'render_reason_row' ) );
			add_action( 'woocommerce_review_order_after_shipping', array( __CLASS__, 'render_reason_row' ) );
			add_filter( 'woocommerce_no_shipping_available_html', array( __CLASS__, 'filter_no_shipping_html' ), 20 );
			add_filter( 'woocommerce_cart_no_shipping_available_html', array( __CLASS__, 'filter_no_shipping_html' ), 20 );

			add_action( 'woocommerce_checkout_create_order_shipping_item', array( __CLASS__, 'name_order_item' ), 10, 1 );
			add_filter( 'woocommerce_order_item_get_formatted_meta_data', array( __CLASS__, 'hide_flag_meta' ), 10, 2 );
		}

		/**
		 * Whether any shipping zone has an enabled `lafka_distance` method.
		 *
		 * @return bool
		 */
		public static function in_use(): bool {
			if ( ! class_exists( 'WC_Shipping_Zones' ) ) {
				return false;
			}
			$zones   = (array) WC_Shipping_Zones::get_zones();
			$zones[] = array( 'shipping_methods' => WC_Shipping_Zones::get_zone( 0 )->get_shipping_methods( true ) );
			foreach ( $zones as $zone ) {
				foreach ( (array) ( $zone['shipping_methods'] ?? array() ) as $method ) {
					if ( is_object( $method ) && 'lafka_distance' === $method->id && 'yes' === $method->enabled ) {
						return true;
					}
				}
			}

			return false;
		}

		/**
		 * Load the method class once WooCommerce starts its shipping.
		 *
		 * @return void
		 */
		public static function load_method(): void {
			require_once __DIR__ . '/class-lafka-shipping-method-distance.php';
		}

		/**
		 * Add the method to WooCommerce's list.
		 *
		 * @param mixed $methods Method id => class name.
		 * @return mixed
		 */
		public static function register_method( $methods ) {
			if ( is_array( $methods ) ) {
				$methods['lafka_distance'] = 'Lafka_Shipping_Method_Distance';
			}

			return $methods;
		}

		/* ------------------------------------------------------------------ *
		 *  Delivery pin
		 * ------------------------------------------------------------------ */

		/**
		 * Order-review refresh: take the pin from the posted checkout form.
		 *
		 * @param mixed $post_data The form, urlencoded.
		 * @return void
		 */
		public static function capture_pin_from_review( $post_data ): void {
			parse_str( (string) $post_data, $form );
			self::store_pin( is_array( $form ) ? $form : array() );
		}

		/**
		 * Place order: take the pin from the submitted form (WooCommerce has
		 * already checked the checkout nonce).
		 *
		 * @return void
		 */
		public static function capture_pin_from_checkout(): void {
			if ( ! function_exists( 'lafka_verify_checkout_nonce' ) || ! lafka_verify_checkout_nonce() ) {
				return;
			}
			$form = array();
			foreach ( array( 'lafka_picked_delivery_geocoded', 'ship_to_different_address', 'billing_country', 'billing_state', 'billing_postcode', 'billing_city', 'billing_address_1', 'shipping_country', 'shipping_state', 'shipping_postcode', 'shipping_city', 'shipping_address_1' ) as $field ) {
				if ( isset( $_POST[ $field ] ) && is_scalar( $_POST[ $field ] ) ) {
						$form[ $field ] = sanitize_text_field( wp_unslash( (string) $_POST[ $field ] ) );
				}
			}
			self::store_pin( $form );
		}

		/**
		 * Save (or clear) the pin for the address in a posted form.
		 *
		 * @param array $form Posted checkout fields.
		 * @return void
		 */
		private static function store_pin( array $form ): void {
			$wc = function_exists( 'WC' ) ? WC() : null;
			if ( ! is_object( $wc ) || ! isset( $wc->session ) || ! is_object( $wc->session ) ) {
				return;
			}
			$pin = null;
			if ( ! empty( $form['lafka_picked_delivery_geocoded'] ) ) {
				$decoded = json_decode( (string) $form['lafka_picked_delivery_geocoded'], true );
				$point   = is_array( $decoded ) ? lafka_geo_point( $decoded['lat'] ?? null, $decoded['lng'] ?? null ) : null;
				if ( null !== $point ) {
					$prefix = empty( $form['ship_to_different_address'] ) ? 'billing_' : 'shipping_';
					$parts  = array();
					foreach ( array( 'country', 'state', 'postcode', 'city', 'address_1' ) as $part ) {
						$parts[ $part ] = (string) ( $form[ $prefix . $part ] ?? '' );
					}
					$pin = array(
						'lat' => $point['lat'],
						'lng' => $point['lng'],
						'fp'  => Lafka_Distance_Resolver::fingerprint( $parts ),
					);
				}
			}
			$wc->session->set( Lafka_Distance_Resolver::SESSION_PIN, $pin );
		}

		/**
		 * Put the session pin on every shipping package.
		 *
		 * @param mixed $packages Shipping packages.
		 * @return mixed
		 */
		public static function add_pin_to_packages( $packages ) {
			$wc  = function_exists( 'WC' ) ? WC() : null;
			$pin = ( is_object( $wc ) && isset( $wc->session ) && is_object( $wc->session ) ) ? $wc->session->get( Lafka_Distance_Resolver::SESSION_PIN ) : null;
			if ( ! is_array( $packages ) || ! is_array( $pin ) ) {
				return $packages;
			}
			foreach ( $packages as $i => $package ) {
				if ( is_array( $package ) ) {
					$packages[ $i ][ Lafka_Distance_Resolver::PACKAGE_PIN ] = $pin;
				}
			}

			return $packages;
		}

		/* ------------------------------------------------------------------ *
		 *  Why there is no rate
		 * ------------------------------------------------------------------ */

		/**
		 * Remember (or clear) why the last calculation offered no rate.
		 *
		 * @param array|null $reason kind, max, unit; null clears.
		 * @return void
		 */
		public static function set_reason( ?array $reason ): void {
			$wc = function_exists( 'WC' ) ? WC() : null;
			if ( is_object( $wc ) && isset( $wc->session ) && is_object( $wc->session ) ) {
				$wc->session->set( self::SESSION_REASON, $reason );
			}
		}

		/**
		 * The customer-facing sentence for the last "no rate" ('' = none).
		 *
		 * @return string
		 */
		public static function reason_message(): string {
			$wc     = function_exists( 'WC' ) ? WC() : null;
			$reason = ( is_object( $wc ) && isset( $wc->session ) && is_object( $wc->session ) ) ? $wc->session->get( self::SESSION_REASON ) : null;
			if ( ! is_array( $reason ) || self::has_delivery_rate() ) {
				return '';
			}
			switch ( $reason['kind'] ?? '' ) {
				case 'address':
					$message = __( 'We couldn\'t find that address. Check the street and postcode, or choose Pickup.', 'lafka-plugin' );
					break;
				case 'beyond':
					$max     = (float) ( $reason['max'] ?? 0 );
					$message = $max > 0
						/* translators: 1: distance number, 2: km or mi. */
						? sprintf( __( 'We can\'t deliver that far (we deliver up to %1$s %2$s). Choose Pickup instead.', 'lafka-plugin' ), rtrim( rtrim( number_format( $max, 1, '.', '' ), '0' ), '.' ), (string) ( $reason['unit'] ?? 'km' ) )
						: __( 'We can\'t deliver to that address. Choose Pickup instead.', 'lafka-plugin' );
					break;
				default:
					$message = __( 'Delivery isn\'t available right now. Please choose Pickup or call us.', 'lafka-plugin' );
			}

			/**
			 * Filter the sentence shown when distance delivery offers no rate.
			 *
			 * @since 10.4.0
			 * @param string $message Message.
			 * @param array  $reason  kind (address, beyond, unavailable), max, unit.
			 */
			return (string) apply_filters( 'lafka_distance_unavailable_message', $message, $reason );
		}

		/**
		 * Whether any shipping package currently offers a delivery (non-pickup) rate.
		 *
		 * @return bool
		 */
		private static function has_delivery_rate(): bool {
			$wc = function_exists( 'WC' ) ? WC() : null;
			if ( ! is_object( $wc ) || ! method_exists( $wc, 'shipping' ) || ! is_object( $wc->shipping() ) ) {
				return false;
			}
			foreach ( (array) $wc->shipping()->get_packages() as $package ) {
				foreach ( (array) ( $package['rates'] ?? array() ) as $rate ) {
					if ( is_object( $rate ) && ! lafka_is_pickup_shipping_method( (string) $rate->get_method_id() ) && Lafka_Delivery_Quote_Guard::PLACEHOLDER !== $rate->get_method_id() ) {
						return true;
					}
				}
			}

			return false;
		}

		/**
		 * A row under the shipping options explaining the missing delivery rate.
		 *
		 * @return void
		 */
		public static function render_reason_row(): void {
			$message = self::reason_message();
			if ( '' === $message ) {
				return;
			}
			printf(
				'<tr class="lafka-delivery-quote-notice lafka-distance-notice"><td colspan="2"><p class="lafka-delivery-quote-notice__text">%s</p></td></tr>',
				esc_html( $message )
			);
		}

		/**
		 * The empty-rates copy when delivery was the only option.
		 *
		 * @param mixed $html WooCommerce's message.
		 * @return mixed
		 */
		public static function filter_no_shipping_html( $html ) {
			$message = self::reason_message();

			return '' === $message ? $html : esc_html( $message );
		}

		/* ------------------------------------------------------------------ *
		 *  The distance on the rate and the order
		 * ------------------------------------------------------------------ */

		/**
		 * Put the distance in the block checkout's rate label (the classic
		 * checkout shows it under the label, see render_distance_note()).
		 *
		 * @param mixed $rates Package rates.
		 * @return mixed
		 */
		public static function label_rates( $rates ) {
			if ( ! is_array( $rates ) ) {
				return $rates;
			}
			foreach ( $rates as $rate ) {
				if ( is_object( $rate ) && 'lafka_distance' === $rate->get_method_id() ) {
					$meta = $rate->get_meta_data();
					// The block checkout keeps a rate's description from its first render (a stale
					// distance under a new price), but always re-renders the label: it carries the distance there.
					if ( ! empty( $meta['Distance'] ) && is_object( WC() ) && WC()->is_store_api_request() ) {
						$rate->set_label( $rate->get_label() . ' · ' . $meta['Distance'] );
					}
				}
			}

			return $rates;
		}

		/**
		 * Classic cart / checkout: the distance under the rate's label.
		 *
		 * @param mixed $method WC_Shipping_Rate.
		 * @return void
		 */
		public static function render_distance_note( $method ): void {
			if ( ! is_object( $method ) || 'lafka_distance' !== $method->get_method_id() ) {
				return;
			}
			$meta = $method->get_meta_data();
			if ( ! empty( $meta['Distance'] ) ) {
				printf( '<div class="lafka-distance-note"><small>%s</small></div>', esc_html( (string) $meta['Distance'] ) );
			}
		}

		/**
		 * The order's shipping line reads "Delivery · 4.2 km" (order screen,
		 * emails, the kitchen display's order).
		 *
		 * @param mixed $item WC_Order_Item_Shipping.
		 * @return void
		 */
		public static function name_order_item( $item ): void {
			if ( ! is_object( $item ) || 'lafka_distance' !== $item->get_method_id() ) {
				return;
			}
			$distance = (string) $item->get_meta( 'Distance' );
			if ( '' !== $distance && false === strpos( (string) $item->get_method_title(), '·' ) ) {
				$item->set_method_title( $item->get_method_title() . ' · ' . $distance );
			}
		}

		/**
		 * The free-delivery flag is for the storefront scripts, not the order screen.
		 *
		 * @param mixed $meta Formatted meta.
		 * @param mixed $item Order item.
		 * @return mixed
		 */
		public static function hide_flag_meta( $meta, $item ) {
			if ( is_array( $meta ) && is_object( $item ) && 'shipping' === $item->get_type() ) {
				foreach ( $meta as $id => $entry ) {
					if ( isset( $entry->key ) && 'lafka_free_delivery' === $entry->key ) {
						unset( $meta[ $id ] );
					}
				}
			}

			return $meta;
		}

		/**
		 * "4.2 km" for an order ('' when it was not priced by distance).
		 *
		 * @param WC_Order $order Order.
		 * @return string
		 */
		public static function order_distance( $order ): string {
			if ( ! is_object( $order ) || ! method_exists( $order, 'get_shipping_methods' ) ) {
				return '';
			}
			foreach ( $order->get_shipping_methods() as $item ) {
				if ( 'lafka_distance' === $item->get_method_id() ) {
					$distance = (string) $item->get_meta( 'Distance' );

					// Flag a distance only the customer's pin vouched for.
					return '' !== (string) $item->get_meta( 'Distance check' ) ? $distance . ' ' . __( '(pin only, address not verified)', 'lafka-plugin' ) : $distance;
				}
			}

			return '';
		}
	}

	Lafka_Distance_Shipping::init();
}
