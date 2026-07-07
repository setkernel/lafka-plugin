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

	public function test_fresh_install_defaults_to_blocks(): void {
		// mode not set, no pre-existing lafka state → blocks.
		$this->assertSame(
			Lafka_Checkout_Mode::MODE_BLOCKS,
			Lafka_Checkout_Mode::decide_mode( false, '', false )
		);
	}

	public function test_existing_install_migrates_to_classic(): void {
		// mode not set, pre-existing lafka state → classic (byte-identical behaviour).
		$this->assertSame(
			Lafka_Checkout_Mode::MODE_CLASSIC,
			Lafka_Checkout_Mode::decide_mode( false, '', true )
		);
	}

	public function test_explicit_blocks_choice_is_never_overridden_even_on_existing_install(): void {
		$this->assertSame(
			Lafka_Checkout_Mode::MODE_BLOCKS,
			Lafka_Checkout_Mode::decide_mode( true, Lafka_Checkout_Mode::MODE_BLOCKS, true )
		);
	}

	public function test_explicit_classic_choice_is_never_overridden_on_fresh_install(): void {
		$this->assertSame(
			Lafka_Checkout_Mode::MODE_CLASSIC,
			Lafka_Checkout_Mode::decide_mode( true, Lafka_Checkout_Mode::MODE_CLASSIC, false )
		);
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

	public function test_get_mode_returns_stored_blocks(): void {
		Functions\when( 'get_option' )->justReturn( 'blocks' );
		Filters\expectApplied( 'lafka_force_classic_checkout' )->andReturn( false );
		$this->assertSame( 'blocks', Lafka_Checkout_Mode::get_mode() );
		$this->assertTrue( Lafka_Checkout_Mode::is_blocks() );
	}

	public function test_get_mode_returns_stored_classic(): void {
		Functions\when( 'get_option' )->justReturn( 'classic' );
		Filters\expectApplied( 'lafka_force_classic_checkout' )->andReturn( false );
		$this->assertSame( 'classic', Lafka_Checkout_Mode::get_mode() );
		$this->assertTrue( Lafka_Checkout_Mode::is_classic() );
	}

	public function test_unset_option_defaults_to_classic_at_runtime(): void {
		// Production preservation: an unset option at runtime is an in-place upgrade.
		Functions\when( 'get_option' )->justReturn( '' );
		Filters\expectApplied( 'lafka_force_classic_checkout' )->andReturn( false );
		$this->assertSame( 'classic', Lafka_Checkout_Mode::get_mode() );
	}

	public function test_garbage_option_value_defaults_to_classic(): void {
		Functions\when( 'get_option' )->justReturn( 'nonsense' );
		Filters\expectApplied( 'lafka_force_classic_checkout' )->andReturn( false );
		$this->assertSame( 'classic', Lafka_Checkout_Mode::get_mode() );
	}

	public function test_force_classic_filter_overrides_stored_blocks(): void {
		Functions\when( 'get_option' )->justReturn( 'blocks' );
		Filters\expectApplied( 'lafka_force_classic_checkout' )->andReturn( true );
		$this->assertSame( 'classic', Lafka_Checkout_Mode::get_mode() );
		$this->assertTrue( Lafka_Checkout_Mode::is_classic() );
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
