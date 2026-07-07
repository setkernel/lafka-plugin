<?php
/**
 * Phase 3E (v9.29.0): Web Push - reorder reminder cron.
 *
 * Daily WP-Cron event `lafka_push_reorder_reminder` that:
 *
 *   1. Looks up users whose most recent completed WC order was placed
 *      exactly N days ago (operator-configurable via Customizer; default 14).
 *   2. For each match, finds the user's active push subscriptions and sends
 *      "Your usual? Tap to reorder" with a deep-link to /menu/.
 *
 * Self-gates on the `lafka_push_enabled` master toggle AND the
 * `lafka_push_reorder_reminder_enabled` channel toggle. Operator can opt out
 * per-user by setting user_meta `_lafka_push_reorder_opt_out` to '1'.
 *
 * Idempotent: a per-user transient `_lafka_push_reorder_sent_{user_id}_{ymd}`
 * with a 7-day TTL prevents double-sends if the cron fires twice in the same
 * day (rare but possible on busy hosts).
 *
 * @package Lafka\Plugin\Conversion
 * @since   9.29.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'lafka_push_reorder_is_enabled' ) ) {
	/**
	 * Both the master toggle and the channel toggle must be ON.
	 */
	function lafka_push_reorder_is_enabled(): bool {
		if ( ! function_exists( 'get_theme_mod' ) ) {
			return false;
		}
		if ( '1' !== (string) get_theme_mod( 'lafka_push_enabled', '0' ) ) {
			return false;
		}
		if ( '1' !== (string) get_theme_mod( 'lafka_push_reorder_reminder_enabled', '0' ) ) {
			return false;
		}
		return true;
	}
}

if ( ! function_exists( 'lafka_push_reorder_get_days' ) ) {
	/**
	 * Days since the user's last completed order before the reminder fires.
	 * Clamped to [3, 90].
	 */
	function lafka_push_reorder_get_days(): int {
		$raw = 14;
		if ( function_exists( 'get_theme_mod' ) ) {
			$raw = (int) get_theme_mod( 'lafka_push_reorder_reminder_days', 14 );
		}
		return max( 3, min( 90, $raw ) );
	}
}

if ( ! function_exists( 'lafka_push_reorder_schedule_event' ) ) {
	/**
	 * Register the daily cron event. Idempotent.
	 */
	function lafka_push_reorder_schedule_event(): void {
		if ( ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_schedule_event' ) ) {
			return;
		}
		if ( function_exists( 'lafka_push_rest_is_enabled' ) && ! lafka_push_rest_is_enabled() ) {
			// Default-OFF module must stay fully inert: no cron for sites that
			// never opted in, and drop events left behind by a former opt-in.
			if ( function_exists( 'lafka_push_reorder_unschedule_event' ) && wp_next_scheduled( 'lafka_push_reorder_reminder' ) ) {
				lafka_push_reorder_unschedule_event();
			}
			return;
		}
		if ( ! wp_next_scheduled( 'lafka_push_reorder_reminder' ) ) {
			wp_schedule_event( time() + 3600, 'daily', 'lafka_push_reorder_reminder' );
		}
		// Cleanup cron runs daily too.
		if ( ! wp_next_scheduled( 'lafka_push_cleanup_subscriptions' ) ) {
			wp_schedule_event( time() + 7200, 'daily', 'lafka_push_cleanup_subscriptions' );
		}
	}
}

if ( ! function_exists( 'lafka_push_reorder_unschedule_event' ) ) {
	/**
	 * Drop both cron events. Plugin-deactivation hook calls this.
	 */
	function lafka_push_reorder_unschedule_event(): void {
		if ( ! function_exists( 'wp_clear_scheduled_hook' ) ) {
			return;
		}
		wp_clear_scheduled_hook( 'lafka_push_reorder_reminder' );
		wp_clear_scheduled_hook( 'lafka_push_cleanup_subscriptions' );
	}
}

if ( ! function_exists( 'lafka_push_reorder_find_eligible_users' ) ) {
	/**
	 * Find users whose most-recent completed order was placed `$days_ago` days
	 * ago (+/- 1 day window so the cron has a tolerance buffer).
	 *
	 * @param int $days_ago
	 * @return array<int,int> User IDs.
	 */
	function lafka_push_reorder_find_eligible_users( int $days_ago ): array {
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return array();
		}
		$start = strtotime( '-' . ( $days_ago + 1 ) . ' days' );
		$end   = strtotime( '-' . max( 1, $days_ago - 1 ) . ' days' );
		if ( false === $start || false === $end ) {
			return array();
		}
		$orders = wc_get_orders(
			array(
				'limit'        => 1000,
				'date_created' => $start . '...' . $end,
				'status'       => array( 'completed' ),
				'return'       => 'ids',
			)
		);
		if ( ! is_array( $orders ) ) {
			return array();
		}

		$user_ids = array();
		foreach ( $orders as $order_id ) {
			if ( ! function_exists( 'wc_get_order' ) ) {
				continue;
			}
			$order = wc_get_order( (int) $order_id );
			if ( ! is_object( $order ) || ! method_exists( $order, 'get_customer_id' ) ) {
				continue;
			}
			$uid = (int) $order->get_customer_id();
			if ( $uid <= 0 ) {
				continue;
			}
			// Skip users who have opted out of reorder reminders.
			if ( function_exists( 'get_user_meta' ) ) {
				$opt_out = (string) get_user_meta( $uid, '_lafka_push_reorder_opt_out', true );
				if ( '1' === $opt_out ) {
					continue;
				}
			}
			$user_ids[ $uid ] = $uid;
		}
		return array_values( $user_ids );
	}
}

