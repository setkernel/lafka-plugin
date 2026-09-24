<?php
/**
 * PushNotificationsTest — Web Push module: subscription persistence (insert,
 * endpoint dedupe, soft/hard delete), REST route permission wiring and payload
 * validation, the public VAPID-key endpoint, wp-config constant precedence for
 * the VAPID keys, Customizer sanitizers, the crypto helpers (b64url, HKDF),
 * reorder/cleanup cron scheduling, and audience resolution.
 *
 * @package Lafka\Plugin\Tests\Unit
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Lafka_Customizer_Push;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
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

require_once __DIR__ . '/Stubs/wp-error-class.php';
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
		// Subscribe rate limiter: never limited here (PushNoncePermissionTest owns it).
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );

		unset( $_SERVER['HTTP_X_WP_NONCE'], $_REQUEST['_wpnonce'] );

		// Reset the fake DB.
		global $wpdb;
		$wpdb = new FakePushWpdb();
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'], $_SERVER['HTTP_X_WP_NONCE'], $_REQUEST['_wpnonce'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	// ─────────────────────────────────────────────────────────────────────────
	// 1. Persistence
	// ─────────────────────────────────────────────────────────────────────────

	public function test_save_subscription_inserts_a_row_using_only_schema_columns(): void {
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

		// Every column the writer persists must exist in the CREATE TABLE.
		$sql     = \lafka_push_schema_sql();
		$missing = array_values(
			array_filter(
				array_diff( array_keys( $wpdb->rows[0] ), array( 'id' ) ),
				static fn( $col ) => 1 !== preg_match( '/^\s+' . preg_quote( $col, '/' ) . '\s/m', $sql )
			)
		);
		$this->assertSame( array(), $missing, 'Inserted columns missing from lafka_push_schema_sql().' );
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

	public function test_rest_routes_require_a_nonce_for_writes_and_expose_the_key_publicly(): void {
		Functions\when( 'get_theme_mod' )->alias(
			static fn( $key, $default = null ) => 'lafka_push_enabled' === $key ? '1' : $default
		);
		$routes = array();
		Functions\when( 'register_rest_route' )->alias(
			static function ( $ns, $route, $args ) use ( &$routes ) {
				$routes[ $ns . $route ] = $args;
				return true;
			}
		);
		\lafka_push_register_rest_routes();

		$this->assertSame(
			array( 'lafka/v1/push/subscribe', 'lafka/v1/push/unsubscribe', 'lafka/v1/push/vapid-key' ),
			array_keys( $routes )
		);
		// The write routes' registered permission callback must refuse a request
		// that carries no nonce (guests included).
		foreach ( array( 'lafka/v1/push/subscribe', 'lafka/v1/push/unsubscribe' ) as $route ) {
			$this->assertInstanceOf( 'WP_Error', call_user_func( $routes[ $route ]['permission_callback'], null ), $route );
		}
		$this->assertSame( '__return_true', $routes['lafka/v1/push/vapid-key']['permission_callback'] );
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
		global $wpdb;
		$this->assertSame( $wpdb->rows[0]['id'], $response['subscription_id'] );
		$this->assertSame( 'https://fcm.googleapis.com/fcm/send/abc123', $wpdb->rows[0]['endpoint'] );
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

	/**
	 * wp-config.php constants must win over theme_mods so a multi-admin site can
	 * keep the VAPID private key out of wp_options. Separate process: the
	 * constants are process-global.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_vapid_config_prefers_wp_config_constants_over_theme_mods(): void {
		define( 'LAFKA_PUSH_VAPID_PUBLIC_KEY', 'CONST_PUBLIC' );
		define( 'LAFKA_PUSH_VAPID_PRIVATE_KEY', 'CONST_PRIVATE' );
		define( 'LAFKA_PUSH_VAPID_SUBJECT', 'mailto:const@example.test' );
		Functions\when( 'get_theme_mod' )->alias(
			static fn( $key, $default = null ) => array(
				'lafka_push_enabled'           => '1',
				'lafka_push_vapid_public_key'  => 'MOD_PUBLIC',
				'lafka_push_vapid_private_key' => 'MOD_PRIVATE',
				'lafka_push_vapid_subject'     => 'mailto:mod@example.test',
			)[ $key ] ?? $default
		);

		$this->assertSame(
			array(
				'enabled' => true,
				'public'  => 'CONST_PUBLIC',
				'private' => 'CONST_PRIVATE',
				'subject' => 'mailto:const@example.test',
			),
			\lafka_push_get_vapid_config()
		);
	}

	public function test_vapid_subject_defaults_to_the_site_admin_email(): void {
		Functions\when( 'get_option' )->alias( static fn( $key, $default = false ) => 'admin_email' === $key ? 'owner@example.test' : $default );
		Functions\when( 'apply_filters' )->returnArg( 2 );

		// Unset, and the placeholder older Customizer builds pre-filled.
		foreach ( array( '', 'mailto:operator@site.com' ) as $stored ) {
			Functions\when( 'get_theme_mod' )->alias(
				static fn( $key, $default = null ) => 'lafka_push_vapid_subject' === $key ? $stored : $default
			);
			$this->assertSame( 'mailto:owner@example.test', \lafka_push_get_vapid_config()['subject'], "Stored: '{$stored}'" );
		}

		Functions\when( 'apply_filters' )->alias( static fn( $tag, $value ) => 'lafka_push_default_vapid_subject' === $tag ? 'https://example.test/contact' : $value );
		$this->assertSame( 'https://example.test/contact', \lafka_push_get_vapid_config()['subject'] );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// 3. Customizer sanitizers
	// ─────────────────────────────────────────────────────────────────────────

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
	// 4. Crypto helpers (b64url + HKDF)
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

	public function test_hkdf_matches_rfc5869_test_vector(): void {
		// RFC 5869 Appendix A.1 (SHA-256), first 32 bytes of the OKM — the
		// length Web Push content encryption derives.
		$out = \lafka_push_hkdf(
			str_repeat( "\x0b", 22 ),
			(string) hex2bin( '000102030405060708090a0b0c' ),
			(string) hex2bin( 'f0f1f2f3f4f5f6f7f8f9' ),
			32
		);
		$this->assertSame( '3cb25f25faacd57a90434f64d0362f2a2d2d0a90cf1a5a4c5db02d56ecc4c5bf', bin2hex( $out ) );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// 5. Reorder cron + audience resolver
	// ─────────────────────────────────────────────────────────────────────────

	public function test_reorder_schedule_stays_inert_when_push_disabled(): void {
		// get_theme_mod default stub returns '0' → push OFF (its default).
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\expect( 'wp_schedule_event' )->never();

		\lafka_push_reorder_schedule_event();
		$this->assertTrue( true ); // Mockery's never() above is the real assertion.
	}

	public function test_reorder_schedule_drops_stale_events_when_disabled(): void {
		Functions\when( 'wp_next_scheduled' )->justReturn( 1234567890 );
		Functions\expect( 'wp_schedule_event' )->never();
		$cleared = array();
		Functions\when( 'wp_clear_scheduled_hook' )->alias(
			static function ( $hook ) use ( &$cleared ) {
				$cleared[] = $hook;
			}
		);

		\lafka_push_reorder_schedule_event();

		$this->assertContains( 'lafka_push_reorder_reminder', $cleared, 'A former opt-in must be unscheduled once push is off.' );
	}

	public function test_reorder_schedule_schedules_when_push_enabled(): void {
		Functions\when( 'get_theme_mod' )->alias(
			static fn( $key, $default = '' ) => 'lafka_push_enabled' === $key ? '1' : $default
		);
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		$scheduled = array();
		Functions\when( 'wp_schedule_event' )->alias(
			static function ( $ts, $recurrence, $hook ) use ( &$scheduled ) {
				$scheduled[] = $hook;
				return true;
			}
		);

		\lafka_push_reorder_schedule_event();

		$this->assertContains( 'lafka_push_reorder_reminder', $scheduled );
		$this->assertContains( 'lafka_push_cleanup_subscriptions', $scheduled );
	}

	public function test_push_maybe_install_table_inert_when_disabled(): void {
		$read = array();
		Functions\when( 'get_option' )->alias(
			static function ( $key, $default = false ) use ( &$read ) {
				$read[] = $key;
				return $default;
			}
		);

		\lafka_push_maybe_install_table();

		$this->assertNotContains( 'lafka_push_db_version', $read, 'Disabled module must not probe/install its table.' );
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

	public function test_resolve_audience_recent_customers_dedupes_and_drops_guests(): void {
		$customer_by_order = array(
			101 => 7,
			102 => 0, // guest checkout
			103 => 7, // repeat customer
			104 => 9,
		);
		Functions\when( 'wc_get_orders' )->justReturn( array_keys( $customer_by_order ) );
		Functions\when( 'wc_get_order' )->alias(
			static function ( $id ) use ( $customer_by_order ) {
				return new class( $customer_by_order[ $id ] ) {
					private int $cid;
					public function __construct( int $cid ) {
						$this->cid = $cid;
					}
					public function get_customer_id(): int {
						return $this->cid;
					}
				};
			}
		);
		$this->assertSame( array( 7, 9 ), \lafka_push_resolve_audience( 'recent_customers' ) );
	}
}
