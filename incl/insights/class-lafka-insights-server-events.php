<?php
/**
 * Lafka_Insights_Server_Events — money events recorded from WooCommerce hooks.
 *
 * These run in PHP on uncached requests, so ad-blockers and full-page caches
 * cannot hide them, and they work for classic AND block (Store API) checkout:
 *
 *   add to cart        woocommerce_add_to_cart (classic, AJAX and Store API all fire it)
 *   remove from cart   woocommerce_cart_item_removed
 *   cart / checkout    template_redirect + is_cart() / is_checkout() — both the
 *                      shortcode and the block pages answer those conditionals
 *   payment attempt    woocommerce_checkout_order_processed (classic) and
 *                      woocommerce_store_api_checkout_order_processed (blocks)
 *   order placed       first transition to processing / on-hold / completed,
 *                      once per order (order meta `_lafka_insights_counted`)
 *   payment failed     woocommerce_order_status_failed, classified from the
 *                      newest order note (declined / avs / cvv / gateway_error / other)
 *   checkout refused   do_action( 'lafka_checkout_blocked', $reason, $context )
 *
 * A gateway webhook runs in the gateway's request, not the visitor's, so the
 * payment-attempt hook parks the visit id in a 2-day transient keyed by order
 * id; the order / failure hooks use it (then delete it) and never persist a
 * visit id on the order itself.
 *
 * @package Lafka\Plugin\Insights
 * @since   10.2.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Lafka_Insights_Server_Events' ) ) {

	final class Lafka_Insights_Server_Events {

		/** Order meta guard: this order was counted. */
		const COUNTED_META = '_lafka_insights_counted';

		/** Transient prefix: visit id parked per order. */
		const ORDER_SID_TRANSIENT = 'lafka_ins_o_';

		/** @var array<string,bool> Reasons already recorded in this request. */
		private static $seen_reasons = array();

		/**
		 * Hook everything (only when the module is collecting).
		 *
		 * @return void
		 */
		public static function register(): void {
			add_action( 'woocommerce_add_to_cart', array( __CLASS__, 'on_add_to_cart' ), 20, 6 );
			add_action( 'woocommerce_cart_item_removed', array( __CLASS__, 'on_cart_item_removed' ), 20, 2 );
			add_action( 'template_redirect', array( __CLASS__, 'on_template_redirect' ), 20 );
			add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'on_checkout_order_processed' ), 20, 3 );
			add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'on_store_api_order_processed' ), 20, 1 );
			add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'on_order_status_changed' ), 20, 4 );
			add_action( 'woocommerce_order_status_failed', array( __CLASS__, 'on_order_failed' ), 20, 2 );
			add_action( 'lafka_checkout_blocked', array( __CLASS__, 'on_checkout_blocked' ), 10, 2 );
		}

		/**
		 * Reset per-request state (tests).
		 *
		 * @return void
		 */
		public static function reset(): void {
			self::$seen_reasons = array();
		}

		// ─── Handlers ────────────────────────────────────────────────────────

		/**
		 * @param string $cart_item_key Cart key.
		 * @param int    $product_id    Product id.
		 * @return void
		 */
		public static function on_add_to_cart( $cart_item_key = '', $product_id = 0 ): void {
			$product_id = (int) $product_id;
			self::record(
				Lafka_Insights_DB::STAGE_ADD,
				$product_id > 0 ? array( 'item_add' => array( (string) $product_id => 1 ) ) : array()
			);
		}

		/**
		 * @param string $cart_item_key Removed key.
		 * @param object $cart          WC_Cart.
		 * @return void
		 */
		public static function on_cart_item_removed( $cart_item_key = '', $cart = null ): void {
			$product_id = 0;
			if ( is_object( $cart ) && isset( $cart->removed_cart_contents[ $cart_item_key ]['product_id'] ) ) {
				$product_id = (int) $cart->removed_cart_contents[ $cart_item_key ]['product_id'];
			}
			if ( $product_id > 0 ) {
				self::record( 0, array( 'item_remove' => array( (string) $product_id => 1 ) ) );
			}
		}

		/**
		 * Cart page / checkout page views (never cached, both checkout modes).
		 *
		 * @return void
		 */
		public static function on_template_redirect(): void {
			if ( function_exists( 'is_wc_endpoint_url' ) && ( is_wc_endpoint_url( 'order-received' ) || is_wc_endpoint_url( 'order-pay' ) ) ) {
				return;
			}
			$stage = 0;
			if ( function_exists( 'is_checkout' ) && is_checkout() ) {
				$stage = Lafka_Insights_DB::STAGE_CHECKOUT;
			} elseif ( function_exists( 'is_cart' ) && is_cart() ) {
				$stage = Lafka_Insights_DB::STAGE_CART;
			}
			if ( 0 === $stage || self::cart_is_empty() ) {
				return;
			}
			// Reaching checkout implies the cart stage.
			if ( Lafka_Insights_DB::STAGE_CHECKOUT === $stage ) {
				$stage |= Lafka_Insights_DB::STAGE_CART;
			}
			self::record( $stage | Lafka_Insights_DB::STAGE_ADD );
		}

		/**
		 * Classic checkout: order created, payment about to be attempted.
		 *
		 * @param int   $order_id Order id.
		 * @param array $posted   Posted data.
		 * @param mixed $order    WC_Order.
		 * @return void
		 */
		public static function on_checkout_order_processed( $order_id = 0, $posted = array(), $order = null ): void {
			self::payment_attempt( (int) $order_id );
		}

		/**
		 * Block checkout (Store API): order created, payment about to be attempted.
		 *
		 * @param mixed $order WC_Order.
		 * @return void
		 */
		public static function on_store_api_order_processed( $order = null ): void {
			$order_id = ( is_object( $order ) && method_exists( $order, 'get_id' ) ) ? (int) $order->get_id() : 0;
			self::payment_attempt( $order_id );
		}

		/**
		 * @param int $order_id Order id.
		 * @return void
		 */
		private static function payment_attempt( int $order_id ): void {
			$sid = self::record( Lafka_Insights_DB::STAGE_PAY_ATTEMPT | Lafka_Insights_DB::STAGE_CHECKOUT | Lafka_Insights_DB::STAGE_CART | Lafka_Insights_DB::STAGE_ADD );
			if ( '' !== $sid && $order_id > 0 ) {
				set_transient( self::ORDER_SID_TRANSIENT . $order_id, Lafka_Insights_Session::today() . '|' . $sid, 2 * DAY_IN_SECONDS );
			}
		}

		/**
		 * First move into a "placed" status counts the order, once.
		 *
		 * @param int    $order_id Order id.
		 * @param string $from     Old status.
		 * @param string $to       New status.
		 * @param mixed  $order    WC_Order.
		 * @return void
		 */
		public static function on_order_status_changed( $order_id = 0, $from = '', $to = '', $order = null ): void {
			$placed = array( 'processing', 'on-hold', 'completed' );
			if ( function_exists( 'apply_filters' ) ) {
				$placed = (array) apply_filters( 'lafka_insights_placed_statuses', $placed );
			}
			if ( ! in_array( (string) $to, $placed, true ) ) {
				return;
			}
			$order = is_object( $order ) ? $order : ( function_exists( 'wc_get_order' ) ? wc_get_order( (int) $order_id ) : null );
			if ( ! is_object( $order ) || ! self::is_customer_order( $order ) ) {
				return;
			}
			if ( '1' === (string) $order->get_meta( self::COUNTED_META, true ) ) {
				return;
			}
			$order->update_meta_data( self::COUNTED_META, '1' );
			$order->save_meta_data();

			$items = array();
			foreach ( (array) $order->get_items() as $item ) {
				$pid = ( is_object( $item ) && method_exists( $item, 'get_product_id' ) ) ? (int) $item->get_product_id() : 0;
				if ( $pid > 0 ) {
					$items[ (string) $pid ] = 1;
				}
			}
			$counters = array( 'orders' => array( 'all' => 1 ) );
			if ( $items ) {
				$counters['item_order'] = $items;
			}
			self::record_for_order( (int) $order->get_id(), Lafka_Insights_DB::STAGE_ORDER, $counters, '' );
		}

		/**
		 * Payment failed: stage + class counter + a "payment_failed" refusal.
		 *
		 * @param int   $order_id Order id.
		 * @param mixed $order    WC_Order.
		 * @return void
		 */
		public static function on_order_failed( $order_id = 0, $order = null ): void {
			$order = is_object( $order ) ? $order : ( function_exists( 'wc_get_order' ) ? wc_get_order( (int) $order_id ) : null );
			if ( ! is_object( $order ) || ! self::is_customer_order( $order ) ) {
				return;
			}
			$note  = self::latest_order_note( (int) $order->get_id() );
			$class = self::classify_payment_failure( $note );
			self::record_for_order(
				(int) $order->get_id(),
				Lafka_Insights_DB::STAGE_PAY_FAILED,
				array( 'pay_fail' => array( $class => 1 ) ),
				'payment_' . $class
			);
		}

		/**
		 * `lafka_checkout_blocked` listener: one refusal per reason per request.
		 *
		 * @param string $reason  Reason slug (see Lafka_Insights::block_reasons()).
		 * @param array  $context Context (unused beyond the reason).
		 * @return void
		 */
		public static function on_checkout_blocked( $reason = '', $context = array() ): void {
			$reason = self::normalize_reason( (string) $reason );
			if ( '' === $reason || isset( self::$seen_reasons[ $reason ] ) ) {
				return;
			}
			self::$seen_reasons[ $reason ] = true;
			self::record( 0, array( 'block' => array( $reason => 1 ) ), $reason );
		}

		// ─── Recording ───────────────────────────────────────────────────────

		/**
		 * Record a stage (and counters / a refusal reason) for the current visit.
		 * Returns the visit id written ('' when the request is not measured).
		 *
		 * @param int                             $stages   Stage bits.
		 * @param array<string,array<string,int>> $counters Counter increments (always aggregated).
		 * @param string                          $reason   Refusal reason ('' = none).
		 * @return string
		 */
		public static function record( int $stages, array $counters = array(), string $reason = '' ): string {
			if ( ! Lafka_Insights_Session::request_allowed() ) {
				return '';
			}
			$day = Lafka_Insights_Session::today();
			$sid = Lafka_Insights_Session::current_visitor_id();
			self::write( $day, $sid, $stages, $counters, $reason );
			return $sid;
		}

		/**
		 * Record against the visit that placed an order (parked at payment
		 * attempt), falling back to the current request when it is the
		 * visitor's own. Aggregate counters are written even without a visit
		 * (they carry no identifier).
		 *
		 * @param int                             $order_id Order id.
		 * @param int                             $stages   Stage bits.
		 * @param array<string,array<string,int>> $counters Counters.
		 * @param string                          $reason   Refusal reason.
		 * @return void
		 */
		private static function record_for_order( int $order_id, int $stages, array $counters, string $reason ): void {
			$day    = Lafka_Insights_Session::today();
			$sid    = '';
			$parked = get_transient( self::ORDER_SID_TRANSIENT . $order_id );
			if ( is_string( $parked ) && 1 === preg_match( '/^(\d{4}-\d{2}-\d{2})\|([a-f0-9]{32})$/', $parked, $m ) ) {
				$day = $m[1];
				$sid = $m[2];
				if ( $stages & Lafka_Insights_DB::STAGE_ORDER ) {
					delete_transient( self::ORDER_SID_TRANSIENT . $order_id );
				}
			} elseif ( Lafka_Insights_Session::request_allowed() ) {
				$sid = Lafka_Insights_Session::current_visitor_id();
			}
			if ( '' === $sid ) {
				Lafka_Insights_DB::add_counters( Lafka_Insights_Session::today(), self::with_block_counter( $counters, $reason ) );
				return;
			}
			self::write( $day, $sid, $stages, $counters, $reason );
		}

		/**
		 * @param string                          $day      Y-m-d.
		 * @param string                          $sid      Visit id.
		 * @param int                             $stages   Stage bits.
		 * @param array<string,array<string,int>> $counters Counters.
		 * @param string                          $reason   Refusal reason.
		 * @return void
		 */
		private static function write( string $day, string $sid, int $stages, array $counters, string $reason ): void {
			$ua = Lafka_Insights_Session::user_agent();
			Lafka_Insights_DB::upsert_session(
				$day,
				$sid,
				array(
					'stages'     => $stages | Lafka_Insights_DB::STAGE_VISIT,
					'block_mask' => '' !== $reason ? self::reason_bit( $reason ) : 0,
					'last_block' => $reason,
					'device'     => Lafka_Insights_Session::device_from_ua( $ua ),
					'hour'       => function_exists( 'wp_date' ) ? (int) wp_date( 'G' ) : (int) gmdate( 'G' ),
					'dow'        => function_exists( 'wp_date' ) ? (int) wp_date( 'w' ) : (int) gmdate( 'w' ),
				)
			);
			$counters = self::with_block_counter( $counters, $reason );
			if ( $counters ) {
				Lafka_Insights_DB::add_counters( Lafka_Insights_Session::today(), $counters );
			}
		}

		/**
		 * @param array<string,array<string,int>> $counters Counters.
		 * @param string                          $reason   Refusal reason.
		 * @return array<string,array<string,int>>
		 */
		private static function with_block_counter( array $counters, string $reason ): array {
			if ( '' !== $reason && ! isset( $counters['block'] ) && 0 === strpos( $reason, 'payment_' ) ) {
				$counters['block'] = array( 'payment_failed' => 1 );
			}
			return $counters;
		}

		// ─── Classification helpers (pure) ───────────────────────────────────

		/**
		 * Normalise a refusal reason to a slug ≤ 32 chars.
		 *
		 * @param string $reason Raw reason.
		 * @return string
		 */
		public static function normalize_reason( string $reason ): string {
			return substr( (string) preg_replace( '/[^a-z0-9_]/', '', strtolower( str_replace( '-', '_', $reason ) ) ), 0, 32 );
		}

		/**
		 * Bit for a refusal reason in sessions.block_mask. Known reasons get a
		 * stable bit; payment_* share one; unknown reasons share "other".
		 * Filter `lafka_insights_block_reason_bits` to extend.
		 *
		 * @param string $reason Normalised reason.
		 * @return int
		 */
		public static function reason_bit( string $reason ): int {
			$bits = array(
				'store_closed'           => 1,
				'outside_delivery_zone'  => 2,
				'address_unpinned'       => 4,
				'below_delivery_minimum' => 8,
				'timeslot_invalid'       => 16,
				'branch_invalid'         => 32,
				'addon_invalid'          => 64,
				'validation'             => 128,
				'no_shipping_method'     => 256,
				'payment_failed'         => 512,
			);
			if ( function_exists( 'apply_filters' ) ) {
				$bits = (array) apply_filters( 'lafka_insights_block_reason_bits', $bits );
			}
			if ( isset( $bits[ $reason ] ) ) {
				return (int) $bits[ $reason ];
			}
			if ( 0 === strpos( $reason, 'payment' ) ) {
				return (int) ( $bits['payment_failed'] ?? 512 );
			}
			if ( 0 === strpos( $reason, 'validation' ) || 0 === strpos( $reason, 'field' ) ) {
				return (int) ( $bits['validation'] ?? 128 );
			}
			return 1 << 30;
		}

		/**
		 * Classify a gateway failure note: avs / cvv / declined / gateway_error / other.
		 * Keyword map filterable via `lafka_insights_payment_failure_keywords`.
		 *
		 * @param string $note Order note text.
		 * @return string
		 */
		public static function classify_payment_failure( string $note ): string {
			$map = array(
				'avs'           => '/\bavs\b|address verification|address (?:did not match|mismatch)/i',
				'cvv'           => '/\bcvv2?\b|\bcvc\b|card code|security code/i',
				'declined'      => '/declin|insufficient funds|do not honou?r|card (?:was )?refused|lost or stolen|expired card/i',
				'gateway_error' => '/timed? ?out|timeout|unavailable|connection|gateway error|internal error|error code/i',
			);
			if ( function_exists( 'apply_filters' ) ) {
				$map = (array) apply_filters( 'lafka_insights_payment_failure_keywords', $map );
			}
			foreach ( $map as $class => $pattern ) {
				if ( '' !== $note && 1 === preg_match( (string) $pattern, $note ) ) {
					return (string) $class;
				}
			}
			return 'other';
		}

		/**
		 * Only real customer orders (not admin-created / imported).
		 *
		 * @param object $order WC_Order.
		 * @return bool
		 */
		private static function is_customer_order( $order ): bool {
			if ( ! method_exists( $order, 'get_created_via' ) ) {
				return true;
			}
			return in_array( (string) $order->get_created_via(), array( 'checkout', 'store-api', '' ), true );
		}

		/**
		 * Text of the newest order note ('' when none).
		 *
		 * @param int $order_id Order id.
		 * @return string
		 */
		private static function latest_order_note( int $order_id ): string {
			if ( ! function_exists( 'wc_get_order_notes' ) ) {
				return '';
			}
			$notes = wc_get_order_notes(
				array(
					'order_id' => $order_id,
					'limit'    => 1,
				)
			);
			$note = is_array( $notes ) ? reset( $notes ) : null;
			return ( is_object( $note ) && isset( $note->content ) ) ? wp_strip_all_tags( (string) $note->content ) : '';
		}

		/**
		 * @return bool
		 */
		private static function cart_is_empty(): bool {
			if ( ! function_exists( 'WC' ) ) {
				return true;
			}
			$wc = WC();
			return ! is_object( $wc ) || empty( $wc->cart ) || ! method_exists( $wc->cart, 'is_empty' ) || $wc->cart->is_empty();
		}
	}
}
