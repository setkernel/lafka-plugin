<?php
/**
 * AnalyticsEmitterTest — locks down the Phase 1A (v9.23.0) analytics layer:
 *
 *   - Customizer panel registers all required settings (source-grep)
 *   - Consent Mode v2 default state emits with the canonical four categories
 *     BEFORE any tag (priority 1 on wp_head, JSON-encoded payload)
 *   - GTM container head + body noscript emit when ID is set, absent when not
 *   - Direct GA4 / Clarity / Meta Pixel emit ONLY when GTM is empty AND their
 *     own ID is set (the override-not-additive rule)
 *   - GSC verification meta emits when token is set
 *   - Consent banner HTML emits when enabled, absent when disabled
 *   - Banner button labels honour the Customizer overrides
 *
 * @package Lafka\Plugin\Tests\Unit
 * @since   9.23.0
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Lafka_Customizer_Analytics;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/incl/customizer/class-lafka-customizer-analytics.php';
require_once dirname( __DIR__, 2 ) . '/incl/analytics/lafka-analytics-emitter.php';

final class AnalyticsEmitterTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// All emit functions read theme_mods; stub to default for each test
		// so a per-test override via stub_settings() owns the result.
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
	 * Stub the per-key theme_mod values consumed by the emit layer.
	 *
	 * @param array<string, string> $values
	 */
	private function stub_settings( array $values ): void {
		Functions\when( 'get_theme_mod' )->alias(
			static function ( $key, $default = null ) use ( $values ) {
				return array_key_exists( $key, $values ) ? $values[ $key ] : ( null === $default ? '' : $default );
			}
		);
	}

	/**
	 * Buffer an emit-function call and return what it printed.
	 */
	private function capture( callable $fn ): string {
		ob_start();
		$fn();
		return (string) ob_get_clean();
	}

	// ────────────────────────────────────────────────────────────────────────
	// 1. Customizer panel + settings registered (source-grep)
	// ────────────────────────────────────────────────────────────────────────

	public function test_customizer_registers_lafka_analytics_panel(): void {
		$src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/customizer/class-lafka-customizer-analytics.php' );
		$this->assertStringContainsString( "add_panel", $src );
		$this->assertStringContainsString( "'lafka_analytics'", $src );
	}

	public function test_customizer_registers_all_required_settings(): void {
		$src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/customizer/class-lafka-customizer-analytics.php' );
		$required = array(
			'lafka_gtm_container_id',
			'lafka_ga4_measurement_id',
			'lafka_clarity_project_id',
			'lafka_meta_pixel_id',
			'lafka_gsc_verification',
			'lafka_consent_banner_enabled',
			'lafka_consent_default_analytics',
			'lafka_consent_default_ad_storage',
			'lafka_consent_default_ad_user_data',
			'lafka_consent_default_ad_personalization',
			'lafka_consent_banner_text',
			'lafka_consent_banner_accept_label',
			'lafka_consent_banner_reject_label',
			'lafka_consent_banner_settings_label',
		);
		foreach ( $required as $setting ) {
			$this->assertStringContainsString(
				"'" . $setting . "'",
				$src,
				"Customizer setting {$setting} must be registered."
			);
		}
	}

	public function test_every_setting_has_default_and_sanitize_callback(): void {
		$src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/customizer/class-lafka-customizer-analytics.php' );
		// add_setting() calls without the `'default'` key would be a regression —
		// the safety contract is "every Customizer field has a default AND a
		// sanitize_callback" (see lafka feedback_customizer_first.md).
		$default_count   = substr_count( $src, "'default'" );
		$sanitize_count  = substr_count( $src, "'sanitize_callback'" );
		$add_setting_cnt = substr_count( $src, '$wp_customize->add_setting' );
		$this->assertGreaterThanOrEqual( $add_setting_cnt, $default_count, 'Every add_setting() call must include a default.' );
		$this->assertGreaterThanOrEqual( $add_setting_cnt, $sanitize_count, 'Every add_setting() call must include a sanitize_callback.' );
	}

	// ────────────────────────────────────────────────────────────────────────
	// 2. Sanitizer behaviour (functional)
	// ────────────────────────────────────────────────────────────────────────

	public function test_sanitize_gtm_container_id_accepts_valid_uppercases_and_rejects_invalid(): void {
		$this->assertSame( 'GTM-ABC123', Lafka_Customizer_Analytics::sanitize_gtm_container_id( 'gtm-abc123' ) );
		$this->assertSame( 'GTM-XYZ987', Lafka_Customizer_Analytics::sanitize_gtm_container_id( '  GTM-XYZ987  ' ) );
		$this->assertSame( '', Lafka_Customizer_Analytics::sanitize_gtm_container_id( 'XYZ-NOPREFIX' ) );
		$this->assertSame( '', Lafka_Customizer_Analytics::sanitize_gtm_container_id( 'GTM-' ) );
		$this->assertSame( '', Lafka_Customizer_Analytics::sanitize_gtm_container_id( '<script>alert(1)</script>' ) );
		$this->assertSame( '', Lafka_Customizer_Analytics::sanitize_gtm_container_id( array( 'GTM-X' ) ) );
	}

	public function test_sanitize_ga4_measurement_id_validates_g_prefix(): void {
		$this->assertSame( 'G-ABC1234567', Lafka_Customizer_Analytics::sanitize_ga4_measurement_id( 'g-abc1234567' ) );
		$this->assertSame( '', Lafka_Customizer_Analytics::sanitize_ga4_measurement_id( 'UA-12345-1' ) );
		$this->assertSame( '', Lafka_Customizer_Analytics::sanitize_ga4_measurement_id( 'G-' ) );
	}

	public function test_sanitize_meta_pixel_id_requires_15_to_16_digits(): void {
		$this->assertSame( '123456789012345', Lafka_Customizer_Analytics::sanitize_meta_pixel_id( '123456789012345' ) );
		$this->assertSame( '1234567890123456', Lafka_Customizer_Analytics::sanitize_meta_pixel_id( '1234567890123456' ) );
		$this->assertSame( '', Lafka_Customizer_Analytics::sanitize_meta_pixel_id( '12345' ) );
		$this->assertSame( '', Lafka_Customizer_Analytics::sanitize_meta_pixel_id( '12345678901234567' ) );
		$this->assertSame( '', Lafka_Customizer_Analytics::sanitize_meta_pixel_id( '12345abc12345ef' ) );
	}

	public function test_sanitize_consent_state_only_allows_denied_or_granted(): void {
		$this->assertSame( 'granted', Lafka_Customizer_Analytics::sanitize_consent_state( 'granted' ) );
		$this->assertSame( 'granted', Lafka_Customizer_Analytics::sanitize_consent_state( '  GRANTED  ' ) );
		$this->assertSame( 'denied', Lafka_Customizer_Analytics::sanitize_consent_state( 'denied' ) );
		// Default fallback for unknown / empty / array input.
		$this->assertSame( 'denied', Lafka_Customizer_Analytics::sanitize_consent_state( 'maybe' ) );
		$this->assertSame( 'denied', Lafka_Customizer_Analytics::sanitize_consent_state( '' ) );
		$this->assertSame( 'denied', Lafka_Customizer_Analytics::sanitize_consent_state( array( 'granted' ) ) );
	}

	public function test_sanitize_gsc_verification_strips_html_breakouts(): void {
		// Token must not be able to break out of the meta `content="..."`
		// attribute or sneak in an HTML tag.
		$out = Lafka_Customizer_Analytics::sanitize_gsc_verification( "abc123\"><script>alert(1)</script>" );
		$this->assertStringNotContainsString( '<', $out );
		$this->assertStringNotContainsString( '>', $out );
		$this->assertStringNotContainsString( '"', $out );
	}

	// ────────────────────────────────────────────────────────────────────────
	// 3. Consent Mode v2 default state emits with all four categories
	// ────────────────────────────────────────────────────────────────────────

	public function test_consent_mode_defaults_emits_canonical_four_categories(): void {
		$this->stub_settings( array(
			'lafka_consent_default_analytics'          => 'denied',
			'lafka_consent_default_ad_storage'         => 'denied',
			'lafka_consent_default_ad_user_data'       => 'denied',
			'lafka_consent_default_ad_personalization' => 'denied',
		) );
		$out = $this->capture( 'lafka_emit_consent_mode_defaults' );
		// gtag('consent', 'default', {...}) is the v2 canonical call form.
		$this->assertStringContainsString( "gtag('consent','default'", $out );
		$this->assertStringContainsString( '"analytics_storage":"denied"', $out );
		$this->assertStringContainsString( '"ad_storage":"denied"', $out );
		$this->assertStringContainsString( '"ad_user_data":"denied"', $out );
		$this->assertStringContainsString( '"ad_personalization":"denied"', $out );
	}

	public function test_consent_mode_defaults_emits_dataLayer_bootstrap_before_gtag(): void {
		$out = $this->capture( 'lafka_emit_consent_mode_defaults' );
		// Order matters — dataLayer must exist before gtag tries to push.
		$datalayer_pos = strpos( $out, 'window.dataLayer' );
		$gtag_pos      = strpos( $out, "gtag('consent','default'" );
		$this->assertNotFalse( $datalayer_pos );
		$this->assertNotFalse( $gtag_pos );
		$this->assertLessThan( $gtag_pos, $datalayer_pos );
	}

	public function test_consent_mode_defaults_honours_granted_override(): void {
		$this->stub_settings( array(
			'lafka_consent_default_analytics' => 'granted',
		) );
		$out = $this->capture( 'lafka_emit_consent_mode_defaults' );
		$this->assertStringContainsString( '"analytics_storage":"granted"', $out );
	}

	public function test_consent_mode_default_hooked_at_wp_head_priority_1(): void {
		// Priority 1 is critical — Consent Mode v2 must fire before any other
		// wp_head callback that emits a tag, otherwise the tag boots without
		// the default state and Google treats it as untracked-consent.
		$src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/analytics/lafka-analytics-emitter.php' );
		$this->assertMatchesRegularExpression(
			"/add_action\(\s*'wp_head',\s*'lafka_emit_consent_mode_defaults',\s*1\s*\)/",
			$src
		);
	}

	// ────────────────────────────────────────────────────────────────────────
	// 4. GTM head + noscript emit when ID is set, absent otherwise
	// ────────────────────────────────────────────────────────────────────────

	public function test_gtm_head_emits_when_container_id_set(): void {
		$this->stub_settings( array( 'lafka_gtm_container_id' => 'GTM-ABC123' ) );
		$out = $this->capture( 'lafka_emit_gtm_head' );
		$this->assertStringContainsString( 'googletagmanager.com/gtm.js', $out );
		$this->assertStringContainsString( "'GTM-ABC123'", $out );
		$this->assertStringContainsString( '<!-- Google Tag Manager -->', $out );
	}

	public function test_gtm_head_absent_when_container_id_empty(): void {
		// Default stub: get_theme_mod returns the default param (empty here).
		$out = $this->capture( 'lafka_emit_gtm_head' );
		$this->assertSame( '', $out, 'GTM head must not emit when container ID is empty.' );
	}

	public function test_gtm_body_noscript_emits_when_container_id_set(): void {
		$this->stub_settings( array( 'lafka_gtm_container_id' => 'GTM-ABC123' ) );
		$out = $this->capture( 'lafka_emit_gtm_body_noscript' );
		$this->assertStringContainsString( '<noscript>', $out );
		$this->assertStringContainsString( 'googletagmanager.com/ns.html?id=GTM-ABC123', $out );
		$this->assertStringContainsString( 'display:none;visibility:hidden', $out );
	}

	public function test_gtm_body_noscript_absent_when_container_id_empty(): void {
		$out = $this->capture( 'lafka_emit_gtm_body_noscript' );
		$this->assertSame( '', $out );
	}

	// ────────────────────────────────────────────────────────────────────────
	// 5. Direct platform emitters honour the override-not-additive rule
	// ────────────────────────────────────────────────────────────────────────

	public function test_direct_ga4_emits_when_gtm_empty_and_ga4_set(): void {
		$this->stub_settings( array(
			'lafka_gtm_container_id'    => '',
			'lafka_ga4_measurement_id'  => 'G-ABCDE12345',
		) );
		$out = $this->capture( 'lafka_emit_direct_ga4' );
		$this->assertStringContainsString( 'googletagmanager.com/gtag/js?id=G-ABCDE12345', $out );
		$this->assertStringContainsString( "gtag('config','G-ABCDE12345')", $out );
	}

	public function test_direct_ga4_suppressed_when_gtm_set_even_with_ga4_id(): void {
		// Override-not-additive: GTM owns the tag layer, so direct GA4 must
		// no-op even when its ID is present (operator wires GA4 inside GTM).
		$this->stub_settings( array(
			'lafka_gtm_container_id'    => 'GTM-ABC123',
			'lafka_ga4_measurement_id'  => 'G-ABCDE12345',
		) );
		$out = $this->capture( 'lafka_emit_direct_ga4' );
		$this->assertSame( '', $out, 'Direct GA4 must no-op when GTM container is set.' );
	}

	public function test_direct_ga4_absent_when_both_empty(): void {
		$out = $this->capture( 'lafka_emit_direct_ga4' );
		$this->assertSame( '', $out );
	}

	public function test_direct_clarity_emits_when_gtm_empty_and_clarity_set(): void {
		$this->stub_settings( array(
			'lafka_clarity_project_id' => 'abc123xyz',
		) );
		$out = $this->capture( 'lafka_emit_direct_clarity' );
		$this->assertStringContainsString( 'clarity.ms/tag/', $out );
		$this->assertStringContainsString( '"abc123xyz"', $out );
	}

	public function test_direct_clarity_suppressed_when_gtm_set(): void {
		$this->stub_settings( array(
			'lafka_gtm_container_id'   => 'GTM-ABC123',
			'lafka_clarity_project_id' => 'abc123xyz',
		) );
		$out = $this->capture( 'lafka_emit_direct_clarity' );
		$this->assertSame( '', $out );
	}

	public function test_direct_meta_pixel_emits_when_gtm_empty_and_pixel_set(): void {
		$this->stub_settings( array(
			'lafka_meta_pixel_id' => '123456789012345',
		) );
		$out = $this->capture( 'lafka_emit_direct_meta_pixel' );
		$this->assertStringContainsString( 'fbq', $out );
		$this->assertStringContainsString( "fbq('init', '123456789012345')", $out );
		$this->assertStringContainsString( "fbq('track', 'PageView')", $out );
		// Noscript img fallback for cookie-less / JS-less requests.
		$this->assertStringContainsString( 'facebook.com/tr?id=123456789012345', $out );
	}

	public function test_direct_meta_pixel_suppressed_when_gtm_set(): void {
		$this->stub_settings( array(
			'lafka_gtm_container_id' => 'GTM-ABC123',
			'lafka_meta_pixel_id'    => '123456789012345',
		) );
		$out = $this->capture( 'lafka_emit_direct_meta_pixel' );
		$this->assertSame( '', $out );
	}

	// ────────────────────────────────────────────────────────────────────────
	// 6. GSC verification meta
	// ────────────────────────────────────────────────────────────────────────

	public function test_gsc_verification_meta_emits_when_token_set(): void {
		$this->stub_settings( array( 'lafka_gsc_verification' => 'gsc-token-abc123' ) );
		$out = $this->capture( 'lafka_emit_gsc_verification' );
		$this->assertStringContainsString( '<meta name="google-site-verification"', $out );
		$this->assertStringContainsString( 'content="gsc-token-abc123"', $out );
	}

	public function test_gsc_verification_meta_absent_when_token_empty(): void {
		$out = $this->capture( 'lafka_emit_gsc_verification' );
		$this->assertSame( '', $out );
	}

	// ────────────────────────────────────────────────────────────────────────
	// 7. Consent banner: enabled by default, suppressed when off
	// ────────────────────────────────────────────────────────────────────────

	public function test_consent_banner_emits_when_enabled(): void {
		$this->stub_settings( array( 'lafka_consent_banner_enabled' => '1' ) );
		$out = $this->capture( 'lafka_emit_consent_banner' );
		$this->assertStringContainsString( 'id="lafka-consent-banner"', $out );
		$this->assertStringContainsString( 'data-lafka-consent="accept"', $out );
		$this->assertStringContainsString( 'data-lafka-consent="reject"', $out );
		$this->assertStringContainsString( 'data-lafka-consent="settings"', $out );
		// Per-category toggles in the Settings modal
		$this->assertStringContainsString( 'data-lafka-consent-cat="analytics_storage"', $out );
		$this->assertStringContainsString( 'data-lafka-consent-cat="ad_storage"', $out );
		$this->assertStringContainsString( 'data-lafka-consent-cat="ad_user_data"', $out );
		$this->assertStringContainsString( 'data-lafka-consent-cat="ad_personalization"', $out );
		// LocalStorage persistence anchor
		$this->assertStringContainsString( 'lafka_consent_v1', $out );
	}

	public function test_consent_banner_absent_when_disabled(): void {
		$this->stub_settings( array( 'lafka_consent_banner_enabled' => '0' ) );
		$out = $this->capture( 'lafka_emit_consent_banner' );
		$this->assertSame( '', $out );
	}

	public function test_consent_banner_respects_button_label_overrides(): void {
		$this->stub_settings( array(
			'lafka_consent_banner_enabled'        => '1',
			'lafka_consent_banner_accept_label'   => 'Sounds good',
			'lafka_consent_banner_reject_label'   => 'No thanks',
			'lafka_consent_banner_settings_label' => 'Customize',
			'lafka_consent_banner_text'           => 'Custom banner copy.',
		) );
		$out = $this->capture( 'lafka_emit_consent_banner' );
		$this->assertStringContainsString( 'Sounds good', $out );
		$this->assertStringContainsString( 'No thanks', $out );
		$this->assertStringContainsString( 'Customize', $out );
		$this->assertStringContainsString( 'Custom banner copy.', $out );
	}

	public function test_consent_banner_uses_localStorage_for_persistence(): void {
		$this->stub_settings( array( 'lafka_consent_banner_enabled' => '1' ) );
		$out = $this->capture( 'lafka_emit_consent_banner' );
		$this->assertStringContainsString( 'localStorage.setItem', $out );
		$this->assertStringContainsString( 'localStorage.getItem', $out );
	}

	public function test_consent_banner_fires_gtag_consent_update_on_accept(): void {
		// The Accept button must call gtag('consent','update',...) — without
		// this, the default 'denied' state stays put and tags never light up
		// even after a user opts in.
		$this->stub_settings( array( 'lafka_consent_banner_enabled' => '1' ) );
		$out = $this->capture( 'lafka_emit_consent_banner' );
		$this->assertStringContainsString( "gtag('consent','update'", $out );
	}

	// ────────────────────────────────────────────────────────────────────────
	// 8. Hook registration + plugin wiring
	// ────────────────────────────────────────────────────────────────────────

	public function test_emitter_registers_wp_body_open_and_wp_footer_hooks(): void {
		$src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/analytics/lafka-analytics-emitter.php' );
		$this->assertMatchesRegularExpression(
			"/add_action\(\s*'wp_body_open',\s*'lafka_emit_gtm_body_noscript'/",
			$src
		);
		$this->assertMatchesRegularExpression(
			"/add_action\(\s*'wp_footer',\s*'lafka_emit_gtm_body_noscript_fallback'/",
			$src
		);
		$this->assertMatchesRegularExpression(
			"/add_action\(\s*'wp_footer',\s*'lafka_emit_consent_banner'/",
			$src
		);
	}

	public function test_plugin_wires_analytics_files(): void {
		$src = file_get_contents( dirname( __DIR__, 2 ) . '/lafka-plugin.php' );
		$this->assertStringContainsString( 'incl/customizer/class-lafka-customizer-analytics.php', $src );
		$this->assertStringContainsString( 'incl/analytics/lafka-analytics-emitter.php', $src );
	}

	public function test_plugin_version_at_or_above_9_23_0(): void {
		// Phase 1A shipped at 9.23.0; subsequent analytics phases (1B, 1C, ...)
		// bump the version forward. The assertion is "≥ 9.23.0" so phase
		// shipping doesn't have to update this test on every bump.
		$src = file_get_contents( dirname( __DIR__, 2 ) . '/lafka-plugin.php' );
		preg_match( '/Version:\s*(\d+)\.(\d+)\.(\d+)\b/', $src, $m );
		$this->assertNotEmpty( $m, 'Plugin header must declare a semver Version.' );
		$major = (int) $m[1];
		$minor = (int) $m[2];
		$this->assertGreaterThanOrEqual( 9, $major );
		$this->assertTrue(
			$major > 9 || ( 9 === $major && $minor >= 23 ),
			"Plugin version must be >= 9.23.0 (saw {$m[1]}.{$m[2]}.{$m[3]})."
		);
	}
}
