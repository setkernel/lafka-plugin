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
 * Operator surface:
 *   · Customizer → Lafka — Checkout: on/off (`lafka_cod_contextual_title`,
 *     default on) + pickup/delivery title and description overrides.
 *   · `lafka_contextual_payment_gateways` (string[], default [ 'cod' ])
 *   · `lafka_cod_title` / `lafka_cod_description` (string, $context, $original)
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
		 * Theme mod: contextual labels on/off (default on).
		 */
		const MOD_ENABLED = 'lafka_cod_contextual_title';

		/**
		 * Hook the gateway title + description.
		 *
		 * @return void
		 */
		public static function init() {
			add_filter( 'woocommerce_gateway_title', array( __CLASS__, 'filter_title' ), 20, 2 );
			add_filter( 'woocommerce_gateway_description', array( __CLASS__, 'filter_description' ), 20, 2 );
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
			/**
			 * Filter the gateways whose title/description follow the fulfilment.
			 *
			 * @param string[] $gateway_ids Default [ 'cod' ].
			 */
			$gateways = array_map( 'strval', (array) apply_filters( 'lafka_contextual_payment_gateways', array( 'cod' ) ) );
			if ( ! in_array( $gateway_id, $gateways, true ) ) {
				return '';
			}
			if ( function_exists( 'get_theme_mod' ) && ! get_theme_mod( self::MOD_ENABLED, true ) ) {
				return '';
			}
			// Admin screens (gateway settings, order edit) show the configured title.
			if ( function_exists( 'is_admin' ) && is_admin() && ! ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) ) {
				return '';
			}

			return lafka_current_fulfilment_type();
		}

		/**
		 * A Customizer string, or the translatable default when empty.
		 *
		 * @param string $mod      Theme mod id.
		 * @param string $fallback Default text.
		 * @return string
		 */
		private static function string( string $mod, string $fallback ): string {
			$custom = function_exists( 'get_theme_mod' ) ? trim( (string) get_theme_mod( $mod, '' ) ) : '';

			return '' !== $custom ? $custom : $fallback;
		}
	}
}
