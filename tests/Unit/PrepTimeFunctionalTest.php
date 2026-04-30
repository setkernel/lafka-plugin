<?php
/**
 * PrepTimeFunctionalTest — exercises the actual prep-time resolution logic
 * (per-category Customizer overrides, store-open window, default fallback)
 * rather than just source-grep locks.
 *
 * The "Ready in X min" trust signal on every PDP reads from this code; if the
 * category-override walk regresses, every product silently falls back to the
 * default and operators lose their per-category tuning without any error.
 *
 * @package Lafka\Plugin\Tests\Unit
 * @since   9.7.9
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Stubs/wp-error-stub.php';
require_once dirname( __DIR__, 2 ) . '/incl/woocommerce/lafka-prep-time.php';

final class PrepTimeFunctionalTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ────────────────────────────────────────────────────────────────────────
	// lafka_pdp_get_prep_time — default + per-category override
	// ────────────────────────────────────────────────────────────────────────

	public function test_returns_default_when_no_category_override(): void {
		Functions\when( 'get_theme_mod' )->alias(
			static function ( $key, $default = null ) {
				return 'lafka_pdp_prep_time_default' === $key ? 25 : $default;
			}
		);
		Functions\when( 'wp_get_post_terms' )->justReturn( array( 'pizzas' ) );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'sanitize_key' )->returnArg();

		$this->assertSame( 25, \lafka_pdp_get_prep_time( 42 ) );
	}

	public function test_per_category_override_wins_over_default(): void {
		// Operator-set override for the "wings" category should beat the
		// global default. Pre-fix the resolver walked in term-order and the
		// first matching category won.
		Functions\when( 'get_theme_mod' )->alias(
			static function ( $key, $default = null ) {
				$map = array(
					'lafka_pdp_prep_time_default' => 25,
					'lafka_pdp_prep_time_wings'   => 18,
				);
				return $map[ $key ] ?? $default;
			}
		);
		Functions\when( 'wp_get_post_terms' )->justReturn( array( 'wings' ) );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'sanitize_key' )->returnArg();

		$this->assertSame( 18, \lafka_pdp_get_prep_time( 42 ) );
	}

	public function test_first_matching_category_wins_when_product_in_multiple(): void {
		// Products often live in multiple categories — the resolver walks
		// the term list in order and short-circuits on the first override.
		Functions\when( 'get_theme_mod' )->alias(
			static function ( $key, $default = null ) {
				$map = array(
					'lafka_pdp_prep_time_default'    => 25,
					'lafka_pdp_prep_time_pizzas'     => 30,
					'lafka_pdp_prep_time_specials'   => 35,
				);
				return $map[ $key ] ?? $default;
			}
		);
		Functions\when( 'wp_get_post_terms' )->justReturn( array( 'pizzas', 'specials' ) );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'sanitize_key' )->returnArg();

		$this->assertSame( 30, \lafka_pdp_get_prep_time( 42 ), 'first category match should win, not the longest prep time.' );
	}

	public function test_falls_back_to_default_on_term_lookup_error(): void {
		Functions\when( 'get_theme_mod' )->alias(
			static function ( $key, $default = null ) {
				return 'lafka_pdp_prep_time_default' === $key ? 25 : $default;
			}
		);
		Functions\when( 'wp_get_post_terms' )->justReturn( new \WP_Error_Stub() );
		Functions\when( 'is_wp_error' )->justReturn( true );
		Functions\when( 'sanitize_key' )->returnArg();

		$this->assertSame( 25, \lafka_pdp_get_prep_time( 42 ) );
	}

	public function test_falls_back_to_default_for_uncategorized_products(): void {
		Functions\when( 'get_theme_mod' )->alias(
			static function ( $key, $default = null ) {
				return 'lafka_pdp_prep_time_default' === $key ? 25 : $default;
			}
		);
		Functions\when( 'wp_get_post_terms' )->justReturn( array() );
		Functions\when( 'is_wp_error' )->justReturn( false );

		$this->assertSame( 25, \lafka_pdp_get_prep_time( 42 ) );
	}

	// ────────────────────────────────────────────────────────────────────────
	// lafka_pdp_is_store_open — drives "Closed — order ahead" copy on PDP.
	//
	// Reads via the real `lafka_get_restaurant_info()` resolver (already loaded
	// by other tests, can't be Brain-Monkey'd). We stub the resolver's
	// underlying inputs (`get_theme_mod` for `lafka_business_hours_*`) so the
	// real resolver computes the hours map under test conditions.
	// ────────────────────────────────────────────────────────────────────────

	/**
	 * @param array<string, string> $hours_by_day_key e.g. ['mon' => '11:00-23:00']
	 */
	private function stub_resolver_inputs( array $hours_by_day_key ): void {
		Functions\when( 'get_theme_mod' )->alias(
			static function ( $key, $default = null ) use ( $hours_by_day_key ) {
				if ( str_starts_with( (string) $key, 'lafka_business_hours_' ) ) {
					$day_key = substr( (string) $key, strlen( 'lafka_business_hours_' ) );
					return $hours_by_day_key[ $day_key ] ?? '';
				}
				return $default;
			}
		);
		Functions\when( 'get_option' )->returnArg( 2 );
		Functions\when( 'get_bloginfo' )->justReturn( '' );
		Functions\when( 'get_site_icon_url' )->justReturn( '' );
		Functions\when( 'home_url' )->justReturn( 'http://localhost' );
		Functions\when( 'trailingslashit' )->alias( fn( $url ) => rtrim( $url, '/' ) . '/' );
		Functions\when( 'apply_filters' )->returnArg( 2 );
	}

	public function test_open_when_no_hours_configured(): void {
		$this->stub_resolver_inputs( array() );
		Functions\when( 'wp_date' )->justReturn( '14:00' );

		$this->assertTrue( \lafka_pdp_is_store_open() );
	}

	public function test_closed_when_today_marked_closed(): void {
		$this->stub_resolver_inputs( array( 'mon' => 'closed' ) );
		Functions\when( 'wp_date' )->alias(
			static fn( $fmt ) => 'l' === $fmt ? 'Monday' : '14:00'
		);

		$this->assertFalse( \lafka_pdp_is_store_open() );
	}

	public function test_open_during_today_window(): void {
		$this->stub_resolver_inputs( array( 'mon' => '11:00-23:00' ) );
		Functions\when( 'wp_date' )->alias(
			static fn( $fmt ) => 'l' === $fmt ? 'Monday' : '14:00'
		);

		$this->assertTrue( \lafka_pdp_is_store_open() );
	}

	public function test_closed_before_open_time(): void {
		$this->stub_resolver_inputs( array( 'mon' => '11:00-23:00' ) );
		Functions\when( 'wp_date' )->alias(
			static fn( $fmt ) => 'l' === $fmt ? 'Monday' : '09:00'
		);

		$this->assertFalse( \lafka_pdp_is_store_open() );
	}

	public function test_closed_at_close_time_exactly(): void {
		// Boundary check — close time is exclusive (>= open AND < close), so
		// 23:00 with a window of 11:00-23:00 means closed.
		$this->stub_resolver_inputs( array( 'mon' => '11:00-23:00' ) );
		Functions\when( 'wp_date' )->alias(
			static fn( $fmt ) => 'l' === $fmt ? 'Monday' : '23:00'
		);

		$this->assertFalse( \lafka_pdp_is_store_open() );
	}

	public function test_open_one_minute_before_close(): void {
		$this->stub_resolver_inputs( array( 'mon' => '11:00-23:00' ) );
		Functions\when( 'wp_date' )->alias(
			static fn( $fmt ) => 'l' === $fmt ? 'Monday' : '22:59'
		);

		$this->assertTrue( \lafka_pdp_is_store_open() );
	}
}
