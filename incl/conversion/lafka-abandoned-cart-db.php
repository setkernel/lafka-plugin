<?php
/**
 * Phase 3B (v9.27.0): Abandoned-cart recovery — DB layer.
 *
 * Owns the `wp_lafka_abandoned_carts` table (its schema lives in Lafka_Schema):
 *   - Helper functions to insert/upsert a row when a checkout email is captured
 *   - Helper to mark a row as recovered (an order was placed)
 *   - Helper to mark a row as "recovery email sent"
 *   - Helper to fetch rows pending recovery
 *   - Helper to delete stale rows (>30 days)
 *
 * Idempotency: every row uses (customer_email, session_id) as the dedupe key,
 * so a customer who edits the cart and re-enters their email on /checkout/ keeps
 * a single row that updates `cart_contents` and `last_seen_at` in place.
 *
 * The schema and its version are declared once in Lafka_Schema
 * (incl/tools/class-lafka-schema.php), which creates and upgrades the table.
 *
 * Privacy: each row carries the customer's email until either (a) the cron job
 * runs the 30-day cleanup, (b) the customer completes an order (then the row is
 * marked `order_id`-linked and cleaned 30 days after), or (c) the operator
 * invokes the WC `woocommerce_account_delete_completed` flow which deletes all
 * rows for that email.
 *
 * @package Lafka\Plugin\Conversion
 * @since   9.27.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'lafka_ac_table_name' ) ) {
	/**
	 * Return the fully-qualified table name (respects $wpdb->prefix).
	 *
	 * @return string
	 */
	function lafka_ac_table_name(): string {
		return Lafka_Schema::table_name( 'abandoned_carts' );
	}
}

if ( ! function_exists( 'lafka_ac_generate_resume_token' ) ) {
	/**
	 * Generate a cryptographically-random resume token.
	 *
	 * Uses wp_generate_password() with special chars OFF — token must be URL-safe.
	 * 32 chars at the default alphabet ([A-Za-z0-9]) gives ~190 bits of entropy.
	 *
	 * @return string
	 */
	function lafka_ac_generate_resume_token(): string {
		return (string) wp_generate_password( 32, false, false );
	}
}

if ( ! function_exists( 'lafka_ac_save_cart' ) ) {
	/**
	 * Upsert a row for the given email + session pair.
	 *
	 * If a pending row already exists (no recovery_sent_at, no order_id), update
	 * its cart_contents + last_seen_at in place. Otherwise insert a fresh row
	 * with a brand-new resume_token.
	 *
	 * Always returns the row ID (or 0 on failure / missing $wpdb).
	 *
	 * @param string $email      Customer email (must already be sanitized).
	 * @param array  $cart       Cart contents — will be JSON-encoded for storage.
	 * @param string $session_id WC session token for dedupe.
	 * @param float  $cart_total Optional cart subtotal at save time.
	 * @param string $currency   Optional ISO currency at save time.
	 * @return int Row ID, or 0 on failure.
	 */
	function lafka_ac_save_cart( string $email, array $cart, string $session_id, float $cart_total = 0.0, string $currency = '' ): int {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return 0;
		}
		if ( '' === $email || empty( $cart ) ) {
			return 0;
		}

		$table = lafka_ac_table_name();
		$now   = function_exists( 'current_time' ) ? (string) current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );

		// Look for an existing pending row for this email+session.
		$existing_id = 0;
		if ( method_exists( $wpdb, 'get_var' ) && method_exists( $wpdb, 'prepare' ) ) {
			$existing_id = (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT id FROM %i WHERE customer_email = %s AND session_id = %s AND recovery_sent_at IS NULL AND order_id = 0 LIMIT 1',
					$table,
					$email,
					$session_id
				)
			);
		}

		$encoded = wp_json_encode( $cart );
		if ( ! is_string( $encoded ) ) {
			$encoded = '';
		}

		if ( $existing_id > 0 ) {
			if ( method_exists( $wpdb, 'update' ) ) {
				$wpdb->update(
					$table,
					array(
						'cart_contents' => $encoded,
						'cart_total'    => $cart_total,
						'currency'      => $currency,
						'last_seen_at'  => $now,
					),
					array( 'id' => $existing_id ),
					array( '%s', '%f', '%s', '%s' ),
					array( '%d' )
				);
			}
			return $existing_id;
		}

		if ( method_exists( $wpdb, 'insert' ) ) {
			$wpdb->insert(
				$table,
				array(
					'customer_email'   => $email,
					'session_id'       => $session_id,
					'resume_token'     => lafka_ac_generate_resume_token(),
					'cart_contents'    => $encoded,
					'cart_total'       => $cart_total,
					'currency'         => $currency,
					'order_id'         => 0,
					'recovery_sent_at' => null,
					'created_at'       => $now,
					'last_seen_at'     => $now,
				),
				array( '%s', '%s', '%s', '%s', '%f', '%s', '%d', '%s', '%s', '%s' )
			);
			$insert_id = isset( $wpdb->insert_id ) ? (int) $wpdb->insert_id : 0;
			return $insert_id;
		}
		return 0;
	}
}

