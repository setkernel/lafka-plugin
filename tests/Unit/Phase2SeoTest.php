<?php
/**
 * Phase 2 (v9.26.0) — FAQPage schema + sitemap exclusions + robots audit.
 *
 * Locks down:
 *   - FAQPage entity emits with correct schema.org shape
 *   - FAQ resolves from theme_mods AND from page content (block / classic)
 *   - FAQ skips on non-contact pages
 *   - Sitemap filter excludes WC funnel pages from the `page` sub-sitemap
 *   - Sitemap filter passes through other post types unchanged
 *   - robots.txt filter appends every required Disallow directive
 *   - robots.txt filter no-ops in "Discourage search engines" mode
 *   - Plugin wires the new modules + bumps version to 9.26.0
 *
 * @package Lafka\Plugin\Tests\Unit
 * @since   9.26.0
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

// WP-core symbols used by the modules under test. OBJECT is a sentinel
// constant defined by wp-includes/load.php; WP_Post is the canonical
// post-row type. Both must be present before the modules load.
if ( ! defined( 'OBJECT' ) ) {
	define( 'OBJECT', 'OBJECT' );
}
require_once __DIR__ . '/Stubs/wp-post-stub.php';

require_once dirname( __DIR__, 2 ) . '/incl/schema/lafka-schema-faq.php';
require_once dirname( __DIR__, 2 ) . '/incl/seo/lafka-sitemap.php';
require_once dirname( __DIR__, 2 ) . '/incl/seo/lafka-robots.php';

final class Phase2SeoTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function plugin_root(): string {
		return dirname( __DIR__, 2 );
	}

	// ────────────────────────────────────────────────────────────────────
	// 1. Plugin wiring
	// ────────────────────────────────────────────────────────────────────

	public function test_plugin_version_bumped_to_9_26_0(): void {
		$src = (string) file_get_contents( $this->plugin_root() . '/lafka-plugin.php' );
		$this->assertMatchesRegularExpression(
			'/Version:\s*9\.26\.0\b/',
			$src,
			'Plugin header must declare version 9.26.0 for Phase 2.'
		);
	}

	public function test_plugin_requires_faq_schema_module(): void {
		$src = (string) file_get_contents( $this->plugin_root() . '/incl/schema/class-lafka-json-ld.php' );
		$this->assertStringContainsString(
			'lafka-schema-faq.php',
			$src,
			'JSON-LD orchestrator must require the FAQ schema module.'
		);
	}

	public function test_orchestrator_adds_faq_to_graph(): void {
		$src = (string) file_get_contents( $this->plugin_root() . '/incl/schema/class-lafka-json-ld.php' );
		$this->assertStringContainsString(
			'lafka_schema_faq()',
			$src,
			'JSON-LD orchestrator must call lafka_schema_faq() into the @graph builder.'
		);
	}

	public function test_plugin_requires_sitemap_module(): void {
		$src = (string) file_get_contents( $this->plugin_root() . '/lafka-plugin.php' );
		$this->assertStringContainsString( 'incl/seo/lafka-sitemap.php', $src );
	}

	public function test_plugin_requires_robots_module(): void {
		$src = (string) file_get_contents( $this->plugin_root() . '/lafka-plugin.php' );
		$this->assertStringContainsString( 'incl/seo/lafka-robots.php', $src );
	}

	// ────────────────────────────────────────────────────────────────────
	// 2. FAQPage schema — contact-page gate
	// ────────────────────────────────────────────────────────────────────

	private function stub_contact_page( string $slug = 'contact' ): void {
		Functions\when( 'is_page' )->justReturn( true );
		Functions\when( 'get_post_field' )->justReturn( $slug );
		Functions\when( 'is_page_template' )->justReturn( false );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'wp_strip_all_tags' )->returnArg();
	}

	private function stub_non_contact_page(): void {
		Functions\when( 'is_page' )->justReturn( true );
		Functions\when( 'get_post_field' )->justReturn( 'about-us' );
		Functions\when( 'is_page_template' )->justReturn( false );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'wp_strip_all_tags' )->returnArg();
	}

	public function test_faq_returns_null_on_non_contact_page(): void {
		$this->stub_non_contact_page();
		Functions\when( 'get_theme_mod' )->returnArg( 2 );

		$schema = \lafka_schema_faq();
		$this->assertNull( $schema, 'FAQ schema must return null on non-contact pages.' );
	}

	public function test_faq_returns_null_when_not_a_page(): void {
		Functions\when( 'is_page' )->justReturn( false );
		Functions\when( 'get_post_field' )->justReturn( '' );
		Functions\when( 'is_page_template' )->justReturn( false );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'wp_strip_all_tags' )->returnArg();
		Functions\when( 'get_theme_mod' )->returnArg( 2 );

		$this->assertNull( \lafka_schema_faq(), 'FAQ schema must return null when not on a singular page.' );
	}

	public function test_faq_emits_on_contact_slug(): void {
		$this->stub_contact_page( 'contact' );
		Functions\when( 'get_theme_mod' )->alias( static function ( $key, $default = '' ) {
			$fixtures = array(
				'lafka_contact_faq_1_q' => 'How long do orders take?',
				'lafka_contact_faq_1_a' => 'About 25 minutes for pickup.',
			);
			return $fixtures[ $key ] ?? $default;
		} );

		$schema = \lafka_schema_faq();
		$this->assertIsArray( $schema );
		$this->assertSame( 'FAQPage', $schema['@type'] );
	}

	public function test_faq_emits_on_contact_us_slug(): void {
		$this->stub_contact_page( 'contact-us' );
		Functions\when( 'get_theme_mod' )->alias( static function ( $key, $default = '' ) {
			$fixtures = array(
				'lafka_contact_faq_1_q' => 'Q1?',
				'lafka_contact_faq_1_a' => 'A1.',
			);
			return $fixtures[ $key ] ?? $default;
		} );

		$schema = \lafka_schema_faq();
		$this->assertIsArray( $schema );
		$this->assertSame( 'FAQPage', $schema['@type'] );
	}

	public function test_faq_emits_on_contact_template(): void {
		Functions\when( 'is_page' )->justReturn( true );
		Functions\when( 'get_post_field' )->justReturn( 'reach-us' );
		Functions\when( 'is_page_template' )->alias( static fn( $tpl ) => 'template-contact.php' === $tpl );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'wp_strip_all_tags' )->returnArg();
		Functions\when( 'get_theme_mod' )->alias( static function ( $key, $default = '' ) {
			$fixtures = array(
				'lafka_contact_faq_1_q' => 'Q?',
				'lafka_contact_faq_1_a' => 'A.',
			);
			return $fixtures[ $key ] ?? $default;
		} );

		$schema = \lafka_schema_faq();
		$this->assertIsArray( $schema, 'FAQ must emit when page uses template-contact.php even if slug differs.' );
	}

	// ────────────────────────────────────────────────────────────────────
	// 3. FAQPage schema — output shape
	// ────────────────────────────────────────────────────────────────────

	public function test_faq_schema_shape_matches_spec(): void {
		$this->stub_contact_page();
		Functions\when( 'get_theme_mod' )->alias( static function ( $key, $default = '' ) {
			$fixtures = array(
				'lafka_contact_faq_1_q' => 'How long do orders take?',
				'lafka_contact_faq_1_a' => 'About 25 minutes for pickup.',
				'lafka_contact_faq_2_q' => 'Do you deliver?',
				'lafka_contact_faq_2_a' => 'Yes, in our delivery area.',
			);
			return $fixtures[ $key ] ?? $default;
		} );

		$schema = \lafka_schema_faq();
		$this->assertIsArray( $schema );
		$this->assertSame( 'FAQPage', $schema['@type'] );
		$this->assertArrayHasKey( 'mainEntity', $schema );
		$this->assertIsArray( $schema['mainEntity'] );
		$this->assertCount( 2, $schema['mainEntity'] );

		foreach ( $schema['mainEntity'] as $entry ) {
			$this->assertSame( 'Question', $entry['@type'] );
			$this->assertArrayHasKey( 'name', $entry );
			$this->assertIsString( $entry['name'] );
			$this->assertNotEmpty( $entry['name'] );
			$this->assertArrayHasKey( 'acceptedAnswer', $entry );
			$this->assertSame( 'Answer', $entry['acceptedAnswer']['@type'] );
			$this->assertArrayHasKey( 'text', $entry['acceptedAnswer'] );
			$this->assertIsString( $entry['acceptedAnswer']['text'] );
			$this->assertNotEmpty( $entry['acceptedAnswer']['text'] );
		}
	}

	public function test_faq_drops_incomplete_pairs(): void {
		$this->stub_contact_page();
		Functions\when( 'get_theme_mod' )->alias( static function ( $key, $default = '' ) {
			// Only Q3 has both halves; the others should be dropped.
			$fixtures = array(
				'lafka_contact_faq_1_q' => 'Question with no answer',
				'lafka_contact_faq_2_a' => 'Answer with no question',
				'lafka_contact_faq_3_q' => 'Complete Q',
				'lafka_contact_faq_3_a' => 'Complete A',
			);
			return $fixtures[ $key ] ?? $default;
		} );

		$schema = \lafka_schema_faq();
		$this->assertIsArray( $schema );
		$this->assertCount( 1, $schema['mainEntity'], 'Only complete (q+a) pairs must emit.' );
		$this->assertSame( 'Complete Q', $schema['mainEntity'][0]['name'] );
	}

	public function test_faq_returns_null_when_no_items_resolved(): void {
		$this->stub_contact_page();
		Functions\when( 'get_theme_mod' )->returnArg( 2 );
		// No page content to fall back to either.
		Functions\when( 'get_queried_object' )->justReturn( null );

		$this->assertNull( \lafka_schema_faq(), 'FAQ must return null when no items resolve.' );
	}

	public function test_faq_json_safe_for_apostrophes_and_ampersands(): void {
		$this->stub_contact_page();
		Functions\when( 'get_theme_mod' )->alias( static function ( $key, $default = '' ) {
			$fixtures = array(
				'lafka_contact_faq_1_q' => "What's the deal & how does it work?",
				'lafka_contact_faq_1_a' => "It's simple — pick & order.",
			);
			return $fixtures[ $key ] ?? $default;
		} );

		$schema = \lafka_schema_faq();
		$this->assertIsArray( $schema );
		$json = wp_json_encode_compat( $schema );
		$this->assertIsString( $json );
		// Decode round-trip — apostrophes and ampersands must survive.
		$decoded = json_decode( $json, true );
		$this->assertIsArray( $decoded );
		$this->assertSame( "What's the deal & how does it work?", $decoded['mainEntity'][0]['name'] );
		$this->assertSame( "It's simple — pick & order.", $decoded['mainEntity'][0]['acceptedAnswer']['text'] );
	}

	// ────────────────────────────────────────────────────────────────────
	// 4. FAQPage — content parser
	// ────────────────────────────────────────────────────────────────────

	public function test_faq_parser_extracts_from_classic_html(): void {
		$html = <<<HTML
<div class="lafka-contact__faq-list">
	<details class="lafka-contact__faq-item">
		<summary class="lafka-contact__faq-q">Do you cater?<span class="lafka-contact__faq-icon" aria-hidden="true">+</span></summary>
		<div class="lafka-contact__faq-a">Yes — give us a call.</div>
	</details>
	<details class="lafka-contact__faq-item">
		<summary class="lafka-contact__faq-q">Are you open Sundays?</summary>
		<div class="lafka-contact__faq-a">Yes, 11am to 9pm.</div>
	</details>
</div>
HTML;
		Functions\when( 'has_blocks' )->justReturn( false );

		$items = \lafka_schema_faq_items_from_content( $html );
		$this->assertCount( 2, $items );
		$this->assertSame( 'Do you cater?', $items[0]['q'] );
		$this->assertSame( 'Yes — give us a call.', $items[0]['a'] );
		$this->assertSame( 'Are you open Sundays?', $items[1]['q'] );
	}

	public function test_faq_parser_extracts_from_block_editor(): void {
		$html = "<!-- wp:html -->\n"
			. '<details class="lafka-contact__faq-item">'
			. '<summary class="lafka-contact__faq-q">Block question</summary>'
			. '<div class="lafka-contact__faq-a">Block answer</div>'
			. '</details>'
			. "\n<!-- /wp:html -->";

		Functions\when( 'has_blocks' )->justReturn( true );
		Functions\when( 'parse_blocks' )->justReturn(
			array(
				array(
					'blockName'    => 'core/html',
					'innerHTML'    => '<details class="lafka-contact__faq-item"><summary class="lafka-contact__faq-q">Block question</summary><div class="lafka-contact__faq-a">Block answer</div></details>',
					'innerBlocks'  => array(),
					'innerContent' => array(),
				),
			)
		);

		$items = \lafka_schema_faq_items_from_content( $html );
		$this->assertCount( 1, $items );
		$this->assertSame( 'Block question', $items[0]['q'] );
		$this->assertSame( 'Block answer', $items[0]['a'] );
	}

	public function test_faq_parser_returns_empty_for_unrelated_content(): void {
		Functions\when( 'has_blocks' )->justReturn( false );
		$items = \lafka_schema_faq_items_from_content( '<p>Just some prose, no FAQ here.</p>' );
		$this->assertSame( array(), $items );
	}

	public function test_faq_parser_drops_items_missing_summary_or_answer(): void {
		$html = <<<HTML
<details class="lafka-contact__faq-item">
	<summary class="lafka-contact__faq-q">Has a question but no answer</summary>
</details>
<details class="lafka-contact__faq-item">
	<div class="lafka-contact__faq-a">Has an answer but no summary</div>
</details>
<details class="lafka-contact__faq-item">
	<summary class="lafka-contact__faq-q">Complete</summary>
	<div class="lafka-contact__faq-a">Yes.</div>
</details>
HTML;
		Functions\when( 'has_blocks' )->justReturn( false );

		$items = \lafka_schema_faq_items_from_content( $html );
		$this->assertCount( 1, $items );
		$this->assertSame( 'Complete', $items[0]['q'] );
	}

	// ────────────────────────────────────────────────────────────────────
	// 5. Sitemap filter
	// ────────────────────────────────────────────────────────────────────

	public function test_sitemap_excluded_slug_list_contains_canonical_set(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );
		$slugs = \lafka_sitemap_excluded_slugs();
		foreach ( array( 'cart', 'checkout', 'my-account', 'order-received', 'order-pay' ) as $expected ) {
			$this->assertContains( $expected, $slugs, "Sitemap exclusion list must contain '{$expected}'." );
		}
	}

	public function test_sitemap_filter_excludes_cart_checkout_account_pages(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'get_page_by_path' )->alias( static function ( $slug ) {
			$map = array(
				'cart'           => 11,
				'checkout'       => 12,
				'my-account'     => 13,
				'order-received' => 14,
				'order-pay'      => 15,
			);
			if ( ! isset( $map[ $slug ] ) ) {
				return null;
			}
			$post     = new \stdClass();
			$post->ID = $map[ $slug ];
			// Wrap in WP_Post if available; otherwise the filter accepts stdClass
			// via duck-typing in PHPUnit context — but our resolver uses
			// instanceof WP_Post. We stub WP_Post via a minimal class.
			return new \WP_Post( $post );
		} );

		$args = \lafka_sitemap_filter_page_args( array(), 'page' );
		$this->assertArrayHasKey( 'post__not_in', $args );
		$this->assertEqualsCanonicalizing(
			array( 11, 12, 13, 14, 15 ),
			$args['post__not_in'],
			'Sitemap page-sub args must contain every funnel page ID.'
		);
	}

	public function test_sitemap_filter_passes_through_non_page_post_types(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'get_page_by_path' )->justReturn( null );

		$args = array( 'post_status' => 'publish' );
		$out  = \lafka_sitemap_filter_page_args( $args, 'product' );
		$this->assertSame( $args, $out, 'Non-page post types must be left alone.' );
	}

	public function test_sitemap_filter_merges_with_existing_post_not_in(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'get_page_by_path' )->alias( static function ( $slug ) {
			if ( 'cart' !== $slug ) {
				return null;
			}
			$post     = new \stdClass();
			$post->ID = 99;
			return new \WP_Post( $post );
		} );

		$args = \lafka_sitemap_filter_page_args( array( 'post__not_in' => array( 5, 6 ) ), 'page' );
		$this->assertContains( 99, $args['post__not_in'] );
		$this->assertContains( 5, $args['post__not_in'] );
		$this->assertContains( 6, $args['post__not_in'] );
	}

	public function test_sitemap_filter_is_registered_in_source(): void {
		$src = (string) file_get_contents( $this->plugin_root() . '/incl/seo/lafka-sitemap.php' );
		$this->assertMatchesRegularExpression(
			"/add_filter\(\s*'wp_sitemaps_posts_query_args'/",
			$src,
			'Sitemap module must register the wp_sitemaps_posts_query_args filter.'
		);
	}

	// ────────────────────────────────────────────────────────────────────
	// 6. robots.txt filter
	// ────────────────────────────────────────────────────────────────────

	public function test_robots_filter_appends_all_required_disallow_directives(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );
		$default = "User-agent: *\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\n";
		$out     = \lafka_robots_filter( $default, 1 );

		foreach ( array(
			'Disallow: /cart/',
			'Disallow: /checkout/',
			'Disallow: /my-account/',
			'Disallow: /?add-to-cart=',
			'Disallow: /?wc-ajax=',
			'Disallow: /?orderby=',
			'Disallow: /?min_price=',
			'Disallow: /?max_price=',
		) as $directive ) {
			$this->assertStringContainsString( $directive, $out, "robots.txt must contain '{$directive}'." );
		}
	}

	public function test_robots_filter_preserves_default_wp_admin_ajax_allow(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );
		$default = "User-agent: *\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\n";
		$out     = \lafka_robots_filter( $default, 1 );

		$this->assertStringContainsString( 'Allow: /wp-admin/admin-ajax.php', $out );
		$this->assertStringContainsString( 'Disallow: /wp-admin/', $out );
	}

	public function test_robots_filter_no_ops_when_site_is_private(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );
		$default = "User-agent: *\nDisallow: /\n";
		$out     = \lafka_robots_filter( $default, 0 );

		$this->assertSame( $default, $out, 'Filter must not alter robots.txt when search engines are discouraged.' );
	}

	public function test_robots_filter_is_idempotent(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );
		$default = "User-agent: *\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\n";
		$once    = \lafka_robots_filter( $default, 1 );
		$twice   = \lafka_robots_filter( $once, 1 );

		$this->assertSame( $once, $twice, 'Running the filter on already-filtered output must not duplicate directives.' );
	}

	public function test_robots_filter_output_is_newline_terminated(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );
		$out = \lafka_robots_filter( "User-agent: *\n", 1 );
		$this->assertStringEndsWith( "\n", $out );
		$this->assertDoesNotMatchRegularExpression( '/\n{3,}/', $out, 'Output must not contain triple-newlines.' );
	}

	public function test_robots_filter_is_registered_in_source(): void {
		$src = (string) file_get_contents( $this->plugin_root() . '/incl/seo/lafka-robots.php' );
		$this->assertMatchesRegularExpression(
			"/add_filter\(\s*'robots_txt'/",
			$src,
			'Robots module must register the robots_txt filter.'
		);
	}

	// ────────────────────────────────────────────────────────────────────
	// 7. Local SEO guide doc presence + content sanity
	// ────────────────────────────────────────────────────────────────────

	public function test_local_seo_guide_doc_exists(): void {
		$path = dirname( __DIR__, 3 ) . '/LAFKA_LOCAL_SEO_GUIDE.md';
		$this->assertFileExists( $path, 'LAFKA_LOCAL_SEO_GUIDE.md must be present at the lafka/ parent directory.' );
	}

	public function test_local_seo_guide_covers_required_sections(): void {
		$path = dirname( __DIR__, 3 ) . '/LAFKA_LOCAL_SEO_GUIDE.md';
		$body = (string) file_get_contents( $path );
		foreach ( array(
			'Google Business Profile',
			'Local citations',
			'Schema',
			'Review collection',
			'Page speed',
			'Local content',
			'Tracking SEO performance',
		) as $heading ) {
			$this->assertStringContainsString(
				$heading,
				$body,
				"Local SEO guide must cover '{$heading}'."
			);
		}
	}
}

/**
 * Minimal stand-in for WP's wp_json_encode() used inside the test only.
 * Brain Monkey would otherwise complain about unstubbed calls inside helper.
 */
if ( ! function_exists( 'LafkaPlugin\\Tests\\Unit\\wp_json_encode_compat' ) ) {
	function wp_json_encode_compat( $data ): string {
		return (string) json_encode(
			$data,
			JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
		);
	}
}
