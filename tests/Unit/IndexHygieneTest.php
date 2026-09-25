<?php
/**
 * GX3 sitemap & robots hygiene (incl/seo/lafka-sitemap.php + lafka-robots.php):
 *
 *   - attribute (`pa_*`) and legacy food-menu taxonomies leave the sitemap
 *     and are noindexed; the legacy `lafka-foodmenu` CPT too, once
 *     WooCommerce products are the menu;
 *   - author archives are noindexed on a single-author site (filterable);
 *   - posts marked "hide from search engines" leave the sitemap + noindex;
 *   - noindex keeps `follow` and never touches regular pages;
 *   - product entries gain <image:image> tags through the image-aware
 *     renderer (and only then).
 *
 * @package Lafka\Plugin\Tests\Unit
 */

declare(strict_types=1);

namespace {
	require_once __DIR__ . '/Stubs/wp-post-stub.php';
	if ( ! class_exists( 'WP_Sitemaps_Renderer' ) ) {
		// Minimal parent: core's constructor sets $stylesheet.
		class WP_Sitemaps_Renderer { // phpcs:ignore
			protected $stylesheet = '';
		}
	}
}

namespace LafkaPlugin\Tests\Unit {

	use Brain\Monkey;
	use Brain\Monkey\Functions;
	use PHPUnit\Framework\TestCase;

	final class IndexHygieneTest extends TestCase {

		/** @var array<string, bool|callable> */
		private array $is = array();

		/** @var array<string, mixed> */
		private array $filters = array();

		/** @var array<int, array<string, mixed>> */
		private array $meta = array();

		protected function setUp(): void {
			parent::setUp();
			Monkey\setUp();
			$this->is      = array();
			$this->filters = array();
			$this->meta    = array();
			unset( $GLOBALS['lafka_sitemap_images'] );

			Functions\when( 'apply_filters' )->alias(
				fn( $hook, $value ) => array_key_exists( $hook, $this->filters ) ? $this->filters[ $hook ] : $value
			);
			Functions\when( 'get_taxonomies' )->justReturn( array( 'category', 'product_cat', 'pa_size', 'pa_crust', 'lafka_foodmenu_category' ) );
			Functions\when( 'wc_get_products' )->justReturn( array() );
			Functions\when( 'get_users' )->justReturn( array( 1 ) );
			Functions\when( 'get_post_meta' )->alias( fn( $id, $key ) => $this->meta[ $id ][ $key ] ?? '' );
			Functions\when( 'get_queried_object_id' )->justReturn( 7 );
			Functions\when( 'is_tax' )->alias( fn( $tax = '' ) => ! empty( $this->is['tax'] ) && in_array( $this->is['tax'], (array) $tax, true ) );
			Functions\when( 'is_singular' )->alias( fn( $types = '' ) => ! empty( $this->is['singular'] ) && ( '' === $types || in_array( $this->is['singular'], (array) $types, true ) ) );
			Functions\when( 'is_post_type_archive' )->alias( fn( $types = '' ) => ! empty( $this->is['archive'] ) && in_array( $this->is['archive'], (array) $types, true ) );
			Functions\when( 'is_author' )->alias( fn() => ! empty( $this->is['author'] ) );
			Functions\when( 'is_account_page' )->alias( fn() => ! empty( $this->is['account'] ) );
			Functions\when( 'get_post' )->alias( fn() => (object) array( 'post_content' => $this->is['content'] ?? '' ) );

			require_once dirname( __DIR__, 2 ) . '/incl/seo/lafka-sitemap.php';
			require_once dirname( __DIR__, 2 ) . '/incl/seo/lafka-robots.php';
		}

		protected function tearDown(): void {
			unset( $GLOBALS['lafka_sitemap_images'] );
			Monkey\tearDown();
			parent::tearDown();
		}

		private function robots(): array {
			return lafka_seo_wp_robots( array( 'max-image-preview' => 'large' ) );
		}

		public function test_attribute_and_legacy_taxonomies_leave_the_sitemap(): void {
			$kept = lafka_sitemap_filter_taxonomies(
				array(
					'category'                => 1,
					'product_cat'             => 1,
					'pa_size'                 => 1,
					'pa_crust'                => 1,
					'lafka_foodmenu_category' => 1,
				)
			);
			self::assertSame( array( 'category', 'product_cat' ), array_keys( $kept ) );
		}

		public function test_legacy_cpt_leaves_the_sitemap_only_when_woocommerce_is_the_menu(): void {
			self::assertSame( array( 'page', 'product' ), array_keys( lafka_sitemap_filter_post_types( array( 'page' => 1, 'product' => 1, 'lafka-foodmenu' => 1 ) ) ) );

			$this->filters['lafka_seo_legacy_post_types'] = array();
			self::assertArrayHasKey( 'lafka-foodmenu', lafka_sitemap_filter_post_types( array( 'lafka-foodmenu' => 1 ) ) );
		}

