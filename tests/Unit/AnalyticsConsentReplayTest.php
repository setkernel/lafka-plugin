<?php
/**
 * AnalyticsConsentReplayTest — locks down the returning-visitor consent replay
 * fix (audit f007).
 *
 * Regression context: Consent Mode v2 defaults emit 'denied' at wp_head:1 on
 * every page load. Originally the footer banner JS only ran applyConsent()
 * inside the accept/reject/save click handlers and, on load, did
 * `if (!existing){ showBanner(); }` — so a returning visitor with a stored
 * decision had their grants read but never replayed to gtag, leaving every
 * subsequent page stuck at the 'denied' default.
 *
 * The fix is two-layered:
 *   1. A head replay (lafka_emit_consent_replay) fires gtag('consent','update')
 *      from the persisted localStorage decision INSIDE the wait_for_update
 *      window (wp_head:1, right after the defaults).
 *   2. The footer banner JS re-applies the stored decision on load
 *      (`if (existing){ applyConsent(existing); } else { showBanner(); }`).
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
// lafka_analytics_cf_beacon_token() (lafka-cf-analytics.php), so the banner-JS
// tests must bring those into scope and configure a destination to satisfy the
// gate before asserting the banner JS replay logic.
require_once dirname( __DIR__, 2 ) . '/incl/analytics/lafka-wc-events.php';
require_once dirname( __DIR__, 2 ) . '/incl/analytics/lafka-cf-analytics.php';
require_once dirname( __DIR__, 2 ) . '/incl/analytics/lafka-page-context.php';

final class AnalyticsConsentReplayTest extends TestCase {

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
	// Footer banner JS: replays the stored decision on load
	// ────────────────────────────────────────────────────────────────────────

	public function test_banner_js_applies_stored_consent_on_load(): void {
		// The core regression: on load, when a decision is already stored the
		// banner JS MUST call applyConsent(existing) — not skip straight to the
		// no-op `if (!existing)` branch that left returning visitors denied.
		// A configured destination satisfies the banner's lafka_analytics_is_active()
		// gate so the banner JS is emitted (gate itself covered by the dedicated
		// AnalyticsBannerDestinationGateTest).
		$this->stub_settings( array(
			'lafka_consent_banner_enabled' => '1',
			'lafka_gtm_container_id'       => 'GTM-XYZ987',
		) );
		$out = $this->capture( 'lafka_emit_consent_banner' );
		$this->assertMatchesRegularExpression(
			'/if\s*\(\s*existing\s*\)\s*\{\s*applyConsent\(\s*existing\s*\)/',
			$out,
			'Banner JS must replay the stored decision via applyConsent(existing) on load.'
		);
	}

	public function test_banner_js_only_shows_banner_when_no_stored_decision(): void {
		// showBanner() must be the *else* of the stored-decision check, so a
		// returning visitor with a decision never re-sees the banner.
		$this->stub_settings( array(
			'lafka_consent_banner_enabled' => '1',
			'lafka_gtm_container_id'       => 'GTM-XYZ987',
		) );
		$out = $this->capture( 'lafka_emit_consent_banner' );
		$this->assertMatchesRegularExpression(
			'/\}\s*else\s*\{\s*showBanner\(\);/',
			$out,
			'showBanner() must be gated behind the "no stored decision" else branch.'
		);
		// The old, broken `if (!existing){ showBanner(); }` form must be gone.
		$this->assertDoesNotMatchRegularExpression(
			'/if\s*\(\s*!\s*existing\s*\)\s*\{\s*showBanner/',
			$out,
			'The broken on-load branch (if(!existing){showBanner();}) must be removed.'
		);
	}

	// ────────────────────────────────────────────────────────────────────────
	// Head replay: fires gtag consent update from localStorage in <head>
	// ────────────────────────────────────────────────────────────────────────

	public function test_head_replay_emits_consent_update_from_localstorage(): void {
		$this->stub_settings( array( 'lafka_consent_banner_enabled' => '1' ) );
		$out = $this->capture( 'lafka_emit_consent_replay' );
		$this->assertStringContainsString( 'id="lafka-consent-replay"', $out );
		// Reads the same key the banner persists.
		$this->assertStringContainsString( "localStorage.getItem('lafka_consent_v1')", $out );
		// Replays the decision to gtag inside the wait_for_update window.
		$this->assertStringContainsString( "gtag('consent','update'", $out );
		// Maps each of the four categories.
		$this->assertStringContainsString( 'analytics_storage', $out );
		$this->assertStringContainsString( 'ad_storage', $out );
		$this->assertStringContainsString( 'ad_user_data', $out );
		$this->assertStringContainsString( 'ad_personalization', $out );
	}

	public function test_head_replay_suppressed_when_banner_disabled(): void {
		$this->stub_settings( array( 'lafka_consent_banner_enabled' => '0' ) );
		$out = $this->capture( 'lafka_emit_consent_replay' );
		$this->assertSame( '', $out, 'Head replay must no-op when the consent banner feature is off.' );
	}

	public function test_head_replay_guards_against_parse_errors(): void {
		// Private-mode / corrupt-JSON access must not throw; the script wraps the
		// read in try/catch so a failure simply leaves the denied defaults intact.
		$this->stub_settings( array( 'lafka_consent_banner_enabled' => '1' ) );
		$out = $this->capture( 'lafka_emit_consent_replay' );
		$this->assertStringContainsString( 'try {', $out );
		$this->assertStringContainsString( 'catch(e)', $out );
	}

	// ────────────────────────────────────────────────────────────────────────
	// Hook registration: replay runs at wp_head:1, after the defaults
	// ────────────────────────────────────────────────────────────────────────

	public function test_head_replay_hooked_at_wp_head_priority_1(): void {
		$src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/analytics/lafka-analytics-emitter.php' );
		$this->assertMatchesRegularExpression(
			"/add_action\(\s*'wp_head',\s*'lafka_emit_consent_replay',\s*1\s*\)/",
			$src
		);
	}

	public function test_head_replay_registered_after_defaults(): void {
		// Order is load-bearing: the replay calls gtag(), which the defaults
		// emit defines. Same priority (1) means registration order decides
		// execution order, so the replay registration must come AFTER defaults.
		$src          = file_get_contents( dirname( __DIR__, 2 ) . '/incl/analytics/lafka-analytics-emitter.php' );
		$defaults_pos = strpos( $src, "add_action( 'wp_head', 'lafka_emit_consent_mode_defaults', 1 )" );
		$replay_pos   = strpos( $src, "add_action( 'wp_head', 'lafka_emit_consent_replay', 1 )" );
		$this->assertNotFalse( $defaults_pos );
		$this->assertNotFalse( $replay_pos );
		$this->assertGreaterThan( $defaults_pos, $replay_pos );
	}
}
