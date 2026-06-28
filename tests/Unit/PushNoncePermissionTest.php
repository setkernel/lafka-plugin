<?php
/**
 * PushNoncePermissionTest — locks down the guest-nonce + rate-limit hardening
 * for the Phase 3E Web Push REST endpoints (audit f050).
 *
 *   - lafka_push_rest_nonce_permission(): now verifies a wp_rest nonce itself
 *     instead of trusting WP core's rest_cookie_check_errors (which silently
 *     skips the nonce check for cookie-less / guest requests). A missing or
 *     invalid nonce must be denied even though guests are allowed; a valid
 *     nonce — supplied via the X-WP-Nonce header, the raw HTTP header, or a
 *     _wpnonce param — must pass.
 *   - lafka_push_rest_subscribe_rate_limited(): per-IP transient cap that blunts
 *     mass junk-row injection.
 *   - lafka_push_rest_subscribe(): returns 429 'rate_limited' once the cap is
 *     hit, before touching the DB.
 *
 * @package Lafka\Plugin\Tests\Unit
 * @since   9.29.3
 */

declare(strict_types=1);

namespace {
	// WP_Error is referenced as `\WP_Error` from the un-namespaced REST source.
	// Guard against another test file having already declared it.
	if ( ! class_exists( '\WP_Error' ) ) {
		class WP_Error { // phpcs:ignore
			public string $code;
			public string $message;
			/** @var array */
			public array $data;
			public function __construct( $code = '', $message = '', $data = array() ) {
				$this->code    = (string) $code;
				$this->message = (string) $message;
				$this->data    = is_array( $data ) ? $data : array();
			}
			public function get_error_message() {
				return $this->message;
			}
		}
	}
}

namespace LafkaPlugin\Tests\Unit {

	use Brain\Monkey;
	use Brain\Monkey\Functions;
	use PHPUnit\Framework\Attributes\PreserveGlobalState;
	use PHPUnit\Framework\Attributes\RunInSeparateProcess;
	use PHPUnit\Framework\TestCase;
	use WP_Error;

