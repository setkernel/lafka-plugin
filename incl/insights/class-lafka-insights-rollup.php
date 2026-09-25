<?php
/**
 * Lafka_Insights_Rollup — sessions → daily aggregate rows, plus the nightly job.
 *
 * rollup_rows() is pure: it turns one day's session rows into counters
 *
 *   funnel      dim = stage slug      sessions that reached the stage
 *   abandon     dim = last reason     sessions that reached the cart (or
 *                                     later) without an order, by the last
 *                                     checkout-refusal reason ('none' if none)
 *   device      dim = mobile|tablet|desktop|unknown
 *   source      dim = source type     (utm / organic / referral / typein / unknown)
 *   source_order dim = source type    visits of that source that ordered (so
 *                                     orders ≤ visits per source, by construction)
 *   source_name dim = source          (google.com, (direct), newsletter …)
 *   campaign    dim = utm_campaign
 *   landing     dim = landing page type
 *   hour_dow    dim = "dow-hour"      (0 = Sunday)
 *   closed_hour_dow  same, closed-visit sessions only
 *
 * which run_nightly() writes with value = VALUES(value) (idempotent: a re-run
 * replaces, never double-counts). The nightly job (Action Scheduler, 03:10
 * site time) rolls every finished day not yet rolled, prunes (sessions 35
 * days, counters 25 months), rotates the visit-id secret, and re-schedules
 * itself for the next night.
 *
 * @package Lafka\Plugin\Insights
 * @since   10.2.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Lafka_Insights_Rollup' ) ) {

	final class Lafka_Insights_Rollup {

		/** Last day (Y-m-d) whose sessions were rolled up. */
		const ROLLED_OPTION = 'lafka_insights_rolled_through';

		/**
		 * Rollup schema version. Bump when rollup_rows() gains a metric: the next
		 * catch-up then re-rolls every retained day (idempotent replace).
		 */
		const VERSION        = 2;
		const VERSION_OPTION = 'lafka_insights_rollup_version';

		/** Session retention (days) and counter retention (days ≈ 25 months). */
		const SESSION_DAYS = 35;
		const COUNTER_DAYS = 762;

		/**
		 * Rollup metrics written by this class (vs live counters).
		 *
		 * @return array<int,string>
		 */
		public static function metrics(): array {
			return array( 'funnel', 'abandon', 'device', 'source', 'source_order', 'source_name', 'campaign', 'landing', 'hour_dow', 'closed_hour_dow' );
		}

		/**
		 * One day's session rows → counters. Pure.
		 *
		 * @param array<int,array<string,mixed>> $rows Session rows.
		 * @return array<string,array<string,int>>
		 */
		public static function rollup_rows( array $rows ): array {
			$out     = array();
			$devices = array(
				0 => 'unknown',
				1 => 'mobile',
				2 => 'tablet',
				3 => 'desktop',
			);
			$bump    = static function ( string $metric, string $dim ) use ( &$out ): void {
				$out[ $metric ][ $dim ] = ( $out[ $metric ][ $dim ] ?? 0 ) + 1;
			};

			$funnel = Lafka_Insights_DB::funnel_stages();
			foreach ( $rows as $row ) {
				$stages = (int) ( $row['stages'] ?? 0 );
				// Funnel counts are cumulative: a visit that reached checkout also
				// counts for every earlier step, even one it skipped (quick-add
				// from the menu never opens a product page).
				$furthest = -1;
				foreach ( array_values( $funnel ) as $i => $bit ) {
					if ( $stages & $bit ) {
						$furthest = $i;
					}
				}
				foreach ( array_keys( $funnel ) as $i => $slug ) {
					if ( $i <= $furthest ) {
						$bump( 'funnel', $slug );
					}
				}
				if ( $stages & Lafka_Insights_DB::STAGE_PAY_FAILED ) {
					$bump( 'funnel', 'pay_failed' );
				}
				if ( $stages & Lafka_Insights_DB::STAGE_CLOSED ) {
					$bump( 'funnel', 'closed' );
				}

				$reached_cart = (bool) ( $stages & ( Lafka_Insights_DB::STAGE_CART | Lafka_Insights_DB::STAGE_CHECKOUT | Lafka_Insights_DB::STAGE_PAY_ATTEMPT ) );
				if ( $reached_cart && ! ( $stages & Lafka_Insights_DB::STAGE_ORDER ) ) {
					$reason = (string) ( $row['last_block'] ?? '' );
					$bump( 'abandon', '' !== $reason ? $reason : 'none' );
				}

				$bump( 'device', $devices[ (int) ( $row['device'] ?? 0 ) ] ?? 'unknown' );
				$type = (string) ( $row['source_type'] ?? '' );
				$type = '' !== $type ? $type : 'unknown';
				$bump( 'source', $type );
				if ( $stages & Lafka_Insights_DB::STAGE_ORDER ) {
					$bump( 'source_order', $type );
				}
				if ( '' !== (string) ( $row['source'] ?? '' ) ) {
					$bump( 'source_name', (string) $row['source'] );
				}
				if ( '' !== (string) ( $row['campaign'] ?? '' ) ) {
					$bump( 'campaign', (string) $row['campaign'] );
				}
				if ( '' !== (string) ( $row['landing'] ?? '' ) ) {
					$bump( 'landing', (string) $row['landing'] );
				}
				$slot = (int) ( $row['dow'] ?? 0 ) . '-' . (int) ( $row['hour'] ?? 0 );
				$bump( 'hour_dow', $slot );
				if ( $stages & Lafka_Insights_DB::STAGE_CLOSED ) {
					$bump( 'closed_hour_dow', $slot );
				}
			}
			return $out;
		}

		/**
		 * Roll up one day (idempotent).
		 *
		 * @param string $day Y-m-d.
		 * @return void
		 */
		public static function roll_day( string $day ): void {
			$counters = self::rollup_rows( Lafka_Insights_DB::sessions_for_day( $day ) );
			if ( $counters ) {
				Lafka_Insights_DB::add_counters( $day, $counters, true );
			}
		}

		/**
		 * Roll every finished day that has not been rolled yet (bounded by the
		 * session retention). Returns the days rolled.
		 *
		 * @param string|null $today Y-m-d (default: today, site time).
		 * @return array<int,string>
		 */
		public static function catch_up( ?string $today = null ): array {
			$today     = null === $today ? Lafka_Insights_Session::today() : $today;
			$yesterday = gmdate( 'Y-m-d', strtotime( $today . ' 00:00:00 UTC' ) - 86400 );
			$last      = (string) get_option( self::ROLLED_OPTION, '' );
			if ( self::VERSION !== (int) get_option( self::VERSION_OPTION, 1 ) ) {
				$last = ''; // New rollup metrics: re-roll every retained day once.
				update_option( self::VERSION_OPTION, self::VERSION, false );
			}
			$floor     = gmdate( 'Y-m-d', strtotime( $today . ' 00:00:00 UTC' ) - self::SESSION_DAYS * 86400 );
			$start     = ( '' !== $last && $last >= $floor ) ? gmdate( 'Y-m-d', strtotime( $last . ' 00:00:00 UTC' ) + 86400 ) : $floor;

			$rolled = array();
			for ( $day = $start; $day <= $yesterday; $day = gmdate( 'Y-m-d', strtotime( $day . ' 00:00:00 UTC' ) + 86400 ) ) {
				self::roll_day( $day );
				$rolled[] = $day;
			}
			if ( $rolled ) {
				update_option( self::ROLLED_OPTION, $yesterday, false );
			}
			return $rolled;
		}

		/**
		 * The nightly job: schedule tomorrow first (a failure never ends the
		 * chain), then roll up, prune, rotate the secret, and drop the report cache.
		 *
		 * @return void
		 */
		public static function run_nightly(): void {
			if ( ! Lafka_Insights::is_enabled() ) {
				return;
			}
			Lafka_Insights_Scheduler::schedule_nightly();
			$today = Lafka_Insights_Session::today();
			self::catch_up( $today );
			Lafka_Insights_DB::prune( $today, self::SESSION_DAYS, self::COUNTER_DAYS );
			Lafka_Insights_Session::rotate();
			Lafka_Insights_Queries::flush_cache();
		}
	}
}
