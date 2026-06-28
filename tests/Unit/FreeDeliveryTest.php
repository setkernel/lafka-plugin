<?php
declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

require_once dirname( __DIR__, 2 ) . '/incl/woocommerce/lafka-free-delivery.php';

/**
 * Free-delivery-over-$X: SSOT threshold + eligibility + the package-rates rule.
 * Standalone (not behind the promotions/BOGO gate).
 */
final class FreeDeliveryTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// If Lafka_Promotions is also loaded in this run, the resolver tries its
		// knob path (get_option); stub it empty. apply_filters → passthrough.
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'apply_filters' )->returnArg( 2 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_threshold_defaults_off(): void {
		Functions\when( 'get_theme_mod' )->alias( static fn( $k, $d = false ) => $d );
		self::assertSame( 0.0, lafka_get_free_delivery_threshold() );
	}

	public function test_threshold_from_customizer(): void {
		Functions\when( 'get_theme_mod' )->alias(
			static fn( $k, $d = false ) => 'lafka_pdp_free_delivery_threshold' === $k ? 45 : $d
		);
		self::assertSame( 45.0, lafka_get_free_delivery_threshold() );
	}

	public function test_eligibility_boundary(): void {
		Functions\when( 'get_theme_mod' )->alias(
			static fn( $k, $d = false ) => 'lafka_pdp_free_delivery_threshold' === $k ? 45 : $d
		);
		self::assertFalse( lafka_free_delivery_eligible( 44.99 ) );
		self::assertTrue( lafka_free_delivery_eligible( 45.0 ) ); // boundary qualifies
		self::assertTrue( lafka_free_delivery_eligible( 80.0 ) );
	}

	public function test_off_never_eligible(): void {
		Functions\when( 'get_theme_mod' )->alias( static fn( $k, $d = false ) => $d );
		self::assertFalse( lafka_free_delivery_eligible( 999.0 ) );
	}

	public function test_rule_zeros_delivery_cost_over_threshold(): void {
		Functions\when( 'get_theme_mod' )->alias(
			static fn( $k, $d = false ) => 'lafka_pdp_free_delivery_threshold' === $k ? 45 : $d
		);
		$delivery        = (object) array( 'method_id' => 'distance_rate', 'cost' => 7.5, 'taxes' => array( 1 => 0.97 ) );
		$pickup          = (object) array( 'method_id' => 'local_pickup', 'cost' => 0.0, 'taxes' => array() );
		$rates           = array( 'distance_rate:1' => $delivery, 'local_pickup:2' => $pickup );
		$out             = lafka_free_delivery_apply_rates( $rates, array( 'contents_cost' => 50.0 ) );
		self::assertSame( 0, $out['distance_rate:1']->cost, 'delivery cost should be zeroed' );
		self::assertSame( array( 1 => 0 ), $out['distance_rate:1']->taxes, 'delivery taxes should be zeroed' );
	}

	public function test_rule_leaves_cost_under_threshold(): void {
		Functions\when( 'get_theme_mod' )->alias(
			static fn( $k, $d = false ) => 'lafka_pdp_free_delivery_threshold' === $k ? 45 : $d
		);
		$delivery = (object) array( 'method_id' => 'distance_rate', 'cost' => 7.5, 'taxes' => array() );
		$out      = lafka_free_delivery_apply_rates( array( 'd:1' => $delivery ), array( 'contents_cost' => 30.0 ) );
		self::assertSame( 7.5, $out['d:1']->cost, 'under threshold → unchanged' );
	}

	/**
	 * F010: the storefront "Free over $X" copy and the shipping rule must read
	 * ONE value. The theme partials gate their copy on
	 * `lafka_get_free_delivery_threshold() > 0` and print the resolved amount —
	 * exactly the value the rule enforces. Model that contract here so the two
	 * can never drift apart again.
	 */
	public function test_display_copy_and_enforcement_share_one_value(): void {
		Functions\when( 'get_theme_mod' )->alias(
			static fn( $k, $d = false ) => 'lafka_pdp_free_delivery_threshold' === $k ? 45 : $d
		);

		// What the display partials read for the "Free over $X" promise.
		$display_value = lafka_get_free_delivery_threshold();
		self::assertSame( 45.0, $display_value, 'display reads the SSOT resolver' );

		// The displayed promise (Free over $45) is honoured at the boundary the
		// rule enforces — same number, no divergence.
		self::assertTrue( lafka_free_delivery_eligible( $display_value ), 'enforcement honours the displayed threshold' );
		self::assertFalse( lafka_free_delivery_eligible( $display_value - 0.01 ), 'just under the displayed threshold is not free' );
	}

	/**
	 * F010 core regression: on a fresh / unconfigured (off) install the resolver
	 * returns 0, so the copy gate (`> 0`) is false — NO "Free over $X" promise is
	 * shown — and enforcement is likewise off. Previously the copy defaulted to
	 * $30 while enforcement defaulted to $0, promising something never delivered.
	 */
	public function test_copy_hidden_when_resolver_zero(): void {
		Functions\when( 'get_theme_mod' )->alias( static fn( $k, $d = false ) => $d );

		$threshold = lafka_get_free_delivery_threshold();
		self::assertSame( 0.0, $threshold, 'unconfigured install resolves to off' );

		// Display gate the partials use: copy renders only when threshold > 0.
		$copy_shown = $threshold > 0;
		self::assertFalse( $copy_shown, 'no free-delivery promise on an unconfigured install' );

		// Enforcement is off too — copy and rule are suppressed together.
		self::assertFalse( lafka_free_delivery_eligible( PHP_INT_MAX ), 'enforcement off when resolver returns 0' );
	}

	/**
	 * The operator-facing option (WC → Settings → Restaurant) is the top of the
	 * SSOT chain: when set, BOTH the displayed copy and the enforced rule use it,
	 * regardless of the legacy theme_mods.
	 */
	public function test_option_drives_both_display_and_enforcement(): void {
		Functions\when( 'get_option' )->alias(
			static fn( $k, $d = false ) => 'lafka_free_delivery_threshold' === $k ? 60 : $d
		);
		// Even a stale theme_mod must not win over the option.
		Functions\when( 'get_theme_mod' )->alias(
			static fn( $k, $d = false ) => 'lafka_announce_bar_delivery_threshold' === $k ? 30 : $d
		);

		$display_value = lafka_get_free_delivery_threshold();
		self::assertSame( 60.0, $display_value, 'option is the SSOT for display' );
		self::assertTrue( lafka_free_delivery_eligible( 60.0 ), 'option is the SSOT for enforcement' );
		self::assertFalse( lafka_free_delivery_eligible( 59.99 ) );
	}
}
