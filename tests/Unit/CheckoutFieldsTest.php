<?php
/**
 * CheckoutFieldsTest — NX1-04b.
 *
 * Locks the block-checkout order_type + branch fields (Lafka_Checkout_Fields):
 * conditional-display logic, option shapes, the field→session sync (parity with
 * the classic `lafka_branch_location` session), and the registration guards
 * (blocks mode + Additional Checkout Fields API present).
 *
 * @package Lafka_Plugin
 */

declare(strict_types=1);

namespace Lafka\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Lafka_Checkout_Fields;
use PHPUnit\Framework\TestCase;

final class CheckoutFieldsTest extends TestCase {

	/**
	 * Per-test option map consulted by the shared get_option() stub.
	 *
	 * @var array<string,mixed>
	 */
	private array $options = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$this->options = array();
		Functions\when( 'get_option' )->alias(
			function ( $key, $default = false ) {
				return array_key_exists( $key, $this->options ) ? $this->options[ $key ] : $default;
			}
		);

		// Both classes load side-effect-free with get_option stubbed empty.
		if ( ! class_exists( 'Lafka_Branch_Locations', false ) ) {
			require_once dirname( __DIR__, 2 ) . '/incl/branches/class-lafka-branch-locations.php';
		}
		if ( ! class_exists( 'Lafka_Checkout_Mode', false ) ) {
			require_once dirname( __DIR__, 2 ) . '/incl/checkout/class-lafka-checkout-mode.php';
		}
		if ( ! class_exists( 'Lafka_Checkout_Fields', false ) ) {
			require_once dirname( __DIR__, 2 ) . '/incl/checkout/class-lafka-checkout-fields.php';
		}
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Configure the site to offer both order types (default) or a single type, with
	 * the branch-selection modal on (the classic gate that activates the fields).
	 *
	 * @param string $order_type_setting 'delivery_pickup' | 'delivery' | 'pickup'.
	 */
	private function set_site_order_type( string $order_type_setting ): void {
		$this->options['lafka_shipping_areas_branches'] = array(
			'order_type'                    => $order_type_setting,
			'enable_branch_selection_modal' => 1,
		);
	}

	/* ----------------------------------------------------------------- *
	 *  Conditional display
	 * ----------------------------------------------------------------- */

	public function test_fields_hidden_when_shipping_areas_disabled(): void {
		Functions\when( 'is_lafka_shipping_areas' )->justReturn( false );

		$this->assertFalse( Lafka_Checkout_Fields::should_show_order_type_field() );
		$this->assertFalse( Lafka_Checkout_Fields::should_show_branch_field() );
	}

	public function test_order_type_field_shown_only_with_more_than_one_type(): void {
		Functions\when( 'is_lafka_shipping_areas' )->justReturn( true );

		$this->set_site_order_type( 'delivery_pickup' );
		$this->assertTrue( Lafka_Checkout_Fields::should_show_order_type_field() );

		$this->set_site_order_type( 'delivery' );
		$this->assertFalse( Lafka_Checkout_Fields::should_show_order_type_field() );
	}

	public function test_branch_field_shown_only_with_more_than_one_branch(): void {
		Functions\when( 'is_lafka_shipping_areas' )->justReturn( true );
		$this->options['lafka_shipping_areas_branches'] = array( 'enable_branch_selection_modal' => 1 );

		Functions\when( 'get_terms' )->justReturn( array( 10 => 'A', 20 => 'B' ) );
		$this->assertTrue( Lafka_Checkout_Fields::should_show_branch_field() );

		Functions\when( 'get_terms' )->justReturn( array( 10 => 'A' ) );
		$this->assertFalse( Lafka_Checkout_Fields::should_show_branch_field() );

		Functions\when( 'get_terms' )->justReturn( array() );
		$this->assertFalse( Lafka_Checkout_Fields::should_show_branch_field() );
	}

	public function test_fields_hidden_when_branch_modal_off(): void {
		// Module on but the operator did NOT enable the branch-selection modal:
		// the classic path collects no branch/order-type, so neither must the block.
		Functions\when( 'is_lafka_shipping_areas' )->justReturn( true );
		$this->options['lafka_shipping_areas_branches'] = array( 'order_type' => 'delivery_pickup' );
		Functions\when( 'get_terms' )->justReturn( array( 10 => 'A', 20 => 'B' ) );

		$this->assertFalse( Lafka_Checkout_Fields::is_branch_selection_active() );
		$this->assertFalse( Lafka_Checkout_Fields::should_show_order_type_field() );
		$this->assertFalse( Lafka_Checkout_Fields::should_show_branch_field() );
	}

	/* ----------------------------------------------------------------- *
	 *  Option shapes
	 * ----------------------------------------------------------------- */

	public function test_order_type_options_shape(): void {
		$this->set_site_order_type( 'delivery_pickup' );
		$options = Lafka_Checkout_Fields::get_order_type_options();

		$this->assertCount( 2, $options );
		$this->assertSame( 'delivery', $options[0]['value'] );
		$this->assertArrayHasKey( 'label', $options[0] );
		$this->assertSame( 'pickup', $options[1]['value'] );
	}

	public function test_branch_options_shape_casts_ids_to_strings(): void {
		Functions\when( 'get_terms' )->justReturn( array( 10 => 'Downtown', 20 => 'Uptown' ) );
		$options = Lafka_Checkout_Fields::get_branch_options();

		$this->assertSame(
			array(
				array(
					'value' => '10',
					'label' => 'Downtown',
				),
				array(
					'value' => '20',
					'label' => 'Uptown',
				),
			),
			$options
		);
	}

