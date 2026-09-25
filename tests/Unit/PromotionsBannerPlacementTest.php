<?php
/**
 * The BOGO banner renders in the page flow at the top of <body>
 * (wp_body_open) so it never covers the theme header or sticky bars; the
 * fixed overlay is only the fallback for themes that never fire
 * wp_body_open. Regression: on a live store the fixed banner (z-index 10000,
 * top: 0) hid the top of the header.
 */

declare(strict_types=1);

namespace Lafka\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Lafka_Promotions;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/incl/promotions/class-lafka-promotions.php';

final class PromotionsBannerPlacementTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_attr_e' )->echoArg();
		Lafka_Promotions::flush_knobs();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function promotions(): Lafka_Promotions {
		return ( new \ReflectionClass( Lafka_Promotions::class ) )->newInstanceWithoutConstructor();
	}

	private function capture( callable $fn ): string {
		ob_start();
		$fn();
		return (string) ob_get_clean();
	}

	public function test_wp_body_open_prints_the_inline_banner_and_footer_does_not_repeat_it(): void {
		$p      = $this->promotions();
		$inline = $this->capture( array( $p, 'render_banner_inline' ) );
		$footer = $this->capture( array( $p, 'render_banner_fallback' ) );

		$this->assertStringContainsString( 'id="lafka-bogo-banner"', $inline );
		$this->assertStringContainsString( 'lafka-bogo-banner--inline', $inline );
		$this->assertStringNotContainsString( 'lafka-bogo-banner--fixed', $inline );
		$this->assertSame( '', $footer, 'the footer fallback must not print a second banner' );
	}

	public function test_themes_without_wp_body_open_get_the_fixed_fallback_once(): void {
		$p      = $this->promotions();
		$first  = $this->capture( array( $p, 'render_banner_fallback' ) );
		$second = $this->capture( array( $p, 'render_banner_fallback' ) );

		$this->assertStringContainsString( 'lafka-bogo-banner--fixed', $first );
		$this->assertSame( '', $second );
	}

	public function test_banner_is_a_labelled_region_not_a_second_banner_landmark(): void {
		$html = $this->capture( array( $this->promotions(), 'render_banner_inline' ) );

		$this->assertStringContainsString( 'role="region"', $html );
		$this->assertStringContainsString( 'aria-label="Promotion"', $html );
		$this->assertStringNotContainsString( 'role="banner"', $html );
	}

	public function test_only_the_fixed_variant_is_positioned_over_the_page(): void {
		$css = (string) file_get_contents( dirname( __DIR__, 2 ) . '/incl/promotions/assets/css/lafka-promotions.css' );
		preg_match( '/#lafka-bogo-banner\s*\{([^}]*)\}/', $css, $base );

		$this->assertNotEmpty( $base, 'base banner rule exists' );
		$this->assertStringNotContainsString( 'position: fixed', $base[1] );
		$this->assertMatchesRegularExpression( '/#lafka-bogo-banner\.lafka-bogo-banner--fixed\s*\{[^}]*position:\s*fixed/', $css );
	}
}
