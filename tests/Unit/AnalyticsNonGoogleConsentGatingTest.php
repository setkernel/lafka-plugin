<?php
/**
 * AnalyticsNonGoogleConsentGatingTest — locks down the consent gating fix for
 * the non-Google direct emitters (audit f043).
 *
 * Regression context: the direct (non-GTM) Meta Pixel emitter loaded
 * fbevents.js and immediately called fbq('init',…) + fbq('track','PageView'),
 * and the Clarity emitter injected the clarity.ms tag — both at wp_head:2 with
 * NO consent gating. Google Consent Mode v2 (the bundled banner) only governs
 * Google's gtag tags; Meta Pixel and Microsoft Clarity ignore it, so with the
 * banner's default 'denied' state the pixel still dropped _fbp/_fbc cookies and
 * sent a PageView, and Clarity still ran, before the visitor accepted anything.
 *
 * The fix drives both platforms from the same lafka_consent_v1 decision:
 *   - Meta Pixel: revoke BEFORE init, then only replay a PageView when a stored
 *     ad_storage===true decision already exists at load; the banner JS grants /
 *     revokes + fires the deferred PageView on an explicit accept.
 *   - Clarity: expose a lazy loader and only invoke it when a stored
 *     analytics_storage===true decision exists; the banner JS loads it on grant.
 *
 * @package Lafka\Plugin\Tests\Unit
 * @since   9.23.0
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/incl/analytics/lafka-analytics-emitter.php';
// The footer consent banner gates on lafka_analytics_is_active() (the destination
// gate, audit f083 — verified separately by AnalyticsBannerDestinationGateTest).
// It is defined in lafka-page-context.php and built on
// lafka_analytics_has_datalayer_destination() (lafka-wc-events.php) +
// lafka_analytics_cf_beacon_token() (lafka-cf-analytics.php), so the banner
// applyConsent tests must bring those into scope and configure a destination to
// satisfy the gate before asserting the banner JS that drives Meta + Clarity.
require_once dirname( __DIR__, 2 ) . '/incl/analytics/lafka-wc-events.php';
require_once dirname( __DIR__, 2 ) . '/incl/analytics/lafka-cf-analytics.php';
require_once dirname( __DIR__, 2 ) . '/incl/analytics/lafka-page-context.php';

final class AnalyticsNonGoogleConsentGatingTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'get_theme_mod' )->returnArg( 2 );
		Functions\when( 'wp_json_encode' )->alias( static fn( $v ) => json_encode( $v ) );
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_js' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_attr__' )->returnArg();
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'did_action' )->justReturn( 0 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @param array<string, string> $values
	 */
	private function stub_settings( array $values ): void {
		Functions\when( 'get_theme_mod' )->alias(
			static function ( $key, $default = null ) use ( $values ) {
				return array_key_exists( $key, $values ) ? $values[ $key ] : ( null === $default ? '' : $default );
			}
		);
	}

	private function capture( callable $fn ): string {
		ob_start();
		$fn();
		return (string) ob_get_clean();
	}

	// ────────────────────────────────────────────────────────────────────────
	// Meta Pixel head emit: revoke before init, PageView gated on stored grant
	// ────────────────────────────────────────────────────────────────────────

	public function test_meta_pixel_revokes_consent_before_init(): void {
		$this->stub_settings( array( 'lafka_meta_pixel_id' => '123456789012345' ) );
		$out = $this->capture( 'lafka_emit_direct_meta_pixel' );

		$this->assertStringContainsString( "fbq('consent', 'revoke')", $out, 'Meta Pixel must revoke consent so init drops no cookie / sends no beacon.' );

		$revoke_pos = strpos( $out, "fbq('consent', 'revoke')" );
		$init_pos   = strpos( $out, "fbq('init'" );
		$this->assertNotFalse( $revoke_pos );
		$this->assertNotFalse( $init_pos );
		$this->assertLessThan( $init_pos, $revoke_pos, 'consent revoke must run BEFORE fbq init.' );
	}

	public function test_meta_pixel_gates_pageview_on_stored_ad_storage(): void {
		$this->stub_settings( array( 'lafka_meta_pixel_id' => '123456789012345' ) );
		$out = $this->capture( 'lafka_emit_direct_meta_pixel' );

		// Reads the same key the banner persists.
		$this->assertStringContainsString( "localStorage.getItem('lafka_consent_v1')", $out );
		// PageView is gated behind a stored ad_storage===true decision.
		$this->assertMatchesRegularExpression( '/if\s*\(\s*d\s*&&\s*d\.ad_storage\s*\)/', $out );
		// Only inside that gate does it grant + fire the PageView.
		$this->assertStringContainsString( "fbq('consent', 'grant')", $out );
		$this->assertStringContainsString( "fbq('track', 'PageView')", $out );

		// The PageView call must come AFTER the ad_storage gate opens, never at
		// top level — the gate keyword must precede every track call.
		$gate_pos  = strpos( $out, 'd.ad_storage' );
		$track_pos = strpos( $out, "fbq('track', 'PageView')" );
		$this->assertNotFalse( $gate_pos );
		$this->assertNotFalse( $track_pos );
		$this->assertLessThan( $track_pos, $gate_pos, 'The PageView must be gated behind the ad_storage check.' );
	}

	public function test_meta_pixel_read_guards_against_parse_errors(): void {
		$this->stub_settings( array( 'lafka_meta_pixel_id' => '123456789012345' ) );
		$out = $this->capture( 'lafka_emit_direct_meta_pixel' );
		$this->assertStringContainsString( 'try {', $out );
		$this->assertStringContainsString( 'catch(e)', $out );
	}

	// ────────────────────────────────────────────────────────────────────────
	// Clarity head emit: not injected unconditionally; deferred behind consent
	// ────────────────────────────────────────────────────────────────────────

	public function test_clarity_tag_is_not_loaded_unconditionally(): void {
		$this->stub_settings( array( 'lafka_clarity_project_id' => 'abc123xyz' ) );
		$out = $this->capture( 'lafka_emit_direct_clarity' );

		// The external tag still appears, but only inside a lazy loader.
		$this->assertStringContainsString( 'clarity.ms/tag/', $out );
		$this->assertStringContainsString( 'window.lafkaLoadClarity', $out );

		// The loader is DEFINED before the tag src — the src must live inside it.
		$loader_pos = strpos( $out, 'window.lafkaLoadClarity = function' );
		$tag_pos    = strpos( $out, 'clarity.ms/tag/' );
		$this->assertNotFalse( $loader_pos );
		$this->assertNotFalse( $tag_pos );
		$this->assertLessThan( $tag_pos, $loader_pos, 'The clarity.ms tag must be inside the lazy loader, not at top level.' );
	}

	public function test_clarity_loader_invoked_only_when_stored_analytics_granted(): void {
		$this->stub_settings( array( 'lafka_clarity_project_id' => 'abc123xyz' ) );
		$out = $this->capture( 'lafka_emit_direct_clarity' );

		$this->assertStringContainsString( "localStorage.getItem('lafka_consent_v1')", $out );
		$this->assertMatchesRegularExpression(
			'/if\s*\(\s*d\s*&&\s*d\.analytics_storage\s*\)\s*\{\s*window\.lafkaLoadClarity\(\)/',
			$out,
			'Clarity must only load when a stored analytics_storage===true decision exists at load.'
		);
	}

	public function test_clarity_read_guards_against_parse_errors(): void {
		$this->stub_settings( array( 'lafka_clarity_project_id' => 'abc123xyz' ) );
		$out = $this->capture( 'lafka_emit_direct_clarity' );
		$this->assertStringContainsString( 'try {', $out );
		$this->assertStringContainsString( 'catch(e)', $out );
	}

	// ────────────────────────────────────────────────────────────────────────
	// Banner applyConsent: drives Meta + Clarity from the explicit decision
	// ────────────────────────────────────────────────────────────────────────

	public function test_banner_applyconsent_drives_meta_pixel(): void {
		// A configured destination satisfies the banner's lafka_analytics_is_active()
		// gate so the banner JS is emitted (gate itself covered by the dedicated
		// AnalyticsBannerDestinationGateTest).
		$this->stub_settings( array(
			'lafka_consent_banner_enabled' => '1',
			'lafka_gtm_container_id'       => 'GTM-XYZ987',
		) );
		$out = $this->capture( 'lafka_emit_consent_banner' );

		// Grant/revoke mirrors the ad_storage decision.
		$this->assertStringContainsString( "window.fbq('consent', state.ad_storage ? 'grant' : 'revoke')", $out );
		// A PageView fires once ad_storage is granted (deduped against head emit).
		$this->assertStringContainsString( "window.fbq('track','PageView')", $out );
		$this->assertMatchesRegularExpression(
			'/if\s*\(\s*state\.ad_storage\s*&&\s*!\s*window\._lafkaFbPageView\s*\)/',
			$out,
			'The banner PageView must be deduped against the head-emit flag.'
		);
	}

	public function test_banner_applyconsent_loads_clarity_on_analytics_grant(): void {
		$this->stub_settings( array(
			'lafka_consent_banner_enabled' => '1',
			'lafka_gtm_container_id'       => 'GTM-XYZ987',
		) );
		$out = $this->capture( 'lafka_emit_consent_banner' );

		$this->assertMatchesRegularExpression(
			'/if\s*\(\s*state\.analytics_storage\s*&&\s*typeof\s*window\.lafkaLoadClarity\s*===\s*.function.\s*\)/',
			$out,
			'Clarity must load from applyConsent only when analytics_storage is granted.'
		);
		$this->assertStringContainsString( 'window.lafkaLoadClarity();', $out );
	}
}
