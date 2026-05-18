<?php
/**
 * Phase 3B (v9.27.0): Abandoned-cart recovery — cron layer.
 *
 * Two WP-Cron events:
 *
 *   lafka_check_abandoned_carts     every 15 minutes — scans for rows where
 *       last_seen_at < NOW() - delay_minutes AND recovery_sent_at IS NULL
 *       AND order_id = 0 AND email NOT IN (operator opt-out list). Calls the
 *       email class to send. Marks recovery_sent_at on success so the row is
 *       never picked up twice.
 *
 *   lafka_cleanup_abandoned_carts   daily — deletes rows whose `created_at` is
 *       older than 30 days. Privacy + table-size hygiene.
 *
 * Both events are self-healing: if the scheduler is empty on plugin load, the
 * registration hook re-registers them. The `every_fifteen_minutes` interval is
 * registered via `cron_schedules` filter — WP's own minimum is 1 minute, so 15
 * is well-supported.
 *
 * Self-gates on the `lafka_ac_enabled` Customizer toggle. The cron events stay
 * registered even when the toggle is off so the operator can flip it on without
 * needing to deactivate + reactivate the plugin.
 *
 * @package Lafka\Plugin\Conversion
 * @since   9.27.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'lafka_ac_register_cron_schedule' ) ) {
	/**
	 * Register the every_fifteen_minutes schedule for WP-Cron.
	 *
	 * Hooked on `cron_schedules` so the schedule is available before any of
	 * the `wp_next_scheduled` checks below.
	 *
	 * @param array $schedules
	 * @return array
	 */
	function lafka_ac_register_cron_schedule( $schedules ): array {
		$schedules = is_array( $schedules ) ? $schedules : array();
		if ( ! isset( $schedules['every_fifteen_minutes'] ) ) {
			$schedules['every_fifteen_minutes'] = array(
				'interval' => 15 * 60,
				'display'  => function_exists( '__' ) ? __( 'Every 15 minutes', 'lafka-plugin' ) : 'Every 15 minutes',
			);
		}
		return $schedules;
	}
}

if ( function_exists( 'add_filter' ) ) {
	add_filter( 'cron_schedules', 'lafka_ac_register_cron_schedule' );
}

if ( ! function_exists( 'lafka_ac_schedule_events' ) ) {
	/**
	 * Register the two cron events if they're not already on the schedule.
	 *
	 * Called at plugin activation AND on every `plugins_loaded` (idempotent —
	 * wp_next_scheduled() returns the timestamp of an existing event if any).
	 *
	 * @return void
	 */
	function lafka_ac_schedule_events(): void {
		if ( ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_schedule_event' ) ) {
			return;
		}
		if ( ! wp_next_scheduled( 'lafka_check_abandoned_carts' ) ) {
			wp_schedule_event( time() + 60, 'every_fifteen_minutes', 'lafka_check_abandoned_carts' );
		}
		if ( ! wp_next_scheduled( 'lafka_cleanup_abandoned_carts' ) ) {
			wp_schedule_event( time() + 3600, 'daily', 'lafka_cleanup_abandoned_carts' );
		}
	}
}

if ( ! function_exists( 'lafka_ac_unschedule_events' ) ) {
	/**
	 * Drop both cron events. Plugin-deactivation hook calls this.
	 *
	 * @return void
	 */
	function lafka_ac_unschedule_events(): void {
		if ( ! function_exists( 'wp_clear_scheduled_hook' ) ) {
			return;
		}
		wp_clear_scheduled_hook( 'lafka_check_abandoned_carts' );
		wp_clear_scheduled_hook( 'lafka_cleanup_abandoned_carts' );
	}
}

if ( ! function_exists( 'lafka_ac_get_delay_minutes' ) ) {
	/**
	 * Operator-configurable delay before a cart is considered "abandoned".
	 *
	 * @return int Minutes, clamped to [5, 1440] (5 min — 24 h).
	 */
	function lafka_ac_get_delay_minutes(): int {
		$raw = 75;
		if ( function_exists( 'get_theme_mod' ) ) {
			$raw = (int) get_theme_mod( 'lafka_ac_delay_minutes', 75 );
		}
		return max( 5, min( 1440, $raw ) );
	}
}

if ( ! function_exists( 'lafka_ac_get_opt_out_list' ) ) {
	/**
	 * Parse the operator's blocklist textarea into a lowercased email array.
	 *
	 * @return array<int, string>
	 */
	function lafka_ac_get_opt_out_list(): array {
		if ( ! function_exists( 'get_theme_mod' ) ) {
			return array();
		}
		$raw = (string) get_theme_mod( 'lafka_ac_global_opt_out', '' );
		if ( '' === trim( $raw ) ) {
			return array();
		}
		$lines = preg_split( '/[\s,]+/', $raw );
		if ( ! is_array( $lines ) ) {
			return array();
		}
		$out = array();
		foreach ( $lines as $line ) {
			$line = strtolower( trim( $line ) );
			if ( '' === $line ) {
				continue;
			}
			$out[] = $line;
		}
		return array_values( array_unique( $out ) );
	}
}

