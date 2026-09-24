<?php
/**
 * Behavioural coverage for the delivery-location (pinpoint) requirement on
 * both checkout paths: who must pinpoint (pickup never does), and the Store
 * API rejecting a delivery order that carries no pinpoint.
 *
 * @package Lafka_Plugin
 */

declare(strict_types=1);

namespace Lafka\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Lafka_Shipping_Areas;
use Lafka_Store_Api;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class DeliveryPinpointGateTest extends TestCase {

	private const MANDATORY = array(
		'pick_delivery_address'     => '1',
		'mandatory_pickup_delivery' => '1',
	);

	/** @var array<string, mixed> */
	private array $session = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'get_option' )->justReturn( self::MANDATORY );
		$session = new class( $this ) {
			public function __construct( private $test ) {}
			public function get( $key ) {
				return $this->test->session_value( $key );
			}
			public function set( $key, $value ) {}
		};
		$cart = new class() {
			public function needs_shipping() {
				return true;
			}
		};
		$wc = (object) array(
			'session' => $session,
			'cart'    => $cart,
		);
		Functions\when( 'WC' )->justReturn( $wc );
		require_once dirname( __DIR__, 2 ) . '/incl/lafka-shipping-method-helpers.php';
		require_once dirname( __DIR__, 2 ) . '/incl/shipping-areas/class-lafka-shipping-areas.php';
		require_once dirname( __DIR__, 2 ) . '/incl/store-api/class-lafka-store-api.php';
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/** Session reader used by the anonymous session double. */
	public function session_value( string $key ) {
		return $this->session[ $key ] ?? null;
	}

	/**
	 * @return array<string, array{0: mixed, 1: string, 2: string[], 3: bool, 4: bool}>
	 */
	public static function decisions(): array {
		return array(
			'delivery, pinpoint mandatory'         => array( self::MANDATORY, 'delivery', array( 'flat_rate:1' ), true, true ),
			'no order type (branches off)'         => array( self::MANDATORY, '', array( 'flat_rate:1' ), true, true ),
			'Lafka pickup order type'              => array( self::MANDATORY, 'pickup', array( 'flat_rate:1' ), true, false ),
			'classic WC local pickup'              => array( self::MANDATORY, '', array( 'local_pickup:3' ), true, false ),
			'blocks pickup location'               => array( self::MANDATORY, '', array( 'pickup_location:0' ), true, false ),
			'cart ships nothing'                   => array( self::MANDATORY, '', array(), false, false ),
			'pinpoint optional'                    => array( array( 'pick_delivery_address' => '1' ), 'delivery', array(), true, false ),
			'pinpoint off'                         => array( array(), 'delivery', array(), true, false ),
			'option never saved'                   => array( false, 'delivery', array(), true, false ),
		);
	}

	#[DataProvider( 'decisions' )]
	public function test_who_must_pinpoint( $options, string $order_type, array $methods, bool $needs_shipping, bool $expected ): void {
		$this->assertSame(
			$expected,
			Lafka_Shipping_Areas::delivery_pinpoint_required( $options, $order_type, $methods, $needs_shipping )
		);
	}

	public function test_store_api_rejects_a_delivery_order_without_a_pinpoint(): void {
		$this->session = array( 'chosen_shipping_methods' => array( 'flat_rate:1' ) );

		$this->expectException( \RuntimeException::class );
		$this->validate_geo_fence( array( 'extensions' => array() ) );
	}

	public function test_store_api_lets_a_blocks_pickup_order_through_without_a_pinpoint(): void {
		$this->session = array( 'chosen_shipping_methods' => array( 'pickup_location:0' ) );

		$this->validate_geo_fence( array( 'extensions' => array() ) );
		$this->addToAssertionCount( 1 );
	}

	public function test_store_api_rejects_an_out_of_zone_pinpoint_and_accepts_an_in_zone_one(): void {
		$this->session = array( 'chosen_shipping_methods' => array( 'flat_rate:1' ) );
		// One published zone: Google's reference triangle, roughly
		// (38.5,-120.2), (40.7,-120.95), (43.252,-126.453).
		Functions\when( 'get_posts' )->justReturn( array( (object) array( 'ID' => 1 ) ) );
		Functions\when( 'get_post_meta' )->justReturn( '_p~iF~ps|U_ulLnnqC_mqNvxq`@' );
		$prop = ( new ReflectionClass( Lafka_Shipping_Areas::class ) )->getProperty( '_instance' );
		$prop->setValue( null, ( new ReflectionClass( Lafka_Shipping_Areas::class ) )->newInstanceWithoutConstructor() );

		try {
			$this->validate_geo_fence( array( 'extensions' => array( 'lafka' => array( 'delivery_geocoded' => '{"lat":41,"lng":-122}' ) ) ) );
			$this->addToAssertionCount( 1 );

			$this->expectException( \RuntimeException::class );
			$this->validate_geo_fence( array( 'extensions' => array( 'lafka' => array( 'delivery_geocoded' => '{"lat":0,"lng":0}' ) ) ) );
		} finally {
			$prop->setValue( null, null );
		}
	}

	/**
	 * @param array<string, mixed> $body Request body.
	 */
	private function validate_geo_fence( array $body ): void {
		$request = new \ArrayObject( $body );
		$method  = ( new ReflectionClass( Lafka_Store_Api::class ) )->getMethod( 'validate_geo_fence' );
		$method->invoke( null, $request );
	}
}
