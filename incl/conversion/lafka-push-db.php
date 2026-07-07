<?php
/**
 * Phase 3E (v9.29.0): Web Push notifications — DB layer.
 *
 * Owns the `wp_lafka_push_subscriptions` table:
 *   - Schema definition + dbDelta() idempotent migration
 *   - Helpers to save / delete / mark-unsubscribed / fetch active subscriptions
 *   - Helper to delete stale rows (60-day inactive cleanup)
 *
 * Idempotency: every row uses `endpoint` as the unique dedupe key — the Web
 * Push spec guarantees endpoint uniqueness per (UA, push service). A customer
 * who clears site data and re-subscribes gets a new endpoint and therefore a
 * new row; an already-subscribed customer re-running the subscribe flow on the
 * same browser hits the same endpoint and updates the row in place.
 *
 * Schema is versioned via the `lafka_push_db_version` option; the migration
 * runs through dbDelta() on plugin activation AND on any version mismatch (so
 * the table self-heals if it's missing on a stale clone or WP-CLI deploy).
 *
 * Privacy: each row carries the customer's push endpoint + public keys (~1 KB
 * each). When a customer unsubscribes (browser settings, site profile, or the
 * push service returns 410 Gone), the row is marked `unsubscribed_at` rather
 * than deleted immediately so analytics can attribute past sends; rows that
 * have been soft-deleted (`unsubscribed_at` set) for more than 60 days are
 * deleted by the cleanup helper. Active rows are never pruned on inactivity
 * alone — `last_seen_at` is a deliverability heartbeat (refreshed on every
 * successful send), not a delete trigger.
 *
 * @package Lafka\Plugin\Conversion
 * @since   9.29.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'LAFKA_PUSH_DB_VERSION' ) ) {
	define( 'LAFKA_PUSH_DB_VERSION', '1.0.0' );
}

if ( ! function_exists( 'lafka_push_table_name' ) ) {
	/**
	 * Return the fully-qualified table name (respects $wpdb->prefix).
	 *
	 * @return string
	 */
	function lafka_push_table_name(): string {
		global $wpdb;
		$prefix = isset( $wpdb ) && is_object( $wpdb ) && isset( $wpdb->prefix ) ? (string) $wpdb->prefix : 'wp_';
		return $prefix . 'lafka_push_subscriptions';
	}
}

if ( ! function_exists( 'lafka_push_schema_sql' ) ) {
	/**
	 * CREATE TABLE statement for the push-subscriptions table.
	 *
	 * Kept as a separate function so source-grep tests can lock the column list
	 * without booting WordPress. Charset/collate comes from $wpdb when available
	 * so the table matches site conventions; falls back to utf8mb4 otherwise.
	 *
	 * Columns:
	 *   id                  PK, auto-incrementing
	 *   user_id             nullable — guests can subscribe without an account
	 *   endpoint            UNIQUE — the push service URL the row sends to
	 *   p256dh              base64url public key used by the Web Push protocol
	 *   auth                base64url 16-byte auth secret for AES-GCM encryption
	 *   user_agent          UA string at subscribe time (for analytics + debug)
	 *   locale              site locale at subscribe time (multilingual sends)
	 *   created_at          row insertion time
	 *   last_seen_at        last activity heartbeat (subscribe re-up or send)
	 *   unsubscribed_at     NULL = active; timestamp = soft-deleted
	 *
	 * Note on endpoint length: Apple Web Push endpoints can run >2 KB; Mozilla
	 * autopush is short. TEXT covers all known producers. The UNIQUE key uses
	 * a 191-char prefix (utf8mb4 InnoDB limit) which is more than enough for
	 * the random-suffix portion of any push service URL to be unique.
	 *
	 * @return string
	 */
	function lafka_push_schema_sql(): string {
		global $wpdb;
		$table   = lafka_push_table_name();
		$charset = isset( $wpdb ) && is_object( $wpdb ) && method_exists( $wpdb, 'get_charset_collate' )
			? $wpdb->get_charset_collate()
			: 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';

		// dbDelta is whitespace-sensitive — keep one column per line, 2-space
		// indent, no trailing spaces. The PRIMARY KEY clause goes last.
		$sql = "CREATE TABLE {$table} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id BIGINT UNSIGNED NULL DEFAULT NULL,
  endpoint TEXT NOT NULL,
  p256dh VARCHAR(190) NOT NULL DEFAULT '',
  auth VARCHAR(64) NOT NULL DEFAULT '',
  user_agent VARCHAR(255) NOT NULL DEFAULT '',
  locale VARCHAR(16) NOT NULL DEFAULT '',
  created_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
  last_seen_at DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
  unsubscribed_at DATETIME NULL DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY endpoint (endpoint(191)),
  KEY user_id (user_id),
  KEY last_seen_at (last_seen_at),
  KEY unsubscribed_at (unsubscribed_at)
) {$charset};";

		return $sql;
	}
}