	if ( ! defined( 'LAFKA_TESTING' ) ) {
		define( 'LAFKA_TESTING', true );
	}
	if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
		define( 'HOUR_IN_SECONDS', 3600 );
	}

	require_once dirname( __DIR__, 2 ) . '/incl/conversion/lafka-push-rest.php';

	final class PushNoncePermissionTest extends TestCase {

		protected function setUp(): void {
			parent::setUp();
			Monkey\setUp();
			Functions\when( '__' )->returnArg();
			Functions\when( 'sanitize_text_field' )->returnArg();
			Functions\when( 'wp_unslash' )->returnArg();
			Functions\when( 'apply_filters' )->returnArg( 2 );
			Functions\when( 'get_current_user_id' )->justReturn( 0 );
			Functions\when( 'get_locale' )->justReturn( 'en_US' );
			// Feature ON by default; individual tests can override.
			Functions\when( 'get_theme_mod' )->alias(
				static function ( $key, $default = null ) {
					return 'lafka_push_enabled' === $key ? '1' : $default;
				}
			);
			// Only the literal 'valid-nonce' token passes verification.
			Functions\when( 'wp_verify_nonce' )->alias(
				static function ( $nonce, $action ) {
					return ( 'valid-nonce' === $nonce && 'wp_rest' === $action ) ? 1 : false;
				}
			);
			// Rate-limit transients: not-limited by default.
			Functions\when( 'get_transient' )->justReturn( false );
			Functions\when( 'set_transient' )->justReturn( true );

			unset( $_SERVER['HTTP_X_WP_NONCE'], $_REQUEST['_wpnonce'], $_SERVER['REMOTE_ADDR'] );
		}

		protected function tearDown(): void {
			unset( $_SERVER['HTTP_X_WP_NONCE'], $_REQUEST['_wpnonce'], $_SERVER['REMOTE_ADDR'] );
			Monkey\tearDown();
			parent::tearDown();
		}

		/**
		 * Tiny request double exposing get_header() like WP_REST_Request.
		 */
		private function request_with_header( $value ) {
			return new class( $value ) {
				/** @var mixed */
				private $value;
				public function __construct( $value ) {
					$this->value = $value;
				}
				public function get_header( $name ) {
					return ( 'X-WP-Nonce' === $name ) ? $this->value : null;
				}
			};
		}

		// ─────────────────────────────────────────────────────────────────────
		// Nonce enforcement
		// ─────────────────────────────────────────────────────────────────────

		public function test_permission_allows_valid_header_nonce(): void {
			$result = \lafka_push_rest_nonce_permission( $this->request_with_header( 'valid-nonce' ) );
			$this->assertTrue( $result );
		}

		public function test_permission_denies_missing_nonce_for_guest(): void {
			// This is the core regression: a guest with NO nonce header must be
			// rejected. The old callback returned bare true here.
			$result = \lafka_push_rest_nonce_permission( $this->request_with_header( null ) );
			$this->assertNotTrue( $result );
			$this->assertInstanceOf( WP_Error::class, $result );
		}

		public function test_permission_denies_invalid_nonce(): void {
			$result = \lafka_push_rest_nonce_permission( $this->request_with_header( 'bogus' ) );
			$this->assertNotTrue( $result );
			$this->assertInstanceOf( WP_Error::class, $result );
		}

		public function test_permission_denies_empty_string_nonce(): void {
			$result = \lafka_push_rest_nonce_permission( $this->request_with_header( '' ) );
			$this->assertNotTrue( $result );
			$this->assertInstanceOf( WP_Error::class, $result );
		}

		public function test_permission_accepts_raw_http_header_fallback(): void {
			// No get_header() accessor (null request) — must read $_SERVER.
			$_SERVER['HTTP_X_WP_NONCE'] = 'valid-nonce';
			$result                     = \lafka_push_rest_nonce_permission( null );
			$this->assertTrue( $result );
		}

		public function test_permission_accepts_wpnonce_request_param_fallback(): void {
			$_REQUEST['_wpnonce'] = 'valid-nonce';
			$result               = \lafka_push_rest_nonce_permission( null );
			$this->assertTrue( $result );
		}

		public function test_permission_denies_when_feature_disabled_even_with_valid_nonce(): void {
			Functions\when( 'get_theme_mod' )->alias(
				static function ( $key, $default = null ) {
					return 'lafka_push_enabled' === $key ? '0' : $default;
				}
			);
			$result = \lafka_push_rest_nonce_permission( $this->request_with_header( 'valid-nonce' ) );
			$this->assertNotTrue( $result );
			$this->assertInstanceOf( WP_Error::class, $result );
		}

		// ─────────────────────────────────────────────────────────────────────
		// Per-IP rate limit
		// ─────────────────────────────────────────────────────────────────────

		public function test_rate_limit_passes_under_cap(): void {
			Functions\when( 'get_transient' )->justReturn( 3 );
			$this->assertFalse( \lafka_push_rest_subscribe_rate_limited() );
		}

		public function test_rate_limit_blocks_at_or_over_cap(): void {
			Functions\when( 'get_transient' )->justReturn( 10 );
			$this->assertTrue( \lafka_push_rest_subscribe_rate_limited() );
		}

		public function test_rate_limit_disabled_when_filter_returns_zero(): void {
			Functions\when( 'get_transient' )->justReturn( 999 );
			Functions\when( 'apply_filters' )->alias(
				static function ( $tag, $value ) {
					return 'lafka_push_subscribe_rate_limit' === $tag ? 0 : $value;
				}
			);
			$this->assertFalse( \lafka_push_rest_subscribe_rate_limited() );
		}

		public function test_subscribe_returns_429_when_rate_limited(): void {
			Functions\when( 'get_transient' )->justReturn( 10 );
			$req      = $this->request_with_payload(
				array(
					'endpoint' => 'https://fcm.googleapis.com/fcm/send/abc',
					'keys'     => array(
						'p256dh' => 'BNxxxlongbase64urlkey-yes',
						'auth'   => 'authsecret_base64',
					),
				)
			);
			$response = \lafka_push_rest_subscribe( $req );
			$this->assertIsArray( $response );
			$this->assertFalse( $response['ok'] );
			$this->assertSame( 'rate_limited', $response['code'] );
		}

		#[RunInSeparateProcess]
		#[PreserveGlobalState( false )]
		public function test_subscribe_proceeds_when_under_cap(): void {
			Functions\when( 'get_transient' )->justReturn( false );
			Functions\when( 'lafka_push_save_subscription' )->justReturn( 5 );
			$req      = $this->request_with_payload(
				array(
					'endpoint' => 'https://fcm.googleapis.com/fcm/send/abc',
					'keys'     => array(
						'p256dh' => 'BNxxxlongbase64urlkey-yes',
						'auth'   => 'authsecret_base64',
					),
				)
			);
			$response = \lafka_push_rest_subscribe( $req );
			$this->assertIsArray( $response );
			$this->assertTrue( $response['ok'] );
			$this->assertSame( 5, $response['subscription_id'] );
		}

		/**
		 * Request double exposing get_json_params()/get_params() like the subscribe
		 * handler expects.
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
	}
}
