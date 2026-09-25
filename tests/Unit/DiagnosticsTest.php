<?php
/**
 * GX1 / A4 + A7 + A8: the Diagnostics runtime — module flag, WooCommerce
 * place-order trace parsing + indexing, the daily job (retention, digest only
 * when something is new) and the digest email body.
 *
 * @package Lafka_Plugin
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Lafka_Diagnostics;
use Lafka_Email_Error_Digest;
use Lafka_Incidents;
use Lafka_Log;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/incl/observability/class-lafka-checkout-block-reasons.php';
require_once dirname( __DIR__, 2 ) . '/incl/observability/class-lafka-log-scrubber.php';
require_once dirname( __DIR__, 2 ) . '/incl/observability/class-lafka-log.php';
require_once dirname( __DIR__, 2 ) . '/incl/observability/class-lafka-incidents.php';
require_once dirname( __DIR__, 2 ) . '/incl/observability/class-lafka-checkout-failures.php';
require_once dirname( __DIR__, 2 ) . '/incl/observability/class-lafka-diagnostics.php';
require_once __DIR__ . '/Stubs/wc-email-stub.php';
require_once dirname( __DIR__, 2 ) . '/incl/observability/class-lafka-email-error-digest.php';

/**
 * $wpdb double for the digest + prune paths.
 */
final class FakeDiagnosticsWpdb {
	public string $prefix = 'wp_';
	/** @var array<int,string> */
	public array $queries = array();
	/** @var array<int,object> */
	public array $pending = array();

	public function prepare( $sql, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		foreach ( $args as $a ) {
			$sql = preg_replace( '/%[dsf]/', is_int( $a ) ? (string) $a : "'" . (string) $a . "'", (string) $sql, 1 );
		}
		return $sql;
	}

	public function query( $sql ) {
		$this->queries[] = (string) $sql;
		return 0;
	}

	public function get_results( $sql ) {
		$this->queries[] = (string) $sql;
		return str_contains( (string) $sql, 'notified_at IS NULL' ) ? $this->pending : array();
	}

	public function suppress_errors( $s = true ) {
		return false;
	}

	public function esc_like( $text ) {
		return addcslashes( (string) $text, '_%\\' );
	}
}

final class DiagnosticsTest extends TestCase {

