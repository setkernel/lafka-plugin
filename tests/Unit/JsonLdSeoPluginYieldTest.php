<?php
/**
 * When a dedicated SEO plugin owns structured data, Lafka emits no graph —
 * so it must also leave WooCommerce's own Product schema in place, or product
 * pages end up with no Product schema at all.
 *
 * @package Lafka_Plugin
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class JsonLdSeoPluginYieldTest extends TestCase {

	private const WC_PRODUCT = array(
		'@type' => 'Product',
		'name'  => 'Margherita',
	);

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		require_once dirname( __DIR__, 2 ) . '/incl/schema/class-lafka-json-ld.php';
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_wc_product_schema_is_kept_when_an_seo_plugin_owns_schema(): void {
		Functions\when( 'lafka_seo_plugin_active' )->justReturn( true );

		$this->assertSame( self::WC_PRODUCT, lafka_schema_suppress_wc_native_product( self::WC_PRODUCT ) );
	}

	public function test_wc_product_schema_is_replaced_when_lafka_emits_its_own(): void {
		Functions\when( 'lafka_seo_plugin_active' )->justReturn( false );

		$this->assertSame( array(), lafka_schema_suppress_wc_native_product( self::WC_PRODUCT ) );
	}

	public function test_forcing_lafka_schema_over_an_seo_plugin_replaces_wc_product_schema(): void {
		Functions\when( 'lafka_seo_plugin_active' )->justReturn( true );
		Monkey\Filters\expectApplied( 'lafka_schema_force_emit' )->andReturn( true );

		$this->assertSame( array(), lafka_schema_suppress_wc_native_product( self::WC_PRODUCT ) );
	}

	public function test_operators_can_keep_wc_product_schema_alongside_lafka(): void {
		Functions\when( 'lafka_seo_plugin_active' )->justReturn( false );
		Monkey\Filters\expectApplied( 'lafka_schema_keep_wc_native_product' )->andReturn( true );

		$this->assertSame( self::WC_PRODUCT, lafka_schema_suppress_wc_native_product( self::WC_PRODUCT ) );
	}
}
