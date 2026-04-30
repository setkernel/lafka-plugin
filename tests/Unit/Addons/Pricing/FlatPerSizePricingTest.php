<?php
declare(strict_types=1);
namespace LafkaPlugin\Tests\Unit\Addons\Pricing;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Lafka_Addon_Group;
use Lafka_Addon_Schema;
use Lafka_Flat_Per_Size_Pricing;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 4 ) . '/incl/addons/engine/lafka-addons-engine-bootstrap.php';

final class FlatPerSizePricingTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'wp_generate_uuid4' )->justReturn( 'test-uuid-0000' );
		Functions\when( 'wc_attribute_taxonomy_name_by_id' )->alias(
			static fn( $id ) => 1 === (int) $id ? 'pa_size' : ''
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_id_and_label(): void {
		$strategy = new Lafka_Flat_Per_Size_Pricing();
		self::assertSame( Lafka_Addon_Schema::PRICING_FLAT_PER_SIZE, $strategy->id() );
		self::assertNotEmpty( $strategy->label() );
	}

	public function test_expand_writes_size_matrix_to_every_option(): void {
		$group = Lafka_Addon_Group::from_array( array(
			'name'              => 'Toppings',
			'pricing_mode'      => Lafka_Addon_Schema::PRICING_FLAT_PER_SIZE,
			'variations'        => 1,
			'attribute'         => 1,
			'group_size_prices' => array(
				'small'  => '0.50',
				'medium' => '1.00',
				'large'  => '1.50',
			),
			'options' => array(
				array( 'label' => 'Cheese' ),
				array( 'label' => 'Mushroom' ),
			),
		) );
		$strategy = new Lafka_Flat_Per_Size_Pricing();
		$expanded = $strategy->expand( $group );

		$expected = array( 'pa_size' => array( 'small' => '0.50', 'medium' => '1.00', 'large' => '1.50' ) );
		self::assertSame( $expected, $expanded->options[0]->price );
		self::assertSame( $expected, $expanded->options[1]->price );
	}

	public function test_expand_returns_group_unchanged_if_taxonomy_unresolvable(): void {
		$group = Lafka_Addon_Group::from_array( array(
			'name'              => 'Toppings',
			'pricing_mode'      => Lafka_Addon_Schema::PRICING_FLAT_PER_SIZE,
			'variations'        => 1,
			'attribute'         => 999, // not 1 — taxonomy resolver returns ''
			'group_size_prices' => array( 'medium' => '1.00' ),
			'options'           => array( array( 'label' => 'X', 'price' => 'untouched' ) ),
		) );
		$strategy = new Lafka_Flat_Per_Size_Pricing();
		$expanded = $strategy->expand( $group );

		self::assertSame( 'untouched', $expanded->options[0]->price );
	}

	public function test_validate_requires_at_least_one_size_price(): void {
		$invalid = Lafka_Addon_Group::from_array( array(
			'name'              => 'G',
			'pricing_mode'      => Lafka_Addon_Schema::PRICING_FLAT_PER_SIZE,
			'variations'        => 1,
			'attribute'         => 1,
			'group_size_prices' => array(),
			'options'           => array( array( 'label' => 'X' ) ),
		) );
		$valid = Lafka_Addon_Group::from_array( array(
			'name'              => 'G',
			'pricing_mode'      => Lafka_Addon_Schema::PRICING_FLAT_PER_SIZE,
			'variations'        => 1,
			'attribute'         => 1,
			'group_size_prices' => array( 'medium' => '1.00' ),
			'options'           => array( array( 'label' => 'X' ) ),
		) );

		$strategy = new Lafka_Flat_Per_Size_Pricing();
		self::assertNotEmpty( $strategy->validate( $invalid ) );
		self::assertEmpty( $strategy->validate( $valid ) );
	}

	public function test_validate_requires_variations_enabled(): void {
		$without_variations = Lafka_Addon_Group::from_array( array(
			'name'              => 'G',
			'pricing_mode'      => Lafka_Addon_Schema::PRICING_FLAT_PER_SIZE,
			'variations'        => 0,
			'attribute'         => 1,
			'group_size_prices' => array( 'medium' => '1.00' ),
			'options'           => array( array( 'label' => 'X' ) ),
		) );

		$strategy = new Lafka_Flat_Per_Size_Pricing();
		$errors = $strategy->validate( $without_variations );
		self::assertNotEmpty( $errors );
	}
}