if ( ! function_exists( 'lafka_ac_is_opted_out' ) ) {
	/**
	 * Is the given email on the operator's blocklist?
	 *
	 * @param string $email
	 * @return bool
	 */
	function lafka_ac_is_opted_out( string $email ): bool {
		if ( '' === $email ) {
			return true;
		}
		return in_array( strtolower( $email ), lafka_ac_get_opt_out_list(), true );
	}
}

if ( ! function_exists( 'lafka_ac_row_is_eligible' ) ) {
	/**
	 * Defence-in-depth: even though `lafka_ac_get_pending` filters at the SQL
	 * layer, re-validate per row before sending. Protects against opt-out
	 * additions made between the SELECT and the send loop.
	 *
	 * @param object|array $row
	 * @return bool
	 */
	function lafka_ac_row_is_eligible( $row ): bool {
		if ( is_array( $row ) ) {
			$row = (object) $row;
		}
		if ( ! is_object( $row ) ) {
			return false;
		}
		if ( ! empty( $row->recovery_sent_at ) ) {
			return false;
		}
		if ( ! empty( $row->order_id ) && (int) $row->order_id > 0 ) {
			return false;
		}
		$email = isset( $row->customer_email ) ? (string) $row->customer_email : '';
		if ( '' === $email ) {
			return false;
		}
		if ( lafka_ac_is_opted_out( $email ) ) {
			return false;
		}
		return true;
	}
}

if ( ! function_exists( 'lafka_ac_run_check' ) ) {
	/**
	 * The `lafka_check_abandoned_carts` cron handler.
	 *
	 * Skipped when the Customizer toggle is off — we leave the event registered
	 * for cheap flip-on UX, but the body short-circuits.
	 *
	 * @return void
	 */
	function lafka_ac_run_check(): void {
		if ( ! lafka_ac_capture_is_enabled() ) {
			return;
		}
		$delay = lafka_ac_get_delay_minutes();
		$rows  = lafka_ac_get_pending( $delay, 50 );
		if ( empty( $rows ) ) {
			return;
		}

		foreach ( $rows as $row ) {
			if ( ! lafka_ac_row_is_eligible( $row ) ) {
				continue;
			}
			lafka_ac_dispatch_recovery_email( $row );
		}
	}
}

if ( ! function_exists( 'lafka_ac_dispatch_recovery_email' ) ) {
	/**
	 * Trigger the WC email class for this row, then flip recovery_sent_at.
	 *
	 * Marks the row as sent regardless of mailer return value — repeated send
	 * failures should NOT spam the customer. Operator can manually wipe
	 * recovery_sent_at via SQL if they want to retry.
	 *
	 * @param object $row
	 * @return void
	 */
	function lafka_ac_dispatch_recovery_email( $row ): void {
		if ( ! is_object( $row ) || empty( $row->id ) ) {
			return;
		}
		$row_id = (int) $row->id;

		if ( function_exists( 'WC' ) ) {
			$wc = WC();
			if ( is_object( $wc ) && method_exists( $wc, 'mailer' ) ) {
				$mailer = $wc->mailer();
				if ( is_object( $mailer ) ) {
					/**
					 * Fire the WC email class. The class is registered through
					 * the woocommerce_email_classes filter, so $mailer has it
					 * available as $mailer->emails['LAFKA_Abandoned_Cart_Email'].
					 */
					if ( function_exists( 'do_action' ) ) {
						do_action( 'lafka_abandoned_cart_email_trigger', $row );
					}
				}
			}
		}

		lafka_ac_mark_recovery_sent( $row_id );
	}
}

if ( ! function_exists( 'lafka_ac_run_cleanup' ) ) {
	/**
	 * The `lafka_cleanup_abandoned_carts` cron handler.
	 *
	 * Default retention: 30 days. Operator can override via
	 * `lafka_ac_cleanup_retention_days` filter.
	 *
	 * @return void
	 */
	function lafka_ac_run_cleanup(): void {
		$days = 30;
		if ( function_exists( 'apply_filters' ) ) {
			$days = (int) apply_filters( 'lafka_ac_cleanup_retention_days', 30 );
		}
		lafka_ac_cleanup( max( 1, $days ) );
	}
}

if ( function_exists( 'add_action' ) ) {
	add_action( 'lafka_check_abandoned_carts', 'lafka_ac_run_check' );
	add_action( 'lafka_cleanup_abandoned_carts', 'lafka_ac_run_cleanup' );
	// Self-heal — if either event got de-scheduled, re-register on plugins_loaded.
	add_action( 'plugins_loaded', 'lafka_ac_schedule_events', 30 );
}