	/* ----------------------------------------------------------------- *
	 *  Field → session sync (parity with classic lafka_branch_location)
	 * ----------------------------------------------------------------- */

	public function test_sync_ignores_non_lafka_fields(): void {
		$session = new FakeWcSession();
		$this->stub_wc_with_session( $session );

		Lafka_Checkout_Fields::sync_field_to_session( 'other/thing', 'x' );
		$this->assertNull( $session->get( Lafka_Checkout_Fields::BRANCH_SESSION_KEY ) );
	}

	public function test_sync_order_type_writes_session_and_defaults_single_branch(): void {
		$session = new FakeWcSession();
		$this->stub_wc_with_session( $session );
		Functions\when( 'get_terms' )->justReturn( array( 42 => 'Main Branch' ) ); // single branch.

		Lafka_Checkout_Fields::sync_field_to_session( Lafka_Checkout_Fields::FIELD_ORDER_TYPE, 'delivery' );

		$stored = $session->get( Lafka_Checkout_Fields::BRANCH_SESSION_KEY );
		$this->assertSame( 'delivery', $stored['order_type'] );
		$this->assertSame( 42, $stored['branch_id'], 'Single-branch site must default branch_id.' );
	}

	public function test_sync_branch_writes_int_branch_id(): void {
		$session = new FakeWcSession();
		$this->stub_wc_with_session( $session );
		Functions\when( 'get_terms' )->justReturn( array( 10 => 'A', 20 => 'B' ) );

		Lafka_Checkout_Fields::sync_field_to_session( Lafka_Checkout_Fields::FIELD_BRANCH, '20' );

		$stored = $session->get( Lafka_Checkout_Fields::BRANCH_SESSION_KEY );
		$this->assertSame( 20, $stored['branch_id'] );
	}

	public function test_sync_preserves_the_other_key(): void {
		$session = new FakeWcSession();
		$session->set( Lafka_Checkout_Fields::BRANCH_SESSION_KEY, array( 'branch_id' => 20 ) );
		$this->stub_wc_with_session( $session );
		Functions\when( 'get_terms' )->justReturn( array( 10 => 'A', 20 => 'B' ) );

		Lafka_Checkout_Fields::sync_field_to_session( Lafka_Checkout_Fields::FIELD_ORDER_TYPE, 'pickup' );

		$stored = $session->get( Lafka_Checkout_Fields::BRANCH_SESSION_KEY );
		$this->assertSame( 'pickup', $stored['order_type'] );
		$this->assertSame( 20, $stored['branch_id'], 'Existing branch_id must survive an order_type update.' );
	}

	public function test_single_branch_id_helper(): void {
		Functions\when( 'get_terms' )->justReturn( array( 7 => 'Only' ) );
		$this->assertSame( 7, Lafka_Checkout_Fields::single_branch_id() );

		Functions\when( 'get_terms' )->justReturn( array( 7 => 'A', 8 => 'B' ) );
		$this->assertSame( 0, Lafka_Checkout_Fields::single_branch_id() );

		Functions\when( 'get_terms' )->justReturn( array() );
		$this->assertSame( 0, Lafka_Checkout_Fields::single_branch_id() );
	}

	/* ----------------------------------------------------------------- *
	 *  Registration guards
	 * ----------------------------------------------------------------- */

	public function test_register_noops_without_the_api(): void {
		// woocommerce_register_additional_checkout_field undefined → must not fatal.
		Lafka_Checkout_Fields::register();
		$this->assertTrue( true );
	}

	public function test_register_only_wires_fields_in_blocks_mode(): void {
		$registered = array();
		Functions\when( 'woocommerce_register_additional_checkout_field' )->alias(
			static function ( $opts ) use ( &$registered ) {
				$registered[] = $opts['id'];
			}
		);
		Functions\when( 'is_lafka_shipping_areas' )->justReturn( true );
		Functions\when( 'get_terms' )->justReturn( array( 10 => 'A', 20 => 'B' ) );
		$this->set_site_order_type( 'delivery_pickup' );

		// Classic mode → no registration.
		$this->options['lafka_checkout_mode'] = 'classic';
		Lafka_Checkout_Fields::register();
		$this->assertSame( array(), $registered );

		// Blocks mode → both fields registered.
		$this->options['lafka_checkout_mode'] = 'blocks';
		Lafka_Checkout_Fields::register();
		$this->assertContains( Lafka_Checkout_Fields::FIELD_ORDER_TYPE, $registered );
		$this->assertContains( Lafka_Checkout_Fields::FIELD_BRANCH, $registered );
	}

	/* ----------------------------------------------------------------- *
	 *  Test helpers
	 * ----------------------------------------------------------------- */

	private function stub_wc_with_session( FakeWcSession $session ): void {
		$GLOBALS['__lafka_test_wc'] = new class( $session ) {
			public $session;
			public function __construct( $session ) {
				$this->session = $session;
			}
		};
		Functions\when( 'WC' )->alias(
			static function () {
				return $GLOBALS['__lafka_test_wc'];
			}
		);
	}
}

/**
 * Minimal in-memory WC session stand-in.
 */
final class FakeWcSession {
	private array $data = array();
	public function get( $key ) {
		return $this->data[ $key ] ?? null;
	}
	public function set( $key, $value ) {
		$this->data[ $key ] = $value;
	}
}
