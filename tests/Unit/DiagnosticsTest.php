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
