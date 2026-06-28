<?php
/**
 * PushHeartbeatCleanupTest — locks down the deliverability-heartbeat fix
 * (audit f070).
 *
 * The daily cleanup (lafka_push_cleanup, run via lafka_push_cleanup_subscriptions)
 * used to hard-delete rows where `unsubscribed_at` was old OR `last_seen_at` was
 * older than the window. But lafka_push_send() never refreshed `last_seen_at` on
 * a successful delivery — it was only written at subscribe time — so a subscriber
 * who kept receiving pushes but never re-ran the browser subscribe flow within 60
 * days was silently hard-deleted as "stale", shrinking the deliverable audience.
 *
 * This suite asserts the two-part fix:
 *
 *   - lafka_push_cleanup() prunes ONLY soft-deleted rows by age
 *     (`unsubscribed_at IS NOT NULL AND unsubscribed_at < window`) and never
 *     deletes active rows on `last_seen_at` age alone.
 *   - lafka_push_send() refreshes `last_seen_at` on a 2xx delivery (the
 *     documented "or send" heartbeat) and leaves it untouched on a soft failure.
 *
 * @package Lafka\Plugin\Tests\Unit
 * @since   9.29.4
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
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

require_once dirname( __DIR__, 2 ) . '/incl/conversion/lafka-push-db.php';
require_once dirname( __DIR__, 2 ) . '/incl/conversion/lafka-push-sender.php';

/**
 * Captures the DELETE that lafka_push_cleanup() runs so the test can assert the
 * exact predicate (and the number of bound args) without a real database.
 */
class FakeCleanupWpdb {

	public string $prefix = 'wp_';
	public string $last_query = '';
	/** @var array<int,mixed> */
	public array $last_args = array();
	public int $delete_return = 0;

	public function prepare( $sql, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		$this->last_args = $args;
		// Interpolate %d placeholders so the executed query is fully assertable.
		foreach ( $args as $a ) {
			$sql = preg_replace( '/%d/', (string) (int) $a, $sql, 1 );
		}
		return $sql;
	}

	public function query( $sql ) {
		$this->last_query = (string) $sql;
		return $this->delete_return;
	}
}

/**
 * Records last_seen_at UPDATEs issued by lafka_push_send() and applies them to an
 * in-memory row set so the heartbeat can be asserted both at the call boundary
 * and on the persisted row.
 */
class FakeHeartbeatWpdb {

	public string $prefix = 'wp_';
	/** @var array<int,array<string,mixed>> */
	public array $rows = array();
	/** @var array<int,array<string,mixed>> */
	public array $updates = array();

	public function update( $table, $data, $where, $formats = null, $where_formats = null ) {
		$this->updates[] = array(
			'table' => $table,
			'data'  => $data,
			'where' => $where,
		);
		$count = 0;
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
				++$count;
			}
		}
		unset( $row );
		return $count;
	}

	public function delete( $table, $where, $formats = null ) {
		return 0;
	}
}

final class PushHeartbeatCleanupTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		Functions\when( 'get_locale' )->justReturn( 'en_US' );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'get_theme_mod' )->returnArg( 2 );
		Functions\when( 'current_time' )->justReturn( '2026-06-28 09:00:00' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ─────────────────────────────────────────────────────────────────────────
	// 1. cleanup prunes only soft-deleted rows
	// ─────────────────────────────────────────────────────────────────────────

	public function test_cleanup_targets_only_soft_deleted_rows(): void {
		global $wpdb;
		$wpdb                = new FakeCleanupWpdb();
		$wpdb->delete_return = 4;

		$deleted = \lafka_push_cleanup( 60 );

		$this->assertSame( 4, $deleted );
		$this->assertStringContainsString( 'unsubscribed_at IS NOT NULL', $wpdb->last_query );
		$this->assertStringContainsString( 'unsubscribed_at < DATE_SUB', $wpdb->last_query );
	}

	public function test_cleanup_never_prunes_active_rows_on_last_seen_at(): void {
		global $wpdb;
		$wpdb = new FakeCleanupWpdb();

		\lafka_push_cleanup( 60 );

		// The regression: active (deliverable) rows must NOT be deleted on
		// last_seen_at age. The DELETE must reference neither last_seen_at nor an
		// OR branch that would catch active rows.
		$this->assertStringNotContainsString( 'last_seen_at', $wpdb->last_query );
		$this->assertStringNotContainsString( ' OR ', $wpdb->last_query );
	}

	public function test_cleanup_binds_the_window_exactly_once(): void {
		global $wpdb;
		$wpdb = new FakeCleanupWpdb();

		\lafka_push_cleanup( 30 );

		// One %d placeholder now → exactly one bound arg (it was bound twice while
		// the OR last_seen_at branch existed).
		$this->assertCount( 1, $wpdb->last_args );
		$this->assertSame( 30, (int) $wpdb->last_args[0] );
		$this->assertStringContainsString( 'INTERVAL 30 DAY', $wpdb->last_query );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// 2. send refreshes the heartbeat on success, not on failure
	// ─────────────────────────────────────────────────────────────────────────

	public function test_send_refreshes_last_seen_at_on_successful_delivery(): void {
		$keys = self::make_webpush_keys();
		if ( null === $keys ) {
			$this->markTestSkipped( 'OpenSSL P-256 support required to exercise the send path.' );
		}

		$this->stub_enabled_vapid( $keys['vapid_private'] );
		$this->stub_http_post_status( 201 );

		global $wpdb;
		$wpdb         = new FakeHeartbeatWpdb();
		$endpoint     = 'https://fcm.googleapis.com/fcm/send/heartbeat-row';
		$wpdb->rows[] = array(
			'id'              => 1,
			'endpoint'        => $endpoint,
			'p256dh'          => $keys['p256dh'],
			'auth'            => $keys['auth'],
			'last_seen_at'    => '2026-01-01 00:00:00',
			'unsubscribed_at' => null,
		);

		$res = \lafka_push_send(
			(object) $wpdb->rows[0],
			array(
				'title' => 'Lunch deal',
				'body'  => 'Two for one today.',
			)
		);

		$this->assertTrue( $res['ok'], 'A 2xx response must mark the send ok.' );
		$this->assertNotEmpty( $wpdb->updates, 'A successful send must issue a last_seen_at UPDATE.' );

		$last = end( $wpdb->updates );
		$this->assertArrayHasKey( 'last_seen_at', $last['data'] );
		$this->assertSame( '2026-06-28 09:00:00', $last['data']['last_seen_at'] );
		$this->assertSame( $endpoint, $last['where']['endpoint'], 'Heartbeat must key on the unique endpoint.' );
		$this->assertSame(
			'2026-06-28 09:00:00',
			$wpdb->rows[0]['last_seen_at'],
			'The persisted row heartbeat must be bumped in place.'
		);
	}

	public function test_send_does_not_refresh_last_seen_at_on_soft_failure(): void {
		$keys = self::make_webpush_keys();
		if ( null === $keys ) {
			$this->markTestSkipped( 'OpenSSL P-256 support required to exercise the send path.' );
		}

		$this->stub_enabled_vapid( $keys['vapid_private'] );
		$this->stub_http_post_status( 500 );

		global $wpdb;
		$wpdb         = new FakeHeartbeatWpdb();
		$wpdb->rows[] = array(
			'id'              => 1,
			'endpoint'        => 'https://fcm.googleapis.com/fcm/send/soft-fail-row',
			'p256dh'          => $keys['p256dh'],
			'auth'            => $keys['auth'],
			'last_seen_at'    => '2026-01-01 00:00:00',
			'unsubscribed_at' => null,
		);

		$res = \lafka_push_send(
			(object) $wpdb->rows[0],
			array( 'title' => 'Lunch deal' )
		);

		$this->assertFalse( $res['ok'], 'A 5xx response must not mark the send ok.' );
		$this->assertEmpty( $wpdb->updates, 'A failed send must not touch the heartbeat.' );
		$this->assertSame( '2026-01-01 00:00:00', $wpdb->rows[0]['last_seen_at'] );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// helpers
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * Enable push + supply a real VAPID private key via the theme-mod path.
	 */
	private function stub_enabled_vapid( string $vapid_private ): void {
		Functions\when( 'get_theme_mod' )->alias(
			static function ( $key, $default = null ) use ( $vapid_private ) {
				$map = array(
					'lafka_push_enabled'           => '1',
					'lafka_push_vapid_public_key'  => 'BPublicKeyUsedOnlyInHeader',
					'lafka_push_vapid_private_key' => $vapid_private,
					'lafka_push_vapid_subject'     => 'mailto:op@example.com',
				);
				return $map[ $key ] ?? $default;
			}
		);
		Functions\when( 'get_bloginfo' )->justReturn( 'op@example.com' );
	}

	/**
	 * Short-circuit the network: make lafka_push_http_post return $code without
	 * touching cURL, while leaving every other filter as a pass-through.
	 */
	private function stub_http_post_status( int $code ): void {
		Functions\when( 'apply_filters' )->alias(
			static function ( $tag, $value = null ) use ( $code ) {
				if ( 'lafka_push_http_post' === $tag ) {
					return array(
						'http_code' => $code,
						'body'      => 'stubbed',
					);
				}
				return $value;
			}
		);
	}

	/**
	 * Generate a real VAPID private key + a real subscriber (UA) keypair so
	 * lafka_push_send() can build a valid JWT and encrypt the payload, reaching
	 * the 2xx heartbeat branch. Returns null if OpenSSL EC is unavailable.
	 *
	 * @return array{vapid_private:string,p256dh:string,auth:string}|null
	 */
	private static function make_webpush_keys(): ?array {
		if ( ! function_exists( 'openssl_pkey_new' ) || ! function_exists( 'openssl_pkey_derive' ) ) {
			return null;
		}
		$b64url = static function ( string $bytes ): string {
			return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
		};

		$vapid = @openssl_pkey_new(
			array(
				'private_key_type' => OPENSSL_KEYTYPE_EC,
				'curve_name'       => 'prime256v1',
			)
		);
		if ( false === $vapid ) {
			return null;
		}
		$vd = @openssl_pkey_get_details( $vapid );
		if ( ! is_array( $vd ) || empty( $vd['ec']['d'] ) ) {
			return null;
		}
		$vapid_private = $b64url( str_pad( (string) $vd['ec']['d'], 32, "\x00", STR_PAD_LEFT ) );

		$sub = @openssl_pkey_new(
			array(
				'private_key_type' => OPENSSL_KEYTYPE_EC,
				'curve_name'       => 'prime256v1',
			)
		);
		if ( false === $sub ) {
			return null;
		}
		$sd = @openssl_pkey_get_details( $sub );
		if ( ! is_array( $sd ) || empty( $sd['ec']['x'] ) || empty( $sd['ec']['y'] ) ) {
			return null;
		}
		$pub = "\x04"
			. str_pad( (string) $sd['ec']['x'], 32, "\x00", STR_PAD_LEFT )
			. str_pad( (string) $sd['ec']['y'], 32, "\x00", STR_PAD_LEFT );

		return array(
			'vapid_private' => $vapid_private,
			'p256dh'        => $b64url( $pub ),
			'auth'          => $b64url( random_bytes( 16 ) ),
		);
	}
}
