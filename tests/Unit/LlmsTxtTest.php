<?php
/**
 * GX3 machine-readable documents (incl/seo/lafka-llms-txt.php):
 *
 *   - /llms.txt: name, summary (operator description, else a factual
 *     sentence), NAP, hours, service areas, how to order, categories with
 *     item counts + price ranges, links;
 *   - /llms-full.txt: every item with price, diet labels, availability,
 *     description, url, plus category FAQs;
 *   - /menu.md: the menu alone; /menu.json: the same Restaurant + Menu
 *     nodes as the on-page JSON-LD;
 *   - cached, and the cache drops on menu / business-option changes;
 *   - served only when enabled (default ON), 404 otherwise.
 *
 * @package Lafka\Plugin\Tests\Unit
 */

declare(strict_types=1);

namespace {
	require_once __DIR__ . '/Stubs/wp-term-stub.php';
	if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
		define( 'HOUR_IN_SECONDS', 3600 );
	}
}

namespace LafkaPlugin\Tests\Unit {

	use Brain\Monkey;
	use Brain\Monkey\Functions;
	use PHPUnit\Framework\TestCase;

	final class LlmsTxtTest extends TestCase {

		/** @var array<string, mixed> */
		private array $options = array();

		/** @var array<string, mixed> */
		private array $info = array();

		/** @var array<string, mixed> */
		private array $transients = array();

		/** @var array<string, mixed> */
		private array $filters = array();

		protected function setUp(): void {
			parent::setUp();
			Monkey\setUp();
			$this->options    = array();
			$this->transients = array();
			$this->filters    = array();
			$this->info       = array(
				'name'            => 'Acme Kitchen',
				'street'          => '1 Test Road',
				'city'            => 'Springfield',
				'region'          => 'IL',
				'postal'          => '62704',
				'address_display' => "1 Test Road\nSpringfield, IL 62704\nUS",
				'phone_e164'      => '+15551234567',
				'phone_display'   => '(555) 123-4567',
				'email'           => 'hello@example.test',
				'cuisines'        => array( 'Pizza', 'Salads' ),
				'hours'           => array(
					'Monday'  => '11:00-23:00',
					'Tuesday' => 'Closed',
				),
				'service_areas'   => array( 'Northside', 'Riverside' ),
				'map_url'         => 'https://maps.example.test/?cid=1',
				'menu_url'        => 'https://example.test/menu/',
				'same_as'         => array( 'https://social.example.test/acme' ),
				'description'     => '',
				'price_range'     => '$$',
			);

			Functions\when( 'get_option' )->alias( fn( $key, $default = false ) => $this->options[ $key ] ?? $default );
			Functions\when( 'get_theme_mod' )->alias( static fn( $key, $default = false ) => $default );
			Functions\when( 'get_bloginfo' )->justReturn( '' );
			Functions\when( 'get_site_icon_url' )->justReturn( '' );
			Functions\when( 'home_url' )->alias( static fn( $path = '' ) => 'https://example.test' . $path );
			Functions\when( 'trailingslashit' )->alias( static fn( $url ) => rtrim( (string) $url, '/' ) . '/' );
			Functions\when( 'apply_filters' )->alias(
				function ( $hook, $value ) {
					if ( 'lafka_restaurant_info' === $hook ) {
						return array_merge( (array) $value, $this->info );
					}
					return array_key_exists( $hook, $this->filters ) ? $this->filters[ $hook ] : $value;
				}
			);
			Functions\when( '__' )->returnArg();
			Functions\when( '_n' )->alias( static fn( $one, $many, $n ) => 1 === (int) $n ? $one : $many );
			Functions\when( 'wp_strip_all_tags' )->alias( static fn( $s ) => trim( strip_tags( (string) $s ) ) );
			Functions\when( 'wp_timezone_string' )->justReturn( 'America/Chicago' );
			Functions\when( 'wc_price' )->alias( static fn( $p ) => '&#36;' . number_format( (float) $p, 2 ) );
			Functions\when( 'get_woocommerce_currency' )->justReturn( 'USD' );
			Functions\when( 'wp_json_encode' )->alias( static fn( $d, $f = 0 ) => json_encode( $d, $f ) );
			Functions\when( 'get_term_meta' )->justReturn( '' );
			Functions\when( 'get_transient' )->alias( fn( $k ) => $this->transients[ $k ] ?? false );
			Functions\when( 'set_transient' )->alias(
				function ( $k, $v ) {
					$this->transients[ $k ] = $v;
					return true;
				}
			);
			Functions\when( 'delete_transient' )->alias(
				function ( $k ) {
					unset( $this->transients[ $k ] );
					return true;
				}
			);

			// Include-time calls of the modules below.
			Functions\when( 'is_admin' )->justReturn( false );
			Functions\when( 'register_activation_hook' )->justReturn( null );

			require_once dirname( __DIR__, 2 ) . '/incl/schema/lafka-schema-helpers.php';
			require_once dirname( __DIR__, 2 ) . '/incl/schema/lafka-schema-website.php';
			require_once dirname( __DIR__, 2 ) . '/incl/schema/lafka-schema-restaurant.php';
			require_once dirname( __DIR__, 2 ) . '/incl/schema/lafka-schema-menu.php';
			require_once dirname( __DIR__, 2 ) . '/incl/seo/lafka-seo-settings.php';
			require_once dirname( __DIR__, 2 ) . '/incl/seo/lafka-term-faq.php';
			require_once dirname( __DIR__, 2 ) . '/incl/seo/lafka-llms-txt.php';

			Functions\when( 'lafka_schema_menu_data' )->justReturn( self::menu_data() );
		}

