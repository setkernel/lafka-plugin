<?php
/**
 * Regression (audit f014): REST PATCH /addon-groups/{id} on a product post
 * must NOT wipe the product's product_cat terms and must NOT write
 * group-assignment meta (_priority / _all_products) onto the product.
 *
 * Covers Lafka_Addons_REST_Groups_Controller::persist_via_engine():
 *   - product post     → persists only per-product groups (+ explicit
 *                        exclude_global), never touches terms or assignment meta
 *   - global addon CPT → keeps _priority / _all_products writes and only
 *                        replaces product_cat terms when category_ids was
 *                        explicitly supplied (defense-in-depth against the
 *                        empty arg default wiping existing scoping)
 *
 * @package Lafka\Plugin\Tests\Unit\Addons
 */

declare(strict_types=1);

namespace {

	// --- Global WP stubs needed to load the REST controller under unit tests.
	if ( ! class_exists( 'WP_REST_Controller' ) ) {
		// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
		class WP_REST_Controller { // phpcs:ignore
			protected $namespace;
			protected $rest_base;
		}
	}
	if ( ! class_exists( 'WP_Error' ) ) {
		// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
		class WP_Error {} // phpcs:ignore
	}

	require_once dirname( __DIR__, 1 ) . '/Stubs/wp-post-stub.php';
	require_once dirname( __DIR__, 3 ) . '/incl/addons/engine/lafka-addons-engine-bootstrap.php';
	require_once dirname( __DIR__, 3 ) . '/incl/addons/engine/api/class-rest-groups-controller.php';

	/**
	 * WP_Post subclass exposing post_type / post_title without triggering
	 * dynamic-property deprecations — the shared minimal stub only declares
	 * ID / post_name / post_content.
	 */
	#[\AllowDynamicProperties]
	// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
	class Lafka_Test_Group_Post extends WP_Post { // phpcs:ignore
		public string $post_type  = '';
		public string $post_title = '';
	}

	/**
	 * Minimal WP_REST_Request stand-in. ArrayAccess returns the MERGED params
	 * (client values overlaid on arg defaults, exactly as WP merges defaults
	 * before dispatch), while get_*_params() expose ONLY what the client
	 * actually sent — so param_was_sent() can distinguish a real value from a
	 * defaulted one.
	 */
	// phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
	class Lafka_Test_Rest_Request implements \ArrayAccess { // phpcs:ignore
		private array $merged;
		private array $sent;

		public function __construct( array $sent, array $defaults = array() ) {
			$this->sent   = $sent;
			$this->merged = array_merge( $defaults, $sent );
		}

		public function offsetExists( mixed $offset ): bool {
			return isset( $this->merged[ $offset ] );
		}

		public function offsetGet( mixed $offset ): mixed {
			return $this->merged[ $offset ] ?? null;
		}

		public function offsetSet( mixed $offset, mixed $value ): void {
			$this->merged[ $offset ] = $value;
		}

		public function offsetUnset( mixed $offset ): void {
			unset( $this->merged[ $offset ] );
		}

		public function get_json_params(): array {
			return $this->sent;
		}

		public function get_body_params(): array {
			return array();
		}

		public function get_query_params(): array {
			return array();
		}
	}
}

namespace LafkaPlugin\Tests\Unit\Addons {

	use Brain\Monkey;
	use Brain\Monkey\Functions;
	use Lafka_Addon_Schema;
	use Lafka_Addons_Engine;
	use Lafka_Addons_REST_Groups_Controller;
	use Lafka_Test_Group_Post;
	use Lafka_Test_Rest_Request;
	use PHPUnit\Framework\TestCase;
	use ReflectionClass;
	use ReflectionMethod;

	final class RestGroupsControllerProductPatchTest extends TestCase {

		/** @var array<int, array<string, mixed>> non-_product_addons post meta. */
		private array $post_meta = array();
		/** @var array<int, array> repository (_product_addons) store keyed by post id. */
		private array $addons_meta = array();
		/** @var array<int, array{terms: array, taxonomy: string, append: bool}> wp_set_post_terms calls. */
		private array $set_terms_calls = array();

