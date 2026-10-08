<?php
/**
 * Order tracking: the status stepper a customer follows from the order
 * confirmation, My Account → view order and the order emails.
 *
 * Built on WooCommerce, never instead of it: it renders into the
 * `woocommerce_thankyou` and `woocommerce_view_order` actions (and the
 * order-confirmation block of a block theme), reads WooCommerce order
 * statuses, and adds a link to WooCommerce's own customer emails.
 *
 * The steps come from the kitchen display when it is on (accepted, preparing,
 * ready), else from the plain WooCommerce statuses (received, done), where the
 * page is static. The live page polls Lafka_Order_Tracking_Rest.
 *
 * @package Lafka\Plugin\OrderTracking
 * @since   10.4.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Lafka_Order_Tracking' ) ) {

	/**
	 * Order tracking state and markup.
	 */
	final class Lafka_Order_Tracking {

		/** Module flag in the 'lafka' option (Lafka → Modules → Order tracking, default on). */
		const FLAG = 'order_tracking';

		/** Order statuses that end the order without it being served. */
		const TERMINAL_STATUSES = array( 'cancelled', 'refunded', 'failed', 'rejected' );

		/** Whether the order-confirmation block already carried the tracker this request. */
		private static $printed = array();

		/**
		 * Whether the module is on.
		 *
		 * @return bool
		 */
		public static function enabled(): bool {
			return Lafka_Options::is_enabled( self::FLAG );
		}

		/**
		 * Hook in.
		 *
		 * @return void
		 */
		public static function init(): void {
			add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_style' ), 5 );
			add_action( 'woocommerce_thankyou', array( __CLASS__, 'print_on_confirmation' ), 5 );
			add_action( 'woocommerce_view_order', array( __CLASS__, 'print_on_view_order' ), 5 );
			add_filter( 'render_block', array( __CLASS__, 'append_to_confirmation_block' ), 10, 2 );
			add_action( 'woocommerce_email_before_order_table', array( __CLASS__, 'email_link' ), 5, 4 );
		}

		/**
		 * The structure stylesheet, early enough that a theme's own sheet can
		 * refine it, on the pages that show the tracker.
		 *
		 * @return void
		 */
		public static function enqueue_style(): void {
			if ( function_exists( 'is_wc_endpoint_url' ) && ( is_wc_endpoint_url( 'order-received' ) || is_wc_endpoint_url( 'view-order' ) ) ) {
				wp_enqueue_style( 'lafka-order-tracker', plugins_url( 'assets/css/lafka-order-tracker.css', LAFKA_PLUGIN_FILE ), array(), lafka_plugin_asset_version( 'assets/css/lafka-order-tracker.css' ) );
			}
		}

		/**
		 * Whether the visitor may see this order's details: the same decision
		 * WooCommerce makes for its order-confirmation blocks. 'account' is My
		 * Account → view order (WooCommerce's `view_order` capability); 'received' is
		 * the confirmation page, which needs the order key and then, for an order
		 * placed with an account, the logged-in owner or, for a guest order, a fresh
		 * order or a verified email.
		 *
		 * @param WC_Order $order   Order.
		 * @param string   $context 'received' or 'account'.
		 * @return bool
		 */
		public static function viewer_may_see( $order, string $context ): bool {
			if ( 'account' === $context ) {
				return current_user_can( 'view_order', $order->get_id() );
			}

			$key = lafka_input_get_text( 'key' );
			if ( '' === $key || ! $order->key_is_valid( $key ) ) {
				return false;
			}
			if ( $order->get_user_id() > 0 ) {
				return ! apply_filters( 'woocommerce_order_received_verify_known_shoppers', true ) || get_current_user_id() === $order->get_user_id();
			}

			$session = WC()->session;
			if ( $session instanceof WC_Session && $order->get_id() === (int) $session->get( 'store_api_draft_order' ) ) {
				return true;
			}
			$grace   = (int) apply_filters( 'woocommerce_order_email_verification_grace_period', 10 * MINUTE_IN_SECONDS, $order, 'order-received' );
			$created = $order->get_date_created();
			if ( $created && time() - $created->getTimestamp() <= $grace ) {
				return true;
			}
			$nonce = filter_input( INPUT_POST, 'check_submission', FILTER_UNSAFE_RAW, FILTER_REQUIRE_SCALAR );
			$email = filter_input( INPUT_POST, 'email', FILTER_UNSAFE_RAW, FILTER_REQUIRE_SCALAR );
			if ( is_string( $nonce ) && is_string( $email ) && wp_verify_nonce( $nonce, 'wc_verify_email' ) && '' !== $order->get_billing_email() && sanitize_email( $email ) === $order->get_billing_email() ) {
				return true;
			}

			return ! apply_filters( 'woocommerce_order_email_verification_required', true, $order, 'order-received' );
		}

		/**
		 * The URL a customer follows to track an order: the order-received
		 * page, which carries the order key (WooCommerce asks a guest to
		 * confirm their email before showing it).
		 *
		 * @param WC_Order $order Order.
		 * @return string
		 */
		public static function track_url( $order ): string {
			return (string) apply_filters( 'lafka_order_tracking_url', $order->get_checkout_order_received_url(), $order );
		}

		/**
		 * The steps of the stepper, in order: step key => label.
		 *
		 * @param string $fulfilment 'pickup' or 'delivery'.
		 * @param bool   $kitchen    Whether the kitchen display drives the statuses.
		 * @return array<string,string>
		 */
		public static function steps( string $fulfilment, bool $kitchen ): array {
			$steps = array( 'received' => __( 'Received', 'lafka-plugin' ) );
			if ( $kitchen ) {
				$steps['accepted']  = __( 'Accepted', 'lafka-plugin' );
				$steps['preparing'] = __( 'Preparing', 'lafka-plugin' );
				$steps['ready']     = 'pickup' === $fulfilment ? __( 'Ready for pickup', 'lafka-plugin' ) : __( 'Out for delivery', 'lafka-plugin' );
			}
			$steps['done'] = __( 'Done', 'lafka-plugin' );

			/**
			 * Filters the steps of the order tracker.
			 *
			 * The keys must stay within received, accepted, preparing, ready and
			 * done: the order statuses map onto them.
			 *
			 * @since 10.4.0
			 *
			 * @param array<string,string> $steps      Step key => label.
			 * @param string               $fulfilment 'pickup' or 'delivery'.
			 * @param bool                 $kitchen    Whether the kitchen display is on.
			 */
			return (array) apply_filters( 'lafka_order_tracking_steps', $steps, $fulfilment, $kitchen );
		}

		/**
		 * The tracker state of an order. Carries no personal data: it is what the
		 * live endpoint returns.
		 *
		 * @param WC_Order $order Order.
		 * @return array{status:string,kind:string,step:string,index:int,final:bool,headline:string,eta:string}
		 */
		public static function state( $order ): array {
			$status     = $order->get_status();
			$fulfilment = self::fulfilment( $order );
			$kitchen    = function_exists( 'is_lafka_kitchen_display' ) && is_lafka_kitchen_display();
			$steps      = array_keys( self::steps( $fulfilment, $kitchen ) );
			$pickup     = 'pickup' === $fulfilment;

			$kind = 'progress';
			$step = 'received';
			switch ( $status ) {
				case 'pending':
					$kind     = 'waiting';
					$headline = __( 'We are waiting for your payment to come through.', 'lafka-plugin' );
					break;
				case 'on-hold':
					$headline = __( 'We have your order and are confirming it.', 'lafka-plugin' );
					break;
				case 'accepted':
				case 'preparing':
				case 'ready':
					$step = $kitchen ? $status : 'received';
					if ( 'accepted' === $step ) {
						$headline = __( 'The kitchen has accepted your order.', 'lafka-plugin' );
					} elseif ( 'preparing' === $step ) {
						$headline = __( 'Your order is being prepared.', 'lafka-plugin' );
					} elseif ( 'ready' === $step ) {
						$headline = $pickup ? __( 'Your order is ready for pickup.', 'lafka-plugin' ) : __( 'Your order is out for delivery.', 'lafka-plugin' );
					} else {
						$headline = __( 'We have your order and the restaurant is on it.', 'lafka-plugin' );
					}
					break;
				case 'completed':
					$step     = 'done';
					$headline = $pickup ? __( 'All done. Enjoy your meal!', 'lafka-plugin' ) : __( 'Delivered. Enjoy your meal!', 'lafka-plugin' );
					break;
				case 'cancelled':
					$kind     = 'terminal';
					$headline = __( 'This order was cancelled. Please call us if that is not what you expected.', 'lafka-plugin' );
					break;
				case 'refunded':
					$kind     = 'terminal';
					$headline = __( 'This order was refunded.', 'lafka-plugin' );
					break;
				case 'failed':
					$kind     = 'terminal';
					$headline = __( 'The payment for this order failed. Please try again or call us.', 'lafka-plugin' );
					break;
				case 'rejected':
					$kind     = 'terminal';
					$headline = __( 'Sorry, the restaurant could not take this order. Please call us.', 'lafka-plugin' );
					break;
				default:
					$headline = __( 'We have your order and the restaurant is on it.', 'lafka-plugin' );
			}

			$index = array_search( $step, $steps, true );
			$state = array(
				'status'   => $status,
				'kind'     => $kind,
				'step'     => $step,
				'index'    => false === $index ? 0 : (int) $index,
				'final'    => 'terminal' === $kind || 'done' === $step,
				'headline' => $headline,
				'eta'      => 'progress' === $kind && 'done' !== $step ? self::eta( $order, $status, $pickup ) : '',
			);

			/**
			 * Filters the tracker state (also what the live endpoint returns, so
			 * keep personal data out of it).
			 *
			 * @since 10.4.0
			 *
			 * @param array    $state Status, kind, step, index, final, headline and eta.
			 * @param WC_Order $order Order.
			 */
			return (array) apply_filters( 'lafka_order_tracking_state', $state, $order );
		}

		/**
		 * The estimate line: the kitchen's estimate while the order is being
		 * made, else the timeslot the customer chose; '' when neither is known.
		 *
		 * @param WC_Order $order  Order.
		 * @param string   $status Order status.
		 * @param bool     $pickup Whether the order is collected.
		 * @return string
		 */
		private static function eta( $order, string $status, bool $pickup ): string {
			$kitchen_eta = (int) $order->get_meta( '_lafka_kds_eta' );
			if ( $kitchen_eta > 0 && in_array( $status, array( 'accepted', 'preparing' ), true ) ) {
				if ( $kitchen_eta <= time() ) {
					return __( 'Taking a little longer than we thought. Thank you for waiting.', 'lafka-plugin' );
				}
				/* translators: %s: clock time, e.g. 6:45 pm. */
				return sprintf( $pickup ? __( 'Ready for pickup around %s.', 'lafka-plugin' ) : __( 'Arriving around %s.', 'lafka-plugin' ), wp_date( get_option( 'time_format' ), $kitchen_eta ) );
			}

			$date = (string) $order->get_meta( 'lafka_checkout_date' );
			$slot = (string) $order->get_meta( 'lafka_checkout_timeslot' );
			if ( '' === $date ) {
				return '';
			}
			$day = DateTime::createFromFormat( 'Y-m-d', $date );
			if ( ! $day ) {
				return '';
			}
			$when = date_i18n( get_option( 'date_format' ), $day->getTimestamp() );
			if ( '' !== $slot ) {
				$when .= ', ' . $slot;
			}
			/* translators: %s: date and time slot the customer chose. */
			return sprintf( $pickup ? __( 'Pickup scheduled for %s.', 'lafka-plugin' ) : __( 'Delivery scheduled for %s.', 'lafka-plugin' ), $when );
		}

		/**
		 * 'pickup' or 'delivery'.
		 *
		 * @param WC_Order $order Order.
		 * @return string
		 */
		public static function fulfilment( $order ): string {
			return 'pickup' === lafka_order_fulfilment_type( $order ) ? 'pickup' : 'delivery';
		}

		/**
		 * The address the order is collected from, or delivered to: '' when none is known.
		 *
		 * @param WC_Order $order Order.
		 * @return string
		 */
		private static function place( $order ): string {
			if ( 'delivery' === self::fulfilment( $order ) ) {
				$use_billing = '' === $order->get_shipping_address_1();
				$parts       = $use_billing
					? array( $order->get_billing_address_1(), $order->get_billing_address_2(), $order->get_billing_city(), trim( $order->get_billing_state() . ' ' . $order->get_billing_postcode() ) )
					: array( $order->get_shipping_address_1(), $order->get_shipping_address_2(), $order->get_shipping_city(), trim( $order->get_shipping_state() . ' ' . $order->get_shipping_postcode() ) );
				return implode( ', ', array_filter( array_map( 'trim', $parts ) ) );
			}

			$branch_id = (int) $order->get_meta( 'lafka_selected_branch_id' );
			if ( $branch_id > 0 ) {
				$branch = (string) get_term_meta( $branch_id, 'lafka_branch_address', true );
				if ( '' !== $branch ) {
					return $branch;
				}
			}
			foreach ( $order->get_shipping_methods() as $method ) {
				$address = (string) $method->get_meta( 'pickup_address' );
				if ( '' !== $address ) {
					return $address;
				}
			}
			$info = function_exists( 'lafka_get_restaurant_info' ) ? lafka_get_restaurant_info() : array();
			return implode( ', ', array_filter( array( (string) ( $info['street'] ?? '' ), (string) ( $info['city'] ?? '' ), trim( ( $info['region'] ?? '' ) . ' ' . ( $info['postal'] ?? '' ) ) ) ) );
		}

		/**
		 * The seconds between polls: the kitchen display's customer poll
		 * interval when it is configured, else 20.
		 *
		 * @return int
		 */
		private static function interval(): int {
			$seconds = 20;
			if ( class_exists( 'Lafka_Kitchen_Display' ) ) {
				$seconds = (int) Lafka_Kitchen_Display::get_options()['customer_poll_interval'];
			}
			/**
			 * Filters the base seconds between status polls (15 to 120).
			 *
			 * @since 10.4.0
			 *
			 * @param int $seconds Seconds.
			 */
			return max( 15, min( 120, (int) apply_filters( 'lafka_order_tracking_poll_interval', $seconds ) ) );
		}

		/**
		 * Print the tracker on the order-confirmation page.
		 *
		 * @param int $order_id Order id.
		 * @return void
		 */
		public static function print_on_confirmation( $order_id ): void {
			$order = wc_get_order( $order_id );
			if ( $order && empty( self::$printed[ $order->get_id() ] ) ) {
				self::$printed[ $order->get_id() ] = true;
				self::render( $order, 'received' );
			}
		}

		/**
		 * Print the tracker on My Account → view order, while the order is open.
		 *
		 * @param int $order_id Order id.
		 * @return void
		 */
		public static function print_on_view_order( $order_id ): void {
			$order = wc_get_order( $order_id );
			if ( $order && ! self::state( $order )['final'] ) {
				self::render( $order, 'account' );
			}
		}

		/**
		 * Block themes: add the tracker under the order-confirmation status block.
		 * The status block prints nothing unless WooCommerce lets the visitor see
		 * the order, so the tracker follows that decision.
		 *
		 * @param string              $content Block output.
		 * @param array<string,mixed> $block   Parsed block.
		 * @return string
		 */
		public static function append_to_confirmation_block( $content, $block ): string {
			$content = (string) $content;
			if ( 'woocommerce/order-confirmation-status' !== ( $block['blockName'] ?? '' ) || '' === trim( $content ) ) {
				return $content;
			}
			$order = wc_get_order( absint( get_query_var( 'order-received' ) ) );
			if ( ! $order || ! empty( self::$printed[ $order->get_id() ] ) ) {
				return $content;
			}
			self::$printed[ $order->get_id() ] = true;
			ob_start();
			self::render( $order, 'received' );
			return $content . ob_get_clean();
		}

		/**
		 * Print the tracker.
		 *
		 * Prints nothing unless the visitor may see the order (see viewer_may_see()),
		 * whoever calls it.
		 *
		 * @param WC_Order $order   Order.
		 * @param string   $context 'received' or 'account'.
		 * @return void
		 */
		public static function render( $order, string $context = 'received' ): void {
			if ( ! self::viewer_may_see( $order, $context ) ) {
				return;
			}
			$state      = self::state( $order );
			$fulfilment = self::fulfilment( $order );
			$kitchen    = function_exists( 'is_lafka_kitchen_display' ) && is_lafka_kitchen_display();
			$steps      = self::steps( $fulfilment, $kitchen );
			$info       = function_exists( 'lafka_get_restaurant_info' ) ? lafka_get_restaurant_info() : array();
			$phone      = (string) ( $info['phone_display'] ?? '' );
			$tel        = (string) ( $info['phone_e164'] ?? '' );
			$place      = self::place( $order );
			$reorder    = Lafka_Order_Reorder::url( $order );

			// Without the kitchen display the status only changes when someone edits the order by hand: the page stays static.
			if ( $kitchen && ! $state['final'] ) {
				$rel = lafka_plugin_script_path( 'assets/js/lafka-order-tracker.min.js' );
				wp_enqueue_script(
					'lafka-order-tracker',
					plugins_url( $rel, LAFKA_PLUGIN_FILE ),
					array(),
					lafka_plugin_asset_version( $rel ),
					array(
						'in_footer' => true,
						'strategy'  => 'defer',
					)
				);
			}
			?>
			<section class="lafka-tracker" data-lafka-tracker data-kind="<?php echo esc_attr( $state['kind'] ); ?>" data-step="<?php echo esc_attr( $state['step'] ); ?>" data-final="<?php echo $state['final'] ? '1' : '0'; ?>" data-endpoint="<?php echo esc_url( rest_url( Lafka_Order_Tracking_Rest::NAMESPACE . '/order-status/' . $order->get_id() ) ); ?>" data-key="<?php echo esc_attr( $order->get_order_key() ); ?>" data-interval="<?php echo esc_attr( (string) self::interval() ); ?>" data-nonce="<?php echo esc_attr( is_user_logged_in() ? wp_create_nonce( 'wp_rest' ) : '' ); ?>" aria-labelledby="lafka-tracker-title">
				<h2 class="lafka-tracker__title" id="lafka-tracker-title"><?php esc_html_e( 'Track your order', 'lafka-plugin' ); ?></h2>
				<p class="lafka-tracker__headline" role="status" aria-live="polite" data-lafka-tracker-headline><?php echo esc_html( $state['headline'] ); ?></p>
				<ol class="lafka-tracker__steps">
					<?php
					$position = 0;
					foreach ( $steps as $key => $label ) :
						$class   = 'lafka-tracker__step';
						$current = false;
						if ( 'progress' === $state['kind'] ) {
							if ( $position < $state['index'] || ( 'done' === $state['step'] && $position === $state['index'] ) ) {
								$class .= ' is-done';
							} elseif ( $position === $state['index'] ) {
								$class  .= ' is-current';
								$current = true;
							}
						}
						++$position;
						?>
					<li class="<?php echo esc_attr( $class ); ?>" data-step="<?php echo esc_attr( (string) $key ); ?>"<?php echo $current ? ' aria-current="step"' : ''; ?>>
						<span class="lafka-tracker__dot" aria-hidden="true"></span>
						<span class="lafka-tracker__label"><?php echo esc_html( $label ); ?></span>
					</li>
					<?php endforeach; ?>
				</ol>
				<p class="lafka-tracker__eta" data-lafka-tracker-eta<?php echo '' === $state['eta'] ? ' hidden' : ''; ?>><?php echo esc_html( $state['eta'] ); ?></p>
				<?php if ( '' !== $place || '' !== $phone ) : ?>
				<dl class="lafka-tracker__where">
					<?php if ( '' !== $place ) : ?>
					<div>
						<dt><?php echo 'pickup' === $fulfilment ? esc_html__( 'Pick up at', 'lafka-plugin' ) : esc_html__( 'Delivering to', 'lafka-plugin' ); ?></dt>
						<dd><?php echo esc_html( $place ); ?></dd>
					</div>
					<?php endif; ?>
					<?php if ( '' !== $phone ) : ?>
					<div>
						<dt><?php esc_html_e( 'Questions? Call us', 'lafka-plugin' ); ?></dt>
						<dd><a href="<?php echo esc_url( 'tel:' . ( '' !== $tel ? $tel : preg_replace( '/[^0-9+]/', '', $phone ) ) ); ?>"><?php echo esc_html( $phone ); ?></a></dd>
					</div>
					<?php endif; ?>
				</dl>
				<?php endif; ?>
				<?php if ( '' !== $reorder ) : ?>
				<p class="lafka-tracker__reorder" data-lafka-tracker-reorder<?php echo 'done' === $state['step'] ? '' : ' hidden'; ?>>
					<a class="button lafka-tracker__reorder-link" href="<?php echo esc_url( $reorder ); ?>"><?php esc_html_e( 'Order this again', 'lafka-plugin' ); ?></a>
				</p>
				<?php endif; ?>
			</section>
			<?php
		}

		/**
		 * "Track your order" in WooCommerce's customer emails for an open order.
		 *
		 * @param WC_Order $order         Order.
		 * @param bool     $sent_to_admin Whether the email goes to the shop.
		 * @param bool     $plain_text    Whether the email is plain text.
		 * @param WC_Email $email         Email.
		 * @return void
		 */
		public static function email_link( $order, $sent_to_admin = false, $plain_text = false, $email = null ): void {
			/**
			 * Filters the WooCommerce emails that carry the tracking link.
			 *
			 * @since 10.4.0
			 *
			 * @param string[] $ids Email ids.
			 */
			$ids = (array) apply_filters( 'lafka_order_tracking_email_ids', array( 'customer_processing_order', 'customer_on_hold_order', 'customer_completed_order' ) );
			if ( $sent_to_admin || ! $email instanceof WC_Email || ! in_array( $email->id, $ids, true ) || ! $order instanceof WC_Order ) {
				return;
			}
			$url = self::track_url( $order );
			if ( $plain_text ) {
				echo esc_html__( 'Track your order:', 'lafka-plugin' ) . ' ' . esc_url_raw( $url ) . "\n\n";
				return;
			}
			?>
			<p style="margin:0 0 20px;"><a href="<?php echo esc_url( $url ); ?>" style="display:inline-block;padding:12px 24px;background-color:<?php echo esc_attr( (string) get_option( 'woocommerce_email_base_color', '#7f54b3' ) ); ?>;color:#ffffff;font-weight:bold;text-decoration:none;border-radius:6px;"><?php esc_html_e( 'Track your order', 'lafka-plugin' ); ?></a></p>
			<?php
		}
	}
}
