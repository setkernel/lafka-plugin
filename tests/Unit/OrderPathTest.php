<?php
/**
 * Order-path QA fixes (Lafka_Order_Path): the shipping-choice heading, card
 * CSC autocomplete, the checkout phone-digits check and the speculative
 * loading exclusions.
 *
 * @package Lafka_Plugin
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Lafka_Order_Path;
use LafkaPlugin\Tests\Unit\Support\Hooks;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Support/Hooks.php';

final class OrderPathTest extends TestCase {

	/** @var array<string, callable> */
	private array $filters = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->filters = array();
		Functions\when( '__' )->returnArg();
		Functions\when( 'apply_filters' )->alias(
			fn( $hook, $value, ...$args ) => isset( $this->filters[ $hook ] ) ? ( $this->filters[ $hook ] )( $value, ...$args ) : $value
		);
		require_once dirname( __DIR__, 2 ) . '/incl/checkout/class-lafka-order-path.php';
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_the_shipping_row_names_the_choice_the_store_offers(): void {
		$this->assertSame( 'Pickup or delivery', Lafka_Order_Path::label_for_modes( array( 'pickup', 'delivery' ), 'Shipment' ) );
		$this->assertSame( 'Pickup', Lafka_Order_Path::label_for_modes( array( 'pickup' ), 'Shipment' ) );
		$this->assertSame( 'Delivery', Lafka_Order_Path::label_for_modes( array( 'delivery' ), 'Shipment' ) );
		$this->assertSame( 'Shipment', Lafka_Order_Path::label_for_modes( array(), 'Shipment' ), 'No fulfilment info: WooCommerce decides.' );
		$this->assertSame( 'Shipment 2', Lafka_Order_Path::package_name( 'Shipment 2', 1 ), 'A second package keeps its own name.' );

		$this->filters['lafka_shipping_package_name'] = static fn() => 'How do you want it?';
		$this->assertSame( 'How do you want it?', Lafka_Order_Path::label_for_modes( array( 'pickup', 'delivery' ), 'Shipment' ) );
	}

	public function test_card_security_codes_can_be_autofilled(): void {
		$skyverge = array(
			'input_class'       => array( 'js-sv-wc-payment-gateway-credit-card-form-input js-sv-wc-payment-gateway-credit-card-form-csc' ),
			'custom_attributes' => array(
				'autocomplete' => 'off',
				'spellcheck'   => 'no',
			),
		);
		$args     = Lafka_Order_Path::card_field_args( $skyverge, 'wc-any-gateway-csc' );

		$this->assertSame( 'cc-csc', $args['custom_attributes']['autocomplete'] );
		$this->assertSame( 'no', $args['custom_attributes']['spellcheck'] );

		$other = array( 'custom_attributes' => array( 'autocomplete' => 'off' ) );
		$this->assertSame( $other, Lafka_Order_Path::card_field_args( $other, 'billing_company' ), 'Other fields are untouched.' );
	}

	/** @return object WP_Error-like recorder. */
	private static function errors(): object {
		return new class() {
			/** @var array<string, array{0: string, 1: mixed}> */
			public array $added = array();
			public function add( $code, $message, $data = '' ) {
				$this->added[ $code ] = array( $message, $data );
			}
			public function get_error_messages( $code ) {
				return isset( $this->added[ $code ] ) ? array( $this->added[ $code ][0] ) : array();
			}
		};
	}

	public function test_a_phone_with_too_few_digits_is_refused_on_the_field(): void {
		$errors = self::errors();
		Lafka_Order_Path::validate_phone( array( 'billing_phone' => '12' ), $errors );

		$this->assertArrayHasKey( 'billing_phone_validation', $errors->added );
		$this->assertSame( array( 'id' => 'billing_phone' ), $errors->added['billing_phone_validation'][1], 'Inline error on the phone field.' );
	}

	public function test_real_phone_numbers_and_an_empty_field_pass(): void {
		foreach ( array( '(555) 010-0000', '+1 555 010 0000', '555-0100', '' ) as $phone ) {
			$errors = self::errors();
			Lafka_Order_Path::validate_phone( array( 'billing_phone' => $phone ), $errors );
			$this->assertSame( array(), $errors->added, $phone );
		}
	}

	public function test_the_minimum_is_filterable_and_can_be_switched_off(): void {
		$this->filters['lafka_checkout_phone_min_digits'] = static fn() => 0;
		$errors = self::errors();
		Lafka_Order_Path::validate_phone( array( 'billing_phone' => '1' ), $errors );

		$this->assertSame( array(), $errors->added );
	}

	public function test_speculative_loading_never_prefetches_the_order_pages(): void {
		Functions\when( 'wc_get_page_id' )->alias( static fn( $page ) => 'myaccount' === $page ? -1 : 5 );
		Functions\when( 'wc_get_page_permalink' )->alias( static fn( $page ) => 'https://example.test/shop/' . $page . '/' );
		Functions\when( 'home_url' )->alias( static fn( $path = '' ) => 'https://example.test/shop' . $path );
		Functions\when( 'wp_parse_url' )->alias( static fn( $url, $component = -1 ) => parse_url( $url, $component ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url

		$paths = Lafka_Order_Path::speculation_exclusions( array( '/wp-admin/*' ) );

		$this->assertSame(
			array( '/wp-admin/*', '/cart', '/cart/*', '/checkout', '/checkout/*', '/*\\?*remove_item=*', '/*\\?*add-to-cart=*' ),
			$paths,
			'Relative to the home path (a subdirectory install is not doubled); no account page, no entry.'
		);
	}

	public function test_hooks(): void {
		Hooks::reset();

		Lafka_Order_Path::init();

		$this->assertSame(
			array(
				'woocommerce_shipping_package_name -> package_name',
				'woocommerce_form_field_args -> card_field_args',
				'woocommerce_after_checkout_validation -> validate_phone',
				'wp_speculation_rules_href_exclude_paths -> speculation_exclusions',
				'woocommerce_checkout_order_processed -> restore_order_street',
			),
			Hooks::registered()
		);
	}
}