		protected function tearDown(): void {
			Monkey\tearDown();
			parent::tearDown();
		}

		/** @return array<string, mixed> */
		private static function menu_data(): array {
			return array(
				'sections' => array(
					3 => array(
						'term_id'     => 3,
						'name'        => 'Pizza',
						'slug'        => 'pizza',
						'parent'      => 0,
						'url'         => 'https://example.test/menu/pizza/',
						'description' => '<p>Stone-baked.</p>',
						'leaf'        => true,
						'items'       => array(
							array(
								'@type'           => 'MenuItem',
								'name'            => 'Margherita',
								'url'             => 'https://example.test/product/margherita/',
								'description'     => 'Tomato, basil.',
								'suitableForDiet' => 'https://schema.org/VegetarianDiet',
								'offers'          => array( '@type' => 'Offer', 'price' => '12.00', 'availability' => 'https://schema.org/InStock' ),
							),
							array(
								'@type'  => 'MenuItem',
								'name'   => 'Build Your Own',
								'offers' => array( '@type' => 'AggregateOffer', 'lowPrice' => '9.00', 'highPrice' => '18.50', 'availability' => 'https://schema.org/OutOfStock' ),
							),
						),
						'all_count'   => 2,
						'price_min'   => '9.00',
						'price_max'   => '18.50',
					),
				),
				'order'    => array( 3 ),
			);
		}

		public function test_llms_txt_summarises_the_restaurant(): void {
			$doc = lafka_llms_render( 'llms' );

			self::assertStringStartsWith( "# Acme Kitchen\n\n> Acme Kitchen serves Pizza, Salads in Springfield, IL", $doc );
			self::assertStringContainsString( '- Address: 1 Test Road, Springfield, IL 62704, US', $doc );
			self::assertStringContainsString( '- Phone: (555) 123-4567 (+15551234567)', $doc );
			self::assertStringContainsString( '- Monday: 11:00–23:00', $doc );
			self::assertStringContainsString( '- Tuesday: Closed', $doc );
			self::assertStringContainsString( "## Service areas\n\n- Northside\n- Riverside", $doc );
			self::assertStringContainsString( '- Online: https://example.test/menu/', $doc );
			self::assertStringContainsString( '- [Pizza](https://example.test/menu/pizza/): 2 items, $9.00–$18.50', $doc );
			self::assertStringContainsString( '(https://example.test/llms-full.txt)', $doc );
			self::assertStringContainsString( '- https://social.example.test/acme', $doc );
			self::assertStringNotContainsString( 'Margherita', $doc, 'items belong in llms-full.txt' );
		}

		public function test_operator_description_is_the_summary(): void {
			$this->info['description'] = '<p>Family-run since 1999.</p>';
			self::assertStringContainsString( "\n> Family-run since 1999.\n", lafka_llms_render( 'llms' ) );
		}

		public function test_llms_full_lists_every_item_with_facts_and_faqs(): void {
			Functions\when( 'get_term_meta' )->justReturn( array( array( 'q' => 'Gluten-free crust?', 'a' => 'Yes, 10-inch.' ) ) );
			$doc = lafka_llms_render( 'llms-full' );

			self::assertStringContainsString( "## Pizza\n\nStone-baked.", $doc );
			self::assertStringContainsString( '- **Margherita** — $12.00 — (vegetarian): Tomato, basil. <https://example.test/product/margherita/>', $doc );
			self::assertStringContainsString( '- **Build Your Own** — $9.00–$18.50 — [currently unavailable]', $doc );
			self::assertStringContainsString( '- **Gluten-free crust?** Yes, 10-inch.', $doc );
		}

