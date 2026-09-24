<?php
/**
 * CheckoutModeDecisionTest — NX1-04b.
 *
 * Locks the checkout-mode migration decision table (Lafka_Checkout_Mode): the
 * production-preservation contract that fresh activations default to blocks while
 * existing installs are migrated to an explicit classic, the option is never
 * overridden once set, and the force-classic filter wins at runtime.
 *
 * @package Lafka_Plugin
 */

declare(strict_types=1);

namespace Lafka\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Filters;
use Brain\Monkey\Functions;
use Lafka_Checkout_Mode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CheckoutModeDecisionTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		if ( ! class_exists( 'Lafka_Checkout_Mode', false ) ) {
			require_once dirname( __DIR__, 2 ) . '/incl/checkout/class-lafka-checkout-mode.php';
		}
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/* ----------------------------------------------------------------- *
	 *  Pure decision table (decide_mode)
	 * ----------------------------------------------------------------- */

	/** @return array<string, array{0: bool, 1: string, 2: bool, 3: string}> */
	public static function decisions(): array {
		return array(
			'fresh install defaults to blocks'            => array( false, '', false, 'blocks' ),
			'existing install migrates to classic'        => array( false, '', true, 'classic' ),
			'explicit blocks kept on an existing install' => array( true, 'blocks', true, 'blocks' ),
			'explicit classic kept on a fresh install'    => array( true, 'classic', false, 'classic' ),
		);
	}

	#[DataProvider( 'decisions' )]
	public function test_decide_mode( bool $is_set, string $stored, bool $has_existing_state, string $expected ): void {
		$this->assertSame( $expected, Lafka_Checkout_Mode::decide_mode( $is_set, $stored, $has_existing_state ) );
	}

	public function test_is_valid_mode_whitelist(): void {
		$this->assertTrue( Lafka_Checkout_Mode::is_valid_mode( 'blocks' ) );
		$this->assertTrue( Lafka_Checkout_Mode::is_valid_mode( 'classic' ) );
		$this->assertFalse( Lafka_Checkout_Mode::is_valid_mode( '' ) );
		$this->assertFalse( Lafka_Checkout_Mode::is_valid_mode( 'BLOCKS' ) );
		$this->assertFalse( Lafka_Checkout_Mode::is_valid_mode( 'shortcode' ) );
	}

	/* ----------------------------------------------------------------- *
	 *  Runtime resolution (get_mode / is_blocks / is_classic)
	 * ----------------------------------------------------------------- */

	/** @return array<string, array{0: string, 1: bool, 2: string}> */
	public static function runtime_modes(): array {
		return array(
			'stored blocks'                       => array( 'blocks', false, 'blocks' ),
			'stored classic'                      => array( 'classic', false, 'classic' ),
			'unset option is an in-place upgrade' => array( '', false, 'classic' ),
			'garbage value'                       => array( 'nonsense', false, 'classic' ),
			'force-classic filter wins'           => array( 'blocks', true, 'classic' ),
		);
	}

	#[DataProvider( 'runtime_modes' )]
	public function test_get_mode( string $stored, bool $force_classic, string $expected ): void {
		Functions\when( 'get_option' )->justReturn( $stored );
		Filters\expectApplied( 'lafka_force_classic_checkout' )->andReturn( $force_classic );

		$this->assertSame( $expected, Lafka_Checkout_Mode::get_mode() );
		$this->assertSame( 'blocks' === $expected, Lafka_Checkout_Mode::is_blocks() );
		$this->assertSame( 'classic' === $expected, Lafka_Checkout_Mode::is_classic() );
	}

	/* ----------------------------------------------------------------- *
	 *  Persistence guards (set_mode / on_activation / maybe_migrate)
	 * ----------------------------------------------------------------- */

	public function test_set_mode_rejects_invalid_and_writes_valid(): void {
		$written = array();
		Functions\when( 'update_option' )->alias(
			static function ( $key, $value ) use ( &$written ) {
				$written[ $key ] = $value;
				return true;
			}
		);

		$this->assertFalse( Lafka_Checkout_Mode::set_mode( 'bogus' ) );
		$this->assertArrayNotHasKey( Lafka_Checkout_Mode::OPTION, $written );

		$this->assertTrue( Lafka_Checkout_Mode::set_mode( 'blocks' ) );
		$this->assertSame( 'blocks', $written[ Lafka_Checkout_Mode::OPTION ] );
	}

	public function test_on_activation_fresh_writes_blocks(): void {
		$written = array();
		// mode option absent; `lafka` absent (fresh) → both return the default arg.
		Functions\when( 'get_option' )->alias(
			static function ( $key, $default = false ) {
				return $default;
			}
		);
		Functions\when( 'update_option' )->alias(
			static function ( $key, $value ) use ( &$written ) {
				$written[ $key ] = $value;
				return true;
			}
		);

		Lafka_Checkout_Mode::on_activation();
		$this->assertSame( 'blocks', $written[ Lafka_Checkout_Mode::OPTION ] );
	}

	public function test_on_activation_existing_writes_classic(): void {
		$written = array();
		Functions\when( 'get_option' )->alias(
			static function ( $key, $default = false ) {
				if ( 'lafka' === $key ) {
					return array( 'product_addons' => 'enabled' ); // pre-existing state.
				}
				return $default; // mode option absent.
			}
		);
		Functions\when( 'update_option' )->alias(
			static function ( $key, $value ) use ( &$written ) {
				$written[ $key ] = $value;
				return true;
			}
		);

		Lafka_Checkout_Mode::on_activation();
		$this->assertSame( 'classic', $written[ Lafka_Checkout_Mode::OPTION ] );
	}

	public function test_on_activation_is_idempotent_when_mode_already_set(): void {
		$write_count = 0;
		Functions\when( 'get_option' )->alias(
			static function ( $key, $default = false ) {
				if ( Lafka_Checkout_Mode::OPTION === $key ) {
					return 'blocks';
				}
				return $default;
			}
		);
		Functions\when( 'update_option' )->alias(
			static function () use ( &$write_count ) {
				$write_count++;
				return true;
			}
		);

		Lafka_Checkout_Mode::on_activation();
		$this->assertSame( 0, $write_count, 'Activation must not overwrite an explicit mode.' );
	}

	public function test_maybe_migrate_writes_classic_for_upgraded_existing_install(): void {
		$written = array();
		Functions\when( 'get_option' )->alias(
			static function ( $key, $default = false ) {
				if ( 'lafka' === $key ) {
					return array( 'order_hours' => 'enabled' );
				}
				return $default; // mode option absent.
			}
		);
		Functions\when( 'update_option' )->alias(
			static function ( $key, $value ) use ( &$written ) {
				$written[ $key ] = $value;
				return true;
			}
		);

		Lafka_Checkout_Mode::maybe_migrate();
		$this->assertSame( 'classic', $written[ Lafka_Checkout_Mode::OPTION ] );
	}

	public function test_maybe_migrate_noops_when_mode_valid(): void {
		$write_count = 0;
		Functions\when( 'get_option' )->alias(
			static function ( $key, $default = false ) {
				if ( Lafka_Checkout_Mode::OPTION === $key ) {
					return 'classic';
				}
				return $default;
			}
		);
		Functions\when( 'update_option' )->alias(
			static function () use ( &$write_count ) {
				$write_count++;
				return true;
			}
		);

		Lafka_Checkout_Mode::maybe_migrate();
		$this->assertSame( 0, $write_count );
	}
}
