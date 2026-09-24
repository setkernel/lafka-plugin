<?php
/**
 * SecurityHeadersTest — Lafka_Security_Headers: opt-in default, the default
 * header map + Permissions-Policy, the filterable emission contract, and the
 * user-enumeration hardening (REST users endpoints, ?author=N probes).
 *
 * @package Lafka\Plugin\Tests\Unit
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Lafka_Security_Headers;
use PHPUnit\Framework\TestCase;
use RuntimeException;

// Load the real Lafka_Options first so the bootstrap's stub (guarded by
// class_exists) never shadows it for later test files that require the real one.
require_once dirname( __DIR__, 2 ) . '/incl/class-lafka-options.php';
require_once __DIR__ . '/Stubs/security-headers-bootstrap.php';

final class SecurityHeadersTest extends TestCase {

	private function module_src(): string {
		return file_get_contents( dirname( __DIR__, 2 ) . '/incl/security/class-lafka-security-headers.php' );
	}

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		\Lafka_Options::flush(); // Drop any 'lafka' option array cached by an earlier test.
		// instance() → __construct() → is_active() → get_option(). Stub to
		// return empty so should_default_on() owns the result (false).
		Functions\when( 'get_option' )->returnArg( 2 );
	}

	protected function tearDown(): void {
		unset( $_GET['author'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	// ────────────────────────────────────────────────────────────────────────
	// Opt-in default
	// ────────────────────────────────────────────────────────────────────────

	public function test_default_off_until_explicitly_enabled(): void {
		// Critical opt-in invariant. Silently enabling on upgrade can break
		// Stripe / payment-gateway return iframes (X-Frame-Options
		// SAMEORIGIN) — a worse failure mode than leaving headers off until
		// an admin acknowledges them.
		$headers = Lafka_Security_Headers::instance();
		$this->assertFalse( $headers->is_active(), 'Module must default OFF — operators flip the toggle explicitly.' );

		Functions\when( 'get_option' )->justReturn( array( Lafka_Security_Headers::TOGGLE_OPTION_KEY => 'enabled' ) );
		$this->assertTrue( $headers->is_active(), 'The admin toggle turns it on.' );
	}

	// ────────────────────────────────────────────────────────────────────────
	// Header map + Permissions-Policy
	// ────────────────────────────────────────────────────────────────────────

	public function test_default_headers_map_includes_baseline_four(): void {
		// Pre-v9.7.12 these were emitted directly via header() — now they
		// flow through a filterable map. The four baseline headers must
		// remain present in the default.
		$headers = Lafka_Security_Headers::get_default_headers();
		$this->assertArrayHasKey( 'X-Content-Type-Options', $headers );
		$this->assertSame( 'nosniff', $headers['X-Content-Type-Options'] );
		$this->assertArrayHasKey( 'X-Frame-Options', $headers );
		$this->assertSame( 'SAMEORIGIN', $headers['X-Frame-Options'] );
		$this->assertArrayHasKey( 'Referrer-Policy', $headers );
		$this->assertArrayHasKey( 'Permissions-Policy', $headers );
	}

	public function test_permissions_policy_denies_sensors_in_a_comma_separated_list(): void {
		// Default-deny on sensors a restaurant frontend has no business prompting
		// for; the header grammar requires a comma-separated list.
		$directives = array_map( 'trim', explode( ',', Lafka_Security_Headers::get_default_permissions_policy() ) );
		foreach ( array( 'camera=()', 'microphone=()', 'geolocation=()', 'payment=()', 'interest-cohort=()' ) as $deny ) {
			$this->assertContains( $deny, $directives );
		}
		foreach ( $directives as $directive ) {
			$this->assertMatchesRegularExpression( '/^[a-z-]+=\(\)$/', $directive );
		}
	}

	public function test_built_headers_are_the_filtered_map_as_header_lines(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );
		$lines = Lafka_Security_Headers::build_headers();
		$this->assertContains( 'X-Content-Type-Options: nosniff', $lines );
		$this->assertContains( 'X-Frame-Options: SAMEORIGIN', $lines );
		$this->assertCount( count( Lafka_Security_Headers::get_default_headers() ), $lines );
	}

	public function test_the_filter_adds_removes_and_cannot_smuggle_headers(): void {
		// Child plugins add CSP/HSTS and drop headers through this filter.
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $headers ) {
				if ( 'lafka_security_headers' !== $hook ) {
					return $headers;
				}
				unset( $headers['X-Frame-Options'] );
				$headers['Strict-Transport-Security'] = 'max-age=31536000';
				$headers['Referrer-Policy']           = '';
				$headers['X-Injected']                = "1\r\nSet-Cookie: pwned=1";
				$headers[0]                           = 'numeric-key';
				$headers['X-Array']                   = array( 'no' );
				return $headers;
			}
		);
		$lines = Lafka_Security_Headers::build_headers();

		$this->assertContains( 'Strict-Transport-Security: max-age=31536000', $lines );
		$this->assertContains( 'X-Content-Type-Options: nosniff', $lines );
		foreach ( $lines as $line ) {
			$this->assertDoesNotMatchRegularExpression( '/^(X-Frame-Options|Referrer-Policy|X-Injected|X-Array|0):/', $line );
			$this->assertStringNotContainsString( "\n", $line );
		}
	}

	public function test_sender_strips_x_powered_by(): void {
		// send_security_headers() bails on headers_sent(), which is always true
		// under PHPUnit (and CLI keeps no header list), so this one line of the
		// sender is pinned by source; everything it sends is build_headers().
		$this->assertStringContainsString( "header_remove( 'X-Powered-By' )", $this->module_src() );
	}

	// ────────────────────────────────────────────────────────────────────────
	// User-enumeration hardening
	// ────────────────────────────────────────────────────────────────────────

	public function test_rest_user_endpoints_removed(): void {
		// Both the collection and per-id endpoint must come out — leaving
		// either lets an unauthenticated client enumerate usernames via
		// the REST API.
		$instance = Lafka_Security_Headers::instance();
		$method   = ( new \ReflectionClass( $instance ) )->getMethod( 'disable_user_enum_rest' );

		$endpoints = array(
			'/wp/v2/users'                  => 'collection-handler',
			'/wp/v2/users/(?P<id>[\d]+)'    => 'item-handler',
			'/wp/v2/posts'                  => 'unrelated-handler',
		);
		$result    = $method->invoke( $instance, $endpoints );

		$this->assertArrayNotHasKey( '/wp/v2/users', $result );
		$this->assertArrayNotHasKey( '/wp/v2/users/(?P<id>[\d]+)', $result );
		$this->assertArrayHasKey( '/wp/v2/posts', $result, 'Unrelated REST endpoints must not be touched.' );
	}

	public function test_author_probe_redirects_guests_home(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		Functions\when( 'is_author' )->justReturn( false );
		Functions\when( 'home_url' )->alias( static fn( $path = '' ) => 'https://example.test' . $path );
		$redirect = array();
		Functions\when( 'wp_safe_redirect' )->alias(
			static function ( $url, $status ) use ( &$redirect ) {
				$redirect = array( $url, $status );
				throw new RuntimeException( 'redirect' ); // stand-in for the exit that follows.
			}
		);
		$_GET['author'] = '1';

		try {
			Lafka_Security_Headers::instance()->block_author_enum();
			$this->fail( 'A guest ?author=N probe must be redirected.' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( array( 'https://example.test/', 301 ), $redirect );
		}
	}

	public function test_author_probe_is_allowed_for_logged_in_users(): void {
		// Admin previews rely on author URLs.
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\expect( 'wp_safe_redirect' )->never();
		$_GET['author'] = '1';

		Lafka_Security_Headers::instance()->block_author_enum();
		$this->addToAssertionCount( 1 );
	}
}
