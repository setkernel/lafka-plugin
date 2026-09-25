<?php
/**
 * Lafka_Incidents — deduplicated "what is broken and how often" index (GX1 / A4).
 *
 * WooCommerce's logs hold the detail lines; this table holds one row per
 * distinct problem. Every Lafka_Log record at `warning` or above upserts the
 * row keyed by its fingerprint — sha1( channel | code | normalized message |
 * file:line ) — bumping `hit_count` and `last_seen`. Nothing is written for
 * info/debug, and a fingerprint is written at most once per request (so the
 * count means "requests affected", and a loop can never hammer the table).
 *
 * Status: open → muted (hidden from the digest, still counted) or resolved
 * (a resolved incident that recurs re-opens itself: a regression).
 *
 * Retention: rows are deleted 90 days after `last_seen` (option
 * `lafka_log_settings[retention_days]`, filter `lafka_incidents_retention_days`)
 * by the daily Action Scheduler job (Lafka_Diagnostics::run_daily()).
 *
 * Schema is versioned by `lafka_incidents_db_version` and self-heals through
 * dbDelta on version drift; uninstall drops the table (Lafka_Uninstall).
 *
 * @package Lafka\Plugin\Observability
 * @since   10.2.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Lafka_Incidents' ) ) {

	/**
	 * Incident table access.
	 */
	final class Lafka_Incidents {

		const DB_VERSION     = '1.0.0';
		const VERSION_OPTION = 'lafka_incidents_db_version';
		const STATUSES       = array( 'open', 'muted', 'resolved' );

		/** Hard cap on incident upserts in one request. */
		const MAX_WRITES_PER_REQUEST = 20;

		/** Default retention, in days after last_seen. */
		const RETENTION_DAYS = 90;

		/** Channels whose warnings (not only errors) belong in the digest. */
		const DIGEST_CHANNELS = array( 'payment', 'checkout', 'php', 'js' );

		/** Levels at or above warning / error, for SQL IN lists. */
		const WARNING_LEVELS = array( 'warning', 'error', 'critical', 'alert', 'emergency' );
		const ERROR_LEVELS   = array( 'error', 'critical', 'alert', 'emergency' );

		/** @var array<string,bool> Fingerprints written this request. */
		private static $written = array();

		/**
		 * Fully-qualified table name.
		 *
		 * @return string
		 */
		public static function table_name(): string {
			global $wpdb;
			$prefix = isset( $wpdb ) && is_object( $wpdb ) && isset( $wpdb->prefix ) ? (string) $wpdb->prefix : 'wp_';
			return $prefix . 'lafka_incidents';
		}

		/**
		 * CREATE TABLE for dbDelta (one column per line, two spaces before the PK).
		 *
		 * @return string
		 */
		public static function schema_sql(): string {
			global $wpdb;
			$table   = self::table_name();
			$charset = isset( $wpdb ) && is_object( $wpdb ) && method_exists( $wpdb, 'get_charset_collate' )
				? $wpdb->get_charset_collate()
				: 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';

			return "CREATE TABLE {$table} (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  fingerprint CHAR(40) NOT NULL DEFAULT '',
  channel VARCHAR(32) NOT NULL DEFAULT '',
  level VARCHAR(16) NOT NULL DEFAULT '',
  code VARCHAR(64) NOT NULL DEFAULT '',
  message VARCHAR(255) NOT NULL DEFAULT '',
  sample_context TEXT NULL,
  first_seen DATETIME NOT NULL,
  last_seen DATETIME NOT NULL,
  hit_count BIGINT UNSIGNED NOT NULL DEFAULT 0,
  last_request_id VARCHAR(32) NOT NULL DEFAULT '',
  status VARCHAR(10) NOT NULL DEFAULT 'open',
  notified_at DATETIME NULL DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY fingerprint (fingerprint),
  KEY status_last_seen (status,last_seen),
  KEY channel (channel)
) {$charset};";
		}

		/**
		 * Create / migrate the table and record the schema version.
		 *
		 * @return void
		 */
		public static function install(): void {
			if ( ! function_exists( 'dbDelta' ) && defined( 'ABSPATH' ) && file_exists( ABSPATH . 'wp-admin/includes/upgrade.php' ) ) {
				require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			}
			if ( function_exists( 'dbDelta' ) ) {
				dbDelta( self::schema_sql() );
				update_option( self::VERSION_OPTION, self::DB_VERSION );
			}
		}

		/**
		 * Self-heal: install when the stored schema version drifted. The version
		 * option is autoloaded, so the steady-state cost is one array lookup.
		 *
		 * @return void
		 */
		public static function maybe_install(): void {
			if ( ! self::is_installed() ) {
				self::install();
			}
		}

		/**
		 * @return bool
		 */
		public static function is_installed(): bool {
			return function_exists( 'get_option' ) && self::DB_VERSION === get_option( self::VERSION_OPTION );
		}

		// ─── Write path ─────────────────────────────────────────────────────

		/**
		 * Stable fingerprint of a problem.
		 *
		 * @param string $channel  Channel.
		 * @param string $code     Machine code.
		 * @param string $message  Message (normalized here).
		 * @param string $location "file:line" or ''.
		 * @return string 40 hex chars.
		 */
		public static function fingerprint( string $channel, string $code, string $message, string $location = '' ): string {
			return sha1( $channel . '|' . $code . '|' . self::normalize_message( $message ) . '|' . $location );
		}

		/**
		 * Collapse volatile parts of a message (numbers, whitespace) so
		 * "order 12 failed" and "order 13 failed" are one incident.
		 *
		 * @param string $message Message.
		 * @return string
		 */
		public static function normalize_message( string $message ): string {
			$message = (string) preg_replace( '/\d+/', '#', $message );
			$message = (string) preg_replace( '/\s+/', ' ', $message );
			return trim( $message );
		}

		/**
		 * Upsert one Lafka_Log record (warning+). Never throws.
		 *
		 * @param array<string,mixed> $record Record from Lafka_Log::build_record().
		 * @return bool True when a row was written.
		 */
		public static function record( array $record ): bool {
			global $wpdb;
			if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'query' ) || ! self::is_installed() ) {
				return false;
			}

			$channel  = (string) ( $record['channel'] ?? 'core' );
			$code     = (string) ( $record['code'] ?? '' );
			$message  = (string) ( $record['message'] ?? '' );
			$location = ! empty( $record['file'] ) ? $record['file'] . ':' . (int) ( $record['line'] ?? 0 ) : '';
			$print    = self::fingerprint( $channel, $code, $message, $location );

			if ( isset( self::$written[ $print ] ) || count( self::$written ) >= self::MAX_WRITES_PER_REQUEST ) {
				return false;
			}
			self::$written[ $print ] = true;

			$context = is_array( $record['context'] ?? null ) ? Lafka_Log_Scrubber::bound( $record['context'], 2048 ) : array();
			$json    = function_exists( 'wp_json_encode' ) ? wp_json_encode( $context ) : json_encode( $context );
			$now     = gmdate( 'Y-m-d H:i:s' );
			$table   = self::table_name();

			$suppress = method_exists( $wpdb, 'suppress_errors' ) ? $wpdb->suppress_errors( true ) : null;
			$result   = $wpdb->query(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a code-controlled prefix concatenation.
					"INSERT INTO {$table} (fingerprint, channel, level, code, message, sample_context, first_seen, last_seen, hit_count, last_request_id, status)
					VALUES (%s, %s, %s, %s, %s, %s, %s, %s, 1, %s, 'open')
					ON DUPLICATE KEY UPDATE hit_count = hit_count + 1, last_seen = VALUES(last_seen), level = VALUES(level),
					sample_context = VALUES(sample_context), last_request_id = VALUES(last_request_id),
					status = IF(status = 'resolved', 'open', status)",
					$print,
					substr( $channel, 0, 32 ),
					substr( (string) ( $record['level'] ?? 'warning' ), 0, 16 ),
					substr( $code, 0, 64 ),
					function_exists( 'mb_substr' ) ? mb_substr( $message, 0, 255 ) : substr( $message, 0, 255 ),
					(string) $json,
					$now,
					$now,
					substr( (string) ( $record['request_id'] ?? '' ), 0, 32 )
				)
			);
			if ( null !== $suppress ) {
				$wpdb->suppress_errors( $suppress );
			}
			return false !== $result;
		}

		// ─── Read path ──────────────────────────────────────────────────────

		/**
		 * Page of incidents, newest activity first.
		 *
		 * @param array<string,mixed> $args status (open|muted|resolved|all), channel, per_page, page.
		 * @return array<int,object>
		 */
		public static function query( array $args = array() ): array {
			global $wpdb;
			if ( ! self::db_ready() ) {
				return array();
			}
			$status   = isset( $args['status'] ) ? (string) $args['status'] : 'open';
			$channel  = isset( $args['channel'] ) ? (string) $args['channel'] : '';
			$per_page = max( 1, min( 200, (int) ( $args['per_page'] ?? 25 ) ) );
			$offset   = max( 0, ( (int) ( $args['page'] ?? 1 ) - 1 ) * $per_page );
			$table    = self::table_name();

			$where  = array( '1=1' );
			$params = array();
			if ( in_array( $status, self::STATUSES, true ) ) {
				$where[]  = 'status = %s';
				$params[] = $status;
			}
			if ( '' !== $channel ) {
				$where[]  = 'channel = %s';
				$params[] = $channel;
			}
			$params[] = $per_page;
			$params[] = $offset;

			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- table name is code-controlled; the WHERE fragments are fixed literals from this method with %s placeholders filled by prepare().
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table} WHERE " . implode( ' AND ', $where ) . ' ORDER BY last_seen DESC LIMIT %d OFFSET %d',
					$params
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			return is_array( $rows ) ? $rows : array();
		}

		/**
		 * Row counts per status.
		 *
		 * @return array<string,int>
		 */
		public static function status_counts(): array {
			global $wpdb;
			$out = array_fill_keys( self::STATUSES, 0 );
			if ( ! self::db_ready() ) {
				return $out;
			}
			$table = self::table_name();
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a code-controlled prefix concatenation; no user input.
			$rows = $wpdb->get_results( "SELECT status, COUNT(*) AS n FROM {$table} GROUP BY status" );
			foreach ( is_array( $rows ) ? $rows : array() as $row ) {
				if ( isset( $out[ $row->status ] ) ) {
					$out[ $row->status ] = (int) $row->n;
				}
			}
			return $out;
		}

		/**
		 * Change an incident's status.
		 *
		 * @param int    $id     Row id.
		 * @param string $status open|muted|resolved.
		 * @return bool
		 */
		public static function set_status( int $id, string $status ): bool {
			global $wpdb;
			if ( $id <= 0 || ! in_array( $status, self::STATUSES, true ) || ! self::db_ready() ) {
				return false;
			}
			return false !== $wpdb->update( self::table_name(), array( 'status' => $status ), array( 'id' => $id ), array( '%s' ), array( '%d' ) );
		}

		/**
		 * Delete incidents whose last_seen is older than $days.
		 *
		 * @param int $days Retention in days.
		 * @return int Rows deleted.
		 */
		public static function prune( int $days = self::RETENTION_DAYS ): int {
			global $wpdb;
			if ( $days < 1 || ! self::db_ready() ) {
				return 0;
			}
			$table  = self::table_name();
			$cutoff = gmdate( 'Y-m-d H:i:s', time() - $days * 86400 );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a code-controlled prefix concatenation.
			$deleted = $wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE last_seen < %s", $cutoff ) );
			return is_numeric( $deleted ) ? (int) $deleted : 0;
		}

		/**
		 * Number of non-muted incidents on $channel seen in the last $hours.
		 *
		 * @param string $channel Channel.
		 * @param int    $hours   Window.
		 * @return int
		 */
		public static function recent_count( string $channel, int $hours ): int {
			global $wpdb;
			if ( ! self::db_ready() ) {
				return 0;
			}
			$table = self::table_name();
			$since = gmdate( 'Y-m-d H:i:s', time() - $hours * 3600 );
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a code-controlled prefix concatenation.
			$n = $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE channel = %s AND status <> 'muted' AND last_seen >= %s", $channel, $since ) );
			return (int) $n;
		}

		/**
		 * Open incidents the owner has not been told about yet: new, or seen
		 * again since the last digest. Warnings count on the digest channels
		 * (payment, checkout, php, js); elsewhere only errors and above.
		 *
		 * @param int $limit Max rows.
		 * @return array<int,object>
		 */
		public static function pending_digest( int $limit = 50 ): array {
			global $wpdb;
			if ( ! self::db_ready() ) {
				return array();
			}
			$table    = self::table_name();
			$channels = implode( ',', array_fill( 0, count( self::DIGEST_CHANNELS ), '%s' ) );
			$warn     = implode( ',', array_fill( 0, count( self::WARNING_LEVELS ), '%s' ) );
			$err      = implode( ',', array_fill( 0, count( self::ERROR_LEVELS ), '%s' ) );
			$params   = array_merge( self::DIGEST_CHANNELS, self::WARNING_LEVELS, self::ERROR_LEVELS, array( max( 1, $limit ) ) );

			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- table name is code-controlled; the IN() lists are generated %s placeholders for class constants, filled by prepare().
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM {$table}
					WHERE status = 'open'
					AND ( notified_at IS NULL OR last_seen > notified_at )
					AND ( ( channel IN ({$channels}) AND level IN ({$warn}) ) OR level IN ({$err}) )
					ORDER BY last_seen DESC LIMIT %d",
					$params
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
			return is_array( $rows ) ? $rows : array();
		}

		/**
		 * Stamp notified_at on the incidents a digest just reported.
		 *
		 * @param array<int,int> $ids Row ids.
		 * @return void
		 */
		public static function mark_notified( array $ids ): void {
			global $wpdb;
			$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
			if ( empty( $ids ) || ! self::db_ready() ) {
				return;
			}
			$table        = self::table_name();
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- table name is code-controlled; %d placeholders generated for the id list.
			$wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET notified_at = %s WHERE id IN ({$placeholders})",
					array_merge( array( gmdate( 'Y-m-d H:i:s' ) ), $ids )
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare
		}

		/**
		 * Whether the table can be queried.
		 *
		 * @return bool
		 */
		private static function db_ready(): bool {
			global $wpdb;
			return isset( $wpdb ) && is_object( $wpdb ) && method_exists( $wpdb, 'prepare' ) && self::is_installed();
		}

		/**
		 * Forget per-request write state (tests).
		 *
		 * @return void
		 */
		public static function reset(): void {
			self::$written = array();
		}
	}
}
