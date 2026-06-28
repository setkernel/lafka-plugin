<?php
/**
 * PushUnsubscribeOwnershipTest — locks down the IDOR fix on the Phase 3E Web
 * Push unsubscribe route (audit f079).
 *
 * Before the fix, POST /lafka/v1/push/unsubscribe marked the row matching the
 * posted `endpoint` as unsubscribed with no proof that the caller owned it: the
 * shared wp_rest nonce (which every guest possesses) was the only gate, so
 * anyone who learned another subscriber's opaque endpoint URL could disable
 * their push notifications.
 *
 * The fix binds unsubscribe to the per-subscription `auth` secret carried by the
 * browser PushSubscription (sent on subscribe as keys.auth):
 *
 *   - keys.auth is now REQUIRED; its absence is a 400 invalid_payload.
 *   - The supplied auth is hash_equals()-compared against the stored row's auth;
 *     a mismatch — or a non-existent endpoint — yields a uniform 403 forbidden
 *     (no existence oracle) and the row is NOT touched.
 *   - For logged-in callers, the row's user_id must equal get_current_user_id();
 *     this blocks one member from unsubscribing another member's row.
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

require_once dirname( __DIR__, 2 ) . '/incl/conversion/lafka-push-db.php';
require_once dirname( __DIR__, 2 ) . '/incl/conversion/lafka-push-rest.php';

/**
 * Minimal in-process $wpdb stand-in supporting the upsert + lookup + soft-delete
 * paths the unsubscribe flow exercises. Named distinctly from FakePushWpdb in
 * PushNotificationsTest so both test files can coexist in one process.
 */
class FakePushOwnershipWpdb {

	public string $prefix = 'wp_';
	/** @var array<int,array> */
	public array $rows = array();
	public int $next_id = 1;
	public int $insert_id = 0;
	/** @var array */
	public array $last_query_args = array();

	public function prepare( $sql, ...$args ) {
		$this->last_query_args = $args;
		return $sql;
	}

