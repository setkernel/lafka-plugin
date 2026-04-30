<?php
declare(strict_types=1);
namespace LafkaPlugin\Tests\Unit\Addons\Pricing;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Lafka_Addon_Group;
use Lafka_Addon_Schema;
use Lafka_Matrix_Pricing;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 4 ) . '/incl/addons/engine/lafka-addons-engine-bootstrap.php';

final class MatrixPricingTest extends TestCase {

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
		$strategy = new Lafka_Matrix_Pricing();
		self::assertSame( Lafka_Addon_Schema::PRICING_MATRIX, $strategy->id() );
		self::assertNotEmpty( $strategy->label() );
	}

	public function test_expand_is_passthrough_for_already_nested_prices(): void {
		$matrix = array( 'pa_size' => array( 'small' => '0.50', 'medium' => '1.00' ) );
		$group = Lafka_Addon_Group::from_array( array(
			'name'         => 'Toppings',
			'pricing_mode' => Lafka_Addon_Schema::PRICING_MATRIX,
			'variations'   => 1,
			'attribute'    => 1,
			'options'      => array(
				array( 'label' => 'Cheese', 'price' => $matrix ),
			),
		) );
		$strategy = new Lafka_Matrix_Pricing();
		$expanded = $strategy->expand( $group );

		self::assertSame( $matrix, $expanded->options[0]->price );
	}

	public function test_validate_requires_variations_and_attribute(): void {
		$invalid = Lafka_Addon_Group::from_array( array(
			'name'         => 'G',
			'pricing_mode' => Lafka_Addon_Schema::PRICING_MATRIX,
			'variations'   => 0,
			'attribute'    => 0,
			'options'      => array( array( 'label' => 'X' ) ),
		) );
		$valid = Lafka_Addon_Group::from_array( array(
			'name'         => 'G',
			'pricing_mode' => Lafka_Addon_Schema::PRICING_MATRIX,
			'variations'   => 1,
			'attribute'    => 5,
			'options'      => array( array( 'label' => 'X' ) ),
		) );

		$strategy = new Lafka_Matrix_Pricing();
		self::assertNotEmpty( $strategy->validate( $invalid ) );
		self::assertEmpty( $strategy->validate( $valid ) );
	}
}
