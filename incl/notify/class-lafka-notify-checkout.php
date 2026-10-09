<?php
/**
 * The opt-in at checkout, on the classic and the block checkout.
 *
 * One unticked checkbox ("text me updates about this order"), offered only when
 * a channel is ready and the shop asks for a phone number. Ticking it stores the
 * consent on the order with the time, the wording the customer saw and the
 * number in E.164 form; without it nothing is ever sent. The number is the
 * billing phone WooCommerce already collects, so the customer types it once.
 *
 * Classic: the `woocommerce_checkout_fields` filter. Block: WooCommerce's
 * Additional Checkout Fields API, saved by the Store API.
 *
 * @package Lafka\Plugin\Notify
 * @since   10.4.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Lafka_Notify_Checkout' ) ) {

	/**
	 * Opt-in field and consent record.
	 */
	final class Lafka_Notify_Checkout {

		/** Classic checkout field name. */
		const FIELD = 'lafka_notify_optin';

		/** Block checkout Additional Checkout Field id. */
		const BLOCK_FIELD = 'lafka/notify-optin';

		/**
		 * Hook in.
		 *
		 * @return void
		 */
		public static function init(): void {
			add_filter( 'woocommerce_checkout_fields', array( __CLASS__, 'classic_field' ) );
			add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'classic_validate' ), 10, 2 );
			add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'classic_save' ), 10, 2 );
			add_action( 'woocommerce_checkout_order_created', array( __CLASS__, 'note' ) );
			add_action( 'init', array( __CLASS__, 'register_block_field' ), 20 );
			add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( __CLASS__, 'block_save' ), 10, 2 );
		}

		/**
		 * Whether the checkout offers the opt-in.
		 *
		 * @return bool
		 */
		public static function offered(): bool {
			return Lafka_Notify::can_send() && 'hidden' !== get_option( 'woocommerce_checkout_phone_field', 'required' );
		}

		/**
		 * The wording next to the checkbox (also stored on the order).
		 *
		 * @return string
		 */
		public static function wording(): string {
			$text = trim( (string) lafka_setting( 'lafka_notify_optin_text', '' ) );
			return '' !== $text ? $text : __( 'Text me updates about this order. Order updates only, no marketing. Message and data rates may apply.', 'lafka-plugin' );
		}

		/**
		 * Add the checkbox to the classic checkout, under the email.
		 *
		 * @param array<string,array<string,array<string,mixed>>> $fields Checkout fields.
		 * @return array<string,array<string,array<string,mixed>>>
		 */
		public static function classic_field( $fields ) {
			if ( ! self::offered() || ! isset( $fields['billing'] ) ) {
				return $fields;
			}
			$fields['billing'][ self::FIELD ] = array(
				'type'     => 'checkbox',
				'label'    => self::wording(),
				'required' => false,
				'class'    => array( 'form-row-wide', 'lafka-notify-optin' ),
				'priority' => 115,
				'default'  => 0,
			);
			return $fields;
		}

		/**
		 * A ticked box needs a number that texts can reach.
		 *
		 * @param array<string,mixed> $data   Posted checkout data.
		 * @param WP_Error            $errors Validation errors.
		 * @return void
		 */
		public static function classic_validate( $data, $errors ): void {
			if ( ! empty( $data[ self::FIELD ] ) && '' === self::phone( (string) ( $data['billing_phone'] ?? '' ), (string) ( $data['billing_country'] ?? '' ) ) ) {
				$errors->add( 'lafka_notify_phone', self::phone_error(), array( 'id' => 'billing_phone' ) );
			}
		}

		/**
		 * Store the consent on the classic order.
		 *
		 * @param WC_Order            $order Order.
		 * @param array<string,mixed> $data  Posted checkout data.
		 * @return void
		 */
		public static function classic_save( $order, $data ): void {
			if ( ! empty( $data[ self::FIELD ] ) ) {
				self::record( $order, (string) ( $data['billing_phone'] ?? '' ) );
			}
		}

		/**
		 * Register the checkbox with the block checkout.
		 *
		 * @return void
		 */
		public static function register_block_field(): void {
			if ( ! function_exists( 'woocommerce_register_additional_checkout_field' ) || ! class_exists( 'Lafka_Checkout_Mode' ) || ! Lafka_Checkout_Mode::is_blocks() || ! self::offered() ) {
				return;
			}
			woocommerce_register_additional_checkout_field(
				array(
					'id'                         => self::BLOCK_FIELD,
					'label'                      => self::wording(),
					'location'                   => 'order',
					'type'                       => 'checkbox',
					'required'                   => false,
					'show_in_order_confirmation' => false,
				)
			);
		}

		/**
		 * Store the consent on a block-checkout order, or refuse an order whose
		 * ticked box has no usable number.
		 *
		 * @param WC_Order        $order   Order.
		 * @param WP_REST_Request $request Store API request.
		 * @return void
		 * @throws \Automattic\WooCommerce\StoreApi\Exceptions\RouteException When the box is ticked without a usable phone number.
		 */
		public static function block_save( $order, $request ): void {
			$fields = (array) ( $request['additional_fields'] ?? array() );
			if ( empty( $fields[ self::BLOCK_FIELD ] ) || ! self::offered() ) {
				return;
			}
			$phone = '' !== $order->get_billing_phone() ? $order->get_billing_phone() : $order->get_shipping_phone();
			if ( '' === self::phone( $phone, $order->get_billing_country() ) ) {
				throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'lafka_notify_phone', esc_html( self::phone_error() ), 400 );
			}
			$first = 'yes' !== $order->get_meta( Lafka_Notify::META_OPTIN );
			self::record( $order, $phone );
			if ( $first ) {
				self::note( $order );
			}
		}

		/**
		 * A phone number as E.164, using the customer's country, else the shop's.
		 *
		 * @param string $phone   Phone text.
		 * @param string $country Customer's ISO country ('' = the shop's).
		 * @return string '' when it cannot be texted.
		 */
		public static function phone( string $phone, string $country = '' ): string {
			return lafka_phone_to_e164( $phone, $country );
		}

		/**
		 * Plain message for a ticked box without a usable number.
		 *
		 * @return string
		 */
		private static function phone_error(): string {
			return __( 'To get text updates, please enter a mobile number in the Phone field (with the area code), or untick the box.', 'lafka-plugin' );
		}

		/**
		 * Store the consent: that the customer agreed, when, to which wording, and the number.
		 *
		 * @param WC_Order $order Order (saved by the checkout).
		 * @param string   $phone Phone text the customer entered.
		 * @return void
		 */
		public static function record( $order, string $phone ): void {
			$e164 = self::phone( $phone, $order->get_billing_country() );
			if ( '' === $e164 ) {
				return;
			}
			$order->update_meta_data( Lafka_Notify::META_OPTIN, 'yes' );
			$order->update_meta_data( Lafka_Notify::META_TIME, gmdate( 'c' ) );
			$order->update_meta_data( Lafka_Notify::META_TEXT, self::wording() );
			$order->update_meta_data( Lafka_Notify::META_PHONE, $e164 );
		}

		/**
		 * Leave a private note that the customer agreed (the order has an id by now).
		 *
		 * @param WC_Order $order Order.
		 * @return void
		 */
		public static function note( $order ): void {
			if ( 'yes' === $order->get_meta( Lafka_Notify::META_OPTIN ) ) {
				$order->add_order_note( __( 'The customer agreed to text updates about this order (order updates only).', 'lafka-plugin' ) );
			}
		}
	}
}
