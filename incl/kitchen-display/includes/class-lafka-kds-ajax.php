<?php
/**
 * KDS AJAX endpoints.
 *
 * AUTH MODEL (do not weaken without thinking through every endpoint):
 *
 *   1. Operator opens `https://site/kitchen-display/<TOKEN>/`. The standalone
 *      page renders only if `hash_equals($options['token'], $url_token)` —
 *      see Lafka_KDS_Frontend::handle_request().
 *   2. The page injects `LAFKA_KDS = { token, nonce, … }` into the global
 *      JS scope. The page sets `Referrer-Policy: no-referrer` so the token
 *      never leaks via Referer.
 *   3. JS calls AJAX endpoints with both `kds_token` and `nonce`. Every
 *      KDS endpoint validates BOTH via `verify_kds_auth()` (the nonce check
 *      uses `check_ajax_referer($action, $query, false)` so we can rate-limit
 *      failures rather than `wp_die()` on the first bad nonce).
 *   4. `refresh_nonce` — token-only renewal — exists because nonces expire
 *      every ~12-24h and the standalone page is open all day. It is the
 *      most security-sensitive endpoint: failure-only IP rate limiter
 *      caps brute-force / DOS attempts at 5/min.
 *
 * GOTCHAS:
 *   - Token is stored plaintext in `lafka_kds_options['token']` because it
 *     is also embedded in the visible page URL. Hashing storage is tracked
 *     as P2-02a (would require token-id + secret split).
 *   - Both `wp_ajax_` and `wp_ajax_nopriv_` versions are registered. The
 *     `_nopriv_` variant is essential — the standalone page has no WP login.
 *     Token + nonce + rate-limit are the gate, not user authentication.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// PHPCS suppression: every public AJAX handler in this class calls
// `verify_kds_auth()` (or `verify_kds_customer_auth()`) as its first action,
// which in turn calls `check_ajax_referer()`. PHPCS doesn't trace nonce
// verification through helper methods; suppression is correct here.
// phpcs:disable WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended

class Lafka_KDS_Ajax {

	public function __construct() {
		// KDS endpoints (nopriv because standalone page has no WP login)
		add_action( 'wp_ajax_lafka_kds_get_orders', array( $this, 'get_orders' ) );
		add_action( 'wp_ajax_nopriv_lafka_kds_get_orders', array( $this, 'get_orders' ) );

		add_action( 'wp_ajax_lafka_kds_update_status', array( $this, 'update_status' ) );
		add_action( 'wp_ajax_nopriv_lafka_kds_update_status', array( $this, 'update_status' ) );

		add_action( 'wp_ajax_lafka_kds_set_eta', array( $this, 'set_eta' ) );
		add_action( 'wp_ajax_nopriv_lafka_kds_set_eta', array( $this, 'set_eta' ) );

		// Customer endpoint
		add_action( 'wp_ajax_lafka_kds_customer_status', array( $this, 'customer_status' ) );
		add_action( 'wp_ajax_nopriv_lafka_kds_customer_status', array( $this, 'customer_status' ) );

		// Nonce refresh endpoint (token-only auth, no nonce required)
		add_action( 'wp_ajax_lafka_kds_refresh_nonce', array( $this, 'refresh_nonce' ) );
		add_action( 'wp_ajax_nopriv_lafka_kds_refresh_nonce', array( $this, 'refresh_nonce' ) );
	}

	/**
	 * Verify KDS token + nonce from request. Bails with 403/429 on failure.
	 *
	 * Failure modes are folded into one generic "Invalid credentials" response so
	 * an attacker cannot probe nonce-vs-token state. Failed attempts are counted
	 * by IP via {@see track_auth_failure()}; sustained failures yield 429.
	 */
	private function verify_kds_auth() {
		// Don't auto-die on bad nonce — we want to count it toward the rate limit.
		$valid_nonce = (bool) check_ajax_referer( 'lafka_kds_nonce', 'nonce', false );

		$token       = isset( $_POST['kds_token'] ) ? sanitize_text_field( $_POST['kds_token'] ) : '';
		$options     = Lafka_Kitchen_Display::get_options();
		$valid_token = ! empty( $options['token'] ) && hash_equals( $options['token'], $token );

		if ( $valid_nonce && $valid_token ) {
			return;
		}

		if ( $this->track_auth_failure( 'kds_auth' ) ) {
			wp_send_json_error( array( 'message' => 'Too many failed attempts' ), 429 );
		}
		wp_send_json_error( array( 'message' => 'Invalid credentials' ), 403 );
	}

	/**
	 * Increment a per-IP failure counter for `$bucket`; return true if the new
	 * count puts this IP over `$max_per_minute`.
	 *
	 * HIGH-2: implements a *fixed* sliding window. The previous version called
	 * `set_transient($key, $count, MINUTE_IN_SECONDS)` on every failure, which
	 * resets the TTL each time — an attacker pacing requests at 1 per 61s
	 * keeps the counter at 1 forever and is never blocked. We now store the
	 * counter alongside an `expires_at` timestamp set on the FIRST failure of
	 * a window, so the window expires on real time, not on quiet time.
	 *
	 * Behind a reverse proxy (Cloudflare, AWS ALB) `REMOTE_ADDR` collapses to
	 * the LB's IP and all clients share one bucket. Filter `lafka_kds_client_ip`
	 * to feed e.g. `CF-Connecting-IP` or the rightmost-trusted XFF hop. The
	 * filter implementor is responsible for validating that the upstream
	 * header comes from a trusted proxy — a naive header-trust opens up
	 * trivial bypass via spoofed `X-Forwarded-For`.
	 */
	private function track_auth_failure( $bucket, $max_per_minute = 5 ) {
		$ip = isset( $_SERVER['REMOTE_ADDR'] )
			? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) )
			: '0.0.0.0';
		$ip = apply_filters( 'lafka_kds_client_ip', $ip );

		$key    = 'lafka_kds_failrate_' . $bucket . '_' . md5( $ip );
		$now    = time();
		$window = MINUTE_IN_SECONDS;
		$entry  = get_transient( $key );

		if ( ! is_array( $entry ) || empty( $entry['expires_at'] ) || $entry['expires_at'] <= $now ) {
			// New window starts at this failure. Set the expiry once.
			$entry = array(
				'count'      => 1,
				'expires_at' => $now + $window,
			);
		} else {
			$entry['count'] = (int) $entry['count'] + 1;
		}

		// Compute remaining TTL for the transient so it self-expires when the
		// window does (no point keeping it longer in storage).
		$ttl = max( 1, $entry['expires_at'] - $now );
		set_transient( $key, $entry, $ttl );

		return $entry['count'] > $max_per_minute;
	}

	/**
	 * Get all active orders for KDS display.
	 */
	public function get_orders() {
		$this->verify_kds_auth();

		// Issue #18: Add capability check
		if ( is_user_logged_in() && ! current_user_can( 'edit_shop_orders' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions' ), 403 );
		}

		// Active orders (all statuses in the workflow)
		$orders = wc_get_orders(
			array(
				'status'  => array( 'processing', 'accepted', 'preparing', 'ready' ),
				'limit'   => 100,
				'orderby' => 'date',
				'order'   => 'ASC',
			)
		);

		// Recently completed orders (last 4 hours) so staff can still see them
		$completed = wc_get_orders(
			array(
				'status'     => 'completed',
				'limit'      => 50,
				'orderby'    => 'date',
				'order'      => 'DESC',
				'date_after' => gmdate( 'Y-m-d H:i:s', time() - 4 * HOUR_IN_SECONDS ),
			)
		);

		$data     = array();
		$seen_ids = array();

		// Prime meta cache in a single query to avoid N+1 per-order meta lookups
		$all_order_ids = array();
		foreach ( $orders as $order ) {
			$all_order_ids[] = $order->get_id();
		}
		foreach ( $completed as $order ) {
			$all_order_ids[] = $order->get_id();
		}
		if ( ! empty( $all_order_ids ) ) {
			// Batch-load postmeta for legacy CPT orders. Under HPOS the order
			// meta lives in `wc_orders_meta` and is already loaded into the
			// `$order` object's in-memory cache when `wc_get_orders()` returned
			// the WC_Order — so this call is unnecessary (and primes the wrong
			// cache for orders that don't have a `wp_posts` row at all).
			if (
				! class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
				|| ! \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled()
			) {
				update_meta_cache( 'post', $all_order_ids );
			}

			// Also prime order item meta (line items have separate meta table)
			$all_item_ids = array();
			foreach ( array_merge( $orders, $completed ) as $order ) {
				foreach ( $order->get_items() as $item ) {
					$all_item_ids[] = $item->get_id();
				}
			}
			if ( ! empty( $all_item_ids ) ) {
				update_meta_cache( 'order_item', $all_item_ids );
			}

			// PERF-H19: Batch-prime product_cat term cache for all products across all orders.
			// This avoids N+1 wp_get_post_terms() calls per line item in format_order().
			$all_product_ids = array();
			foreach ( array_merge( $orders, $completed ) as $order ) {
				foreach ( $order->get_items() as $item ) {
					$product = $item->get_product();
					if ( $product ) {
						$all_product_ids[] = $product->get_id();
					}
				}
			}
			if ( ! empty( $all_product_ids ) ) {
				$all_product_ids = array_unique( $all_product_ids );
				update_object_term_cache( $all_product_ids, 'product' );
			}
		}

		foreach ( $orders as $order ) {
			$id = $order->get_id();
			if ( ! isset( $seen_ids[ $id ] ) ) {
				$seen_ids[ $id ] = true;
				$data[]          = $this->format_order( $order );
			}
		}
		foreach ( $completed as $order ) {
			$id = $order->get_id();
			if ( ! isset( $seen_ids[ $id ] ) ) {
				$seen_ids[ $id ] = true;
				$data[]          = $this->format_order( $order );
			}
		}

		wp_send_json_success(
			array(
				'orders'      => $data,
				'server_time' => time(),
			)
		);
	}

	/**
	 * Format order data for KDS. Delegates to Lafka_KDS_Order_Formatter so
	 * each shaping concern (line items, payment, scheduling, delivery) can
	 * be unit-tested without standing up the AJAX request flow.
	 */
	private function format_order( $order ) {
		return ( new Lafka_KDS_Order_Formatter() )->format( $order );
	}

	/**
	 * Update order status (with transition validation).
	 */
	public function update_status() {
		$this->verify_kds_auth();

		// Issue #18: Add capability check
		if ( is_user_logged_in() && ! current_user_can( 'edit_shop_orders' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions' ), 403 );
		}

		$order_id   = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$new_status = isset( $_POST['new_status'] ) ? sanitize_text_field( $_POST['new_status'] ) : '';

		if ( ! $order_id || ! $new_status ) {
			wp_send_json_error( array( 'message' => 'Missing parameters' ) );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_send_json_error( array( 'message' => 'Order not found' ) );
		}

		// Atomic database-level lock (fixes TOCTOU race condition with transients)
		global $wpdb;
		$lock_name = 'kds_order_' . $order_id;
		$acquired  = $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 0)', $lock_name ) );
		if ( ! $acquired ) {
			wp_send_json_error( array( 'message' => 'Order is being updated, please try again' ), 409 );
		}

		$allowed = Lafka_KDS_Order_Statuses::get_allowed_transitions();
		$current = $order->get_status();

		// Prevent modifications to completed/rejected orders
		if ( in_array( $current, array( 'completed', 'rejected' ), true ) ) {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
			wp_send_json_error( array( 'message' => 'Cannot modify ' . $current . ' orders' ), 400 );
		}

		if ( ! isset( $allowed[ $current ] ) || ! in_array( $new_status, $allowed[ $current ], true ) ) {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );
			wp_send_json_error( array( 'message' => 'Invalid status transition' ) );
		}

		// Record acceptance timestamp
		if ( 'accepted' === $new_status ) {
			$order->update_meta_data( '_lafka_kds_accepted_at', time() );
		}

		$order->set_status( $new_status );
		$order->save();

		// Release lock
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $lock_name ) );

		wp_send_json_success(
			array(
				'order_id'   => $order_id,
				'new_status' => $new_status,
			)
		);
	}

	/**
	 * Set ETA for an order.
	 */
	public function set_eta() {
		$this->verify_kds_auth();

		// Issue #18: Add capability check
		if ( is_user_logged_in() && ! current_user_can( 'edit_shop_orders' ) ) {
			wp_send_json_error( array( 'message' => 'Insufficient permissions' ), 403 );
		}

		$order_id = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$minutes  = isset( $_POST['minutes'] ) ? (int) $_POST['minutes'] : 0;

		// Issue #14: Add reasonable upper bound (180 minutes = 3 hours)
		if ( ! $order_id || $minutes < 1 || $minutes > 180 ) {
			wp_send_json_error( array( 'message' => 'ETA must be between 1-180 minutes' ), 400 );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			wp_send_json_error( array( 'message' => 'Order not found' ) );
		}

		$eta_timestamp = time() + ( $minutes * 60 );
		$order->update_meta_data( '_lafka_kds_eta', $eta_timestamp );
		$order->update_meta_data( '_lafka_kds_eta_minutes', $minutes );
		$order->save();

		wp_send_json_success(
			array(
				'order_id'    => $order_id,
				'eta'         => $eta_timestamp,
				'eta_minutes' => $minutes,
			)
		);
	}

	/**
	 * Customer-facing status check (authenticated via order key).
	 */
	public function customer_status() {
		$valid_nonce = (bool) check_ajax_referer( 'lafka_kds_customer_nonce', 'nonce', false );
		if ( ! $valid_nonce ) {
			if ( $this->track_auth_failure( 'customer_status' ) ) {
				wp_send_json_error( array( 'message' => 'Too many failed attempts' ), 429 );
			}
			wp_send_json_error( array( 'message' => 'Invalid credentials' ), 403 );
		}

		$order_id  = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$order_key = isset( $_POST['order_key'] ) ? sanitize_text_field( $_POST['order_key'] ) : '';

		if ( ! $order_id || ! $order_key ) {
			wp_send_json_error( array( 'message' => 'Missing parameters' ) );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order || ! hash_equals( $order->get_order_key(), $order_key ) ) {
			if ( $this->track_auth_failure( 'customer_status' ) ) {
				wp_send_json_error( array( 'message' => 'Too many failed attempts' ), 429 );
			}
			wp_send_json_error( array( 'message' => 'Invalid order' ), 403 );
		}

		$status    = $order->get_status();
		$eta       = $order->get_meta( '_lafka_kds_eta' );
		$statuses  = wc_get_order_statuses();
		$wc_status = 'wc-' . $status;

		wp_send_json_success(
			array(
				'status'       => $status,
				'status_label' => isset( $statuses[ $wc_status ] ) ? $statuses[ $wc_status ] : $status,
				'eta'          => $eta ? (int) $eta : null,
				'order_type'   => Lafka_Kitchen_Display::get_order_type( $order ),
				'server_time'  => time(),
			)
		);
	}

	/**
	 * Refresh nonce (token-only auth, no nonce required).
	 * Called by the KDS JS when the current nonce is about to expire (~30 min cadence).
	 *
	 * Most security-sensitive endpoint: it mints fresh nonces on token-only proof,
	 * so a stolen token = unbounded fresh credentials. Failure-only IP rate limiter
	 * caps brute-force at 5/min per IP. Legit operators hit this 2×/hour at most.
	 */
	public function refresh_nonce() {
		$token   = isset( $_POST['kds_token'] ) ? sanitize_text_field( $_POST['kds_token'] ) : '';
		$options = Lafka_Kitchen_Display::get_options();

		if ( empty( $options['token'] ) || ! hash_equals( $options['token'], $token ) ) {
			if ( $this->track_auth_failure( 'refresh_nonce' ) ) {
				wp_send_json_error( array( 'message' => 'Too many failed attempts' ), 429 );
			}
			wp_send_json_error( array( 'message' => 'Invalid token' ), 403 );
		}

		wp_send_json_success(
			array(
				'nonce' => wp_create_nonce( 'lafka_kds_nonce' ),
			)
		);
	}
}
// phpcs:enable WordPress.Security.NonceVerification.Missing,WordPress.Security.NonceVerification.Recommended
