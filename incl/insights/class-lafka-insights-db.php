<?php
/**
 * Lafka_Insights_DB — the two Insights tables (upserts, reads; the schema and
 * its upgrade live in Lafka_Schema).
 *
 *   {prefix}lafka_insights_sessions — one row per visit per local day (35-day
 *     retention): furthest funnel stages (bitmask), checkout-refusal reasons
 *     (bitmask + last reason), device class, source type / source / medium /
 *     campaign, landing page type, local hour + weekday, page-view count.
 *     PK (day, sid) where sid is the daily-rotating pseudonym — no IP, UA,
 *     email or cookie id is ever stored.
 *
 *   {prefix}lafka_insights_daily — aggregate counters (day, metric, dim, value),
 *     upserted with INSERT … ON DUPLICATE KEY UPDATE, one prepared statement
 *     per (metric, dim) counter.
 *
 * @package Lafka\Plugin\Insights
 * @since   10.2.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Lafka_Insights_DB' ) ) {

	final class Lafka_Insights_DB {

		/** Funnel stage bits (sessions.stages). */
		const STAGE_VISIT       = 1;
		const STAGE_MENU        = 2;
		const STAGE_PRODUCT     = 4;
		const STAGE_ADD         = 8;
		const STAGE_CART        = 16;
		const STAGE_CHECKOUT    = 32;
		const STAGE_PAY_ATTEMPT = 64;
		const STAGE_ORDER       = 128;
		const STAGE_PAY_FAILED  = 256;
		const STAGE_CLOSED      = 512;

		/**
		 * Funnel stages in order, slug => bit (closed / pay_failed are side flags).
		 *
		 * @return array<string,int>
		 */
		public static function stages(): array {
			return array(
				'visit'       => self::STAGE_VISIT,
				'menu'        => self::STAGE_MENU,
				'product'     => self::STAGE_PRODUCT,
				'add'         => self::STAGE_ADD,
				'cart'        => self::STAGE_CART,
				'checkout'    => self::STAGE_CHECKOUT,
				'pay_attempt' => self::STAGE_PAY_ATTEMPT,
				'order'       => self::STAGE_ORDER,
				'pay_failed'  => self::STAGE_PAY_FAILED,
				'closed'      => self::STAGE_CLOSED,
			);
		}

		/**
		 * The ordered funnel steps only (visit … order), slug => bit.
		 *
		 * @return array<string,int>
		 */
		public static function funnel_stages(): array {
			$all = self::stages();
			unset( $all['pay_failed'], $all['closed'] );
			return $all;
		}

		/**
		 * Sessions table name.
		 *
		 * @return string
		 */
		public static function sessions_table_name(): string {
			return Lafka_Schema::table_name( 'insights' );
		}

		/**
		 * Daily counters table name.
		 *
		 * @return string
		 */
		public static function daily_table_name(): string {
			return Lafka_Schema::table_name( 'insights', 'lafka_insights_daily' );
		}

		/**
		 * Create / migrate both tables (see Lafka_Schema).
		 *
		 * @return void
		 */
		public static function install(): void {
			Lafka_Schema::install( 'insights' );
		}

		/**
		 * Upsert a visit row. Bits are OR-ed in; descriptive fields are
		 * first-non-empty-wins (a server event may open the row before the page
		 * beacon reports where the visit came from).
		 *
		 * Assignment order matters: MySQL evaluates ON DUPLICATE KEY UPDATE
		 * assignments left to right against the already-updated row, so the
		 * source columns are updated before source_type, which gates them.
		 *
		 * @param string              $day Y-m-d.
		 * @param string              $sid 32 hex chars.
		 * @param array<string,mixed> $row stages, block_mask, last_block, device, source_type,
		 *                                 source, medium, campaign, landing, hour, dow, pageviews.
		 * @return bool
		 */
		public static function upsert_session( string $day, string $sid, array $row ): bool {
			global $wpdb;
			if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || 1 !== preg_match( '/^[a-f0-9]{32}$/', $sid ) ) {
				return false;
			}
			$result = $wpdb->query(
				$wpdb->prepare(
					'INSERT INTO %i (day,sid,stages,block_mask,last_block,device,source_type,source,medium,campaign,landing,hour,dow,pageviews)
					VALUES (%s,UNHEX(%s),%d,%d,%s,%d,%s,%s,%s,%s,%s,%d,%d,%d)
					ON DUPLICATE KEY UPDATE
					stages = stages | VALUES(stages),
					block_mask = block_mask | VALUES(block_mask),
					last_block = IF(VALUES(last_block) = \'\', last_block, VALUES(last_block)),
					device = IF(device = 0, VALUES(device), device),
					source = IF(source_type = \'\', VALUES(source), source),
					medium = IF(source_type = \'\', VALUES(medium), medium),
					campaign = IF(source_type = \'\', VALUES(campaign), campaign),
					source_type = IF(source_type = \'\', VALUES(source_type), source_type),
					landing = IF(landing = \'\', VALUES(landing), landing),
					pageviews = pageviews + VALUES(pageviews)',
					self::sessions_table_name(),
					$day,
					$sid,
					(int) ( $row['stages'] ?? 0 ),
					(int) ( $row['block_mask'] ?? 0 ),
					substr( (string) ( $row['last_block'] ?? '' ), 0, 32 ),
					(int) ( $row['device'] ?? 0 ),
					substr( (string) ( $row['source_type'] ?? '' ), 0, 16 ),
					substr( (string) ( $row['source'] ?? '' ), 0, 64 ),
					substr( (string) ( $row['medium'] ?? '' ), 0, 32 ),
					substr( (string) ( $row['campaign'] ?? '' ), 0, 64 ),
					substr( (string) ( $row['landing'] ?? '' ), 0, 16 ),
					(int) ( $row['hour'] ?? 0 ),
					(int) ( $row['dow'] ?? 0 ),
					(int) ( $row['pageviews'] ?? 0 )
				)
			);
			return false !== $result;
		}

		/**
		 * Add to counters (one prepared upsert per metric/dim pair).
		 *
		 * @param string                          $day      Y-m-d.
		 * @param array<string,array<string,int>> $counters metric => [ dim => increment ].
		 * @param bool                            $replace  true = set the value (idempotent rollup), false = add.
		 * @return bool
		 */
		public static function add_counters( string $day, array $counters, bool $replace = false ): bool {
			global $wpdb;
			if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
				return false;
			}
			$table = self::daily_table_name();
			$ok    = true;
			foreach ( $counters as $metric => $dims ) {
				$metric = substr( (string) $metric, 0, 24 );
				if ( '' === $metric || ! is_array( $dims ) ) {
					continue;
				}
				foreach ( $dims as $dim => $value ) {
					$value = (int) $value;
					if ( $value <= 0 && ! $replace ) {
						continue;
					}
					$dim   = substr( (string) $dim, 0, 100 );
					$value = max( 0, $value );
					if ( $replace ) {
						$result = $wpdb->query(
							$wpdb->prepare(
								'INSERT INTO %i (day,metric,dim,value) VALUES (%s,%s,%s,%d) ON DUPLICATE KEY UPDATE value = VALUES(value)',
								$table,
								$day,
								$metric,
								$dim,
								$value
							)
						);
					} else {
						$result = $wpdb->query(
							$wpdb->prepare(
								'INSERT INTO %i (day,metric,dim,value) VALUES (%s,%s,%s,%d) ON DUPLICATE KEY UPDATE value = value + VALUES(value)',
								$table,
								$day,
								$metric,
								$dim,
								$value
							)
						);
					}
					if ( false === $result ) {
						$ok = false;
					}
				}
			}
			return $ok;
		}

		/**
		 * One read for the beacon caps: this visit's page-view count today and
		 * the site-wide beacon count this hour.
		 *
		 * @param string $day  Y-m-d.
		 * @param string $sid  32 hex chars.
		 * @param string $hour Two-digit local hour.
		 * @return array{0:int,1:int} [ visit pageviews, beacons this hour ].
		 */
		public static function beacon_counts( string $day, string $sid, string $hour ): array {
			global $wpdb;
			if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
				return array( 0, 0 );
			}
			$row = $wpdb->get_row(
				$wpdb->prepare(
					"SELECT (SELECT pageviews FROM %i WHERE day = %s AND sid = UNHEX(%s)) AS pv, (SELECT value FROM %i WHERE day = %s AND metric = 'beacons' AND dim = %s) AS g",
					self::sessions_table_name(),
					$day,
					$sid,
					self::daily_table_name(),
					$day,
					$hour
				),
				ARRAY_A
			);
			return array( (int) ( $row['pv'] ?? 0 ), (int) ( $row['g'] ?? 0 ) );
		}

		/**
		 * The earliest day any Insights data exists for ('' when none).
		 *
		 * @return string Y-m-d or ''.
		 */
		public static function first_day(): string {
			global $wpdb;
			if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
				return '';
			}
			$day = $wpdb->get_var(
				$wpdb->prepare(
					'SELECT LEAST( COALESCE( (SELECT MIN(day) FROM %i), %s ), COALESCE( (SELECT MIN(day) FROM %i), %s ) )',
					self::sessions_table_name(),
					'9999-12-31',
					self::daily_table_name(),
					'9999-12-31'
				)
			);
			$day = is_string( $day ) ? substr( $day, 0, 10 ) : '';
			return ( 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $day ) && '9999-12-31' !== $day ) ? $day : '';
		}

		/**
		 * Session rows for one day (the rollup input).
		 *
		 * @param string $day Y-m-d.
		 * @return array<int,array<string,mixed>>
		 */
		public static function sessions_for_day( string $day ): array {
			global $wpdb;
			if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
				return array();
			}
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					'SELECT stages, block_mask, last_block, device, source_type, source, medium, campaign, landing, hour, dow, pageviews FROM %i WHERE day = %s',
					self::sessions_table_name(),
					$day
				),
				ARRAY_A
			);
			return is_array( $rows ) ? $rows : array();
		}

		/**
		 * Counter rows for a date range, optionally limited to some metrics.
		 *
		 * @param string            $from    Y-m-d (inclusive).
		 * @param string            $to      Y-m-d (inclusive).
		 * @param array<int,string> $metrics Metric names ([] = all).
		 * @return array<int,array<string,mixed>> rows of metric, dim, value (summed over days).
		 */
		public static function counters( string $from, string $to, array $metrics = array() ): array {
			global $wpdb;
			if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
				return array();
			}
			$table = self::daily_table_name();
			if ( empty( $metrics ) ) {
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						'SELECT metric, dim, SUM(value) AS value FROM %i WHERE day BETWEEN %s AND %s GROUP BY metric, dim',
						$table,
						$from,
						$to
					),
					ARRAY_A
				);
				return is_array( $rows ) ? $rows : array();
			}
			// One grouped read per metric: (metric, dim) groups never span metrics, so the union equals the single IN() query.
			$out = array();
			foreach ( array_unique( array_map( 'strval', array_values( $metrics ) ) ) as $metric ) {
				$rows = $wpdb->get_results(
					$wpdb->prepare(
						'SELECT metric, dim, SUM(value) AS value FROM %i WHERE day BETWEEN %s AND %s AND metric = %s GROUP BY metric, dim',
						$table,
						$from,
						$to,
						$metric
					),
					ARRAY_A
				);
				if ( is_array( $rows ) ) {
					$out = array_merge( $out, $rows );
				}
			}
			return $out;
		}

		/**
		 * Delete session rows older than $keep_days and counters older than
		 * $counter_days (retention).
		 *
		 * @param string $today        Y-m-d.
		 * @param int    $keep_days    Session retention in days.
		 * @param int    $counter_days Counter retention in days.
		 * @return void
		 */
		public static function prune( string $today, int $keep_days = 35, int $counter_days = 762 ): void {
			global $wpdb;
			if ( ! isset( $wpdb ) || ! is_object( $wpdb ) ) {
				return;
			}
			$base = strtotime( $today . ' 00:00:00 UTC' );
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE day < %s', self::sessions_table_name(), gmdate( 'Y-m-d', $base - $keep_days * 86400 ) ) );
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE day < %s', self::daily_table_name(), gmdate( 'Y-m-d', $base - $counter_days * 86400 ) ) );
		}
	}
}