if ( ! function_exists( 'lafka_push_reorder_build_payload' ) ) {
	/**
	 * Build the notification payload for the reorder reminder.
	 */
	function lafka_push_reorder_build_payload(): array {
		$site_name = function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'name' ) : '';
		$title     = function_exists( '__' ) ? __( 'Your usual?', 'lafka-plugin' ) : 'Your usual?';
		$body      = function_exists( '__' ) ? __( 'Tap to reorder from your favourites.', 'lafka-plugin' ) : 'Tap to reorder from your favourites.';
		$url       = lafka_get_menu_url();
		$icon      = function_exists( 'get_site_icon_url' ) ? (string) get_site_icon_url() : '';
		if ( '' === $icon && function_exists( 'home_url' ) ) {
			$icon = home_url( '/favicon.ico' );
		}
		$payload = array(
			'title' => $title,
			'body'  => $body,
			'icon'  => $icon,
			'badge' => $icon,
			'url'   => $url,
		);
		if ( '' !== $site_name ) {
			$payload['tag'] = 'lafka-reorder-' . md5( $site_name );
		}
		if ( function_exists( 'apply_filters' ) ) {
			$payload = (array) apply_filters( 'lafka_push_reorder_payload', $payload );
		}
		return $payload;
	}
}

if ( ! function_exists( 'lafka_push_reorder_run' ) ) {
	/**
	 * Cron handler for `lafka_push_reorder_reminder`. Daily.
	 */
	function lafka_push_reorder_run(): void {
		if ( ! lafka_push_reorder_is_enabled() ) {
			return;
		}
		$days  = lafka_push_reorder_get_days();
		$users = lafka_push_reorder_find_eligible_users( $days );
		if ( empty( $users ) ) {
			return;
		}
		$payload = lafka_push_reorder_build_payload();
		$ymd     = gmdate( 'Ymd' );

		$sent_count = 0;
		foreach ( $users as $uid ) {
			$transient_key = '_lafka_push_reorder_sent_' . $uid . '_' . $ymd;
			if ( function_exists( 'get_transient' ) && false !== get_transient( $transient_key ) ) {
				continue;
			}
			$rows = lafka_push_get_active_subscriptions( array( $uid ), 50 );
			if ( empty( $rows ) ) {
				continue;
			}
			foreach ( $rows as $row ) {
				$res = lafka_push_send( $row, $payload );
				if ( ! empty( $res['ok'] ) ) {
					++$sent_count;
				}
			}
			if ( function_exists( 'set_transient' ) ) {
				set_transient( $transient_key, 1, 7 * DAY_IN_SECONDS );
			}
		}
		if ( $sent_count > 0 ) {
			lafka_push_record_activity(
				array(
					'timestamp' => time(),
					'audience'  => 'reorder_reminder',
					'title'     => isset( $payload['title'] ) ? (string) $payload['title'] : '',
					'sent'      => $sent_count,
					'failed'    => 0,
					'size'      => count( $users ),
				)
			);
		}
	}
}

if ( ! function_exists( 'lafka_push_cleanup_run' ) ) {
	/**
	 * Cron handler for `lafka_push_cleanup_subscriptions`. Daily.
	 */
	function lafka_push_cleanup_run(): void {
		$days = 60;
		if ( function_exists( 'apply_filters' ) ) {
			$days = (int) apply_filters( 'lafka_push_cleanup_days', 60 );
		}
		lafka_push_cleanup( max( 1, $days ) );
	}
}

if ( function_exists( 'add_action' ) ) {
	add_action( 'lafka_push_reorder_reminder', 'lafka_push_reorder_run' );
	add_action( 'lafka_push_cleanup_subscriptions', 'lafka_push_cleanup_run' );
	// Self-heal: re-register on every plugins_loaded so a missed activation
	// hook (e.g. WP-CLI deploy) still gets the schedule.
	add_action( 'plugins_loaded', 'lafka_push_reorder_schedule_event', 30 );
}
