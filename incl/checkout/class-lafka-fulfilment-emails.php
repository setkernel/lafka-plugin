<?php
/**
 * Lafka_Fulfilment_Emails — WooCommerce's order emails that fit a pickup order.
 *
 * WooCommerce's "Completed order" email says "Your order from {site_title} is
 * on its way!" / "Good things are heading your way!": right for a delivery,
 * wrong for an order the customer collected. On a pickup order the plugin
 * supplies pickup wording through WooCommerce's own email filters, only while
 * the subject or heading is WooCommerce's default: a text the operator typed
 * in WooCommerce → Settings → Emails always wins. Delivery orders keep
 * WooCommerce's wording.
 *
 * Operator surface:
 *   · WooCommerce → Settings → Emails → Completed order (subject/heading).
 *   · `lafka_pickup_completed_email_text` (string $text, string $field, WC_Order, WC_Email).
 *
 * @package Lafka\Plugin\Checkout
 * @since   10.4.0
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/../lafka-shipping-method-helpers.php';

if ( ! class_exists( 'Lafka_Fulfilment_Emails' ) ) {

	/**
	 * Pickup-aware defaults for the Completed order email.
	 */
	final class Lafka_Fulfilment_Emails {

		/**
		 * Hook WooCommerce's email filters.
		 *
		 * @return void
		 */
		public static function init(): void {
			add_filter( 'woocommerce_email_subject_customer_completed_order', array( __CLASS__, 'completed_subject' ), 10, 3 );
			add_filter( 'woocommerce_email_heading_customer_completed_order', array( __CLASS__, 'completed_heading' ), 10, 3 );
		}

		/**
		 * Completed order subject.
		 *
		 * @param mixed $subject Formatted subject.
		 * @param mixed $order   The order.
		 * @param mixed $email   The email.
		 * @return mixed
		 */
		public static function completed_subject( $subject, $order = null, $email = null ) {
			return self::pickup_text( $subject, $order, $email, 'subject' );
		}

		/**
		 * Completed order heading.
		 *
		 * @param mixed $heading Formatted heading.
		 * @param mixed $order   The order.
		 * @param mixed $email   The email.
		 * @return mixed
		 */
		public static function completed_heading( $heading, $order = null, $email = null ) {
			return self::pickup_text( $heading, $order, $email, 'heading' );
		}

		/**
		 * The pickup wording for a pickup order whose text is still WooCommerce's default.
		 *
		 * @param mixed  $value Formatted text WooCommerce built.
		 * @param mixed  $order The order.
		 * @param mixed  $email The email.
		 * @param string $field 'subject' or 'heading'.
		 * @return mixed
		 */
		private static function pickup_text( $value, $order, $email, string $field ) {
			if ( ! $order instanceof WC_Order || ! $email instanceof WC_Email || 'pickup' !== lafka_order_fulfilment_type( $order ) ) {
				return $value;
			}
			$default = 'subject' === $field ? $email->get_default_subject() : $email->get_default_heading();
			if ( (string) $value !== $email->format_string( $default ) ) {
				return $value;
			}
			$text = 'subject' === $field
				? __( 'Your order from {site_title} is complete', 'lafka-plugin' )
				: __( 'Thank you for your order. Enjoy your meal!', 'lafka-plugin' );

			/**
			 * Filter the Completed order email's subject or heading for a pickup order.
			 *
			 * @since 10.4.0
			 * @param string   $text  Text; WooCommerce placeholders such as {site_title} work.
			 * @param string   $field 'subject' or 'heading'.
			 * @param WC_Order $order The order.
			 * @param WC_Email $email The email.
			 */
			$text = (string) apply_filters( 'lafka_pickup_completed_email_text', $text, $field, $order, $email );

			return $email->format_string( $text );
		}
	}
}
