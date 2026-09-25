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
require_once dirname( __DIR__, 2 ) . '/incl/schema/lafka-schema-helpers.php';

final class PromotionsBannerPlacementTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_attr_e' )->echoArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'home_url' )->alias( static fn( $path = '' ) => 'https://example.test' . $path );
		Functions\when( 'trailingslashit' )->alias( static fn( $v ) => rtrim( (string) $v, '/' ) . '/' );
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

	public function test_the_in_flow_banner_renders_visible_so_it_never_shifts_the_page(): void {
		$inline = $this->capture( array( $this->promotions(), 'render_banner_inline' ) );
		$fixed  = $this->capture( array( $this->promotions(), 'render_banner_fallback' ) );

		$this->assertDoesNotMatchRegularExpression( '/<div id="lafka-bogo-banner"[^>]*\shidden/', $inline, 'H-05: no JS reveal after load.' );
		$this->assertMatchesRegularExpression( '/<div id="lafka-bogo-banner"[^>]*\shidden/', $fixed, 'The overlay keeps its slide-in.' );
	}

	public function test_the_banner_speaks_plainly_links_to_the_menu_and_has_a_real_button(): void {
		$html = $this->capture( array( $this->promotions(), 'render_banner_inline' ) );

		$this->assertStringContainsString( '<a class="lafka-bogo-link" href="https://example.test/menu/">Buy 1, get 1 50% off</a>', $html );
		$this->assertStringContainsString( '<button type="button" class="lafka-bogo-close"', $html );
		$this->assertDoesNotMatchRegularExpression( '/[\x{1F300}-\x{1FAFF}]/u', $html, 'No emoji (served as s.w.org images).' );
	}

	public function test_the_prepaint_check_reads_the_same_key_the_script_writes(): void {
		Functions\when( 'get_option' )->justReturn(
			array(
				'promo_key'    => 'spring_deal',
				'dismiss_days' => 3,
			)
		);
		Lafka_Promotions::flush_knobs();
		Functions\when( 'plugins_url' )->alias( static fn( $path ) => 'https://example.test/' . $path );
		Functions\when( 'wp_enqueue_style' )->justReturn( null );
		Functions\when( 'wp_enqueue_script' )->justReturn( null );
		$localized = array();
		Functions\when( 'wp_localize_script' )->alias(
			static function ( $handle, $name, $data ) use ( &$localized ) {
				$localized = $data;
			}
		);

		$this->promotions()->enqueue_banner_assets();
		$script = Lafka_Promotions::prepaint_script();

		$this->assertSame( 'lafka_bogo_dismissed_spring_deal', $localized['dismissKey'] );
		$this->assertStringContainsString( 'localStorage.getItem("lafka_bogo_dismissed_spring_deal")', $script );
		$this->assertStringContainsString( '<3*864e5', $script, 'Same dismissal window as the script.' );
		$this->assertStringContainsString( 'lafka-bogo-dismissed', $script );

		$js = (string) file_get_contents( dirname( __DIR__, 2 ) . '/incl/promotions/assets/js/lafka-promotions.js' );
		$this->assertStringContainsString( 'window.LAFKA_PROMO.dismissKey', $js, 'The script uses the PHP-built key.' );
	}

	public function test_the_prepaint_check_is_printed_in_the_head_with_its_rule(): void {
		Functions\when( 'wp_print_inline_script_tag' )->alias(
			static function ( $data, $attrs ) {
				echo '<script id="' . $attrs['id'] . '">' . $data . '</script>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			}
		);

		$html = $this->capture( array( $this->promotions(), 'print_prepaint_dismiss_check' ) );

		$this->assertStringContainsString( '.lafka-bogo-dismissed #lafka-bogo-banner{display:none}', $html );
		$this->assertStringContainsString( '<script id="lafka-bogo-prepaint-js">', $html );
	}

	public function test_only_the_fixed_variant_is_positioned_over_the_page(): void {
		$css = (string) file_get_contents( dirname( __DIR__, 2 ) . '/incl/promotions/assets/css/lafka-promotions.css' );
		preg_match( '/#lafka-bogo-banner\s*\{([^}]*)\}/', $css, $base );

		$this->assertNotEmpty( $base, 'base banner rule exists' );
		$this->assertStringNotContainsString( 'position: fixed', $base[1] );
		$this->assertMatchesRegularExpression( '/#lafka-bogo-banner\.lafka-bogo-banner--fixed\s*\{[^}]*position:\s*fixed/', $css );
	}
}
