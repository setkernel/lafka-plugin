<?php
/**
 * Consent banner themability (audit f097): the inline <style> keeps the banner
 * usable without theme CSS, exposes its palette as --lafka-consent-* custom
 * properties, and the lafka_consent_banner_styles filter can replace or
 * suppress the block.
 *
 * @package Lafka\Plugin\Tests\Unit
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

// Bring the emitter (banner) AND every gate definition into scope:
// lafka_analytics_is_active() lives in lafka-page-context.php and is built on
// lafka_analytics_has_datalayer_destination() (lafka-wc-events.php) plus
// lafka_analytics_cf_beacon_token() (lafka-cf-analytics.php).
require_once dirname( __DIR__, 2 ) . '/incl/analytics/lafka-analytics-emitter.php';
require_once dirname( __DIR__, 2 ) . '/incl/analytics/lafka-wc-events.php';
require_once dirname( __DIR__, 2 ) . '/incl/analytics/lafka-cf-analytics.php';
require_once dirname( __DIR__, 2 ) . '/incl/analytics/lafka-page-context.php';

final class AnalyticsConsentBannerStylesTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'wp_json_encode' )->alias( static fn( $v ) => json_encode( $v ) );
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_js' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_attr__' )->returnArg();
		Functions\when( 'wp_kses_post' )->returnArg();
		// Default pass-through: a filter returns its passed value unchanged.
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'did_action' )->justReturn( 0 );
		// Enabled banner + one configured destination so the banner renders.
		Functions\when( 'get_theme_mod' )->alias(
			static function ( $key, $default = null ) {
				$values = array(
					'lafka_consent_banner_enabled' => '1',
					'lafka_gtm_container_id'       => 'GTM-XYZ987',
				);
				return array_key_exists( $key, $values ) ? $values[ $key ] : ( null === $default ? '' : $default );
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function capture( callable $fn ): string {
		ob_start();
		$fn();
		return (string) ob_get_clean();
	}

	public function test_inline_style_block_exposes_palette_as_custom_properties_with_fallbacks(): void {
		$out = $this->capture( 'lafka_emit_consent_banner' );
		$this->assertStringContainsString( '<style id="lafka-consent-banner-style">', $out );

		$missing = array();
		foreach ( array(
			'var(--lafka-consent-bg,#1f2937)',
			'var(--lafka-consent-fg,#fff)',
			'var(--lafka-consent-accept,#10b981)',
			'var(--lafka-consent-reject,#374151)',
			'var(--lafka-consent-overlay,rgba(0,0,0,.55))',
			'var(--lafka-consent-panel-bg,#fff)',
			'var(--lafka-consent-panel-fg,#1f2937)',
			'var(--lafka-consent-border,#e5e7eb)',
			'var(--lafka-consent-close-bg,#e5e7eb)',
			'var(--lafka-consent-close-fg,#1f2937)',
		) as $declaration ) {
			if ( false === strpos( $out, $declaration ) ) {
				$missing[] = $declaration;
			}
		}
		$this->assertSame( array(), $missing, 'Palette must be themable via --lafka-consent-* variables.' );
	}

	// ────────────────────────────────────────────────────────────────────────
	// lafka_consent_banner_styles filter can replace the inline CSS.
	// ────────────────────────────────────────────────────────────────────────

	public function test_filter_can_replace_inline_styles(): void {
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value ) {
				if ( 'lafka_consent_banner_styles' === $hook ) {
					return '.lafka-consent-banner{background:rebeccapurple}';
				}
				return $value;
			}
		);
		$out = $this->capture( 'lafka_emit_consent_banner' );
		$this->assertStringContainsString( '<style id="lafka-consent-banner-style">', $out );
		$this->assertStringContainsString( '.lafka-consent-banner{background:rebeccapurple}', $out );
		$this->assertStringNotContainsString( 'var(--lafka-consent-bg,#1f2937)', $out );
	}

	// ────────────────────────────────────────────────────────────────────────
	// Returning '' from the filter suppresses the <style> block entirely, but
	// the banner markup still renders (theme supplies its own enqueued styling).
	// ────────────────────────────────────────────────────────────────────────

	public function test_filter_can_suppress_style_block_but_keep_markup(): void {
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value ) {
				if ( 'lafka_consent_banner_styles' === $hook ) {
					return '';
				}
				return $value;
			}
		);
		$out = $this->capture( 'lafka_emit_consent_banner' );
		$this->assertStringNotContainsString( '<style id="lafka-consent-banner-style">', $out );
		$this->assertStringContainsString( 'id="lafka-consent-banner"', $out );
		$this->assertStringContainsString( 'data-lafka-consent="accept"', $out );
	}
}