		public function test_menu_md_is_the_menu_alone(): void {
			$doc = lafka_llms_render( 'menu-md' );
			self::assertStringStartsWith( '# Acme Kitchen — Menu', $doc );
			self::assertStringContainsString( 'Prices in USD.', $doc );
			self::assertStringContainsString( '**Margherita**', $doc );
			self::assertStringNotContainsString( '## Opening hours', $doc );
		}

		public function test_menu_json_matches_the_structured_data(): void {
			$json = json_decode( lafka_llms_render( 'menu-json' ), true );

			self::assertSame( 'https://schema.org', $json['@context'] );
			self::assertSame( 'https://example.test/#restaurant', $json['@graph'][0]['@id'] );
			self::assertSame( 'https://example.test/menu/#menu', $json['@graph'][0]['hasMenu']['@id'] );
			self::assertSame( 'Menu', $json['@graph'][1]['@type'] );
			self::assertSame( 'https://example.test/menu/#menu', $json['@graph'][1]['@id'] );
			self::assertSame( 'Margherita', $json['@graph'][1]['hasMenuSection'][0]['hasMenuItem'][0]['name'] );
		}

		public function test_documents_are_cached_and_busted_on_change(): void {
			$first = lafka_llms_document( 'llms' );
			self::assertSame( $first, $this->transients['lafka_llms_llms'] );

			$this->transients['lafka_llms_llms'] = 'stale';
			self::assertSame( 'stale', lafka_llms_document( 'llms' ) );

			lafka_llms_maybe_flush_on_option( 'unrelated_option' );
			self::assertArrayHasKey( 'lafka_llms_llms', $this->transients );

			lafka_llms_maybe_flush_on_option( 'lafka_business_phone_e164' );
			self::assertArrayNotHasKey( 'lafka_llms_llms', $this->transients );

			lafka_llms_document( 'menu-md' );
			lafka_llms_flush_cache();
			self::assertSame( array(), $this->transients );
		}

		public function test_enabled_by_default_and_filterable(): void {
			self::assertTrue( lafka_llms_enabled() );
			$this->options['lafka_seo_llms_enabled'] = 'no';
			self::assertFalse( lafka_llms_enabled() );
			$this->filters['lafka_llms_enabled'] = true;
			self::assertTrue( lafka_llms_enabled() );
		}

		public function test_disabled_documents_404(): void {
			$this->options['lafka_seo_llms_enabled'] = 'no';
			Functions\when( 'get_query_var' )->justReturn( 'llms' );
			$status = null;
			Functions\when( 'status_header' )->alias(
				function ( $code ) use ( &$status ) {
					$status = $code;
				}
			);
			lafka_llms_serve();
			self::assertSame( 404, $status );
		}

		public function test_routes_and_diet_labels(): void {
			Functions\when( 'add_rewrite_rule' )->alias(
				static function ( $regex, $query ) use ( &$rules ) {
					$rules[ $regex ] = $query;
				}
			);
			$rules = array();
			lafka_llms_register_rewrites();
			self::assertSame( 'index.php?lafka_machine=llms', $rules['^llms\.txt$'] );
			self::assertSame( 'index.php?lafka_machine=menu-json', $rules['^menu\.json$'] );
			self::assertContains( 'lafka_machine', lafka_llms_query_vars( array() ) );
			self::assertSame( 'gluten-free', lafka_llms_diet_label( 'https://schema.org/GlutenFreeDiet' ) );
		}

		public function test_rewrites_flush_once_per_version(): void {
			$flushes = 0;
			Functions\when( 'flush_rewrite_rules' )->alias(
				static function () use ( &$flushes ) {
					++$flushes;
				}
			);
			Functions\when( 'update_option' )->alias(
				function ( $k, $v ) {
					$this->options[ $k ] = $v;
					return true;
				}
			);
			lafka_seo_maybe_flush_rewrites();
			lafka_seo_maybe_flush_rewrites();
			self::assertSame( 1, $flushes );
		}

		public function test_listed_on_the_modules_page_default_on(): void {
			require_once dirname( __DIR__, 2 ) . '/incl/class-lafka-options.php';
			require_once dirname( __DIR__, 2 ) . '/incl/class-lafka-module-registry.php';
			Functions\when( 'esc_html__' )->returnArg();
			Functions\when( 'do_action' )->justReturn( null );
			Functions\when( 'update_option' )->alias(
				function ( $k, $v ) {
					$this->options[ $k ] = $v;
					return true;
				}
			);
			\Lafka_Module_Registry::reset();

			lafka_llms_register_module();
			$module = \Lafka_Module_Registry::get( 'machine_readable_menu' );

			self::assertTrue( $module->default_enabled() );
			self::assertTrue( $module->is_enabled() );
			$module->set_enabled( false );
			self::assertFalse( lafka_llms_enabled() );
			\Lafka_Module_Registry::reset();
		}
	}
}
