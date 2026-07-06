<?php
/**
 * StoreApiParityTest — NX1-04a.
 *
 * Locks the Store API (block cart/checkout, headless) server-truth parity module
 * (Lafka_Store_Api): every classic-checkout ordering gate must also hold on the
 * Store API path. Covers, per the roadmap item:
 *   · registration of the schema extension + update callback (behavioural),
 *   · the pure validation-adapter decisions (order-hours, branch, timeslot shape),
 *   · the cart schema payload shape,
 *   · structural locks that the module wires the correct Store API hooks and
 *     routes each gate back through the shared classic decision (no duplication).
 *
 * Adapter decisions that lean on WC()/order-hours/session statics (not
 * bootstrapped in unit tests) are pinned structurally, matching the
 * OrderHoursServerGateTest / TimeslotCapacityEnforcementTest convention; their
 * real behaviour is exercised by the live wp-env contract check in the item.
 *
 * @package Lafka_Plugin
 */

declare(strict_types=1);

namespace Lafka\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Lafka_Store_Api;
use PHPUnit\Framework\TestCase;

final class StoreApiParityTest extends TestCase {

	private string $src;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		if ( ! defined( 'ARRAY_A' ) ) {
			define( 'ARRAY_A', 'ARRAY_A' );
		}
		// __() passthrough so pure decisions return their message strings.
		Functions\when( '__' )->returnArg();
		if ( ! class_exists( 'Lafka_Store_Api', false ) ) {
			require_once dirname( __DIR__, 2 ) . '/incl/store-api/class-lafka-store-api.php';
		}
		$this->src = file_get_contents(
			dirname( __DIR__, 2 ) . '/incl/store-api/class-lafka-store-api.php'
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/* ----------------------------------------------------------------- *
	 *  Registration (behavioural)
	 * ----------------------------------------------------------------- */

	public function test_register_wires_cart_schema_and_update_callback(): void {
		$captured = array();
		Functions\when( 'woocommerce_store_api_register_endpoint_data' )->alias(
			static function ( $args ) use ( &$captured ) {
				$captured['schema'] = $args;
				return true;
			}
		);
		Functions\when( 'woocommerce_store_api_register_update_callback' )->alias(
			static function ( $args ) use ( &$captured ) {
				$captured['callback'] = $args;
				return true;
			}
		);

		Lafka_Store_Api::register();

		$this->assertArrayHasKey( 'schema', $captured, 'register() must register cart endpoint data.' );
		$this->assertSame( 'cart', $captured['schema']['endpoint'], 'Schema must extend the cart endpoint.' );
		$this->assertSame( 'lafka', $captured['schema']['namespace'], 'Schema must use the lafka namespace.' );
		$this->assertIsCallable( $captured['schema']['data_callback'], 'Schema must provide a data callback.' );
		$this->assertIsCallable( $captured['schema']['schema_callback'], 'Schema must provide a schema callback.' );

		$this->assertArrayHasKey( 'callback', $captured, 'register() must register an update callback.' );
		$this->assertSame( 'lafka', $captured['callback']['namespace'], 'Update callback must use the lafka namespace.' );
		$this->assertIsCallable( $captured['callback']['callback'], 'Update callback must be callable.' );
	}

	public function test_register_guards_on_store_api_availability(): void {
		// The guard (structural): register() must bail unless the Store API
		// registration helpers exist, so a WC build without the Store API is a
		// clean no-op. (Behaviourally untestable here — Brain Monkey cannot
		// UNdefine a function once another test has defined it.)
		$body = $this->method_body( 'register' );
		$this->assertStringContainsString(
			"! function_exists( 'woocommerce_store_api_register_endpoint_data' )",
			$body,
			'register() must guard on Store API helper availability before wiring anything.'
		);
	}

	/* ----------------------------------------------------------------- *
	 *  Adapter decision: order-hours (store closed)
	 * ----------------------------------------------------------------- */

	public function test_order_hours_open_passes(): void {
		$this->assertNull(
			Lafka_Store_Api::evaluate_order_hours( true, 'Sorry, we are closed.' ),
			'An open store must not add a cart error.'
		);
	}

	public function test_order_hours_closed_returns_message(): void {
		$this->assertSame(
			'Sorry, we are closed.',
			Lafka_Store_Api::evaluate_order_hours( false, 'Sorry, we are closed.' ),
			'A closed store must surface the closed-store message as a cart error.'
		);
	}

	/* ----------------------------------------------------------------- *
	 *  Adapter decision: branch update-callback validation
	 * ----------------------------------------------------------------- */

	public function test_branch_update_accepts_valid_selection(): void {
		Functions\when( 'is_wp_error' )->justReturn( false );
		$term = (object) array( 'taxonomy' => 'lafka_branch_location' );
		$this->assertNull(
			Lafka_Store_Api::branch_update_error( $term, true, true ),
			'A legit branch with a permitted order type must be accepted.'
		);
	}

	public function test_branch_update_rejects_wrong_taxonomy(): void {
		Functions\when( 'is_wp_error' )->justReturn( false );
		$term = (object) array( 'taxonomy' => 'product_cat' );
		$this->assertNotNull(
			Lafka_Store_Api::branch_update_error( $term, true, true ),
			'A term outside lafka_branch_location must be rejected (taxonomy constraint).'
		);
	}

	public function test_branch_update_rejects_null_term(): void {
		$this->assertNotNull(
			Lafka_Store_Api::branch_update_error( null, true, true ),
			'A non-resolving branch id must be rejected.'
		);
	}

	public function test_branch_update_rejects_non_legit_branch(): void {
		Functions\when( 'is_wp_error' )->justReturn( false );
		$term = (object) array( 'taxonomy' => 'lafka_branch_location' );
		$this->assertNotNull(
			Lafka_Store_Api::branch_update_error( $term, false, true ),
			'A branch outside the legit (orderable) allow-list must be rejected.'
		);
	}

	public function test_branch_update_rejects_capability_mismatch(): void {
		Functions\when( 'is_wp_error' )->justReturn( false );
		$term = (object) array( 'taxonomy' => 'lafka_branch_location' );
		$this->assertNotNull(
			Lafka_Store_Api::branch_update_error( $term, true, false ),
			'An order type the branch/site does not permit must be rejected.'
		);
	}

	/* ----------------------------------------------------------------- *
	 *  Adapter decision: timeslot update-callback shape validation
	 * ----------------------------------------------------------------- */

	public function test_timeslot_update_accepts_empty(): void {
		$this->assertNull(
			Lafka_Store_Api::timeslot_update_error( '', '' ),
			'An empty selection is valid at the update boundary (optional store).'
		);
	}

	public function test_timeslot_update_accepts_well_formed_pair(): void {
		$this->assertNull(
			Lafka_Store_Api::timeslot_update_error( '2026-07-10', '12:00 - 12:30' ),
			'A well-formed date + slot must pass the shape check.'
		);
	}

	public function test_timeslot_update_rejects_slot_without_date(): void {
		$this->assertNotNull(
			Lafka_Store_Api::timeslot_update_error( '', '12:00 - 12:30' ),
			'A slot without its anchoring date is malformed.'
		);
	}

	public function test_timeslot_update_rejects_malformed_date(): void {
		$this->assertNotNull(
			Lafka_Store_Api::timeslot_update_error( '10/07/2026', '' ),
			'A date not in Y-m-d form is malformed.'
		);
	}

	/* ----------------------------------------------------------------- *
	 *  Schema payload shape
	 * ----------------------------------------------------------------- */

	public function test_cart_schema_exposes_block_ui_contract(): void {
		$schema = Lafka_Store_Api::extend_cart_schema();
		foreach ( array(
			'order_type',
			'branch_id',
			'branch_name',
			'checkout_date',
			'checkout_timeslot',
			'store_open_now',
			'next_open',
			'free_delivery_threshold',
			'free_delivery_remaining',
			'delivery_minimum',
			'delivery_minimum_remaining',
		) as $key ) {
			$this->assertArrayHasKey( $key, $schema, "Cart schema must expose '{$key}' for the block UI." );
			$this->assertArrayHasKey( 'type', $schema[ $key ], "Schema property '{$key}' must declare a type." );
		}
	}

	/* ----------------------------------------------------------------- *
	 *  Structural locks: hooks + shared-decision routing
	 * ----------------------------------------------------------------- */

	public function test_registers_store_api_validation_hooks(): void {
		$this->assertMatchesRegularExpression(
			"/add_action\(\s*'woocommerce_store_api_cart_errors'\s*,\s*array\(\s*__CLASS__\s*,\s*'add_cart_errors'/",
			$this->src,
			'Cart/checkout gates must be enforced on the real woocommerce_store_api_cart_errors hook.'
		);
		$this->assertMatchesRegularExpression(
			"/add_action\(\s*'woocommerce_store_api_checkout_update_order_from_request'\s*,\s*array\(\s*__CLASS__\s*,\s*'on_checkout_update_order_from_request'/",
			$this->src,
			'The geo-fence + meta persistence must hook woocommerce_store_api_checkout_update_order_from_request.'
		);
	}

	public function test_cart_errors_route_through_shared_gates(): void {
		$body = $this->method_body( 'add_cart_errors' );
		$this->assertNotSame( '', $body, 'add_cart_errors body not found.' );
		$this->assertStringContainsString(
			'Lafka_Order_Hours::is_shop_open()',
			$body,
			'Store closed gate must reuse Lafka_Order_Hours::is_shop_open().'
		);
		$this->assertStringContainsString(
			'Lafka_Branch_Locations::is_order_type_allowed_for_branch',
			$body,
			'Branch order-type gate must reuse the classic capability predicate.'
		);
		$this->assertStringContainsString(
			'timeslot_error',
			$body,
			'Timeslot gate must route through the shared timeslot decision.'
		);
	}

	public function test_timeslot_gate_delegates_to_shared_decision(): void {
		$body = $this->method_body( 'timeslot_error' );
		$this->assertStringContainsString(
			'evaluate_datetime_selection',
			$body,
			'The Store API timeslot gate must delegate to Lafka_Timeslots::evaluate_datetime_selection (no duplicated validity/capacity logic).'
		);
	}

	public function test_geo_fence_is_delivery_only_and_reuses_polygon_test(): void {
		$body = $this->method_body( 'validate_geo_fence' );
		$this->assertNotSame( '', $body, 'validate_geo_fence body not found.' );
		$this->assertStringContainsString(
			"'delivery' !== ( \$branch['order_type'] ?? '' )",
			$body,
			'The geo-fence must apply to delivery orders only.'
		);
		$this->assertStringContainsString(
			'is_point_in_delivery_zone',
			$body,
			'The geo-fence must reuse the shared Lafka_Shipping_Areas::is_point_in_delivery_zone() polygon test.'
		);
		$this->assertStringContainsString(
			'lafka_outside_delivery_area',
			$body,
			'An out-of-zone pinpoint must raise a Store API error.'
		);
	}

	public function test_update_callback_reuses_select_branch_predicates(): void {
		$body = $this->method_body( 'apply_branch_update' );
		$this->assertNotSame( '', $body, 'apply_branch_update body not found.' );
		$this->assertStringContainsString(
			"get_term( \$branch_id, 'lafka_branch_location' )",
			$body,
			'Branch update must constrain the id to the lafka_branch_location taxonomy (like select_branch).'
		);
		$this->assertStringContainsString(
			'Lafka_Shipping_Areas::get_all_legit_branch_locations()',
			$body,
			'Branch update must enforce the legit-branch allow-list (like select_branch).'
		);
		$this->assertStringContainsString(
			'Lafka_Branch_Locations::is_order_type_allowed_for_branch',
			$body,
			'Branch update must enforce the order-type capability (like select_branch).'
		);
	}

	/**
	 * Crude PHP-source method-body extractor, mirroring the helper used by the
	 * order-hours / timeslot structural tests.
	 */
	private function method_body( string $name ): string {
		$start = strpos( $this->src, 'function ' . $name );
		if ( false === $start ) {
			return '';
		}
		$rest = substr( $this->src, $start + strlen( 'function ' . $name ) );
		$next = strpos( $rest, ' function ' );
		return false === $next ? $rest : substr( $rest, 0, $next );
	}
}
