<?php
/**
 * Consent gating for the non-Google direct emitters (audit f043). Meta Pixel
 * and Microsoft Clarity ignore Google Consent Mode, so without explicit gating
 * they drop cookies / send beacons before the visitor decides. Both are driven
 * from the same lafka_consent_v1 decision the bundled banner persists.
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

	public function test_meta_pixel_revokes_before_init_and_tracks_only_on_stored_ad_storage_grant(): void {
		$this->stub_settings( array( 'lafka_meta_pixel_id' => '123456789012345' ) );
		$out = $this->capture( 'lafka_emit_direct_meta_pixel' );

		$revoke = strpos( $out, "fbq('consent', 'revoke')" );
		$init   = strpos( $out, "fbq('init'" );
		$gate   = strpos( $out, "if (d && d.ad_storage) {" );
		$track  = strpos( $out, "fbq('track', 'PageView')" );
		$this->assertNotFalse( $revoke, 'Pixel must revoke consent so init drops no cookie / sends no beacon.' );
		$this->assertNotFalse( $gate );
		$this->assertLessThan( $init, $revoke, 'Consent revoke must run before fbq init.' );
		$this->assertLessThan( $track, $gate, 'The PageView must sit behind the stored ad_storage check.' );
		$this->assertSame( 1, substr_count( $out, "fbq('track'" ), 'No ungated track call may be emitted.' );
		$this->assertStringContainsString( "localStorage.getItem('lafka_consent_v1')", $out );
	}

	public function test_clarity_tag_loads_only_through_the_consent_gated_loader(): void {
		$this->stub_settings( array( 'lafka_clarity_project_id' => 'abc123xyz' ) );
		$out = $this->capture( 'lafka_emit_direct_clarity' );

		$loader = strpos( $out, 'window.lafkaLoadClarity = function' );
		$this->assertNotFalse( $loader );
		$this->assertLessThan( strpos( $out, 'clarity.ms/tag/' ), $loader, 'The clarity.ms tag must live inside the lazy loader.' );
		$this->assertMatchesRegularExpression(
			'/if\s*\(\s*d\s*&&\s*d\.analytics_storage\s*\)\s*\{\s*window\.lafkaLoadClarity\(\)/',
			$out,
			'Clarity may only load at page start when a stored analytics_storage grant exists.'
		);
	}

	public function test_banner_decision_drives_meta_pixel_and_clarity(): void {
		$this->stub_settings( array(
			'lafka_consent_banner_enabled' => '1',
			'lafka_gtm_container_id'       => 'GTM-XYZ987',
		) );
		$out = $this->capture( 'lafka_emit_consent_banner' );

		$this->assertStringContainsString( "window.fbq('consent', state.ad_storage ? 'grant' : 'revoke')", $out );
		$this->assertMatchesRegularExpression(
			'/if\s*\(\s*state\.ad_storage\s*&&\s*!\s*window\._lafkaFbPageView\s*\)\s*\{\s*window\.fbq\(\'track\',\'PageView\'\)/',
			$out,
			'The banner PageView must require ad_storage and be deduped against the head emit.'
		);
		$this->assertMatchesRegularExpression(
			'/if\s*\(\s*state\.analytics_storage\s*&&\s*typeof\s*window\.lafkaLoadClarity\s*===\s*.function.\s*\)\s*\{\s*window\.lafkaLoadClarity\(\);/',
			$out,
			'Clarity must load from the banner only when analytics_storage is granted.'
		);
	}
}
