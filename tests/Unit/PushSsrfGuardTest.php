<?php
/**
 * PushSsrfGuardTest — locks down the SSRF hardening for the Phase 3E Web Push
 * module (audit f013):
 *
 *   - lafka_push_endpoint_host_allowed(): accepts the real push providers
 *     (FCM / Apple / Mozilla / Windows), rejects private/internal/arbitrary
 *     hosts, and honours the `lafka_push_endpoint_host_allowlist` filter.
 *   - lafka_push_rest_subscribe(): rejects an internal-host endpoint at the REST
 *     boundary with code 'invalid_endpoint_host'.
 *   - lafka_push_is_safe_remote_host(): rejects private/reserved IP literals
 *     (incl. the 169.254.169.254 cloud-metadata host and ::1) and accepts a
 *     publicly-routable literal; fails closed on an unresolvable host.
 *   - lafka_push_http_post(): blocks non-https URLs and private-IP hosts before
 *     ever calling cURL, and source-pins the cURL hardening (HTTPS-only
 *     protocols, no redirect following).
 *   - lafka_push_send(): belt-and-suspenders host guard refuses to send to a
 *     row whose endpoint host is not an allowed provider.
 *
 * @package Lafka\Plugin\Tests\Unit
 * @since   9.29.2
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;
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

require_once dirname( __DIR__, 2 ) . '/incl/conversion/lafka-push-rest.php';
require_once dirname( __DIR__, 2 ) . '/incl/conversion/lafka-push-sender.php';

final class PushSsrfGuardTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'sanitize_text_field' )->returnArg();
		// Default: the filter is a pass-through (returns the value argument).
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		Functions\when( 'get_locale' )->justReturn( 'en_US' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ─────────────────────────────────────────────────────────────────────────
	// 1. Host allowlist
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * @return array<string,array{0:string}>
	 */
	public static function provider_allowed_hosts(): array {
		return array(
			'fcm'                => array( 'https://fcm.googleapis.com/fcm/send/abc123' ),
			'apple_web'          => array( 'https://web.push.apple.com/QABC...' ),
			'apple_wildcard'     => array( 'https://api.push.apple.com/3/device/xyz' ),
			'windows_wildcard'   => array( 'https://db5p.notify.windows.com/w/?token=abc' ),
			'mozilla'            => array( 'https://updates.push.services.mozilla.com/wpush/v2/gAA' ),
			'host_case_insens'   => array( 'https://FCM.GoogleAPIs.COM/fcm/send/abc' ),
		);
	}

	#[DataProvider( 'provider_allowed_hosts' )]
	public function test_endpoint_host_allowed_accepts_real_providers( string $endpoint ): void {
		$this->assertTrue( \lafka_push_endpoint_host_allowed( $endpoint ) );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public static function provider_blocked_hosts(): array {
		return array(
			'metadata_ip'        => array( 'https://169.254.169.254/latest/meta-data/' ),
			'private_ip_port'    => array( 'https://10.0.0.5:8080/internal' ),
			'loopback_name'      => array( 'https://localhost/admin' ),
			'loopback_ip'        => array( 'https://127.0.0.1/' ),
			'private_192'        => array( 'https://192.168.1.1/router' ),
			'ipv6_loopback'      => array( 'https://[::1]/x' ),
			'arbitrary_domain'   => array( 'https://attacker.example.com/collect' ),
			'lookalike_suffix'   => array( 'https://fcm.googleapis.com.evil.example/x' ),
			'bare_apple_base'    => array( 'https://push.apple.com/x' ),
			'no_host'            => array( 'https:///path-only' ),
			'empty'              => array( '' ),
		);
	}

	#[DataProvider( 'provider_blocked_hosts' )]
	public function test_endpoint_host_allowed_rejects_unsafe_hosts( string $endpoint ): void {
		$this->assertFalse( \lafka_push_endpoint_host_allowed( $endpoint ) );
	}

	public function test_endpoint_host_allowlist_filter_can_extend(): void {
		Functions\when( 'apply_filters' )->alias(
			static function ( $tag, $value ) {
				if ( 'lafka_push_endpoint_host_allowlist' === $tag && is_array( $value ) ) {
					$value[] = 'push.regional.example.net';
				}
				return $value;
			}
		);
		$this->assertTrue( \lafka_push_endpoint_host_allowed( 'https://push.regional.example.net/send/1' ) );
		// A host still not on the (extended) list stays blocked.
		$this->assertFalse( \lafka_push_endpoint_host_allowed( 'https://other.example.net/send/1' ) );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// 2. REST subscribe boundary
	// ─────────────────────────────────────────────────────────────────────────

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_rest_subscribe_rejects_internal_host(): void {
		Functions\when( 'get_theme_mod' )->alias(
			static function ( $key, $default = null ) {
				return 'lafka_push_enabled' === $key ? '1' : $default;
			}
		);
		$req = new class() {
			public function get_json_params() {
				return array(
					'endpoint' => 'https://169.254.169.254/latest/meta-data/',
					'keys'     => array(
						'p256dh' => 'BNxxxlongbase64urlkey-yes',
						'auth'   => 'authsecret_base64',
					),
				);
			}
			public function get_params() {
				return array();
			}
		};
		$response = \lafka_push_rest_subscribe( $req );
		$this->assertIsArray( $response );
		$this->assertFalse( $response['ok'] );
		$this->assertSame( 'invalid_endpoint_host', $response['code'] );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_rest_subscribe_still_accepts_provider_host(): void {
		Functions\when( 'get_theme_mod' )->alias(
			static function ( $key, $default = null ) {
				return 'lafka_push_enabled' === $key ? '1' : $default;
			}
		);
		// Persist path needs a fake $wpdb; stub save to avoid DB coupling.
		Functions\when( 'lafka_push_save_subscription' )->justReturn( 7 );
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
				return array();
			}
		};
		$response = \lafka_push_rest_subscribe( $req );
		$this->assertIsArray( $response );
		$this->assertTrue( $response['ok'] );
		$this->assertSame( 7, $response['subscription_id'] );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// 3. IP-range guard (cURL sender)
	// ─────────────────────────────────────────────────────────────────────────

	/**
	 * @return array<string,array{0:string}>
	 */
	public static function provider_unsafe_ip_literals(): array {
		return array(
			'metadata'  => array( '169.254.169.254' ),
			'private_10'=> array( '10.0.0.5' ),
			'private_192' => array( '192.168.1.1' ),
			'private_172' => array( '172.16.0.1' ),
			'loopback'  => array( '127.0.0.1' ),
			'ipv6_loop' => array( '::1' ),
			'ipv6_brk'  => array( '[::1]' ),
		);
	}

	#[DataProvider( 'provider_unsafe_ip_literals' )]
	public function test_is_safe_remote_host_rejects_private_and_reserved( string $host ): void {
		$this->assertFalse( \lafka_push_is_safe_remote_host( $host ) );
	}

	/**
	 * @return array<string,array{0:string}>
	 */
	public static function provider_safe_ip_literals(): array {
		return array(
			'google_dns_v4' => array( '8.8.8.8' ),
			'cloudflare_v4' => array( '1.1.1.1' ),
			'google_dns_v6' => array( '2001:4860:4860::8888' ),
		);
	}

	#[DataProvider( 'provider_safe_ip_literals' )]
	public function test_is_safe_remote_host_accepts_public_literals( string $host ): void {
		$this->assertTrue( \lafka_push_is_safe_remote_host( $host ) );
	}

	public function test_is_safe_remote_host_fails_closed_on_unresolvable(): void {
		// RFC 6761 guarantees `.invalid` never resolves.
		$this->assertFalse( \lafka_push_is_safe_remote_host( 'definitely-not-real.invalid' ) );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// 4. http_post boundary + cURL hardening
	// ─────────────────────────────────────────────────────────────────────────

	public function test_http_post_blocks_non_https_url(): void {
		$res = \lafka_push_http_post( 'http://fcm.googleapis.com/fcm/send/abc', array(), 'body' );
		$this->assertSame( 0, $res['http_code'] );
		$this->assertSame( 'blocked_url', $res['body'] );
	}

	public function test_http_post_blocks_private_ip_host(): void {
		$res = \lafka_push_http_post( 'https://10.0.0.5:8080/internal', array(), 'body' );
		$this->assertSame( 0, $res['http_code'] );
		$this->assertSame( 'blocked_host', $res['body'] );
	}

	public function test_http_post_blocks_metadata_host(): void {
		$res = \lafka_push_http_post( 'https://169.254.169.254/latest/', array(), 'body' );
		$this->assertSame( 0, $res['http_code'] );
		$this->assertSame( 'blocked_host', $res['body'] );
	}

	public function test_http_post_honours_override_filter(): void {
		Functions\when( 'apply_filters' )->alias(
			static function ( $tag, $value ) {
				if ( 'lafka_push_http_post' === $tag ) {
					return array(
						'http_code' => 201,
						'body'      => 'ok',
					);
				}
				return $value;
			}
		);
		$res = \lafka_push_http_post( 'https://10.0.0.5/internal', array(), 'body' );
		$this->assertSame( 201, $res['http_code'] );
	}

	public function test_sender_source_pins_curl_hardening(): void {
		$src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/conversion/lafka-push-sender.php' );
		$this->assertStringContainsString( 'FILTER_FLAG_NO_PRIV_RANGE', $src );
		$this->assertStringContainsString( 'FILTER_FLAG_NO_RES_RANGE', $src );
		$this->assertStringContainsString( 'CURLOPT_PROTOCOLS', $src );
		$this->assertStringContainsString( 'CURLOPT_REDIR_PROTOCOLS', $src );
		$this->assertStringContainsString( 'CURLOPT_FOLLOWLOCATION', $src );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// 5. lafka_push_send belt-and-suspenders guard
	// ─────────────────────────────────────────────────────────────────────────

	public function test_send_refuses_disallowed_host_row(): void {
		Functions\when( 'get_theme_mod' )->alias(
			static function ( $key, $default = null ) {
				$map = array(
					'lafka_push_enabled'           => '1',
					'lafka_push_vapid_public_key'  => 'PUBLICKEY',
					'lafka_push_vapid_private_key' => 'PRIVATEKEY',
				);
				return $map[ $key ] ?? $default;
			}
		);
		Functions\when( 'get_bloginfo' )->justReturn( 'op@example.com' );

		$row = (object) array(
			'endpoint' => 'https://attacker.example.com/collect',
			'p256dh'   => 'pub',
			'auth'     => 'auth',
		);
		$res = \lafka_push_send( $row, array( 'title' => 'hi' ) );
		$this->assertFalse( $res['ok'] );
		$this->assertSame( 'host_not_allowed', $res['response'] );
	}
}
