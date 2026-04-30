<?php
/**
 * SecurityHeadersTest — locks down the Lafka_Security_Headers module
 * (P2-05a) plus its v9.7.12 filterable + extended-Permissions-Policy
 * surface.
 *
 * Module name says "security" — tests matter most here. Source-grep + a
 * functional test for the default-headers map suffice; emission itself
 * happens inside `send_headers` and is too tied to PHP's header() to test
 * cleanly without a bigger harness.
 *
 * @package Lafka\Plugin\Tests\Unit
 * @since   9.7.12
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Lafka_Security_Headers;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Stubs/security-headers-bootstrap.php';

final class SecurityHeadersTest extends TestCase {

	private function module_src(): string {
		return file_get_contents( dirname( __DIR__, 2 ) . '/incl/security/class-lafka-security-headers.php' );
	}

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// instance() → __construct() → is_active() → get_option(). Stub to
		// return empty so should_default_on() owns the result (false).
		Functions\when( 'get_option' )->returnArg( 2 );
	}

	protected function tearDown(): void {
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
		$this->assertFalse(
			Lafka_Security_Headers::should_default_on(),
			'Module must default OFF — operators flip the toggle explicitly.'
		);
	}

	// ────────────────────────────────────────────────────────────────────────
	// v9.7.12 — filterable headers + expanded Permissions-Policy
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

	public function test_permissions_policy_denies_camera_microphone_geolocation(): void {
		// Default-deny on sensors a typical restaurant frontend has no
		// business prompting for. Operators that genuinely need them
		// override via the lafka_security_headers filter.
		$policy = Lafka_Security_Headers::get_default_permissions_policy();
		$this->assertStringContainsString( 'camera=()', $policy );
		$this->assertStringContainsString( 'microphone=()', $policy );
		$this->assertStringContainsString( 'geolocation=()', $policy );
		$this->assertStringContainsString( 'payment=()', $policy );
		$this->assertStringContainsString( 'interest-cohort=()', $policy, 'FLoC opt-out (legacy default) must remain.' );
	}

	public function test_permissions_policy_directives_comma_separated(): void {
		// HTTP spec requires comma-separated list — sites can't read a
		// space-separated Permissions-Policy correctly.
		$policy = Lafka_Security_Headers::get_default_permissions_policy();
		$this->assertStringContainsString( ',', $policy );
		$this->assertDoesNotMatchRegularExpression(
			'/[a-z]\(\)\s+[a-z]+=\(/i',
			$policy,
			'Permissions-Policy directives must be comma-separated, not space-separated.'
		);
	}

	public function test_send_security_headers_passes_through_filter(): void {
		// Regression lock — child plugins rely on lafka_security_headers
		// to inject CSP / HSTS without forking. The filter must wrap the
		// header emission loop, not the per-header header() calls.
		$src = $this->module_src();
		$this->assertMatchesRegularExpression(
			"/apply_filters\(\s*\n?\s*'lafka_security_headers'\s*,\s*\\\$headers\s*\)/",
			$src,
			'send_security_headers must apply the lafka_security_headers filter.'
		);
	}

	public function test_xpowered_by_stripped(): void {
		// Defense-in-depth even though the right fix is `expose_php = Off`
		// in php.ini. Drop here so admins without ini access still benefit.
		$src = $this->module_src();
		$this->assertStringContainsString( "header_remove( 'X-Powered-By' )", $src );
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

	public function test_block_author_enum_redirects_unauthenticated_probes(): void {
		// `?author=N` query-arg enumeration is the legacy pre-REST attack;
		// must redirect logged-out users to home regardless of WP routing.
		$src = $this->module_src();
		$this->assertStringContainsString( "isset( \$_GET['author'] )", $src );
		$this->assertStringContainsString( 'wp_safe_redirect( home_url( \'/\' ), 301 )', $src );
		$this->assertMatchesRegularExpression(
			'/is_user_logged_in\(\)\s*\)\s*\{[^}]*return;/s',
			$src,
			'Logged-in users must be exempted from the redirect (admin previews need author URLs).'
		);
	}
}
