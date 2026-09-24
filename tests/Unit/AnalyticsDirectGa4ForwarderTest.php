<?php
/**
 * Direct-GA4 dataLayer -> gtag forwarder. Without GTM, gtag.js ignores
 * GTM-format dataLayer.push({event, ecommerce}) messages, so a "GA4 ID, no GTM"
 * site would get pageviews but no ecommerce events. lafka_emit_direct_ga4()
 * mirrors those pushes into gtag('event', name, params) with the ecommerce
 * object spread as params (GA4 ignores a nested `ecommerce` key). The
 * GTM-set / no-ID no-op cases are covered in AnalyticsEmitterTest.
 *
 * @package Lafka\Plugin\Tests\Unit
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

	public function test_forwarder_mirrors_ecommerce_pushes_into_ga4_events(): void {
		$this->stub_settings( array( 'lafka_ga4_measurement_id' => 'G-ABCDE12345' ) );
		$out = $this->capture_direct_ga4();

		// dataLayer.push is wrapped (the original still runs).
		$this->assertStringContainsString( 'var op = dl.push.bind(dl);', $out );
		$this->assertStringContainsString( 'var r = op(o);', $out );
		// Only pushes carrying BOTH event and ecommerce are mirrored: the
		// {ecommerce:null} clears are skipped and gtag's own arguments object
		// (no .event) can't recurse.
		$this->assertStringContainsString( "if (o && typeof o === 'object' && o.event && o.ecommerce && typeof gtag === 'function')", $out );
		// ecommerce is spread into the params and routed to the configured ID.
		$this->assertStringContainsString( "gtag('event', o.event, Object.assign({ send_to: 'G-ABCDE12345' }, o.ecommerce));", $out );
	}
}
