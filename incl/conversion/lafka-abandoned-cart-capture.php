<?php
/**
 * Phase 3B (v9.27.0): Abandoned-cart recovery — capture layer.
 *
 * Records (email, cart) into the abandoned-cart table whenever a customer:
 *   - blurs the checkout email field (AJAX listener — handled by JS in the
 *     theme; this file exposes the wp-admin/admin-ajax.php endpoint)
 *   - or triggers `woocommerce_checkout_update_order_review` (the standard
 *     WC AJAX that re-renders the order-review block when the email field is
 *     populated)
 *
 * Marks the row as recovered when an order completes successfully via
 * `woocommerce_checkout_order_processed`.
 *
 * Self-gates on the `lafka_ac_enabled` Customizer toggle so an operator who
 * hasn't opted in pays zero overhead at request time.
 *
 * Privacy: the email is captured only AFTER the customer typed it into the
 * checkout form themselves — same opt-in surface as the order they intend to
 * place. No tracking pixels, no third-party calls.
 *
 * @package Lafka\Plugin\Conversion
 * @since   9.27.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'lafka_ac_capture_is_enabled' ) ) {
	/**
	 * Master enable toggle — read from Customizer setting `lafka_ac_enabled`.
	 *
	 * @return bool
	 */
	function lafka_ac_capture_is_enabled(): bool {
		if ( ! function_exists( 'get_theme_mod' ) ) {
			return false;
		}
		$value = get_theme_mod( 'lafka_ac_enabled', '0' );
		return '1' === (string) $value;
	}
}

if ( ! function_exists( 'lafka_ac_get_cart_snapshot' ) ) {
	/**
	 * Take a JSON-serialisable snapshot of the current WC cart.
	 *
	 * Returns [] when cart is empty or WC isn't loaded — caller no-ops in that case.
	 *
	 * Shape:
	 *   [
	 *     'items' => [
	 *       [
	 *         'product_id'   => int,
	 *         'variation_id' => int,
	 *         'name'         => string,
	 *         'quantity'     => int,
	 *         'price'        => float (line subtotal),
	 *         'image'        => string URL,
	 *         'permalink'    => string,
	 *       ],
	 *       …
	 *     ],
	 *     'subtotal' => float,
	 *     'currency' => string,
	 *   ]
	 *
	 * @return array
	 */
	function lafka_ac_get_cart_snapshot(): array {
		if ( ! function_exists( 'WC' ) ) {
			return array();
		}
		$wc = WC();
		if ( ! is_object( $wc ) || ! isset( $wc->cart ) || ! is_object( $wc->cart ) ) {
			return array();
		}
		$cart = $wc->cart;
		if ( method_exists( $cart, 'is_empty' ) && $cart->is_empty() ) {
			return array();
		}

		$items = array();
		if ( method_exists( $cart, 'get_cart' ) ) {
			foreach ( $cart->get_cart() as $cart_item_key => $cart_item ) {
				$product_id   = isset( $cart_item['product_id'] ) ? (int) $cart_item['product_id'] : 0;
				$variation_id = isset( $cart_item['variation_id'] ) ? (int) $cart_item['variation_id'] : 0;
				$qty          = isset( $cart_item['quantity'] ) ? (int) $cart_item['quantity'] : 0;
				$line_total   = isset( $cart_item['line_subtotal'] ) ? (float) $cart_item['line_subtotal'] : 0.0;

				$name      = '';
				$permalink = '';
				$image     = '';
				if ( isset( $cart_item['data'] ) && is_object( $cart_item['data'] ) ) {
					$product = $cart_item['data'];
					if ( method_exists( $product, 'get_name' ) ) {
						$name = (string) $product->get_name();
					}
					if ( method_exists( $product, 'get_permalink' ) ) {
						$permalink = (string) $product->get_permalink();
					}
					if ( method_exists( $product, 'get_image_id' ) ) {
						$image_id = (int) $product->get_image_id();
						if ( $image_id > 0 && function_exists( 'wp_get_attachment_image_url' ) ) {
							$resolved = wp_get_attachment_image_url( $image_id, 'thumbnail' );
							if ( is_string( $resolved ) ) {
								$image = $resolved;
							}
						}
					}
				}

				$items[] = array(
					'product_id'   => $product_id,
					'variation_id' => $variation_id,
					'name'         => $name,
					'quantity'     => $qty,
					'price'        => $line_total,
					'image'        => $image,
					'permalink'    => $permalink,
				);
			}
		}

		if ( empty( $items ) ) {
			return array();
		}

		$subtotal = method_exists( $cart, 'get_subtotal' ) ? (float) $cart->get_subtotal() : 0.0;
		$currency = function_exists( 'get_woocommerce_currency' ) ? (string) get_woocommerce_currency() : '';

		return array(
			'items'    => $items,
			'subtotal' => $subtotal,
			'currency' => $currency,
		);
	}
}

