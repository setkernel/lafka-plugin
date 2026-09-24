<?php
/**
 * Returning-visitor consent replay (audit f007). Consent Mode v2 defaults emit
 * 'denied' on every page; a stored decision must be replayed (in <head>,
 * inside the wait_for_update window, and again by the footer banner JS) or a
 * visitor who accepted is silently downgraded to denied on every later page.
 * Registration order (replay after defaults) is covered in AnalyticsEmitterTest.
 *
 * @package Lafka\Plugin\Tests\Unit
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

	public function test_banner_js_replays_stored_decision_and_only_shows_banner_without_one(): void {
		$this->stub_settings( array(
			'lafka_consent_banner_enabled' => '1',
			'lafka_gtm_container_id'       => 'GTM-XYZ987',
		) );
		$out = $this->capture( 'lafka_emit_consent_banner' );
		$this->assertMatchesRegularExpression(
			'/if\s*\(\s*existing\s*\)\s*\{\s*applyConsent\(\s*existing\s*\);\s*\}\s*else\s*\{\s*showBanner\(\);/',
			$out,
			'On load the stored decision must be re-applied; the banner shows only when none is stored.'
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
}