if ( ! function_exists( 'lafka_push_install_table' ) ) {
	/**
	 * Run dbDelta() to create or migrate the push-subscriptions table.
	 *
	 * Idempotent: dbDelta diffs current schema against the desired one and only
	 * emits ALTER statements when columns drift. Safe to call on every plugin
	 * activation; safe to call from a self-heal check on `plugins_loaded`.
	 *
	 * @return void
	 */
	function lafka_push_install_table(): void {
		if ( ! function_exists( 'dbDelta' ) ) {
			require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		}

		$sql = lafka_push_schema_sql();
		if ( function_exists( 'dbDelta' ) ) {
			dbDelta( $sql );
		}

		if ( function_exists( 'update_option' ) ) {
			update_option( 'lafka_push_db_version', LAFKA_PUSH_DB_VERSION );
		}
	}
}

if ( ! function_exists( 'lafka_push_maybe_install_table' ) ) {
	/**
	 * Self-heal: if the stored DB version is missing or older than this file's
	 * constant, re-run the install. Hooked on `plugins_loaded` so a stale clone
	 * that's missing the activation hook (e.g. WP-CLI deploy with no activate
	 * call) still gets the table.
	 *
	 * @return void
	 */
	function lafka_push_maybe_install_table(): void {
		if ( ! function_exists( 'get_option' ) ) {
			return;
		}
		if ( function_exists( 'lafka_push_rest_is_enabled' ) && ! lafka_push_rest_is_enabled() ) {
			return; // Default-OFF module: don't create the table until the operator opts in.
		}
		$installed = (string) get_option( 'lafka_push_db_version', '' );
		if ( $installed === LAFKA_PUSH_DB_VERSION ) {
			return;
		}
		lafka_push_install_table();
	}
}

if ( ! function_exists( 'lafka_push_save_subscription' ) ) {
	/**
	 * Upsert a subscription row for the given endpoint.
	 *
	 * If a row already exists for this endpoint, update p256dh/auth/UA/locale/
	 * last_seen_at and clear `unsubscribed_at` (the customer re-opted in).
	 * Otherwise insert a fresh row.
	 *
	 * Returns the row ID, or 0 on failure.
	 *
	 * @param string $endpoint   Push service URL — the Web Push protocol target.
	 * @param string $p256dh     base64url-encoded public key.
	 * @param string $auth       base64url-encoded auth secret.
	 * @param int    $user_id    Logged-in user ID, or 0 for guests.
	 * @param string $user_agent Optional User-Agent string at subscribe time.
	 * @param string $locale     Optional site locale at subscribe time.
	 * @return int Row ID, or 0 on failure.
	 */
	function lafka_push_save_subscription(
		string $endpoint,
		string $p256dh,
		string $auth,
		int $user_id = 0,
		string $user_agent = '',
		string $locale = ''
	): int {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
			return 0;
		}
		if ( '' === $endpoint || '' === $p256dh || '' === $auth ) {
			return 0;
		}

		$table = lafka_push_table_name();
		$now   = function_exists( 'current_time' ) ? (string) current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );

		// Look for an existing row by endpoint (the unique key).
		$existing_id = 0;
		if ( method_exists( $wpdb, 'get_var' ) && method_exists( $wpdb, 'prepare' ) ) {
			$existing_id = (int) $wpdb->get_var(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT id FROM {$table} WHERE endpoint = %s LIMIT 1",
					$endpoint
				)
			);
		}

		if ( $existing_id > 0 ) {
			if ( method_exists( $wpdb, 'update' ) ) {
				$wpdb->update(
					$table,
					array(
						'p256dh'          => $p256dh,
						'auth'            => $auth,
						'user_id'         => $user_id > 0 ? $user_id : null,
						'user_agent'      => $user_agent,
						'locale'          => $locale,
						'last_seen_at'    => $now,
						'unsubscribed_at' => null,
					),
					array( 'id' => $existing_id ),
					array( '%s', '%s', '%d', '%s', '%s', '%s', '%s' ),
					array( '%d' )
				);
			}
			return $existing_id;
		}

		if ( method_exists( $wpdb, 'insert' ) ) {
			$wpdb->insert(
				$table,
				array(
					'user_id'         => $user_id > 0 ? $user_id : null,
					'endpoint'        => $endpoint,
					'p256dh'          => $p256dh,
					'auth'            => $auth,
					'user_agent'      => $user_agent,
					'locale'          => $locale,
					'created_at'      => $now,
					'last_seen_at'    => $now,
					'unsubscribed_at' => null,
				),
				array( '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
			);
			$insert_id = isset( $wpdb->insert_id ) ? (int) $wpdb->insert_id : 0;
			return $insert_id;
		}
		return 0;
	}
}

if ( ! function_exists( 'lafka_push_delete_subscription' ) ) {
	/**
	 * Hard-delete a subscription row by endpoint.
	 *
	 * Used when the push service returns 410 Gone — the subscription is
	 * permanently invalid and there's no point keeping the row.
	 *
	 * @param string $endpoint
	 * @return int Rows deleted (0 or 1).
	 */
	function lafka_push_delete_subscription( string $endpoint ): int {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'delete' ) ) {
			return 0;
		}
		if ( '' === $endpoint ) {
			return 0;
		}
		$deleted = $wpdb->delete(
			lafka_push_table_name(),
			array( 'endpoint' => $endpoint ),
			array( '%s' )
		);
		return is_numeric( $deleted ) ? (int) $deleted : 0;
	}
}