	private const TRACE = <<<'LOG'
2026-05-29T20:24:52+00:00 DEBUG [Shortcode #1] Place Order flow initiated CONTEXT: {"order_uid":"6de16f86","store_url":"https://example.test"}
2026-05-29T20:24:52+00:00 DEBUG [Shortcode #2] Session updated with checkout data and totals calculated CONTEXT: {"order_uid":"6de16f86"}
2026-05-29T20:24:53+00:00 DEBUG [Shortcode #5] woocommerce_checkout_order_processed hook ran successfully CONTEXT: {"order_id":9001,"billing":{"country":"CA"}}
LOG;

	/** @var array<string,mixed> */
	private array $options = array();

	/** @var array<int,array{level:string,message:string,context:array}> */
	private array $logged = array();

	private FakeDiagnosticsWpdb $wpdb;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Lafka_Log::reset();
		Lafka_Incidents::reset();
		$this->logged  = array();
		$this->options = array( Lafka_Incidents::VERSION_OPTION => Lafka_Incidents::DB_VERSION );
		$this->wpdb    = new FakeDiagnosticsWpdb();
		$GLOBALS['wpdb'] = $this->wpdb;

		Functions\when( 'get_option' )->alias( fn( $key, $default = false ) => $this->options[ $key ] ?? $default );
		Functions\when( 'update_option' )->alias(
			function ( $key, $value ) {
				$this->options[ $key ] = $value;
				return true;
			}
		);
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->alias( static fn( $s ) => htmlspecialchars( (string) $s, ENT_QUOTES ) );
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( '_n' )->alias( static fn( $single, $plural, $n ) => 1 === $n ? $single : $plural );
		Functions\when( 'admin_url' )->alias( static fn( $path = '' ) => 'https://example.test/wp-admin/' . $path );
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
		unset( $GLOBALS['wpdb'] );
		Lafka_Log::reset();
		Monkey\tearDown();
		parent::tearDown();
	}

	/** Logger double sink. */
	public function capture( $level, $message, $context ): void {
		$this->logged[] = compact( 'level', 'message', 'context' );
	}

	// ─── Module flag ────────────────────────────────────────────────────────

	public function test_module_is_on_by_default_and_can_be_switched_off(): void {
		self::assertTrue( Lafka_Diagnostics::is_enabled() );

		Lafka_Diagnostics::set_enabled( false );
		self::assertFalse( Lafka_Diagnostics::is_enabled() );
		self::assertSame( 'disabled', $this->options['lafka_log_settings']['diagnostics'] );
	}

	// ─── Place-order traces ─────────────────────────────────────────────────

	public function test_a_trace_is_parsed_into_steps_last_step_and_order(): void {
		$trace = Lafka_Diagnostics::parse_trace( self::TRACE );

		self::assertSame( 3, $trace['steps'] );
		self::assertSame( '[Shortcode #1] Place Order flow initiated', $trace['first_step'] );
		self::assertSame( '[Shortcode #5] woocommerce_checkout_order_processed hook ran successfully', $trace['last_step'] );
		self::assertSame( 9001, $trace['order_id'] );
		self::assertSame( '2026-05-29T20:24:52+00:00', $trace['started'] );
	}

	public function test_only_stale_unseen_traces_are_indexed_as_checkout_incidents(): void {
		// No order behind the trace is known (stub it: any earlier test that
		// defined wc_get_order through Brain Monkey would otherwise be called).
		Functions\when( 'wc_get_order' )->justReturn( false );
		$stale = array_merge(
			Lafka_Diagnostics::parse_trace( self::TRACE ),
			array(
				'source'   => 'place-order-debug-aaaaaaaa',
				'modified' => time() - 3600,
			)
		);
		$fresh = array_merge( $stale, array( 'source' => 'place-order-debug-bbbbbbbb', 'modified' => time() - 60 ) );

		self::assertSame( 1, Lafka_Diagnostics::index_place_order_traces( array( $stale, $fresh ) ) );
		self::assertSame( 'lafka-checkout', $this->logged[0]['context']['source'] );
		self::assertSame( 'place_order_incomplete', $this->logged[0]['context']['code'] );
		self::assertSame( 9001, $this->logged[0]['context']['order_id'] );
		self::assertStringContainsString( 'woocommerce_checkout_order_processed', $this->logged[0]['message'] );

		// Seen traces are never indexed twice.
		self::assertSame( 0, Lafka_Diagnostics::index_place_order_traces( array( $stale ) ) );
	}

	/**
	 * One WC place-order log line.
	 */
	private static function line( string $step, string $context = '{}' ): string {
		return '2026-09-25T05:57:18+00:00 DEBUG ' . $step . ' CONTEXT: ' . $context;
	}

	/**
	 * A trace whose steps are $steps, optionally linked to an order in $status.
	 *
	 * @param array<int,string> $steps  Step messages.
	 * @param string            $status Order status ('' = no order).
	 * @return array<string,mixed>
	 */
	private static function trace( array $steps, string $status = '', string $source = 'place-order-debug-cccccccc' ): array {
		$lines = array();
		foreach ( $steps as $step ) {
			$lines[] = self::line( $step, '' !== $status ? '{"order_id":9750}' : '{}' );
		}
		return array_merge(
			Lafka_Diagnostics::parse_trace( implode( "\n", $lines ) ),
			array(
				'source'       => $source,
				'modified'     => time() - 3600,
				'order_status' => $status,
			)
		);
	}

	/**
	 * @return array<string,array{0:array<int,string>,1:string,2:string}>
	 */
	public static function trace_outcomes(): array {
		$shortcode = array(
			'[Shortcode #1] Place Order flow initiated',
			'[Shortcode #2] Session updated with checkout data and totals calculated',
			'[Shortcode #3] Checkout posted data validated',
			'[Shortcode #4] Validated/Created customer and created order object',
			'[Shortcode #5] woocommerce_checkout_order_processed hook ran successfully',
		);
		$store_api = array(
			'[Store API #1] Place Order flow initiated',
			'[Store API #2] Cart validated',
			'[Store API #4::create_or_update_draft_order] Updated order from cart',
			'[Store API #7] Validated order data',
			'[Store API #8] Reserved stock for order',
		);
		return array(
			// Success terminal steps: WC keeps (or defers deleting) these logs.
			'shortcode paid (#6A)'              => array( array_merge( $shortcode, array( '[Shortcode #6A] Order payment processed successfully' ) ), 'processing', 'finished' ),
			'shortcode no payment (#6B)'        => array( array_merge( $shortcode, array( '[Shortcode #6B] Order processed without payment' ) ), 'processing', 'finished' ),
			'store api processed (#9)'          => array( array_merge( $store_api, array( '[Store API #9] Order processed' ) ), 'processing', 'finished' ),
			'success marker, order not looked up' => array( array_merge( $shortcode, array( '[Shortcode #6A] Order payment processed successfully' ) ), '', 'finished' ),
			// A later step than payment on a paid order also counts as finished.
			'past payment step, paid order'     => array( array_merge( $shortcode, array( '[Shortcode #7] Some later step' ) ), 'on-hold', 'finished' ),
			// Genuinely stuck: stopped in the gateway, even though a LATER retry completed the order.
			'stopped at #5, order completed later' => array( $shortcode, 'completed', 'unfinished' ),
			'store api stopped before payment'  => array( $store_api, 'processing', 'unfinished' ),
			'validation stop at #2'             => array( array_slice( $shortcode, 0, 2 ), '', 'unfinished' ),
			// Explicit failure terminal steps.
			'shortcode expected failure'        => array( array_merge( array_slice( $shortcode, 0, 3 ), array( '[Shortcode #EXPECTEDFAIL] Invalid payment method.' ) ), '', 'failed' ),
			'store api failure'                 => array( array_merge( array_slice( $store_api, 0, 2 ), array( '[Store API #FAIL] Placing Order failed' ) ), '', 'failed' ),
		);
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'trace_outcomes' )]
	public function test_trace_outcome_separates_finished_from_stuck_attempts( array $steps, string $status, string $expected ): void {
		$trace = self::trace( $steps, $status );

		self::assertSame( $expected, Lafka_Diagnostics::trace_outcome( $trace, $status ) );
	}

	public function test_finished_traces_are_never_indexed_and_are_hidden_unless_asked_for(): void {
		$done  = self::trace( array( '[Shortcode #5] woocommerce_checkout_order_processed hook ran successfully', '[Shortcode #6A] Order payment processed successfully' ), 'processing', 'place-order-debug-dddddddd' );
		$stuck = self::trace( array( '[Shortcode #1] Place Order flow initiated', '[Shortcode #2] Session updated with checkout data and totals calculated' ), '', 'place-order-debug-eeeeeeee' );

		self::assertSame( 1, Lafka_Diagnostics::index_place_order_traces( array( $done, $stuck ) ) );
		self::assertCount( 1, $this->logged );
		self::assertStringContainsString( '[Shortcode #2]', $this->logged[0]['message'] );
		self::assertNotContains( 'place-order-debug-dddddddd', (array) $this->options[ Lafka_Diagnostics::SEEN_TRACES_OPTION ] );

		$visible = Lafka_Diagnostics::filter_traces( array( $done, $stuck ), false );
		self::assertSame( array( 'place-order-debug-eeeeeeee' ), array_column( $visible, 'source' ) );
		self::assertCount( 2, Lafka_Diagnostics::filter_traces( array( $done, $stuck ), true ) );
	}

	public function test_daily_job_resolves_incidents_recorded_for_finished_attempts(): void {
		Lafka_Diagnostics::run_daily();

		$resolve = array_values( array_filter( $this->wpdb->queries, static fn( $q ) => str_starts_with( $q, 'UPDATE' ) ) );
		self::assertCount( 1, $resolve );
		self::assertStringContainsString( "SET status = 'resolved'", $resolve[0] );
		self::assertStringContainsString( "code = 'place_order_incomplete'", $resolve[0] );
		self::assertStringContainsString( '[Shortcode #6', $resolve[0] );
		self::assertStringContainsString( '[Store API #9', $resolve[0] );
	}

	// ─── Daily job ──────────────────────────────────────────────────────────

	public function test_daily_job_prunes_with_the_retention_window_and_skips_an_empty_digest(): void {
		$this->options['lafka_log_settings'] = array( 'retention_days' => 3 ); // clamped to 7.

		$summary = Lafka_Diagnostics::run_daily();

		self::assertSame( 0, $summary['digest'] );
		self::assertStringContainsString( gmdate( 'Y-m-d', time() - 7 * 86400 ), $this->wpdb->queries[0] );
		self::assertIsInt( $this->options[ Lafka_Diagnostics::LAST_RUN_OPTION ] );
	}

	public function test_digest_is_sent_and_incidents_marked_notified_when_something_is_new(): void {
		$this->wpdb->pending = array(
			(object) array(
				'id'        => 7,
				'channel'   => 'payment',
				'level'     => 'warning',
				'code'      => 'payment_avs',
				'message'   => 'Payment failed: avs',
				'hit_count' => 3,
				'last_seen' => '2026-09-24 10:00:00',
			),
		);
		$email = new class() {
			/** @var array<int,object> */
			public array $sent = array();
			public function trigger( $rows ) {
				$this->sent = $rows;
				return true;
			}
		};
		$mailer = new class( $email ) {
			public function __construct( private $email ) {}
			public function get_emails() {
				return array( 'Lafka_Email_Error_Digest' => $this->email );
			}
		};
		$wc     = new class( $mailer ) {
			public function __construct( private $mailer ) {}
			public function mailer() {
				return $this->mailer;
			}
		};
		Functions\when( 'WC' )->justReturn( $wc );

		self::assertSame( 1, Lafka_Diagnostics::send_digest() );
		self::assertCount( 1, $email->sent );
		self::assertStringContainsString( 'SET notified_at', (string) end( $this->wpdb->queries ) );
		self::assertStringContainsString( 'IN (7)', (string) end( $this->wpdb->queries ) );

		Lafka_Diagnostics::set_enabled( false );
		self::assertSame( 0, Lafka_Diagnostics::send_digest(), 'Module off: no digest.' );
	}

	public function test_next_run_is_the_next_seven_am_in_the_future(): void {
		$next = Lafka_Diagnostics::next_run_timestamp();

		self::assertGreaterThan( time(), $next );
		self::assertLessThanOrEqual( time() + 86400, $next );
		self::assertSame( '07:00', gmdate( 'H:i', $next ) );
	}

	// ─── Digest email body ──────────────────────────────────────────────────

	public function test_digest_body_lists_escaped_incidents_with_reason_labels(): void {
		Functions\when( 'get_date_from_gmt' )->alias( static fn( $gmt ) => substr( (string) $gmt, 0, 16 ) );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		$rows = array(
			(object) array(
				'channel'   => 'payment',
				'level'     => 'warning',
				'code'      => 'payment_avs',
				'message'   => 'Payment failed: avs',
				'hit_count' => 3,
				'last_seen' => '2026-09-24 10:00:00',
			),
			(object) array(
				'channel'   => 'php',
				'level'     => 'critical',
				'code'      => 'php_fatal',
				'message'   => 'Uncaught <script>x</script>',
				'hit_count' => 1,
				'last_seen' => '2026-09-24 11:00:00',
			),
		);

		$html = Lafka_Email_Error_Digest::render_rows_html( $rows );

		self::assertStringContainsString( 'Card declined: address mismatch (AVS)', $html );
		self::assertStringContainsString( '&lt;script&gt;', $html );
		self::assertStringNotContainsString( '<script>', $html );
		self::assertStringContainsString( 'page=lafka-diagnostics', $html );
		self::assertStringContainsString( '2 problems were recorded', $html );
	}
}
