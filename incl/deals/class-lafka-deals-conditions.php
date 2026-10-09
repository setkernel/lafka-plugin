<?php
/**
 * Deal conditions: when, for whom and with what a deal can be ordered.
 *
 *   · hours window     a time of day (the store's clock, Lafka_Order_Hours)
 *   · order type       pickup only / delivery only (Lafka_Fulfilment)
 *   · max uses         per customer (account or billing email) and in total,
 *                      counted on paid orders
 *   · coupons          allow (WooCommerce's own behaviour) or block, through
 *                      WooCommerce's coupon validity check
 *
 * The days and dates a deal runs are Lafka_Deals::is_available_today(). The
 * time, hours and limit rules all fold into Lafka_Deals::unavailable_reason(),
 * which WC_Product_Lafka_Deal::is_purchasable() reads, so the page, the
 * builder, the AJAX endpoints and the cart re-check say the same thing. The
 * order type, the customer's limits and the coupon rule also guard a deal
 * already in the cart at the classic cart/checkout and the Store API.
 *
 * Uses are recorded as order meta (`_lafka_deal_used_{deal id}` = number of
 * times the deal is in the order) the moment an order becomes paid, and read
 * back with WooCommerce's order query, so HPOS and posts storage both work.
 *
 * @package Lafka\Plugin\Deals
 * @since   10.4.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Lafka_Deals_Conditions' ) ) {

	/**
	 * Deal conditions and their enforcement.
	 */
	final class Lafka_Deals_Conditions {

		/** Product meta: 'pickup', 'delivery' or '' (both). */
		const ORDER_TYPE_META = '_lafka_deal_order_type';

		/** Product meta: first and last time of day the deal runs ("HH:MM"). */
		const HOURS_FROM_META  = '_lafka_deal_hours_from';
		const HOURS_UNTIL_META = '_lafka_deal_hours_until';

		/** Product meta: most uses per customer / in total (0 = no limit). */
		const MAX_CUSTOMER_META = '_lafka_deal_max_customer';
		const MAX_TOTAL_META    = '_lafka_deal_max_total';

		/** Product meta: 'allow' or 'block' coupons on an order holding the deal. */
		const COUPONS_META = '_lafka_deal_coupons';

		/** Order meta prefix: the deal id follows, the value is the uses in that order. */
		const USED_META = '_lafka_deal_used_';

		/**
		 * Per-request use counts, keyed deal|user|email.
		 *
		 * @var array<string,int>
		 */
		private static $uses = array();

		/**
		 * Hook in.
		 *
		 * @return void
		 */
		public static function init(): void {
			add_action( 'woocommerce_check_cart_items', array( __CLASS__, 'check_cart_items' ) );
			add_action( 'woocommerce_store_api_cart_errors', array( __CLASS__, 'store_api_errors' ) );
			add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( __CLASS__, 'store_api_order_errors' ), 7 );
			add_filter( 'woocommerce_coupon_is_valid', array( __CLASS__, 'coupon_is_valid' ), 20, 2 );
			add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'record_uses' ), 10, 4 );
		}

		/**
		 * A deal's conditions, normalised.
		 *
		 * @param int|WC_Product $deal Deal.
		 * @return array{order_type: string, hours_from: string, hours_until: string, max_customer: int, max_total: int, coupons: string}
		 */
		public static function conditions( $deal ): array {
			$id    = is_object( $deal ) ? (int) $deal->get_id() : (int) $deal;
			$type  = (string) get_post_meta( $id, self::ORDER_TYPE_META, true );
			$time  = static function ( $value ): string {
				$minutes = Lafka_Order_Hours_Engine::to_minutes( (string) $value );
				return $minutes >= 0 ? Lafka_Order_Hours_Engine::to_hhmm( $minutes ) : '';
			};
			$from  = $time( get_post_meta( $id, self::HOURS_FROM_META, true ) );
			$until = $time( get_post_meta( $id, self::HOURS_UNTIL_META, true ) );
			return array(
				'order_type'   => in_array( $type, Lafka_Fulfilment::ALL_MODES, true ) ? $type : '',
				'hours_from'   => '' !== $from && '' !== $until && $from !== $until ? $from : '',
				'hours_until'  => '' !== $from && '' !== $until && $from !== $until ? $until : '',
				'max_customer' => max( 0, (int) get_post_meta( $id, self::MAX_CUSTOMER_META, true ) ),
				'max_total'    => max( 0, (int) get_post_meta( $id, self::MAX_TOTAL_META, true ) ),
				'coupons'      => 'block' === get_post_meta( $id, self::COUPONS_META, true ) ? 'block' : 'allow',
			);
		}

		/* ------------------------------------------------------------------ *
		 *  Who is ordering
		 * ------------------------------------------------------------------ */

		/**
		 * The customer as far as it is known: the account, else the billing
		 * email in the session.
		 *
		 * @return array{0: int, 1: string} User id and lower-case email ('' when unknown).
		 */
		public static function customer(): array {
			$user  = get_current_user_id();
			$email = '';
			if ( function_exists( 'WC' ) && WC()->customer instanceof WC_Customer ) {
				$email = strtolower( trim( (string) WC()->customer->get_billing_email() ) );
			}
			return array( $user, is_email( $email ) ? $email : '' );
		}

		/**
		 * A short key for the customer, for per-request caches.
		 *
		 * @return string
		 */
		public static function customer_key(): string {
			list( $user, $email ) = self::customer();
			return $user . '|' . $email;
		}

		/* ------------------------------------------------------------------ *
		 *  Hours
		 * ------------------------------------------------------------------ */

		/**
		 * Whether the store's clock is inside the deal's hours (always true
		 * without a window). A window whose end is at or before its start
		 * runs past midnight, as in the opening hours.
		 *
		 * @param array{hours_from: string, hours_until: string} $conditions Conditions.
		 * @return bool
		 */
		public static function in_hours( array $conditions ): bool {
			if ( '' === $conditions['hours_from'] ) {
				return true;
			}
			$from  = Lafka_Order_Hours_Engine::to_minutes( $conditions['hours_from'] );
			$until = Lafka_Order_Hours_Engine::to_minutes( $conditions['hours_until'] );
			$now   = class_exists( 'Lafka_Order_Hours' ) ? Lafka_Order_Hours::get_order_hours_time() : new DateTime( 'now', wp_timezone() );
			$mins  = ( (int) $now->format( 'G' ) * 60 ) + (int) $now->format( 'i' );
			return $from < $until ? ( $mins >= $from && $mins < $until ) : ( $mins >= $from || $mins < $until );
		}

		/**
		 * "11:00 AM to 2:00 PM".
		 *
		 * @param array{hours_from: string, hours_until: string} $conditions Conditions.
		 * @return string
		 */
		private static function hours_text( array $conditions ): string {
			$format = (string) get_option( 'time_format', 'g:i A' );
			/* translators: 1: opening time, 2: closing time, e.g. "11:00 AM to 2:00 PM". */
			return sprintf( __( '%1$s to %2$s', 'lafka-plugin' ), gmdate( $format, Lafka_Order_Hours_Engine::to_minutes( $conditions['hours_from'] ) * 60 ), gmdate( $format, Lafka_Order_Hours_Engine::to_minutes( $conditions['hours_until'] ) * 60 ) );
		}

		/* ------------------------------------------------------------------ *
		 *  Uses
		 * ------------------------------------------------------------------ */

		/**
		 * How many times a deal was used on paid orders: by anyone, or by one
		 * customer (account or billing email).
		 *
		 * @param int    $deal_id Deal id.
		 * @param int    $user    User id (0 = not by account).
		 * @param string $email   Billing email ('' = not by email).
		 * @param bool   $total   Count everyone's.
		 * @return int
		 */
		public static function uses( int $deal_id, int $user = 0, string $email = '', bool $total = false ): int {
			$cache = $deal_id . '|' . ( $total ? 'all' : $user . '|' . $email );
			if ( isset( self::$uses[ $cache ] ) ) {
				return self::$uses[ $cache ];
			}
			if ( ! $total && 0 === $user && '' === $email ) {
				return 0;
			}
			$base = array(
				'type'       => 'shop_order',
				'status'     => wc_get_is_paid_statuses(),
				'limit'      => -1,
				'return'     => 'ids',
				'meta_query' => array(
					array(
						'key'     => self::USED_META . $deal_id,
						'compare' => 'EXISTS',
					),
				),
			);
			$ids  = array();
			if ( $total ) {
				$ids = (array) wc_get_orders( $base );
			} else {
				if ( $user > 0 ) {
					$ids = array_merge( $ids, (array) wc_get_orders( array_merge( $base, array( 'customer_id' => $user ) ) ) );
				}
				if ( '' !== $email ) {
					$ids = array_merge( $ids, (array) wc_get_orders( array_merge( $base, array( 'billing_email' => $email ) ) ) );
				}
			}
			$count = 0;
			foreach ( array_unique( array_map( 'intval', $ids ) ) as $order_id ) {
				$order  = wc_get_order( $order_id );
				$count += $order ? (int) $order->get_meta( self::USED_META . $deal_id ) : 0;
			}
			self::$uses[ $cache ] = $count;
			return $count;
		}

		/**
		 * Forget the per-request use counts.
		 *
		 * @return void
		 */
		public static function flush(): void {
			self::$uses = array();
		}

		/**
		 * Record a deal's uses on an order the moment it is paid.
		 *
		 * @param int      $order_id Order id.
		 * @param string   $from     Old status.
		 * @param string   $to       New status.
		 * @param WC_Order $order    Order.
		 * @return void
		 */
		public static function record_uses( $order_id, $from, $to, $order = null ): void {
			unset( $from );
			if ( ! $order instanceof WC_Order || ! in_array( (string) $to, wc_get_is_paid_statuses(), true ) ) {
				return;
			}
			$groups = array();
			foreach ( $order->get_items() as $item ) {
				$deal_id = (int) $item->get_meta( '_lafka_deal_id' );
				$group   = (string) $item->get_meta( '_lafka_deal_group' );
				if ( $deal_id > 0 && '' !== $group ) {
					$groups[ $deal_id ][ $group ] = true;
				}
			}
			$changed = false;
			foreach ( $groups as $deal_id => $set ) {
				if ( (int) $order->get_meta( self::USED_META . $deal_id ) !== count( $set ) ) {
					$order->update_meta_data( self::USED_META . $deal_id, count( $set ) );
					$changed = true;
				}
			}
			if ( $changed ) {
				$order->save_meta_data();
			}
			self::flush();
			Lafka_Deals::flush();
		}

		/**
		 * Why a deal's hours or limits stop it being ordered, or ''. Part of
		 * Lafka_Deals::unavailable_reason(): "no further use allowed", not
		 * counting what the cart already holds.
		 *
		 * @param int  $deal_id Deal id.
		 * @param bool $in_cart Judging a deal already in the cart: the customer's own limit is left to cart_errors().
		 * @return string
		 */
		public static function time_or_limit_reason( int $deal_id, bool $in_cart = false ): string {
			$conditions = self::conditions( $deal_id );
			if ( ! self::in_hours( $conditions ) ) {
				/* translators: %s: hours, e.g. "11:00 AM to 2:00 PM". */
				return sprintf( __( 'This deal runs from %s.', 'lafka-plugin' ), self::hours_text( $conditions ) );
			}
			if ( $conditions['max_total'] > 0 && self::uses( $deal_id, 0, '', true ) >= $conditions['max_total'] ) {
				return __( 'This deal has sold out.', 'lafka-plugin' );
			}
			if ( $conditions['max_customer'] > 0 && ! $in_cart ) {
				list( $user, $email ) = self::customer();
				if ( self::uses( $deal_id, $user, $email ) >= $conditions['max_customer'] ) {
					return self::customer_limit_message( $conditions['max_customer'] );
				}
			}
			return '';
		}

		/**
		 * "You have already used this deal (limit: 1 per customer)."
		 *
		 * @param int $max Most uses per customer.
		 * @return string
		 */
		private static function customer_limit_message( int $max ): string {
			/* translators: %d: most times one customer can use the deal. */
			return sprintf( _n( 'You have already used this deal. It is limited to %d use per customer.', 'You have already used this deal. It is limited to %d uses per customer.', $max, 'lafka-plugin' ), $max );
		}

		/* ------------------------------------------------------------------ *
		 *  Order type
		 * ------------------------------------------------------------------ */

		/**
		 * The order type in force: the shipping rate chosen in the session
		 * (pickup when every rate is a pickup), else the visitor's preference.
		 * A customer who asked for delivery but is on the pickup rate only
		 * because no delivery rate exists yet (no address) still counts as
		 * delivery: that order cannot be placed as pickup either.
		 *
		 * @return string 'pickup', 'delivery' or ''.
		 */
		public static function order_mode(): string {
			$chosen = function_exists( 'WC' ) && WC()->session ? array_values( array_filter( array_map( 'strval', (array) WC()->session->get( 'chosen_shipping_methods', array() ) ) ) ) : array();
			if ( array() !== $chosen ) {
				foreach ( $chosen as $rate ) {
					if ( ! lafka_is_pickup_shipping_method( $rate ) ) {
						return 'delivery';
					}
				}
				return Lafka_Fulfilment::delivery_would_become_pickup( $chosen ) ? 'delivery' : 'pickup';
			}
			return Lafka_Fulfilment::current_mode();
		}

		/**
		 * Why the order type rules a deal out, or ''.
		 *
		 * @param WC_Product $deal Deal.
		 * @param string     $mode Order type; default the one in force.
		 * @return string
		 */
		public static function order_type_error( WC_Product $deal, string $mode = '' ): string {
			$type = self::conditions( $deal )['order_type'];
			$mode = '' === $mode ? self::order_mode() : $mode;
			if ( '' === $type || '' === $mode || $type === $mode ) {
				return '';
			}
			if ( 'pickup' === $type ) {
				/* translators: %s: deal name. */
				return sprintf( __( '"%s" is for pickup orders only. Choose Pickup to order it.', 'lafka-plugin' ), $deal->get_name() );
			}
			/* translators: %s: deal name. */
			return sprintf( __( '"%s" is for delivery orders only. Choose Delivery to order it.', 'lafka-plugin' ), $deal->get_name() );
		}

		/* ------------------------------------------------------------------ *
		 *  The cart
		 * ------------------------------------------------------------------ */

		/**
		 * Deals in the cart: deal id => times it is in the cart.
		 *
		 * @return array<int,int>
		 */
		public static function cart_deals(): array {
			$deals = array();
			if ( function_exists( 'WC' ) && WC()->cart ) {
				foreach ( Lafka_Deals_Cart::groups( WC()->cart->get_cart() ) as $items ) {
					$id           = (int) Lafka_Deals_Cart::deal_of( (array) reset( $items ) )['deal_id'];
					$deals[ $id ] = ( $deals[ $id ] ?? 0 ) + 1;
				}
			}
			return $deals;
		}

		/**
		 * Why the deals already in the cart cannot be ordered as the cart
		 * stands: the order type, the limits (counting the cart's own uses).
		 *
		 * @param string $mode  Order type; default the one in force.
		 * @param int    $user  User id; default the customer's.
		 * @param string $email Billing email; default the customer's.
		 * @return string[]
		 */
		public static function cart_errors( string $mode = '', int $user = -1, string $email = '' ): array {
			$errors = array();
			if ( $user < 0 ) {
				list( $user, $email ) = self::customer();
			}
			foreach ( self::cart_deals() as $deal_id => $in_cart ) {
				$deal = wc_get_product( $deal_id );
				if ( ! $deal ) {
					continue;
				}
				$conditions = self::conditions( $deal );
				$type       = self::order_type_error( $deal, $mode );
				if ( '' !== $type ) {
					$errors[] = $type;
				}
				if ( $conditions['max_total'] > 0 && self::uses( $deal_id, 0, '', true ) + $in_cart > $conditions['max_total'] ) {
					/* translators: %s: deal name. */
					$errors[] = sprintf( __( '"%s" has sold out. Remove it from your order.', 'lafka-plugin' ), $deal->get_name() );
				}
				if ( $conditions['max_customer'] > 0 && ( $user > 0 || '' !== $email ) && self::uses( $deal_id, $user, $email ) + $in_cart > $conditions['max_customer'] ) {
					/* translators: 1: deal name, 2: the limit sentence. */
					$errors[] = sprintf( '"%1$s": %2$s', $deal->get_name(), self::customer_limit_message( $conditions['max_customer'] ) );
				}
			}
			return $errors;
		}

		/**
		 * Classic cart and checkout: the same refusals, as errors. WooCommerce
		 * runs this when the cart or checkout loads and again when the order is
		 * placed (after the posted shipping method and email are in the session).
		 *
		 * @return void
		 */
		public static function check_cart_items(): void {
			foreach ( self::cart_errors() as $message ) {
				wc_add_notice( $message, 'error' );
			}
		}

		/**
		 * Block cart and checkout: the same refusals on the Store API cart.
		 *
		 * @param mixed $errors Store API error bag.
		 * @return void
		 */
		public static function store_api_errors( $errors ): void {
			if ( ! is_object( $errors ) || ! method_exists( $errors, 'add' ) ) {
				return;
			}
			foreach ( self::cart_errors() as $index => $message ) {
				$errors->add( 'lafka_deal_condition_' . $index, $message );
			}
		}

		/**
		 * Block place-order: the limits again, by the order's own account and
		 * billing email (the session may not know the email yet).
		 *
		 * @param mixed $order WC_Order being placed.
		 * @return void
		 *
		 * @throws \Automattic\WooCommerce\StoreApi\Exceptions\RouteException When a limit is exceeded.
		 * @throws \RuntimeException Fallback without the Store API.
		 */
		public static function store_api_order_errors( $order ): void {
			if ( ! $order instanceof WC_Order ) {
				return;
			}
			$errors = self::cart_errors( '', (int) $order->get_customer_id(), strtolower( trim( (string) $order->get_billing_email() ) ) );
			if ( array() === $errors ) {
				return;
			}
			if ( class_exists( '\Automattic\WooCommerce\StoreApi\Exceptions\RouteException' ) ) {
				throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'lafka_deal_condition', esc_html( $errors[0] ), 400 );
			}
			throw new \RuntimeException( esc_html( $errors[0] ) );
		}

		/**
		 * The first deal in the cart that does not allow coupons.
		 *
		 * @return WC_Product|null
		 */
		private static function coupon_blocker(): ?WC_Product {
			foreach ( array_keys( self::cart_deals() ) as $deal_id ) {
				if ( 'block' === self::conditions( $deal_id )['coupons'] ) {
					$deal = wc_get_product( $deal_id );
					if ( $deal ) {
						return $deal;
					}
				}
			}
			return null;
		}

		/**
		 * WooCommerce's coupon check: no coupon on an order holding a deal that
		 * blocks them. The coupon stays usable on its own; WooCommerce shows
		 * the message and drops a coupon already applied.
		 *
		 * @param bool      $valid  Valid so far.
		 * @param WC_Coupon $coupon Coupon.
		 * @return bool
		 *
		 * @throws Exception With the reason, which WooCommerce shows as the coupon error.
		 */
		public static function coupon_is_valid( $valid, $coupon ) {
			unset( $coupon );
			$blocker = $valid ? self::coupon_blocker() : null;
			if ( $blocker ) {
				/* translators: %s: deal name. */
				throw new Exception( esc_html( sprintf( __( 'Coupons can\'t be used with the "%s" deal. Remove the deal to use a coupon.', 'lafka-plugin' ), $blocker->get_name() ) ) );
			}
			return (bool) $valid;
		}

		/* ------------------------------------------------------------------ *
		 *  The builder
		 * ------------------------------------------------------------------ */

		/**
		 * Why this deal cannot be added to the cart now, or '': its hours and
		 * limits, the order type, what the cart already holds of it, and
		 * coupons already applied when the deal does not allow them.
		 *
		 * @param WC_Product $deal Deal.
		 * @return string
		 */
		public static function builder_error( WC_Product $deal ): string {
			$reason = Lafka_Deals::unavailable_reason( $deal );
			if ( '' !== $reason ) {
				return $reason;
			}
			$reason = self::order_type_error( $deal );
			if ( '' !== $reason ) {
				return $reason;
			}
			$conditions = self::conditions( $deal );
			$in_cart    = self::cart_deals()[ $deal->get_id() ] ?? 0;
			if ( $conditions['max_total'] > 0 && self::uses( $deal->get_id(), 0, '', true ) + $in_cart + 1 > $conditions['max_total'] ) {
				return __( 'There are no more of this deal left.', 'lafka-plugin' );
			}
			list( $user, $email ) = self::customer();
			if ( $conditions['max_customer'] > 0 && self::uses( $deal->get_id(), $user, $email ) + $in_cart + 1 > $conditions['max_customer'] ) {
				return $in_cart > 0 && self::uses( $deal->get_id(), $user, $email ) < $conditions['max_customer']
					/* translators: %d: most times one customer can use the deal. */
					? sprintf( _n( 'This deal is already in your order. It is limited to %d use per customer.', 'This deal is already in your order. It is limited to %d uses per customer.', $conditions['max_customer'], 'lafka-plugin' ), $conditions['max_customer'] )
					: self::customer_limit_message( $conditions['max_customer'] );
			}
			if ( 'block' === $conditions['coupons'] && function_exists( 'WC' ) && WC()->cart && array() !== WC()->cart->get_applied_coupons() ) {
				return __( 'This deal can\'t be used with a coupon. Remove the coupon from your order first.', 'lafka-plugin' );
			}
			return '';
		}

		/**
		 * The deal's conditions as short phrases for its page: "11:00 AM to
		 * 2:00 PM", "delivery orders only", "1 per customer".
		 *
		 * @param WC_Product $deal Deal.
		 * @return string[]
		 */
		public static function notes( WC_Product $deal ): array {
			$conditions = self::conditions( $deal );
			$notes      = array();
			if ( '' !== $conditions['hours_from'] ) {
				$notes[] = self::hours_text( $conditions );
			}
			if ( 'pickup' === $conditions['order_type'] ) {
				$notes[] = __( 'pickup orders only', 'lafka-plugin' );
			} elseif ( 'delivery' === $conditions['order_type'] ) {
				$notes[] = __( 'delivery orders only', 'lafka-plugin' );
			}
			if ( $conditions['max_customer'] > 0 ) {
				/* translators: %d: most uses per customer. */
				$notes[] = sprintf( __( 'limit %d per customer', 'lafka-plugin' ), $conditions['max_customer'] );
			}
			if ( 'block' === $conditions['coupons'] ) {
				$notes[] = __( 'coupons can\'t be used with it', 'lafka-plugin' );
			}
			return $notes;
		}
	}
}
