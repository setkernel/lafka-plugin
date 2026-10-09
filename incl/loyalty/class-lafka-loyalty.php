<?php
/**
 * Loyalty points: settings, earning, clawback, expiry and the completed email.
 *
 * Points are earned when a customer's order reaches Completed, on the items
 * only (after discounts; never tips, fees, shipping or tax). The award is
 * written once per order: a status that flips back and forth does not award
 * twice. A refund (partial or full) takes back the same share of the points,
 * and cancelling an order after the award takes back what is left. A
 * clawback never drives a balance below zero: the part that could not be
 * taken is stored on the ledger row as its shortfall.
 *
 * Settings: WooCommerce → Settings → Restaurant → Loyalty
 *   lafka_loyalty_enabled        yes|no (default no)
 *   lafka_loyalty_earn_rate      points per 1.00 of items (default 1)
 *   lafka_loyalty_redeem_rate    points that are worth 1.00 off (default 100)
 *   lafka_loyalty_min_redeem     fewest points that can be redeemed (default 500)
 *   lafka_loyalty_max_share      most of the items a redemption can cover, in percent (default 50)
 *   lafka_loyalty_expiry_months  months without earning or redeeming after which points expire (0 = never)
 *
 * Order meta (the order is the record, HPOS-safe through the CRUD API):
 *   _lafka_loyalty_state   awarded | revoked
 *   _lafka_loyalty_earned  points awarded for this award cycle
 *   _lafka_loyalty_clawed  points taken back so far (including any shortfall)
 *   _lafka_loyalty_cycle   award cycle (a cancelled order that is completed again earns again)
 *
 * @package Lafka\Plugin\Loyalty
 * @since   10.4.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Lafka_Loyalty' ) ) {

	/**
	 * Loyalty points.
	 */
	final class Lafka_Loyalty {

		/** Maintenance hook (Action Scheduler, hourly). */
		const MAINTENANCE_HOOK = 'lafka_loyalty_maintenance';

		/** Action Scheduler group. */
		const GROUP = 'lafka-loyalty';

		/**
		 * Whether the module is on.
		 *
		 * @return bool
		 */
		public static function enabled(): bool {
			return 'yes' === lafka_setting( 'lafka_loyalty_enabled', 'no' );
		}

		/**
		 * Hook in.
		 *
		 * @return void
		 */
		public static function init(): void {
			// Before WooCommerce's own email hook (priority 10) so the email can say what was earned.
			add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'award' ), 5, 2 );
			add_action( 'woocommerce_order_status_cancelled', array( __CLASS__, 'claw_back_order' ), 10, 2 );
			add_action( 'woocommerce_order_status_failed', array( __CLASS__, 'claw_back_order' ), 10, 2 );
			add_action( 'woocommerce_order_refunded', array( __CLASS__, 'claw_back_refund' ), 10, 2 );
			add_action( 'woocommerce_refund_deleted', array( __CLASS__, 'restore_refund' ), 10, 2 );
			add_action( 'woocommerce_email_after_order_table', array( __CLASS__, 'email_line' ), 10, 4 );
			add_action( 'deleted_user', array( 'Lafka_Loyalty_Ledger', 'erase' ) );
			add_action( self::MAINTENANCE_HOOK, array( __CLASS__, 'maintenance' ) );
			add_action( 'init', array( __CLASS__, 'schedule' ), 20 );
		}

		// ---------------------------------------------------------------- settings.

		/**
		 * Points earned per 1.00 spent on items.
		 *
		 * @return float
		 */
		public static function earn_rate(): float {
			return max( 0.0, (float) lafka_setting( 'lafka_loyalty_earn_rate', 1 ) );
		}

		/**
		 * Points that are worth 1.00 off (also the step a redemption moves in).
		 *
		 * @return int
		 */
		public static function redeem_rate(): int {
			return max( 1, (int) lafka_setting( 'lafka_loyalty_redeem_rate', 100 ) );
		}

		/**
		 * Fewest points that can be redeemed in one go.
		 *
		 * @return int
		 */
		public static function min_redeem(): int {
			return max( self::redeem_rate(), (int) lafka_setting( 'lafka_loyalty_min_redeem', 500 ) );
		}

		/**
		 * Most of the items' value one redemption can cover, in percent.
		 *
		 * @return int
		 */
		public static function max_share(): int {
			return min( 100, max( 1, (int) lafka_setting( 'lafka_loyalty_max_share', 50 ) ) );
		}

		/**
		 * Months without earning or redeeming after which points expire; 0 = never.
		 *
		 * @return int
		 */
		public static function expiry_months(): int {
			return max( 0, (int) lafka_setting( 'lafka_loyalty_expiry_months', 0 ) );
		}

		/**
		 * What points are worth in money.
		 *
		 * @param int $points Points.
		 * @return float
		 */
		public static function value_of( int $points ): float {
			return round( $points / self::redeem_rate(), 2 );
		}

		/**
		 * Points as a number for display.
		 *
		 * @param int $points Points.
		 * @return string
		 */
		public static function format( int $points ): string {
			return number_format_i18n( $points );
		}

		// ---------------------------------------------------------------- earning.

		/**
		 * The part of an order's items that earns points: items after
		 * discounts, without tax, shipping, fees or tips.
		 *
		 * @param WC_Order $order Order.
		 * @return float
		 */
		public static function earn_base( $order ): float {
			return max( 0.0, (float) $order->get_subtotal() - (float) $order->get_total_discount( true ) );
		}

		/**
		 * Points a base earns.
		 *
		 * @param float         $base  Items value after discounts.
		 * @param WC_Order|null $order The order, when there is one.
		 * @return int
		 */
		public static function points_for( float $base, $order = null ): int {
			$points = (int) floor( $base * self::earn_rate() + 0.0001 );
			/**
			 * Filters the points an order's items earn.
			 *
			 * @since 10.4.0
			 *
			 * @param int           $points Points.
			 * @param float         $base   Items value after discounts.
			 * @param WC_Order|null $order  The order, or null for a cart estimate.
			 */
			return max( 0, (int) apply_filters( 'lafka_loyalty_earn_points', $points, $base, $order ) );
		}

		/**
		 * Award an order's points when it is completed.
		 *
		 * @param int           $order_id Order id.
		 * @param WC_Order|null $order    The order WooCommerce is changing (the email is built from this same object, so the award is written to it).
		 * @return void
		 */
		public static function award( $order_id, $order = null ): void {
			$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
			if ( ! $order instanceof WC_Order || ! self::enabled() || 'awarded' === $order->get_meta( '_lafka_loyalty_state' ) ) {
				return;
			}
			$user_id = (int) $order->get_customer_id();
			if ( $user_id <= 0 ) {
				return;
			}
			$cycle  = (int) $order->get_meta( '_lafka_loyalty_cycle' ) + 1;
			$points = self::points_for( self::earn_base( $order ), $order );
			if ( $points > 0 ) {
				/* translators: %s: order number. */
				$note   = sprintf( __( 'Order #%s', 'lafka-plugin' ), $order->get_order_number() );
				$result = Lafka_Loyalty_Ledger::add( $user_id, $points, 'earn', $order->get_id(), 'earn:' . $order->get_id() . ':' . $cycle, $note );
				if ( 'ok' !== $result['status'] && 'duplicate' !== $result['status'] ) {
					return;
				}
			}
			$order->update_meta_data( '_lafka_loyalty_state', 'awarded' );
			$order->update_meta_data( '_lafka_loyalty_cycle', $cycle );
			$order->update_meta_data( '_lafka_loyalty_earned', $points );
			$order->update_meta_data( '_lafka_loyalty_clawed', 0 );
			$order->save_meta_data();
		}

		// ---------------------------------------------------------------- clawback.

		/**
		 * Take back points from an order's award.
		 *
		 * @param WC_Order $order  Order.
		 * @param int      $points Points to take back (counted against the award, shortfall included).
		 * @param string   $reason ledger reason.
		 * @param string   $ref    Idempotency key.
		 * @param string   $note   Note.
		 * @return void
		 */
		private static function take_back( $order, int $points, string $reason, string $ref, string $note ): void {
			$user_id = (int) $order->get_customer_id();
			if ( $points <= 0 || $user_id <= 0 ) {
				return;
			}
			$result = Lafka_Loyalty_Ledger::add( $user_id, -$points, $reason, $order->get_id(), $ref, $note, 'clamp' );
			if ( 'ok' === $result['status'] ) {
				$order->update_meta_data( '_lafka_loyalty_clawed', (int) $order->get_meta( '_lafka_loyalty_clawed' ) + $points );
				$order->save_meta_data();
			}
		}

		/**
		 * A cancelled (or failed) order after an award: take back what is left.
		 *
		 * @param int           $order_id Order id.
		 * @param WC_Order|null $order    The order WooCommerce is changing.
		 * @return void
		 */
		public static function claw_back_order( $order_id, $order = null ): void {
			$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
			if ( ! $order instanceof WC_Order || 'awarded' !== $order->get_meta( '_lafka_loyalty_state' ) ) {
				return;
			}
			$left = (int) $order->get_meta( '_lafka_loyalty_earned' ) - (int) $order->get_meta( '_lafka_loyalty_clawed' );
			$ref  = 'cancel:' . $order->get_id() . ':' . (int) $order->get_meta( '_lafka_loyalty_cycle' );
			/* translators: %s: order number. */
			self::take_back( $order, $left, 'cancel', $ref, sprintf( __( 'Order #%s', 'lafka-plugin' ), $order->get_order_number() ) );
			$order->update_meta_data( '_lafka_loyalty_state', 'revoked' );
			$order->save_meta_data();
		}

		/**
		 * The share of an order's items a refund returns (0 to 1).
		 *
		 * A refund of items counts by their value, a refund of only shipping,
		 * fees or tax counts for nothing, and a refund of a bare amount counts
		 * by its share of the order total.
		 *
		 * @param WC_Order_Refund $refund Refund.
		 * @param WC_Order        $order  Order.
		 * @return float
		 */
		private static function refund_share( $refund, $order ): float {
			$items = 0.0;
			foreach ( $refund->get_items( 'line_item' ) as $item ) {
				$items += abs( (float) $item->get_total() );
			}
			if ( $items > 0 ) {
				$base = self::earn_base( $order );
				return $base > 0 ? min( 1.0, $items / $base ) : 1.0;
			}
			if ( array() !== $refund->get_items( array( 'shipping', 'fee' ) ) ) {
				return 0.0;
			}
			$total = (float) $order->get_total();
			return $total > 0 ? min( 1.0, abs( (float) $refund->get_amount() ) / $total ) : 0.0;
		}

		/**
		 * A refund: take back the same share of the award.
		 *
		 * @param int $order_id  Order id.
		 * @param int $refund_id Refund id.
		 * @return void
		 */
		public static function claw_back_refund( $order_id, $refund_id ): void {
			$order  = wc_get_order( $order_id );
			$refund = wc_get_order( $refund_id );
			if ( ! $order instanceof WC_Order || ! $refund instanceof WC_Order_Refund || 'awarded' !== $order->get_meta( '_lafka_loyalty_state' ) ) {
				return;
			}
			$earned = (int) $order->get_meta( '_lafka_loyalty_earned' );
			$share  = 0.0;
			foreach ( $order->get_refunds() as $each ) {
				$share += self::refund_share( $each, $order );
			}
			if ( $order->get_remaining_refund_amount() <= 0 ) {
				$share = 1.0;
			}
			$target = (int) round( $earned * min( 1.0, $share ) );
			$take   = $target - (int) $order->get_meta( '_lafka_loyalty_clawed' );
			/* translators: %s: order number. */
			self::take_back( $order, $take, 'refund', 'refund:' . $refund_id, sprintf( __( 'Order #%s', 'lafka-plugin' ), $order->get_order_number() ) );
		}

		/**
		 * A refund that is deleted gives back the points it took.
		 *
		 * @param int $refund_id Refund id.
		 * @param int $order_id  Order id.
		 * @return void
		 */
		public static function restore_refund( $refund_id, $order_id ): void {
			$row   = Lafka_Loyalty_Ledger::row_by_ref( 'refund:' . $refund_id );
			$order = wc_get_order( $order_id );
			if ( null === $row || ! $order instanceof WC_Order ) {
				return;
			}
			$result = Lafka_Loyalty_Ledger::add( (int) $row['user_id'], - (int) $row['delta'], 'refund', $order->get_id(), 'unrefund:' . $refund_id, __( 'Refund removed', 'lafka-plugin' ) );
			if ( 'ok' === $result['status'] ) {
				$order->update_meta_data( '_lafka_loyalty_clawed', max( 0, (int) $order->get_meta( '_lafka_loyalty_clawed' ) - ( - (int) $row['delta'] + (int) $row['shortfall'] ) ) );
				$order->save_meta_data();
			}
		}

		// ---------------------------------------------------------------- email.

		/**
		 * A line in the completed email: what the order earned and the balance.
		 *
		 * @param WC_Order $order         Order.
		 * @param bool     $sent_to_admin Whether the email goes to the shop.
		 * @param bool     $plain_text    Whether the email is plain text.
		 * @param WC_Email $email         Email.
		 * @return void
		 */
		public static function email_line( $order, $sent_to_admin = false, $plain_text = false, $email = null ): void {
			if ( $sent_to_admin || ! $email instanceof WC_Email || 'customer_completed_order' !== $email->id || ! $order instanceof WC_Order || 'awarded' !== $order->get_meta( '_lafka_loyalty_state' ) ) {
				return;
			}
			$earned = (int) $order->get_meta( '_lafka_loyalty_earned' );
			$user   = (int) $order->get_customer_id();
			if ( $earned <= 0 || $user <= 0 ) {
				return;
			}
			$balance = Lafka_Loyalty_Ledger::balance( $user );
			$text    = sprintf(
				/* translators: 1: points earned, 2: balance, 3: what the balance is worth. */
				_n( 'You earned %1$s point on this order. Your balance is %2$s points (worth %3$s).', 'You earned %1$s points on this order. Your balance is %2$s points (worth %3$s).', $earned, 'lafka-plugin' ),
				self::format( $earned ),
				self::format( $balance ),
				lafka_price_plain( self::value_of( $balance ) )
			);
			if ( $plain_text ) {
				echo esc_html( $text ) . "\n\n";
				return;
			}
			?>
			<p style="margin:0 0 20px;font-weight:bold;"><?php echo esc_html( $text ); ?></p>
			<?php
		}

		// ---------------------------------------------------------------- expiry and upkeep.

		/**
		 * Schedule the hourly upkeep (Action Scheduler).
		 *
		 * @return void
		 */
		public static function schedule(): void {
			if ( function_exists( 'as_has_scheduled_action' ) && ! as_has_scheduled_action( self::MAINTENANCE_HOOK, array(), self::GROUP ) ) {
				as_schedule_recurring_action( time() + 300, HOUR_IN_SECONDS, self::MAINTENANCE_HOOK, array(), self::GROUP );
			}
		}

		/**
		 * Hourly upkeep: release reservations nobody used and expire idle balances.
		 *
		 * @return void
		 */
		public static function maintenance(): void {
			if ( ! self::enabled() ) {
				return;
			}
			Lafka_Loyalty_Redeem::release_stale();
			self::expire_idle();
		}

		/**
		 * Expire the points of customers who have been idle for the set months.
		 *
		 * @return int Customers whose points expired.
		 */
		public static function expire_idle(): int {
			$months = self::expiry_months();
			if ( $months <= 0 ) {
				return 0;
			}
			$before = gmdate( 'Y-m-d H:i:s', strtotime( '-' . $months . ' months' ) );
			$count  = 0;
			foreach ( Lafka_Loyalty_Ledger::inactive_since( $before ) as $user_id ) {
				$balance = Lafka_Loyalty_Ledger::sum( $user_id );
				if ( $balance <= 0 ) {
					continue;
				}
				$result = Lafka_Loyalty_Ledger::add( $user_id, -$balance, 'expire', 0, 'expire:' . $user_id . ':' . gmdate( 'Ymd' ), __( 'Points expired', 'lafka-plugin' ) );
				if ( 'ok' === $result['status'] ) {
					++$count;
				}
			}
			return $count;
		}

		/**
		 * When a customer's points expire (a date), or '' when they do not.
		 *
		 * @param int $user_id User id.
		 * @return string
		 */
		public static function expires_on( int $user_id ): string {
			$months = self::expiry_months();
			$last   = Lafka_Loyalty_Ledger::last_activity( $user_id );
			if ( $months <= 0 || '' === $last || Lafka_Loyalty_Ledger::balance( $user_id ) <= 0 ) {
				return '';
			}
			$stamp = strtotime( $last . ' UTC +' . $months . ' months' );
			return false === $stamp ? '' : wp_date( get_option( 'date_format' ), $stamp );
		}
	}
}
