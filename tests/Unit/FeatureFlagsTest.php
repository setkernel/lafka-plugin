<?php
/**
 * Smoke test for the is_lafka_*() feature-flag helpers (P2-03a.2).
 *
 * Each helper reads from `Lafka_Options::is_enabled('<key>')`, which in turn
 * checks the `lafka` option array for `'enabled'`. Locks in the contract that:
 *   - default-OFF (empty / absent option → false)
 *   - 'enabled' → true
 *   - any other string → false
 *
 * The helpers are loaded by lafka-plugin.php at load time. We require the
 * options helper directly + redefine the feature-flag functions inline so the
 * test doesn't have to boot the full plugin file.
 */

declare(strict_types=1);

namespace Lafka\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Lafka_Options;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

require_once dirname( __DIR__, 2 ) . '/incl/class-lafka-options.php';

// Inline-define the feature-flag helpers so we don't have to require the
// entire lafka-plugin.php (which calls add_action / add_filter at load).
if ( ! function_exists( 'is_lafka_product_addons' ) ) {
	function is_lafka_product_addons() {
		return Lafka_Options::is_enabled( 'product_addons' );
	}
}
if ( ! function_exists( 'is_lafka_product_combos' ) ) {
	function is_lafka_product_combos() {
		return Lafka_Options::is_enabled( 'product_combos' );
	}
}
if ( ! function_exists( 'is_lafka_kitchen_display' ) ) {
	function is_lafka_kitchen_display() {
		return Lafka_Options::is_enabled( 'kitchen_display' );
	}
}
if ( ! function_exists( 'is_lafka_promotions' ) ) {
	function is_lafka_promotions() {
		return Lafka_Options::is_enabled( 'promotions' );
	}
}

final class FeatureFlagsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// Reset Lafka_Options static cache between tests.
		Lafka_Options::flush();
		$reflection    = new ReflectionClass( Lafka_Options::class );
		$defaults_prop = $reflection->getProperty( 'defaults' );
		$defaults_prop->setValue( null, array() );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ─── Default-OFF semantics: empty option → all flags false ──────────────

	public function test_addons_off_when_lafka_option_empty(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		self::assertFalse( is_lafka_product_addons() );
	}

	public function test_combos_off_when_lafka_option_empty(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		self::assertFalse( is_lafka_product_combos() );
	}

	public function test_kitchen_display_off_when_lafka_option_empty(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		self::assertFalse( is_lafka_kitchen_display() );
	}

	public function test_promotions_off_when_lafka_option_empty(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		self::assertFalse( is_lafka_promotions() );
	}

	// ─── Explicit enabled → true ────────────────────────────────────────────

	public function test_addons_on_when_explicitly_enabled(): void {
		Functions\when( 'get_option' )->justReturn( array( 'product_addons' => 'enabled' ) );
		self::assertTrue( is_lafka_product_addons() );
	}

	public function test_combos_on_when_explicitly_enabled(): void {
		Functions\when( 'get_option' )->justReturn( array( 'product_combos' => 'enabled' ) );
		self::assertTrue( is_lafka_product_combos() );
	}

	public function test_promotions_on_when_explicitly_enabled(): void {
		Functions\when( 'get_option' )->justReturn( array( 'promotions' => 'enabled' ) );
		self::assertTrue( is_lafka_promotions() );
	}

	// ─── Strict 'enabled' string match: other truthy values are NOT enabled ─

	public function test_addons_off_when_value_is_disabled(): void {
		Functions\when( 'get_option' )->justReturn( array( 'product_addons' => 'disabled' ) );
		self::assertFalse( is_lafka_product_addons() );
	}

	public function test_addons_off_when_value_is_truthy_but_not_enabled(): void {
		Functions\when( 'get_option' )->justReturn( array( 'product_addons' => '1' ) );
		self::assertFalse( is_lafka_product_addons(), "Only the literal string 'enabled' counts." );
	}

	// ─── Per-flag isolation: enabling one doesn't enable others ─────────────

	public function test_flags_are_independent(): void {
		Functions\when( 'get_option' )->justReturn(
			array(
				'product_addons' => 'enabled',
				'product_combos' => 'disabled',
			)
		);
		self::assertTrue( is_lafka_product_addons() );
		self::assertFalse( is_lafka_product_combos() );
		self::assertFalse( is_lafka_kitchen_display() );
		self::assertFalse( is_lafka_promotions() );
	}
}
