<?php
/**
 * WpImporterWcAttrsBridgeTest — locks down the WP-Importer ↔ WC-attributes
 * bridge added in v9.7.18.
 *
 * Covers:
 *   - Idempotent: existing taxonomies skipped (no double-create).
 *   - Per-post + per-term iteration (multiple `pa_*` terms per product).
 *   - Non-product posts and non-`pa_*` terms ignored.
 *   - Invalid input (non-array, missing keys) handled defensively.
 *   - Filter is registered as wp_import_posts.
 *   - Returns the posts array unchanged (filter passthrough).
 *
 * @package Lafka\Plugin\Tests\Unit
 * @since   9.7.18
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/incl/compat/wp-importer-wc-attrs-bridge.php';

final class WpImporterWcAttrsBridgeTest extends TestCase {

	/**
	 * Captures wc_create_attribute and register_taxonomy calls so each test
	 * can assert what would have been created.
	 *
	 * @var array<int, array{0:string, 1:array}>
	 */
	private array $created = array();

	/**
	 * Existing taxonomies — the bridge must skip these. Tests override.
	 *
	 * @var array<string, bool>
	 */
	private array $existing_taxonomies = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->created             = array();
		$this->existing_taxonomies = array();

		Functions\when( 'wc_sanitize_taxonomy_name' )->returnArg();
		Functions\when( 'taxonomy_exists' )->alias(
			fn( $tax ) => isset( $this->existing_taxonomies[ $tax ] )
		);
		Functions\when( 'wc_create_attribute' )->alias(
			function ( $args ) {
				$this->created[] = array( 'wc_create_attribute', $args );
				return 1;
			}
		);
		Functions\when( 'register_taxonomy' )->alias(
			function ( $taxonomy, $object_type, $args ) {
				$this->created[] = array( 'register_taxonomy', compact( 'taxonomy', 'object_type', 'args' ) );
			}
		);
		Functions\when( 'apply_filters' )->returnArg( 2 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_creates_missing_attribute_taxonomy(): void {
		$posts = array(
			array(
				'post_type' => 'product',
				'terms'     => array(
					array( 'domain' => 'pa_size', 'name' => 'Large', 'slug' => 'large' ),
				),
			),
		);

		$result = \lafka_compat_wp_importer_create_missing_wc_attrs( $posts );

		$this->assertSame( $posts, $result, 'Filter must passthrough the posts array unchanged.' );

		$this->assertCount( 2, $this->created, 'One create + one register call expected.' );
		$this->assertSame( 'wc_create_attribute', $this->created[0][0] );
		$this->assertSame( 'size', $this->created[0][1]['name'] );
		$this->assertSame( 'register_taxonomy', $this->created[1][0] );
		$this->assertSame( 'pa_size', $this->created[1][1]['taxonomy'] );
	}

	public function test_skips_taxonomies_that_already_exist(): void {
		// Idempotent: re-running an import after WC has registered pa_color
		// must not re-create or re-register the taxonomy.
		$this->existing_taxonomies['pa_color'] = true;

		$posts = array(
			array(
				'post_type' => 'product',
				'terms'     => array(
					array( 'domain' => 'pa_color', 'name' => 'Red', 'slug' => 'red' ),
				),
			),
		);

		\lafka_compat_wp_importer_create_missing_wc_attrs( $posts );

		$this->assertCount( 0, $this->created, 'Existing taxonomy must not trigger create or register.' );
	}

	public function test_skips_non_product_posts(): void {
		// A WXR file contains posts/pages/products/etc. Bridge must only
		// react to product posts so a `pa_*` slug accidentally appearing on
		// a non-product post can't trigger taxonomy creation.
		$posts = array(
			array(
				'post_type' => 'page',
				'terms'     => array(
					array( 'domain' => 'pa_size', 'name' => 'Large', 'slug' => 'large' ),
				),
			),
		);

		\lafka_compat_wp_importer_create_missing_wc_attrs( $posts );

		$this->assertCount( 0, $this->created );
	}

	public function test_skips_non_pa_taxonomies(): void {
		// product_cat / product_tag / custom taxonomies must not be
		// auto-created — only WC product attributes (the `pa_*` prefix).
		$posts = array(
			array(
				'post_type' => 'product',
				'terms'     => array(
					array( 'domain' => 'product_cat', 'name' => 'Pizzas', 'slug' => 'pizzas' ),
					array( 'domain' => 'product_tag', 'name' => 'Featured', 'slug' => 'featured' ),
					array( 'domain' => 'custom_tax', 'name' => 'Foo', 'slug' => 'foo' ),
				),
			),
		);

		\lafka_compat_wp_importer_create_missing_wc_attrs( $posts );

		$this->assertCount( 0, $this->created );
	}

	public function test_creates_each_unique_pa_taxonomy_once_per_product(): void {
		// Multi-attribute product (size + colour). Both must get created.
		$posts = array(
			array(
				'post_type' => 'product',
				'terms'     => array(
					array( 'domain' => 'pa_size', 'name' => 'Large' ),
					array( 'domain' => 'pa_color', 'name' => 'Red' ),
				),
			),
		);

		\lafka_compat_wp_importer_create_missing_wc_attrs( $posts );

		$created_taxonomies = array();
		foreach ( $this->created as $call ) {
			if ( 'register_taxonomy' === $call[0] ) {
				$created_taxonomies[] = $call[1]['taxonomy'];
			}
		}
		$this->assertContains( 'pa_size', $created_taxonomies );
		$this->assertContains( 'pa_color', $created_taxonomies );
	}

	public function test_handles_empty_posts_array(): void {
		$result = \lafka_compat_wp_importer_create_missing_wc_attrs( array() );
		$this->assertSame( array(), $result );
		$this->assertCount( 0, $this->created );
	}

	public function test_handles_non_array_input(): void {
		// Defensive — if a future filter chain breaks the contract and feeds
		// a non-array, the bridge must fail closed (no creates) rather than fatal.
		$result = \lafka_compat_wp_importer_create_missing_wc_attrs( 'not-an-array' );
		$this->assertSame( 'not-an-array', $result );
		$this->assertCount( 0, $this->created );
	}

	public function test_handles_post_without_terms(): void {
		$posts = array(
			array( 'post_type' => 'product' ),
			array( 'post_type' => 'product', 'terms' => array() ),
			array( 'post_type' => 'product', 'terms' => 'malformed' ),
		);

		\lafka_compat_wp_importer_create_missing_wc_attrs( $posts );

		$this->assertCount( 0, $this->created );
	}

	public function test_handles_term_with_missing_domain(): void {
		$posts = array(
			array(
				'post_type' => 'product',
				'terms'     => array(
					array( 'name' => 'Orphan', 'slug' => 'orphan' ),
				),
			),
		);

		\lafka_compat_wp_importer_create_missing_wc_attrs( $posts );

		$this->assertCount( 0, $this->created );
	}

	public function test_filter_registered_on_wp_import_posts(): void {
		$src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/compat/wp-importer-wc-attrs-bridge.php' );
		$this->assertMatchesRegularExpression(
			"/add_filter\(\s*'wp_import_posts'\s*,\s*'lafka_compat_wp_importer_create_missing_wc_attrs'\s*\)/",
			$src,
			'Bridge must register on wp_import_posts (upstream WP Importer hook).'
		);
	}
}
