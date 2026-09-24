<?php
/**
 * Cloudflare Web Analytics beacon: emitted only for a valid operator token
 * (keeps the OSS plugin account-free), and — being cookieless — independent
 * of the consent defaults that gate GTM / GA4.
 *
 * @package Lafka\Plugin\Tests\Unit
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/incl/analytics/lafka-cf-analytics.php';

final class AnalyticsCfAnalyticsTest extends TestCase {

	private const TOKEN = 'abcdef0123456789abcdef0123456789';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'wp_json_encode' )->alias( static fn( $v ) => json_encode( $v ) );
		Functions\when( 'esc_attr' )->alias( static fn( $v ) => htmlspecialchars( (string) $v, ENT_QUOTES ) );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function stub_theme_mods( array $mods ): void {
		Functions\when( 'get_theme_mod' )->alias(
			static fn( $key, $default = '' ) => array_key_exists( $key, $mods ) ? $mods[ $key ] : $default
		);
	}

	private function emit(): string {
		ob_start();
		lafka_analytics_emit_cf_beacon();
		return (string) ob_get_clean();
	}

	public function test_token_must_be_32_hex_chars(): void {
		$this->stub_theme_mods( array( 'lafka_cf_beacon_token' => '  ' . strtoupper( self::TOKEN ) . ' ' ) );
		$this->assertSame( self::TOKEN, lafka_analytics_cf_beacon_token() );

		foreach ( array( '', 'abc', self::TOKEN . '0', 'zzzzzz0123456789abcdef0123456789', "x'><script>" ) as $bad ) {
			$this->stub_theme_mods( array( 'lafka_cf_beacon_token' => $bad ) );
			$this->assertSame( '', lafka_analytics_cf_beacon_token(), "Rejected token: {$bad}" );
		}
	}

	public function test_beacon_emits_with_token_even_under_denied_consent_defaults(): void {
		$this->stub_theme_mods(
			array(
				'lafka_cf_beacon_token'           => self::TOKEN,
				'lafka_consent_default_analytics' => 'denied',
			)
		);
		$this->assertSame(
			'<script defer src="https://static.cloudflareinsights.com/beacon.min.js" data-cf-beacon=\'{&quot;token&quot;:&quot;' . self::TOKEN . '&quot;}\'></script>' . "\n",
			$this->emit()
		);
	}

	public function test_beacon_is_silent_without_token_and_in_admin(): void {
		$this->stub_theme_mods( array() );
		$this->assertSame( '', $this->emit() );

		$this->stub_theme_mods( array( 'lafka_cf_beacon_token' => self::TOKEN ) );
		Functions\when( 'is_admin' )->justReturn( true );
		$this->assertSame( '', $this->emit() );
	}
}
