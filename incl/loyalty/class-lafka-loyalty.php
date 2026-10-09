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
 *   _lafka_loyalty_state   awarded (completed at least once and standing) | revoked (cancelled / failed)
 *   _lafka_loyalty_earned  what the order is entitled to now (shown in the completed email)
 * What the customer holds from an order is the sum of its earn / refund /
 * cancel / settle ledger rows (Lafka_Loyalty_Ledger::order_net()).
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
		//
		// One rule for every event (completed, cancelled, failed, refunded, a
		// refund deleted): what the order is ENTITLED to now is compared with
		// what the customer still HOLDS from it in the ledger, and the
		// difference is credited or taken back. Points that were already spent
		// when something had to be taken back stay counted as held, so
		// cancelling and completing again (in any order, any number of times)
		// never earns twice, and they are recovered from the customer's next
		// earnings (settle_debts()).

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
		 * The share of an order's items refunded so far (0 to 1).
		 *
		 * @param WC_Order $order Order.
		 * @return float
		 */
		private static function refunded_share( $order ): float {
			if ( $order->get_remaining_refund_amount() <= 0 && (float) $order->get_total() > 0 ) {
				return 1.0;
			}
			$share = 0.0;
			foreach ( $order->get_refunds() as $refund ) {
				$share += self::refund_share( $refund, $order );
			}
			return max( 0.0, min( 1.0, $share ) );
		}

		/**
		 * The share of an order's items one refund returns (0 to 1).
		 *
		 * A refund of items counts by their value; a refund of only shipping
		 * or fees counts for nothing; a bare amount counts against the items
		 * first (a refund typed as an amount is almost always food).
		 *
		 * @param WC_Order_Refund $refund Refund.
		 * @param WC_Order        $order  Order.
		 * @return float
		 */
		private static function refund_share( $refund, $order ): float {
			$base = self::earn_base( $order );
			if ( $base <= 0 ) {
				return 1.0;
			}
			$items = 0.0;
			foreach ( $refund->get_items( 'line_item' ) as $item ) {
				$items += abs( (float) $item->get_total() );
			}
			if ( $items > 0 ) {
				return min( 1.0, $items / $base );
			}
			if ( array() !== $refund->get_items( array( 'shipping', 'fee' ) ) ) {
				return 0.0;
			}
			return min( 1.0, abs( (float) $refund->get_amount() ) / $base );
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
		 * What an order is entitled to now: its items after discounts and
		 * refunds once it has been completed, nothing once it is cancelled or
		 * failed (or before it is completed).
		 *
		 * @param WC_Order $order Order.
		 * @return int
		 */
		public static function entitled( $order ): int {
			if ( 'awarded' !== $order->get_meta( '_lafka_loyalty_state' ) ) {
				return 0;
			}
			return self::points_for( self::earn_base( $order ) * ( 1.0 - self::refunded_share( $order ) ), $order );
		}

		/**
		 * Bring what the customer holds from an order in line with what it is
		 * entitled to, under the customer's ledger lock.
		 *
		 * @param WC_Order $order  Order.
		 * @param string   $reason Ledger reason for a debit: refund | cancel.
		 * @return int Points credited (> 0) or taken back (< 0, before clamping).
		 */
		private static function reconcile( $order, string $reason ): int {
			$user_id = (int) $order->get_customer_id();
			if ( $user_id <= 0 ) {
				return 0;
			}
			/* translators: %s: order number. */
			$note = sprintf( __( 'Order #%s', 'lafka-plugin' ), $order->get_order_number() );
			$diff = 0;
			Lafka_Loyalty_Ledger::locked(
				$user_id,
				static function () use ( $order, $user_id, $reason, $note, &$diff ) {
					$diff = self::entitled( $order ) - Lafka_Loyalty_Ledger::order_net( $order->get_id() );
					if ( $diff > 0 ) {
						$result = Lafka_Loyalty_Ledger::add( $user_id, $diff, 'earn', $order->get_id(), null, $note );
						if ( 'ok' === $result['status'] ) {
							self::settle_debts( $user_id, $diff, $order->get_id() );
						}
					} elseif ( $diff < 0 ) {
						Lafka_Loyalty_Ledger::add( $user_id, $diff, $reason, $order->get_id(), null, $note, 'clamp' );
					}
				}
			);
			$order->update_meta_data( '_lafka_loyalty_earned', self::entitled( $order ) );
			$order->save_meta_data();
			return $diff;
		}

		/**
		 * Recover points still owed from other orders (taken back after the
		 * customer had spent them) out of points just earned.
		 *
		 * @param int $user_id   User id.
		 * @param int $available Points just credited.
		 * @param int $except    The order that earned them.
		 * @return void
		 */
		private static function settle_debts( int $user_id, int $available, int $except ): void {
			foreach ( Lafka_Loyalty_Ledger::orders_held( $user_id ) as $order_id => $held ) {
				if ( $available <= 0 ) {
					return;
				}
				if ( $order_id === $except ) {
					continue;
				}
				$other = wc_get_order( $order_id );
				$owe   = $other instanceof WC_Order ? $held - self::entitled( $other ) : 0;
				if ( $owe <= 0 ) {
					continue;
				}
				$take = min( $owe, $available );
				/* translators: %s: order number. */
				$result     = Lafka_Loyalty_Ledger::add( $user_id, -$take, 'settle', $order_id, null, sprintf( __( 'Points owed from order #%s', 'lafka-plugin' ), $other->get_order_number() ), 'clamp' );
				$available -= max( 0, - (int) $result['applied'] );
			}
		}

		/**
		 * An order is completed: it earns.
		 *
		 * @param int           $order_id Order id.
		 * @param WC_Order|null $order    The order WooCommerce is changing (the email is built from this same object, so the award is written to it).
		 * @return void
		 */
		public static function award( $order_id, $order = null ): void {
			$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
			if ( ! $order instanceof WC_Order || ! self::enabled() || (int) $order->get_customer_id() <= 0 ) {
				return;
			}
			$order->update_meta_data( '_lafka_loyalty_state', 'awarded' );
			self::reconcile( $order, 'refund' );
		}

		/**
		 * A cancelled or failed order is entitled to nothing: take back what
		 * the customer holds from it.
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
			$order->update_meta_data( '_lafka_loyalty_state', 'revoked' );
			self::reconcile( $order, 'cancel' );
		}

		/**
		 * A refund was made: the order is entitled to less.
		 *
		 * @param int $order_id  Order id.
		 * @param int $refund_id Refund id.
		 * @return void
		 */
		public static function claw_back_refund( $order_id, $refund_id ): void {
			unset( $refund_id );
			$order = wc_get_order( $order_id );
			if ( $order instanceof WC_Order ) {
				self::reconcile( $order, 'refund' );
			}
		}

		/**
		 * A refund was deleted: the order is entitled to more again (only
		 * while it stands; a cancelled order stays at nothing).
		 *
		 * @param int $refund_id Refund id.
		 * @param int $order_id  Order id.
		 * @return void
		 */
		public static function restore_refund( $refund_id, $order_id ): void {
			unset( $refund_id );
			$order = wc_get_order( $order_id );
			if ( $order instanceof WC_Order ) {
				self::reconcile( $order, 'refund' );
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
