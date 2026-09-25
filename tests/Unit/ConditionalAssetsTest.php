<?php
/**
 * T-25 (GX): third-party front-end assets load only where they can run —
 *
 *   - Contact Form 7's JS/CSS only on a page whose content embeds a form
 *     (shortcode or block), through CF7's own `wpcf7_load_js/css` filters,
 *     with a late enqueue when a form renders from anywhere else (widget,
 *     template) and an operator override filter;
 *   - payment-gateway styles/scripts (SkyVerge framework, Authorize.Net CIM)
 *     dequeued outside cart / checkout / account / order-pay, never touching
 *     express-pay handles, and only by the filterable handle patterns.
 *
 * @package Lafka\Plugin\Tests\Unit
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class ConditionalAssetsTest extends TestCase {

	/** @var list<array{0:string, 1:mixed, 2:int, 3:int}>|null */
	private static ?array $registrations = null;

	/** @var array<string, bool> */
	private array $is = array();

	/** @var array<string, mixed> */
	private array $filters = array();

	/** @var list<string> "style:handle" / "script:handle" */
	private array $dequeued = array();

	/** @var list<string> */
	private array $late = array();

	private string $content = '';

	private bool $script_enqueued = false;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->is              = array();
		$this->filters         = array();
		$this->dequeued        = array();
		$this->late            = array();
		$this->content         = '';
		$this->script_enqueued = false;

		foreach ( array( 'is_admin', 'is_singular', 'is_cart', 'is_checkout', 'is_account_page', 'is_checkout_pay_page', 'is_add_payment_method_page' ) as $tag ) {
			Functions\when( $tag )->alias( fn() => $this->is[ $tag ] ?? false );
		}
		Functions\when( 'is_wc_endpoint_url' )->alias( fn( $endpoint = '' ) => ! empty( $this->is[ 'endpoint:' . $endpoint ] ) );
		Functions\when( 'apply_filters' )->alias(
			fn( $hook, $value ) => array_key_exists( $hook, $this->filters ) ? $this->filters[ $hook ] : $value
		);
		Functions\when( 'get_post' )->alias( fn() => (object) array( 'post_content' => $this->content ) );
		Functions\when( 'wp_dequeue_style' )->alias( fn( $h ) => $this->dequeued[] = 'style:' . $h );
		Functions\when( 'wp_dequeue_script' )->alias( fn( $h ) => $this->dequeued[] = 'script:' . $h );
		Functions\when( 'wp_styles' )->justReturn(
			(object) array(
				'queue' => array(
					'sv-wc-payment-gateway-payment-form-v6_1_4',
					'wc-authorize-net-cim-credit-card-checkout-block',
					'wc-authorize-net-cim-echeck-checkout-block',
					'wc-authorize-net-cim-apple-pay',
					'lafka-style',
				),
			)
		);
		Functions\when( 'wp_scripts' )->justReturn(
			(object) array(
				'queue' => array( 'sv-wc-payment-gateway-payment-form-v6_1_4', 'wc-authorize-net-cim-google-pay', 'wc-cart-fragments' ),
			)
		);
		Functions\when( 'wp_script_is' )->alias( fn() => $this->script_enqueued );
		Functions\when( 'wpcf7_enqueue_scripts' )->alias( fn() => $this->late[] = 'scripts' );
		Functions\when( 'wpcf7_enqueue_styles' )->alias( fn() => $this->late[] = 'styles' );

		if ( null === self::$registrations ) {
			$before = count( $GLOBALS['lafka_test_hooks'] );
			require_once dirname( __DIR__, 2 ) . '/incl/perf/lafka-conditional-assets.php';
			self::$registrations = array_slice( $GLOBALS['lafka_test_hooks'], $before );
		}
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/** @return list<string> */
	private static function hooked(): array {
		return array_map( static fn( $r ) => $r[0] . ' -> ' . ( is_string( $r[1] ) ? $r[1] : '?' ), self::$registrations );
	}

	public function test_hooks_into_cf7_and_the_enqueue_and_print_stages(): void {
		$hooks = self::hooked();
		foreach ( array(
			'wpcf7_load_js -> lafka_cf7_load_assets',
			'wpcf7_load_css -> lafka_cf7_load_assets',
			'do_shortcode_tag -> lafka_cf7_late_enqueue',
			'wp_enqueue_scripts -> lafka_dequeue_gateway_assets',
			'wp_print_styles -> lafka_dequeue_gateway_assets',
			'wp_print_footer_scripts -> lafka_dequeue_gateway_assets',
		) as $expected ) {
			self::assertContains( $expected, $hooks );
		}
		foreach ( self::$registrations as $r ) {
			if ( 'wp_enqueue_scripts' === $r[0] ) {
				self::assertGreaterThanOrEqual( 100, $r[2], 'Runs after the gateways enqueue.' );
			}
		}
	}

	public function test_cf7_assets_load_only_where_the_content_embeds_a_form(): void {
		$this->is['is_singular'] = true;
		$this->content           = '<p>Welcome</p>';
		self::assertFalse( lafka_cf7_load_assets( true ) );

		foreach ( array( 'Hi [contact-form-7 id="12" title="Contact"]', '[contact-form 3 "Legacy"]', '<!-- wp:contact-form-7/contact-form-selector {"id":12} /-->' ) as $content ) {
			$this->content = $content;
			self::assertTrue( lafka_cf7_load_assets( true ), $content );
		}

		$this->is['is_singular'] = false;
		self::assertFalse( lafka_cf7_load_assets( true ), 'Archives embed no form content.' );
	}

	public function test_cf7_respects_an_explicit_off_and_the_operator_override(): void {
		$this->is['is_singular'] = true;
		$this->content           = '[contact-form-7 id="12"]';
		self::assertFalse( lafka_cf7_load_assets( false ), 'WPCF7_LOAD_JS=false stays false.' );

		$this->content                            = '';
		$this->filters['lafka_cf7_assets_needed'] = true;
		self::assertTrue( lafka_cf7_load_assets( true ) );
	}

	public function test_a_form_rendered_outside_the_content_enqueues_cf7_late(): void {
		self::assertSame( '<form/>', lafka_cf7_late_enqueue( '<form/>', 'contact-form-7' ) );
		self::assertSame( array( 'scripts', 'styles' ), $this->late );

		$this->late            = array();
		$this->script_enqueued = true;
		lafka_cf7_late_enqueue( '<form/>', 'contact-form' );
		self::assertSame( array(), $this->late, 'Already loaded — nothing to do.' );

		$this->script_enqueued = false;
		lafka_cf7_late_enqueue( '<b/>', 'gallery' );
		self::assertSame( array(), $this->late );
	}

	public function test_gateway_assets_are_dropped_off_the_payment_pages_except_express_pay(): void {
		lafka_dequeue_gateway_assets();
		self::assertSame(
			array(
				'style:sv-wc-payment-gateway-payment-form-v6_1_4',
				'style:wc-authorize-net-cim-credit-card-checkout-block',
				'style:wc-authorize-net-cim-echeck-checkout-block',
				'script:sv-wc-payment-gateway-payment-form-v6_1_4',
			),
			$this->dequeued
		);
	}

	public function test_gateway_assets_stay_where_a_payment_form_can_render(): void {
		foreach ( array( 'is_cart', 'is_checkout', 'is_account_page', 'is_checkout_pay_page', 'is_add_payment_method_page', 'endpoint:order-pay', 'endpoint:add-payment-method', 'is_admin' ) as $tag ) {
			$this->is = array( $tag => true );
			lafka_dequeue_gateway_assets();
			self::assertSame( array(), $this->dequeued, $tag );
		}
	}

	public function test_gateway_patterns_and_the_needed_decision_are_filterable(): void {
		$this->filters['lafka_gateway_asset_patterns'] = array( 'wc-authorize-net-cim-echeck' );
		lafka_dequeue_gateway_assets();
		self::assertSame( array( 'style:wc-authorize-net-cim-echeck-checkout-block' ), $this->dequeued );

		$this->dequeued                                = array();
		$this->filters['lafka_gateway_assets_needed'] = true;
		lafka_dequeue_gateway_assets();
		self::assertSame( array(), $this->dequeued );
	}
}