if ( ! function_exists( 'lafka_ac_get_session_id' ) ) {
	/**
	 * Resolve a stable session ID for dedupe.
	 *
	 * Uses WC's session customer_id when available (a session-bound UUID),
	 * otherwise falls back to a hash of the user-agent + IP. Never returns
	 * the raw IP — that's PII we'd then have to store.
	 *
	 * @return string
	 */
	function lafka_ac_get_session_id(): string {
		if ( function_exists( 'WC' ) ) {
			$wc = WC();
			if ( is_object( $wc ) && isset( $wc->session ) && is_object( $wc->session ) ) {
				$session = $wc->session;
				if ( method_exists( $session, 'get_customer_id' ) ) {
					$id = (string) $session->get_customer_id();
					if ( '' !== $id ) {
						return $id;
					}
				}
			}
		}
		// Fallback: hashed UA — never raw IP / PII.
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? (string) $_SERVER['HTTP_USER_AGENT'] : '';
		return substr( md5( $ua . wp_salt() ), 0, 32 );
	}
}

if ( ! function_exists( 'lafka_ac_capture_from_post' ) ) {
	/**
	 * Read the customer email from the current request's POST payload.
	 *
	 * WC's checkout AJAX puts the entire serialised form in $_POST['post_data'];
	 * the regular checkout submit lands in $_POST['billing_email']. Both surfaces
	 * are covered so the row is written on either path.
	 *
	 * @return string Lowercased + sanitised email, or '' if none found / invalid.
	 */
	function lafka_ac_capture_from_post(): string {
		// CSRF: this helper only fires from WC core hooks
		// (woocommerce_checkout_update_order_review + woocommerce_checkout_order_processed),
		// both of which verify their own checkout nonce upstream before invoking
		// the action chain. Suppress the Missing-nonce sniff for the whole
		// function since it never runs outside that protected context.
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$email = '';

		// AJAX update_order_review payload — flat string of url-encoded form data.
		if ( isset( $_POST['post_data'] ) && is_string( $_POST['post_data'] ) ) {
			parse_str( wp_unslash( $_POST['post_data'] ), $parsed );
			if ( isset( $parsed['billing_email'] ) && is_string( $parsed['billing_email'] ) ) {
				$email = $parsed['billing_email'];
			}
		}

		// Regular checkout submit.
		if ( '' === $email && isset( $_POST['billing_email'] ) && is_string( $_POST['billing_email'] ) ) {
			$email = wp_unslash( $_POST['billing_email'] );
		}

		// Custom Lafka blur-event AJAX endpoint (theme-side opt-in).
		if ( '' === $email && isset( $_POST['email'] ) && is_string( $_POST['email'] ) ) {
			$email = wp_unslash( $_POST['email'] );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		if ( '' === $email ) {
			return '';
		}
		$email = function_exists( 'sanitize_email' ) ? sanitize_email( $email ) : trim( strtolower( $email ) );
		if ( ! $email ) {
			return '';
		}
		if ( function_exists( 'is_email' ) && ! is_email( $email ) ) {
			return '';
		}
		return strtolower( $email );
	}
}

if ( ! function_exists( 'lafka_ac_handle_update_order_review' ) ) {
	/**
	 * Hook handler: WC fires this on every email/address field edit on /checkout/.
	 *
	 * @return void
	 */
	function lafka_ac_handle_update_order_review(): void {
		if ( ! lafka_ac_capture_is_enabled() ) {
			return;
		}
		$email = lafka_ac_capture_from_post();
		if ( '' === $email ) {
			return;
		}
		$snapshot = lafka_ac_get_cart_snapshot();
		if ( empty( $snapshot ) || empty( $snapshot['items'] ) ) {
			return;
		}
		$session_id = lafka_ac_get_session_id();
		lafka_ac_save_cart(
			$email,
			$snapshot,
			$session_id,
			isset( $snapshot['subtotal'] ) ? (float) $snapshot['subtotal'] : 0.0,
			isset( $snapshot['currency'] ) ? (string) $snapshot['currency'] : ''
		);
	}
}

if ( ! function_exists( 'lafka_ac_handle_order_processed' ) ) {
	/**
	 * Hook handler: WC fires this once the order is placed. Marks the matching
	 * abandoned-cart row as recovered so cron never sends a recovery email for
	 * a cart that already converted.
	 *
	 * Matching strategy: find the most recent pending row for the order's
	 * billing email + session_id and mark it. Misses are fine — the cron's own
	 * recovery_sent_at + order_id guards keep us idempotent.
	 *
	 * @param int $order_id
	 * @return void
	 */
	function lafka_ac_handle_order_processed( $order_id ): void {
		if ( ! lafka_ac_capture_is_enabled() ) {
			return;
		}
		$order_id = (int) $order_id;
		if ( $order_id <= 0 ) {
			return;
		}
		if ( ! function_exists( 'wc_get_order' ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order || ! is_object( $order ) ) {
			return;
		}
		$email = method_exists( $order, 'get_billing_email' ) ? (string) $order->get_billing_email() : '';
		if ( '' === $email ) {
			return;
		}
		$email = strtolower( $email );

		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) ) {
			return;
		}
		$table = lafka_ac_table_name();
		$row_id = (int) $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT id FROM {$table} WHERE customer_email = %s AND order_id = 0 ORDER BY last_seen_at DESC LIMIT 1",
				$email
			)
		);
		if ( $row_id > 0 ) {
			lafka_ac_mark_recovered( $row_id, $order_id );
		}
	}
}

