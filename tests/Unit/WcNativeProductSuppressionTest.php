<?php
declare(strict_types=1);
namespace LafkaPlugin\Tests\Unit;
use PHPUnit\Framework\TestCase;

final class WcNativeProductSuppressionTest extends TestCase {
	public function test_woocommerce_structured_data_product_filter_registered(): void {
		$src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/schema/class-lafka-json-ld.php' );
		$this->assertMatchesRegularExpression(
			"/add_filter\(\s*['\"]woocommerce_structured_data_product['\"]/",
			$src
		);
	}
	public function test_lafka_schema_keep_wc_native_product_filter_exposed(): void {
		$src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/schema/class-lafka-json-ld.php' );
		$this->assertStringContainsString( 'lafka_schema_keep_wc_native_product', $src );
	}
}
