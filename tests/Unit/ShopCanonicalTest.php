<?php
/**
 * Shop/product-taxonomy archive canonical (T-09): the clean archive URL —
 * the term's own get_term_link() (or the shop page), `/page/N/` on paginated
 * archives — with EVERY request query arg dropped (sort, filter, utm_*,
 * fbclid …); exactly one canonical tag on the page.
 *
 * @package Lafka_Plugin
 */

declare(strict_types=1);

namespace {
	require_once __DIR__ . '/Stubs/wp-term-stub.php';
}

namespace LafkaPlugin\Tests\Unit {

	use Brain\Monkey;
	use Brain\Monkey\Functions;
	use PHPUnit\Framework\TestCase;

	final class ShopCanonicalTest extends TestCase {

		private string $requested = 'https://example.test/shop/?orderby=price&min_price=10&filter_size=large&utm_source=news&fbclid=abc';
		private int $paged        = 0;
		private bool $taxonomy    = false;
		private $queried          = null;
		private string $structure = '/%postname%/';

		protected function setUp(): void {
			parent::setUp();
			Monkey\setUp();
			$this->paged     = 0;
			$this->taxonomy  = false;
			$this->queried   = null;
			$this->structure = '/%postname%/';
			Functions\when( 'is_admin' )->justReturn( false );
			Functions\when( 'is_shop' )->alias( fn() => ! $this->taxonomy );
			Functions\when( 'is_product_taxonomy' )->alias( fn() => $this->taxonomy );
			Functions\when( 'get_query_var' )->alias( fn() => $this->paged );
			Functions\when( 'get_queried_object' )->alias( fn() => $this->queried );
			Functions\when( 'get_term_link' )->alias( static fn( $term ) => 'https://example.test/menu/' . $term->slug . '/' );
			Functions\when( 'wc_get_page_permalink' )->justReturn( 'https://example.test/shop/' );
			Functions\when( 'get_option' )->alias( fn( $key, $default = false ) => 'permalink_structure' === $key ? $this->structure : $default );
			Functions\when( 'is_wp_error' )->alias( static fn( $v ) => $v instanceof \stdClass );
			Functions\when( 'get_pagenum_link' )->alias(
				fn( $n ) => $n >= 2 ? str_replace( '/shop/', '/shop/page/' . $n . '/', $this->requested ) : $this->requested
			);
			Functions\when( 'wp_parse_url' )->alias( static fn( $url, $component = -1 ) => parse_url( $url, $component ) );
			Functions\when( 'trailingslashit' )->alias( static fn( $url ) => rtrim( (string) $url, '/' ) . '/' );
			Functions\when( 'add_query_arg' )->alias( static fn( $key, $value, $url ) => $url . ( false === strpos( $url, '?' ) ? '?' : '&' ) . $key . '=' . $value );
			Functions\when( 'apply_filters' )->returnArg( 2 );
			Functions\when( 'esc_url' )->returnArg();
			Functions\when( 'lafka_seo_plugin_active' )->justReturn( false );
			require_once dirname( __DIR__, 2 ) . '/incl/seo/lafka-shop-canonical.php';
		}

		protected function tearDown(): void {
			Monkey\tearDown();
			parent::tearDown();
		}

		public function test_every_query_arg_is_dropped_from_the_shop_canonical(): void {
			$this->assertSame( 'https://example.test/shop/', lafka_seo_shop_canonical_url() );
		}

		public function test_paginated_archives_self_canonicalise_without_args(): void {
			$this->paged = 3;
			$this->assertSame( 'https://example.test/shop/page/3/', lafka_seo_shop_canonical_url() );
		}

		public function test_a_term_archive_canonicalises_to_its_term_link(): void {
			$this->taxonomy = true;
			$this->queried  = new \WP_Term( array( 'term_id' => 4, 'slug' => 'wings', 'taxonomy' => 'product_cat' ) );
			$this->assertSame( 'https://example.test/menu/wings/', lafka_seo_shop_canonical_url() );

			$this->paged = 2;
			$this->assertSame( 'https://example.test/menu/wings/page/2/', lafka_seo_shop_canonical_url() );
		}

		public function test_plain_permalinks_keep_the_term_query_and_page_by_arg(): void {
			$this->taxonomy  = true;
			$this->structure = '';
			$this->queried   = new \WP_Term( array( 'term_id' => 4, 'slug' => 'wings', 'taxonomy' => 'product_cat' ) );
			Functions\when( 'get_term_link' )->justReturn( 'https://example.test/?product_cat=wings' );
			$this->paged = 2;
			$this->assertSame( 'https://example.test/?product_cat=wings&paged=2', lafka_seo_shop_canonical_url() );
		}

		public function test_emits_one_canonical_tag(): void {
			ob_start();
			lafka_seo_emit_shop_canonical();
			$this->assertSame( '<link rel="canonical" href="https://example.test/shop/" />' . "\n", ob_get_clean() );
		}

		public function test_leaves_the_tag_to_an_active_seo_plugin(): void {
			Functions\when( 'lafka_seo_plugin_active' )->justReturn( true );

			ob_start();
			lafka_seo_emit_shop_canonical();
			$this->assertSame( '', ob_get_clean() );
		}
	}
}
