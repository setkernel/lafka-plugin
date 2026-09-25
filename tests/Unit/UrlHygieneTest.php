<?php
/**
 * URL hygiene (incl/seo/lafka-url-hygiene.php):
 *
 *   - T-07: once WooCommerce products are the menu, the legacy
 *     `lafka-foodmenu` singles, archive and category archives 301 to the
 *     menu page (else the shop page, else home) — filterable target and
 *     opt-out; nothing happens without WooCommerce or on a preview;
 *   - T-36: WordPress' "guess the permalink" 404 redirect (which sent
 *     /pa_size/large/ to an unrelated combo product) is off by default,
 *     filterable back on.
 *
 * @package Lafka\Plugin\Tests\Unit
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class UrlHygieneTest extends TestCase {

	/** @var list<array{0:string, 1:mixed, 2:int, 3:int}>|null */
	private static ?array $registrations = null;

	/** @var array<string, mixed> */
	private array $is = array();

	/** @var array<string, mixed> */
	private array $filters = array();

	private $menu_page = null;

	private int $shop_id = 0;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->is        = array();
		$this->filters   = array( 'lafka_seo_legacy_post_types' => array( 'lafka-foodmenu' ) );
		$this->menu_page = (object) array( 'ID' => 3, 'post_name' => 'menu', 'post_status' => 'publish' );
		$this->shop_id   = 5;

		Functions\when( 'apply_filters' )->alias(
			fn( $hook, $value ) => array_key_exists( $hook, $this->filters ) ? $this->filters[ $hook ] : $value
		);
		Functions\when( 'is_admin' )->alias( fn() => ! empty( $this->is['admin'] ) );
		Functions\when( 'is_preview' )->alias( fn() => ! empty( $this->is['preview'] ) );
		Functions\when( 'is_singular' )->alias( fn( $type = '' ) => ( $this->is['singular'] ?? '' ) === $type );
		Functions\when( 'is_post_type_archive' )->alias( fn( $type = '' ) => ( $this->is['archive'] ?? '' ) === $type );
		Functions\when( 'is_tax' )->alias( fn( $tax = '' ) => ( $this->is['tax'] ?? '' ) === $tax );
		Functions\when( 'get_page_by_path' )->alias( fn() => $this->menu_page );
		Functions\when( 'wc_get_page_id' )->alias( fn() => $this->shop_id );
		Functions\when( 'get_permalink' )->alias( static fn( $post ) => 'https://example.test/' . ( is_object( $post ) ? $post->post_name : 'order' ) . '/' );
		Functions\when( 'home_url' )->alias( static fn( $path = '' ) => 'https://example.test' . $path );
		Functions\when( 'get_taxonomies' )->justReturn( array() );

		require_once dirname( __DIR__, 2 ) . '/incl/seo/lafka-sitemap.php';
		if ( null === self::$registrations ) {
			$before = count( $GLOBALS['lafka_test_hooks'] );
			require_once dirname( __DIR__, 2 ) . '/incl/seo/lafka-url-hygiene.php';
			self::$registrations = array_slice( $GLOBALS['lafka_test_hooks'], $before );
		}
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_hooks(): void {
		$tags = array_map( static fn( $r ) => $r[0] . ' -> ' . $r[1] . ' @' . $r[2], self::$registrations );
		self::assertContains( 'template_redirect -> lafka_legacy_foodmenu_redirect @1', $tags );
		self::assertContains( 'do_redirect_guess_404_permalink -> lafka_disable_404_guess_redirect @10', $tags );
	}

	public function test_legacy_foodmenu_urls_go_to_the_menu_page(): void {
		foreach ( array( array( 'singular' => 'lafka-foodmenu' ), array( 'archive' => 'lafka-foodmenu' ), array( 'tax' => 'lafka_foodmenu_category' ) ) as $context ) {
			$this->is = $context;
			self::assertSame( 'https://example.test/menu/', lafka_legacy_foodmenu_redirect_url(), (string) key( $context ) );
		}
	}

	public function test_target_falls_back_to_the_shop_then_home_and_is_filterable(): void {
		$this->is        = array( 'singular' => 'lafka-foodmenu' );
		$this->menu_page = (object) array( 'ID' => 3, 'post_name' => 'menu', 'post_status' => 'draft' );
		self::assertSame( 'https://example.test/order/', lafka_legacy_foodmenu_redirect_url() );

		$this->menu_page = null;
		$this->shop_id   = -1;
		self::assertSame( 'https://example.test/', lafka_legacy_foodmenu_redirect_url() );

		$this->filters['lafka_legacy_foodmenu_redirect_target'] = 'https://example.test/our-food/';
		self::assertSame( 'https://example.test/our-food/', lafka_legacy_foodmenu_redirect_url() );
	}

	public function test_no_redirect_elsewhere_without_woocommerce_on_previews_or_when_opted_out(): void {
		$this->is = array( 'singular' => 'page' );
		self::assertSame( '', lafka_legacy_foodmenu_redirect_url(), 'other content' );

		$this->is = array( 'singular' => 'lafka-foodmenu', 'preview' => true );
		self::assertSame( '', lafka_legacy_foodmenu_redirect_url(), 'preview' );

		$this->is = array( 'singular' => 'lafka-foodmenu' );
		$this->filters['lafka_seo_legacy_post_types'] = array();
		self::assertSame( '', lafka_legacy_foodmenu_redirect_url(), 'CPT still the menu (no WooCommerce)' );

		$this->filters = array(
			'lafka_seo_legacy_post_types'   => array( 'lafka-foodmenu' ),
			'lafka_legacy_foodmenu_redirect' => false,
		);
		self::assertSame( '', lafka_legacy_foodmenu_redirect_url(), 'opt-out' );
	}

	public function test_404_permalink_guessing_is_off_by_default_and_filterable(): void {
		self::assertFalse( lafka_disable_404_guess_redirect( true ) );

		$this->filters['lafka_disable_404_guess_redirect'] = false;
		self::assertTrue( lafka_disable_404_guess_redirect( true ) );
		self::assertFalse( lafka_disable_404_guess_redirect( false ), 'Never switches it on.' );
	}
}
