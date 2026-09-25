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
// lafka_pdp_is_store_open() reads through the real lafka_get_restaurant_info()
// resolver. Load it here so this test is self-contained — relying on a sibling
// test file to have loaded it first made the store-open assertions pass only in
// full-suite runs and silently no-op (always "open") when run in isolation.
require_once dirname( __DIR__, 2 ) . '/incl/schema/lafka-schema-helpers.php';

final class PrepTimeFunctionalTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->reset_order_hours_static();
	}

	protected function tearDown(): void {
		$this->reset_order_hours_static();
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Clear the Lafka_Order_Hours options static between tests.
	 *
	 * When that class is loaded by a sibling test the resolver's SSOT-
	 * reconciliation branch reads this static; a schedule leaked from another
	 * test would otherwise let lafka_pdp_is_store_open() derive hours the
	 * store-open assertions here did not configure.
	 */
	private function reset_order_hours_static(): void {
		if ( class_exists( '\Lafka_Order_Hours' ) ) {
			\Lafka_Order_Hours::$lafka_order_hours_options               = null;
			\Lafka_Order_Hours::$lafka_order_hours_schedule              = null;
			\Lafka_Order_Hours::$lafka_order_hours_force_override_check  = false;
			\Lafka_Order_Hours::$lafka_order_hours_force_override_status = '';
		}
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
	// Reads via the real `lafka_get_restaurant_info()` resolver (loaded at the
	// top of this file, can't be Brain-Monkey'd). We stub the resolver's
	// underlying inputs (the `lafka_business_hours_*` options — GX3's single
	// NAP store) so the
	// real resolver computes the hours map under test conditions.
	// ────────────────────────────────────────────────────────────────────────

	/**
	 * @param array<string, string> $hours_by_day_key e.g. ['mon' => '11:00-23:00']
	 */
	private function stub_resolver_inputs( array $hours_by_day_key ): void {
		Functions\when( 'get_theme_mod' )->alias( static fn( $key, $default = null ) => $default );
		// Return the passed default for any other get_option() call. The resolver
		// always passes a default (2 args), but the SSOT-reconciliation branch
		// (active only when Lafka_Order_Hours is loaded by a sibling test) calls
		// get_option('lafka_order_hours_options') with ONE argument — a strict
		// returnArg(2) fatals there, so an alias that tolerates both arities is
		// required for the test to behave the same in isolation and full-suite runs.
		Functions\when( 'get_option' )->alias(
			static function ( $option, $default_value = false ) use ( $hours_by_day_key ) {
				if ( str_starts_with( (string) $option, 'lafka_business_hours_' ) ) {
					$day_key = substr( (string) $option, strlen( 'lafka_business_hours_' ) );
					return $hours_by_day_key[ $day_key ] ?? '';
				}
				return $default_value;
			}
		);
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
			static fn( $fmt ) => 'N' === $fmt ? '1' : '14:00'
		);

		$this->assertFalse( \lafka_pdp_is_store_open() );
	}

	public function test_open_during_today_window(): void {
		$this->stub_resolver_inputs( array( 'mon' => '11:00-23:00' ) );
		Functions\when( 'wp_date' )->alias(
			static fn( $fmt ) => 'N' === $fmt ? '1' : '14:00'
		);

		$this->assertTrue( \lafka_pdp_is_store_open() );
	}

	public function test_closed_before_open_time(): void {
		$this->stub_resolver_inputs( array( 'mon' => '11:00-23:00' ) );
		Functions\when( 'wp_date' )->alias(
			static fn( $fmt ) => 'N' === $fmt ? '1' : '09:00'
		);

		$this->assertFalse( \lafka_pdp_is_store_open() );
	}

	public function test_closed_at_close_time_exactly(): void {
		// Boundary check — close time is exclusive (>= open AND < close), so
		// 23:00 with a window of 11:00-23:00 means closed.
		$this->stub_resolver_inputs( array( 'mon' => '11:00-23:00' ) );
		Functions\when( 'wp_date' )->alias(
			static fn( $fmt ) => 'N' === $fmt ? '1' : '23:00'
		);

		$this->assertFalse( \lafka_pdp_is_store_open() );
	}

	public function test_open_one_minute_before_close(): void {
		$this->stub_resolver_inputs( array( 'mon' => '11:00-23:00' ) );
		Functions\when( 'wp_date' )->alias(
			static fn( $fmt ) => 'N' === $fmt ? '1' : '22:59'
		);

		$this->assertTrue( \lafka_pdp_is_store_open() );
	}

	/**
	 * Stub the store clock: ISO day number (1 = Monday … 7 = Sunday) + "H:i".
	 */
	private function clock( int $iso_day, string $hhmm ): void {
		Functions\when( 'wp_date' )->alias(
			static fn( $fmt ) => 'N' === $fmt ? (string) $iso_day : $hhmm
		);
	}

	// ── M-05: a midnight close ("00:00") is the END of the day, not 00:00 ──

	public function test_open_mid_afternoon_when_close_is_midnight(): void {
		// Friday 11:00-00:00 at 15:30 used to string-compare "15:30" < "00:00"
		// (always false) and print "Closed — order ahead" all Friday.
		$this->stub_resolver_inputs( array( 'fri' => '11:00-00:00' ) );
		$this->clock( 5, '15:30' );

		$this->assertTrue( \lafka_pdp_is_store_open() );
	}

	public function test_open_one_minute_before_a_midnight_close(): void {
		$this->stub_resolver_inputs( array( 'sat' => '11:00-00:00' ) );
		$this->clock( 6, '23:59' );

		$this->assertTrue( \lafka_pdp_is_store_open() );
	}

	public function test_midnight_close_does_not_leak_into_the_next_morning(): void {
		// Saturday 11:00-00:00 then Sunday 11:00-22:00: Sunday 00:30 is closed.
		$this->stub_resolver_inputs( array( 'sat' => '11:00-00:00', 'sun' => '11:00-22:00' ) );
		$this->clock( 7, '00:30' );

		$this->assertFalse( \lafka_pdp_is_store_open() );
	}

	public function test_24_00_close_is_accepted_as_end_of_day(): void {
		$this->stub_resolver_inputs( array( 'fri' => '11:00-24:00' ) );
		$this->clock( 5, '22:00' );

		$this->assertTrue( \lafka_pdp_is_store_open() );
	}

	// ── Overnight windows (close before open) run past midnight ──

	public function test_overnight_window_is_open_in_the_evening(): void {
		$this->stub_resolver_inputs( array( 'fri' => '17:00-02:00' ) );
		$this->clock( 5, '23:15' );

		$this->assertTrue( \lafka_pdp_is_store_open() );
	}

	public function test_overnight_window_spills_into_the_next_day(): void {
		// Friday 17:00-02:00 keeps the store open at Saturday 01:00 even
		// though Saturday itself is closed.
		$this->stub_resolver_inputs( array( 'fri' => '17:00-02:00', 'sat' => 'closed' ) );
		$this->clock( 6, '01:00' );

		$this->assertTrue( \lafka_pdp_is_store_open() );
	}

	public function test_overnight_spill_wraps_sunday_into_monday(): void {
		$this->stub_resolver_inputs( array( 'sun' => '17:00-02:00', 'mon' => '11:00-22:00' ) );
		$this->clock( 1, '01:30' );

		$this->assertTrue( \lafka_pdp_is_store_open() );
	}

	public function test_overnight_window_closed_after_the_spill_ends(): void {
		$this->stub_resolver_inputs( array( 'fri' => '17:00-02:00', 'sat' => '17:00-02:00' ) );
		$this->clock( 6, '03:00' );

		$this->assertFalse( \lafka_pdp_is_store_open() );
	}

	public function test_overnight_window_closed_before_it_opens(): void {
		$this->stub_resolver_inputs( array( 'fri' => '17:00-02:00' ) );
		$this->clock( 5, '16:00' );

		$this->assertFalse( \lafka_pdp_is_store_open() );
	}

	public function test_day_is_resolved_by_number_not_translated_name(): void {
		// wp_date( 'l' ) is locale-translated ("vendredi"); the lookup must
		// not depend on it.
		$this->stub_resolver_inputs( array( 'fri' => '11:00-23:00' ) );
		Functions\when( 'wp_date' )->alias(
			static function ( $fmt ) {
				$map = array(
					'N'   => '5',
					'H:i' => '14:00',
					'l'   => 'vendredi',
				);
				return $map[ $fmt ] ?? '';
			}
		);

		$this->assertTrue( \lafka_pdp_is_store_open() );
	}

	// ── One source with the header badge: a configured order gate wins ──

	private function load_order_gate(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'UTC' ) );
		Functions\when( 'WC' )->justReturn( (object) array( 'session' => null ) );
		require_once dirname( __DIR__, 2 ) . '/incl/order-hours/Lafka_Order_Hours.php';
	}

	public function test_configured_order_gate_forced_closed_wins_over_open_hours(): void {
		$this->load_order_gate();
		$this->stub_resolver_inputs( array( 'fri' => '11:00-00:00' ) );
		$this->clock( 5, '15:30' );
		\Lafka_Order_Hours::$lafka_order_hours_force_override_check  = true;
		\Lafka_Order_Hours::$lafka_order_hours_force_override_status = '';

		$this->assertFalse( \lafka_pdp_is_store_open() );
	}

	public function test_configured_order_gate_forced_open_wins_over_closed_hours(): void {
		$this->load_order_gate();
		$this->stub_resolver_inputs( array( 'fri' => 'closed' ) );
		$this->clock( 5, '15:30' );
		\Lafka_Order_Hours::$lafka_order_hours_force_override_check  = true;
		\Lafka_Order_Hours::$lafka_order_hours_force_override_status = '1';

		$this->assertTrue( \lafka_pdp_is_store_open() );
	}

	public function test_unconfigured_order_gate_falls_back_to_hours_map(): void {
		$this->load_order_gate();
		\Lafka_Order_Hours::$lafka_order_hours_schedule = '';
		$this->stub_resolver_inputs( array( 'fri' => 'closed' ) );
		$this->clock( 5, '15:30' );

		$this->assertFalse( \lafka_pdp_is_store_open() );
	}

	// ── The render never pairs "Ready in" with "Closed" ──

	public function test_render_prints_closed_copy_without_ready_in_when_closed(): void {
		$this->stub_resolver_inputs( array( 'fri' => 'closed' ) );
		$this->clock( 5, '15:30' );
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( '__' )->returnArg();

		ob_start();
		\lafka_pdp_render_prep_time( 42 );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'lafka-pdp-trust--closed', $html );
		$this->assertStringNotContainsString( 'Ready in', $html );
	}

	public function test_render_prints_ready_in_on_a_friday_afternoon_with_midnight_close(): void {
		$this->stub_resolver_inputs( array( 'fri' => '11:00-00:00' ) );
		$this->clock( 5, '15:30' );
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_get_post_terms' )->justReturn( array() );
		Functions\when( 'is_wp_error' )->justReturn( false );

		ob_start();
		\lafka_pdp_render_prep_time( 42 );
		$html = (string) ob_get_clean();

		$this->assertStringContainsString( 'lafka-pdp-trust--open', $html );
		$this->assertStringNotContainsString( 'Closed', $html );
	}
}
