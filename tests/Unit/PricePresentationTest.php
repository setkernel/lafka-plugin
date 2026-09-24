<?php
declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/incl/woocommerce/lafka-price-presentation.php';

/**
 * Price HTML normalization: no superscript cents, en-dash ranges, no
 * "Price range:" prefix.
 */
final class PricePresentationTest extends TestCase {

	/** @return array<string, array{0: string, 1: string}> */
	public static function prices(): array {
		return array(
			'superscript cents'   => array( '<span>$25<sup>.99</sup></span>', '<span>$25.99</span>' ),
			'"through" range'     => array( '$12.50 through $29.95', '$12.50 – $29.95' ),
			'"Price range" label' => array( 'Price range: $12.50 – $29.95', '$12.50 – $29.95' ),
		);
	}

	#[DataProvider( 'prices' )]
	public function test_price_html_is_normalized( string $input, string $expected ): void {
		$this->assertSame( $expected, lafka_normalize_price_html( $input, null ) );
	}
}
