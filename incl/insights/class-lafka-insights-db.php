<?php
/**
 * Lafka_Insights_DB — the two Insights tables (dbDelta install / self-heal,
 * upserts, reads).
 *
 *   {prefix}lafka_insights_sessions — one row per visit per local day (35-day
 *     retention): furthest funnel stages (bitmask), checkout-refusal reasons
 *     (bitmask + last reason), device class, source type / source / medium /
 *     campaign, landing page type, local hour + weekday, page-view count.
 *     PK (day, sid) where sid is the daily-rotating pseudonym — no IP, UA,
 *     email or cookie id is ever stored.
 *
 *   {prefix}lafka_insights_daily — aggregate counters (day, metric, dim, value),
 *     upserted with a single multi-row INSERT … ON DUPLICATE KEY UPDATE.
 *
 * Every write is one statement, so the beacon endpoint stays within its
 * budget of two writes (one session upsert + one counter upsert).
 *
 * Schema version: option `lafka_insights_db_version`; maybe_install() re-runs
 * dbDelta when it drifts (same self-heal as the abandoned-cart table).
 *
 * @package Lafka\Plugin\Insights
 * @since   10.2.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Lafka_Insights_DB' ) ) {

	final class Lafka_Insights_DB {

		const VERSION        = '1.0.0';
		const VERSION_OPTION = 'lafka_insights_db_version';

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
			return self::prefix() . 'lafka_insights_sessions';
		}

		/**
		 * Daily counters table name.
		 *
		 * @return string
		 */
		public static function daily_table_name(): string {
			return self::prefix() . 'lafka_insights_daily';
		}

		/**
		 * The $wpdb prefix.
		 *
		 * @return string
		 */
		private static function prefix(): string {
			global $wpdb;
			return ( isset( $wpdb ) && is_object( $wpdb ) && isset( $wpdb->prefix ) ) ? (string) $wpdb->prefix : 'wp_';
		}

		/**
		 * dbDelta schema for both tables (one column per line, two spaces
		 * before PRIMARY KEY's column list — dbDelta is whitespace-sensitive).
		 *
		 * @return array<int,string>
		 */
		public static function schema_sql(): array {
			global $wpdb;
			$charset  = ( isset( $wpdb ) && is_object( $wpdb ) && method_exists( $wpdb, 'get_charset_collate' ) )
				? $wpdb->get_charset_collate()
				: 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
			$sessions = self::sessions_table_name();
			$daily    = self::daily_table_name();

			return array(
				"CREATE TABLE {$sessions} (
  day DATE NOT NULL,
  sid BINARY(16) NOT NULL,
  stages SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  block_mask INT UNSIGNED NOT NULL DEFAULT 0,
  last_block VARCHAR(32) NOT NULL DEFAULT '',
  device TINYINT UNSIGNED NOT NULL DEFAULT 0,
  source_type VARCHAR(16) NOT NULL DEFAULT '',
  source VARCHAR(64) NOT NULL DEFAULT '',
  medium VARCHAR(32) NOT NULL DEFAULT '',
  campaign VARCHAR(64) NOT NULL DEFAULT '',
  landing VARCHAR(16) NOT NULL DEFAULT '',
  hour TINYINT UNSIGNED NOT NULL DEFAULT 0,
  dow TINYINT UNSIGNED NOT NULL DEFAULT 0,
  pageviews SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY  (day,sid),
  KEY day_stages (day,stages)
) {$charset};",
				"CREATE TABLE {$daily} (
  day DATE NOT NULL,
  metric VARCHAR(24) NOT NULL,
  dim VARCHAR(100) NOT NULL DEFAULT '',
  value INT UNSIGNED NOT NULL DEFAULT 0,
  PRIMARY KEY  (day,metric,dim),
  KEY metric_day (metric,day)
) {$charset};",
			);
		}

		/**
		 * Create / migrate both tables and record the schema version.
		 *
		 * @return void
		 */
		public static function install(): void {
			if ( ! function_exists( 'dbDelta' ) && defined( 'ABSPATH' ) && file_exists( ABSPATH . 'wp-admin/includes/upgrade.php' ) ) {
				require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			}
			if ( function_exists( 'dbDelta' ) ) {
				dbDelta( self::schema_sql() );
			}
			update_option( self::VERSION_OPTION, self::VERSION );
		}

		/**
		 * Self-heal on plugins_loaded: (re)install when the stored version drifts.
		 *
		 * @return void
		 */
		public static function maybe_install(): void {
			if ( self::VERSION === (string) get_option( self::VERSION_OPTION, '' ) ) {
				return;
			}
			self::install();
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
			$table = self::sessions_table_name();
			$sql   = $wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a code-controlled prefix concatenation.
				"INSERT INTO {$table} (day,sid,stages,block_mask,last_block,device,source_type,source,medium,campaign,landing,hour,dow,pageviews)
				VALUES (%s,UNHEX(%s),%d,%d,%s,%d,%s,%s,%s,%s,%s,%d,%d,%d)
				ON DUPLICATE KEY UPDATE
				stages = stages | VALUES(stages),
				block_mask = block_mask | VALUES(block_mask),
				last_block = IF(VALUES(last_block) = '', last_block, VALUES(last_block)),
				device = IF(device = 0, VALUES(device), device),
				source = IF(source_type = '', VALUES(source), source),
				medium = IF(source_type = '', VALUES(medium), medium),
				campaign = IF(source_type = '', VALUES(campaign), campaign),
				source_type = IF(source_type = '', VALUES(source_type), source_type),
				landing = IF(landing = '', VALUES(landing), landing),
				pageviews = pageviews + VALUES(pageviews)",
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
			);
			return false !== $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- prepared above.
		}

		/**
		 * Add to counters with ONE multi-row upsert.
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
			$tuples = array();
			$args   = array();
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
					$tuples[] = '(%s,%s,%s,%d)';
					array_push( $args, $day, $metric, substr( (string) $dim, 0, 100 ), max( 0, $value ) );
				}
			}
			if ( empty( $tuples ) ) {
				return true;
			}
			$table  = self::daily_table_name();
			$update = $replace ? 'value = VALUES(value)' : 'value = value + VALUES(value)';
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- table + tuple placeholders are code-controlled; every value is a %s/%d placeholder.
			$sql = $wpdb->prepare( "INSERT INTO {$table} (day,metric,dim,value) VALUES " . implode( ',', $tuples ) . " ON DUPLICATE KEY UPDATE {$update}", $args );
			return false !== $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- prepared above.
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
			$sessions = self::sessions_table_name();
			$daily    = self::daily_table_name();
			$row      = $wpdb->get_row( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- live counters; caching would defeat the cap.
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names are code-controlled prefix concatenations.
					"SELECT (SELECT pageviews FROM {$sessions} WHERE day = %s AND sid = UNHEX(%s)) AS pv, (SELECT value FROM {$daily} WHERE day = %s AND metric = 'beacons' AND dim = %s) AS g",
					$day,
					$sid,
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
			$sessions = self::sessions_table_name();
			$daily    = self::daily_table_name();
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- code-controlled table names, no input; one-off fallback, then persisted.
			$day = $wpdb->get_var( "SELECT LEAST( COALESCE( (SELECT MIN(day) FROM {$sessions}), '9999-12-31' ), COALESCE( (SELECT MIN(day) FROM {$daily}), '9999-12-31' ) )" );
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
			$table = self::sessions_table_name();
			$rows  = $wpdb->get_results( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- nightly batch / admin report.
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a code-controlled prefix concatenation.
					"SELECT stages, block_mask, last_block, device, source_type, source, medium, campaign, landing, hour, dow, pageviews FROM {$table} WHERE day = %s",
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
			$args  = array( $from, $to );
			$in    = '';
			if ( ! empty( $metrics ) ) {
				$in   = ' AND metric IN (' . implode( ',', array_fill( 0, count( $metrics ), '%s' ) ) . ')';
				$args = array_merge( $args, array_values( $metrics ) );
			}
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- table + IN placeholders are code-controlled.
			$sql  = $wpdb->prepare( "SELECT metric, dim, SUM(value) AS value FROM {$table} WHERE day BETWEEN %s AND %s{$in} GROUP BY metric, dim", $args );
			$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- prepared above; the report layer caches.
			return is_array( $rows ) ? $rows : array();
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
			$base     = strtotime( $today . ' 00:00:00 UTC' );
			$sessions = self::sessions_table_name();
			$daily    = self::daily_table_name();
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- code-controlled table names; retention batch.
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$sessions} WHERE day < %s", gmdate( 'Y-m-d', $base - $keep_days * 86400 ) ) );
			$wpdb->query( $wpdb->prepare( "DELETE FROM {$daily} WHERE day < %s", gmdate( 'Y-m-d', $base - $counter_days * 86400 ) ) );
			// phpcs:enable
		}
	}
}
