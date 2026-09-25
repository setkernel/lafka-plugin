<?php
/**
 * Lafka_Order_Path — small ordering-path fixes from the live order-path QA.
 *
 *  - O-06: the classic cart/checkout shipping row is headed by WooCommerce's
 *    package name ("Shipping" / "Shipment"), which reads oddly for a
 *    restaurant. With one package it now names the choice the store offers:
 *    "Pickup or delivery", "Pickup" or "Delivery" (filter
 *    `lafka_shipping_package_name`).
 *  - O-28: card-form security-code inputs rendered with autocomplete="off"
 *    (the SkyVerge payment-form framework does this) get "cc-csc", so
 *    browsers and password managers can fill them. Gateway-agnostic: it
 *    matches the field, not a gateway id.
 *  - O-31: the classic checkout refuses a phone number with fewer digits than
 *    `lafka_checkout_phone_min_digits` (default 7), with an inline error on
 *    the field (WooCommerce only checks the characters, so "12" passed).
 *  - O-37: WordPress speculative loading must not prefetch the cart,
 *    checkout or account pages (session-bound, uncacheable; the live CDN
 *    answered the prefetches with 503s), nor add/remove-item links.
 *
 * @package Lafka\Plugin\Checkout
 * @since   10.3.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Lafka_Order_Path' ) ) {

	/**
	 * Ordering-path fixes (package name, card CSC autocomplete, phone check,
	 * speculative-loading exclusions).
	 */
	final class Lafka_Order_Path {

		/**
		 * Default minimum digits in a checkout phone number.
		 */
		const PHONE_MIN_DIGITS = 7;

		/**
		 * Wire the filters.
		 *
		 * @return void
		 */
		public static function init() {
			add_filter( 'woocommerce_shipping_package_name', array( __CLASS__, 'package_name' ), 20, 3 );
			add_filter( 'woocommerce_form_field_args', array( __CLASS__, 'card_field_args' ), 20, 3 );
			add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'validate_phone' ), 20, 2 );
			add_filter( 'wp_speculation_rules_href_exclude_paths', array( __CLASS__, 'speculation_exclusions' ) );
		}

		/**
		 * woocommerce_shipping_package_name.
		 *
		 * @param mixed $name    WooCommerce's package name.
		 * @param mixed $index   Package index.
		 * @param mixed $package Package (unused).
		 * @return mixed
		 */
		public static function package_name( $name, $index = 0, $package = array() ) {
			if ( 0 !== (int) $index || ! function_exists( 'lafka_fulfilment_modes' ) ) {
				return $name;
			}

			return self::label_for_modes( lafka_fulfilment_modes(), $name );
		}

		/**
		 * The shipping-choice heading for the offered fulfilment modes.
		 *
		 * @param string[] $modes Offered modes (subset of pickup, delivery).
		 * @param mixed    $name  WooCommerce's package name (kept when unknown).
		 * @return mixed
		 */
		public static function label_for_modes( array $modes, $name ) {
			$labels = array(
				'pickup'   => __( 'Pickup', 'lafka-plugin' ),
				'delivery' => __( 'Delivery', 'lafka-plugin' ),
			);
			if ( array( 'pickup', 'delivery' ) === $modes ) {
				$label = __( 'Pickup or delivery', 'lafka-plugin' );
			} elseif ( 1 === count( $modes ) && isset( $labels[ $modes[0] ] ) ) {
				$label = $labels[ $modes[0] ];
			} else {
				return $name;
			}

			/**
			 * Filter the heading of the cart/checkout shipping choice.
			 *
			 * @since 10.3.0
			 * @param string   $label Default label.
			 * @param string[] $modes Offered fulfilment modes.
			 * @param mixed    $name  WooCommerce's package name.
			 */
			return (string) apply_filters( 'lafka_shipping_package_name', $label, $modes, $name );
		}

		/**
		 * woocommerce_form_field_args: "cc-csc" on card security-code inputs.
		 *
		 * @param mixed $args  Field args.
		 * @param mixed $key   Field key.
		 * @param mixed $value Field value (unused).
		 * @return mixed
		 */
		public static function card_field_args( $args, $key = '', $value = null ) {
			if ( ! is_array( $args ) || ! self::is_csc_field( (string) $key, $args ) ) {
				return $args;
			}
			$attrs = isset( $args['custom_attributes'] ) && is_array( $args['custom_attributes'] ) ? $args['custom_attributes'] : array();
			if ( ! isset( $attrs['autocomplete'] ) || 'off' === $attrs['autocomplete'] ) {
				$attrs['autocomplete']     = 'cc-csc';
				$args['custom_attributes'] = $attrs;
			}

			return $args;
		}

		/**
		 * Whether a form field is a card security code.
		 *
		 * @param string $key  Field key.
		 * @param array  $args Field args.
		 * @return bool
		 */
		private static function is_csc_field( string $key, array $args ): bool {
			$classes = isset( $args['input_class'] ) ? implode( ' ', array_map( 'strval', (array) $args['input_class'] ) ) : '';
			if ( false !== strpos( $classes, 'credit-card-form-csc' ) ) {
				return true;
			}

			return (bool) preg_match( '/(^|[-_])(csc|cvc|cvv|card-code|security-code)$/', $key );
		}

		/**
		 * The minimum digits a checkout phone number needs.
		 *
		 * @return int
		 */
		public static function phone_min_digits(): int {
			/**
			 * Filter the minimum digits in a checkout phone number (0 = off).
			 * The theme's checkout script reads the same filter.
			 *
			 * @since 10.3.0
			 * @param int $digits Default 7.
			 */
			return max( 0, (int) apply_filters( 'lafka_checkout_phone_min_digits', self::PHONE_MIN_DIGITS ) );
		}

		/**
		 * woocommerce_after_checkout_validation: refuse a too-short phone.
		 *
		 * @param mixed $data   Posted checkout data.
		 * @param mixed $errors WP_Error.
		 * @return void
		 */
		public static function validate_phone( $data, $errors ) {
			if ( ! is_array( $data ) || ! is_object( $errors ) || ! method_exists( $errors, 'add' ) ) {
				return;
			}
			$phone = trim( (string) ( $data['billing_phone'] ?? '' ) );
			$min   = self::phone_min_digits();
			if ( '' === $phone || 0 === $min ) {
				return; // Empty: WooCommerce's own required-field check speaks.
			}
			if ( strlen( (string) preg_replace( '/\D+/', '', $phone ) ) >= $min ) {
				return;
			}
			if ( method_exists( $errors, 'get_error_messages' ) && array() !== (array) $errors->get_error_messages( 'billing_phone_validation' ) ) {
				return;
			}
			$errors->add( 'billing_phone_validation', __( 'Please enter a valid phone number, so we can reach you about your order.', 'lafka-plugin' ), array( 'id' => 'billing_phone' ) );
		}

		/**
		 * wp_speculation_rules_href_exclude_paths: never prefetch the cart,
		 * checkout or account pages, nor add/remove-item links.
		 *
		 * @param mixed $paths Path patterns relative to the home URL.
		 * @return mixed
		 */
		public static function speculation_exclusions( $paths ) {
			if ( ! is_array( $paths ) ) {
				return $paths;
			}
			foreach ( array( 'cart', 'checkout', 'myaccount' ) as $page ) {
				$path = self::page_path( $page );
				if ( '' === $path ) {
					continue;
				}
				$paths[] = $path;
				$paths[] = $path . '/*';
			}
			$paths[] = '/*\\?*remove_item=*';
			$paths[] = '/*\\?*add-to-cart=*';

			return array_values( array_unique( $paths ) );
		}

		/**
		 * A WooCommerce page's path relative to the home URL, no trailing slash
		 * ('' when there is no such page, or it is the home page).
		 *
		 * @param string $page cart|checkout|myaccount.
		 * @return string
		 */
		private static function page_path( string $page ): string {
			if ( ! function_exists( 'wc_get_page_permalink' ) || ! function_exists( 'wc_get_page_id' ) || (int) wc_get_page_id( $page ) <= 0 ) {
				return '';
			}
			$path = (string) wp_parse_url( (string) wc_get_page_permalink( $page ), PHP_URL_PATH );
			$home = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
			if ( '' !== $home && '/' !== $home && 0 === strpos( $path, $home ) ) {
				$path = '/' . substr( $path, strlen( $home ) );
			}
			$path = '/' . trim( $path, '/' );

			return '/' === $path ? '' : $path;
		}
	}
}
