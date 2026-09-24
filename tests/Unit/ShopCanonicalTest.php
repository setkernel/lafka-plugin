<?php
/**
 * Shop/product-taxonomy archive canonical: sort/filter params stripped,
 * pagination preserved, exactly one canonical tag on the page.
 *
 * @package Lafka_Plugin
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class ShopCanonicalTest extends TestCase {

	private string $requested = 'https://example.test/shop/?orderby=price&min_price=10&filter_size=large&utm_source=news';
	private int $paged = 0;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'is_shop' )->justReturn( true );
		Functions\when( 'is_product_taxonomy' )->justReturn( false );
		Functions\when( 'get_query_var' )->alias( fn() => $this->paged );
		Functions\when( 'get_pagenum_link' )->alias(
			fn( $n ) => $n >= 2 ? str_replace( '/shop/', '/shop/page/' . $n . '/', $this->requested ) : $this->requested
		);
		Functions\when( 'wp_parse_url' )->alias( static fn( $url ) => parse_url( $url ) );
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'lafka_seo_plugin_active' )->justReturn( false );
		require_once dirname( __DIR__, 2 ) . '/incl/seo/lafka-shop-canonical.php';
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_sort_and_filter_params_are_stripped(): void {
		$this->assertSame( 'https://example.test/shop/?utm_source=news', lafka_seo_shop_canonical_url() );
	}

	public function test_paginated_archives_self_canonicalise(): void {
		$this->paged = 3;
		$this->assertSame( 'https://example.test/shop/page/3/?utm_source=news', lafka_seo_shop_canonical_url() );
	}

	public function test_emits_one_canonical_tag(): void {
		ob_start();
		lafka_seo_emit_shop_canonical();
		$this->assertSame( '<link rel="canonical" href="https://example.test/shop/?utm_source=news" />' . "\n", ob_get_clean() );
	}

	public function test_leaves_the_tag_to_an_active_seo_plugin(): void {
		Functions\when( 'lafka_seo_plugin_active' )->justReturn( true );

		ob_start();
		lafka_seo_emit_shop_canonical();
		$this->assertSame( '', ob_get_clean() );
	}
}
