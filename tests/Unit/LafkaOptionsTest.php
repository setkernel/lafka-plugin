<?php
/**
 * Smoke test for Lafka_Options::get() precedence + cache.
 *
 * Demonstrates the harness wiring (PHPUnit + Brain Monkey) and locks in the
 * lookup order specified at class-lafka-options.php:33–45.
 */

declare(strict_types=1);

namespace Lafka\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Lafka_Options;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

require_once dirname( __DIR__, 2 ) . '/incl/class-lafka-options.php';

final class LafkaOptionsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->reset_lafka_options_static_state();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_saved_value_beats_explicit_default(): void {
		Functions\when( 'get_option' )->justReturn( array( 'greeting' => 'hello' ) );

		self::assertSame( 'hello', Lafka_Options::get( 'greeting', 'fallback' ) );
	}

	public function test_explicit_default_beats_registered_default(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		Lafka_Options::set_defaults( array( 'color' => 'blue' ) );

		self::assertSame( 'red', Lafka_Options::get( 'color', 'red' ) );
	}

	public function test_registered_default_used_when_no_explicit_default(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		Lafka_Options::set_defaults( array( 'color' => 'blue' ) );

		self::assertSame( 'blue', Lafka_Options::get( 'color' ) );
	}

	public function test_returns_false_when_nothing_resolves(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		self::assertFalse( Lafka_Options::get( 'totally_missing_key' ) );
	}

	public function test_get_caches_db_read_within_request(): void {
		$call_count = 0;
		Functions\when( 'get_option' )->alias(
			function () use ( &$call_count ) {
				$call_count++;
				return array( 'foo' => 'bar' );
			}
		);

		Lafka_Options::get( 'foo' );
		Lafka_Options::get( 'foo' );
		Lafka_Options::get( 'unrelated' );

		self::assertSame( 1, $call_count, 'get_option() must be called only once per request lifecycle.' );
	}

	/**
	 * Lafka_Options is a static singleton; reset its private cache between tests
	 * so test order can't leak state. Uses reflection because the class
	 * deliberately keeps these properties private.
	 */
	private function reset_lafka_options_static_state(): void {
		Lafka_Options::flush();
		// ReflectionProperty has been accessible-by-default since PHP 8.1; no setAccessible() needed.
		$reflection    = new ReflectionClass( Lafka_Options::class );
		$defaults_prop = $reflection->getProperty( 'defaults' );
		$defaults_prop->setValue( null, array() );
	}
}
