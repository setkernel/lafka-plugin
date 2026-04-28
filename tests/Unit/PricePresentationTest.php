<?php
declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PricePresentationTest extends TestCase {
	private string $module;
	protected function setUp(): void {
		parent::setUp();
		$this->module = file_get_contents(
			dirname( __DIR__, 2 ) . '/incl/woocommerce/lafka-price-presentation.php'
		);
	}
	public function test_module_exists(): void {
		$this->assertNotEmpty( $this->module );
	}
	public function test_filter_registered(): void {
		$this->assertMatchesRegularExpression(
			"/add_filter\(\s*['\"]woocommerce_get_price_html['\"]\s*,\s*['\"]lafka_normalize_price_html['\"]/",
			$this->module
		);
	}
	public function test_strips_sup_tags(): void {
		$this->assertStringContainsString( '<sup', $this->module );
		$this->assertStringContainsString( 'preg_replace', $this->module );
	}
	public function test_normalizes_through_separator(): void {
		$this->assertStringContainsString( ' through ', $this->module );
	}
	public function test_main_plugin_requires_module(): void {
		$main = file_get_contents( dirname( __DIR__, 2 ) . '/lafka-plugin.php' );
		$this->assertStringContainsString( 'lafka-price-presentation.php', $main );
	}

	/**
	 * Functional unit test of the normalize function with mock data.
	 * (Pure string transformation — no WP/WC bootstrap needed.)
	 */
	public function test_normalize_function_strips_sup(): void {
		if ( ! function_exists( 'lafka_normalize_price_html' ) ) {
			require_once dirname( __DIR__, 2 ) . '/incl/woocommerce/lafka-price-presentation.php';
		}
		$input  = '<span>$25<sup>.99</sup></span>';
		$output = lafka_normalize_price_html( $input, null );
		$this->assertEquals( '<span>$25.99</span>', $output );
	}
	public function test_normalize_function_handles_through(): void {
		if ( ! function_exists( 'lafka_normalize_price_html' ) ) {
			require_once dirname( __DIR__, 2 ) . '/incl/woocommerce/lafka-price-presentation.php';
		}
		$input  = '$12.50 through $29.95';
		$output = lafka_normalize_price_html( $input, null );
		$this->assertEquals( '$12.50 – $29.95', $output );
	}
	public function test_normalize_function_strips_price_range_prefix(): void {
		if ( ! function_exists( 'lafka_normalize_price_html' ) ) {
			require_once dirname( __DIR__, 2 ) . '/incl/woocommerce/lafka-price-presentation.php';
		}
		$input  = 'Price range: $12.50 – $29.95';
		$output = lafka_normalize_price_html( $input, null );
		$this->assertEquals( '$12.50 – $29.95', $output );
	}
}
