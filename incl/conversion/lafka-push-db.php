<?php
/**
 * Phase 3E (v9.29.0): Web Push notifications — DB layer.
 *
 * Owns the `wp_lafka_push_subscriptions` table (its schema lives in Lafka_Schema):
 *   - Helpers to save / delete / mark-unsubscribed / fetch active subscriptions
 *   - Helper to delete stale rows (60-day inactive cleanup)
 *
 * Idempotency: every row uses `endpoint` as the unique dedupe key — the Web
 * Push spec guarantees endpoint uniqueness per (UA, push service). A customer
 * who clears site data and re-subscribes gets a new endpoint and therefore a
 * new row; an already-subscribed customer re-running the subscribe flow on the
 * same browser hits the same endpoint and updates the row in place.
 *
 * The schema and its version are declared once in Lafka_Schema
 * (incl/tools/class-lafka-schema.php), which creates and upgrades the table.
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

if ( ! function_exists( 'lafka_push_table_name' ) ) {
	/**
	 * Return the fully-qualified table name (respects $wpdb->prefix).
	 *
	 * @return string
	 */
	function lafka_push_table_name(): string {
		return Lafka_Schema::table_name( 'push' );
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
					'SELECT id FROM %i WHERE endpoint = %s LIMIT 1',
					$table,
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

if ( ! function_exists( 'lafka_push_user_id_chunks' ) ) {
	/**
	 * Split a user-id list into the fixed-size groups the user_id IN (…)
	 * queries take (10 ids each, short groups padded with 0, which matches no
	 * user), so the user_id index serves them. Positive, unique ids only.
	 *
	 * @since 10.4.0
	 * @param array<int,mixed> $user_ids User ids.
	 * @return array<int,array<int,int>> Groups of exactly 10 ids.
	 */
	function lafka_push_user_id_chunks( array $user_ids ): array {
		$ids = array_values(
			array_unique(
				array_filter(
					array_map( 'intval', $user_ids ),
					static function ( $id ) {
						return $id > 0;
					}
				)
			)
		);
		$out = array();
		foreach ( array_chunk( $ids, 10 ) as $chunk ) {
			$out[] = array_pad( $chunk, 10, 0 );
		}
		return $out;
	}
}

if ( ! function_exists( 'lafka_push_get_active_subscriptions_for_users' ) ) {
	/**
	 * Active subscriptions of the given users with id greater than a cursor,
	 * ordered by id, at most $limit rows.
	 *
	 * @since 10.4.0
	 * @param array<int,mixed> $user_ids Non-empty user ids.
	 * @param int              $after_id Return rows with id greater than this.
	 * @param int              $limit    Max rows.
	 * @return array<int,object>
	 */
	function lafka_push_get_active_subscriptions_for_users( array $user_ids, int $after_id, int $limit ): array {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_results' ) ) {
			return array();
		}
		$table = lafka_push_table_name();
		$rows  = array();
		foreach ( lafka_push_user_id_chunks( $user_ids ) as $chunk ) {
			$found = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT * FROM %i WHERE unsubscribed_at IS NULL AND user_id IN ( %d,%d,%d,%d,%d,%d,%d,%d,%d,%d ) AND id > %d ORDER BY id ASC LIMIT %d',
					array( $table, $chunk[0], $chunk[1], $chunk[2], $chunk[3], $chunk[4], $chunk[5], $chunk[6], $chunk[7], $chunk[8], $chunk[9], max( 0, $after_id ), $limit )
				)
			);
			if ( is_array( $found ) ) {
				$rows = array_merge( $rows, $found );
			}
		}
		usort(
			$rows,
			static function ( $a, $b ) {
				return (int) $a->id <=> (int) $b->id;
			}
		);
		return array_slice( $rows, 0, $limit );
	}
}

if ( ! function_exists( 'lafka_push_count_active_subscriptions_for_users' ) ) {
	/**
	 * Number of active subscriptions belonging to the given users.
	 *
	 * @since 10.4.0
	 * @param array<int,mixed> $user_ids User ids.
	 * @return int
	 */
	function lafka_push_count_active_subscriptions_for_users( array $user_ids ): int {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) ) {
			return 0;
		}
		$table = lafka_push_table_name();
		$count = 0;
		foreach ( lafka_push_user_id_chunks( $user_ids ) as $chunk ) {
			$count += (int) $wpdb->get_var(
				$wpdb->prepare(
					'SELECT COUNT(*) FROM %i WHERE unsubscribed_at IS NULL AND user_id IN ( %d,%d,%d,%d,%d,%d,%d,%d,%d,%d )',
					array( $table, $chunk[0], $chunk[1], $chunk[2], $chunk[3], $chunk[4], $chunk[5], $chunk[6], $chunk[7], $chunk[8], $chunk[9] )
				)
			);
		}
		return $count;
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
			return lafka_push_get_active_subscriptions_for_users( $user_ids, 0, $limit );
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT * FROM %i WHERE unsubscribed_at IS NULL ORDER BY id ASC LIMIT %d',
				$table,
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
				'SELECT * FROM %i WHERE endpoint = %s LIMIT 1',
				$table,
				$endpoint
			)
		);
		return $row ? $row : null;
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
				'DELETE FROM %i WHERE unsubscribed_at IS NOT NULL AND unsubscribed_at < DATE_SUB(NOW(), INTERVAL %d DAY)',
				$table,
				$days_old
			)
		);
		return is_numeric( $result ) ? (int) $result : 0;
	}
}
