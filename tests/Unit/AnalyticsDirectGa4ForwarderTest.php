<?php
/**
 * AnalyticsDirectGa4ForwarderTest — regression lock for the direct-GA4
 * dataLayer -> gtag forwarder.
 *
 * Without GTM, gtag.js does NOT translate GTM-format
 * `dataLayer.push({event, ecommerce})` messages into GA4 events. The WC
 * events module emits exactly that shape (both server-rendered inline pushes
 * via lafka_dl_emit_push() and client pushes in lafka-dl-client.js), so a
 * "paste a GA4 ID, no GTM" operator would otherwise get pageviews but ZERO
 * funnel/ecommerce/purchase tracking. lafka_emit_direct_ga4() installs a
 * single dataLayer.push monkeypatch that mirrors every {event, ecommerce}
 * push into gtag('event', name, params) with the ecommerce object SPREAD as
 * the params (a nested `ecommerce` key is ignored by GA4) and the
 * `{ecommerce:null}` clear pushes skipped.
 *
 * @package Lafka\Plugin\Tests\Unit
 * @since   9.24.0
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/incl/analytics/lafka-analytics-emitter.php';

final class AnalyticsDirectGa4ForwarderTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'get_theme_mod' )->returnArg( 2 );
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_js' )->returnArg();
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

	private function capture_direct_ga4(): string {
		ob_start();
		lafka_emit_direct_ga4();
		return (string) ob_get_clean();
	}

	public function test_direct_ga4_installs_datalayer_push_forwarder(): void {
		$this->stub_settings( array( 'lafka_ga4_measurement_id' => 'G-ABCDE12345' ) );
		$out = $this->capture_direct_ga4();
		// The forwarder must wrap dataLayer.push and re-assign it.
		$this->assertStringContainsString( 'dl.push.bind(dl)', $out );
		$this->assertStringContainsString( 'dl.push = function(o)', $out );
	}

	public function test_forwarder_mirrors_event_into_gtag_event_call(): void {
		$this->stub_settings( array( 'lafka_ga4_measurement_id' => 'G-ABCDE12345' ) );
		$out = $this->capture_direct_ga4();
		// Mirrors {event, ecommerce} pushes into a gtag('event', name, params)
		// call — the only form gtag.js converts into a GA4 event.
		$this->assertStringContainsString( "gtag('event', o.event,", $out );
	}

	public function test_forwarder_spreads_ecommerce_object_not_nested(): void {
		$this->stub_settings( array( 'lafka_ga4_measurement_id' => 'G-ABCDE12345' ) );
		$out = $this->capture_direct_ga4();
		// CRITICAL: GA4 ignores params nested under an `ecommerce` key, so the
		// ecommerce object must be SPREAD (Object.assign) into the event params.
		$this->assertStringContainsString( 'Object.assign({ send_to:', $out );
		$this->assertStringContainsString( '}, o.ecommerce)', $out );
		// Guard against a regression that passes a nested {ecommerce: ...} param.
		$this->assertStringNotContainsString( "gtag('event', o.event, { ecommerce", $out );
		$this->assertStringNotContainsString( 'gtag("event", o.event, {ecommerce', $out );
	}

	public function test_forwarder_targets_configured_ga4_id(): void {
		$this->stub_settings( array( 'lafka_ga4_measurement_id' => 'G-ABCDE12345' ) );
		$out = $this->capture_direct_ga4();
		// send_to must route to the configured measurement ID.
		$this->assertStringContainsString( "send_to: 'G-ABCDE12345'", $out );
	}

	public function test_forwarder_only_fires_for_pushes_carrying_both_event_and_ecommerce(): void {
		$this->stub_settings( array( 'lafka_ga4_measurement_id' => 'G-ABCDE12345' ) );
		$out = $this->capture_direct_ga4();
		// The {ecommerce:null} clear pushes carry no `event`; the guard requires
		// BOTH o.event AND o.ecommerce so those clears are skipped (and gtag's
		// own array-like arguments object — no `.event` own key — never recurses).
		$this->assertStringContainsString( 'o && typeof o === \'object\' && o.event && o.ecommerce', $out );
	}

	public function test_forwarder_absent_when_gtm_owns_the_path(): void {
		// GTM does the dataLayer -> tag translation itself, so the forwarder
		// (and the whole direct-GA4 emit) must no-op when a container is set.
		$this->stub_settings( array(
			'lafka_gtm_container_id'   => 'GTM-ABC123',
			'lafka_ga4_measurement_id' => 'G-ABCDE12345',
		) );
		$out = $this->capture_direct_ga4();
		$this->assertSame( '', $out );
	}

	public function test_forwarder_absent_when_no_ga4_id(): void {
		$out = $this->capture_direct_ga4();
		$this->assertSame( '', $out );
		$this->assertStringNotContainsString( 'dl.push', $out );
	}
}
