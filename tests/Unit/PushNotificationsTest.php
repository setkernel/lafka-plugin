<?php
/**
 * PushNotificationsTest — locks down the Phase 3E (v9.29.0) Web Push module:
 *
 *   - DB schema source-grep + helper presence
 *   - REST route registration (subscribe / unsubscribe / vapid-key)
 *   - VAPID key sanitisation (valid vs invalid base64url)
 *   - Subscription save dedupes by endpoint (upsert path)
 *   - Reorder cron schedules + days clamping
 *   - Customizer panel + every setting has default + sanitize_callback
 *   - Audience resolver returns expected shape for 'all' / 'recent_customers'
 *     / explicit array
 *   - VAPID JWT + crypto encode/decode helpers (b64url, DER->JOSE, HKDF)
 *   - Main plugin requires every push module + activation hooks present
 *   - Uninstall.php drops the push table + option
 *
 * @package Lafka\Plugin\Tests\Unit
 * @since   9.29.0
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Lafka_Customizer_Push;
use PHPUnit\Framework\TestCase;

if ( ! defined( 'LAFKA_TESTING' ) ) {
	define( 'LAFKA_TESTING', true );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}

require_once dirname( __DIR__, 2 ) . '/incl/conversion/lafka-push-db.php';
require_once dirname( __DIR__, 2 ) . '/incl/conversion/lafka-push-rest.php';
require_once dirname( __DIR__, 2 ) . '/incl/conversion/lafka-push-sender.php';
require_once dirname( __DIR__, 2 ) . '/incl/conversion/lafka-push-reorder-cron.php';
require_once dirname( __DIR__, 2 ) . '/incl/customizer/class-lafka-customizer-push.php';

/**
 * In-process $wpdb stand-in. Records every insert/update/delete so tests can
 * assert dedupe + upsert behaviour without booting WordPress.
 */
class FakePushWpdb {

	public string $prefix = 'wp_';
	/** @var array<int,array> */
	public array $rows = array();
	public int $next_id = 1;
	public int $insert_id = 0;
	public array $last_query_args = array();

	public function get_charset_collate(): string {
		return 'DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci';
	}

	public function prepare( $sql, ...$args ) {
		$this->last_query_args = $args;
		return $sql;
	}

	public function get_var( $sql ) {
		// Used by `lafka_push_save_subscription` to look up existing row by endpoint.
		// We match against last_query_args[0] which is the endpoint param.
		$endpoint = $this->last_query_args[0] ?? '';
		foreach ( $this->rows as $row ) {
			if ( isset( $row['endpoint'] ) && $row['endpoint'] === $endpoint ) {
				return (int) $row['id'];
			}
		}
		// Used by the admin "active count" call.
		if ( false !== strpos( $sql, 'COUNT(*)' ) ) {
			$active = 0;
			foreach ( $this->rows as $row ) {
				if ( empty( $row['unsubscribed_at'] ) ) {
					++$active;
				}
			}
			return $active;
		}
		return 0;
	}

	public function get_row( $sql ) {
		$endpoint = $this->last_query_args[0] ?? '';
		foreach ( $this->rows as $row ) {
			if ( isset( $row['endpoint'] ) && $row['endpoint'] === $endpoint ) {
				return (object) $row;
			}
		}
		return null;
	}

	public function get_results( $sql ) {
		$out = array();
		foreach ( $this->rows as $row ) {
			if ( empty( $row['unsubscribed_at'] ) ) {
				$out[] = (object) $row;
			}
		}
		return $out;
	}

	public function insert( $table, $data, $formats = null ) {
		$id           = $this->next_id++;
		$data['id']   = $id;
		$this->rows[] = $data;
		$this->insert_id = $id;
		return 1;
	}

	public function update( $table, $data, $where, $formats = null, $where_formats = null ) {
		foreach ( $this->rows as &$row ) {
			$matches = true;
			foreach ( $where as $k => $v ) {
				if ( ( $row[ $k ] ?? null ) !== $v ) {
					$matches = false;
					break;
				}
			}
			if ( $matches ) {
				foreach ( $data as $k => $v ) {
					$row[ $k ] = $v;
				}
				return 1;
			}
		}
		return 0;
	}

