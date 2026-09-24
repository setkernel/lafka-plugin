<?php
/**
 * The add-ons module coexists with the official WooCommerce Product Add-Ons
 * extension: it must not claim that vendor's constants.
 *
 * @package Lafka_Plugin
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit\Addons;

use Brain\Monkey;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 3 ) . '/incl/addons/lafka-product-addons.php';

final class EngineLoadedTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_booting_alongside_woocommerce_product_addons_leaves_its_version_alone(): void {
		if ( ! defined( 'WC_PRODUCT_ADDONS_VERSION' ) ) {
			define( 'WC_PRODUCT_ADDONS_VERSION', '7.0.0' ); // As the real extension would.
		}
		$vendor_version = WC_PRODUCT_ADDONS_VERSION;

		new \Lafka_Product_Addons(); // A redefinition would raise a warning (failOnWarning).

		$this->assertSame( $vendor_version, WC_PRODUCT_ADDONS_VERSION );
	}
}
