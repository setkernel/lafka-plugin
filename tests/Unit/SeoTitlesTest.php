<?php
/**
 * GX3 title & description templates (incl/seo/lafka-seo-settings.php +
 * incl/seo/lafka-seo-titles.php + the head-meta description resolver):
 *
 *   - {token} replacement, [optional] segments dropped when a token is
 *     empty, no dangling separators;
 *   - per page type: home / menu / category / product / page templates, a
 *     per-post override wins, pagination suffix, output escaped;
 *   - silent when an SEO plugin owns the head (unless forced) or disabled;
 *   - unique fallback descriptions: product short description → product
 *     template; term description (excerpted) → category template.
 *
 * @package Lafka\Plugin\Tests\Unit
 */

declare(strict_types=1);

namespace {
	require_once __DIR__ . '/Stubs/wp-term-stub.php';
	require_once __DIR__ . '/Stubs/wp-post-stub.php';
}

namespace LafkaPlugin\Tests\Unit {

	use Brain\Monkey;
	use Brain\Monkey\Functions;
	use PHPUnit\Framework\TestCase;

	final class SeoTitlesTest extends TestCase {

		/** @var array<string, mixed> */
		private array $options = array();

		/** @var array<string, bool> */
		private array $is = array();

		/** @var array<string, mixed> */
		private array $meta = array();

		/** @var array<string, mixed> */
		private array $filters = array();

		/** @var array<string, mixed> Merged into lafka_get_restaurant_info(). */
		private array $info = array();

		private $queried = null;

		private int $paged = 0;

		private bool $seo_plugin = false;

		protected function setUp(): void {
			parent::setUp();
			Monkey\setUp();
			$this->options    = array();
			$this->is         = array();
			$this->meta       = array();
			$this->filters    = array();
			$this->queried    = null;
			$this->paged      = 0;
			$this->seo_plugin = false;
			$this->info       = array(
				'name'          => 'Acme Kitchen',
				'city'          => 'Springfield',
				'region'        => 'IL',
				'cuisines'      => array( 'Pizza', 'Poutine', 'Wings' ),
				'phone_display' => '(555) 123-4567',
			);

			// The real resolver runs; the business record is injected through its
			// own `lafka_restaurant_info` filter (plugin functions loaded by other
			// tests cannot be re-stubbed).
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
			Functions\when( 'esc_html' )->alias( static fn( $s ) => htmlspecialchars( (string) $s, ENT_QUOTES ) );
			Functions\when( 'wp_strip_all_tags' )->alias( static fn( $s ) => trim( strip_tags( (string) $s ) ) );
			Functions\when( 'number_format_i18n' )->alias( static fn( $n ) => (string) $n );
			Functions\when( 'wc_price' )->alias( static fn( $p ) => '<span class="amount"><bdi><span>&#36;</span>' . number_format( (float) $p, 2 ) . '</bdi></span>' );
			Functions\when( 'get_query_var' )->alias( fn( $var ) => 'paged' === $var ? $this->paged : 0 );
			Functions\when( 'get_queried_object' )->alias( fn() => $this->queried );
			Functions\when( 'get_post_meta' )->alias( fn( $id, $key ) => $this->meta[ $key ] ?? '' );
			Functions\when( 'lafka_seo_plugin_active' )->alias( fn() => $this->seo_plugin );
			Functions\when( 'lafka_schema_menu_data' )->justReturn(
				array(
					'sections' => array(
						5 => array( 'price_min' => '8.50' ),
					),
					'order'    => array( 5 ),
				)
			);
			foreach ( array( 'is_admin', 'is_feed', 'is_404', 'is_search', 'is_front_page', 'is_singular', 'is_product', 'is_shop', 'is_product_category', 'is_product_tag', 'is_tax', 'is_category', 'is_tag', 'is_home', 'lafka_schema_is_menu_page' ) as $tag ) {
				Functions\when( $tag )->alias( fn() => $this->is[ $tag ] ?? false );
			}

			require_once dirname( __DIR__, 2 ) . '/incl/schema/lafka-schema-helpers.php';
			require_once dirname( __DIR__, 2 ) . '/incl/seo/lafka-seo-settings.php';
			require_once dirname( __DIR__, 2 ) . '/incl/seo/lafka-seo-titles.php';
		}

		protected function tearDown(): void {
			Monkey\tearDown();
			parent::tearDown();
		}

		private function product( string $name, string $price ): object {
			return new class( $name, $price ) {
				public function __construct( private string $n, private string $p ) {
				}
				public function get_name() {
					return $this->n;
				}
				public function is_type( $t ) {
					return false;
				}
				public function get_price() {
					return $this->p;
				}
				public function get_short_description() {
					return '';
				}
			};
		}

		// ── Template engine ───────────────────────────────────────────────

		public function test_tokens_and_optional_segments(): void {
			self::assertSame( 'Poutine in Springfield – Acme Kitchen', lafka_seo_render_template( '{term}[ in {city}]{sep}{name}', array( 'term' => 'Poutine' ) ) );

			$this->info['city'] = '';
			self::assertSame( 'Poutine – Acme Kitchen', lafka_seo_render_template( '{term}[ in {city}]{sep}{name}', array( 'term' => 'Poutine' ) ) );
		}

		public function test_cuisines_token_lists_the_first_two(): void {
			self::assertSame( 'Acme Kitchen – Pizza & Poutine in Springfield', lafka_seo_render_template( '{name}[{sep}{cuisines} in {city}]' ) );
			$this->info['cuisines'] = array();
			self::assertSame( 'Acme Kitchen', lafka_seo_render_template( '{name}[{sep}{cuisines} in {city}]' ) );
		}

