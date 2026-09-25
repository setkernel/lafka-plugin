<?php
/**
 * Pickup checkout slimming (GX0): a pickup order paid with a method that does
 * not verify the billing address asks only for name, phone and email. The
 * billing address stays required for delivery and for gateways that check it
 * (card AVS), and the base country/state fill in when left empty.
 *
 * @package Lafka_Plugin
 */

declare(strict_types=1);

namespace Automattic\WooCommerce\StoreApi\Exceptions {
	if ( ! class_exists( RouteException::class ) ) {
		class RouteException extends \RuntimeException { // phpcs:ignore
			public string $error_code;
			public function __construct( $error_code, $message, $http_status_code = 400 ) {
				$this->error_code = (string) $error_code;
				parent::__construct( (string) $message, (int) $http_status_code );
			}
			public function getErrorCode(): string {
				return $this->error_code;
			}
		}
	}
}

namespace LafkaPlugin\Tests\Unit {

	use Automattic\WooCommerce\StoreApi\Exceptions\RouteException;
	use Brain\Monkey;
	use Brain\Monkey\Functions;
	use Lafka_Pickup_Checkout;
	use LafkaPlugin\Tests\Unit\Support\Hooks;
	use PHPUnit\Framework\Attributes\DataProvider;
	use PHPUnit\Framework\TestCase;

	require_once __DIR__ . '/Support/Hooks.php';

	final class PickupCheckoutTest extends TestCase {

		/** @var array<string, mixed> */
		private array $theme_mods = array();

		/** @var array<string, mixed> */
		private array $options = array();

		/** @var array<string, mixed> WC session contents. */
		private array $session = array();

		/** @var string[] Countries the store sells to. */
		private array $allowed = array( 'CA' );

		/** @var array<string, callable> */
		private array $filters = array();

