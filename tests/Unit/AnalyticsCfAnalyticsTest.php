<?php
declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Tracking foundation: Cloudflare Web Analytics beacon.
 */
final class AnalyticsCfAnalyticsTest extends TestCase {

	private string $src;

	protected function setUp(): void {
		$this->src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/analytics/lafka-cf-analytics.php' );
	}

	public function test_token_accessor_validates_32_hex(): void {
		$this->assertStringContainsString( 'function lafka_analytics_cf_beacon_token', $this->src );
		$this->assertMatchesRegularExpression( "/\\^\[a-f0-9\]\{32\}\\\$/", $this->src,
			'CF token must validate against 32 lowercase hex chars.' );
		$this->assertStringContainsString( "get_theme_mod( 'lafka_cf_beacon_token'", $this->src );
	}

	public function test_emits_on_wp_footer_when_token_set(): void {
		$this->assertMatchesRegularExpression(
			"/add_action\(\s*'wp_footer',\s*'lafka_analytics_emit_cf_beacon'/",
			$this->src
		);
		$this->assertStringContainsString( 'static.cloudflareinsights.com/beacon.min.js', $this->src );
		$this->assertStringContainsString( 'data-cf-beacon', $this->src );
	}

	public function test_no_op_when_token_empty(): void {
		// Must bail when token resolves to '' (keeps OSS plugin account-free).
		$this->assertMatchesRegularExpression( "/if\s*\(\s*''\s*===\s*\\\$token\s*\)\s*\{\s*return;/", $this->src );
	}

	public function test_beacon_is_cookieless_not_consent_gated(): void {
		// The CF beacon is privacy-first/cookieless, so it must NOT call the
		// consent-state accessor or be gated on Consent Mode the way GTM/GA4 are.
		// (The word "consent" may appear in comments explaining this.)
		$this->assertStringNotContainsString( 'lafka_analytics_consent', $this->src,
			'CF beacon must not gate on Consent Mode (it is cookieless).' );
		$this->assertStringNotContainsString( "wp_script_is( 'gtm", $this->src );
	}
}
