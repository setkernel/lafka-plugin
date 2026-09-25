<?php
/**
 * O-22: several choices from one add-on group show as ONE line
 * ("Toppings: Bacon, Olives"), never as a nameless follow-up line that
 * WooCommerce renders as an orphan ":" label.
 *
 * @package Lafka\Plugin\Tests\Unit
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Lafka_Engine_Cart;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/incl/addons/engine/lafka-addons-engine-bootstrap.php';

final class AddonCartItemDataTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'apply_filters' )->returnArg( 2 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private static function item( array $addons ): array {
		return array(
			'addons' => $addons,
			'data'   => null,
		);
	}

	private static function addon( string $name, string $value, string $display = '' ): array {
		return array(
			'name'    => $name,
			'value'   => $value,
			'price'   => 0,
			'display' => $display,
		);
	}

	public function test_a_repeated_group_joins_its_first_line(): void {
		$data = ( new Lafka_Engine_Cart() )->get_item_data(
			array(),
			self::item(
				array(
					self::addon( 'Toppings', 'Bacon' ),
					self::addon( 'Toppings', 'Olives' ),
					self::addon( 'Toppings', 'Onions' ),
					self::addon( 'Crust', 'Thin' ),
				)
			)
		);

		$this->assertSame(
			array(
				array(
					'name'    => 'Toppings',
					'value'   => 'Bacon, Olives, Onions',
					'display' => '',
				),
				array(
					'name'    => 'Crust',
					'value'   => 'Thin',
					'display' => '',
				),
			),
			$data
		);
		foreach ( $data as $line ) {
			$this->assertNotSame( '', $line['name'], 'No nameless line (the orphan ":").' );
		}
	}

	public function test_display_text_is_joined_too_and_earlier_item_data_is_kept(): void {
		$data = ( new Lafka_Engine_Cart() )->get_item_data(
			array(
				array(
					'name'  => 'Size',
					'value' => 'Large',
				),
			),
			self::item(
				array(
					self::addon( 'Sauces', 'bbq', 'BBQ' ),
					self::addon( 'Sauces', 'ranch' ),
				)
			)
		);

		$this->assertSame( 'Size', $data[0]['name'] );
		$this->assertSame( 'bbq, ranch', $data[1]['value'] );
		$this->assertSame( 'BBQ, ranch', $data[1]['display'] );
	}
}
