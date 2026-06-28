<?php
/**
 * AnalyticsBannerDestinationGateTest — locks down the consent-banner
 * destination gate (audit f083).
 *
 * Regression context: lafka_emit_consent_banner() only checked
 * lafka_analytics_banner_enabled() (default '1'). Unlike every other analytics
 * emitter (page-context, custom-events, dl-client, store-events all gate on
 * lafka_analytics_is_active()), the banner did NOT check whether any tracking
 * destination was configured. A fresh/default install with zero GTM / GA4 /
 * Clarity / Pixel / CF-beacon IDs therefore rendered a fixed bottom-of-viewport
 * "we use cookies" banner to every visitor — pointless (no cookies are set),
 * arguably misleading, and a conversion drag on the default theme.
 *
 * The fix adds, immediately after the banner-enabled check:
 *
 *   if ( ! function_exists( 'lafka_analytics_is_active' )
 *        || ! lafka_analytics_is_active() ) { return; }
 *
 * This test pins:
 *   - banner emits when enabled AND a tracking destination is configured
 *   - banner is silent when enabled but NO destination is configured
 *   - every dataLayer destination (and the CF beacon) trips the gate
 *   - the enabled flag still wins (disabled → silent even with a destination)
 *
 * @package Lafka\Plugin\Tests\Unit
 * @since   9.31.0
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

// Bring the emitter (banner + ID accessors) AND every gate definition into
// scope: lafka_analytics_is_active() lives in lafka-page-context.php and is
// built on lafka_analytics_has_datalayer_destination() (lafka-wc-events.php)
// plus lafka_analytics_cf_beacon_token() (lafka-cf-analytics.php).
require_once dirname( __DIR__, 2 ) . '/incl/analytics/lafka-analytics-emitter.php';
require_once dirname( __DIR__, 2 ) . '/incl/analytics/lafka-wc-events.php';
require_once dirname( __DIR__, 2 ) . '/incl/analytics/lafka-cf-analytics.php';
require_once dirname( __DIR__, 2 ) . '/incl/analytics/lafka-page-context.php';

final class AnalyticsBannerDestinationGateTest extends TestCase {

	/** Valid (format-passing) example value per Customizer theme_mod key. */
	private const VALID = array(
		'lafka_gtm_container_id'   => 'GTM-XYZ987',
		'lafka_ga4_measurement_id' => 'G-ABCDE12345',
		'lafka_clarity_project_id' => 'abcdef12345',
		'lafka_meta_pixel_id'      => '1234567890123456',
		'lafka_cf_beacon_token'    => 'abcdef0123456789abcdef0123456789',
	);

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
	 * Stub get_theme_mod so only the given keys resolve to a configured value.
	 *
	 * @param array<string, string> $values key => value for the keys that are "set".
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
	// The gate function must be available at the point the banner fires.
	// ────────────────────────────────────────────────────────────────────────

	public function test_destination_gate_function_is_defined(): void {
		$this->assertTrue(
			function_exists( 'lafka_analytics_is_active' ),
			'lafka_analytics_is_active() must be defined (required at bootstrap) so the banner gate has something to call.'
		);
	}

	// ────────────────────────────────────────────────────────────────────────
	// Banner emits only when enabled AND a destination is configured.
	// ────────────────────────────────────────────────────────────────────────

	public function test_banner_emits_when_enabled_and_destination_configured(): void {
		$this->stub_settings( array(
			'lafka_consent_banner_enabled' => '1',
			'lafka_gtm_container_id'       => self::VALID['lafka_gtm_container_id'],
		) );
		$out = $this->capture( 'lafka_emit_consent_banner' );
		$this->assertStringContainsString( 'id="lafka-consent-banner"', $out );
		$this->assertStringContainsString( 'data-lafka-consent="accept"', $out );
		$this->assertStringContainsString( 'data-lafka-consent="reject"', $out );
		$this->assertStringContainsString( 'data-lafka-consent="settings"', $out );
		$this->assertStringContainsString( 'lafka_consent_v1', $out );
	}

	public function test_banner_silent_when_enabled_but_no_destination_configured(): void {
		// The exact regression: enabled (the default) but a fresh install with
		// zero tracking IDs must render NOTHING — no cookies are set, so a cookie
		// banner is pointless and misleading.
		$this->stub_settings( array( 'lafka_consent_banner_enabled' => '1' ) );
		$out = $this->capture( 'lafka_emit_consent_banner' );
		$this->assertSame( '', $out, 'Banner must be silent when no analytics destination is configured.' );
	}

	/**
	 * Every configured destination (the four dataLayer destinations plus the
	 * cookieless CF beacon) must trip lafka_analytics_is_active() and so allow
	 * the banner to render when it is enabled.
	 *
	 * @return array<string, array{0:string}>
	 */
	public static function destination_keys(): array {
		return array(
			'gtm'       => array( 'lafka_gtm_container_id' ),
			'ga4'       => array( 'lafka_ga4_measurement_id' ),
			'clarity'   => array( 'lafka_clarity_project_id' ),
			'pixel'     => array( 'lafka_meta_pixel_id' ),
			'cf_beacon' => array( 'lafka_cf_beacon_token' ),
		);
	}

	#[DataProvider( 'destination_keys' )]
	public function test_each_destination_unlocks_the_banner( string $key ): void {
		$this->stub_settings( array(
			'lafka_consent_banner_enabled' => '1',
			$key                           => self::VALID[ $key ],
		) );
		$out = $this->capture( 'lafka_emit_consent_banner' );
		$this->assertStringContainsString(
			'id="lafka-consent-banner"',
			$out,
			"A configured {$key} must make analytics active and unlock the banner."
		);
	}

	// ────────────────────────────────────────────────────────────────────────
	// The enabled flag still wins: disabled → silent even with a destination.
	// ────────────────────────────────────────────────────────────────────────

	public function test_banner_silent_when_disabled_even_with_destination(): void {
		$this->stub_settings( array(
			'lafka_consent_banner_enabled' => '0',
			'lafka_gtm_container_id'       => self::VALID['lafka_gtm_container_id'],
		) );
		$out = $this->capture( 'lafka_emit_consent_banner' );
		$this->assertSame( '', $out, 'A disabled banner must stay silent even when a destination is configured.' );
	}
}
