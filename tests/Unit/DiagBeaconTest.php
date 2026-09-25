<?php
/**
 * Front-end JS error beacon (A6): the inline head handler stays within its
 * 700-byte budget, POST /lafka/v1/diag guards (same-origin, size, schema,
 * bots, rate limit) and the log hand-off — lafka_log( 'warning', 'js', … )
 * when the logging facade exists, WooCommerce's logger otherwise. Also the
 * shared Lafka_Beacon_Guard rate limiter.
 *
 * @package Lafka\Plugin\Tests\Unit
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey\Functions;
use Lafka_Beacon_Guard;
use Lafka_Diag_Beacon;
use LafkaPlugin\Tests\Unit\Support\InsightsHarness;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;
use WP_Error;

require_once __DIR__ . '/Stubs/wp-error-class.php';
require_once __DIR__ . '/Support/InsightsHarness.php';
require_once dirname( __DIR__, 2 ) . '/incl/observability/class-lafka-diag-beacon.php';
require_once dirname( __DIR__, 2 ) . '/incl/analytics/lafka-page-context.php';

final class DiagBeaconTest extends TestCase {

	use InsightsHarness;

	/** @var array<int,array<string,mixed>> */
	private array $logged = array();

	private const REPORT = array(
		'm' => 'TypeError: cart is undefined',
		'f' => 'https://shop.example.test/wp-content/plugins/lafka-plugin/assets/js/x.js?ver=1',
		'l' => 12,
		'c' => 4,
		't' => 'checkout',
	);

	protected function setUp(): void {
		parent::setUp();
		$this->set_up_insights();
		$this->logged = array();
		Functions\when( 'wp_salt' )->justReturn( 'salt' );
	}

	/**
	 * Capture writes through the Lafka logging facade's procedural helper
	 * (defined by the GX1 observability bootstrap in production).
	 */
	private function capture_lafka_log(): void {
		Functions\when( 'lafka_log' )->alias(
			function ( $level, $channel, $message, $context = array() ) {
				$this->logged[] = compact( 'level', 'channel', 'message', 'context' );
				return true;
			}
		);
	}

	protected function tearDown(): void {
		$this->tear_down_insights();
		parent::tearDown();
	}

	public function test_inline_handler_is_within_its_700_byte_budget(): void {
		$min = (string) file_get_contents( dirname( __DIR__, 2 ) . '/assets/js/lafka-diag.min.js' );
		$this->assertNotSame( '', $min );
		$this->assertLessThanOrEqual( 700, strlen( $min ) );
	}

	public function test_head_script_carries_its_config_and_skips_the_kitchen_display(): void {
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'get_query_var' )->justReturn( '' );
		Functions\when( 'rest_url' )->alias( static fn( $p = '' ) => 'https://shop.example.test/wp-json/' . $p );
		Functions\when( 'wp_json_encode' )->alias( static fn( $v ) => json_encode( $v, JSON_UNESCAPED_SLASHES ) );
		$this->options['lafka_log_settings'] = array( 'js_sample' => '0.5' );
		Functions\when( 'is_order_received_page' )->justReturn( true );

		ob_start();
		Lafka_Diag_Beacon::print_script();
		$html = (string) ob_get_clean();
		$this->assertStringStartsWith( '<script id="lafka-diag">window.lafkaDiagCfg={"u":"https://shop.example.test/wp-json/lafka/v1/diag","s":0.5,"t":"purchase"};', $html );
		$this->assertStringContainsString( 'unhandledrejection', $html );

		Functions\when( 'get_query_var' )->justReturn( 'kds-token' );
		ob_start();
		Lafka_Diag_Beacon::print_script();
		$this->assertSame( '', (string) ob_get_clean() );
	}

	public function test_cross_origin_reports_are_refused(): void {
		$this->assertTrue( Lafka_Diag_Beacon::permission( $this->beacon( self::REPORT ) ) );
		$this->assertInstanceOf( WP_Error::class, Lafka_Diag_Beacon::permission( $this->beacon( self::REPORT, array( 'origin' => 'https://evil.example' ) ) ) );
	}

	public function test_report_is_logged_on_the_js_channel(): void {
		$this->capture_lafka_log();
		$reply = Lafka_Diag_Beacon::handle( $this->beacon( self::REPORT ) );

		$this->assertSame( 204, $this->status_of( $reply ) );
		$this->assertCount( 1, $this->logged );
		$this->assertSame( 'warning', $this->logged[0]['level'] );
		$this->assertSame( 'js', $this->logged[0]['channel'] );
		$this->assertSame( 'TypeError: cart is undefined', $this->logged[0]['message'] );
		$this->assertSame( 'js_error', $this->logged[0]['context']['code'] );
		$this->assertSame( '/wp-content/plugins/lafka-plugin/assets/js/x.js', $this->logged[0]['context']['file'], 'Path only; query string dropped.' );
		$this->assertSame( 12, $this->logged[0]['context']['line'] );
		$this->assertSame( 'safari-ios', $this->logged[0]['context']['ua'], 'UA family, never the raw UA.' );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_without_the_logging_facade_it_falls_back_to_woocommerce_logs(): void {
		$this->assertFalse( function_exists( 'lafka_log' ), 'Fresh process: no logging facade.' );
		$sink = array();
		Functions\when( 'wc_get_logger' )->justReturn(
			new class( $sink ) {
				public function __construct( private array &$sink ) {}
				public function warning( $message, $context ) {
					$this->sink[] = array( $message, $context );
				}
			}
		);
		Lafka_Diag_Beacon::handle( $this->beacon( self::REPORT ) );
		$this->assertCount( 1, $sink );
		$this->assertSame( 'TypeError: cart is undefined', $sink[0][0] );
		$this->assertSame( 'lafka-js', $sink[0][1]['source'] );
	}

	public function test_invalid_reports_are_400_and_not_logged(): void {
		$this->capture_lafka_log();
		$cases = array(
			'third-party file' => array_merge( self::REPORT, array( 'f' => 'https://cdn.other.example/lib.js' ) ),
			'extension frame'  => array_merge( self::REPORT, array( 'f' => 'chrome-extension://abc/content.js' ) ),
			'opaque error'     => array_merge( self::REPORT, array( 'm' => 'Script error.' ) ),
			'unknown key'      => array_merge( self::REPORT, array( 'stack' => '…' ) ),
			'line not int'     => array_merge( self::REPORT, array( 'l' => '12' ) ),
		);
		foreach ( $cases as $label => $report ) {
			$this->assertSame( 400, $this->status_of( Lafka_Diag_Beacon::handle( $this->beacon( $report ) ) ), $label );
		}
		$this->assertSame( 413, $this->status_of( Lafka_Diag_Beacon::handle( $this->beacon( str_repeat( 'x', 2049 ) ) ) ) );
		$this->assertSame( array(), $this->logged );
	}

	public function test_bots_are_ignored_and_floods_are_rate_limited(): void {
		$this->capture_lafka_log();
		$_SERVER['HTTP_USER_AGENT'] = 'HeadlessChrome/120';
		$this->assertSame( 204, $this->status_of( Lafka_Diag_Beacon::handle( $this->beacon( self::REPORT ) ) ) );
		$this->assertSame( array(), $this->logged );

		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0) Chrome/130.0 Safari/537.36';
		for ( $i = 0; $i < 30; $i++ ) {
			$this->assertSame( 204, $this->status_of( Lafka_Diag_Beacon::handle( $this->beacon( self::REPORT ) ) ) );
		}
		$this->assertSame( 429, $this->status_of( Lafka_Diag_Beacon::handle( $this->beacon( self::REPORT ) ) ), '31st report in the hour from one visitor.' );
		$this->assertCount( 30, $this->logged );
		$this->assertCount( 1, $this->transients, 'One transient per bucket — one write per report.' );
		$this->assertStringNotContainsString( '203.0.113.7', serialize( $this->transients ), 'The IP is only ever hashed.' );
	}

	public function test_personal_data_is_masked_in_the_message(): void {
		$parsed = Lafka_Diag_Beacon::parse(
			(string) json_encode( array_merge( self::REPORT, array( 'm' => 'Bad input jane@example.com 902-555-0142' ) ) ),
			'shop.example.test'
		);
		$this->assertSame( 'Bad input [email] [number]', $parsed['message'] );
	}

	public function test_rate_limiter_has_a_global_cap_and_a_window(): void {
		$this->assertFalse( Lafka_Beacon_Guard::rate_limited( 'test', 'a', 5, 2, 60 ) );
		$this->assertFalse( Lafka_Beacon_Guard::rate_limited( 'test', 'b', 5, 2, 60 ) );
		$this->assertTrue( Lafka_Beacon_Guard::rate_limited( 'test', 'c', 5, 2, 60 ), 'Global cap reached.' );

		// The window rolls over.
		$this->transients['lafka_rl_test']['t'] = time() - 61;
		$this->assertFalse( Lafka_Beacon_Guard::rate_limited( 'test', 'c', 5, 2, 60 ) );

		$this->assertFalse( Lafka_Beacon_Guard::rate_limited( 'off', 'x', 0, 0, 60 ), 'A limit of 0 disables the cap.' );
		$this->assertArrayNotHasKey( 'lafka_rl_off', $this->transients );
	}
}
