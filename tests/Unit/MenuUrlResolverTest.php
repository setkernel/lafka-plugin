<?php
/**
 * MenuUrlResolverTest — exercises `lafka_get_menu_url()`, the canonical
 * "browse the menu" resolver (f104). Guarantees:
 *
 *   - the default browse target is the trailing-slashed /menu/ page;
 *   - the long-standing `lafka_header_cta_url` filter can repoint it; and
 *   - `lafka_get_restaurant_info()['menu_url']` (which feeds the JSON-LD
 *     Restaurant `hasMenu` link) can never diverge from the value the on-page
 *     CTAs render, because both read the SAME resolver.
 *
 * @package Lafka\Plugin\Tests\Unit
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

require_once dirname( __DIR__, 2 ) . '/incl/schema/lafka-schema-helpers.php';

final class MenuUrlResolverTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'home_url' )->alias(
			static fn( $path = '' ) => 'https://example.test' . $path
		);
		Functions\when( 'trailingslashit' )->alias(
			static fn( $url ) => rtrim( (string) $url, '/' ) . '/'
		);
		// Default behaviour: filters pass the value (arg 2) through untouched.
		Functions\when( 'apply_filters' )->returnArg( 2 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_default_target_is_the_trailing_slashed_menu_page(): void {
		$this->assertSame( 'https://example.test/menu/', \lafka_get_menu_url() );
	}

	public function test_value_always_ends_in_a_single_trailing_slash(): void {
		$url = \lafka_get_menu_url();
		$this->assertNotSame( '', $url );
		$this->assertSame( '/', substr( $url, -1 ), 'menu URL must end in a trailing slash' );
		$this->assertStringEndsNotWith( '//', $url, 'menu URL must not double the trailing slash' );
	}

	public function test_header_cta_filter_can_repoint_the_target(): void {
		// Simulate an operator who repoints the global "Order now" button to a
		// custom slug or an external ordering platform — every menu CTA AND the
		// JSON-LD Menu links must follow it in lockstep.
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value ) {
				return 'lafka_header_cta_url' === $hook ? 'https://order.example.test/now/' : $value;
			}
		);
		$this->assertSame( 'https://order.example.test/now/', \lafka_get_menu_url() );
	}

	public function test_restaurant_info_menu_url_cannot_diverge_from_the_resolver(): void {
		// lafka_get_restaurant_info()['menu_url'] (JSON-LD hasMenu source) must
		// equal lafka_get_menu_url() (the on-page CTA source) — the whole point
		// of routing both through one helper.
		Functions\when( 'get_bloginfo' )->justReturn( '' );
		Functions\when( 'get_site_icon_url' )->justReturn( '' );
		Functions\when( 'get_option' )->justReturn( '' );
		Functions\when( 'get_theme_mod' )->returnArg( 2 );

		$info = \lafka_get_restaurant_info();

		$this->assertSame( \lafka_get_menu_url(), $info['menu_url'] );
		$this->assertSame( 'https://example.test/menu/', $info['menu_url'] );
	}

	public function test_restaurant_info_menu_url_follows_the_header_cta_filter(): void {
		// When the operator repoints the header CTA, the resolved info array's
		// menu_url (hence JSON-LD hasMenu) tracks the override too.
		Functions\when( 'get_bloginfo' )->justReturn( '' );
		Functions\when( 'get_site_icon_url' )->justReturn( '' );
		Functions\when( 'get_option' )->justReturn( '' );
		Functions\when( 'get_theme_mod' )->returnArg( 2 );
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value ) {
				return 'lafka_header_cta_url' === $hook ? 'https://order.example.test/now/' : $value;
			}
		);

		$info = \lafka_get_restaurant_info();

		$this->assertSame( 'https://order.example.test/now/', $info['menu_url'] );
	}

	/**
	 * SSOT regression lock (audit #97): the /menu/ browse route has exactly ONE
	 * source of truth — lafka_get_menu_url(). Every plugin call site must route
	 * through it, so the only place a bare home_url('/menu/') literal may appear
	 * is the resolver's own definition. Any other occurrence means a call site
	 * drifted back to hardcoding the route and can silently diverge from the
	 * filter-repointed CTA.
	 */
	public function test_no_bare_menu_url_literal_outside_the_resolver(): void {
		$root     = dirname( __DIR__, 2 );
		$resolver = 'incl/schema/lafka-schema-helpers.php';
		// Matches home_url('/menu/'), home_url( "/menu/" ) and spacing variants.
		$pattern  = '#home_url\(\s*[\'"]/menu/[\'"]\s*\)#';

		$violations   = array();
		$resolver_hit = 0;
		foreach ( $this->collect_php_files( $root ) as $file ) {
			$rel      = substr( $file, strlen( $root ) + 1 );
			$contents = (string) file_get_contents( $file );
			$count    = preg_match_all( $pattern, $contents );
			if ( $resolver === $rel ) {
				$resolver_hit = (int) $count;
				continue; // The resolver's own definition is the ONE allowed literal.
			}
			if ( $count > 0 ) {
				$violations[] = $rel;
			}
		}

		$this->assertSame(
			array(),
			$violations,
			"Bare home_url('/menu/') literal(s) must be replaced with lafka_get_menu_url():\n  " . implode( "\n  ", $violations )
		);
		$this->assertSame(
			1,
			$resolver_hit,
			'The canonical home_url( \'/menu/\' ) literal must live exactly once, in lafka_get_menu_url().'
		);
	}

	/**
	 * Collect PHP source files under lafka-plugin/incl/ plus the main plugin
	 * file. Excludes tests/ and vendor/ (by only walking incl/) and the main
	 * plugin bootstrap only.
	 *
	 * @return list<string>
	 */
	private function collect_php_files( string $root ): array {
		$out  = array();
		$incl = $root . '/incl';
		$main = $root . '/lafka-plugin.php';

		if ( file_exists( $main ) ) {
			$out[] = $main;
		}

		if ( is_dir( $incl ) ) {
			$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $incl, RecursiveDirectoryIterator::SKIP_DOTS ) );
			foreach ( $it as $f ) {
				if ( ! $f->isFile() ) {
					continue;
				}
				$path = $f->getPathname();
				if ( 'php' === strtolower( (string) pathinfo( $path, PATHINFO_EXTENSION ) ) ) {
					$out[] = $path;
				}
			}
		}
		return $out;
	}
}
