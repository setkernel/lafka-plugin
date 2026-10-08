<?php
/**
 * Lafka_Schema: the one registry of the plugin's custom tables.
 *
 * Every custom table is declared here once: its name, the SQL that creates it,
 * its schema version and the option that records the installed version. One
 * upgrader reads the registry for activation, for the self-heal on
 * plugins_loaded (a missing or outdated table is created or altered through
 * dbDelta) and for uninstall, so a table can no longer be added to one path and
 * forgotten in another.
 *
 * To change a table: edit its SQL in sql() and raise its 'version'; the next
 * request upgrades it. To add a table: add one entry to definitions() and one
 * case to sql().
 *
 * @package Lafka\Plugin\Tools
 * @since   10.4.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Lafka_Schema' ) ) {

	final class Lafka_Schema {

		/**
		 * The tables.
		 *
		 *   suffix         table name without the $wpdb prefix.
		 *   version        schema version; raise it when the SQL changes.
		 *   version_option option that records the installed version.
		 *   gate           callable(): bool, or null. A module-owned table is only
		 *                  created or upgraded while its module is on (activation
		 *                  and uninstall ignore the gate).
		 *
		 * @return array<string,array{suffix:string,version:string,version_option:string,gate:?callable}>
		 */
		public static function definitions(): array {
			return array(
				'abandoned_carts' => array(
					'suffix'         => 'lafka_abandoned_carts',
					'version'        => '1.1.0',
					'version_option' => 'lafka_abandoned_cart_db_version',
					'gate'           => static function () {
						return function_exists( 'lafka_ac_capture_is_enabled' ) && lafka_ac_capture_is_enabled();
					},
				),
				'push'            => array(
					'suffix'         => 'lafka_push_subscriptions',
					'version'        => '1.0.0',
					'version_option' => 'lafka_push_db_version',
					'gate'           => static function () {
						return function_exists( 'lafka_push_rest_is_enabled' ) && lafka_push_rest_is_enabled();
					},
				),
				'incidents'       => array(
					'suffix'         => 'lafka_incidents',
					'version'        => '1.0.0',
					'version_option' => 'lafka_incidents_db_version',
					'gate'           => null,
				),
				'insights'        => array(
					'suffix'         => 'lafka_insights_sessions',
					'version'        => '1.0.0',
					'version_option' => 'lafka_insights_db_version',
					'gate'           => static function () {
						return class_exists( 'Lafka_Insights' ) && Lafka_Insights::is_enabled();
					},
				),
			);
		}

		/**
		 * Tables created by a definition's SQL beyond its own suffix (one entry
		 * may create more than one table).
		 *
		 * @return array<string,string[]> Definition id => extra table suffixes.
		 */
		private static function extra_suffixes(): array {
			return array(
				'insights' => array( 'lafka_insights_daily' ),
			);
		}

		/**
		 * Every table suffix owned by the plugin (for uninstall and tests).
		 *
		 * @return string[]
		 */
		public static function all_suffixes(): array {
			$out = array();
			foreach ( self::definitions() as $id => $def ) {
				$out[] = $def['suffix'];
				foreach ( self::extra_suffixes()[ $id ] ?? array() as $extra ) {
					$out[] = $extra;
				}
			}
			return $out;
		}

		/**
		 * Every version option (for uninstall).
		 *
		 * @return string[]
		 */
		public static function version_options(): array {
			return array_values( array_column( self::definitions(), 'version_option' ) );
		}

		/**
		 * Fully-qualified name of a table (respects $wpdb->prefix).
		 *
		 * @param string $id     Definition id.
		 * @param string $suffix Optional suffix of a secondary table of the definition.
		 * @return string
		 */
		public static function table_name( string $id, string $suffix = '' ): string {
			global $wpdb;
			$prefix = isset( $wpdb ) && is_object( $wpdb ) && isset( $wpdb->prefix ) ? (string) $wpdb->prefix : 'wp_';
			$def    = self::definitions()[ $id ] ?? null;
			return $prefix . ( '' !== $suffix ? $suffix : ( null !== $def ? $def['suffix'] : $id ) );
		}

		/**
		 * Whether a table's recorded version is the current one.
		 *
		 * @param string $id Definition id.
		 * @return bool
		 */
		public static function is_installed( string $id ): bool {
			$def = self::definitions()[ $id ] ?? null;
			return null !== $def && function_exists( 'get_option' ) && (string) get_option( $def['version_option'], '' ) === $def['version'];
		}

		/**
		 * Create or upgrade one table through dbDelta and record its version.
		 *
		 * @param string $id Definition id.
		 * @return void
		 */
		public static function install( string $id ): void {
			$def = self::definitions()[ $id ] ?? null;
			if ( null === $def ) {
				return;
			}
			if ( ! function_exists( 'dbDelta' ) && defined( 'ABSPATH' ) && file_exists( ABSPATH . 'wp-admin/includes/upgrade.php' ) ) {
				require_once ABSPATH . 'wp-admin/includes/upgrade.php';
			}
			if ( ! function_exists( 'dbDelta' ) ) {
				return;
			}
			dbDelta( self::sql( $id ) );
			update_option( $def['version_option'], $def['version'] );
		}

		/**
		 * Activation: create every table.
		 *
		 * @return void
		 */
		public static function install_all(): void {
			foreach ( array_keys( self::definitions() ) as $id ) {
				self::install( $id );
			}
		}

		/**
		 * Self-heal on plugins_loaded: create or upgrade each table whose
		 * recorded version differs, while its module is on.
		 *
		 * @return void
		 */
		public static function maybe_install(): void {
			foreach ( self::definitions() as $id => $def ) {
				if ( is_callable( $def['gate'] ) && ! call_user_func( $def['gate'] ) ) {
					continue;
				}
				if ( ! self::is_installed( $id ) ) {
					self::install( $id );
				}
			}
		}

		/**
		 * The CREATE TABLE statement(s) of a definition. dbDelta is
		 * whitespace-sensitive: one column per line, two spaces before the
		 * PRIMARY KEY column list.
		 *
		 * @param string $id Definition id.
		 * @return string|string[]
		 */
		public static function sql( string $id ) {
			global $wpdb;
			$charset = isset( $wpdb ) && is_object( $wpdb ) && method_exists( $wpdb, 'get_charset_collate' )
				? $wpdb->get_charset_collate()
				: 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';

			switch ( $id ) {
				case 'abandoned_carts':
					$table = self::table_name( $id );
					// Indexes: customer_email and session_id serve the upsert, order_id
					// the "was an order placed" lookups, created_at the 30-day purge,
					// and recovery_queue the pending-recovery scan.
					return "CREATE TABLE {$table} (
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
  KEY order_id (order_id),
  KEY created_at (created_at),
  KEY recovery_queue (recovery_sent_at,order_id,last_seen_at)
) {$charset};";

				case 'push':
					$table = self::table_name( $id );
					// The endpoint key is a 191-character prefix (the utf8mb4 InnoDB
					// limit): Apple endpoints run past 2 KB but are unique within it.
					return "CREATE TABLE {$table} (
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

				case 'incidents':
					$table = self::table_name( $id );
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

				case 'insights':
					$sessions = self::table_name( $id );
					$daily    = self::table_name( $id, 'lafka_insights_daily' );
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
			return '';
		}
	}
}
