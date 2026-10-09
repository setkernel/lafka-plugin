<?php
/**
 * Loyalty points at checkout: the "You'll earn" line and redeeming points
 * for a discount, on the classic and the block checkout.
 *
 * A redemption is a WooCommerce coupon, never a custom discount: choosing
 * points issues a single-use fixed-cart coupon tied to the customer and
 * applies it to the cart, so totals, tax, emails and reports are
 * WooCommerce's own. The points are reserved (taken off the ledger) when the
 * coupon is issued, behind the ledger's per-customer lock, so two tabs
 * cannot spend the same points. A reservation is released, and its coupon
 * deleted, when the coupon leaves the cart, when the order fails, is
 * cancelled or refunded in full, or when the coupon expires unused (the
 * hourly upkeep sweeps those).
 *
 * Coupon meta: _lafka_loyalty_user, _lafka_loyalty_points, _lafka_loyalty_state
 * (reserved | released), _lafka_loyalty_expires (unix time), _lafka_loyalty_order
 * (set once an order is placed with it), _lafka_loyalty_cycle (reserve/release count).
 *
 *   Classic: a row in the order review; admin-ajax `lafka_loyalty_apply` and
 *            `lafka_loyalty_remove`, then WooCommerce's `update_checkout`.
 *   Blocks:  a panel in the order summary; Store API extensionCartUpdate
 *            (namespace `lafka-loyalty`); the cart extension carries the state.
 *
 * @package Lafka\Plugin\Loyalty
 * @since   10.4.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Lafka_Loyalty_Redeem' ) ) {

	/**
	 * Redemption at checkout.
	 */
	final class Lafka_Loyalty_Redeem {

		/** Store API namespace. */
		const NAMESPACE = 'lafka-loyalty';

		/** Coupon code prefix (codes are lower-cased by WooCommerce). */
		const PREFIX = 'loyalty-';

		/**
		 * Hook in.
		 *
		 * @return void
		 */
		public static function init(): void {
			add_filter( 'woocommerce_coupon_is_valid', array( __CLASS__, 'validate_coupon' ), 10, 2 );
			add_action( 'woocommerce_removed_coupon', array( __CLASS__, 'on_removed' ) );
			add_filter( 'woocommerce_cart_totals_coupon_label', array( __CLASS__, 'coupon_label' ), 10, 2 );
			add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'bind_processed' ), 10, 3 );
			add_action( 'woocommerce_store_api_checkout_order_processed', array( __CLASS__, 'bind' ) );
			add_action( 'woocommerce_order_status_cancelled', array( __CLASS__, 'release_order' ) );
			add_action( 'woocommerce_order_status_refunded', array( __CLASS__, 'release_order' ) );
			add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'reserve_order' ) );
			add_action( 'woocommerce_order_status_on-hold', array( __CLASS__, 'reserve_order' ) );
			add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'reserve_order' ), 1 );

			add_action( 'woocommerce_review_order_before_order_total', array( __CLASS__, 'render_classic' ) );
			add_action( 'wp_ajax_lafka_loyalty_apply', array( __CLASS__, 'ajax_apply' ) );
			add_action( 'wp_ajax_nopriv_lafka_loyalty_apply', array( __CLASS__, 'ajax_apply' ) );
			add_action( 'wp_ajax_lafka_loyalty_remove', array( __CLASS__, 'ajax_remove' ) );
			add_action( 'wp_ajax_nopriv_lafka_loyalty_remove', array( __CLASS__, 'ajax_remove' ) );
			add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
			add_action( 'woocommerce_blocks_loaded', array( __CLASS__, 'register_store_api' ) );
			if ( did_action( 'woocommerce_blocks_loaded' ) ) {
				self::register_store_api();
			}
		}

		// ---------------------------------------------------------------- the cart's side.

		/**
		 * The reservation coupon in the cart, or null.
		 *
		 * @return WC_Coupon|null
		 */
		public static function current_coupon() {
			if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
				return null;
			}
			foreach ( WC()->cart->get_applied_coupons() as $code ) {
				$id = wc_get_coupon_id_by_code( $code );
				if ( $id > 0 && (int) get_post_meta( $id, '_lafka_loyalty_user', true ) > 0 ) {
					return new WC_Coupon( $id );
				}
			}
			return null;
		}

		/**
		 * The most points the current cart can redeem: the share of the items
		 * the setting allows, in whole currency units, and what the customer holds.
		 *
		 * @param int $balance The customer's balance.
		 * @return int
		 */
		public static function max_points( int $balance ): int {
			$rate  = Lafka_Loyalty::redeem_rate();
			$items = ( function_exists( 'WC' ) && WC()->cart ) ? (float) WC()->cart->get_subtotal() : 0.0;
			$units = (int) floor( $items * Lafka_Loyalty::max_share() / 100 + 0.0001 );
			$units = min( $units, intdiv( $balance, $rate ) );
			return max( 0, $units * $rate );
		}

		/**
		 * What the checkout shows: the same facts feed the classic row and the block panel.
		 *
		 * @return array<string,mixed>
		 */
		public static function state(): array {
			$user    = get_current_user_id();
			$cart    = WC()->cart;
			$promo   = $cart && function_exists( 'lafka_order_discount_fee_amount' ) ? lafka_order_discount_fee_amount( $cart ) : 0.0;
			$base    = $cart ? max( 0.0, (float) $cart->get_subtotal() - (float) $cart->get_discount_total() - $promo ) : 0.0;
			$earn    = Lafka_Loyalty::points_for( $base );
			$balance = $user > 0 ? Lafka_Loyalty_Ledger::balance( $user ) : 0;
			$coupon  = $user > 0 ? self::current_coupon() : null;
			$max     = self::max_points( $balance );
			// Points already reserved are off the balance: what can still be added is the cap minus them.
			$applied = $coupon ? (int) $coupon->get_meta( '_lafka_loyalty_points' ) : 0;
			$free    = self::max_points( $balance + $applied );
			$min     = Lafka_Loyalty::min_redeem();
			return array(
				'applies'       => (bool) $cart && ! $cart->is_empty(),
				'logged_in'     => $user > 0,
				'signup'        => 'yes' === get_option( 'woocommerce_enable_signup_and_login_from_checkout', 'no' ),
				'earn'          => $earn,
				'balance'       => $balance,
				'balance_text'  => Lafka_Loyalty::format( $balance ),
				'balance_worth' => lafka_price_plain( Lafka_Loyalty::value_of( $balance ) ),
				'min'           => $min,
				'step'          => Lafka_Loyalty::redeem_rate(),
				'max'           => $max,
				'max_free'      => $free,
				'can_redeem'    => $user > 0 && null === $coupon && $max >= $min,
				'applied'       => $applied,
				'coupon'        => $coupon ? $coupon->get_code() : '',
				'applied_text'  => Lafka_Loyalty::format( $applied ),
				'applied_worth' => lafka_price_plain( Lafka_Loyalty::value_of( $applied ) ),
				'max_text'      => Lafka_Loyalty::format( $max ),
				'max_worth'     => lafka_price_plain( Lafka_Loyalty::value_of( $max ) ),
				'min_text'      => Lafka_Loyalty::format( $min ),
				'earn_text'     => Lafka_Loyalty::format( $earn ),
				'max_share'     => Lafka_Loyalty::max_share(),
			);
		}

		/**
		 * Redeem points: reserve them, issue the coupon, apply it. Replaces a redemption already in the cart.
		 *
		 * @param int $points Points the customer asked for.
		 * @return string '' on success, else the message to show.
		 */
		public static function apply( int $points ): string {
			$user = get_current_user_id();
			if ( $user <= 0 || ! Lafka_Loyalty::enabled() || ! WC()->cart || WC()->cart->is_empty() ) {
				return __( 'Log in to use your points.', 'lafka-plugin' );
			}
			$rate   = Lafka_Loyalty::redeem_rate();
			$min    = Lafka_Loyalty::min_redeem();
			$points = intdiv( max( 0, $points ), $rate ) * $rate;
			if ( $points < $min ) {
				/* translators: %s: minimum points. */
				return sprintf( __( 'You can use points from %s at a time.', 'lafka-plugin' ), Lafka_Loyalty::format( $min ) );
			}

			// Start from a clean slate: the old reservation goes back to the balance first.
			$old = self::current_coupon();
			if ( $old ) {
				WC()->cart->remove_coupon( $old->get_code() );
			}

			$balance = Lafka_Loyalty_Ledger::balance( $user );
			$points  = min( $points, self::max_points( $balance ) );
			if ( $points < $min ) {
				return $balance < $min
					/* translators: %s: minimum points. */
					? sprintf( __( 'You need at least %s points to redeem.', 'lafka-plugin' ), Lafka_Loyalty::format( $min ) )
					/* translators: %d: percent. */
					: sprintf( __( 'Points can cover up to %d%% of your items. Add more items to use more points.', 'lafka-plugin' ), Lafka_Loyalty::max_share() );
			}

			$ttl    = (int) apply_filters( 'lafka_loyalty_reservation_ttl', 2 * HOUR_IN_SECONDS );
			$coupon = new WC_Coupon();
			$coupon->set_code( self::PREFIX . strtolower( wp_generate_password( 8, false ) ) );
			$coupon->set_discount_type( 'fixed_cart' );
			$coupon->set_amount( Lafka_Loyalty::value_of( $points ) );
			$coupon->set_usage_limit( 1 );
			$coupon->set_usage_limit_per_user( 1 );
			$coupon->set_date_expires( time() + max( 600, $ttl ) );
			$coupon->set_description( __( 'Loyalty points redeemed at checkout. Single use, one customer.', 'lafka-plugin' ) );
			$coupon->update_meta_data( '_lafka_loyalty_user', $user );
			$coupon->update_meta_data( '_lafka_loyalty_points', $points );
			$coupon->update_meta_data( '_lafka_loyalty_state', 'reserved' );
			$coupon->update_meta_data( '_lafka_loyalty_expires', time() + max( 600, $ttl ) );
			$coupon->update_meta_data( '_lafka_loyalty_cycle', 0 );
			$id = $coupon->save();
			if ( ! $id ) {
				return __( 'Points could not be used right now. Please try again.', 'lafka-plugin' );
			}

			// The reservation: refused, with no coupon left behind, when the points are not there.
			$reserved = Lafka_Loyalty_Ledger::add( $user, -$points, 'reserve', 0, 'reserve:' . $id, __( 'Points used at checkout', 'lafka-plugin' ), 'strict' );
			if ( 'ok' !== $reserved['status'] ) {
				wp_delete_post( $id, true );
				return __( 'You do not have enough points for that.', 'lafka-plugin' );
			}

			if ( ! WC()->cart->apply_coupon( $coupon->get_code() ) ) {
				$notices = wc_get_notices( 'error' );
				wc_clear_notices();
				self::release( $id, __( 'Points returned', 'lafka-plugin' ) );
				$first   = $notices ? reset( $notices ) : array();
				$message = wp_strip_all_tags( is_array( $first ) ? (string) ( $first['notice'] ?? '' ) : (string) $first );
				return '' !== $message ? $message : __( 'Points could not be applied to this order.', 'lafka-plugin' );
			}
			wc_clear_notices();
			return '';
		}

		/**
		 * Take the redemption off the cart (its points go back to the balance).
		 *
		 * @return void
		 */
		public static function remove(): void {
			$coupon = self::current_coupon();
			if ( $coupon && WC()->cart ) {
				WC()->cart->remove_coupon( $coupon->get_code() );
				wc_clear_notices();
			}
		}

		// ---------------------------------------------------------------- coupon rules.

		/**
		 * A reservation coupon works for its own customer only, while it is
		 * reserved, and only as far as the cart still covers it.
		 *
		 * @param bool       $valid  Whether the coupon is valid.
		 * @param WC_Coupon  $coupon Coupon.
		 * @return bool
		 * @throws Exception With the reason the coupon cannot be used.
		 */
		public static function validate_coupon( $valid, $coupon ) {
			if ( ! $coupon instanceof WC_Coupon ) {
				return $valid;
			}
			$user = (int) $coupon->get_meta( '_lafka_loyalty_user' );
			if ( $user <= 0 ) {
				return $valid;
			}
			if ( get_current_user_id() !== $user ) {
				throw new Exception( esc_html__( 'These points belong to another customer.', 'lafka-plugin' ), 100 );
			}
			if ( 'reserved' !== $coupon->get_meta( '_lafka_loyalty_state' ) ) {
				throw new Exception( esc_html__( 'These points were already returned. Choose your points again.', 'lafka-plugin' ), 100 );
			}
			// An order already placed with the coupon is not re-checked against a cart.
			$items = ( function_exists( 'WC' ) && WC()->cart ) ? (float) WC()->cart->get_subtotal() : 0.0;
			if ( ! (int) $coupon->get_meta( '_lafka_loyalty_order' ) && $coupon->get_amount() > floor( $items * Lafka_Loyalty::max_share() / 100 + 0.0001 ) ) {
				throw new Exception( esc_html__( 'Your order changed, so these points no longer fit. Choose your points again.', 'lafka-plugin' ), 100 );
			}
			return $valid;
		}

		/**
		 * A friendlier label than the coupon code in the classic totals.
		 *
		 * @param string    $label  Label.
		 * @param WC_Coupon $coupon Coupon.
		 * @return string
		 */
		public static function coupon_label( $label, $coupon ) {
			if ( $coupon instanceof WC_Coupon && (int) $coupon->get_meta( '_lafka_loyalty_user' ) > 0 ) {
				return esc_html__( 'Loyalty points', 'lafka-plugin' );
			}
			return $label;
		}

		// ---------------------------------------------------------------- reservations.

		/**
		 * Give a reservation's points back and retire its coupon.
		 *
		 * @param int    $coupon_id Coupon id.
		 * @param string $why       Short reason (kept in the ledger note).
		 * @return bool Whether points were returned.
		 */
		public static function release( int $coupon_id, string $why = '' ): bool {
			$coupon = new WC_Coupon( $coupon_id );
			$user   = (int) $coupon->get_meta( '_lafka_loyalty_user' );
			if ( $user <= 0 || 'reserved' !== $coupon->get_meta( '_lafka_loyalty_state' ) ) {
				return false;
			}
			$points = (int) $coupon->get_meta( '_lafka_loyalty_points' );
			$cycle  = (int) $coupon->get_meta( '_lafka_loyalty_cycle' );
			$order  = (int) $coupon->get_meta( '_lafka_loyalty_order' );
			$coupon->update_meta_data( '_lafka_loyalty_state', 'released' );
			$coupon->save();
			$note   = '' !== $why ? $why : __( 'Points returned', 'lafka-plugin' );
			$result = Lafka_Loyalty_Ledger::add( $user, $points, 'release', $order, 'release:' . $coupon_id . ':' . $cycle, $note );
			if ( ! $order ) {
				wp_delete_post( $coupon_id, true );
			}
			return 'ok' === $result['status'];
		}

		/**
		 * A reservation coupon left the cart before an order was placed: return its points.
		 *
		 * @param string $code Coupon code.
		 * @return void
		 */
		public static function on_removed( $code ): void {
			$id = wc_get_coupon_id_by_code( (string) $code );
			if ( $id > 0 && (int) get_post_meta( $id, '_lafka_loyalty_user', true ) > 0 && ! (int) get_post_meta( $id, '_lafka_loyalty_order', true ) ) {
				self::release( $id, __( 'Points returned', 'lafka-plugin' ) );
			}
		}

		/**
		 * The reservation coupons used on an order.
		 *
		 * @param WC_Order $order Order.
		 * @return int[] Coupon ids.
		 */
		private static function order_coupons( $order ): array {
			$ids = array();
			foreach ( $order->get_coupon_codes() as $code ) {
				$id = wc_get_coupon_id_by_code( $code );
				if ( $id > 0 && (int) get_post_meta( $id, '_lafka_loyalty_user', true ) > 0 ) {
					$ids[] = $id;
				}
			}
			return $ids;
		}

		/**
		 * Classic checkout: the order exists, link the reservation to it.
		 *
		 * @param int      $order_id Order id.
		 * @param array    $posted   Posted data.
		 * @param WC_Order $order    Order.
		 * @return void
		 */
		public static function bind_processed( $order_id, $posted = array(), $order = null ): void {
			unset( $posted );
			self::bind( $order instanceof WC_Order ? $order : wc_get_order( $order_id ) );
		}

		/**
		 * Link a placed order to the reservation it used: the coupon and the ledger row now name the order.
		 *
		 * @param WC_Order $order Order.
		 * @return void
		 */
		public static function bind( $order ): void {
			if ( ! $order instanceof WC_Order ) {
				return;
			}
			foreach ( self::order_coupons( $order ) as $id ) {
				// Retrying payment on a new order moves the reservation to it.
				update_post_meta( $id, '_lafka_loyalty_order', $order->get_id() );
				Lafka_Loyalty_Ledger::set_order( 'reserve:' . $id, $order->get_id() );
			}
		}

		/**
		 * The order was cancelled or refunded in full: return the points. A failed
		 * payment is not final (the customer usually retries the same order), so
		 * release_stale() returns a failed order's points only after a day.
		 *
		 * @param int $order_id Order id.
		 * @return void
		 */
		public static function release_order( $order_id ): void {
			$order = wc_get_order( $order_id );
			if ( ! $order instanceof WC_Order ) {
				return;
			}
			foreach ( self::order_coupons( $order ) as $id ) {
				// A newer order may have taken the reservation over (payment retried): leave it.
				if ( (int) get_post_meta( $id, '_lafka_loyalty_order', true ) === $order->get_id() ) {
					self::release( $id, __( 'Order did not go through', 'lafka-plugin' ) );
				}
			}
		}

		/**
		 * An order that was released (failed, then paid again) is placed after all: take the points again.
		 *
		 * @param int $order_id Order id.
		 * @return void
		 */
		public static function reserve_order( $order_id ): void {
			$order = wc_get_order( $order_id );
			if ( ! $order instanceof WC_Order ) {
				return;
			}
			foreach ( self::order_coupons( $order ) as $id ) {
				$coupon = new WC_Coupon( $id );
				if ( 'released' !== $coupon->get_meta( '_lafka_loyalty_state' ) || (int) $coupon->get_meta( '_lafka_loyalty_order' ) !== $order->get_id() ) {
					continue;
				}
				$cycle  = (int) $coupon->get_meta( '_lafka_loyalty_cycle' ) + 1;
				$points = (int) $coupon->get_meta( '_lafka_loyalty_points' );
				// The order is real and paid: the points are taken as far as the
				// customer still has them; anything they spent meanwhile is
				// flagged to the shop, never silently given away.
				$result = Lafka_Loyalty_Ledger::add( (int) $coupon->get_meta( '_lafka_loyalty_user' ), -$points, 'reserve', $order->get_id(), 'rereserve:' . $id . ':' . $cycle, __( 'Points used at checkout', 'lafka-plugin' ), 'clamp' );
				if ( 'ok' === $result['status'] && (int) $result['shortfall'] > 0 ) {
					$order->add_order_note(
						sprintf(
							/* translators: 1: points short, 2: points the discount used, 3: their value. */
							__( 'Loyalty: this order was reinstated, but the customer had already spent %1$d of the %2$d points its discount used (%3$s not covered by points).', 'lafka-plugin' ),
							(int) $result['shortfall'],
							$points,
							lafka_price_plain( Lafka_Loyalty::value_of( (int) $result['shortfall'] ) )
						)
					);
				}
				$coupon->update_meta_data( '_lafka_loyalty_state', 'reserved' );
				$coupon->update_meta_data( '_lafka_loyalty_cycle', $cycle );
				$coupon->save();
			}
		}

		/**
		 * Hourly: release reservations whose coupon expired without an order.
		 *
		 * @return int Reservations released.
		 */
		public static function release_stale(): int {
			global $wpdb;
			$ids   = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT s.post_id FROM %i s
					JOIN %i e ON e.post_id = s.post_id AND e.meta_key = '_lafka_loyalty_expires'
					WHERE s.meta_key = '_lafka_loyalty_state' AND s.meta_value = 'reserved' AND CAST(e.meta_value AS UNSIGNED) < %d
					AND NOT EXISTS (SELECT 1 FROM %i o WHERE o.post_id = s.post_id AND o.meta_key = '_lafka_loyalty_order')
					LIMIT 100",
					$wpdb->postmeta,
					$wpdb->postmeta,
					time(),
					$wpdb->postmeta
				)
			);
			$count = 0;
			foreach ( (array) $ids as $id ) {
				if ( self::release( (int) $id, __( 'Points returned: not used in time', 'lafka-plugin' ) ) ) {
					++$count;
				}
			}
			// Orders whose payment failed and was not retried within a day.
			/**
			 * Filters how long a failed order keeps its reserved points (seconds).
			 *
			 * @since 10.4.0
			 *
			 * @param int $seconds Default a day.
			 */
			$grace  = (int) apply_filters( 'lafka_loyalty_failed_grace', DAY_IN_SECONDS );
			$failed = wc_get_orders(
				array(
					'status'       => 'failed',
					'date_created' => '<' . ( time() - $grace ),
					'limit'        => 100,
					'return'       => 'ids',
				)
			);
			foreach ( (array) $failed as $order_id ) {
				$order = wc_get_order( (int) $order_id );
				if ( ! $order instanceof WC_Order ) {
					continue;
				}
				foreach ( self::order_coupons( $order ) as $id ) {
					if ( (int) get_post_meta( $id, '_lafka_loyalty_order', true ) === $order->get_id() && self::release( $id, __( 'Order did not go through', 'lafka-plugin' ) ) ) {
						++$count;
					}
				}
			}
			return $count;
		}

		// ---------------------------------------------------------------- classic checkout.

		/**
		 * Classic checkout: the points row in the order review.
		 *
		 * @return void
		 */
		public static function render_classic(): void {
			$state = self::state();
			if ( ! $state['applies'] ) {
				return;
			}
			?>
			<tr class="lafka-loyalty-row">
				<td colspan="2">
					<div class="lafka-loyalty lafka-card" data-lafka-loyalty="<?php echo esc_attr( wp_create_nonce( 'lafka_loyalty' ) ); ?>">
						<?php self::render_panel( $state ); ?>
					</div>
				</td>
			</tr>
			<?php
		}

		/**
		 * The panel's markup (classic). The block panel renders the same structure.
		 *
		 * @param array<string,mixed> $state State.
		 * @return void
		 */
		private static function render_panel( array $state ): void {
			?>
			<p class="lafka-loyalty__title"><?php esc_html_e( 'Loyalty points', 'lafka-plugin' ); ?></p>
			<?php if ( ! $state['logged_in'] ) : ?>
				<p class="lafka-loyalty__text">
					<?php
					echo esc_html(
						$state['signup']
							/* translators: %s: points. */
							? sprintf( __( 'Create an account with this order to earn %s points.', 'lafka-plugin' ), $state['earn_text'] )
							/* translators: %s: points. */
							: sprintf( __( 'Log in to earn %s points with this order.', 'lafka-plugin' ), $state['earn_text'] )
					);
					?>
				</p>
				<?php
				return;
			endif;
			?>
			<p class="lafka-loyalty__text">
				<?php
				echo esc_html(
					/* translators: %s: points. */
					sprintf( __( 'You\'ll earn %s points with this order.', 'lafka-plugin' ), $state['earn_text'] )
				);
				?>
			</p>
			<p class="lafka-loyalty__text lafka-loyalty__balance">
				<?php
				echo esc_html(
					sprintf(
						/* translators: 1: points, 2: money. */
						__( 'You have %1$s points (worth %2$s).', 'lafka-plugin' ),
						$state['balance_text'],
						$state['balance_worth']
					)
				);
				?>
			</p>
			<?php if ( $state['applied'] > 0 ) : ?>
				<p class="lafka-loyalty__applied">
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: points, 2: money. */
							__( '%1$s points used, taking %2$s off.', 'lafka-plugin' ),
							$state['applied_text'],
							$state['applied_worth']
						)
					);
					?>
				</p>
				<button type="button" class="lafka-btn lafka-btn--ghost" data-lafka-loyalty-remove><?php esc_html_e( 'Keep my points', 'lafka-plugin' ); ?></button>
			<?php elseif ( $state['can_redeem'] ) : ?>
				<div class="lafka-loyalty__form">
					<label class="lafka-loyalty__label" for="lafka-loyalty-points"><?php esc_html_e( 'Points to use', 'lafka-plugin' ); ?></label>
					<input type="number" id="lafka-loyalty-points" class="input-text" min="<?php echo esc_attr( (string) $state['min'] ); ?>" max="<?php echo esc_attr( (string) $state['max'] ); ?>" step="<?php echo esc_attr( (string) $state['step'] ); ?>" value="<?php echo esc_attr( (string) $state['max'] ); ?>" inputmode="numeric" data-lafka-loyalty-points>
					<button type="button" class="lafka-btn lafka-btn--primary" data-lafka-loyalty-apply><?php esc_html_e( 'Use points', 'lafka-plugin' ); ?></button>
				</div>
				<p class="lafka-loyalty__hint">
					<?php
					echo esc_html(
						sprintf(
							/* translators: 1: points, 2: money, 3: percent. */
							__( 'Up to %1$s points (%2$s) on this order: points cover at most %3$d%% of your items.', 'lafka-plugin' ),
							$state['max_text'],
							$state['max_worth'],
							$state['max_share']
						)
					);
					?>
				</p>
			<?php else : ?>
				<p class="lafka-loyalty__hint">
					<?php
					echo esc_html(
						$state['balance'] < $state['min']
							/* translators: %s: points. */
							? sprintf( __( 'Use your points once you have %s.', 'lafka-plugin' ), $state['min_text'] )
							/* translators: %d: percent. */
							: sprintf( __( 'Points cover up to %d%% of your items. Add more items to use them.', 'lafka-plugin' ), $state['max_share'] )
					);
					?>
				</p>
			<?php endif; ?>
			<p class="lafka-loyalty__error" role="alert" data-lafka-loyalty-error hidden></p>
			<?php
		}

		/**
		 * Classic checkout: redeem.
		 *
		 * @return void
		 */
		public static function ajax_apply(): void {
			check_ajax_referer( 'lafka_loyalty', 'nonce' );
			$points  = isset( $_POST['points'] ) ? absint( wp_unslash( $_POST['points'] ) ) : 0;
			$message = self::apply( $points );
			if ( '' !== $message ) {
				wp_send_json_error( array( 'message' => $message ) );
			}
			wp_send_json_success();
		}

		/**
		 * Classic checkout: give the points back.
		 *
		 * @return void
		 */
		public static function ajax_remove(): void {
			check_ajax_referer( 'lafka_loyalty', 'nonce' );
			self::remove();
			wp_send_json_success();
		}

		/**
		 * Scripts: the classic handler and the block panel, on the checkout.
		 *
		 * @return void
		 */
		public static function assets(): void {
			if ( ! function_exists( 'is_checkout' ) || is_wc_endpoint_url( 'order-received' ) ) {
				return;
			}
			// The block cart shows the redemption coupon too: it needs the label.
			if ( is_cart() && wp_script_is( 'wc-blocks-checkout', 'registered' ) ) {
				$blocks = lafka_plugin_script_path( 'incl/loyalty/assets/js/lafka-loyalty-blocks.min.js' );
				wp_enqueue_script( 'lafka-loyalty-blocks', plugins_url( $blocks, LAFKA_PLUGIN_FILE ), array( 'wp-element', 'wp-plugins', 'wp-data', 'wc-blocks-data-store', 'wc-blocks-checkout' ), lafka_plugin_asset_version( $blocks ), true );
				return;
			}
			if ( ! is_checkout() ) {
				return;
			}
			$classic = lafka_plugin_script_path( 'assets/js/lafka-loyalty.min.js' );
			wp_enqueue_script( 'lafka-loyalty', plugins_url( $classic, LAFKA_PLUGIN_FILE ), array( 'lafka-core', 'jquery' ), lafka_plugin_asset_version( $classic ), true );
			wp_localize_script( 'lafka-loyalty', 'lafkaLoyalty', array( 'ajaxUrl' => admin_url( 'admin-ajax.php' ) ) );

			if ( wp_script_is( 'wc-blocks-checkout', 'registered' ) ) {
				$blocks = lafka_plugin_script_path( 'incl/loyalty/assets/js/lafka-loyalty-blocks.min.js' );
				wp_enqueue_script( 'lafka-loyalty-blocks', plugins_url( $blocks, LAFKA_PLUGIN_FILE ), array( 'wp-element', 'wp-plugins', 'wp-data', 'wc-blocks-data-store', 'wc-blocks-checkout' ), lafka_plugin_asset_version( $blocks ), true );
			}
		}

		// ---------------------------------------------------------------- block checkout.

		/**
		 * Block checkout: expose the state and take the choices.
		 *
		 * @return void
		 */
		public static function register_store_api(): void {
			static $done = false;
			if ( $done || ! function_exists( 'woocommerce_store_api_register_endpoint_data' ) ) {
				return;
			}
			$done = true;
			woocommerce_store_api_register_endpoint_data(
				array(
					'endpoint'        => 'cart',
					'namespace'       => self::NAMESPACE,
					'data_callback'   => array( __CLASS__, 'cart_data' ),
					'schema_callback' => array( __CLASS__, 'cart_schema' ),
					'schema_type'     => ARRAY_A,
				)
			);
			woocommerce_store_api_register_update_callback(
				array(
					'namespace' => self::NAMESPACE,
					'callback'  => array( __CLASS__, 'store_api_update' ),
				)
			);
		}

		/**
		 * Cart extension data for the block panel.
		 *
		 * @return array<string,mixed>
		 */
		public static function cart_data(): array {
			$state = self::state();
			return array_merge(
				$state,
				array(
					'text' => array(
						'title'   => __( 'Loyalty points', 'lafka-plugin' ),
						'use'     => __( 'Use points', 'lafka-plugin' ),
						'keep'    => __( 'Keep my points', 'lafka-plugin' ),
						'points'  => __( 'Points to use', 'lafka-plugin' ),
						/* translators: %s: points. */
						'signup'  => sprintf( __( 'Create an account with this order to earn %s points.', 'lafka-plugin' ), $state['earn_text'] ),
						/* translators: %s: points. */
						'login'   => sprintf( __( 'Log in to earn %s points with this order.', 'lafka-plugin' ), $state['earn_text'] ),
						/* translators: %s: points. */
						'earn'    => sprintf( __( 'You\'ll earn %s points with this order.', 'lafka-plugin' ), $state['earn_text'] ),
						/* translators: 1: points, 2: money. */
						'balance' => sprintf( __( 'You have %1$s points (worth %2$s).', 'lafka-plugin' ), $state['balance_text'], $state['balance_worth'] ),
						/* translators: 1: points, 2: money. */
						'used'    => sprintf( __( '%1$s points used, taking %2$s off.', 'lafka-plugin' ), $state['applied_text'], $state['applied_worth'] ),
						/* translators: 1: points, 2: money, 3: percent. */
						'hint'    => sprintf( __( 'Up to %1$s points (%2$s) on this order: points cover at most %3$d%% of your items.', 'lafka-plugin' ), $state['max_text'], $state['max_worth'], $state['max_share'] ),
						/* translators: %s: points. */
						'need'    => sprintf( __( 'Use your points once you have %s.', 'lafka-plugin' ), $state['min_text'] ),
						/* translators: %d: percent. */
						'cap'     => sprintf( __( 'Points cover up to %d%% of your items. Add more items to use them.', 'lafka-plugin' ), $state['max_share'] ),
					),
				)
			);
		}

		/**
		 * Schema of the cart extension.
		 *
		 * @return array<string, array<string,mixed>>
		 */
		public static function cart_schema(): array {
			$field = static function ( string $type, string $description ): array {
				return array(
					'description' => $description,
					'type'        => $type,
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				);
			};
			return array(
				'applies'       => $field( 'boolean', 'Whether loyalty points show on this cart.' ),
				'logged_in'     => $field( 'boolean', 'Whether the customer is logged in.' ),
				'signup'        => $field( 'boolean', 'Whether an account can be created at checkout.' ),
				'earn'          => $field( 'integer', 'Points this order will earn.' ),
				'balance'       => $field( 'integer', 'The customer\'s available points.' ),
				'balance_text'  => $field( 'string', 'Balance, formatted.' ),
				'balance_worth' => $field( 'string', 'Balance in money.' ),
				'min'           => $field( 'integer', 'Fewest points that can be redeemed.' ),
				'step'          => $field( 'integer', 'Points per whole currency unit.' ),
				'max'           => $field( 'integer', 'Most points this cart can redeem.' ),
				'max_free'      => $field( 'integer', 'Most points this cart could redeem counting the reserved ones.' ),
				'can_redeem'    => $field( 'boolean', 'Whether points can be redeemed now.' ),
				'applied'       => $field( 'integer', 'Points redeemed on this cart.' ),
				'coupon'        => $field( 'string', 'Code of the coupon that carries the redeemed points (\'\' when none).' ),
				'applied_text'  => $field( 'string', 'Redeemed points, formatted.' ),
				'applied_worth' => $field( 'string', 'Redeemed points in money.' ),
				'max_text'      => $field( 'string', 'Maximum, formatted.' ),
				'max_worth'     => $field( 'string', 'Maximum in money.' ),
				'min_text'      => $field( 'string', 'Minimum, formatted.' ),
				'earn_text'     => $field( 'string', 'Earned points, formatted.' ),
				'max_share'     => $field( 'integer', 'Share of the items points can cover, in percent.' ),
				'text'          => $field( 'object', 'Translated labels.' ),
			);
		}

		/**
		 * Store API update from the block panel.
		 *
		 * @param array<string,mixed> $data { action: apply|remove, points?: int }.
		 * @return void
		 * @throws \Automattic\WooCommerce\StoreApi\Exceptions\RouteException When the points cannot be used.
		 */
		public static function store_api_update( $data ): void {
			$data = is_array( $data ) ? $data : array();
			if ( 'apply' === ( $data['action'] ?? '' ) ) {
				$message = self::apply( absint( $data['points'] ?? 0 ) );
				if ( '' !== $message ) {
					throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( 'lafka_loyalty', esc_html( $message ), 400 );
				}
				return;
			}
			self::remove();
		}
	}
}