	public function get_var( $sql ) {
		$endpoint = $this->last_query_args[0] ?? '';
		foreach ( $this->rows as $row ) {
			if ( isset( $row['endpoint'] ) && $row['endpoint'] === $endpoint ) {
				return (int) $row['id'];
			}
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

	public function insert( $table, $data, $formats = null ) {
		$id              = $this->next_id++;
		$data['id']      = $id;
		$this->rows[]    = $data;
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
}

final class PushUnsubscribeOwnershipTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'current_time' )->justReturn( '2026-06-28 12:00:00' );
		Functions\when( 'get_current_user_id' )->justReturn( 0 );

		global $wpdb;
		$wpdb = new FakePushOwnershipWpdb();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Request double exposing get_json_params()/get_params() like the handler
	 * expects.
	 *
	 * @param array $payload
	 */
	private function request_with_payload( array $payload ) {
		return new class( $payload ) {
			/** @var array */
			private $payload;
			public function __construct( array $payload ) {
				$this->payload = $payload;
			}
			public function get_json_params() {
				return $this->payload;
			}
			public function get_params() {
				return array();
			}
		};
	}

	// ──────────────────────────────────────────────────────────────────────
	// Happy path
	// ──────────────────────────────────────────────────────────────────────

	public function test_unsubscribe_succeeds_with_matching_auth(): void {
		global $wpdb;
		\lafka_push_save_subscription( 'https://fcm.googleapis.com/fcm/send/abc', 'pub', 'secret_auth', 0 );

		$req      = $this->request_with_payload(
			array(
				'endpoint' => 'https://fcm.googleapis.com/fcm/send/abc',
				'keys'     => array( 'auth' => 'secret_auth' ),
			)
		);
		$response = \lafka_push_rest_unsubscribe( $req );

		$this->assertIsArray( $response );
		$this->assertTrue( $response['ok'] );
		$this->assertSame( 1, $response['removed'] );
		$this->assertNotEmpty( $wpdb->rows[0]['unsubscribed_at'] );
	}

	public function test_unsubscribe_accepts_top_level_auth_fallback(): void {
		\lafka_push_save_subscription( 'https://fcm.googleapis.com/fcm/send/abc', 'pub', 'secret_auth', 0 );

		$req      = $this->request_with_payload(
			array(
				'endpoint' => 'https://fcm.googleapis.com/fcm/send/abc',
				'auth'     => 'secret_auth',
			)
		);
		$response = \lafka_push_rest_unsubscribe( $req );

		$this->assertTrue( $response['ok'] );
		$this->assertSame( 1, $response['removed'] );
	}

	// ──────────────────────────────────────────────────────────────────────
	// IDOR: wrong / missing secret
	// ──────────────────────────────────────────────────────────────────────

	public function test_unsubscribe_rejects_wrong_auth_and_leaves_row_active(): void {
		global $wpdb;
		\lafka_push_save_subscription( 'https://fcm.googleapis.com/fcm/send/abc', 'pub', 'secret_auth', 0 );

		$req      = $this->request_with_payload(
			array(
				'endpoint' => 'https://fcm.googleapis.com/fcm/send/abc',
				'keys'     => array( 'auth' => 'attacker_guess' ),
			)
		);
		$response = \lafka_push_rest_unsubscribe( $req );

		$this->assertIsArray( $response );
		$this->assertFalse( $response['ok'] );
		$this->assertSame( 'forbidden', $response['code'] );
		// The victim's row must remain active.
		$this->assertArrayHasKey( 'unsubscribed_at', $wpdb->rows[0] );
		$this->assertEmpty( $wpdb->rows[0]['unsubscribed_at'] );
	}

	public function test_unsubscribe_requires_auth(): void {
		\lafka_push_save_subscription( 'https://fcm.googleapis.com/fcm/send/abc', 'pub', 'secret_auth', 0 );

		$req      = $this->request_with_payload(
			array( 'endpoint' => 'https://fcm.googleapis.com/fcm/send/abc' )
		);
		$response = \lafka_push_rest_unsubscribe( $req );

		$this->assertFalse( $response['ok'] );
		$this->assertSame( 'invalid_payload', $response['code'] );
	}

	public function test_unsubscribe_requires_endpoint(): void {
		$req      = $this->request_with_payload(
			array( 'keys' => array( 'auth' => 'secret_auth' ) )
		);
		$response = \lafka_push_rest_unsubscribe( $req );

		$this->assertFalse( $response['ok'] );
		$this->assertSame( 'invalid_payload', $response['code'] );
	}

	public function test_unsubscribe_unknown_endpoint_returns_uniform_forbidden(): void {
		// No row stored at all — must look identical to a wrong-secret rejection
		// so the route can't be probed for which endpoints exist.
		$req      = $this->request_with_payload(
			array(
				'endpoint' => 'https://fcm.googleapis.com/fcm/send/does-not-exist',
				'keys'     => array( 'auth' => 'anything' ),
			)
		);
		$response = \lafka_push_rest_unsubscribe( $req );

		$this->assertFalse( $response['ok'] );
		$this->assertSame( 'forbidden', $response['code'] );
	}

	// ──────────────────────────────────────────────────────────────────────
	// Member-to-member binding
	// ──────────────────────────────────────────────────────────────────────

	public function test_logged_in_user_cannot_unsubscribe_another_members_row(): void {
		global $wpdb;
		// Row owned by user 42.
		\lafka_push_save_subscription( 'https://fcm.googleapis.com/fcm/send/abc', 'pub', 'secret_auth', 42 );
		// Caller is user 7, and even with the correct secret must be blocked.
		Functions\when( 'get_current_user_id' )->justReturn( 7 );

		$req      = $this->request_with_payload(
			array(
				'endpoint' => 'https://fcm.googleapis.com/fcm/send/abc',
				'keys'     => array( 'auth' => 'secret_auth' ),
			)
		);
		$response = \lafka_push_rest_unsubscribe( $req );

		$this->assertFalse( $response['ok'] );
		$this->assertSame( 'forbidden', $response['code'] );
		$this->assertEmpty( $wpdb->rows[0]['unsubscribed_at'] );
	}

	public function test_logged_in_user_can_unsubscribe_own_row(): void {
		\lafka_push_save_subscription( 'https://fcm.googleapis.com/fcm/send/abc', 'pub', 'secret_auth', 42 );
		Functions\when( 'get_current_user_id' )->justReturn( 42 );

		$req      = $this->request_with_payload(
			array(
				'endpoint' => 'https://fcm.googleapis.com/fcm/send/abc',
				'keys'     => array( 'auth' => 'secret_auth' ),
			)
		);
		$response = \lafka_push_rest_unsubscribe( $req );

		$this->assertTrue( $response['ok'] );
		$this->assertSame( 1, $response['removed'] );
	}

	public function test_logged_in_user_can_unsubscribe_guest_row_with_secret(): void {
		// Guest-owned row (user_id 0/null): a now logged-in visitor who holds the
		// secret may still unsubscribe it.
		\lafka_push_save_subscription( 'https://fcm.googleapis.com/fcm/send/abc', 'pub', 'secret_auth', 0 );
		Functions\when( 'get_current_user_id' )->justReturn( 9 );

		$req      = $this->request_with_payload(
			array(
				'endpoint' => 'https://fcm.googleapis.com/fcm/send/abc',
				'keys'     => array( 'auth' => 'secret_auth' ),
			)
		);
		$response = \lafka_push_rest_unsubscribe( $req );

		$this->assertTrue( $response['ok'] );
		$this->assertSame( 1, $response['removed'] );
	}
}
