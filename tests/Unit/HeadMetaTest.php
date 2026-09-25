<?php
/**
 * Head metadata (incl/seo/lafka-head-meta.php): OpenGraph / Twitter tags,
 * <meta name="description"> and the <html lang> override.
 *
 *   - f049: both emitters stay silent while an SEO plugin owns the head,
 *     unless `lafka_head_meta_force_emit` returns true;
 *   - v8.11.4: a static front page is also is_singular(); the front-page
 *     branch wins, so the homepage is og:type restaurant.restaurant titled
 *     with the site name;
 *   - v9.7.24: og:image width/height are the attachment's real size, and are
 *     omitted when unknown (a raw URL);
 *   - v9.22.0 / v9.22.1: the WP default tagline and a tagline equal to the
 *     site name are skipped, and the constructed pitch reads the resolver's
 *     flat `cuisines` / `city` keys.
 *
 * @package Lafka\Plugin\Tests\Unit
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class HeadMetaTest extends TestCase {

	private const SITE = 'Test Kitchen';

	/** @var list<array{0:string, 1:mixed, 2:int, 3:int}>|null What the module hooked when it loaded. */
	private static ?array $registrations = null;

	/** @var array<string, bool> Conditional tag => value. */
	private array $is = array();

	private bool $seo_plugin = false;

	/** @var array<string, mixed> Filter name => forced return value. */
	private array $filters = array();

	/** @var array<string, mixed> Merged into lafka_get_restaurant_info(). */
	private array $info = array();

	/** @var array<string, string> */
	private array $bloginfo = array();

	/** @var array<string, mixed> */
	private array $theme_mods = array();

	/** @var array<string, mixed> Post meta key => value (any post). */
	private array $meta = array();

	private int $thumbnail_id = 0;

	/** @var array<int, array{0:string, 1:int, 2:int}> Attachment ID => src. */
	private array $attachments = array();

	private string $site_icon = '';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->bloginfo = array(
			'name'        => self::SITE,
			'description' => '',
		);

		foreach ( array( 'is_admin', 'is_feed', 'is_404', 'is_front_page', 'is_home', 'is_singular', 'is_tax', 'is_category', 'is_tag', 'is_product', 'is_shop', 'is_post_type_archive', 'is_product_category' ) as $tag ) {
			Functions\when( $tag )->alias( fn() => $this->is[ $tag ] ?? false );
		}
		Functions\when( 'lafka_seo_plugin_active' )->alias( fn() => $this->seo_plugin );
		Functions\when( 'apply_filters' )->alias(
			function ( $hook, $value ) {
				if ( 'lafka_restaurant_info' === $hook ) {
					return array_merge( (array) $value, $this->info );
				}
				return array_key_exists( $hook, $this->filters ) ? $this->filters[ $hook ] : $value;
			}
		);
		Functions\when( 'get_queried_object' )->alias( fn() => $GLOBALS['post'] ?? null );
		Functions\when( 'get_bloginfo' )->alias( fn( $key = '' ) => $this->bloginfo[ $key ] ?? '' );
		Functions\when( 'get_theme_mod' )->alias( fn( $key, $fallback = false ) => $this->theme_mods[ $key ] ?? $fallback );
		Functions\when( 'get_option' )->alias( static fn( $key, $fallback = false ) => $fallback );
		Functions\when( 'get_post_meta' )->alias( fn( $id, $key ) => $this->meta[ $key ] ?? '' );
		Functions\when( 'get_post_thumbnail_id' )->alias( fn() => $this->thumbnail_id );
		Functions\when( 'wp_get_attachment_image_src' )->alias( fn( $id ) => $this->attachments[ $id ] ?? false );
		Functions\when( 'get_site_icon_url' )->alias( fn() => $this->site_icon );
		Functions\when( 'home_url' )->alias( static fn( $path = '' ) => 'https://example.test' . $path );
		Functions\when( 'trailingslashit' )->alias( static fn( $url ) => rtrim( (string) $url, '/' ) . '/' );
		Functions\when( 'get_permalink' )->alias( static fn( $post ) => 'https://example.test/?p=' . $post->ID );
		Functions\when( 'get_the_title' )->alias( static fn( $post ) => $post->post_title );
		Functions\when( 'get_locale' )->justReturn( 'en_US' );
		Functions\when( 'wp_get_document_title' )->justReturn( 'Menu' );
		Functions\when( 'add_query_arg' )->justReturn( '/menu/' );
		Functions\when( 'wp_strip_all_tags' )->alias( static fn( $s ) => trim( strip_tags( (string) $s ) ) );
		Functions\when( 'esc_attr' )->alias( static fn( $s ) => htmlspecialchars( (string) $s, ENT_QUOTES ) );
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_cache_get' )->justReturn( false );
		Functions\when( 'wp_cache_set' )->justReturn( true );

		require_once dirname( __DIR__, 2 ) . '/incl/schema/lafka-schema-helpers.php';
		if ( null === self::$registrations ) {
			$before = count( $GLOBALS['lafka_test_hooks'] );
			require_once dirname( __DIR__, 2 ) . '/incl/seo/lafka-head-meta.php';
			self::$registrations = array_slice( $GLOBALS['lafka_test_hooks'], $before );
		}
	}

	protected function tearDown(): void {
		unset( $GLOBALS['post'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	private function og(): string {
		ob_start();
		lafka_insert_og_tags();
		return (string) ob_get_clean();
	}

	private function description_tag(): string {
		ob_start();
		lafka_render_meta_description();
		return (string) ob_get_clean();
	}

	/** @param array<string, mixed> $fields */
	private function singular_post( array $fields = array() ): object {
		$this->is['is_singular'] = true;
		$post                    = (object) array_merge(
			array(
				'ID'           => 7,
				'post_title'   => 'A Post',
				'post_excerpt' => '',
			),
			$fields
		);
		$GLOBALS['post']         = $post;
		return $post;
	}

	private static function meta_content( string $html, string $attr, string $name ): ?string {
		return preg_match( '/<meta ' . $attr . '="' . preg_quote( $name, '/' ) . '" content="([^"]*)">/', $html, $m ) ? $m[1] : null;
	}

	public function test_hooks_are_the_public_api(): void {
		$this->assertSame(
			array(
				array( 'wp_head', 'lafka_insert_og_tags', 10, 1 ),
				array( 'language_attributes', 'lafka_filter_language_attributes', 10, 2 ),
				array( 'wp_head', 'lafka_render_meta_description', 1, 1 ),
			),
			self::$registrations
		);
	}

	public function test_head_meta_defers_to_an_active_seo_plugin_unless_forced(): void {
		$this->bloginfo['description'] = 'Wood-fired pizza';
		$this->seo_plugin              = true;
		$this->assertSame( '', $this->og() );
		$this->assertSame( '', $this->description_tag() );

		$this->filters['lafka_head_meta_force_emit'] = true;
		$this->assertStringContainsString( 'og:title', $this->og() );
		$this->assertSame( "<meta name=\"description\" content=\"Wood-fired pizza\">\n", $this->description_tag() );

		$this->seo_plugin = false;
		unset( $this->filters['lafka_head_meta_force_emit'] );
		$this->assertStringContainsString( 'og:title', $this->og() );
	}

	public function test_nothing_is_emitted_in_admin_feeds_or_404s(): void {
		foreach ( array( 'is_admin', 'is_feed', 'is_404' ) as $tag ) {
			$this->is = array( $tag => true );
			$this->assertSame( '', $this->og(), $tag );
			$this->assertSame( '', $this->description_tag(), $tag );
		}
	}

	public function test_a_static_front_page_is_tagged_as_the_restaurant(): void {
		$this->is['is_front_page'] = true;
		$this->singular_post(
			array(
				'post_title'   => 'Home New',
				'post_excerpt' => 'Front page excerpt',
			)
		);

		$og = $this->og();
		$this->assertSame( 'restaurant.restaurant', self::meta_content( $og, 'property', 'og:type' ) );
		$this->assertSame( self::SITE, self::meta_content( $og, 'property', 'og:title' ) );
		$this->assertSame( 'https://example.test/', self::meta_content( $og, 'property', 'og:url' ) );
		// The page's own description override / excerpt still applies.
		$this->assertSame( 'Front page excerpt', self::meta_content( $og, 'property', 'og:description' ) );
	}

	public function test_singular_posts_and_products_get_their_own_type(): void {
		$this->singular_post();
		$og = $this->og();
		$this->assertSame( 'article', self::meta_content( $og, 'property', 'og:type' ) );
		$this->assertSame( 'A Post – Test Kitchen', self::meta_content( $og, 'property', 'og:title' ), 'T-16: site suffix' );
		$this->assertSame( 'A Post – Test Kitchen', self::meta_content( $og, 'name', 'twitter:title' ) );
		$this->assertSame( 'https://example.test/?p=7', self::meta_content( $og, 'property', 'og:url' ) );

		$this->is['is_product'] = true;
		Functions\when( 'wc_get_product' )->justReturn( false );
		$this->assertSame( 'product', self::meta_content( $this->og(), 'property', 'og:type' ) );
	}

	public function test_og_image_dimensions_are_the_attachments_real_size(): void {
		$this->singular_post();
		$this->thumbnail_id   = 9;
		$this->attachments[9] = array( 'https://example.test/portrait.jpg', 800, 1200 );

		$og = $this->og();
		$this->assertSame( 'https://example.test/portrait.jpg', self::meta_content( $og, 'property', 'og:image' ) );
		$this->assertSame( '800', self::meta_content( $og, 'property', 'og:image:width' ) );
		$this->assertSame( '1200', self::meta_content( $og, 'property', 'og:image:height' ) );
		$this->assertSame( 'summary', self::meta_content( $og, 'name', 'twitter:card' ), 'T-16: a portrait image would be cropped by the large card.' );

		$this->attachments[9] = array( 'https://example.test/wide.jpg', 1200, 630 );
		$this->assertSame( 'summary_large_image', self::meta_content( $this->og(), 'name', 'twitter:card' ) );
	}

	public function test_an_image_url_override_wins_and_omits_unknown_dimensions(): void {
		$this->singular_post();
		$this->thumbnail_id             = 9;
		$this->attachments[9]           = array( 'https://example.test/featured.jpg', 800, 1200 );
		$this->meta['_lafka_og_image'] = 'https://example.test/override.jpg';

		$og = $this->og();
		$this->assertSame( 'https://example.test/override.jpg', self::meta_content( $og, 'property', 'og:image' ) );
		$this->assertNull( self::meta_content( $og, 'property', 'og:image:width' ) );
		$this->assertNull( self::meta_content( $og, 'property', 'og:image:height' ) );
	}

	public function test_image_falls_back_to_the_customizer_default_then_the_site_icon(): void {
		$this->attachments[3]                       = array( 'https://example.test/hero.jpg', 1200, 630 );
		$this->theme_mods['lafka_og_image_default'] = '3';
		$og                                         = $this->og();
		$this->assertSame( 'https://example.test/hero.jpg', self::meta_content( $og, 'property', 'og:image' ) );
		$this->assertSame( '630', self::meta_content( $og, 'property', 'og:image:height' ) );

		unset( $this->theme_mods['lafka_og_image_default'] );
		$this->site_icon = 'https://example.test/icon.png';
		$og              = $this->og();
		$this->assertSame( 'https://example.test/icon.png', self::meta_content( $og, 'property', 'og:image' ) );
		$this->assertSame( '1200', self::meta_content( $og, 'property', 'og:image:width' ) );

		$this->site_icon = '';
		$og              = $this->og();
		$this->assertNull( self::meta_content( $og, 'property', 'og:image' ) );
		$this->assertSame( 'summary', self::meta_content( $og, 'name', 'twitter:card' ) );
	}

	/** T-16: pages are `website`; a title already naming the site gets no second suffix. */
	public function test_pages_are_websites_and_titles_are_not_double_suffixed(): void {
		$this->singular_post(
			array(
				'post_type'  => 'page',
				'post_title' => 'Contact Test Kitchen',
			)
		);
		$og = $this->og();
		$this->assertSame( 'website', self::meta_content( $og, 'property', 'og:type' ) );
		$this->assertSame( 'Contact Test Kitchen', self::meta_content( $og, 'property', 'og:title' ) );
	}

	/** T-16: a menu category shares its own thumbnail; the site icon never gets the large card. */
	public function test_a_category_shares_its_thumbnail_and_the_icon_gets_the_small_card(): void {
		$this->is['is_tax'] = true;
		Functions\when( 'get_queried_object' )->justReturn(
			(object) array(
				'term_id'     => 5,
				'name'        => 'Wings',
				'taxonomy'    => 'product_cat',
				'description' => 'Crispy.',
			)
		);
		Functions\when( 'get_term_link' )->justReturn( 'https://example.test/menu/wings/' );
		$thumb = 0;
		Functions\when( 'get_term_meta' )->alias(
			static function ( $id, $key ) use ( &$thumb ) {
				return 5 === $id && 'thumbnail_id' === $key ? $thumb : '';
			}
		);
		$this->site_icon = 'https://example.test/icon.png';

		$og = $this->og();
		$this->assertSame( 'https://example.test/icon.png', self::meta_content( $og, 'property', 'og:image' ) );
		$this->assertSame( 'summary', self::meta_content( $og, 'name', 'twitter:card' ), 'square logo → small card' );

		$thumb                 = 21;
		$this->attachments[21] = array( 'https://example.test/wings.jpg', 1600, 900 );
		$og                    = $this->og();
		$this->assertSame( 'https://example.test/wings.jpg', self::meta_content( $og, 'property', 'og:image' ) );
		$this->assertSame( 'summary_large_image', self::meta_content( $og, 'name', 'twitter:card' ) );
	}

	/** T-16: with no page image and no default share image, the homepage hero beats the logo. */
	public function test_the_hero_photo_is_the_fallback_before_the_site_icon(): void {
		Functions\when( 'wp_get_attachment_image_url' )->alias( fn( $id ) => $this->attachments[ $id ][0] ?? false );
		require_once dirname( __DIR__, 2 ) . '/incl/perf/lcp-preload.php';
		$this->theme_mods['lafka_home_hero_image_id'] = 11;
		$this->attachments[11]                        = array( 'https://example.test/hero.jpg', 1600, 900 );
		$this->site_icon                              = 'https://example.test/icon.png';

		$og = $this->og();
		$this->assertSame( 'https://example.test/hero.jpg', self::meta_content( $og, 'property', 'og:image' ) );
		$this->assertSame( '900', self::meta_content( $og, 'property', 'og:image:height' ) );
		$this->assertSame( 'summary_large_image', self::meta_content( $og, 'name', 'twitter:card' ) );
	}

	public function test_the_card_rule_and_its_filter(): void {
		$this->assertSame( 'summary', lafka_og_twitter_card( '' ) );
		$this->assertSame( 'summary', lafka_og_twitter_card( 'x.png', 1200, 1200 ) );
		$this->assertSame( 'summary', lafka_og_twitter_card( 'x.png', 500, 250 ), 'too small for the large card' );
		$this->assertSame( 'summary_large_image', lafka_og_twitter_card( 'x.png', 1200, 630 ) );
		$this->assertSame( 'summary_large_image', lafka_og_twitter_card( 'x.png' ), 'operator URL, size unknown' );
		$this->assertSame( 'summary', lafka_og_twitter_card( 'x.png', 1600, 900, true ) );

		$this->filters['lafka_twitter_card'] = 'summary_large_image';
		$this->assertSame( 'summary_large_image', lafka_og_twitter_card( 'x.png', 100, 100 ) );
		$this->filters['lafka_twitter_card'] = 'player';
		$this->assertSame( 'summary', lafka_og_twitter_card( 'x.png', 100, 100 ), 'unknown card types are refused' );
	}

	public function test_the_customizer_locale_drives_og_locale_and_html_lang(): void {
		$this->assertSame( 'en_US', self::meta_content( $this->og(), 'property', 'og:locale' ) );
		$this->assertSame( 'lang="en-US"', lafka_filter_language_attributes( 'lang="en-US"' ) );

		$this->theme_mods['lafka_default_locale'] = 'en-CA';
		$this->assertSame( 'en_CA', self::meta_content( $this->og(), 'property', 'og:locale' ) );
		$this->assertSame( 'dir="ltr" lang="en-CA"', lafka_filter_language_attributes( 'dir="ltr" lang="en-US"' ) );
		$this->assertSame( 'dir="ltr" lang="en-CA"', lafka_filter_language_attributes( 'dir="ltr"' ) );

		$this->is['is_admin'] = true;
		$this->assertSame( 'lang="en-US"', lafka_filter_language_attributes( 'lang="en-US"' ) );
	}

	public function test_a_per_post_description_override_wins(): void {
		$post                                   = $this->singular_post( array( 'post_excerpt' => 'The excerpt' ) );
		$this->meta['_lafka_meta_description'] = 'Hand-written description';
		$this->assertSame( 'Hand-written description', lafka_resolve_meta_description( $post ) );

		$this->meta = array();
		$this->assertSame( 'The excerpt', lafka_resolve_meta_description( $post ) );
	}

	public function test_the_wp_default_tagline_and_a_site_name_tagline_are_skipped(): void {
		$this->info = array( 'description' => '<p>Restaurant description</p>' );

		$this->bloginfo['description'] = 'Just another WordPress site';
		$this->assertSame( 'Restaurant description', lafka_resolve_meta_description( null ) );

		$this->bloginfo['description'] = ' test kitchen ';
		$this->assertSame( 'Restaurant description', lafka_resolve_meta_description( null ) );

		$this->bloginfo['description'] = 'Pizza by the slice';
		$this->assertSame( 'Pizza by the slice', lafka_resolve_meta_description( null ) );
	}

	public function test_the_constructed_pitch_reads_cuisines_and_city(): void {
		$this->info = array(
			'cuisines' => array( 'Pizza', 'Salads' ),
			'city'     => 'Testville',
		);
		$this->assertSame( self::SITE . ' — Fresh Pizza, Salads in Testville — order online or call.', lafka_resolve_meta_description( null ) );

		$this->info = array(
			'cuisines' => array(),
			'city'     => 'Testville',
		);
		$this->assertSame( self::SITE . ' — Serving Testville — order online or call.', lafka_resolve_meta_description( null ) );
	}

	public function test_a_term_archive_uses_the_term_description(): void {
		$this->is['is_tax'] = true;
		$term               = (object) array(
			'name'        => 'Pizzas',
			'description' => '<b>Stone-baked</b>',
		);
		Functions\when( 'get_queried_object' )->justReturn( $term );
		Functions\when( 'get_term_link' )->justReturn( 'https://example.test/menu/pizzas/' );

		$og = $this->og();
		$this->assertSame( 'Pizzas – Test Kitchen', self::meta_content( $og, 'property', 'og:title' ) );
		$this->assertSame( 'Stone-baked', self::meta_content( $og, 'property', 'og:description' ) );
		$this->assertSame( 'website', self::meta_content( $og, 'property', 'og:type' ) );
		$this->assertSame( 'Stone-baked', lafka_resolve_meta_description( null ) );
	}

	/**
	 * GX3: a product without a short description and a menu category without
	 * a description get a fact-built line from the Search & AI templates
	 * instead of the site-wide pitch; long descriptions are SERP-capped.
	 */
	private function load_templates(): void {
		require_once dirname( __DIR__, 2 ) . '/incl/seo/lafka-seo-settings.php';
		require_once dirname( __DIR__, 2 ) . '/incl/seo/lafka-seo-titles.php';
		Functions\when( 'home_url' )->alias( static fn( $path = '' ) => 'https://example.test' . $path );
		require_once dirname( __DIR__, 2 ) . '/incl/schema/lafka-schema-menu.php';
		Functions\when( 'lafka_schema_menu_data' )->justReturn(
			array(
				'sections' => array( 5 => array( 'price_min' => '8.50' ) ),
				'order'    => array( 5 ),
			)
		);
		Functions\when( 'wc_price' )->alias( static fn( $p ) => '&#36;' . number_format( (float) $p, 2 ) );
		$this->info = array(
			'name'          => self::SITE,
			'city'          => 'Testville',
			'phone_display' => '',
		);
	}

	public function test_a_menu_category_without_a_description_gets_the_category_template(): void {
		$this->load_templates();
		$this->is['is_tax'] = true;
		Functions\when( 'get_queried_object' )->justReturn(
			(object) array(
				'term_id'     => 5,
				'name'        => 'Wings',
				'count'       => 6,
				'taxonomy'    => 'product_cat',
				'description' => '',
			)
		);
		$this->assertSame( 'Order Wings online from Test Kitchen in Testville — 6 items from $8.50.', lafka_resolve_meta_description( null ) );
	}

	public function test_a_long_term_description_is_capped_for_the_serp(): void {
		$this->load_templates();
		$this->is['is_tax'] = true;
		Functions\when( 'get_queried_object' )->justReturn(
			(object) array(
				'name'        => 'Wings',
				'taxonomy'    => 'product_cat',
				'description' => '<p>' . str_repeat( 'Crispy wings tossed in house sauce. ', 10 ) . '</p>',
			)
		);
		$out = lafka_resolve_meta_description( null );
		$this->assertLessThanOrEqual( 160, mb_strlen( $out ) );
		$this->assertStringStartsWith( 'Crispy wings tossed', $out );
	}

	public function test_a_product_without_a_short_description_gets_the_product_template(): void {
		$this->load_templates();
		$post                   = $this->singular_post( array( 'post_title' => 'Donair' ) );
		$this->is['is_product'] = true;
		Functions\when( 'wc_get_product' )->justReturn(
			new class() {
				public function get_short_description() {
					return '';
				}
				public function get_name() {
					return 'Donair';
				}
				public function is_type( $t ) {
					return false;
				}
				public function get_price() {
					return '14';
				}
			}
		);
		$this->assertSame( 'Donair, from $14.00 at Test Kitchen in Testville. Order online.', lafka_resolve_meta_description( $post ) );
	}

	/** T-15: a thin short description is wrapped by the product template; a substantial one stands alone. */
	public function test_a_thin_short_description_is_wrapped_by_the_product_template(): void {
		$this->load_templates();
		$post                   = $this->singular_post( array( 'post_title' => 'Fries' ) );
		$this->is['is_product'] = true;
		$short                  = 'Plain or Seasoned';
		$product                = new class( $short ) {
			public function __construct( public string $short ) {
			}
			public function get_short_description() {
				return $this->short;
			}
			public function get_name() {
				return 'Fries';
			}
			public function is_type( $t ) {
				return false;
			}
			public function get_price() {
				return '4.99';
			}
		};
		Functions\when( 'wc_get_product' )->justReturn( $product );
		$this->assertSame( 'Fries — Plain or Seasoned, from $4.99 at Test Kitchen in Testville. Order online.', lafka_resolve_meta_description( $post ) );

		$product->short = 'Hand-cut Kennebec potatoes, fried twice in canola oil and tossed with sea salt or house seasoning.';
		$this->assertSame( $product->short, lafka_resolve_meta_description( $post ) );
	}

	/** T-15: inner pages get their own description, the front page keeps the restaurant pitch. */
	public function test_inner_pages_never_share_the_site_pitch(): void {
		$this->load_templates();
		$this->bloginfo['description'] = 'Pizza by the slice';
		$about                         = $this->singular_post(
			array(
				'post_title'   => 'About Us',
				'post_name'    => 'about-us',
				'post_content' => '<p>Short.</p>',
			)
		);
		$this->assertSame( 'About Us — Test Kitchen in Testville. Order online.', lafka_resolve_meta_description( $about ) );

		$this->is['is_front_page'] = true;
		$this->assertSame( 'Pizza by the slice', lafka_resolve_meta_description( $about ) );
	}
}