		public function test_noindexed_posts_leave_the_sitemap(): void {
			$args = lafka_sitemap_exclude_noindexed( array( 'post_type' => 'page' ) );
			self::assertSame( array( array( 'key' => '_lafka_seo_noindex', 'compare' => 'NOT EXISTS' ) ), $args['meta_query'] );

			$args = lafka_sitemap_exclude_noindexed( array( 'meta_query' => array( array( 'key' => 'x' ) ) ) );
			self::assertSame( 'AND', $args['meta_query']['relation'] );
		}

		public function test_thin_archives_are_noindex_follow(): void {
			$this->is = array( 'tax' => 'pa_size' );
			self::assertSame( array( 'max-image-preview' => 'large', 'noindex' => true, 'follow' => true ), $this->robots() );

			$this->is = array( 'singular' => 'lafka-foodmenu' );
			self::assertTrue( $this->robots()['noindex'] ?? false );

			$this->is = array( 'archive' => 'lafka-foodmenu' );
			self::assertTrue( $this->robots()['noindex'] ?? false );
		}

		public function test_author_archives_follow_the_single_author_rule(): void {
			$this->is = array( 'author' => true );
			self::assertTrue( $this->robots()['noindex'] ?? false, 'single-author site' );

			$this->filters['lafka_seo_noindex_author_archives'] = false;
			self::assertArrayNotHasKey( 'noindex', $this->robots() );
		}

		public function test_operator_hidden_pages_are_noindexed_and_others_untouched(): void {
			$this->is = array( 'singular' => 'page' );
			self::assertSame( array( 'max-image-preview' => 'large' ), $this->robots() );

			$this->meta[7]['_lafka_seo_noindex'] = '1';
			self::assertTrue( $this->robots()['noindex'] ?? false );

			$this->is = array( 'tax' => 'product_cat' );
			self::assertArrayNotHasKey( 'noindex', $this->robots() );
		}

		public function test_the_account_area_is_noindexed(): void {
			$this->is = array( 'account' => true );
			self::assertTrue( $this->robots()['noindex'] ?? false, 'is_account_page()' );

			// A page carrying the account shortcode while WooCommerce's
			// "My account page" setting is unset still is the account area.
			$this->is = array(
				'singular' => 'page',
				'content'  => '[vc_row][vc_column][woocommerce_my_account][/vc_column][/vc_row]',
			);
			self::assertTrue( $this->robots()['noindex'] ?? false, 'shortcode page' );

			$this->is = array(
				'singular' => 'page',
				'content'  => '<p>About us</p>',
			);
			self::assertArrayNotHasKey( 'noindex', $this->robots() );
		}

		public function test_product_image_entries_only_with_the_image_renderer(): void {
			Functions\when( 'get_post_thumbnail_id' )->justReturn( 5 );
			Functions\when( 'absint' )->alias( static fn( $v ) => abs( (int) $v ) );
			Functions\when( 'wp_get_attachment_image_url' )->alias( static fn( $id ) => 'https://example.test/img-' . $id . '.jpg' );
			$this->meta[3]['_product_image_gallery'] = '6,5,0';
			$post                                    = new \WP_Post( (object) array( 'ID' => 3 ) );

			self::assertSame( array( 'loc' => 'x' ), lafka_sitemap_product_images( array( 'loc' => 'x' ), $post, 'product' ) );

			$sitemaps = new \stdClass();
			lafka_sitemap_use_image_renderer( $sitemaps );
			self::assertInstanceOf( \Lafka_Sitemaps_Image_Renderer::class, $sitemaps->renderer );

			self::assertSame(
				array( 'loc' => 'x', 'lafka_images' => array( 'https://example.test/img-5.jpg', 'https://example.test/img-6.jpg' ) ),
				lafka_sitemap_product_images( array( 'loc' => 'x' ), $post, 'product' )
			);
			self::assertSame( array( 'loc' => 'y' ), lafka_sitemap_product_images( array( 'loc' => 'y' ), $post, 'page' ) );
		}

		public function test_image_renderer_writes_image_tags(): void {
			Functions\when( 'esc_url' )->returnArg();
			Functions\when( 'esc_xml' )->returnArg();
			require_once dirname( __DIR__, 2 ) . '/incl/seo/class-lafka-sitemaps-image-renderer.php';

			$xml = ( new \Lafka_Sitemaps_Image_Renderer() )->get_sitemap_xml(
				array(
					array(
						'loc'          => 'https://example.test/product/a/',
						'lastmod'      => '2026-09-25T00:00:00+00:00',
						'lafka_images' => array( 'https://example.test/a.jpg' ),
					),
					array( 'loc' => 'https://example.test/about/' ),
				)
			);

			$doc = simplexml_load_string( (string) $xml );
			self::assertNotFalse( $doc );
			self::assertSame( 'https://example.test/product/a/', (string) $doc->url[0]->loc );
			$images = $doc->url[0]->children( \Lafka_Sitemaps_Image_Renderer::IMAGE_NS );
			self::assertSame( 'https://example.test/a.jpg', (string) $images->image->loc );
			self::assertCount( 0, $doc->url[1]->children( \Lafka_Sitemaps_Image_Renderer::IMAGE_NS ) );
		}
	}
}
