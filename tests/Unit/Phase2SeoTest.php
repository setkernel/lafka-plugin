<?php
/**
 * FAQPage schema (contact-page gate, theme_mod + page-content sources),
 * sitemap exclusions and the robots.txt additions.
 *
 * @package Lafka\Plugin\Tests\Unit
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;
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
		// GX3: lafka_schema_faq() also serves product-category FAQs; these
		// tests are about pages, never a category archive.
		Functions\when( 'is_product_category' )->justReturn( false );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ────────────────────────────────────────────────────────────────────
	// FAQPage schema — contact-page gate
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

	// ────────────────────────────────────────────────────────────────────
	// FAQPage schema — output shape
	// ────────────────────────────────────────────────────────────────────

	/**
	 * @return array<string, array{0:string, 1:string}>
	 */
	public static function contact_pages(): array {
		return array(
			'contact slug'     => array( 'contact', '' ),
			'contact-us slug'  => array( 'contact-us', '' ),
			'contact template' => array( 'reach-us', 'template-contact.php' ),
		);
	}

	#[DataProvider( 'contact_pages' )]
	public function test_faq_emits_on_contact_pages( string $slug, string $template ): void {
		$this->stub_contact_page( $slug );
		Functions\when( 'is_page_template' )->alias( static fn( $tpl ) => '' !== $template && $tpl === $template );
		Functions\when( 'get_theme_mod' )->alias(
			static fn( $key, $default = '' ) => array(
				'lafka_contact_faq_1_q' => 'Q1?',
				'lafka_contact_faq_1_a' => 'A1.',
			)[ $key ] ?? $default
		);

		$this->assertSame( 'FAQPage', \lafka_schema_faq()['@type'] ?? null );
	}

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

	// ────────────────────────────────────────────────────────────────────
	// FAQPage — content parser
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
	// Sitemap filter
	// ────────────────────────────────────────────────────────────────────

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

	public function test_sitemap_drops_users_provider(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 ); // lafka_sitemap_keep_users default false
		$this->assertFalse(
			\lafka_sitemap_drop_users_provider( 'fake-provider', 'users' ),
			'Users sitemap provider must be removed (no thin author archives / enumeration).'
		);
		$this->assertSame(
			'fake-provider',
			\lafka_sitemap_drop_users_provider( 'fake-provider', 'posts' ),
			'Posts/taxonomies providers must pass through unchanged.'
		);
	}

	// ────────────────────────────────────────────────────────────────────
	// robots.txt filter
	// ────────────────────────────────────────────────────────────────────

	public function test_robots_filter_appends_all_required_disallow_directives(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );
		$default = "User-agent: *\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\n";
		$out     = \lafka_robots_filter( $default, 1 );

		foreach ( array(
			'Disallow: /cart/',
			'Disallow: /checkout/',
			'Disallow: /*?*add-to-cart=',
			'Disallow: /*?*wc-ajax=',
			'Disallow: /*?*orderby=',
			'Disallow: /*?*min_price=',
			'Disallow: /*?*max_price=',
			'Disallow: /*?*filter_',
			'Disallow: /*?*rating_filter=',
		) as $directive ) {
			$this->assertStringContainsString( $directive . "\n", $out, "robots.txt must contain '{$directive}'." );
		}
		// T-29: the account area is noindexed instead (a robots block would hide the noindex).
		$this->assertStringNotContainsString( 'Disallow: /my-account/', $out );
	}

	/**
	 * T-29: WordPress core appends "\nSitemap: …" before this filter runs, so
	 * appending rules after it left them outside any User-agent group. Every
	 * rule now sits inside the single `User-agent: *` group, and the Sitemap
	 * line(s) close the file after one blank line.
	 */
	public function test_robots_rules_stay_in_one_user_agent_group_before_the_sitemap(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );
		$core = "User-agent: *\nDisallow: /*?add-to-cart=\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\n\nSitemap: https://example.test/wp-sitemap.xml\n";
		$out  = \lafka_robots_filter( $core, 1 );

		$groups = preg_split( '/\n\s*\n/', trim( $out ) );
		$this->assertCount( 2, $groups, $out );
		$this->assertStringStartsWith( "User-agent: *\nDisallow: /*?add-to-cart=\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\nDisallow: /cart/", $groups[0] );
		$this->assertStringNotContainsString( 'Sitemap:', $groups[0] );
		$this->assertSame( 'Sitemap: https://example.test/wp-sitemap.xml', $groups[1] );
		$this->assertSame( $out, \lafka_robots_filter( $out, 1 ), 'Idempotent with a sitemap line too.' );
	}

	public function test_robots_rules_get_a_group_when_the_body_has_none(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );
		$out = \lafka_robots_filter( "Sitemap: https://example.test/s.xml\n", 1 );
		$this->assertStringStartsWith( "User-agent: *\nDisallow: /cart/", $out );
		$this->assertStringEndsWith( "\n\nSitemap: https://example.test/s.xml\n", $out );
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

	public function test_robots_rules_stay_inside_the_user_agent_group_before_the_sitemap(): void {
		Functions\when( 'apply_filters' )->returnArg( 2 );
		// WP core's sitemap filter (priority 0) has already appended its line.
		$default = "User-agent: *\nDisallow: /wp-admin/\nAllow: /wp-admin/admin-ajax.php\n\nSitemap: https://example.test/wp-sitemap.xml\n";
		$out     = \lafka_robots_filter( $default, 1 );

		$this->assertLessThan( strpos( $out, "\n\nSitemap:" ), strpos( $out, 'Disallow: /cart/' ), 'Disallow rules must precede the blank line + Sitemap.' );
		$this->assertStringEndsWith( "Sitemap: https://example.test/wp-sitemap.xml\n", $out );
		$this->assertSame( $out, \lafka_robots_filter( $out, 1 ), 'idempotent' );
	}
}
