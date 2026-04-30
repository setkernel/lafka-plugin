<?php
declare(strict_types=1);
namespace LafkaPlugin\Tests\Unit\Addons\Pricing;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Lafka_Addon_Group;
use Lafka_Addon_Schema;
use Lafka_Flat_Group_Pricing;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 4 ) . '/incl/addons/engine/lafka-addons-engine-bootstrap.php';

final class FlatGroupPricingTest extends TestCase {

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
		$strategy = new Lafka_Flat_Group_Pricing();
		self::assertSame( Lafka_Addon_Schema::PRICING_FLAT_GROUP, $strategy->id() );
		self::assertNotEmpty( $strategy->label() );
	}

	public function test_expand_writes_group_flat_price_to_every_option(): void {
		$group = Lafka_Addon_Group::from_array( array(
			'name'             => 'Toppings',
			'pricing_mode'     => Lafka_Addon_Schema::PRICING_FLAT_GROUP,
			'group_flat_price' => '1.50',
			'options'          => array(
				array( 'label' => 'Cheese', 'price' => '' ),
				array( 'label' => 'Mushroom', 'price' => '' ),
			),
		) );
		$strategy = new Lafka_Flat_Group_Pricing();
		$expanded = $strategy->expand( $group );

		self::assertSame( '1.50', $expanded->options[0]->price );
		self::assertSame( '1.50', $expanded->options[1]->price );
		// Original unchanged.
		self::assertSame( '', $group->options[0]->price );
	}

	public function test_validate_requires_non_empty_price(): void {
		$invalid = Lafka_Addon_Group::from_array( array(
			'name'             => 'G',
			'pricing_mode'     => Lafka_Addon_Schema::PRICING_FLAT_GROUP,
			'group_flat_price' => '',
			'options'          => array( array( 'label' => 'X' ) ),
		) );
		$valid = Lafka_Addon_Group::from_array( array(
			'name'             => 'G',
			'pricing_mode'     => Lafka_Addon_Schema::PRICING_FLAT_GROUP,
			'group_flat_price' => '0.50',
			'options'          => array( array( 'label' => 'X' ) ),
		) );

		$strategy = new Lafka_Flat_Group_Pricing();
		self::assertNotEmpty( $strategy->validate( $invalid ) );
		self::assertEmpty( $strategy->validate( $valid ) );
	}

	public function test_zero_price_is_allowed(): void {
		$group = Lafka_Addon_Group::from_array( array(
			'name'             => 'G',
			'pricing_mode'     => Lafka_Addon_Schema::PRICING_FLAT_GROUP,
			'group_flat_price' => '0',
			'options'          => array( array( 'label' => 'X' ) ),
		) );
		$strategy = new Lafka_Flat_Group_Pricing();
		self::assertEmpty( $strategy->validate( $group ) );
	}
}
