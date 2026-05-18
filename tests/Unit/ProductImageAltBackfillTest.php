<?php
/**
 * ProductImageAltBackfillTest — locks the runtime backfill that
 * substitutes a WC product's display name for empty `alt=""` on
 * product images.
 *
 * The visual QA pass on 2026-05-18 found 104 of 108 product images on
 * /menu/ shipped with empty alt because the operator never populated
 * the attachment's `_wp_attachment_image_alt` meta. This filter is the
 * runtime safety net; the CLI command `wp lafka image-alts apply`
 * (incl/cli/lafka-image-alt-backfill.php) remains the durable fix.
 *
 * NOTE: This file uses bracketed namespaces so we can declare a minimal
 * WC_Product stub in the global namespace (required for the `instanceof`
 * check inside the filter) alongside the namespaced test class.
 *
 * @package Lafka\Plugin\Tests\Unit
 * @since   9.22.2
 */

declare(strict_types=1);

namespace {
	if ( ! class_exists( 'WC_Product' ) ) {
		// Minimal stub so the filter's `$x instanceof WC_Product` check
		// succeeds against test doubles that extend this class.
		class WC_Product {
			public function get_name( $context = 'view' ) {
				return '';
			}
		}
	}
}

namespace LafkaPlugin\Tests\Unit {

	use Brain\Monkey;
	use Brain\Monkey\Functions;
	use PHPUnit\Framework\TestCase;

	// Bring the module into scope. add_filter is stubbed as no-op in
	// tests/bootstrap.php so file-load registration doesn't blow up.
	require_once dirname( __DIR__, 2 ) . '/incl/woocommerce/lafka-product-image-alt.php';

	final class ProductImageAltBackfillTest extends TestCase {

		protected function setUp(): void {
			parent::setUp();
			Monkey\setUp();
		}

		protected function tearDown(): void {
			Monkey\tearDown();
			parent::tearDown();
		}

		// ────────────────────────────────────────────────────────────────────
		// Source-grep regressions
		// ────────────────────────────────────────────────────────────────────

		public function test_module_file_exists(): void {
			$this->assertFileExists(
				dirname( __DIR__, 2 ) . '/incl/woocommerce/lafka-product-image-alt.php'
			);
		}

		public function test_filter_registered_on_wp_get_attachment_image_attributes(): void {
			$src = file_get_contents(
				dirname( __DIR__, 2 ) . '/incl/woocommerce/lafka-product-image-alt.php'
			);
			$this->assertMatchesRegularExpression(
				"/add_filter\(\s*['\"]wp_get_attachment_image_attributes['\"]\s*,\s*['\"]lafka_backfill_product_image_alt['\"]/",
				$src,
				'Filter must be registered on wp_get_attachment_image_attributes'
			);
		}

		public function test_module_required_from_main_plugin(): void {
			$src = file_get_contents( dirname( __DIR__, 2 ) . '/lafka-plugin.php' );
			$this->assertStringContainsString(
				'incl/woocommerce/lafka-product-image-alt.php',
				$src,
				'Main plugin file must require the product-image-alt backfill module.'
			);
		}

		// ────────────────────────────────────────────────────────────────────
		// Functional behavior (Brain Monkey stubs)
		// ────────────────────────────────────────────────────────────────────

		/**
		 * When alt is already set the filter must return $attr untouched —
		 * operator-set alt always wins.
		 */
		public function test_existing_alt_text_is_preserved(): void {
			$attr       = array( 'alt' => 'Operator-set alt text', 'src' => 'foo.jpg' );
			$attachment = $this->make_attachment( 99, 0 );
			$result     = \lafka_backfill_product_image_alt( $attr, $attachment, 'thumbnail' );
			$this->assertSame( 'Operator-set alt text', $result['alt'] );
		}

		/**
		 * Branch 1: attachment has a product as its `post_parent`. Filter
		 * substitutes the product's display name.
		 */
		public function test_uses_parent_product_name_when_alt_empty(): void {
			$product = $this->make_product( 'Margherita Pizza' );
			Functions\when( 'wc_get_product' )->alias( function ( $id ) use ( $product ) {
				return ( 42 === (int) $id ) ? $product : null;
			} );

			$attr       = array( 'alt' => '', 'src' => 'pizza.jpg' );
			$attachment = $this->make_attachment( 99, 42 );
			$result     = \lafka_backfill_product_image_alt( $attr, $attachment, 'thumbnail' );

			$this->assertSame( 'Margherita Pizza', $result['alt'] );
		}

		/**
		 * Branch 1 fallthrough: parent post is not a WC product (e.g. a regular
		 * page attached an image). Filter must noop (no alt added) when
		 * Branch 2 lookup also returns nothing.
		 */
		public function test_noop_when_parent_is_not_a_product(): void {
			Functions\when( 'wc_get_product' )->justReturn( null );
			Functions\when( 'get_posts' )->justReturn( array() );

			$attr       = array( 'alt' => '', 'src' => 'foo.jpg' );
			$attachment = $this->make_attachment( 99, 7 );
			$result     = \lafka_backfill_product_image_alt( $attr, $attachment, 'thumbnail' );
			$this->assertSame( '', $result['alt'] );
		}

		/**
		 * Branch 2: attachment is unparented (post_parent = 0) but belongs to a
		 * product via `_thumbnail_id` / `_product_image_gallery` meta. The
		 * resolver should find it via the get_posts meta-query lookup.
		 */
		public function test_resolves_via_meta_query_for_unparented_attachment(): void {
			$product = $this->make_product( 'Garlic Fingers' );
			Functions\when( 'wc_get_product' )->alias( function ( $id ) use ( $product ) {
				return ( 77 === (int) $id ) ? $product : null;
			} );
			Functions\when( 'get_posts' )->justReturn( array( 77 ) );

			$attr       = array( 'alt' => '', 'src' => 'garlic.jpg' );
			$attachment = $this->make_attachment( 555, 0 );
			$result     = \lafka_backfill_product_image_alt( $attr, $attachment, 'thumbnail' );

			$this->assertSame( 'Garlic Fingers', $result['alt'] );
		}

		/**
		 * Bad attachment input (null / no ID) must noop without throwing.
		 */
		public function test_noop_for_invalid_attachment_object(): void {
			Functions\when( 'wc_get_product' )->justReturn( null );

			$attr   = array( 'alt' => '', 'src' => 'foo.jpg' );
			$result = \lafka_backfill_product_image_alt( $attr, null, 'thumbnail' );
			$this->assertSame( '', $result['alt'] );
		}

		// ────────────────────────────────────────────────────────────────────
		// Test helpers
		// ────────────────────────────────────────────────────────────────────

		/**
		 * Build a minimal WP_Post-like attachment stub with the two fields the
		 * filter reads (ID, post_parent).
		 */
		private function make_attachment( int $id, int $post_parent ): object {
			return (object) array(
				'ID'          => $id,
				'post_parent' => $post_parent,
			);
		}

		/**
		 * Build a WC_Product test double. Brain Monkey's wc_get_product stub
		 * returns this; the filter only calls ->get_name() on it.
		 */
		private function make_product( string $name ): \WC_Product {
			return new class( $name ) extends \WC_Product {
				public function __construct( private string $name ) {
				}
				public function get_name( $context = 'view' ) {
					return $this->name;
				}
			};
		}
	}
}