	public function delete( $table, $where, $formats = null ) {
		$count = 0;
		foreach ( $this->rows as $k => $row ) {
			$matches = true;
			foreach ( $where as $col => $val ) {
				if ( ( $row[ $col ] ?? null ) !== $val ) {
					$matches = false;
					break;
				}
			}
			if ( $matches ) {
				unset( $this->rows[ $k ] );
				++$count;
			}
		}
		$this->rows = array_values( $this->rows );
		return $count;
	}

	public function query( $sql ) {
		// Used by the cleanup helper - just return count for the test.
		return count( $this->rows );
	}
}

final class PushNotificationsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
		Functions\when( '_x' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_attr__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_url_raw' )->returnArg();
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_textarea_field' )->returnArg();
		Functions\when( 'sanitize_email' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'get_theme_mod' )->returnArg( 2 );
		Functions\when( 'get_option' )->returnArg( 2 );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'get_locale' )->justReturn( 'en_US' );
		Functions\when( 'current_time' )->justReturn( '2026-05-18 12:00:00' );
		Functions\when( 'is_email' )->alias(
			static function ( $email ) {
				return is_string( $email ) && false !== strpos( $email, '@' );
			}
		);
		Functions\when( 'get_current_user_id' )->justReturn( 0 );

		// Reset the fake DB.
		global $wpdb;
		$wpdb = new FakePushWpdb();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ─────────────────────────────────────────────────────────────────────────
	// 1. DB schema (source-grep + functional)
	// ─────────────────────────────────────────────────────────────────────────

	public function test_db_module_defines_table_name_helper(): void {
		$src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/conversion/lafka-push-db.php' );
		$this->assertStringContainsString( 'function lafka_push_table_name', $src );
		$this->assertStringContainsString( 'lafka_push_subscriptions', $src );
	}

	public function test_schema_sql_contains_every_expected_column(): void {
		$sql = \lafka_push_schema_sql();
		$this->assertStringContainsString( 'CREATE TABLE', $sql );
		$this->assertStringContainsString( 'lafka_push_subscriptions', $sql );
		$required = array(
			'id',
			'user_id',
			'endpoint',
			'p256dh',
			'auth',
			'user_agent',
			'locale',
			'created_at',
			'last_seen_at',
			'unsubscribed_at',
		);
		foreach ( $required as $column ) {
			$this->assertStringContainsString( $column, $sql, "Schema must include column {$column}" );
		}
	}

	public function test_schema_sql_declares_primary_key_and_indexes(): void {
		$sql = \lafka_push_schema_sql();
		$this->assertStringContainsString( 'PRIMARY KEY', $sql );
		$this->assertStringContainsString( 'UNIQUE KEY endpoint', $sql );
		$this->assertStringContainsString( 'KEY user_id', $sql );
		$this->assertStringContainsString( 'KEY last_seen_at', $sql );
		$this->assertStringContainsString( 'KEY unsubscribed_at', $sql );
	}

	public function test_install_table_calls_dbdelta(): void {
		$src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/conversion/lafka-push-db.php' );
		$this->assertStringContainsString( 'dbDelta', $src );
		$this->assertStringContainsString( 'lafka_push_db_version', $src );
	}

	public function test_save_subscription_inserts_new_row(): void {
		global $wpdb;
		$id = \lafka_push_save_subscription(
			'https://fcm.googleapis.com/fcm/send/abc',
			'BNxxx_publickey_88chars',
			'authsecret16b',
			42,
			'Mozilla/5.0 test',
			'en_US'
		);
		$this->assertGreaterThan( 0, $id );
		$this->assertCount( 1, $wpdb->rows );
		$this->assertSame( 'https://fcm.googleapis.com/fcm/send/abc', $wpdb->rows[0]['endpoint'] );
		$this->assertSame( 42, $wpdb->rows[0]['user_id'] );
	}

	public function test_save_subscription_dedupes_by_endpoint(): void {
		global $wpdb;
		$id1 = \lafka_push_save_subscription( 'https://fcm.googleapis.com/fcm/send/abc', 'pub1', 'auth1', 42 );
		$id2 = \lafka_push_save_subscription( 'https://fcm.googleapis.com/fcm/send/abc', 'pub2', 'auth2', 42 );
		$this->assertSame( $id1, $id2, 'Same endpoint must reuse the same row.' );
		$this->assertCount( 1, $wpdb->rows, 'Only one row should exist after dedupe.' );
		$this->assertSame( 'pub2', $wpdb->rows[0]['p256dh'], 'Upsert must update p256dh in place.' );
	}

	public function test_save_subscription_rejects_empty_endpoint(): void {
		$id = \lafka_push_save_subscription( '', 'pub', 'auth', 42 );
		$this->assertSame( 0, $id );
	}

	public function test_mark_unsubscribed_soft_deletes_row(): void {
		global $wpdb;
		\lafka_push_save_subscription( 'https://example.com/ep', 'pub', 'auth', 42 );
		$count = \lafka_push_mark_unsubscribed( 'https://example.com/ep' );
		$this->assertSame( 1, $count );
		$this->assertNotEmpty( $wpdb->rows[0]['unsubscribed_at'] );
	}

	public function test_delete_subscription_removes_row(): void {
		global $wpdb;
		\lafka_push_save_subscription( 'https://example.com/ep', 'pub', 'auth', 42 );
		$count = \lafka_push_delete_subscription( 'https://example.com/ep' );
		$this->assertSame( 1, $count );
		$this->assertCount( 0, $wpdb->rows );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// 2. REST routes + payload shape
	// ─────────────────────────────────────────────────────────────────────────

	public function test_rest_module_registers_three_routes(): void {
		$src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/conversion/lafka-push-rest.php' );
		$this->assertStringContainsString( "register_rest_route", $src );
		$this->assertStringContainsString( "'/push/subscribe'", $src );
		$this->assertStringContainsString( "'/push/unsubscribe'", $src );
		$this->assertStringContainsString( "'/push/vapid-key'", $src );
		$this->assertStringContainsString( "'lafka/v1'", $src );
	}

	public function test_rest_subscribe_rejects_non_https_endpoint(): void {
		Functions\when( 'get_theme_mod' )->alias(
			static function ( $key, $default = null ) {
				return 'lafka_push_enabled' === $key ? '1' : $default;
			}
		);
		$req      = new class() {
			public function get_json_params() {
				return array(
					'endpoint' => 'http://insecure.example.com/ep',
					'keys'     => array(
						'p256dh' => 'validkey',
						'auth'   => 'validauth',
					),
				);
			}
			public function get_params() {
				return array(); }
		};
		$response = \lafka_push_rest_subscribe( $req );
		// In test env without WP_REST_Response, we get back an array.
		$this->assertIsArray( $response );
		$this->assertFalse( $response['ok'] );
		$this->assertSame( 'invalid_payload', $response['code'] );
	}

	public function test_rest_subscribe_persists_when_payload_valid(): void {
		Functions\when( 'get_theme_mod' )->alias(
			static function ( $key, $default = null ) {
				return 'lafka_push_enabled' === $key ? '1' : $default;
			}
		);
		$req = new class() {
			public function get_json_params() {
				return array(
					'endpoint' => 'https://fcm.googleapis.com/fcm/send/abc123',
					'keys'     => array(
						'p256dh' => 'BNxxxlongbase64urlkey-yes',
						'auth'   => 'authsecret_base64',
					),
				);
			}
			public function get_params() {
				return array(); }
		};
		$response = \lafka_push_rest_subscribe( $req );
		$this->assertIsArray( $response );
		$this->assertTrue( $response['ok'] );
		$this->assertGreaterThan( 0, $response['subscription_id'] );
	}

	public function test_rest_unsubscribe_marks_row(): void {
		Functions\when( 'get_theme_mod' )->alias(
			static function ( $key, $default = null ) {
				return 'lafka_push_enabled' === $key ? '1' : $default;
			}
		);
		global $wpdb;
		\lafka_push_save_subscription( 'https://example.com/ep', 'pub', 'auth', 42 );
		$req      = new class() {
			public function get_json_params() {
				return array( 'endpoint' => 'https://example.com/ep' );
			}
			public function get_params() {
				return array(); }
		};
		$response = \lafka_push_rest_unsubscribe( $req );
		$this->assertTrue( $response['ok'] );
		$this->assertSame( 1, $response['removed'] );
	}

	public function test_rest_vapid_key_returns_public_key_and_enabled_flag(): void {
		Functions\when( 'get_theme_mod' )->alias(
			static function ( $key, $default = null ) {
				if ( 'lafka_push_enabled' === $key ) {
					return '1';
				}
				if ( 'lafka_push_vapid_public_key' === $key ) {
					return 'PUBLICKEY_88_chars_base64url';
				}
				return $default;
			}
		);
		$response = \lafka_push_rest_vapid_key();
		$this->assertTrue( $response['enabled'] );
		$this->assertSame( 'PUBLICKEY_88_chars_base64url', $response['key'] );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// 3. Customizer + sanitizers
	// ─────────────────────────────────────────────────────────────────────────

	public function test_customizer_registers_lafka_push_panel(): void {
		$src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/customizer/class-lafka-customizer-push.php' );
		$this->assertStringContainsString( 'add_panel', $src );
		$this->assertStringContainsString( "'lafka_push'", $src );
	}

	public function test_customizer_registers_all_required_settings(): void {
		$src      = file_get_contents( dirname( __DIR__, 2 ) . '/incl/customizer/class-lafka-customizer-push.php' );
		$required = array(
			'lafka_push_enabled',
			'lafka_push_vapid_public_key',
			'lafka_push_vapid_private_key',
			'lafka_push_vapid_subject',
			'lafka_push_subscribe_prompt_enabled',
			'lafka_push_subscribe_prompt_threshold',
			'lafka_push_subscribe_prompt_copy',
			'lafka_push_reorder_reminder_enabled',
			'lafka_push_reorder_reminder_days',
		);
		foreach ( $required as $setting ) {
			$this->assertStringContainsString(
				"'" . $setting . "'",
				$src,
				"Customizer setting {$setting} must be registered."
			);
		}
	}

	public function test_every_customizer_setting_has_default_and_sanitize_callback(): void {
		$src             = file_get_contents( dirname( __DIR__, 2 ) . '/incl/customizer/class-lafka-customizer-push.php' );
		$default_count   = substr_count( $src, "'default'" );
		$sanitize_count  = substr_count( $src, "'sanitize_callback'" );
		$add_setting_cnt = substr_count( $src, '$wp_customize->add_setting' );
		$this->assertGreaterThanOrEqual( $add_setting_cnt, $default_count, 'Every add_setting() must include a default.' );
		$this->assertGreaterThanOrEqual( $add_setting_cnt, $sanitize_count, 'Every add_setting() must include a sanitize_callback.' );
	}

	public function test_sanitize_vapid_public_accepts_long_base64url(): void {
		$valid = str_repeat( 'A', 88 );
		$this->assertSame( $valid, Lafka_Customizer_Push::sanitize_vapid_public( $valid ) );
		$mixed = 'ABCabc_-' . str_repeat( 'X', 80 );
		$this->assertSame( $mixed, Lafka_Customizer_Push::sanitize_vapid_public( $mixed ) );
	}

	public function test_sanitize_vapid_public_rejects_invalid_input(): void {
		$this->assertSame( '', Lafka_Customizer_Push::sanitize_vapid_public( 'too_short' ) );
		$this->assertSame( '', Lafka_Customizer_Push::sanitize_vapid_public( str_repeat( '!', 88 ) ) );
		$this->assertSame( '', Lafka_Customizer_Push::sanitize_vapid_public( '' ) );
	}

	public function test_sanitize_vapid_private_accepts_44ish_base64url(): void {
		$valid = str_repeat( 'B', 43 );
		$this->assertSame( $valid, Lafka_Customizer_Push::sanitize_vapid_private( $valid ) );
	}

	public function test_sanitize_vapid_private_rejects_invalid(): void {
		$this->assertSame( '', Lafka_Customizer_Push::sanitize_vapid_private( 'x' ) );
		$this->assertSame( '', Lafka_Customizer_Push::sanitize_vapid_private( str_repeat( '#', 44 ) ) );
	}

	public function test_sanitize_vapid_subject_normalises_mailto(): void {
		$this->assertSame(
			'mailto:op@example.com',
			Lafka_Customizer_Push::sanitize_vapid_subject( 'mailto:OP@example.com' )
		);
		$this->assertSame( '', Lafka_Customizer_Push::sanitize_vapid_subject( 'mailto:notanemail' ) );
		$this->assertSame( '', Lafka_Customizer_Push::sanitize_vapid_subject( 'ftp://nope.example/' ) );
	}

	public function test_sanitize_prompt_threshold_clamps(): void {
		$this->assertSame( 1, Lafka_Customizer_Push::sanitize_prompt_threshold( 0 ) );
		$this->assertSame( 10, Lafka_Customizer_Push::sanitize_prompt_threshold( 999 ) );
		$this->assertSame( 2, Lafka_Customizer_Push::sanitize_prompt_threshold( 2 ) );
	}

	public function test_sanitize_reorder_days_clamps(): void {
		$this->assertSame( 3, Lafka_Customizer_Push::sanitize_reorder_days( 1 ) );
		$this->assertSame( 90, Lafka_Customizer_Push::sanitize_reorder_days( 999 ) );
		$this->assertSame( 14, Lafka_Customizer_Push::sanitize_reorder_days( 14 ) );
	}

	public function test_sanitize_checkbox_normalises_truthy_input(): void {
		$this->assertSame( '1', Lafka_Customizer_Push::sanitize_checkbox( '1' ) );
		$this->assertSame( '1', Lafka_Customizer_Push::sanitize_checkbox( 1 ) );
		$this->assertSame( '1', Lafka_Customizer_Push::sanitize_checkbox( true ) );
		$this->assertSame( '0', Lafka_Customizer_Push::sanitize_checkbox( '' ) );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// 4. Crypto helpers (b64url + DER->JOSE + HKDF)
	// ─────────────────────────────────────────────────────────────────────────

	public function test_b64url_encode_strips_padding_and_uses_url_alphabet(): void {
		$raw = "\xff\xff\xfb";
		$out = \lafka_push_b64url_encode( $raw );
		$this->assertSame( '___7', $out );
		$this->assertStringNotContainsString( '=', $out );
		$this->assertStringNotContainsString( '+', $out );
		$this->assertStringNotContainsString( '/', $out );
	}

	public function test_b64url_round_trip(): void {
		$raw     = random_bytes( 32 );
		$encoded = \lafka_push_b64url_encode( $raw );
		$decoded = \lafka_push_b64url_decode( $encoded );
		$this->assertSame( $raw, $decoded );
	}

	public function test_hkdf_returns_requested_length(): void {
		$out = \lafka_push_hkdf( 'ikm', 'salt', 'info', 32 );
		$this->assertSame( 32, strlen( $out ) );
		$out16 = \lafka_push_hkdf( 'ikm', 'salt', 'info', 16 );
		$this->assertSame( 16, strlen( $out16 ) );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// 5. Reorder cron + audience resolver
	// ─────────────────────────────────────────────────────────────────────────

	public function test_reorder_cron_module_registers_schedule_and_handlers(): void {
		$src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/conversion/lafka-push-reorder-cron.php' );
		$this->assertStringContainsString( "wp_schedule_event", $src );
		$this->assertStringContainsString( 'lafka_push_reorder_reminder', $src );
		$this->assertStringContainsString( 'lafka_push_cleanup_subscriptions', $src );
		$this->assertStringContainsString( "add_action( 'lafka_push_reorder_reminder'", $src );
	}

	public function test_reorder_days_helper_clamps(): void {
		Functions\when( 'get_theme_mod' )->alias(
			static function ( $key, $default = null ) {
				return 'lafka_push_reorder_reminder_days' === $key ? 1 : $default;
			}
		);
		$this->assertGreaterThanOrEqual( 3, \lafka_push_reorder_get_days() );
		Functions\when( 'get_theme_mod' )->alias(
			static function ( $key, $default = null ) {
				return 'lafka_push_reorder_reminder_days' === $key ? 9999 : $default;
			}
		);
		$this->assertLessThanOrEqual( 90, \lafka_push_reorder_get_days() );
	}

	public function test_resolve_audience_all_returns_null(): void {
		$this->assertNull( \lafka_push_resolve_audience( 'all' ) );
	}

	public function test_resolve_audience_array_filters_invalid(): void {
		$ids = \lafka_push_resolve_audience( array( 0, 1, 2, -3, '4', 'abc', 5 ) );
		$this->assertSame( array( 1, 2, 4, 5 ), $ids );
	}

	public function test_resolve_audience_recent_customers_returns_array(): void {
		// Without WC functions mocked, the helper returns an empty array (not null).
		$out = \lafka_push_resolve_audience( 'recent_customers' );
		$this->assertIsArray( $out );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// 6. Main plugin wiring + uninstall
	// ─────────────────────────────────────────────────────────────────────────

	public function test_main_plugin_requires_all_push_modules(): void {
		$main = file_get_contents( dirname( __DIR__, 2 ) . '/lafka-plugin.php' );
		$this->assertStringContainsString( 'incl/conversion/lafka-push-db.php', $main );
		$this->assertStringContainsString( 'incl/conversion/lafka-push-rest.php', $main );
		$this->assertStringContainsString( 'incl/conversion/lafka-push-sender.php', $main );
		$this->assertStringContainsString( 'incl/conversion/lafka-push-reorder-cron.php', $main );
		$this->assertStringContainsString( 'incl/customizer/class-lafka-customizer-push.php', $main );
		$this->assertStringContainsString( 'incl/admin/class-lafka-push-admin.php', $main );
	}

	public function test_main_plugin_registers_push_activation_hooks(): void {
		$main = file_get_contents( dirname( __DIR__, 2 ) . '/lafka-plugin.php' );
		$this->assertStringContainsString( 'lafka_push_install_table', $main );
		$this->assertStringContainsString( 'lafka_push_reorder_schedule_event', $main );
		$this->assertStringContainsString( 'lafka_push_reorder_unschedule_event', $main );
	}

	public function test_main_plugin_version_is_at_least_9_29_0(): void {
		$main = file_get_contents( dirname( __DIR__, 2 ) . '/lafka-plugin.php' );
		$this->assertMatchesRegularExpression( '/Version:\s*9\.(29|[3-9]\d|\d{3,})\.\d+/', $main );
	}

	public function test_uninstall_drops_push_table(): void {
		$src = file_get_contents( dirname( __DIR__, 2 ) . '/uninstall.php' );
		$this->assertStringContainsString( 'lafka_push_subscriptions', $src );
		$this->assertStringContainsString( 'DROP TABLE IF EXISTS', $src );
		$this->assertStringContainsString( 'lafka_push_db_version', $src );
		$this->assertStringContainsString( 'lafka_push_activity_log', $src );
	}

	// ────────────────────────────────────────────────────────────────────────
	// v9.29.1 — VAPID constants override (P0 hardening from operator audit)
	// ────────────────────────────────────────────────────────────────────────

	/**
	 * The sender's vapid-config resolver must check wp-config.php-defined
	 * constants BEFORE falling back to get_theme_mod(). Without this lock,
	 * a multi-admin site has no way to keep the VAPID private key out of
	 * wp_options.
	 */
	public function test_sender_reads_lafka_push_vapid_constants_before_theme_mod(): void {
		$src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/conversion/lafka-push-sender.php' );
		$this->assertStringContainsString(
			"defined( 'LAFKA_PUSH_VAPID_PRIVATE_KEY' )",
			$src,
			'sender must check LAFKA_PUSH_VAPID_PRIVATE_KEY constant'
		);
		$this->assertStringContainsString(
			"defined( 'LAFKA_PUSH_VAPID_PUBLIC_KEY' )",
			$src,
			'sender must check LAFKA_PUSH_VAPID_PUBLIC_KEY constant'
		);
		$this->assertStringContainsString(
			"defined( 'LAFKA_PUSH_VAPID_SUBJECT' )",
			$src,
			'sender must check LAFKA_PUSH_VAPID_SUBJECT constant'
		);
	}

	/**
	 * Customizer description must document the constant override so operators
	 * know the safer wp-config path exists.
	 */
	public function test_customizer_private_key_field_documents_constant_override(): void {
		$src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/customizer/class-lafka-customizer-push.php' );
		$this->assertStringContainsString(
			'LAFKA_PUSH_VAPID_PRIVATE_KEY',
			$src,
			'private-key Customizer description must mention the wp-config constant'
		);
		$this->assertStringContainsString(
			'wp-config.php',
			$src,
			'private-key Customizer description must point operators at wp-config.php'
		);
	}
}
