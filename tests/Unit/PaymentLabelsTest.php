<?php
/**
 * Contextual "cash on delivery" (GX0): the COD gateway reads "Pay at pickup"
 * on a pickup order and "Pay on delivery" on a delivery order, from
 * Customizer strings with translatable defaults.
 *
 * @package Lafka_Plugin
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Lafka_Payment_Labels;
use LafkaPlugin\Tests\Unit\Support\Hooks;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Support/Hooks.php';

final class PaymentLabelsTest extends TestCase {

	/** @var array<string, mixed> */
	private array $theme_mods = array();

	/** @var array<string, mixed> */
	private array $session = array();

	/** @var array<string, callable> */
	private array $filters = array();

	private bool $admin = false;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'is_admin' )->alias( fn() => $this->admin );
		Functions\when( 'wp_doing_ajax' )->justReturn( false );
		Functions\when( 'get_theme_mod' )->alias( fn( $key, $fallback = false ) => $this->theme_mods[ $key ] ?? $fallback );
		Functions\when( 'apply_filters' )->alias(
			function ( $hook, $value, ...$args ) {
				return isset( $this->filters[ $hook ] ) ? ( $this->filters[ $hook ] )( $value, ...$args ) : $value;
			}
		);
		$session = new class( $this ) {
			public function __construct( private PaymentLabelsTest $test ) {}
			public function get( $key ) {
				return $this->test->session_get( $key );
			}
		};
		Functions\when( 'WC' )->justReturn( (object) array( 'session' => $session ) );
		require_once dirname( __DIR__, 2 ) . '/incl/lafka-shipping-method-helpers.php';
		require_once dirname( __DIR__, 2 ) . '/incl/checkout/class-lafka-payment-labels.php';
		$_POST = array();
	}

	protected function tearDown(): void {
		$_POST = array();
		Monkey\tearDown();
		parent::tearDown();
	}

	/** @return mixed */
	public function session_get( string $key ) {
		return $this->session[ $key ] ?? null;
	}

	private function ship( string $rate ): void {
		$this->session['chosen_shipping_methods'] = array( $rate );
	}

	public function test_cod_reads_pay_at_pickup_on_a_pickup_order(): void {
		$this->ship( 'local_pickup:9' );

		$this->assertSame( 'Pay at pickup', Lafka_Payment_Labels::filter_title( 'Cash on delivery', 'cod' ) );
		$this->assertSame( 'Pay when you collect your order.', Lafka_Payment_Labels::filter_description( 'Pay with cash upon delivery.', 'cod' ) );
	}

	public function test_cod_reads_pay_on_delivery_on_a_delivery_order(): void {
		$this->ship( 'distance_rate:8' );

		$this->assertSame( 'Pay on delivery', Lafka_Payment_Labels::filter_title( 'Cash on delivery', 'cod' ) );
		$this->assertSame( 'Pay when your order arrives.', Lafka_Payment_Labels::filter_description( 'x', 'cod' ) );
	}

	public function test_the_classic_order_review_refresh_uses_the_posted_choice(): void {
		$this->ship( 'distance_rate:8' );
		$_POST['shipping_method'] = array( 'local_pickup:9' );

		$this->assertSame( 'Pay at pickup', Lafka_Payment_Labels::filter_title( 'Cash on delivery', 'cod' ) );
	}

	public function test_customizer_strings_override_the_defaults(): void {
		$this->theme_mods['lafka_cod_title_pickup']       = 'Pay at the counter';
		$this->theme_mods['lafka_cod_description_pickup'] = 'Cash or card when you collect.';
		$this->ship( 'local_pickup:9' );

		$this->assertSame( 'Pay at the counter', Lafka_Payment_Labels::filter_title( 'Cash on delivery', 'cod' ) );
		$this->assertSame( 'Cash or card when you collect.', Lafka_Payment_Labels::filter_description( 'x', 'cod' ) );
	}

	public function test_the_title_is_filterable_per_context(): void {
		$this->filters['lafka_cod_title'] = static fn( $title, $context ) => strtoupper( $context ) . ': ' . $title;
		$this->ship( 'local_pickup:9' );

		$this->assertSame( 'PICKUP: Pay at pickup', Lafka_Payment_Labels::filter_title( 'Cash on delivery', 'cod' ) );
	}

	public function test_other_gateways_unknown_context_admin_and_switched_off_are_untouched(): void {
		$this->ship( 'local_pickup:9' );
		$this->assertSame( 'Credit Card', Lafka_Payment_Labels::filter_title( 'Credit Card', 'card_gateway' ), 'Other gateway.' );

		$this->theme_mods['lafka_cod_contextual_title'] = '0';
		$this->assertSame( 'Cash on delivery', Lafka_Payment_Labels::filter_title( 'Cash on delivery', 'cod' ), 'Switched off.' );
		unset( $this->theme_mods['lafka_cod_contextual_title'] );

		$this->admin = true;
		$this->assertSame( 'Cash on delivery', Lafka_Payment_Labels::filter_title( 'Cash on delivery', 'cod' ), 'Admin screens show the configured title.' );
		$this->admin = false;

		$this->session = array();
		$this->assertSame( 'Cash on delivery', Lafka_Payment_Labels::filter_title( 'Cash on delivery', 'cod' ), 'Nothing chosen yet.' );
	}

	public function test_hooks_filter_the_gateway_title_and_description(): void {
		Hooks::reset();

		Lafka_Payment_Labels::init();

		$this->assertSame(
			array(
				'woocommerce_gateway_title -> filter_title',
				'woocommerce_gateway_description -> filter_description',
			),
			Hooks::registered()
		);
	}
}
