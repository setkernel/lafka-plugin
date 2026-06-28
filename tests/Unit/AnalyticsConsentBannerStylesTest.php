<?php
/**
 * AnalyticsConsentBannerStylesTest — locks down the consent-banner
 * themability hooks (audit f097).
 *
 * Regression context: lafka_emit_consent_banner() printed an inline
 * <style id="lafka-consent-banner-style"> block whose palette was hardcoded
 * (background #1f2937, accept #10b981, reject #374151, panel #fff, border
 * #e5e7eb, …). With no CSS custom properties and no filter, a theme could only
 * restyle the banner with !important overrides — contradicting the plugin's
 * "markup only / theme owns appearance" and "customizer-first" conventions.
 *
 * The fix keeps the inline <style> (the "works without theme CSS" rationale is
 * valid) but expresses the palette as --lafka-consent-* custom properties with
 * the current values as fallbacks, and adds a lafka_consent_banner_styles
 * filter so the whole block can be replaced or suppressed.
 *
 * This test pins:
 *   - the inline <style> block still renders by default
 *   - the palette is exposed via --lafka-consent-* vars (with fallbacks)
 *   - no brand color is emitted as a bare hardcoded property value
 *   - the lafka_consent_banner_styles filter can replace the CSS
 *   - returning '' from the filter suppresses the <style> block while the
 *     banner markup itself still renders
 *
 * @package Lafka\Plugin\Tests\Unit
 * @since   9.32.0
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;
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

	// ────────────────────────────────────────────────────────────────────────
	// Inline <style> still renders by default (the offline-resilience rationale).
	// ────────────────────────────────────────────────────────────────────────

	public function test_inline_style_block_renders_by_default(): void {
		$out = $this->capture( 'lafka_emit_consent_banner' );
		$this->assertStringContainsString( '<style id="lafka-consent-banner-style">', $out );
		$this->assertStringContainsString( '</style>', $out );
	}

	// ────────────────────────────────────────────────────────────────────────
	// Palette is exposed through --lafka-consent-* custom properties, current
	// brand values kept as fallbacks so the banner still renders when the theme
	// CSS fails to load.
	// ────────────────────────────────────────────────────────────────────────

	/**
	 * @return array<string, array{0:string}>
	 */
	public static function custom_property_declarations(): array {
		return array(
			'banner background' => array( 'var(--lafka-consent-bg,#1f2937)' ),
			'banner foreground' => array( 'var(--lafka-consent-fg,#fff)' ),
			'accept button'     => array( 'var(--lafka-consent-accept,#10b981)' ),
			'reject button'     => array( 'var(--lafka-consent-reject,#374151)' ),
			'modal overlay'     => array( 'var(--lafka-consent-overlay,rgba(0,0,0,.55))' ),
			'modal panel bg'    => array( 'var(--lafka-consent-panel-bg,#fff)' ),
			'modal panel fg'    => array( 'var(--lafka-consent-panel-fg,#1f2937)' ),
			'modal row border'  => array( 'var(--lafka-consent-border,#e5e7eb)' ),
			'close button bg'   => array( 'var(--lafka-consent-close-bg,#e5e7eb)' ),
			'close button fg'   => array( 'var(--lafka-consent-close-fg,#1f2937)' ),
		);
	}

	#[DataProvider( 'custom_property_declarations' )]
	public function test_palette_uses_custom_properties_with_fallbacks( string $declaration ): void {
		$out = $this->capture( 'lafka_emit_consent_banner' );
		$this->assertStringContainsString(
			$declaration,
			$out,
			"Palette must be themable via {$declaration} so a theme can recolor the banner without !important."
		);
	}

	/**
	 * The pre-fix hardcoded property values must no longer appear as bare
	 * declarations (they now live only as var() fallbacks).
	 *
	 * @return array<string, array{0:string}>
	 */
	public static function removed_hardcoded_declarations(): array {
		return array(
			'banner bg'    => array( 'background:#1f2937' ),
			'accept bg'    => array( 'background:#10b981' ),
			'reject bg'    => array( 'background:#374151' ),
			'panel bg'     => array( 'background:#fff' ),
			'panel border' => array( 'solid #e5e7eb' ),
		);
	}

	#[DataProvider( 'removed_hardcoded_declarations' )]
	public function test_no_bare_hardcoded_palette_declarations( string $hardcoded ): void {
		$out = $this->capture( 'lafka_emit_consent_banner' );
		$this->assertStringNotContainsString(
			$hardcoded,
			$out,
			"Brand color '{$hardcoded}' must be expressed as a --lafka-consent-* var, not a bare hardcoded declaration."
		);
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
