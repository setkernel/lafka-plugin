<?php
declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

require_once dirname( __DIR__, 2 ) . '/incl/woocommerce/lafka-combo-deal.php';

/**
 * Combo deal: category-pair matching + discount math.
 */
final class ComboDealTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'apply_filters' )->returnArg( 2 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_amount_fixed_capped_at_subtotal(): void {
		$config = array( 'amount' => 8.0, 'type' => 'fixed' );
		self::assertSame( 8.0, lafka_combo_deal_amount_for( $config, 40.0 ) );
		self::assertSame( 5.0, lafka_combo_deal_amount_for( $config, 5.0 ) ); // never exceeds subtotal
	}

	public function test_amount_percent(): void {
		$config = array( 'amount' => 20.0, 'type' => 'percent' );
		self::assertSame( 8.0, lafka_combo_deal_amount_for( $config, 40.0 ) );
	}

	public function test_config_enabled_only_when_complete(): void {
		Functions\when( 'get_option' )->justReturn( '' );
		Functions\when( 'get_theme_mod' )->alias( static fn( $k, $d = false ) => $d );
		$cfg = lafka_combo_deal_config();
		self::assertFalse( $cfg['enabled'] ); // nothing configured → off
	}
}
