<?php
/**
 * Phase 3B (v9.27.0): Abandoned-cart recovery — DB layer.
 *
 * Owns the `wp_lafka_abandoned_carts` table:
 *   - Schema definition + dbDelta() idempotent migration
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
 * Schema is versioned via `lafka_abandoned_cart_db_version` option; the migration
 * runs through dbDelta() on plugin activation AND on any version mismatch (so
 * the table self-heals if it's missing after a stale clone).
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

if ( ! defined( 'LAFKA_ABANDONED_CART_DB_VERSION' ) ) {
	define( 'LAFKA_ABANDONED_CART_DB_VERSION', '1.0.0' );
}

if ( ! function_exists( 'lafka_ac_table_name' ) ) {
	/**
	 * Return the fully-qualified table name (respects $wpdb->prefix).
	 *
	 * @return string
	 */
	function lafka_ac_table_name(): string {
		global $wpdb;
		$prefix = isset( $wpdb ) && is_object( $wpdb ) && isset( $wpdb->prefix ) ? (string) $wpdb->prefix : 'wp_';
		return $prefix . 'lafka_abandoned_carts';
	}
}

if ( ! function_exists( 'lafka_ac_schema_sql' ) ) {
	/**
	 * CREATE TABLE statement for the abandoned-cart table.
	 *
	 * Kept as a separate function so source-grep tests can lock the column list
	 * without booting WordPress. Charset/collate comes from $wpdb when available
	 * so the table matches site conventions; falls back to utf8mb4 otherwise.
	 *
	 * Columns:
	 *   id                  PK, auto-incrementing
	 *   customer_email      indexed — used for upsert + opt-out filter
	 *   session_id          indexed — WC session token; pairs with email for dedupe
	 *   resume_token        unique random string — appears in the recovery URL
	 *   cart_contents       JSON-encoded WC cart payload (line items + qty + meta)
	 *   cart_total          numeric cart subtotal at time of save (for email)
	 *   currency            ISO currency code at time of save
	 *   order_id            non-null when an order was placed from this row
	 *   recovery_sent_at    timestamp the email left the queue (NULL if pending)
	 *   created_at          row insertion time
	 *   last_seen_at        last time the customer touched the cart (heartbeat)
	 *
	 * @return string
	 */
	function lafka_ac_schema_sql(): string {
		global $wpdb;
		$table   = lafka_ac_table_name();
		$charset = isset( $wpdb ) && is_object( $wpdb ) && method_exists( $wpdb, 'get_charset_collate' )
			? $wpdb->get_charset_collate()
			: 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';

		// dbDelta is whitespace-sensitive — keep one column per line, 2-space
		// indent, no trailing spaces. The PRIMARY KEY clause goes last.
		$sql = "CREATE TABLE {$table} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  customer_email VARCHAR(190) NOT NULL DEFAULT '',
  session_id VARCHAR(190) NOT NULL DEFAULT '',
  resume_token VARCHAR(64) NOT NULL DEFAULT '',
  cart_contents LONGTEXT NOT NULL,
  cart_total DECIMAL(18,4) NOT NULL DEFAULT 0,
  currency VARCHAR(8) NOT NULL DEFAULT '',
  order_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
  recovery_sent_at DATETIME NULL DEFAULT NULL,
  created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
  last_seen_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
  PRIMARY KEY  (id),
  KEY customer_email (customer_email),
  KEY session_id (session_id),
  KEY resume_token (resume_token),
  KEY recovery_sent_at (recovery_sent_at),
  KEY last_seen_at (last_seen_at)
) {$charset};";

		return $sql;
	}
}

if ( ! function_exists( 'lafka_ac_install_table' ) ) {
	/**
	 * Run dbDelta() to create or migrate the abandoned-cart table.
	 *
	 * Idempotent: dbDelta diffs current schema against the desired one and only
	 * emits ALTER statements when columns drift. Safe to call on every plugin
	 * activation; safe to call from a self-heal check on `plugins_loaded`.
	 *
	 * @return void
	 */
	function lafka_ac_install_table(): void {
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		$sql = lafka_ac_schema_sql();
		if ( function_exists( 'dbDelta' ) ) {
			dbDelta( $sql );
		}

		if ( function_exists( 'update_option' ) ) {
			update_option( 'lafka_abandoned_cart_db_version', LAFKA_ABANDONED_CART_DB_VERSION );
		}
	}
}

if ( ! function_exists( 'lafka_ac_maybe_install_table' ) ) {
	/**
	 * Self-heal: if the stored DB version is missing or older than this file's
	 * constant, re-run the install. Hooked on `plugins_loaded` so a stale clone
	 * that's missing the activation hook (e.g. WP CLI deploy with no activate
	 * call) still gets the table.
	 *
	 * @return void
	 */
	function lafka_ac_maybe_install_table(): void {
		if ( ! function_exists( 'get_option' ) ) {
			return;
		}
		$installed = (string) get_option( 'lafka_abandoned_cart_db_version', '' );
		if ( $installed === LAFKA_ABANDONED_CART_DB_VERSION ) {
			return;
		}
		lafka_ac_install_table();
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
		if ( function_exists( 'wp_generate_password' ) ) {
			return (string) wp_generate_password( 32, false, false );
		}
		// Fallback for environments where wp_generate_password isn't loaded
		// (notably the unit-test harness). bin2hex(random_bytes(16)) is also
		// 32 chars / ~128 bits of entropy.
		return bin2hex( random_bytes( 16 ) );
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
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT id FROM {$table} WHERE customer_email = %s AND session_id = %s AND recovery_sent_at IS NULL AND order_id = 0 LIMIT 1",
					$email,
					$session_id
				)
			);
		}

		$encoded = function_exists( 'wp_json_encode' ) ? wp_json_encode( $cart ) : json_encode( $cart );
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
		$table         = lafka_ac_table_name();

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$table} WHERE recovery_sent_at IS NULL AND order_id = 0 AND last_seen_at < DATE_SUB(NOW(), INTERVAL %d MINUTE) ORDER BY last_seen_at ASC LIMIT %d",
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
		$table = lafka_ac_table_name();
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$table} WHERE resume_token = %s LIMIT 1",
				$token
			)
		);
		return $row ?: null;
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
		$table    = lafka_ac_table_name();
		$result   = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"DELETE FROM {$table} WHERE created_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
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

// Self-heal: if the table is missing on a stale deploy, install on plugins_loaded.
if ( function_exists( 'add_action' ) ) {
	add_action( 'plugins_loaded', 'lafka_ac_maybe_install_table', 20 );
}
