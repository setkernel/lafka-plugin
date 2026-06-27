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
}
