<?php
/**
 * Global page_context dataLayer push: one server-rendered event with the
 * segmentation dimensions, emitted only when an analytics destination is
 * configured.
 *
 * @package Lafka\Plugin\Tests\Unit
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/incl/analytics/lafka-analytics-emitter.php';
require_once dirname( __DIR__, 2 ) . '/incl/analytics/lafka-wc-events.php';
require_once dirname( __DIR__, 2 ) . '/incl/analytics/lafka-cf-analytics.php';
require_once dirname( __DIR__, 2 ) . '/incl/analytics/lafka-page-context.php';

final class AnalyticsPageContextTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'get_theme_mod' )->returnArg( 2 );
		foreach ( array( 'is_order_received_page', 'is_checkout', 'is_cart', 'is_product', 'is_product_category', 'is_shop', 'is_account_page', 'is_front_page', 'is_page', 'is_singular', 'is_search', 'is_tax', 'is_user_logged_in' ) as $conditional ) {
			Functions\when( $conditional )->justReturn( false );
		}
	}

	protected function tearDown(): void {
		unset( $_COOKIE['lafka_order_method'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_cart_value_bands(): void {
		$bands = array();
		foreach ( array( 0.0, -1.0, 0.01, 24.99, 25.0, 39.99, 40.0, 54.99, 55.0, 300.0 ) as $total ) {
			$bands[ (string) $total ] = lafka_analytics_cart_value_band( $total );
		}
		$this->assertSame(
			array(
				'0'     => 'empty',
				'-1'    => 'empty',
				'0.01'  => 'under_25',
				'24.99' => 'under_25',
				'25'    => '25_40',
				'39.99' => '25_40',
				'40'    => '40_55',
				'54.99' => '40_55',
				'55'    => '55_plus',
				'300'   => '55_plus',
			),
			$bands
		);
	}

	public function test_order_received_is_reported_as_purchase_not_checkout(): void {
		Functions\when( 'is_checkout' )->justReturn( true );
		$this->assertSame( 'checkout', lafka_analytics_page_type() );

		Functions\when( 'is_order_received_page' )->justReturn( true );
		$this->assertSame( 'purchase', lafka_analytics_page_type() );
	}

	public function test_nothing_is_emitted_without_an_analytics_destination(): void {
		ob_start();
		lafka_analytics_emit_page_context();
		$this->assertSame( '', ob_get_clean() );
	}

	/**
	 * Separate process: other suites define WC() / lafka_pdp_is_store_open()
	 * for real, and this test controls both.
	 */
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_emits_one_page_context_push_with_all_dimensions(): void {
		Functions\when( 'get_theme_mod' )->alias(
			static fn( $key, $default = '' ) => 'lafka_gtm_container_id' === $key ? 'GTM-XYZ987' : $default
		);
		Functions\when( 'is_cart' )->justReturn( true );
		Functions\when( 'wp_json_encode' )->alias( static fn( $v ) => json_encode( $v ) );
		Functions\when( 'sanitize_key' )->alias( static fn( $v ) => strtolower( preg_replace( '/[^a-z0-9_\-]/i', '', (string) $v ) ) );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'lafka_pdp_is_store_open' )->justReturn( true );
		$cart = new class() {
			public function get_cart_contents_count() {
				return 3;
			}
			public function get_cart_contents_total() {
				return 42.0;
			}
		};
		Functions\when( 'WC' )->justReturn( (object) array( 'cart' => $cart ) );
		$_COOKIE['lafka_order_method'] = 'delivery';

		ob_start();
		lafka_analytics_emit_page_context();
		$out = (string) ob_get_clean();

		$this->assertSame( 1, preg_match( '/^<script>window\.dataLayer = window\.dataLayer \|\| \[\];window\.dataLayer\.push\((.*)\);<\/script>$/', trim( $out ), $m ) );
		$this->assertSame(
			array(
				'event'              => 'page_context',
				'page_type'          => 'cart',
				'fulfilment_method'  => 'delivery',
				'store_open'         => true,
				'customer_logged_in' => false,
				'customer_is_repeat' => false,
				'cart_items_count'   => 3,
				'cart_value_band'    => '40_55',
				'top_category'       => '',
			),
			json_decode( $m[1], true )
		);
	}
}
