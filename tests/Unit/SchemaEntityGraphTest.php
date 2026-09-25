<?php
/**
 * GX3 linked entity graph: Restaurant ↔ Menu ↔ MenuSection ↔ MenuItem.
 *
 *   - Restaurant: hasMenu is a Menu reference sharing the Menu node's @id;
 *     logo, image, hasMap, description and an areaServed list from the
 *     operator's service areas (own city only as the fallback);
 *   - Menu: one MenuSection per LEAF category, a parent keeping only its
 *     direct items; provider → #restaurant; MenuItems carry url/@id and
 *     suitableForDiet from the operator's tags;
 *   - a category archive emits only its own subtree, the menu page the lot;
 *   - Product offers name the Restaurant as seller.
 *
 * @package Lafka\Plugin\Tests\Unit
 */

declare(strict_types=1);

namespace {
	require_once __DIR__ . '/Stubs/wp-term-stub.php';
	require_once __DIR__ . '/Stubs/wp-post-stub.php';
	if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
		define( 'HOUR_IN_SECONDS', 3600 );
	}
	if ( ! class_exists( 'WC_Product' ) ) {
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

	final class SchemaEntityGraphTest extends TestCase {

		private const NAP = array(
			'lafka_business_name'       => 'Acme Test Cafe',
			'lafka_business_street'     => '123 Test Street',
			'lafka_business_city'       => 'Testville',
			'lafka_business_postal'     => 'T1S 1S1',
			'lafka_business_phone_e164' => '+15551234567',
		);

		/** @var array<string, mixed> */
		private array $options = array();

		/** @var array<string, mixed> */
		private array $theme_mods = array();

		/** @var array<int, list<string>> product id => tag/cat slugs */
		private array $slugs = array();

		/** @var array<string, bool> */
		private array $is = array();

		private $queried = null;

		protected function setUp(): void {
			parent::setUp();
			Monkey\setUp();
			$this->options    = self::NAP;
			$this->theme_mods = array();
			$this->slugs      = array();
			$this->is         = array();
			$this->queried    = null;

			Functions\when( 'get_option' )->alias( fn( $key, $default = false ) => $this->options[ $key ] ?? $default );
			Functions\when( 'get_theme_mod' )->alias( fn( $key, $default = false ) => $this->theme_mods[ $key ] ?? $default );
			Functions\when( 'get_bloginfo' )->justReturn( '' );
			Functions\when( 'get_site_icon_url' )->justReturn( 'https://example.test/icon.png' );
			Functions\when( 'home_url' )->alias( static fn( $path = '' ) => 'https://example.test' . $path );
			Functions\when( 'trailingslashit' )->alias( static fn( $url ) => rtrim( (string) $url, '/' ) . '/' );
			Functions\when( 'apply_filters' )->returnArg( 2 );
			Functions\when( 'do_action' )->justReturn( null );
			Functions\when( '__' )->returnArg();
			Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );
			Functions\when( 'wp_strip_all_tags' )->alias( static fn( $v ) => trim( strip_tags( (string) $v ) ) );
			Functions\when( 'wp_trim_words' )->returnArg();
			Functions\when( 'wp_get_attachment_image_url' )->alias( static fn( $id ) => 'https://example.test/img-' . $id . '.jpg' );
			Functions\when( 'get_permalink' )->alias( static fn( $id ) => 'https://example.test/product/p' . $id . '/' );
			Functions\when( 'wp_get_post_terms' )->alias( fn( $id ) => $this->slugs[ $id ] ?? array() );
			Functions\when( 'get_transient' )->justReturn( false );
			Functions\when( 'set_transient' )->justReturn( true );
			Functions\when( 'is_wp_error' )->justReturn( false );
			Functions\when( 'get_term_link' )->alias( static fn( $term ) => 'https://example.test/menu/' . $term->slug . '/' );
			foreach ( array( 'is_shop', 'is_product_category', 'is_front_page' ) as $tag ) {
				Functions\when( $tag )->alias( fn() => $this->is[ $tag ] ?? false );
			}
			Functions\when( 'get_queried_object' )->alias( fn() => $this->queried );

			require_once dirname( __DIR__, 2 ) . '/incl/seo/lafka-seo-settings.php';
			require_once dirname( __DIR__, 2 ) . '/incl/schema/lafka-schema-helpers.php';
			require_once dirname( __DIR__, 2 ) . '/incl/schema/lafka-schema-website.php';
			require_once dirname( __DIR__, 2 ) . '/incl/schema/lafka-schema-restaurant.php';
			require_once dirname( __DIR__, 2 ) . '/incl/schema/lafka-schema-menu.php';
			require_once dirname( __DIR__, 2 ) . '/incl/schema/lafka-schema-product.php';
		}

		protected function tearDown(): void {
			Monkey\tearDown();
			parent::tearDown();
		}

		private function product( int $id, string $name, string $price ): \WC_Product {
			return new class( $id, $name, $price ) extends \WC_Product {
				public function __construct( private int $id, private string $n, private string $p ) {
				}
				public function get_id() {
					return $this->id;
				}
				public function get_name( $context = 'view' ) {
					return $this->n;
				}
				public function get_short_description() {
					return '';
				}
				public function get_description() {
					return '';
				}
				public function get_image_id() {
					return 0;
				}
				public function get_sku() {
					return '';
				}
				public function is_in_stock() {
					return true;
				}
				public function is_type( $type ) {
					return false;
				}
				public function get_price() {
					return $this->p;
				}
				public function get_review_count() {
					return 0;
				}
				public function get_average_rating() {
					return 0;
				}
			};
		}

		/**
		 * Pizza (1) > Classic (2), Specialty (3); Sides (4). Product 12 sits
		 * directly in Pizza; WC's category query includes descendants.
		 */
		private function stub_catalog(): void {
			$terms = array(
				new \WP_Term( array( 'term_id' => 1, 'name' => 'Pizza', 'slug' => 'pizza', 'parent' => 0, 'count' => 3 ) ),
				new \WP_Term( array( 'term_id' => 2, 'name' => 'Classic', 'slug' => 'classic', 'parent' => 1, 'count' => 1 ) ),
				new \WP_Term( array( 'term_id' => 3, 'name' => 'Specialty', 'slug' => 'specialty', 'parent' => 1, 'count' => 1 ) ),
				new \WP_Term( array( 'term_id' => 4, 'name' => 'Sides', 'slug' => 'sides', 'parent' => 0, 'count' => 1 ) ),
			);
			Functions\when( 'get_terms' )->justReturn( $terms );

			$p10 = $this->product( 10, 'Margherita', '12' );
			$p11 = $this->product( 11, 'Truffle', '18.5' );
			$p12 = $this->product( 12, 'Build Your Own', '9' );
			$p20 = $this->product( 20, 'Garden Salad', '7' );
			$by  = array(
				'pizza'     => array( $p10, $p11, $p12 ),
				'classic'   => array( $p10 ),
				'specialty' => array( $p11 ),
				'sides'     => array( $p20 ),
			);
			Functions\when( 'wc_get_products' )->alias( static fn( $args ) => $by[ $args['category'][0] ] ?? array() );
			$this->slugs = array(
				20 => array( 'vegan', 'sides' ),
				10 => array( 'vegetarian', 'gluten-free', 'classic', 'pizza' ),
			);
		}

		/** @return array<string, list<string>> section name => item names */
		private static function section_items( array $menu ): array {
			$out = array();
			foreach ( $menu['hasMenuSection'] as $section ) {
				$out[ $section['name'] ] = array_column( $section['hasMenuItem'], 'name' );
			}
			return $out;
		}

		// ── Restaurant ────────────────────────────────────────────────────

		public function test_restaurant_links_to_the_menu_node_by_id(): void {
			$schema = lafka_schema_restaurant();
			self::assertSame(
				array(
					'@type' => 'Menu',
					'@id'   => 'https://example.test/menu/#menu',
					'url'   => 'https://example.test/menu/',
				),
				$schema['hasMenu']
			);
		}

		public function test_restaurant_carries_logo_images_map_and_description(): void {
			$this->theme_mods = array(
				'custom_logo'            => 5,
				'lafka_og_image_default' => '8',
			);
			$this->options += array(
				'lafka_business_map_url'     => 'https://maps.example.test/?cid=1',
				'lafka_business_description' => '<p>Wood-fired pizza.</p>',
			);

			$schema = lafka_schema_restaurant();

			self::assertSame( 'https://example.test/img-5.jpg', $schema['logo'] );
			self::assertSame( 'https://example.test/img-8.jpg', $schema['image'] );
			self::assertSame( 'https://maps.example.test/?cid=1', $schema['hasMap'] );
			self::assertSame( 'Wood-fired pizza.', $schema['description'] );
		}

		public function test_logo_and_image_fall_back_to_the_site_icon(): void {
			$schema = lafka_schema_restaurant();
			self::assertSame( 'https://example.test/icon.png', $schema['logo'] );
			self::assertSame( 'https://example.test/icon.png', $schema['image'] );
			self::assertArrayNotHasKey( 'hasMap', $schema );
		}

		public function test_area_served_is_the_operator_list_else_the_own_city(): void {
			self::assertSame( array( array( '@type' => 'City', 'name' => 'Testville' ) ), lafka_schema_restaurant()['areaServed'] );

			$this->options['lafka_business_service_areas'] = "Northside\nRiverside";
			self::assertSame(
				array(
					array( '@type' => 'Place', 'name' => 'Northside' ),
					array( '@type' => 'Place', 'name' => 'Riverside' ),
				),
				lafka_schema_restaurant()['areaServed']
			);
		}

		public function test_restaurant_never_self_rates(): void {
			$this->theme_mods = array( 'lafka_social_proof_rating' => '4.9', 'lafka_social_proof_count' => '999' );
			self::assertArrayNotHasKey( 'aggregateRating', lafka_schema_restaurant() );
		}

		// ── Menu ──────────────────────────────────────────────────────────

		public function test_full_menu_has_one_section_per_leaf_and_no_duplicated_items(): void {
			$this->stub_catalog();
			$this->is['is_shop'] = true;

			$menu = lafka_schema_menu();

			self::assertSame( 'https://example.test/menu/#menu', $menu['@id'] );
			self::assertSame(
				array(
					'Pizza'     => array( 'Build Your Own' ),
					'Classic'   => array( 'Margherita' ),
					'Specialty' => array( 'Truffle' ),
					'Sides'     => array( 'Garden Salad' ),
				),
				self::section_items( $menu )
			);
			self::assertSame( array( '@id' => 'https://example.test/#restaurant' ), $menu['provider'] );
		}

		public function test_menu_items_link_to_their_product_and_declare_diets(): void {
			$this->stub_catalog();
			$items = array();
			foreach ( lafka_schema_menu()['hasMenuSection'] as $section ) {
				foreach ( $section['hasMenuItem'] as $item ) {
					$items[ $item['name'] ] = $item;
				}
			}

			self::assertSame( 'https://example.test/product/p10/', $items['Margherita']['url'] );
			self::assertSame( 'https://example.test/product/p10/#menuitem', $items['Margherita']['@id'] );
			self::assertSame( array( 'https://schema.org/VegetarianDiet', 'https://schema.org/GlutenFreeDiet' ), $items['Margherita']['suitableForDiet'] );
			self::assertSame( 'https://schema.org/VeganDiet', $items['Garden Salad']['suitableForDiet'] );
			self::assertArrayNotHasKey( 'suitableForDiet', $items['Truffle'] );
		}

		public function test_operator_diet_map_lines_extend_the_defaults(): void {
			$this->options['lafka_seo_diet_map'] = "plant-based = VeganDiet\nnot a rule\nlocal: Halal";
			$map                                 = lafka_schema_diet_map();
			self::assertSame( 'https://schema.org/VeganDiet', $map['plant-based'] );
			self::assertSame( 'https://schema.org/HalalDiet', $map['local'] );
			self::assertSame( 'https://schema.org/VeganDiet', $map['vegan'] );
		}

		public function test_a_category_page_emits_only_its_own_subtree(): void {
			$this->stub_catalog();
			$this->is['is_product_category'] = true;

			$this->queried = new \WP_Term( array( 'term_id' => 1, 'slug' => 'pizza' ) );
			self::assertSame(
				array( 'Pizza', 'Classic', 'Specialty' ),
				array_keys( self::section_items( lafka_schema_menu() ) )
			);

			$this->queried = new \WP_Term( array( 'term_id' => 4, 'slug' => 'sides' ) );
			$menu          = lafka_schema_menu();
			self::assertSame( array( 'Sides' ), array_keys( self::section_items( $menu ) ) );
			self::assertSame( 'https://example.test/menu/sides/#menusection', $menu['hasMenuSection'][0]['@id'] );
			// T-18: the category slice is its own Menu node, never a second
			// (partial) definition of the full menu's /menu/#menu.
			self::assertSame( 'https://example.test/menu/sides/#menu', $menu['@id'] );
			self::assertSame( 'https://example.test/menu/sides/', $menu['url'] );

			$this->is      = array();
			$this->queried = null;
			self::assertSame( 'https://example.test/menu/#menu', lafka_schema_menu()['@id'], 'The full menu keeps the canonical @id.' );
		}

		public function test_section_price_range_is_recorded_for_the_price_from_token(): void {
			$this->stub_catalog();
			$data = lafka_schema_menu_data();
			self::assertSame( '9.00', $data['sections'][1]['price_min'] );
			self::assertSame( '18.50', $data['sections'][1]['price_max'] );
			self::assertSame( 3, $data['sections'][1]['all_count'] );
			self::assertSame( array( 1, 2, 3, 4 ), $data['order'] );
		}

		public function test_menu_has_no_provider_when_the_restaurant_node_is_absent(): void {
			$this->stub_catalog();
			$this->options = array();
			self::assertArrayNotHasKey( 'provider', lafka_schema_menu() );
		}

		public function test_home_gets_the_menu_only_when_opted_in(): void {
			$this->is['is_front_page'] = true;
			self::assertFalse( lafka_schema_is_menu_context() );

			$this->options['lafka_seo_menu_schema_on_home'] = 'yes';
			self::assertTrue( lafka_schema_is_menu_context() );
		}

		/**
		 * WC 11.0: on the Shop archive get_queried_object() is the Shop PAGE.
		 * With that page slugged `menu`/`order` the archive must not count as
		 * "the menu page" (the shop is its own menu context).
		 */
		public function test_a_product_archive_is_never_the_menu_page(): void {
			Functions\when( 'is_post_type_archive' )->alias( fn( $type = '' ) => $this->is['is_post_type_archive'] ?? false );
			$this->queried = new \WP_Post( (object) array( 'ID' => 5, 'post_name' => 'menu' ) );

			self::assertTrue( lafka_schema_is_menu_page(), 'the real /menu/ page' );

			$this->is['is_shop'] = true;
			self::assertFalse( lafka_schema_is_menu_page(), 'shop archive whose queried object is the Shop page' );
			self::assertTrue( lafka_schema_is_menu_context(), 'the shop is still a menu context in its own right' );

			$this->is = array( 'is_post_type_archive' => true );
			self::assertFalse( lafka_schema_is_menu_page(), 'product post-type archive' );
		}

		// ── Product ───────────────────────────────────────────────────────

		public function test_product_offer_names_the_restaurant_as_seller(): void {
			$product = $this->product( 10, 'Margherita', '12' );
			Functions\when( 'get_queried_object_id' )->justReturn( 10 );
			Functions\when( 'wc_get_product' )->justReturn( $product );

			self::assertSame( array( '@id' => 'https://example.test/#restaurant' ), lafka_schema_product()['offers']['seller'] );

			$this->options = array();
			self::assertArrayNotHasKey( 'seller', lafka_schema_product()['offers'] );
		}
	}
}