		protected function setUp(): void {
			parent::setUp();
			Monkey\setUp();
			Functions\when( '__' )->returnArg();
			Functions\when( 'esc_html' )->returnArg();
			Functions\when( 'esc_html__' )->returnArg();
			Functions\when( 'sanitize_text_field' )->returnArg();
			Functions\when( 'wp_unslash' )->returnArg();
			Functions\when( 'get_theme_mod' )->alias( fn( $key, $fallback = false ) => $this->theme_mods[ $key ] ?? $fallback );
			Functions\when( 'get_option' )->alias( fn( $key, $fallback = false ) => $this->options[ $key ] ?? $fallback );
			Functions\when( 'apply_filters' )->alias(
				function ( $hook, $value, ...$args ) {
					return isset( $this->filters[ $hook ] ) ? ( $this->filters[ $hook ] )( $value, ...$args ) : $value;
				}
			);
			$session   = new class( $this ) {
				public function __construct( private PickupCheckoutTest $test ) {}
				public function get( $key ) {
					return $this->test->session_get( $key );
				}
				public function set( $key, $value ) {}
			};
			$countries = new class( $this ) {
				public function __construct( private PickupCheckoutTest $test ) {}
				public function get_base_country() {
					return 'CA';
				}
				public function get_base_state() {
					return 'NS';
				}
				public function get_allowed_countries() {
					return array_fill_keys( $this->test->allowed(), 'Country' );
				}
			};
			Functions\when( 'WC' )->justReturn(
				(object) array(
					'session'   => $session,
					'countries' => $countries,
					'cart'      => null,
				)
			);
			require_once dirname( __DIR__, 2 ) . '/incl/lafka-shipping-method-helpers.php';
			require_once dirname( __DIR__, 2 ) . '/incl/checkout/class-lafka-checkout-mode.php';
			require_once dirname( __DIR__, 2 ) . '/incl/checkout/class-lafka-pickup-checkout.php';
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

		/** @return string[] */
		public function allowed(): array {
			return $this->allowed;
		}

		/** WooCommerce's classic billing fields, as the checkout builds them. */
		private static function billing_fields(): array {
			$fields = array();
			foreach ( array(
				'billing_first_name' => true,
				'billing_last_name'  => true,
				'billing_company'    => false,
				'billing_country'    => true,
				'billing_address_1'  => true,
				'billing_address_2'  => false,
				'billing_city'       => true,
				'billing_state'      => true,
				'billing_postcode'   => true,
				'billing_phone'      => true,
				'billing_email'      => true,
			) as $key => $required ) {
				$fields[ $key ] = array(
					'required' => $required,
					'class'    => array( 'form-row-wide' ),
				);
			}
			return array( 'billing' => $fields );
		}

		/** @return array<string, bool> field => required, after the filter. */
		private function required_after_filter(): array {
			$out = array();
			foreach ( Lafka_Pickup_Checkout::filter_checkout_fields( self::billing_fields() )['billing'] as $key => $field ) {
				$out[ $key ] = (bool) $field['required'];
			}
			return $out;
		}

		private function choose( string $shipping, string $gateway ): void {
			$this->session['chosen_shipping_methods'] = array( $shipping );
			$this->session['chosen_payment_method']   = $gateway;
		}

		/* ------------------------------------------------------------ *
		 *  Classic checkout fields
		 * ------------------------------------------------------------ */

		public function test_pickup_paid_on_collection_needs_only_name_phone_and_email(): void {
			$this->choose( 'local_pickup:9', 'cod' );

			$required = array_keys( array_filter( $this->required_after_filter() ) );

			$this->assertSame( array( 'billing_first_name', 'billing_last_name', 'billing_phone', 'billing_email' ), $required );
		}

		public function test_slimmable_fields_are_tagged_for_the_client_toggle(): void {
			$this->choose( 'flat_rate:1', 'cod' );

			$fields = Lafka_Pickup_Checkout::filter_checkout_fields( self::billing_fields() )['billing'];

			$this->assertContains( 'lafka-pickup-slim-field', $fields['billing_address_1']['class'] );
			$this->assertContains( 'lafka-pickup-slim-field', $fields['billing_state']['class'] );
			$this->assertNotContains( 'lafka-pickup-slim-field', $fields['billing_phone']['class'] );
		}

		public function test_delivery_keeps_the_full_address(): void {
			$this->choose( 'distance_rate:8', 'cod' );

			$this->assertSame( array_map( static fn( $f ) => $f['required'], self::billing_fields()['billing'] ), $this->required_after_filter() );
		}

		public function test_a_card_gateway_keeps_the_billing_address_for_avs(): void {
			$this->choose( 'local_pickup:9', 'authorize_net_cim_credit_card' );

			$this->assertTrue( $this->required_after_filter()['billing_address_1'] );
			$this->assertTrue( $this->required_after_filter()['billing_postcode'] );
		}

		public function test_the_posted_checkout_choice_wins_over_the_session(): void {
			$this->choose( 'distance_rate:8', 'authorize_net_cim_credit_card' );
			$_POST = array(
				'shipping_method' => array( 'pickup_location:0' ),
				'payment_method'  => 'cod',
			);

			$this->assertFalse( $this->required_after_filter()['billing_address_1'] );
		}

		public function test_the_lafka_order_type_decides_over_the_shipping_method(): void {
			$this->choose( 'flat_rate:1', 'cod' );
			$this->session['lafka_branch_location'] = array( 'order_type' => 'pickup' );
			$this->assertFalse( $this->required_after_filter()['billing_address_1'], 'Lafka pickup order type.' );

			$this->choose( 'local_pickup:9', 'cod' );
			$this->session['lafka_branch_location'] = array( 'order_type' => 'delivery' );
			$this->assertTrue( $this->required_after_filter()['billing_address_1'], 'Lafka delivery order type.' );
		}

		public function test_country_and_state_stay_when_the_store_sells_abroad(): void {
			$this->allowed = array( 'CA', 'US' );
			$this->choose( 'local_pickup:9', 'cod' );

			$required = $this->required_after_filter();

			$this->assertFalse( $required['billing_address_1'] );
			$this->assertTrue( $required['billing_country'] );
			$this->assertTrue( $required['billing_state'] );
		}

		public function test_the_operator_can_switch_slimming_off(): void {
			$this->theme_mods['lafka_pickup_checkout_slim'] = '0';
			$this->choose( 'local_pickup:9', 'cod' );

			$this->assertSame( self::billing_fields(), Lafka_Pickup_Checkout::filter_checkout_fields( self::billing_fields() ) );
		}

		/**
		 * @return array<string, array{0: string, 1: bool}>
		 */
		public static function gateways(): array {
			return array(
				'cash'          => array( 'cod', false ),
				'cheque'        => array( 'cheque', false ),
				'bank transfer' => array( 'bacs', false ),
				'card'          => array( 'authorize_net_cim_credit_card', true ),
				'unknown'       => array( 'any_gateway', true ),
				'not chosen'    => array( '', true ),
			);
		}

		#[DataProvider( 'gateways' )]
		public function test_only_offline_gateways_skip_the_billing_address( string $gateway, bool $needs ): void {
			$this->assertSame( $needs, Lafka_Pickup_Checkout::gateway_needs_billing_address( $gateway ) );
		}

		public function test_gateway_requirement_is_filterable(): void {
			$this->filters['lafka_pickup_address_optional_gateways'] = static fn( $ids ) => array_merge( $ids, array( 'my_terminal' ) );
			$this->assertFalse( Lafka_Pickup_Checkout::gateway_needs_billing_address( 'my_terminal' ) );

			$this->filters['lafka_pickup_gateway_needs_billing_address'] = static fn( $needs, $id ) => 'cod' === $id ? true : $needs;
			$this->assertTrue( Lafka_Pickup_Checkout::gateway_needs_billing_address( 'cod' ) );
		}

		public function test_empty_billing_country_and_state_default_to_the_store_base(): void {
			$this->choose( 'local_pickup:9', 'cod' );

			$data = Lafka_Pickup_Checkout::fill_base_location(
				array(
					'billing_country' => '',
					'billing_state'   => '',
					'billing_email'   => 'a@example.test',
				)
			);

			$this->assertSame( 'CA', $data['billing_country'] );
			$this->assertSame( 'NS', $data['billing_state'] );

			$kept = Lafka_Pickup_Checkout::fill_base_location(
				array(
					'billing_country' => 'US',
					'billing_state'   => '',
				)
			);
			$this->assertSame( array( 'US', '' ), array( $kept['billing_country'], $kept['billing_state'] ), 'Another country keeps its own (empty) state.' );
		}

		/* ------------------------------------------------------------ *
		 *  Block checkout (Store API)
		 * ------------------------------------------------------------ */

		private function blocks_mode(): void {
			$this->options['lafka_checkout_mode'] = 'blocks';
		}

		public function test_block_checkout_makes_the_address_optional_for_every_country(): void {
			$this->blocks_mode();

			$locale = Lafka_Pickup_Checkout::relax_block_locale(
				array(
					'CA' => array( 'postcode' => array( 'label' => 'Postal code' ) ),
					'US' => array(),
				)
			);

			foreach ( array( 'CA', 'US' ) as $country ) {
				foreach ( array( 'address_1', 'city', 'postcode', 'state' ) as $key ) {
					$this->assertFalse( $locale[ $country ][ $key ]['required'], "{$country} {$key}" );
				}
			}
			$this->assertSame( 'Postal code', $locale['CA']['postcode']['label'], 'Existing locale data is kept.' );
		}

		public function test_classic_mode_leaves_the_locale_alone(): void {
			$this->options['lafka_checkout_mode'] = 'classic';
			$locale                                = array( 'CA' => array() );

			$this->assertSame( $locale, Lafka_Pickup_Checkout::relax_block_locale( $locale ) );
		}

		private function order( array $billing, array $shipping = array() ): object {
			return new class( $billing, $shipping ) {
				public array $set = array();
				public function __construct( private array $billing, private array $shipping ) {}
				public function get_address( $type = 'billing' ) {
					return 'billing' === $type ? $this->billing : $this->shipping;
				}
				public function get_payment_method() {
					return '';
				}
				public function set_billing_country( $v ) {
					$this->set['billing_country'] = $v;
				}
				public function set_billing_state( $v ) {
					$this->set['billing_state'] = $v;
				}
			};
		}

		private function store_api_error( object $order, string $gateway ): ?string {
			try {
				Lafka_Pickup_Checkout::on_store_api_checkout( $order, new \ArrayObject( array( 'payment_method' => $gateway ) ) );
			} catch ( RouteException $e ) {
				return $e->getErrorCode();
			}
			return null;
		}

		public function test_block_pickup_with_cash_places_without_an_address(): void {
			$this->blocks_mode();
			$this->choose( 'pickup_location:0', 'cod' );
			$order = $this->order( array( 'country' => '' ) );

			$this->assertNull( $this->store_api_error( $order, 'cod' ) );
			$this->assertSame(
				array(
					'billing_country' => 'CA',
					'billing_state'   => 'NS',
				),
				$order->set
			);
		}

		public function test_block_card_payment_still_needs_the_billing_address(): void {
			$this->blocks_mode();
			$this->choose( 'pickup_location:0', 'authorize_net_cim_credit_card' );

			$this->assertSame( 'lafka_billing_address_required', $this->store_api_error( $this->order( array( 'country' => 'CA' ) ), 'authorize_net_cim_credit_card' ) );
			$this->assertNull(
				$this->store_api_error(
					$this->order(
						array(
							'country'   => 'CA',
							'address_1' => '1 Example St',
							'city'      => 'Town',
							'postcode'  => 'A1A 1A1',
						)
					),
					'authorize_net_cim_credit_card'
				)
			);
		}

		public function test_block_delivery_still_needs_the_billing_address(): void {
			$this->blocks_mode();
			$this->choose( 'flat_rate:1', 'cod' );

			$this->assertSame( 'lafka_billing_address_required', $this->store_api_error( $this->order( array( 'country' => 'CA' ) ), 'cod' ) );
		}

		public function test_hooks_cover_classic_fields_posted_data_script_and_the_block_path(): void {
			Hooks::reset();

			Lafka_Pickup_Checkout::init();

			$this->assertSame(
				array(
					'woocommerce_checkout_fields -> filter_checkout_fields',
					'woocommerce_checkout_posted_data -> fill_base_location',
					'wp_enqueue_scripts -> enqueue_script',
					'woocommerce_after_checkout_form -> print_client_config',
					'woocommerce_get_country_locale -> relax_block_locale',
					'woocommerce_get_country_locale_default -> relax_block_locale_fields',
					'woocommerce_get_country_locale_base -> relax_block_locale_fields',
					'woocommerce_store_api_checkout_update_order_from_request -> on_store_api_checkout',
				),
				Hooks::registered()
			);
		}
	}
}
