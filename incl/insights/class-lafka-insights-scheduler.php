<?php
/**
 * Lafka_Insights_Scheduler — the two Insights jobs on Action Scheduler
 * (bundled with WooCommerce), falling back to WP-Cron when it is absent.
 *
 *   lafka_insights_nightly       03:10 site time, every night
 *   lafka_insights_weekly_email  Monday 08:00 site time
 *
 * Each job is a single action at the next local occurrence that re-schedules
 * itself when it runs — so both stay pinned to local wall-clock time across
 * DST changes (a fixed 24 h recurrence would drift by an hour twice a year).
 * ensure_scheduled() (admin_init + on module enable) repairs a broken chain.
 *
 * @package Lafka\Plugin\Insights
 * @since   10.2.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Lafka_Insights_Scheduler' ) ) {

	final class Lafka_Insights_Scheduler {

		/**
		 * Unix timestamp of the next local occurrence of a wall-clock time.
		 *
		 * @param string   $time    "H:i" local.
		 * @param int|null $weekday ISO weekday 1 (Mon) – 7 (Sun), or null for daily.
		 * @param int|null $now     Unix now (tests).
		 * @param string   $tz      Timezone name ('' = site timezone).
		 * @return int
		 */
		public static function next_local( string $time, ?int $weekday = null, ?int $now = null, string $tz = '' ): int {
			$now  = null === $now ? time() : $now;
			$zone = '' !== $tz ? new DateTimeZone( $tz ) : ( function_exists( 'wp_timezone' ) ? wp_timezone() : new DateTimeZone( 'UTC' ) );
			$at   = ( new DateTimeImmutable( '@' . $now ) )->setTimezone( $zone );
			list( $h, $m ) = array_map( 'intval', explode( ':', $time ) );
			$candidate = $at->setTime( $h, $m, 0 );
			if ( null !== $weekday ) {
				$diff      = ( $weekday - (int) $candidate->format( 'N' ) + 7 ) % 7;
				$candidate = $candidate->modify( '+' . $diff . ' days' )->setTime( $h, $m, 0 );
			}
			if ( $candidate->getTimestamp() <= $now ) {
				$candidate = $candidate->modify( null === $weekday ? '+1 day' : '+7 days' )->setTime( $h, $m, 0 );
			}
			return $candidate->getTimestamp();
		}

		/**
		 * Schedule the next nightly run (no-op if one is already pending).
		 *
		 * @return void
		 */
		public static function schedule_nightly(): void {
			self::schedule_once( Lafka_Insights::NIGHTLY_HOOK, self::next_local( '03:10' ) );
		}

		/**
		 * Schedule the next Monday-morning email (no-op if already pending).
		 *
		 * @return void
		 */
		public static function schedule_weekly(): void {
			self::schedule_once( Lafka_Insights::WEEKLY_HOOK, self::next_local( '08:00', 1 ) );
		}

		/**
		 * Make sure both jobs are pending.
		 *
		 * @return void
		 */
		public static function ensure_scheduled(): void {
			self::schedule_nightly();
			self::schedule_weekly();
		}

		/**
		 * Drop both jobs (module disabled).
		 *
		 * @return void
		 */
		public static function unschedule_all(): void {
			foreach ( array( Lafka_Insights::NIGHTLY_HOOK, Lafka_Insights::WEEKLY_HOOK ) as $hook ) {
				if ( function_exists( 'as_unschedule_all_actions' ) ) {
					as_unschedule_all_actions( $hook, array(), Lafka_Insights::AS_GROUP );
				}
				if ( function_exists( 'wp_clear_scheduled_hook' ) ) {
					wp_clear_scheduled_hook( $hook );
				}
			}
		}

		/**
		 * @param string $hook Hook.
		 * @param int    $when Unix timestamp.
		 * @return void
		 */
		private static function schedule_once( string $hook, int $when ): void {
			if ( function_exists( 'as_schedule_single_action' ) && function_exists( 'as_get_scheduled_actions' ) ) {
				// Pending only: while the job itself runs, its own (running)
				// action must not count, or the chain would never continue.
				$pending = as_get_scheduled_actions(
					array(
						'hook'     => $hook,
						'group'    => Lafka_Insights::AS_GROUP,
						'status'   => 'pending',
						'per_page' => 1,
					),
					'ids'
				);
				if ( empty( $pending ) ) {
					as_schedule_single_action( $when, $hook, array(), Lafka_Insights::AS_GROUP );
				}
				return;
			}
			if ( function_exists( 'wp_next_scheduled' ) && ! wp_next_scheduled( $hook ) ) {
				wp_schedule_single_event( $when, $hook );
			}
		}
	}
}
