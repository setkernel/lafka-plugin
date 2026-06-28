<?php
/**
 * BranchOrderTypeCapabilityTest — locks down the order-type capability gate
 * added to close the "pickup-only branch forced into a delivery order" bypass
 * (audit f012).
 *
 * Before the fix, select_branch() only checked that lafka_branch_order_type was
 * non-empty and that delivery requests carried an address. It never compared the
 * requested order type against the branch's configured capability meta nor the
 * site-wide enabled modes, so a tampered client POST could push 'delivery' onto
 * a pickup-only branch (or a globally-disabled mode). This pins the pure
 * allow-list decision so a refactor cannot silently re-open the hole.
 *
 * @package Lafka_Plugin
 */

declare(strict_types=1);

namespace Lafka\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Lafka_Branch_Locations;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BranchOrderTypeCapabilityTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// Lafka_Branch_Locations::init() runs at file-include time and calls
		// get_option(); stub it empty so the include is side-effect free, then
		// load the class (once) now that the stub is active.
		Functions\when( 'get_option' )->justReturn( array() );
		if ( ! class_exists( 'Lafka_Branch_Locations', false ) ) {
			require_once dirname( __DIR__, 2 ) . '/incl/branches/class-lafka-branch-locations.php';
		}
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @return array<string, array{0: string, 1: string, 2: array<int, string>, 3: bool}>
	 */
	public static function capabilityMatrixProvider(): array {
		$both = array( 'delivery', 'pickup' );

		return array(
			// Branch supports both, site supports both.
			'delivery on delivery_pickup branch'          => array( 'delivery', 'delivery_pickup', $both, true ),
			'pickup on delivery_pickup branch'            => array( 'pickup', 'delivery_pickup', $both, true ),

			// Pickup-only branch must reject delivery (the core exploit).
			'delivery on pickup-only branch rejected'     => array( 'delivery', 'pickup', $both, false ),
			'pickup on pickup-only branch allowed'        => array( 'pickup', 'pickup', $both, true ),

			// Delivery-only branch must reject pickup.
			'pickup on delivery-only branch rejected'     => array( 'pickup', 'delivery', $both, false ),
			'delivery on delivery-only branch allowed'    => array( 'delivery', 'delivery', $both, true ),

			// Site-wide intersection: globally-disabled mode cannot be forced
			// even when the branch itself would permit it.
			'delivery blocked when site is pickup-only'   => array( 'delivery', 'delivery_pickup', array( 'pickup' ), false ),
			'pickup blocked when site is delivery-only'   => array( 'pickup', 'delivery_pickup', array( 'delivery' ), false ),

			// Unknown / empty branch cap falls back to delivery_pickup.
			'unknown cap treated as delivery_pickup'      => array( 'delivery', 'something_else', $both, true ),

			// Whitelist: anything outside {delivery,pickup} is rejected.
			'garbage order type rejected'                 => array( 'teleport', 'delivery_pickup', $both, false ),
			'empty order type rejected'                   => array( '', 'delivery_pickup', $both, false ),
			'delivery_pickup is not itself an order type' => array( 'delivery_pickup', 'delivery_pickup', $both, false ),
		);
	}

	/**
	 * @param string             $order_type
	 * @param string             $branch_cap
	 * @param array<int, string> $site_allowed
	 * @param bool               $expected
	 */
	#[DataProvider( 'capabilityMatrixProvider' )]
	public function test_is_order_type_permitted_by_caps( string $order_type, string $branch_cap, array $site_allowed, bool $expected ): void {
		$this->assertSame(
			$expected,
			Lafka_Branch_Locations::is_order_type_permitted_by_caps( $order_type, $branch_cap, $site_allowed )
		);
	}

	public function test_empty_site_allowed_rejects_everything(): void {
		$this->assertFalse(
			Lafka_Branch_Locations::is_order_type_permitted_by_caps( 'delivery', 'delivery_pickup', array() )
		);
		$this->assertFalse(
			Lafka_Branch_Locations::is_order_type_permitted_by_caps( 'pickup', 'delivery_pickup', array() )
		);
	}
}