if ( ! function_exists( 'lafka_push_mark_unsubscribed' ) ) {
	/**
	 * Soft-delete a subscription row by endpoint — stamps `unsubscribed_at`.
	 *
	 * Used by the customer-facing unsubscribe REST route. Soft delete (vs hard
	 * delete) preserves the row for analytics + lets the customer re-subscribe
	 * without losing their original created_at attribution.
	 *
	 * @param string $endpoint
	 * @return int Rows updated (0 or 1).
	 */
	function lafka_push_mark_unsubscribed( string $endpoint ): int {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'update' ) ) {
			return 0;
		}
		if ( '' === $endpoint ) {
			return 0;
		}
		$now     = function_exists( 'current_time' ) ? (string) current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
		$updated = $wpdb->update(
			lafka_push_table_name(),
			array( 'unsubscribed_at' => $now ),
			array( 'endpoint' => $endpoint ),
			array( '%s' ),
			array( '%s' )
		);
		return is_numeric( $updated ) ? (int) $updated : 0;
	}
}

if ( ! function_exists( 'lafka_push_get_active_subscriptions' ) ) {
	/**
	 * Fetch every active (not unsubscribed) subscription row.
	 *
	 * Optional `$user_ids` filter selects only rows for those WP user IDs —
	 * `null` returns all active rows including guests.
	 *
	 * @param array<int, int>|null $user_ids Filter; null = all subscribers.
	 * @param int                  $limit    Max rows to return (cap for batch sends).
	 * @return array<int, object>
	 */
	function lafka_push_get_active_subscriptions( $user_ids = null, int $limit = 1000 ): array {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_results' ) ) {
			return array();
		}
		$limit = max( 1, min( 10000, $limit ) );
		$table = lafka_push_table_name();

		if ( is_array( $user_ids ) && ! empty( $user_ids ) ) {
			$ids = array_values( array_unique( array_map( 'intval', $user_ids ) ) );
			$ids = array_filter(
				$ids,
				static function ( $id ) {
					return $id > 0;
				}
			);
			if ( empty( $ids ) ) {
				return array();
			}
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$args         = array_merge( $ids, array( $limit ) );
			$rows         = $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT * FROM {$table} WHERE unsubscribed_at IS NULL AND user_id IN ({$placeholders}) ORDER BY id ASC LIMIT %d",
					$args
				)
			);
			return is_array( $rows ) ? $rows : array();
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$table} WHERE unsubscribed_at IS NULL ORDER BY id ASC LIMIT %d",
				$limit
			)
		);
		return is_array( $rows ) ? $rows : array();
	}
}

if ( ! function_exists( 'lafka_push_get_subscription_by_endpoint' ) ) {
	/**
	 * Look up a row by its endpoint.
	 *
	 * @param string $endpoint
	 * @return object|null
	 */
	function lafka_push_get_subscription_by_endpoint( string $endpoint ) {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_row' ) ) {
			return null;
		}
		if ( '' === $endpoint ) {
			return null;
		}
		$table = lafka_push_table_name();
		$row   = $wpdb->get_row(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$table} WHERE endpoint = %s LIMIT 1",
				$endpoint
			)
		);
		return $row ?: null;
	}
}

if ( ! function_exists( 'lafka_push_cleanup' ) ) {
	/**
	 * Delete subscription rows that have been soft-deleted (unsubscribed) for
	 * longer than $days_old.
	 *
	 * Only rows whose `unsubscribed_at` is set AND older than the window are
	 * pruned. Active rows are NEVER deleted on `last_seen_at` age alone: a
	 * deliverable subscriber who keeps receiving pushes but never re-runs the
	 * browser subscribe flow would otherwise be silently hard-deleted, quietly
	 * shrinking the deliverable audience. `last_seen_at` is kept fresh by
	 * lafka_push_send() on every successful (2xx) delivery — the documented
	 * "or send" half of the heartbeat — so it is a deliverability signal, not a
	 * delete trigger. Pruning on `last_seen_at` is reserved for a future signal
	 * (rows that have also accumulated repeated 4xx/5xx send failures), which
	 * needs a failure-count column the schema does not yet carry.
	 *
	 * Defaults to 60 days — matches industry practice for "stale subscription"
	 * pruning. Run daily by cron.
	 *
	 * @param int $days_old
	 * @return int Number of rows deleted.
	 */
	function lafka_push_cleanup( int $days_old = 60 ): int {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'query' ) ) {
			return 0;
		}
		$days_old = max( 1, $days_old );
		$table    = lafka_push_table_name();
		$result   = $wpdb->query(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"DELETE FROM {$table} WHERE unsubscribed_at IS NOT NULL AND unsubscribed_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
				$days_old
			)
		);
		return is_numeric( $result ) ? (int) $result : 0;
	}
}

// Self-heal: if the table is missing on a stale deploy, install on plugins_loaded.
if ( function_exists( 'add_action' ) ) {
	add_action( 'plugins_loaded', 'lafka_push_maybe_install_table', 20 );
}
