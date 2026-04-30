<?php
declare(strict_types=1);
namespace LafkaPlugin\Tests\Unit\Addons\Pricing;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Lafka_Addon_Group;
use Lafka_Addon_Schema;
use Lafka_Flat_Per_Option_Pricing;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 4 ) . '/incl/addons/engine/lafka-addons-engine-bootstrap.php';

final class FlatPerOptionPricingTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'wp_generate_uuid4' )->justReturn( 'test-uuid-0000' );
		Functions\when( '__' )->returnArg( 1 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_id_and_label(): void {
		$strategy = new Lafka_Flat_Per_Option_Pricing();
		self::assertSame( Lafka_Addon_Schema::PRICING_FLAT_PER_OPTION, $strategy->id() );
		self::assertNotEmpty( $strategy->label() );
	}

	public function test_expand_is_passthrough_for_already_scalar_prices(): void {
		$group = Lafka_Addon_Group::from_array( array(
			'name'         => 'Toppings',
			'pricing_mode' => Lafka_Addon_Schema::PRICING_FLAT_PER_OPTION,
			'options'      => array(
				array( 'label' => 'Cheese', 'price' => '1.00' ),
				array( 'label' => 'Truffle', 'price' => '3.00' ),
			),
		) );
		$strategy = new Lafka_Flat_Per_Option_Pricing();
		$expanded = $strategy->expand( $group );

		self::assertSame( '1.00', $expanded->options[0]->price );
		self::assertSame( '3.00', $expanded->options[1]->price );
	}

	public function test_validate_passes_when_all_options_have_a_price(): void {
		$group = Lafka_Addon_Group::from_array( array(
			'name'         => 'G',
			'pricing_mode' => Lafka_Addon_Schema::PRICING_FLAT_PER_OPTION,
			'options'      => array(
				array( 'label' => 'A', 'price' => '1.00' ),
				array( 'label' => 'B', 'price' => '0' ),
			),
		) );
		$strategy = new Lafka_Flat_Per_Option_Pricing();
		self::assertEmpty( $strategy->validate( $group ) );
	}

	public function test_validate_skips_excluded_options(): void {
		$group = Lafka_Addon_Group::from_array( array(
			'name'         => 'G',
			'pricing_mode' => Lafka_Addon_Schema::PRICING_FLAT_PER_OPTION,
			'options'      => array(
				array( 'label' => 'Included', 'price' => '1.00', 'included' => true ),
				array( 'label' => 'Excluded', 'price' => '', 'included' => false ),
			),
		) );
		$strategy = new Lafka_Flat_Per_Option_Pricing();
		self::assertEmpty( $strategy->validate( $group ) );
	}
}
