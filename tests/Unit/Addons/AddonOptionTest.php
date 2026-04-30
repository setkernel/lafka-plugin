<?php
declare(strict_types=1);
namespace LafkaPlugin\Tests\Unit\Addons;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Lafka_Addon_Option;
use Lafka_Addon_Schema;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 3 ) . '/incl/addons/engine/lafka-addons-engine-bootstrap.php';

final class AddonOptionTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// Brain Monkey + Patchwork keep wp_generate_uuid4 around once any
		// earlier test stubbed it; provide an expectation here for strict mode.
		Functions\when( 'wp_generate_uuid4' )->justReturn( 'test-uuid-0000' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_from_array_normalizes_against_defaults(): void {
		$option = Lafka_Addon_Option::from_array( array(
			'label' => 'Cheese',
			'price' => '1.50',
		) );

		self::assertSame( 'Cheese', $option->label );
		self::assertSame( '1.50', $option->price );
		self::assertNotEmpty( $option->id );
		self::assertTrue( $option->included );
	}

	public function test_to_array_round_trips(): void {
		$original = Lafka_Addon_Option::from_array( array(
			'id'       => 'fixed-id',
			'label'    => 'Mushroom',
			'price'    => '0.75',
			'default'  => '0',
			'included' => false,
		) );
		$array = $original->to_array();

		self::assertSame( 'fixed-id', $array['id'] );
		self::assertSame( 'Mushroom', $array['label'] );
		self::assertSame( '0.75', $array['price'] );
		self::assertFalse( $array['included'] );
	}

	public function test_unknown_keys_dropped_on_normalization(): void {
		$option = Lafka_Addon_Option::from_array( array(
			'label'        => 'Bacon',
			'rogue_field'  => 'should-be-stripped',
		) );
		$array = $option->to_array();

		self::assertArrayNotHasKey( 'rogue_field', $array );
	}

	public function test_with_price_returns_new_instance_unchanged_original(): void {
		$original = Lafka_Addon_Option::from_array( array( 'label' => 'Cheese', 'price' => '1.00' ) );
		$updated  = $original->with_price( '2.00' );

		self::assertSame( '1.00', $original->price );
		self::assertSame( '2.00', $updated->price );
		self::assertNotSame( $original, $updated );
	}

	public function test_price_can_be_nested_array(): void {
		$option = Lafka_Addon_Option::from_array( array(
			'label' => 'Cheese',
			'price' => array( 'pa_size' => array( 'small' => '1.00', 'large' => '2.00' ) ),
		) );

		self::assertIsArray( $option->price );
		self::assertSame( '2.00', $option->price['pa_size']['large'] );
	}
}
