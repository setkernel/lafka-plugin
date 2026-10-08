<?php
/**
 * The live-status endpoint behind the order tracker.
 *
 *   GET /wp-json/lafka/v1/order-status/{id}?key={order key}
 *
 * Read-only and free of personal data: it returns the status, the step, the
 * headline and the estimate line. The caller proves the order is theirs with
 * its order key (the same proof WooCommerce asks for on the confirmation
 * page) or, when logged in with a REST nonce, by owning it. A wrong id and a
 * wrong key look the same, and every request counts against a per-visitor
 * limit that the visitor cannot rotate (their IP, or their user id).
 *
 * @package Lafka\Plugin\OrderTracking
 * @since   10.4.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Lafka_Order_Tracking_Rest' ) ) {

	/**
	 * Order status REST route.
	 */
	final class Lafka_Order_Tracking_Rest {

		/** REST namespace. */
		const NAMESPACE = 'lafka/v1';

		/** Seconds a state is cached (object cache) and a browser may reuse a response. */
		const CACHE_TTL = 10;

		/**
		 * Hook in.
		 *
		 * @return void
		 */
		public static function init(): void {
			add_action( 'rest_api_init', array( __CLASS__, 'register' ) );
			add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'forget' ) );
			add_action( 'woocommerce_update_order', array( __CLASS__, 'forget' ) );
		}

		/**
		 * Register the route.
		 *
		 * @return void
		 */
		public static function register(): void {
			register_rest_route(
				self::NAMESPACE,
				'/order-status/(?P<id>\d+)',
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( __CLASS__, 'respond' ),
					'permission_callback' => array( __CLASS__, 'authorise' ),
					'args'                => array(
						'id'  => array(
							'sanitize_callback' => 'absint',
						),
						'key' => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				)
			);
		}

		/**
		 * Drop the cached state of an order.
		 *
		 * @param int $order_id Order id.
		 * @return void
		 */
		public static function forget( $order_id ): void {
			wp_cache_delete( 'state_' . (int) $order_id, 'lafka_order_tracking' );
		}

		/**
		 * The key the rate limit counts a visitor under: the user id, else the IP.
		 * Never the order key, which a client could vary to dodge the limit.
		 *
		 * @return string
		 */
		private static function visitor_key(): string {
			$user = get_current_user_id();
			if ( $user > 0 ) {
				return 'u' . $user;
			}
			if ( ! class_exists( 'Lafka_Insights_Session' ) ) {
				require_once dirname( __DIR__ ) . '/insights/class-lafka-insights-session.php';
			}

			return 'i' . Lafka_Insights_Session::client_ip();
		}

		/**
		 * Rate limit, then prove the order is the caller's.
		 *
		 * @param WP_REST_Request $request Request.
		 * @return true|WP_Error
		 */
		public static function authorise( $request ) {
			// A tab polling every 20 s makes 30 calls in 10 minutes; this leaves room for a few tabs.
			if ( Lafka_Beacon_Guard::rate_limited( 'order_status', self::visitor_key(), 120, 3000, 10 * MINUTE_IN_SECONDS ) ) {
				return new WP_Error( 'lafka_order_status_rate_limited', __( 'Too many requests. Please try again in a minute.', 'lafka-plugin' ), array( 'status' => 429 ) );
			}

			$order = wc_get_order( absint( $request['id'] ) );
			$key   = (string) $request->get_param( 'key' );
			if ( $order && '' !== $key && hash_equals( (string) $order->get_order_key(), $key ) ) {
				return true;
			}
			if ( $order && get_current_user_id() > 0 && (int) $order->get_customer_id() === get_current_user_id() ) {
				return true;
			}

			return new WP_Error( 'lafka_order_not_found', __( 'Order not found.', 'lafka-plugin' ), array( 'status' => 404 ) );
		}

		/**
		 * The state of the order.
		 *
		 * @param WP_REST_Request $request Request.
		 * @return WP_REST_Response|WP_Error
		 */
		public static function respond( $request ) {
			$id    = absint( $request['id'] );
			$state = wp_cache_get( 'state_' . $id, 'lafka_order_tracking' );
			if ( ! is_array( $state ) ) {
				$order = wc_get_order( $id );
				if ( ! $order ) {
					return new WP_Error( 'lafka_order_not_found', __( 'Order not found.', 'lafka-plugin' ), array( 'status' => 404 ) );
				}
				$state = Lafka_Order_Tracking::state( $order );
				wp_cache_set( 'state_' . $id, $state, 'lafka_order_tracking', self::CACHE_TTL );
			}

			$response = new WP_REST_Response( $state );
			$response->header( 'Cache-Control', 'private, max-age=' . self::CACHE_TTL );
			return $response;
		}
	}
}
