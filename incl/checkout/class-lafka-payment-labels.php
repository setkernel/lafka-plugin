<?php
/**
 * Lafka_Payment_Labels — "cash on delivery" that says what it means.
 *
 * WooCommerce's COD gateway is titled "Cash on delivery" — confusing on a
 * pickup order, which is most restaurant orders. The title and description
 * follow the order's fulfilment: "Pay at pickup" on a pickup order, "Pay on
 * delivery" on a delivery order (Customizer strings with translatable
 * defaults). With nothing chosen yet, and on admin screens, the configured
 * title stands.
 *
 * Classic checkout: the payment list re-renders on every order-review refresh,
 * so the label follows the shipping choice live. Block checkout: WooCommerce
 * serialises payment-method labels once at page load, so the label reflects
 * the fulfilment chosen before the checkout loaded (usually on the cart). The
 * title saved on the order uses the same filter on both paths, so emails and
 * the admin order screen show the contextual title.
 *
 * The gateway's instructions (the order-received page and the customer's
 * emails, where WooCommerce prints "Pay with cash upon delivery." by default)
 * follow the ORDER's fulfilment: the gateway's `instructions` are swapped for
 * the contextual description just before WooCommerce prints them and put back
 * right after.
 *
 * Operator surface:
 *   · Customizer → Lafka — Checkout: on/off (`lafka_cod_contextual_title`,
 *     default on) + pickup/delivery title and description overrides.
 *   · `lafka_contextual_payment_gateways` (string[], default [ 'cod' ])
 *   · `lafka_cod_title` / `lafka_cod_description` (string, $context, $original)
 *   · `lafka_cod_instructions` (string, $context, $original, $order)
 *
 * @package Lafka\Plugin\Checkout
 * @since   10.2.0
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/../lafka-shipping-method-helpers.php';

if ( ! class_exists( 'Lafka_Payment_Labels' ) ) {

	/**
	 * Contextual COD title + description.
	 */
	final class Lafka_Payment_Labels {

		/**
		 * Setting: contextual labels on/off (default on).
		 */
		const MOD_ENABLED = 'lafka_cod_contextual_title';

		/**
		 * Configured instructions of the gateways swapped for one order, by gateway id.
		 *
		 * @var array<string,string>
		 */
		private static $swapped = array();

		/**
		 * Hook the gateway title + description.
		 *
		 * @return void
		 */
		public static function init() {
			add_filter( 'woocommerce_gateway_title', array( __CLASS__, 'filter_title' ), 20, 2 );
			add_filter( 'woocommerce_gateway_description', array( __CLASS__, 'filter_description' ), 20, 2 );
			// WooCommerce prints the instructions at priority 10 on both hooks.
			add_action( 'wp_loaded', array( __CLASS__, 'hook_order_received' ) );
			add_action( 'woocommerce_email_before_order_table', array( __CLASS__, 'swap_instructions' ), 9 );
			add_action( 'woocommerce_email_before_order_table', array( __CLASS__, 'restore_instructions' ), 11 );
		}

		/**
		 * The order-received page (classic template and the Order Confirmation
		 * block) prints the instructions on `woocommerce_thankyou_{gateway}`.
		 *
		 * @since 10.4.0
		 * @return void
		 */
		public static function hook_order_received(): void {
			foreach ( self::gateway_ids() as $id ) {
				add_action( 'woocommerce_thankyou_' . $id, array( __CLASS__, 'swap_instructions' ), 9 );
				add_action( 'woocommerce_thankyou_' . $id, array( __CLASS__, 'restore_instructions' ), 11 );
			}
		}

		/**
		 * Before WooCommerce prints a gateway's instructions for an order (the
		 * order-received page, the customer's emails): use the text for the
		 * order's fulfilment.
		 *
		 * @since 10.4.0
		 * @param mixed $order Order or order id.
		 * @return void
		 */
		public static function swap_instructions( $order ): void {
			$order = $order instanceof WC_Order ? $order : wc_get_order( is_numeric( $order ) ? (int) $order : 0 );
			if ( ! $order instanceof WC_Order || ! self::contextual_gateway( (string) $order->get_payment_method() ) ) {
				return;
			}
			$context = lafka_order_fulfilment_type( $order );
			if ( ! in_array( $context, array( 'pickup', 'delivery' ), true ) ) {
				return;
			}
			$gateway = self::gateway( (string) $order->get_payment_method() );
			if ( null === $gateway || ! property_exists( $gateway, 'instructions' ) || '' === trim( (string) $gateway->instructions ) ) {
				return;
			}
			$text = self::string(
				'lafka_cod_description_' . $context,
				'pickup' === $context ? __( 'Pay when you collect your order.', 'lafka-plugin' ) : __( 'Pay when your order arrives.', 'lafka-plugin' )
			);

			self::$swapped[ $gateway->id ] = (string) $gateway->instructions;

			/**
			 * Filter the contextual instructions of a cash-style gateway (the
			 * order-received page and the customer's emails).
			 *
			 * @since 10.4.0
			 * @param string   $text     Instructions for this fulfilment.
			 * @param string   $context  'pickup' or 'delivery'.
			 * @param string   $original The gateway's configured instructions.
			 * @param WC_Order $order    The order.
			 */
			$gateway->instructions = (string) apply_filters( 'lafka_cod_instructions', $text, $context, self::$swapped[ $gateway->id ], $order );
		}

		/**
		 * After WooCommerce printed the instructions: put the configured text back.
		 *
		 * @since 10.4.0
		 * @return void
		 */
		public static function restore_instructions(): void {
			foreach ( self::$swapped as $id => $instructions ) {
				$gateway = self::gateway( (string) $id );
				if ( null !== $gateway ) {
					$gateway->instructions = $instructions;
				}
			}
			self::$swapped = array();
		}

		/**
		 * A loaded payment gateway, or null.
		 *
		 * @param string $id Gateway id.
		 * @return WC_Payment_Gateway|null
		 */
		private static function gateway( string $id ) {
			if ( ! function_exists( 'WC' ) || ! is_object( WC()->payment_gateways() ) ) {
				return null;
			}
			$gateways = WC()->payment_gateways()->payment_gateways();

			return isset( $gateways[ $id ] ) && $gateways[ $id ] instanceof WC_Payment_Gateway ? $gateways[ $id ] : null;
		}

		/**
		 * woocommerce_gateway_title.
		 *
		 * @param mixed $title      Gateway title.
		 * @param mixed $gateway_id Gateway id.
		 * @return mixed
		 */
		public static function filter_title( $title, $gateway_id = '' ) {
			$context = self::context( (string) $gateway_id );
			if ( '' === $context ) {
				return $title;
			}
			$label = self::string(
				'lafka_cod_title_' . $context,
				'pickup' === $context ? __( 'Pay at pickup', 'lafka-plugin' ) : __( 'Pay on delivery', 'lafka-plugin' )
			);

			/**
			 * Filter the contextual COD title.
			 *
			 * @param string $label    Title for this fulfilment.
			 * @param string $context  'pickup' or 'delivery'.
			 * @param mixed  $original The gateway's configured title.
			 */
			return (string) apply_filters( 'lafka_cod_title', $label, $context, $title );
		}

		/**
		 * woocommerce_gateway_description.
		 *
		 * @param mixed $description Gateway description (already kses'd).
		 * @param mixed $gateway_id  Gateway id.
		 * @return mixed
		 */
		public static function filter_description( $description, $gateway_id = '' ) {
			$context = self::context( (string) $gateway_id );
			if ( '' === $context ) {
				return $description;
			}
			$text = self::string(
				'lafka_cod_description_' . $context,
				'pickup' === $context ? __( 'Pay when you collect your order.', 'lafka-plugin' ) : __( 'Pay when your order arrives.', 'lafka-plugin' )
			);

			/**
			 * Filter the contextual COD description.
			 *
			 * @param string $text     Description for this fulfilment.
			 * @param string $context  'pickup' or 'delivery'.
			 * @param mixed  $original The gateway's configured description.
			 */
			return wp_kses_post( (string) apply_filters( 'lafka_cod_description', $text, $context, $description ) );
		}

		/**
		 * The fulfilment to label for, or '' to leave the gateway alone.
		 *
		 * @param string $gateway_id Gateway id.
		 * @return string 'pickup', 'delivery' or ''.
		 */
		private static function context( string $gateway_id ): string {
			if ( ! self::contextual_gateway( $gateway_id ) ) {
				return '';
			}
			// Admin screens (gateway settings, order edit) show the configured title.
			if ( is_admin() && ! wp_doing_ajax() ) {
				return '';
			}

			// Pay for order: the order being paid says how it is fulfilled.
			if ( function_exists( 'is_checkout_pay_page' ) && is_checkout_pay_page() ) {
				$order = wc_get_order( absint( get_query_var( 'order-pay' ) ) );
				if ( $order instanceof WC_Order ) {
					$type = lafka_order_fulfilment_type( $order );
					return in_array( $type, array( 'pickup', 'delivery' ), true ) ? $type : '';
				}
			}

			// After the totals: the rate WooCommerce actually settled on (it can
			// switch to delivery during the refresh that unlocked the delivery
			// rates, while the posted radio still says pickup).
			return lafka_settled_fulfilment_type();
		}

		/**
		 * Whether a gateway's texts follow the fulfilment (contextual labels on,
		 * and the gateway is one of `lafka_contextual_payment_gateways`).
		 *
		 * @param string $gateway_id Gateway id.
		 * @return bool
		 */
		private static function contextual_gateway( string $gateway_id ): bool {
			return in_array( $gateway_id, self::gateway_ids(), true ) && (bool) lafka_setting( self::MOD_ENABLED, true );
		}

		/**
		 * The gateways whose texts follow the fulfilment.
		 *
		 * @return string[]
		 */
		private static function gateway_ids(): array {
			/**
			 * Filter the gateways whose title/description follow the fulfilment.
			 *
			 * @param string[] $gateway_ids Default [ 'cod' ].
			 */
			return array_map( 'strval', (array) apply_filters( 'lafka_contextual_payment_gateways', array( 'cod' ) ) );
		}

		/**
		 * A Customizer string, or the translatable default when empty.
		 *
		 * @param string $mod      Setting name.
		 * @param string $fallback Default text.
		 * @return string
		 */
		private static function string( string $mod, string $fallback ): string {
			$custom = trim( (string) lafka_setting( $mod, '' ) );

			return '' !== $custom ? $custom : $fallback;
		}
	}
}
