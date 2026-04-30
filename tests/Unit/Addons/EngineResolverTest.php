<?php
declare(strict_types=1);
namespace LafkaPlugin\Tests\Unit\Addons;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Lafka_Addon_Group;
use Lafka_Addon_Repository;
use Lafka_Addon_Schema;
use Lafka_Addons_Upgrader;
use Lafka_Engine_Resolver;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 3 ) . '/incl/addons/engine/lafka-addons-engine-bootstrap.php';

/**
 * Resolver walks parent + product + global all-products + global category
 * scopes and returns merged Lafka_Addon_Group[] sorted by priority.
 */
final class EngineResolverTest extends TestCase {

	private array $stored_meta = array();
	private array $term_ids    = array();
	private array $exclude_global = array();
	private array $parent_of   = array();
	private array $global_posts = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Lafka_Engine_Resolver::clear_cache();
		$this->stored_meta    = array();
		$this->term_ids       = array();
		$this->exclude_global = array();
		$this->parent_of      = array();
		$this->global_posts   = array();

		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'wp_generate_uuid4' )->justReturn( 'test-uuid-0000' );
		Functions\when( 'apply_filters' )->returnArg( 2 );

		Functions\when( 'get_post_meta' )->alias(
			function ( $post_id, $key, $single = false ) {
				if ( '_product_addons' === $key ) {
					return $this->stored_meta[ $post_id ] ?? array();
				}
				if ( '_priority' === $key ) {
					return $this->global_posts[ $post_id ]['priority'] ?? 10;
				}
				return '';
			}
		);

		Functions\when( 'wp_get_post_parent_id' )->alias(
			fn( $id ) => $this->parent_of[ $id ] ?? 0
		);

		Functions\when( 'wc_get_object_terms' )->alias(
			fn( $id, $tax, $field ) => $this->term_ids[ $id ] ?? array()
		);

		Functions\when( 'wc_get_attribute' )->justReturn( null );
		Functions\when( 'get_posts' )->alias(
			function ( $args ) {
				$out = array();
				foreach ( $this->global_posts as $id => $info ) {
					if ( ! empty( $args['meta_query'] ) ) {
						// _all_products = 1 query
						$query = $args['meta_query'][0] ?? null;
						if ( $query && '_all_products' === ( $query['key'] ?? '' ) ) {
							if ( '1' === ( $info['all_products'] ?? '0' ) ) {
								$out[] = (object) array( 'ID' => $id );
							}
						}
					}
					if ( ! empty( $args['tax_query'] ) ) {
						// product_cat tax query
						$query = $args['tax_query'][0] ?? null;
						$want  = $query['terms'] ?? array();
						$has   = $info['cat_ids'] ?? array();
						if ( array_intersect( $want, $has ) ) {
							$out[] = (object) array( 'ID' => $id );
						}
					}
				}
				return $out;
			}
		);

		// Mock wc_get_product to return an object with predictable get_meta + get_attributes.
		Functions\when( 'wc_get_product' )->alias(
			function ( $id ) {
				$exclude = $this->exclude_global[ $id ] ?? false;
				return new class( $id, $exclude ) {
					public function __construct( public int $id, private bool $excludes ) {}
					public function get_meta( $key ) {
						if ( '_product_addons_exclude_global' === $key ) {
							return $this->excludes ? '1' : '';
						}
						return '';
					}
					public function get_attributes() {
						return array();
					}
				};
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		Lafka_Engine_Resolver::clear_cache();
		parent::tearDown();
	}

	private function group( string $name ): array {
		return Lafka_Addon_Group::from_array( array( 'name' => $name ) )->to_array();
	}

	private function resolver(): Lafka_Engine_Resolver {
		return new Lafka_Engine_Resolver(
			new Lafka_Addon_Repository( new Lafka_Addons_Upgrader() )
		);
	}

	public function test_returns_empty_when_product_id_invalid(): void {
		self::assertSame( array(), $this->resolver()->resolve_for_product( 0 ) );
	}

	public function test_returns_only_product_groups_when_no_parent_no_globals(): void {
		$this->stored_meta[42] = array( $this->group( 'Toppings' ) );
		$result = $this->resolver()->resolve_for_product( 42 );
		self::assertCount( 1, $result );
		self::assertSame( 'Toppings', $result[0]->name );
	}

	public function test_includes_parent_groups_before_product_groups(): void {
		$this->parent_of[ 50 ]    = 49;
		$this->stored_meta[ 49 ]  = array( $this->group( 'Parent Group' ) );
		$this->stored_meta[ 50 ]  = array( $this->group( 'Variation Group' ) );

		$result = $this->resolver()->resolve_for_product( 50 );
		self::assertCount( 2, $result );
		self::assertSame( 'Parent Group', $result[0]->name );
		self::assertSame( 'Variation Group', $result[1]->name );
	}

	public function test_skips_parent_when_inc_parent_false(): void {
		$this->parent_of[ 50 ]    = 49;
		$this->stored_meta[ 49 ]  = array( $this->group( 'Parent Group' ) );
		$this->stored_meta[ 50 ]  = array( $this->group( 'Variation Group' ) );

		$result = $this->resolver()->resolve_for_product( 50, false );
		self::assertCount( 1, $result );
		self::assertSame( 'Variation Group', $result[0]->name );
	}

	public function test_includes_global_all_products(): void {
		$this->stored_meta[ 60 ]      = array( $this->group( 'Product Group' ) );
		$this->stored_meta[ 200 ]     = array( $this->group( 'Global All' ) );
		$this->global_posts[ 200 ]    = array( 'all_products' => '1', 'priority' => 5 );

		$result = $this->resolver()->resolve_for_product( 60 );
		self::assertCount( 2, $result );
		// Priority 5 < 10, so global comes first
		self::assertSame( 'Global All', $result[0]->name );
		self::assertSame( 'Product Group', $result[1]->name );
	}

	public function test_excludes_globals_when_meta_set(): void {
		$this->exclude_global[ 70 ]   = true;
		$this->stored_meta[ 70 ]      = array( $this->group( 'Product Group' ) );
		$this->stored_meta[ 300 ]     = array( $this->group( 'Global Excluded' ) );
		$this->global_posts[ 300 ]    = array( 'all_products' => '1', 'priority' => 1 );

		$result = $this->resolver()->resolve_for_product( 70 );
		self::assertCount( 1, $result );
		self::assertSame( 'Product Group', $result[0]->name );
	}

	public function test_includes_category_globals(): void {
		$this->term_ids[ 80 ]         = array( 17 );
		$this->stored_meta[ 80 ]      = array( $this->group( 'Product Group' ) );
		$this->stored_meta[ 400 ]     = array( $this->group( 'Pizza Cat Addons' ) );
		$this->global_posts[ 400 ]    = array( 'cat_ids' => array( 17 ), 'priority' => 8 );

		$result = $this->resolver()->resolve_for_product( 80 );
		self::assertCount( 2, $result );
		self::assertSame( 'Pizza Cat Addons', $result[0]->name );
		self::assertSame( 'Product Group', $result[1]->name );
	}

	public function test_per_request_cache_avoids_rebuild(): void {
		$call_count = 0;
		Functions\when( 'get_post_meta' )->alias(
			function ( $post_id, $key, $single = false ) use ( &$call_count ) {
				if ( '_product_addons' === $key ) {
					$call_count++;
					return $this->stored_meta[ $post_id ] ?? array();
				}
				if ( '_priority' === $key ) {
					return 10;
				}
				return '';
			}
		);
		$this->stored_meta[ 90 ] = array( $this->group( 'Cached' ) );

		$resolver = $this->resolver();
		$first    = $resolver->resolve_for_product( 90 );
		$second   = $resolver->resolve_for_product( 90 );

		self::assertSame( $first, $second );
		// First call hits get_post_meta once for product groups; second hits
		// the cache and doesn't call again.
		self::assertSame( 1, $call_count );
	}
}
