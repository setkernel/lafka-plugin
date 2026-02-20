<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

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
	 * Verify KDS token from request.
	 */
	private function verify_kds_auth() {
		check_ajax_referer( 'lafka_kds_nonce', 'nonce' );

		$token   = isset( $_POST['kds_token'] ) ? sanitize_text_field( $_POST['kds_token'] ) : '';
		$options = Lafka_Kitchen_Display::get_options();

		if ( empty( $options['token'] ) || ! hash_equals( $options['token'], $token ) ) {
			wp_send_json_error( array( 'message' => 'Invalid token' ), 403 );
		}
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
		$orders = wc_get_orders( array(
			'status'  => array( 'processing', 'accepted', 'preparing', 'ready' ),
			'limit'   => 100,
			'orderby' => 'date',
			'order'   => 'ASC',
		) );

		// Recently completed orders (last 4 hours) so staff can still see them
		$completed = wc_get_orders( array(
			'status'     => 'completed',
			'limit'      => 50,
			'orderby'    => 'date',
			'order'      => 'DESC',
			'date_after' => gmdate( 'Y-m-d H:i:s', time() - 4 * HOUR_IN_SECONDS ),
		) );

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
			// Batch-load postmeta for all orders + their line items
			update_meta_cache( 'post', $all_order_ids );

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
		}

		foreach ( $orders as $order ) {
			$id = $order->get_id();
			if ( ! isset( $seen_ids[ $id ] ) ) {
				$seen_ids[ $id ] = true;
				$data[] = $this->format_order( $order );
			}
		}
		foreach ( $completed as $order ) {
			$id = $order->get_id();
			if ( ! isset( $seen_ids[ $id ] ) ) {
				$seen_ids[ $id ] = true;
				$data[] = $this->format_order( $order );
			}
		}

		wp_send_json_success( array(
			'orders'      => $data,
			'server_time' => time(),
		) );
	}

	/**
	 * Format order data for KDS.
	 */
	private function format_order( $order ) {
		$items = array();
		foreach ( $order->get_items() as $item ) {
			$item_data = array(
				'name'     => $item->get_name(),
				'quantity' => $item->get_quantity(),
				'meta'     => array(),
				'category' => '',
			);

			// Get product category for item grouping on KDS
			$product = $item->get_product();
			if ( $product ) {
				$categories = wp_get_post_terms( $product->get_id(), 'product_cat', array( 'fields' => 'names' ) );
				if ( ! is_wp_error( $categories ) && ! empty( $categories ) ) {
					$item_data['category'] = $categories[0];
				}
			}

			// Get formatted meta (variations, addons, etc.)
			$meta_data = $item->get_formatted_meta_data( '_', true );
			foreach ( $meta_data as $meta ) {
				$item_data['meta'][] = array(
					'key'   => wp_strip_all_tags( $meta->display_key ),
					'value' => wp_strip_all_tags( $meta->display_value ),
				);
			}

			$items[] = $item_data;
		}

		$order_type = Lafka_Kitchen_Display::get_order_type( $order );

		// Payment method
		$payment_method = $order->get_payment_method();
		$is_paid_online = ! in_array( $payment_method, array( 'cod', 'cheque', '' ), true );

		// Scheduled time (from lafka shipping areas or similar)
		$scheduled_date = $order->get_meta( 'lafka_order_date' );
		$scheduled_time = $order->get_meta( 'lafka_order_time' );
		$scheduled      = '';
		if ( $scheduled_date && $scheduled_time ) {
			$scheduled = $scheduled_date . ' ' . $scheduled_time;
		}

		// ETA
		$eta         = $order->get_meta( '_lafka_kds_eta' );
		$eta_minutes = $order->get_meta( '_lafka_kds_eta_minutes' );
		$accepted_at = $order->get_meta( '_lafka_kds_accepted_at' );

		// Issue #30: Add missing order metadata
		$delivery_address = '';
		if ( 'delivery' === $order_type ) {
			$address_parts = array(
				$order->get_shipping_address_1(),
				$order->get_shipping_address_2(),
				$order->get_shipping_city(),
				$order->get_shipping_state(),
				$order->get_shipping_postcode(),
			);
			$delivery_address = trim( implode( ', ', array_filter( $address_parts ) ) );
		}

		// Get special instructions from order meta
		$special_instructions = $order->get_meta( '_lafka_special_instructions' );

		// Get allergen information if available
		$allergen_info = $order->get_meta( '_lafka_allergen_info' );

		return array(
			'id'                   => $order->get_id(),
			'number'               => $order->get_order_number(),
			'status'               => $order->get_status(),
			'date_created'         => $order->get_date_created() ? $order->get_date_created()->getTimestamp() : 0,
			'order_type'           => $order_type,
			'is_paid_online'       => $is_paid_online,
			'payment_label'        => $is_paid_online ? __( 'Paid Online', 'lafka-plugin' ) : __( 'Cash on Delivery', 'lafka-plugin' ),
			'customer_name'        => $order->get_billing_first_name() . ' ' . $order->get_billing_last_name(),
			'customer_phone'       => $order->get_billing_phone(),
			'items'                => $items,
			'customer_note'        => $order->get_customer_note(),
			'scheduled'            => $scheduled,
			'eta'                  => $eta ? (int) $eta : null,
			'eta_minutes'          => $eta_minutes ? (int) $eta_minutes : null,
			'accepted_at'          => $accepted_at ? (int) $accepted_at : null,
			'total'                => $order->get_total(),
			'currency_symbol'      => get_woocommerce_currency_symbol( $order->get_currency() ),
			'delivery_address'     => $delivery_address,
			'special_instructions' => $special_instructions,
			'allergen_info'        => $allergen_info,
		);
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
		$acquired  = $wpdb->get_var( $wpdb->prepare( "SELECT GET_LOCK(%s, 0)", $lock_name ) );
		if ( ! $acquired ) {
			wp_send_json_error( array( 'message' => 'Order is being updated, please try again' ), 409 );
		}

		// Validate transitions (forward, undo, and reject)
		$allowed = array(
			'processing' => array( 'accepted', 'rejected' ),
			'on-hold'    => array( 'accepted' ),
			'accepted'   => array( 'preparing', 'rejected', 'processing' ),
			'preparing'  => array( 'ready', 'accepted' ),
			'ready'      => array( 'completed', 'preparing' ),
		);

		$current = $order->get_status();

		// Prevent modifications to completed/rejected orders
		if ( in_array( $current, array( 'completed', 'rejected' ), true ) ) {
			$wpdb->query( $wpdb->prepare( "SELECT RELEASE_LOCK(%s)", $lock_name ) );
			wp_send_json_error( array( 'message' => 'Cannot modify ' . $current . ' orders' ), 400 );
		}

		if ( ! isset( $allowed[ $current ] ) || ! in_array( $new_status, $allowed[ $current ], true ) ) {
			$wpdb->query( $wpdb->prepare( "SELECT RELEASE_LOCK(%s)", $lock_name ) );
			wp_send_json_error( array( 'message' => 'Invalid status transition' ) );
		}

		// Record acceptance timestamp
		if ( 'accepted' === $new_status ) {
			$order->update_meta_data( '_lafka_kds_accepted_at', time() );
		}

		$order->set_status( $new_status );
		$order->save();

		// Release lock
		$wpdb->query( $wpdb->prepare( "SELECT RELEASE_LOCK(%s)", $lock_name ) );

		wp_send_json_success( array(
			'order_id'   => $order_id,
			'new_status' => $new_status,
		) );
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

		wp_send_json_success( array(
			'order_id'    => $order_id,
			'eta'         => $eta_timestamp,
			'eta_minutes' => $minutes,
		) );
	}

	/**
	 * Customer-facing status check (authenticated via order key).
	 */
	public function customer_status() {
		check_ajax_referer( 'lafka_kds_customer_nonce', 'nonce' );

		$order_id  = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$order_key = isset( $_POST['order_key'] ) ? sanitize_text_field( $_POST['order_key'] ) : '';

		if ( ! $order_id || ! $order_key ) {
			wp_send_json_error( array( 'message' => 'Missing parameters' ) );
		}

		$order = wc_get_order( $order_id );
		if ( ! $order || ! hash_equals( $order->get_order_key(), $order_key ) ) {
			wp_send_json_error( array( 'message' => 'Invalid order' ) );
		}

		$status    = $order->get_status();
		$eta       = $order->get_meta( '_lafka_kds_eta' );
		$statuses  = wc_get_order_statuses();
		$wc_status = 'wc-' . $status;

		wp_send_json_success( array(
			'status'       => $status,
			'status_label' => isset( $statuses[ $wc_status ] ) ? $statuses[ $wc_status ] : $status,
			'eta'          => $eta ? (int) $eta : null,
			'order_type'   => Lafka_Kitchen_Display::get_order_type( $order ),
			'server_time'  => time(),
		) );
	}

	/**
	 * Refresh nonce (token-only auth, no nonce required).
	 * Called by the KDS JS when the current nonce is about to expire.
	 */
	public function refresh_nonce() {
		$token   = isset( $_POST['kds_token'] ) ? sanitize_text_field( $_POST['kds_token'] ) : '';
		$options = Lafka_Kitchen_Display::get_options();

		if ( empty( $options['token'] ) || ! hash_equals( $options['token'], $token ) ) {
			wp_send_json_error( array( 'message' => 'Invalid token' ), 403 );
		}

		wp_send_json_success( array(
			'nonce' => wp_create_nonce( 'lafka_kds_nonce' ),
		) );
	}
}
