<?php
/**
 * Behavioural coverage for Lafka_Timeslots and its Store API adapter.
 *
 * Each test drives the real class with Brain Monkey stubs for the WP/WC
 * functions it touches, and asserts on the decision it returns.
 *
 * @package Lafka_Plugin
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Lafka_Store_Api;
use Lafka_Timeslots;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class TimeslotsBehaviorTest extends TestCase {

	/** @var array<string, mixed> */
	private array $options = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
		Functions\when( 'get_option' )->alias(
			fn( $key, $fallback = false ) => $this->options[ $key ] ?? $fallback
		);
		Functions\when( 'get_term_meta' )->justReturn( '' );
		Functions\when( 'WC' )->justReturn( (object) array( 'session' => null ) );
		require_once dirname( __DIR__, 2 ) . '/incl/timeslots/class-lafka-timeslots.php';
		require_once dirname( __DIR__, 2 ) . '/incl/store-api/class-lafka-store-api.php';
	}

	protected function tearDown(): void {
		$this->set_singleton( null );
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Build a Lafka_Timeslots without running the hook-registering constructor,
	 * hydrate it from the stubbed options, and install it as the singleton.
	 */
	private function timeslots(): Lafka_Timeslots {
		$instance = ( new ReflectionClass( Lafka_Timeslots::class ) )->newInstanceWithoutConstructor();
		$instance->init_order_date_time_options();
		$this->set_singleton( $instance );
		return $instance;
	}

	private function set_singleton( ?Lafka_Timeslots $instance ): void {
		$prop = ( new ReflectionClass( Lafka_Timeslots::class ) )->getProperty( '_instance' );
		$prop->setValue( null, $instance );
	}

	private function store_api_timeslot_error(): ?string {
		$method = ( new ReflectionClass( Lafka_Store_Api::class ) )->getMethod( 'timeslot_error' );
		return $method->invoke( null, array() );
	}

	public function test_saved_mandatory_is_inert_while_feature_is_off(): void {
		$this->options['lafka_shipping_areas_datetime'] = array(
			'enable_datetime_option' => '',
			'datetime_mandatory'     => '1',
		);

		$this->assertFalse( $this->timeslots()->is_mandatory() );
		$this->assertNull(
			$this->store_api_timeslot_error(),
			'Block checkout must be placeable when the date/time feature is off, whatever "mandatory" says.'
		);
	}

	public function test_mandatory_blocks_an_empty_selection_while_feature_is_on(): void {
		$this->options['lafka_shipping_areas_datetime'] = array(
			'enable_datetime_option' => '1',
			'datetime_mandatory'     => '1',
		);

		$this->assertTrue( $this->timeslots()->is_mandatory() );
		$this->assertSame( 'Please enter Delivery/Pickup time.', $this->store_api_timeslot_error() );
	}

	public function test_optional_store_accepts_an_empty_selection(): void {
		$this->options['lafka_shipping_areas_datetime'] = array(
			'enable_datetime_option' => '1',
			'datetime_mandatory'     => '',
		);

		$this->assertFalse( $this->timeslots()->is_mandatory() );
		$this->assertNull( $this->store_api_timeslot_error() );
	}
}
