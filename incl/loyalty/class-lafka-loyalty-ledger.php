<?php
/**
 * Loyalty points ledger: one row per change of a customer's balance.
 *
 * The ledger is the truth. A row holds the signed change (delta), why it
 * happened (reason), the order it belongs to (a WooCommerce order id, so it
 * works with HPOS) and the balance after it. The balance cached in user meta
 * is always the sum of the ledger and can be rebuilt with `wp lafka loyalty
 * recalc`.
 *
 * Every change goes through add(), which takes a per-customer database lock
 * (GET_LOCK) around "read the balance, check it, insert the row, store the
 * balance". Two requests for the same customer therefore run one after the
 * other, so two tabs can never spend the same points. A row with a `ref` is
 * written once: repeating the call with the same ref does nothing, which is
 * what makes awards and clawbacks safe to retry.
 *
 * A negative change never takes the balance below zero. In 'clamp' mode it
 * is cut to the balance and the part that could not be taken is stored as
 * the row's shortfall; in 'strict' mode (a reservation) it is refused.
 *
 * @package Lafka\Plugin\Loyalty
 * @since   10.4.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Lafka_Loyalty_Ledger' ) ) {

	/**
	 * Ledger access.
	 */
	final class Lafka_Loyalty_Ledger {

		/** User meta caching the balance. */
		const META_BALANCE = 'lafka_loyalty_balance';

		/** Seconds to wait for the customer lock. */
		const LOCK_WAIT = 10;

		/**
		 * The table name.
		 *
		 * @return string
		 */
		public static function table(): string {
			return Lafka_Schema::table_name( 'loyalty' );
		}

		/**
		 * The balance: the cached value, or the sum of the ledger when none is cached.
		 *
		 * @param int $user_id User id.
		 * @return int
		 */
		public static function balance( int $user_id ): int {
			$cached = get_user_meta( $user_id, self::META_BALANCE, true );
			if ( '' !== $cached && false !== $cached ) {
				return (int) $cached;
			}
			$sum = self::sum( $user_id );
			update_user_meta( $user_id, self::META_BALANCE, $sum );
			return $sum;
		}

		/**
		 * The sum of the customer's ledger rows.
		 *
		 * @param int $user_id User id.
		 * @return int
		 */
		public static function sum( int $user_id ): int {
			global $wpdb;
			return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(delta),0) FROM %i WHERE user_id = %d', self::table(), $user_id ) );
		}

		/**
		 * Add one row.
		 *
		 * @param int         $user_id  User id.
		 * @param int         $delta    Signed change in points.
		 * @param string      $reason   earn | reserve | release | refund | cancel | expire | adjust.
		 * @param int         $order_id WooCommerce order id, or 0.
		 * @param string|null $ref      Idempotency key; null for rows that may repeat.
		 * @param string      $note     Short note (shown to the customer).
		 * @param string      $mode     'clamp' (a debit is cut to the balance) or 'strict' (a debit that does not fit is refused).
		 * @return array{status:string,applied:int,shortfall:int,balance:int} status is ok | duplicate | insufficient | locked | error.
		 */
		public static function add( int $user_id, int $delta, string $reason, int $order_id = 0, ?string $ref = null, string $note = '', string $mode = 'clamp' ): array {
			global $wpdb;
			$fail = static function ( string $status ) use ( $user_id ): array {
				return array(
					'status'    => $status,
					'applied'   => 0,
					'shortfall' => 0,
					'balance'   => self::balance( $user_id ),
				);
			};
			if ( $user_id <= 0 || 0 === $delta ) {
				return $fail( 'error' );
			}

			$lock = 'lafka_loyalty_' . $user_id;
			// A lock held by another request means a change for this customer is under way: wait for it.
			if ( 1 !== (int) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $lock, self::LOCK_WAIT ) ) ) {
				return $fail( 'locked' );
			}

			try {
				if ( null !== $ref && (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE ref = %s', self::table(), $ref ) ) > 0 ) {
					return $fail( 'duplicate' );
				}
				$balance   = self::sum( $user_id );
				$applied   = $delta;
				$shortfall = 0;
				if ( $delta < 0 && -$delta > $balance ) {
					if ( 'strict' === $mode ) {
						return $fail( 'insufficient' );
					}
					$applied   = -$balance;
					$shortfall = -$delta - $balance;
				}
				if ( 0 === $applied && 0 === $shortfall ) {
					return $fail( 'error' );
				}
				$new = $balance + $applied;
				// A row with nothing to apply (the balance was already empty) still records the shortfall.
				$ok = $wpdb->insert(
					self::table(),
					array(
						'user_id'       => $user_id,
						'order_id'      => max( 0, $order_id ),
						'delta'         => $applied,
						'reason'        => $reason,
						'ref'           => $ref,
						'note'          => mb_substr( $note, 0, 255 ),
						'shortfall'     => $shortfall,
						'balance_after' => $new,
						'created_at'    => gmdate( 'Y-m-d H:i:s' ),
					),
					array( '%d', '%d', '%d', '%s', '%s', '%s', '%d', '%d', '%s' )
				);
				if ( false === $ok ) {
					return $fail( 'error' );
				}
				update_user_meta( $user_id, self::META_BALANCE, $new );
				return array(
					'status'    => 'ok',
					'applied'   => $applied,
					'shortfall' => $shortfall,
					'balance'   => $new,
				);
			} finally {
				$wpdb->get_var( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock ) );
			}
		}

		/**
		 * The row with a ref, or null.
		 *
		 * @param string $ref Ref.
		 * @return array<string,mixed>|null
		 */
		public static function row_by_ref( string $ref ): ?array {
			global $wpdb;
			$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE ref = %s', self::table(), $ref ), ARRAY_A );
			return is_array( $row ) ? $row : null;
		}

		/**
		 * Point a reservation's row at the order it was spent on.
		 *
		 * @param string $ref      Ref of the row.
		 * @param int    $order_id Order id.
		 * @return void
		 */
		public static function set_order( string $ref, int $order_id ): void {
			global $wpdb;
			$wpdb->update( self::table(), array( 'order_id' => $order_id ), array( 'ref' => $ref ), array( '%d' ), array( '%s' ) );
		}

		/**
		 * A customer's rows, newest first.
		 *
		 * @param int $user_id User id.
		 * @param int $limit   Rows.
		 * @param int $offset  Offset.
		 * @return array<int,array<string,mixed>>
		 */
		public static function history( int $user_id, int $limit = 20, int $offset = 0 ): array {
			global $wpdb;
			$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE user_id = %d ORDER BY id DESC LIMIT %d OFFSET %d', self::table(), $user_id, $limit, $offset ), ARRAY_A );
			return is_array( $rows ) ? $rows : array();
		}

		/**
		 * Number of rows of a customer.
		 *
		 * @param int $user_id User id.
		 * @return int
		 */
		public static function count( int $user_id ): int {
			global $wpdb;
			return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE user_id = %d', self::table(), $user_id ) );
		}

		/**
		 * When the customer last earned or redeemed points (UTC, MySQL format), or ''.
		 *
		 * @param int $user_id User id.
		 * @return string
		 */
		public static function last_activity( int $user_id ): string {
			global $wpdb;
			return (string) $wpdb->get_var( $wpdb->prepare( "SELECT MAX(created_at) FROM %i WHERE user_id = %d AND reason IN ('earn','reserve')", self::table(), $user_id ) );
		}

		/**
		 * Customers whose last earn or redeem is older than a cut-off and who still hold points.
		 *
		 * @param string $before UTC MySQL datetime.
		 * @return int[]
		 */
		public static function inactive_since( string $before ): array {
			global $wpdb;
			$ids = $wpdb->get_col( $wpdb->prepare( "SELECT user_id FROM %i GROUP BY user_id HAVING SUM(delta) > 0 AND MAX(CASE WHEN reason IN ('earn','reserve') THEN created_at END) < %s", self::table(), $before ) );
			return array_map( 'intval', (array) $ids );
		}

		/**
		 * Every user id that has ledger rows.
		 *
		 * @return int[]
		 */
		public static function user_ids(): array {
			global $wpdb;
			return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT user_id FROM %i ORDER BY user_id', self::table() ) ) );
		}

		/**
		 * Rebuild the running balances of a customer from the ledger and
		 * refresh the cached balance.
		 *
		 * @param int  $user_id User id.
		 * @param bool $apply   False reports without writing.
		 * @return array{cached:int,ledger:int,rows_fixed:int}
		 */
		public static function recalc( int $user_id, bool $apply = true ): array {
			global $wpdb;
			$cached  = (int) get_user_meta( $user_id, self::META_BALANCE, true );
			$running = 0;
			$fixed   = 0;
			$rows    = $wpdb->get_results( $wpdb->prepare( 'SELECT id, delta, balance_after FROM %i WHERE user_id = %d ORDER BY id', self::table(), $user_id ), ARRAY_A );
			foreach ( (array) $rows as $row ) {
				$running += (int) $row['delta'];
				if ( (int) $row['balance_after'] !== $running ) {
					++$fixed;
					if ( $apply ) {
						$wpdb->update( self::table(), array( 'balance_after' => $running ), array( 'id' => (int) $row['id'] ), array( '%d' ), array( '%d' ) );
					}
				}
			}
			if ( $apply ) {
				update_user_meta( $user_id, self::META_BALANCE, $running );
			}
			return array(
				'cached'     => $cached,
				'ledger'     => $running,
				'rows_fixed' => $fixed,
			);
		}

		/**
		 * Remove a customer's rows and cached balance (account deletion, privacy erasure).
		 *
		 * @param int $user_id User id.
		 * @return void
		 */
		public static function erase( int $user_id ): void {
			global $wpdb;
			$wpdb->delete( self::table(), array( 'user_id' => $user_id ), array( '%d' ) );
			delete_user_meta( $user_id, self::META_BALANCE );
		}
	}
}
