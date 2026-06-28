<?php
/**
 * Regression test for f102: Lafka_Options::is_enabled() must resolve a feature
 * flag through the SAME path as lafka_get_option() once `init` has fired, so the
 * two documented access paths to the same option (is_lafka_*() helpers vs.
 * lafka_get_option()) agree.
 *
 * The historical bug: is_enabled() always passed an explicit '' default, which
 * short-circuited get() at the caller-default layer and never reached the
 * registered-theme-defaults layer. On a fresh install this made
 * lafka_get_option('product_addons') return the registered 'enabled' default
 * while is_lafka_product_addons() (-> is_enabled) returned false for the same
 * key. The fix keeps the '' short-circuit only PRE-init (to avoid the
 * "_load_textdomain_just_in_time" notice) and delegates to the defaults-aware
 * lookup POST-init.
 */

declare(strict_types=1);

namespace Lafka\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Lafka_Options;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

require_once dirname( __DIR__, 2 ) . '/incl/class-lafka-options.php';

final class FeatureFlagGateConsistencyTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->reset_lafka_options_static_state();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Post-init, with no saved value, is_enabled() resolves the registered theme
	 * default — exactly like the defaults-aware lafka_get_option() path.
	 *
	 * @param string $registered_default The std value the theme registers.
	 * @param bool   $expected           Whether the flag should read as enabled.
	 */
	#[DataProvider( 'registered_default_provider' )]
	public function test_resolves_registered_default_after_init( string $registered_default, bool $expected ): void {
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'did_action' )->justReturn( 1 );
		Lafka_Options::set_defaults( array( 'product_addons' => $registered_default ) );

		self::assertSame(
			$expected,
			Lafka_Options::is_enabled( 'product_addons' ),
			'is_enabled() must honour the registered theme default once init has fired.'
		);
	}

	/**
	 * The headline fix: post-init, is_enabled() agrees with the defaults-aware
	 * get() lookup (the path lafka_get_option() takes) for the same key.
	 *
	 * @param string $registered_default The std value the theme registers.
	 * @param bool   $expected           Whether the flag should read as enabled.
	 */
	#[DataProvider( 'registered_default_provider' )]
	public function test_is_enabled_agrees_with_get_after_init( string $registered_default, bool $expected ): void {
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'did_action' )->justReturn( 1 );
		Lafka_Options::set_defaults( array( 'product_addons' => $registered_default ) );

		$via_get        = 'enabled' === Lafka_Options::get( 'product_addons' );
		$via_is_enabled = Lafka_Options::is_enabled( 'product_addons' );

		self::assertSame(
			$via_get,
			$via_is_enabled,
			'is_lafka_*() (is_enabled) and lafka_get_option() (get) must agree for the same key after init.'
		);
		self::assertSame( $expected, $via_is_enabled );
	}

	public static function registered_default_provider(): array {
		return array(
			'default-on flag (std=enabled)'  => array( 'enabled', true ),
			'default-off flag (std=empty)'   => array( '', false ),
			'explicit disabled std value'    => array( 'disabled', false ),
		);
	}

	/**
	 * Pre-init, is_enabled() keeps the '' short-circuit so it never reaches (and
	 * never triggers the lazy load of) the framework defaults layer — even when a
	 * default-ON value is registered. This preserves the notice-avoidance contract
	 * for the include-time loader gate in lafka-plugin.php.
	 */
	public function test_pre_init_does_not_consult_registered_defaults(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'did_action' )->justReturn( 0 );
		Lafka_Options::set_defaults( array( 'product_addons' => 'enabled' ) );

		self::assertFalse(
			Lafka_Options::is_enabled( 'product_addons' ),
			'Pre-init reads must not fall through to the registered defaults layer.'
		);
	}

	/**
	 * A persisted value always wins, in both init states — this is how a fresh
	 * install behaves once the 'lafka' option is seeded on activation: the
	 * pre-init gate reads the persisted 'enabled' value directly.
	 *
	 * @param int  $did_init Stubbed did_action('init') return (0 pre-init, 1 post-init).
	 */
	#[DataProvider( 'init_state_provider' )]
	public function test_saved_enabled_value_wins_regardless_of_init_state( int $did_init ): void {
		Functions\when( 'get_option' )->justReturn( array( 'product_addons' => 'enabled' ) );
		Functions\when( 'did_action' )->justReturn( $did_init );

		self::assertTrue( Lafka_Options::is_enabled( 'product_addons' ) );
	}

	/**
	 * An explicitly-saved empty (disabled) value is respected in both init states
	 * — the activation seeder is create-only and never overrides an admin choice.
	 *
	 * @param int $did_init Stubbed did_action('init') return.
	 */
	#[DataProvider( 'init_state_provider' )]
	public function test_saved_disabled_value_stays_off_regardless_of_init_state( int $did_init ): void {
		Functions\when( 'get_option' )->justReturn( array( 'product_addons' => '' ) );
		Functions\when( 'did_action' )->justReturn( $did_init );

		self::assertFalse( Lafka_Options::is_enabled( 'product_addons' ) );
	}

	public static function init_state_provider(): array {
		return array(
			'pre-init'  => array( 0 ),
			'post-init' => array( 1 ),
		);
	}

	/**
	 * Lafka_Options is a static singleton; reset its private cache between tests
	 * so test order can't leak state.
	 */
	private function reset_lafka_options_static_state(): void {
		Lafka_Options::flush();
		$reflection    = new ReflectionClass( Lafka_Options::class );
		$defaults_prop = $reflection->getProperty( 'defaults' );
		$defaults_prop->setValue( null, array() );
	}
}
