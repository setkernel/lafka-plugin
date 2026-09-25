<?php
/**
 * GX1 / A7: WooCommerce-owned refusals become `lafka_checkout_blocked`
 * reasons — classic validation codes (never values), Store API checkout error
 * responses, and payment failures classified declined / avs / cvv /
 * gateway_error / other — and every reason is logged + counted.
 *
 * @package Lafka_Plugin
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Lafka_Checkout_Block_Reasons;
use Lafka_Checkout_Failures;
use Lafka_Log;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/incl/observability/class-lafka-checkout-block-reasons.php';
require_once dirname( __DIR__, 2 ) . '/incl/observability/class-lafka-log-scrubber.php';
require_once dirname( __DIR__, 2 ) . '/incl/observability/class-lafka-log.php';
require_once dirname( __DIR__, 2 ) . '/incl/observability/class-lafka-checkout-failures.php';

final class CheckoutFailuresTest extends TestCase {

	/** @var array<int,array{0:string,1:array}> */
	private array $blocked = array();

	/** @var array<string,mixed> */
	private array $options = array();

	/** @var array<int,array{level:string,message:string,context:array}> */
	private array $logged = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Lafka_Checkout_Block_Reasons::reset();
		Lafka_Checkout_Failures::reset();
		Lafka_Log::reset();
		$this->blocked = array();
		$this->options = array( 'lafka_log_settings' => array( 'min_level' => 'debug' ) );
		$this->logged  = array();

		Functions\when( 'do_action' )->alias(
			function ( $hook, ...$args ) {
				if ( 'lafka_checkout_blocked' === $hook ) {
					$this->blocked[] = array( $args[0], $args[1] );
				}
			}
		);
		Functions\when( 'get_option' )->alias( fn( $key, $default = false ) => $this->options[ $key ] ?? $default );
		Functions\when( 'update_option' )->alias(
			function ( $key, $value ) {
				$this->options[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'UTC' ) );
		$test = $this;
		Lafka_Log::set_logger(
			new class( $test ) {
				public function __construct( private $test ) {}
				public function log( $level, $message, $context = array() ): void {
					$this->test->capture( $level, $message, $context );
				}
			}
		);
	}

	protected function tearDown(): void {
		// on_rest_before() marks the request as a Store API checkout in a
		// static; leaving it set turned later tests' 'cart' stage into 'checkout'.
		Lafka_Checkout_Failures::reset();
		Lafka_Checkout_Block_Reasons::reset();
		Lafka_Log::reset();
		Monkey\tearDown();
		parent::tearDown();
	}

	/** Logger double sink. */
	public function capture( $level, $message, $context ): void {
		$this->logged[] = array(
			'level'   => $level,
			'message' => $message,
			'context' => $context,
		);
	}

	/** @return array<int,string> */
	private function reasons(): array {
		return array_column( $this->blocked, 0 );
	}

	private static function request( string $route, string $method = 'POST' ): object {
		return new class( $route, $method ) {
			public function __construct( private string $route, private string $method ) {}
			public function get_route() {
				return $this->route;
			}
			public function get_method() {
				return $this->method;
			}
		};
	}

	private static function rest_error( int $status, array $data ): object {
		return new class( $status, $data ) {
			public function __construct( private int $status, private array $data ) {}
			public function get_status() {
				return $this->status;
			}
			public function get_data() {
				return $this->data;
			}
		};
	}

	// ─── Classic validation ─────────────────────────────────────────────────

	public function test_classic_validation_reports_codes_never_values(): void {
		$errors = new class() {
			public function get_error_codes() {
				return array( 'billing_phone_required', 'billing_email_validation', 'shipping' );
			}
		};

		Lafka_Checkout_Failures::on_classic_validation( array( 'billing_email' => 'jane@example.com' ), $errors );

		self::assertSame( array( 'no_shipping_method', 'field_validation' ), $this->reasons() );
		$context = $this->blocked[1][1];
		self::assertSame( array( 'billing_phone_required', 'billing_email_validation' ), $context['fields'] );
		self::assertStringNotContainsString( 'jane@example.com', (string) json_encode( $this->blocked ) );
	}

	public function test_classic_validation_without_errors_reports_nothing(): void {
		$errors = new class() {
			public function get_error_codes() {
				return array();
			}
		};

		Lafka_Checkout_Failures::on_classic_validation( array(), $errors );

		self::assertSame( array(), $this->blocked );
	}

	// ─── Store API checkout responses ───────────────────────────────────────

	public function test_store_api_field_errors_become_field_validation(): void {
		$response = self::rest_error(
			400,
			array(
				'code'    => 'rest_invalid_param',
				'message' => 'Invalid parameter(s): billing_address',
				'data'    => array(
					'status'  => 400,
					'params'  => array( 'billing_address' => 'Invalid email jane@example.com' ),
					'details' => array( 'billing_address' => array( 'code' => 'invalid_email' ) ),
				),
			)
		);

		Lafka_Checkout_Failures::on_rest_after( $response, null, self::request( '/wc/store/v1/checkout' ) );

		self::assertSame( array( 'field_validation' ), $this->reasons() );
		self::assertSame( 'store_api', $this->blocked[0][1]['path'] );
		self::assertSame( array( 'billing_address', 'invalid_email' ), $this->blocked[0][1]['fields'] );
	}

	public function test_store_api_lafka_codes_map_to_their_reasons(): void {
		$response = self::rest_error(
			409,
			array(
				'code'              => 'lafka_store_closed',
				'message'           => 'Closed',
				'additional_errors' => array(
					array( 'code' => 'lafka_invalid_timeslot', 'message' => 'Full' ),
				),
			)
		);

		Lafka_Checkout_Failures::on_rest_after( $response, null, self::request( '/wc/store/checkout' ) );

		self::assertSame( array( 'store_closed', 'timeslot_invalid' ), $this->reasons() );
	}

	public function test_store_api_payment_error_is_classified(): void {
		$response = self::rest_error(
			400,
			array(
				'code'    => 'woocommerce_rest_checkout_process_payment_error',
				'message' => 'The transaction has been declined because of an AVS mismatch.',
			)
		);

		Lafka_Checkout_Failures::on_rest_after( $response, null, self::request( '/wc/store/v1/checkout' ) );

		self::assertSame( array( 'payment_avs' ), $this->reasons() );
		self::assertSame( 'payment', $this->blocked[0][1]['stage'] );
	}

	public function test_unknown_store_api_errors_become_store_api_error_and_5xx_is_logged(): void {
		$response = self::rest_error( 500, array( 'code' => 'woocommerce_rest_cart_error', 'message' => 'x' ) );

		Lafka_Checkout_Failures::on_rest_after( $response, null, self::request( '/wc/store/v1/checkout' ) );

		self::assertSame( array( 'store_api_error' ), $this->reasons() );
		self::assertContains( 'lafka-store-api', array_map( static fn( $r ) => $r['context']['source'], $this->logged ) );
	}

	public function test_non_checkout_or_successful_responses_are_ignored(): void {
		$error = self::rest_error( 400, array( 'code' => 'lafka_store_closed' ) );

		Lafka_Checkout_Failures::on_rest_after( $error, null, self::request( '/wc/store/v1/cart' ) );
		Lafka_Checkout_Failures::on_rest_after( $error, null, self::request( '/wc/store/v1/checkout', 'GET' ) );
		Lafka_Checkout_Failures::on_rest_after( self::rest_error( 200, array( 'order_id' => 5 ) ), null, self::request( '/wc/store/v1/checkout' ) );

		self::assertSame( array(), $this->blocked );
		self::assertFalse( Lafka_Checkout_Failures::in_store_api_checkout() );
		Lafka_Checkout_Failures::on_rest_before( null, null, self::request( '/wc/store/v1/checkout' ) );
		self::assertTrue( Lafka_Checkout_Failures::in_store_api_checkout() );
	}

	// ─── Payment failure classifier ─────────────────────────────────────────

	/**
	 * @return array<string,array{0:string,1:string}>
	 */
	public static function gateway_notes(): array {
		return array(
			'authorize.net AVS'   => array( 'Authorize.Net Credit Card Payment Failed (Status code 2: Error Code: 27 - The transaction has been declined because of an AVS mismatch. The address provided does not match billing address of cardholder. Transaction ID 1)', 'avs' ),
			'authorize.net plain' => array( 'Authorize.Net Credit Card Payment Failed (Status code 2: Error Code: 2 - This transaction has been declined. Transaction ID 1)', 'declined' ),
			'stripe cvc'          => array( 'Your card\'s security code is incorrect. (incorrect_cvc)', 'cvv' ),
			'cvv word'            => array( 'Declined: CVV2 mismatch', 'cvv' ),
			'insufficient funds'  => array( 'Payment failed: insufficient_funds', 'declined' ),
			'gateway timeout'     => array( 'Payment failed: Connection timed out after 30001 milliseconds', 'gateway_error' ),
			'zip check'           => array( 'The zip code you supplied failed validation.', 'avs' ),
			'unknown'             => array( 'Order status changed from Pending payment to Failed.', 'other' ),
			'empty'               => array( '', 'other' ),
		);
	}

	#[DataProvider( 'gateway_notes' )]
	public function test_payment_failures_are_classified( string $note, string $expected ): void {
		self::assertSame( $expected, Lafka_Checkout_Failures::classify_payment_failure( $note ) );
	}

	public function test_keyword_map_is_filterable(): void {
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value ) {
				if ( 'lafka_payment_failure_keywords' === $hook ) {
					$value['declined'][] = 'nicht autorisiert';
				}
				return $value;
			}
		);

		self::assertSame( 'declined', Lafka_Checkout_Failures::classify_payment_failure( 'Zahlung nicht autorisiert' ) );
	}

	// ─── Payment failure hooks ──────────────────────────────────────────────

	private static function order( int $id, string $status = 'failed', string $gateway = 'authorize_net_cim_credit_card' ): object {
		return new class( $id, $status, $gateway ) {
			public function __construct( private int $id, private string $status, private string $gateway ) {}
			public function get_id() {
				return $this->id;
			}
			public function get_status() {
				return $this->status;
			}
			public function get_payment_method() {
				return $this->gateway;
			}
		};
	}

	public function test_status_transition_to_failed_emits_a_classified_payment_reason(): void {
		Lafka_Checkout_Failures::on_order_failed(
			41,
			self::order( 41 ),
			array( 'note' => 'Payment Failed (Error Code: 27 - declined because of an AVS mismatch)' )
		);

		self::assertSame( array( 'payment_avs' ), $this->reasons() );
		$context = $this->blocked[0][1];
		self::assertSame( 41, $context['order_id'] );
		self::assertSame( 'authorize_net_cim_credit_card', $context['gateway'] );
		self::assertSame( 'avs', $context['class'] );
		self::assertSame( 'order', $context['path'] );
	}

	public function test_manual_status_changes_are_not_payment_failures(): void {
		Lafka_Checkout_Failures::on_order_failed( 41, self::order( 41 ), array( 'manual' => true, 'note' => 'declined' ) );

		self::assertSame( array(), $this->blocked );
	}

	public function test_retry_note_on_an_already_failed_order_is_reported_once_per_request(): void {
		Functions\when( 'get_comment' )->justReturn( (object) array( 'comment_content' => 'Payment Failed (This transaction has been declined.)' ) );
		Functions\when( 'is_admin' )->justReturn( false );

		Lafka_Checkout_Failures::on_order_note_added( 9, self::order( 41 ) );
		Lafka_Checkout_Failures::on_order_note_added( 10, self::order( 41 ) );
		Lafka_Checkout_Failures::on_order_note_added( 11, self::order( 42, 'pending' ) );

		self::assertSame( array( 'payment_declined' ), $this->reasons() );
	}

	public function test_notes_typed_on_the_order_screen_are_ignored(): void {
		Functions\when( 'get_comment' )->justReturn( (object) array( 'comment_content' => 'Customer said the card was declined' ) );
		Functions\when( 'is_admin' )->justReturn( true );
		Functions\when( 'wp_doing_ajax' )->justReturn( false );

		Lafka_Checkout_Failures::on_order_note_added( 9, self::order( 41 ) );

		self::assertSame( array(), $this->blocked );
	}

	// ─── Recorder ───────────────────────────────────────────────────────────

	public function test_payment_reasons_log_a_warning_on_the_payment_channel(): void {
		Lafka_Checkout_Failures::on_blocked( 'payment_avs', array( 'class' => 'avs', 'order_id' => 41, 'path' => 'order' ) );

		self::assertSame( 'warning', $this->logged[0]['level'] );
		self::assertSame( 'lafka-payment', $this->logged[0]['context']['source'] );
		self::assertSame( 'payment_avs', $this->logged[0]['context']['code'] );
	}

	public function test_checkout_reasons_log_a_notice_on_the_checkout_channel_and_are_counted(): void {
		Lafka_Checkout_Failures::on_blocked( 'store_closed', array( 'path' => 'classic' ) );
		Lafka_Checkout_Failures::on_blocked( 'store_closed', array( 'path' => 'classic' ) );
		Lafka_Checkout_Failures::on_blocked( 'payment_declined', array( 'path' => 'order' ) );
		Lafka_Checkout_Failures::on_blocked( 'bogus', array() );

		self::assertSame( 'notice', $this->logged[0]['level'] );
		self::assertSame( 'lafka-checkout', $this->logged[0]['context']['source'] );
		self::assertSame(
			array(
				'store_closed'     => 2,
				'payment_declined' => 1,
			),
			Lafka_Checkout_Failures::totals( 30 )
		);
		self::assertSame( 1, Lafka_Checkout_Failures::payment_failures( 7 ) );
	}

	public function test_counter_window_excludes_old_days(): void {
		$old   = ( new \DateTimeImmutable( '-40 days', new \DateTimeZone( 'UTC' ) ) )->format( 'Y-m-d' );
		$week  = ( new \DateTimeImmutable( '-10 days', new \DateTimeZone( 'UTC' ) ) )->format( 'Y-m-d' );
		$stats = array(
			$old  => array( 'payment_avs' => 5 ),
			$week => array( 'payment_avs' => 2 ),
		);

		self::assertSame( array( 'payment_avs' => 2 ), Lafka_Checkout_Failures::totals( 30, $stats ) );
		self::assertSame( array(), Lafka_Checkout_Failures::totals( 7, $stats ) );
	}
}