		protected function setUp(): void {
			parent::setUp();
			Monkey\setUp();
			$this->post_meta       = array();
			$this->addons_meta     = array();
			$this->set_terms_calls = array();

			Functions\when( '__' )->returnArg( 1 );
			Functions\when( 'esc_html__' )->returnArg( 1 );
			Functions\when( 'apply_filters' )->returnArg( 2 );
			Functions\when( 'wp_unslash' )->returnArg( 1 );
			Functions\when( 'wp_generate_uuid4' )->justReturn( 'test-uuid-0000' );
			Functions\when( 'sanitize_text_field' )->returnArg( 1 );
			Functions\when( 'sanitize_title' )->alias( static fn( $v ) => strtolower( (string) $v ) );
			Functions\when( 'sanitize_key' )->alias( static fn( $v ) => preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) $v ) ) );
			Functions\when( 'wp_kses_post' )->returnArg( 1 );
			Functions\when( 'wc_format_decimal' )->alias( static fn( $v ) => (string) $v );
			Functions\when( 'wc_attribute_taxonomy_name_by_id' )->justReturn( '' );
			Functions\when( 'absint' )->alias( static fn( $v ) => abs( (int) $v ) );

			Functions\when( 'get_post_meta' )->alias(
				function ( $post_id, $key, $single ) {
					if ( '_product_addons' === $key ) {
						return $this->addons_meta[ $post_id ] ?? array();
					}
					return $this->post_meta[ $post_id ][ $key ] ?? '';
				}
			);
			Functions\when( 'update_post_meta' )->alias(
				function ( $post_id, $key, $value ) {
					if ( '_product_addons' === $key ) {
						$this->addons_meta[ $post_id ] = $value;
						return true;
					}
					$this->post_meta[ $post_id ][ $key ] = $value;
					return true;
				}
			);
			Functions\when( 'wp_set_post_terms' )->alias(
				function ( $post_id, $terms, $taxonomy, $append ) {
					$this->set_terms_calls[ $post_id ] = array(
						'terms'    => $terms,
						'taxonomy' => $taxonomy,
						'append'   => $append,
					);
					return array();
				}
			);
			Functions\when( 'wp_get_post_terms' )->justReturn( array() );

			$this->reset_engine_singleton();
		}

		protected function tearDown(): void {
			Monkey\tearDown();
			$this->reset_engine_singleton();
			parent::tearDown();
		}

		private function reset_engine_singleton(): void {
			$ref  = new ReflectionClass( Lafka_Addons_Engine::class );
			$prop = $ref->getProperty( 'instance' );
			$prop->setValue( null, null );
		}

		private function controller(): Lafka_Addons_REST_Groups_Controller {
			return new Lafka_Addons_REST_Groups_Controller();
		}

		private function persist( int $post_id, $request ) {
			$method = new ReflectionMethod( Lafka_Addons_REST_Groups_Controller::class, 'persist_via_engine' );
			return $method->invoke( $this->controller(), $post_id, $request );
		}

		private function make_post( int $id, string $type ): Lafka_Test_Group_Post {
			$post             = new Lafka_Test_Group_Post();
			$post->ID         = $id;
			$post->post_type  = $type;
			$post->post_title = 'Fixture';
			return $post;
		}

		/** PATCH arg defaults that WP merges into the request before dispatch. */
		private function defaults(): array {
			return array(
				'priority'     => 10,
				'all_products' => true,
				'category_ids' => array(),
				'groups'       => array(),
			);
		}

		private function sample_groups(): array {
			return array(
				array(
					'name'           => 'Toppings',
					'type'           => 'checkbox',
					'pricing_mode'   => Lafka_Addon_Schema::PRICING_FLAT_PER_OPTION,
					'options_source' => Lafka_Addon_Schema::SOURCE_MANUAL,
					'options'        => array(
						array( 'id' => 'opt-1', 'label' => 'Cheese', 'price' => '1.00', 'included' => '1' ),
					),
				),
			);
		}

		public function test_product_patch_never_writes_terms_or_assignment_meta(): void {
			Functions\when( 'get_post' )->justReturn( $this->make_post( 42, 'product' ) );

			// Realistic minimal PATCH: only groups sent; everything else defaulted.
			$request = new Lafka_Test_Rest_Request(
				array( 'groups' => $this->sample_groups() ),
				$this->defaults()
			);

			$this->persist( 42, $request );

			self::assertArrayNotHasKey( 42, $this->set_terms_calls, 'product_cat terms must never be touched on a product post.' );
			self::assertArrayNotHasKey( '_priority', $this->post_meta[42] ?? array(), '_priority is meaningless on a product.' );
			self::assertArrayNotHasKey( '_all_products', $this->post_meta[42] ?? array(), '_all_products is meaningless on a product.' );

			$groups = Lafka_Addons_Engine::instance()->repository()->get_groups( 42 );
			self::assertCount( 1, $groups );
			self::assertSame( 'Toppings', $groups[0]->name );
		}

		public function test_product_patch_persists_explicit_exclude_global_flag(): void {
			Functions\when( 'get_post' )->justReturn( $this->make_post( 42, 'product' ) );

			$request = new Lafka_Test_Rest_Request(
				array(
					'groups'         => array(),
					'exclude_global' => true,
				),
				$this->defaults()
			);

			$this->persist( 42, $request );

			self::assertSame( 1, $this->post_meta[42]['_product_addons_exclude_global'] ?? null );
			self::assertArrayNotHasKey( 42, $this->set_terms_calls );
		}

		public function test_product_patch_ignores_exclude_global_when_not_sent(): void {
			Functions\when( 'get_post' )->justReturn( $this->make_post( 42, 'product' ) );

			$request = new Lafka_Test_Rest_Request(
				array( 'groups' => array() ),
				$this->defaults()
			);

			$this->persist( 42, $request );

			// Absent flag must not be written (no clobbering an existing opt-out).
			self::assertArrayNotHasKey( '_product_addons_exclude_global', $this->post_meta[42] ?? array() );
		}

		public function test_global_addon_patch_writes_meta_and_terms_when_categories_sent(): void {
			Functions\when( 'get_post' )->justReturn( $this->make_post( 7, 'lafka_glb_addon' ) );

			$request = new Lafka_Test_Rest_Request(
				array(
					'priority'     => 5,
					'all_products' => false,
					'category_ids' => array( 11, 22 ),
					'groups'       => array(),
				),
				$this->defaults()
			);

			$this->persist( 7, $request );

			self::assertSame( 5, $this->post_meta[7]['_priority'] );
			self::assertSame( 0, $this->post_meta[7]['_all_products'] );
			self::assertArrayHasKey( 7, $this->set_terms_calls );
			self::assertSame( array( 11, 22 ), $this->set_terms_calls[7]['terms'] );
			self::assertSame( 'product_cat', $this->set_terms_calls[7]['taxonomy'] );
			self::assertFalse( $this->set_terms_calls[7]['append'] );
		}

		public function test_global_addon_partial_patch_does_not_wipe_terms_when_categories_absent(): void {
			Functions\when( 'get_post' )->justReturn( $this->make_post( 7, 'lafka_glb_addon' ) );

			// Client sends only groups; category_ids is purely a default.
			$request = new Lafka_Test_Rest_Request(
				array( 'groups' => array() ),
				$this->defaults()
			);

			$this->persist( 7, $request );

			self::assertArrayNotHasKey( 7, $this->set_terms_calls, 'A defaulted category_ids must not trigger a destructive product_cat replace.' );
			// Assignment meta is still written for the global addon CPT.
			self::assertArrayHasKey( '_priority', $this->post_meta[7] );
			self::assertArrayHasKey( '_all_products', $this->post_meta[7] );
		}

		public function test_global_addon_all_products_clears_categories_when_sent(): void {
			Functions\when( 'get_post' )->justReturn( $this->make_post( 7, 'lafka_glb_addon' ) );

			// all_products true + categories explicitly sent → categories dropped.
			$request = new Lafka_Test_Rest_Request(
				array(
					'all_products' => true,
					'category_ids' => array( 11 ),
					'groups'       => array(),
				),
				$this->defaults()
			);

			$this->persist( 7, $request );

			self::assertArrayHasKey( 7, $this->set_terms_calls );
			self::assertSame( array(), $this->set_terms_calls[7]['terms'] );
		}
	}
}
