<?php
/**
 * Pickup recognition across modules: both WooCommerce pickup methods — the
 * classic `local_pickup` and the blocks `pickup_location` — count as pickup
 * for the delivery minimum, free delivery and the kitchen display order type.
 *
 * @package Lafka_Plugin
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Lafka_Promotions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class PickupShippingMethodTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'get_option' )->justReturn( array() );
		if ( class_exists( 'Lafka_Promotions' ) ) {
			Lafka_Promotions::flush_knobs(); // No knob values leaked from other tests.
		}
		Functions\when( 'get_theme_mod' )->alias(
			static fn( $k, $d = false ) => 'lafka_pdp_free_delivery_threshold' === $k ? 45 : $d
		);
		require_once dirname( __DIR__, 2 ) . '/incl/lafka-shipping-method-helpers.php';
		require_once dirname( __DIR__, 2 ) . '/incl/woocommerce/lafka-free-delivery.php';
		require_once dirname( __DIR__, 2 ) . '/incl/promotions/class-lafka-promotions.php';
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @return array<string, array{0: string, 1: bool}>
	 */
	public static function methods(): array {
		return array(
			'classic pickup method'   => array( 'local_pickup', true ),
			'classic pickup rate id'  => array( 'local_pickup:3', true ),
			'blocks pickup method'    => array( 'pickup_location', true ),
			'blocks pickup rate id'   => array( 'pickup_location:0', true ),
			'flat rate'               => array( 'flat_rate:1', false ),
			'distance rate'           => array( 'distance_rate', false ),
			'empty'                   => array( '', false ),
		);
	}

	#[DataProvider( 'methods' )]
	public function test_pickup_method_recognition( string $method, bool $expected ): void {
		$this->assertSame( $expected, lafka_is_pickup_shipping_method( $method ) );
	}

	private static function rate( string $method_id, float $cost ): object {
		return (object) array(
			'method_id' => $method_id,
			'cost'      => $cost,
			'taxes'     => array(),
		);
	}

	public function test_delivery_minimum_keeps_both_pickup_methods(): void {
		$rates = array(
			'flat_rate:1'       => self::rate( 'flat_rate', 5.0 ),
			'local_pickup:2'    => self::rate( 'local_pickup', 0.0 ),
			'pickup_location:0' => self::rate( 'pickup_location', 0.0 ),
		);
		$promotions = ( new ReflectionClass( Lafka_Promotions::class ) )->newInstanceWithoutConstructor();

		// Below the (default) delivery minimum: only delivery is withdrawn.
		$out = $promotions->apply_delivery_minimum( $rates, array( 'contents_cost' => 1.0 ) );

		$this->assertSame( array( 'local_pickup:2', 'pickup_location:0' ), array_keys( $out ) );
	}

	public function test_free_delivery_leaves_a_charged_pickup_untouched(): void {
		$rates = array(
			'flat_rate:1'       => self::rate( 'flat_rate', 5.0 ),
			'pickup_location:0' => self::rate( 'pickup_location', 2.0 ),
		);

		$out = lafka_free_delivery_apply_rates( $rates, array( 'contents_cost' => 50.0 ) );

		$this->assertSame( 0, $out['flat_rate:1']->cost );
		$this->assertSame( 2.0, $out['pickup_location:0']->cost );
	}

	/**
	 * @return array<string, array{0: string, 1: string[], 2: string}>
	 */
	public static function orders(): array {
		return array(
			'Lafka order type wins'  => array( 'delivery', array( 'pickup_location' ), 'delivery' ),
			'blocks pickup line'     => array( '', array( 'pickup_location' ), 'pickup' ),
			'classic pickup line'    => array( '', array( 'local_pickup' ), 'pickup' ),
			'delivery line'          => array( '', array( 'flat_rate' ), 'delivery' ),
			'no shipping line'       => array( '', array(), 'pickup' ),
		);
	}

	/**
	 * @param string[] $method_ids Shipping-line method ids.
	 */
	#[DataProvider( 'orders' )]
	public function test_order_fulfilment_type( string $meta, array $method_ids, string $expected ): void {
		$lines = array_map(
			static fn( $id ) => new class( $id ) {
				public function __construct( private string $id ) {}
				public function get_method_id(): string {
					return $this->id;
				}
			},
			$method_ids
		);
		$order = new class( $meta, $lines ) {
			public function __construct( private string $meta, private array $lines ) {}
			public function get_meta( $key ) {
				return 'lafka_order_type' === $key ? $this->meta : '';
			}
			public function get_shipping_methods(): array {
				return $this->lines;
			}
		};

		$this->assertSame( $expected, lafka_order_fulfilment_type( $order ) );
	}
}