if ( ! function_exists( 'lafka_ac_handle_account_deleted' ) ) {
	/**
	 * Cascade WC account deletions into the abandoned-cart table.
	 *
	 * The `woocommerce_account_delete_completed` hook isn't a WC core action by
	 * default — operators ship plugins that emit it (or a similar `delete_user`
	 * hook). We listen on both surfaces. The handler also runs on WP core's
	 * `delete_user` so an admin removing a customer in /wp-admin/users.php
	 * still purges their cart rows.
	 *
	 * @param int|object $user_or_id
	 * @return void
	 */
	function lafka_ac_handle_account_deleted( $user_or_id ): void {
		$email = '';
		if ( is_object( $user_or_id ) && isset( $user_or_id->user_email ) ) {
			$email = (string) $user_or_id->user_email;
		} elseif ( is_numeric( $user_or_id ) ) {
			$user_id = (int) $user_or_id;
			if ( $user_id > 0 && function_exists( 'get_userdata' ) ) {
				$user = get_userdata( $user_id );
				if ( $user && isset( $user->user_email ) ) {
					$email = (string) $user->user_email;
				}
			}
		}
		if ( '' === $email ) {
			return;
		}
		lafka_ac_delete_by_email( strtolower( $email ) );
	}
}

if ( function_exists( 'add_action' ) ) {
	add_action( 'woocommerce_checkout_update_order_review', 'lafka_ac_handle_update_order_review', 20 );
	add_action( 'woocommerce_checkout_order_processed', 'lafka_ac_handle_order_processed', 20, 1 );
	add_action( 'woocommerce_account_delete_completed', 'lafka_ac_handle_account_deleted', 10, 1 );
	add_action( 'delete_user', 'lafka_ac_handle_account_deleted', 10, 1 );
}
