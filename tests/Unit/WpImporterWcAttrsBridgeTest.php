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
 *   - Returns the posts array unchanged (filter passthrough).
 *
 * @package Lafka\Plugin\Tests\Unit
 * @since   9.7.18
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;
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

	/**
	 * Inputs that must create nothing: non-product posts (a stray `pa_*` slug on
	 * a page), non-attribute taxonomies, and malformed / empty WXR entries.
	 *
	 * @param array<int, mixed> $posts WXR posts.
	 */
	#[DataProvider( 'inert_posts_provider' )]
	public function test_creates_nothing_for_inert_input( array $posts ): void {
		$this->assertSame( $posts, \lafka_compat_wp_importer_create_missing_wc_attrs( $posts ) );
		$this->assertSame( array(), $this->created );
	}

	/**
	 * @return array<string, array{0: array<int, mixed>}>
	 */
	public static function inert_posts_provider(): array {
		$pa_size = array( array( 'domain' => 'pa_size', 'name' => 'Large', 'slug' => 'large' ) );
		return array(
			'no posts'             => array( array() ),
			'non-product post'     => array( array( array( 'post_type' => 'page', 'terms' => $pa_size ) ) ),
			'non-pa taxonomies'    => array(
				array(
					array(
						'post_type' => 'product',
						'terms'     => array(
							array( 'domain' => 'product_cat', 'name' => 'Mains', 'slug' => 'mains' ),
							array( 'domain' => 'product_tag', 'name' => 'Featured', 'slug' => 'featured' ),
							array( 'domain' => 'custom_tax', 'name' => 'Foo', 'slug' => 'foo' ),
						),
					),
				),
			),
			'product without terms' => array(
				array(
					array( 'post_type' => 'product' ),
					array( 'post_type' => 'product', 'terms' => array() ),
					array( 'post_type' => 'product', 'terms' => 'malformed' ),
				),
			),
			'term without domain'  => array( array( array( 'post_type' => 'product', 'terms' => array( array( 'name' => 'Orphan', 'slug' => 'orphan' ) ) ) ) ),
		);
	}

	public function test_non_array_input_passes_through_untouched(): void {
		// A broken upstream filter chain must fail closed (no creates), not fatal.
		$this->assertSame( 'not-an-array', \lafka_compat_wp_importer_create_missing_wc_attrs( 'not-an-array' ) );
		$this->assertSame( array(), $this->created );
	}
}