		public function test_empty_tokens_never_leave_dangling_separators(): void {
			self::assertSame( 'Acme Kitchen', lafka_seo_render_template( '{title}{sep}{name}{sep}{region2}' ) );
			$this->options['lafka_seo_title_sep'] = '|';
			self::assertSame( 'About | Acme Kitchen', lafka_seo_render_template( '{title}{sep}{name}', array( 'title' => 'About' ) ) );
		}

		public function test_excerpt_caps_at_a_word_boundary(): void {
			$long = str_repeat( 'Hand-stretched dough baked hot. ', 12 );
			$out  = lafka_seo_excerpt( '<p>' . $long . '</p>' );
			self::assertLessThanOrEqual( 160, mb_strlen( $out ) );
			self::assertStringEndsWith( '…', $out );
			self::assertSame( 'Short.', lafka_seo_excerpt( 'Short.' ) );
		}

		// ── Titles ────────────────────────────────────────────────────────

		public function test_home_title(): void {
			$this->is['is_front_page'] = true;
			self::assertSame( 'Acme Kitchen – Pizza &amp; Poutine in Springfield', lafka_seo_document_title( '' ) );
		}

		public function test_category_title_with_pagination(): void {
			$this->is['is_product_category'] = true;
			$this->queried                   = new \WP_Term( array( 'term_id' => 5, 'name' => 'Garlic Fingers', 'count' => 6 ) );
			self::assertSame( 'Garlic Fingers in Springfield – Acme Kitchen', lafka_seo_resolve_title() );

			$this->paged = 2;
			self::assertSame( 'Garlic Fingers in Springfield – Acme Kitchen – Page 2', lafka_seo_resolve_title() );
		}

		public function test_menu_product_and_page_titles(): void {
			$this->is['lafka_schema_is_menu_page'] = true;
			$this->is['is_singular']               = true;
			$this->queried                         = (object) array( 'ID' => 3, 'post_title' => 'Menu' );
			self::assertSame( 'Menu – Pizza & Poutine in Springfield – Acme Kitchen', lafka_seo_resolve_title() );

			$this->is = array(
				'is_singular' => true,
				'is_product'  => true,
			);
			$this->queried = (object) array( 'ID' => 9, 'post_title' => 'Donair' );
			Functions\when( 'wc_get_product' )->justReturn( $this->product( 'Donair', '14' ) );
			self::assertSame( 'Donair in Springfield – Acme Kitchen', lafka_seo_resolve_title() );

			$this->is      = array( 'is_singular' => true );
			$this->queried = (object) array( 'ID' => 4, 'post_title' => 'Catering' );
			self::assertSame( 'Catering – Acme Kitchen', lafka_seo_resolve_title() );
		}

		public function test_per_post_override_wins_and_takes_tokens(): void {
			$this->is['is_singular']        = true;
			$this->queried                  = (object) array( 'ID' => 4, 'post_title' => 'Catering' );
			$this->meta['_lafka_seo_title'] = 'Party trays in {city}{sep}{name}';
			self::assertSame( 'Party trays in Springfield – Acme Kitchen', lafka_seo_resolve_title() );
		}

		public function test_operator_template_option_is_used(): void {
			$this->is['is_front_page']             = true;
			$this->options['lafka_seo_title_home'] = 'Order {cuisines} online{sep}{name}';
			self::assertSame( 'Order Pizza & Poutine online – Acme Kitchen', lafka_seo_resolve_title() );
		}

		public function test_untemplated_contexts_keep_the_wordpress_title(): void {
			$this->is['is_search'] = true;
			self::assertSame( '', lafka_seo_document_title( '' ) );
			$this->is = array( 'is_home' => true );
			self::assertSame( '', lafka_seo_document_title( '' ) );
		}

		public function test_silent_when_an_seo_plugin_owns_the_head_or_disabled(): void {
			$this->is['is_front_page'] = true;
			$this->seo_plugin          = true;
			self::assertSame( '', lafka_seo_document_title( '' ) );

			$this->filters['lafka_head_meta_force_emit'] = true;
			self::assertNotSame( '', lafka_seo_document_title( '' ) );

			$this->seo_plugin                          = false;
			$this->filters                             = array( 'lafka_seo_titles_enabled' => false );
			self::assertSame( '', lafka_seo_document_title( '' ) );
		}

		public function test_an_earlier_filter_title_is_respected(): void {
			$this->is['is_front_page'] = true;
			self::assertSame( 'Custom', lafka_seo_document_title( 'Custom' ) );
		}

		// ── Descriptions ──────────────────────────────────────────────────

		public function test_product_fallback_description_is_fact_built(): void {
			self::assertSame(
				'Donair from $14.00 at Acme Kitchen in Springfield. Order online or call (555) 123-4567.',
				lafka_seo_product_description( $this->product( 'Donair', '14' ) )
			);
			$this->info['phone_display'] = '';
			self::assertSame(
				'Donair at Acme Kitchen in Springfield. Order online.',
				lafka_seo_product_description( $this->product( 'Donair', '' ) )
			);
		}

		public function test_category_fallback_description_uses_count_and_price_from(): void {
			$term = new \WP_Term( array( 'term_id' => 5, 'name' => 'Wings', 'count' => 6 ) );
			self::assertSame( 'Order Wings online from Acme Kitchen in Springfield — 6 items from $8.50.', lafka_seo_term_description( $term ) );

			$empty = new \WP_Term( array( 'term_id' => 99, 'name' => 'Dips', 'count' => 0 ) );
			self::assertSame( 'Order Dips online from Acme Kitchen in Springfield.', lafka_seo_term_description( $empty ) );
		}
	}
}