if ( ! function_exists( 'lafka_ac_mark_recovered' ) ) {
	/**
	 * Mark a row as having converted to an order. Cron will skip it from then on.
	 *
	 * @param int $row_id
	 * @param int $order_id The WC order ID that closed the loop.
	 * @return void
	 */
	function lafka_ac_mark_recovered( int $row_id, int $order_id ): void {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'update' ) ) {
			return;
		}
		if ( $row_id <= 0 ) {
			return;
		}
		$wpdb->update(
			lafka_ac_table_name(),
			array( 'order_id' => $order_id ),
			array( 'id' => $row_id ),
			array( '%d' ),
			array( '%d' )
		);
	}
}

if ( ! function_exists( 'lafka_ac_mark_recovery_sent' ) ) {
	/**
	 * Mark a row as having had its recovery email sent. Cron will skip it next pass.
	 *
	 * @param int $row_id
	 * @return void
	 */
	function lafka_ac_mark_recovery_sent( int $row_id ): void {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'update' ) ) {
			return;
		}
		if ( $row_id <= 0 ) {
			return;
		}
		$now = function_exists( 'current_time' ) ? (string) current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
		$wpdb->update(
			lafka_ac_table_name(),
			array( 'recovery_sent_at' => $now ),
			array( 'id' => $row_id ),
			array( '%s' ),
			array( '%d' )
		);
	}
}

if ( ! function_exists( 'lafka_ac_get_pending' ) ) {
	/**
	 * Fetch rows eligible for a recovery email.
	 *
	 * Criteria:
	 *   - recovery_sent_at IS NULL (haven't emailed yet)
	 *   - order_id = 0 (no order linked)
	 *   - last_seen_at < NOW() - delay_minutes
	 *
	 * @param int $delay_minutes How long the cart must have been idle.
	 * @param int $limit         Batch size cap.
	 * @return array<int, object>
	 */
	function lafka_ac_get_pending( int $delay_minutes = 75, int $limit = 50 ): array {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_results' ) ) {
			return array();
		}
		$delay_minutes = max( 1, $delay_minutes );
		$limit         = max( 1, min( 500, $limit ) );

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE recovery_sent_at IS NULL AND order_id = 0 AND last_seen_at < DATE_SUB(NOW(), INTERVAL %d MINUTE) ORDER BY last_seen_at ASC LIMIT %d',
				lafka_ac_table_name(),
				$delay_minutes,
				$limit
			)
		);
		return is_array( $rows ) ? $rows : array();
	}
}

if ( ! function_exists( 'lafka_ac_get_row_by_token' ) ) {
	/**
	 * Look up a row by its resume token (used by the /?lafka_resume_cart=… handler).
	 *
	 * @param string $token
	 * @return object|null
	 */
	function lafka_ac_get_row_by_token( string $token ) {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_row' ) ) {
			return null;
		}
		if ( '' === $token ) {
			return null;
		}
		$row = $wpdb->get_row(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE resume_token = %s LIMIT 1',
				lafka_ac_table_name(),
				$token
			)
		);
		return $row ? $row : null;
	}
}

if ( ! function_exists( 'lafka_ac_cleanup' ) ) {
	/**
	 * Delete rows older than $days_old. Defaults to 30 days. Run daily by cron.
	 *
	 * @param int $days_old
	 * @return int Number of rows deleted.
	 */
	function lafka_ac_cleanup( int $days_old = 30 ): int {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'query' ) ) {
			return 0;
		}
		$days_old = max( 1, $days_old );
		$result   = $wpdb->query(
			$wpdb->prepare(
				'DELETE FROM %i WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)',
				lafka_ac_table_name(),
				$days_old
			)
		);
		return is_numeric( $result ) ? (int) $result : 0;
	}
}

if ( ! function_exists( 'lafka_ac_delete_by_email' ) ) {
	/**
	 * GDPR / right-to-be-forgotten — delete every row for the given email.
	 *
	 * Hooked into the WC `woocommerce_account_delete_completed` flow so account
	 * deletions cascade into the abandoned-cart history.
	 *
	 * @param string $email
	 * @return int Number of rows deleted.
	 */
	function lafka_ac_delete_by_email( string $email ): int {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'delete' ) ) {
			return 0;
		}
		if ( '' === $email ) {
			return 0;
		}
		$deleted = $wpdb->delete(
			lafka_ac_table_name(),
			array( 'customer_email' => $email ),
			array( '%s' )
		);
		return is_numeric( $deleted ) ? (int) $deleted : 0;
	}
}
