<?php
/**
 * GX1 / A8: Site Health tests — Lafka fatal in 24 h (critical), ≥ 3 payment
 * failures in 7 days (recommended), background jobs stalled (recommended) —
 * registered only while the diagnostics module is on.
 *
 * @package Lafka_Plugin
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Lafka_Diagnostics;
use Lafka_Diagnostics_Health;
use Lafka_Incidents;
use Lafka_Log;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/incl/observability/class-lafka-checkout-block-reasons.php';
require_once dirname( __DIR__, 2 ) . '/incl/observability/class-lafka-log-scrubber.php';
require_once dirname( __DIR__, 2 ) . '/incl/observability/class-lafka-log.php';
require_once dirname( __DIR__, 2 ) . '/incl/observability/class-lafka-incidents.php';
require_once dirname( __DIR__, 2 ) . '/incl/observability/class-lafka-checkout-failures.php';
require_once dirname( __DIR__, 2 ) . '/incl/observability/class-lafka-diagnostics.php';
require_once dirname( __DIR__, 2 ) . '/incl/observability/class-lafka-diagnostics-health.php';

if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

final class SiteHealthDiagnosticsTest extends TestCase {

	/** @var array<string,mixed> */
	private array $options = array();

	private int $fatals = 0;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Lafka_Log::reset();
		$this->options = array( Lafka_Incidents::VERSION_OPTION => Lafka_Incidents::DB_VERSION );
		$this->fatals  = 0;
		$test          = $this;
		$GLOBALS['wpdb'] = new class( $test ) {
			public string $prefix = 'wp_';
			public function __construct( private $test ) {}
			public function prepare( $sql, ...$args ) {
				return (string) $sql;
			}
			public function get_var( $sql ) {
				return $this->test->fatal_count();
			}
		};
		Functions\when( 'get_option' )->alias( fn( $key, $default = false ) => $this->options[ $key ] ?? $default );
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'admin_url' )->returnArg();
		Functions\when( '_n' )->alias( static fn( $single, $plural, $n ) => 1 === $n ? $single : $plural );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'wp_timezone' )->justReturn( new \DateTimeZone( 'UTC' ) );
		Functions\when( 'human_time_diff' )->justReturn( '3 days' );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	/** Fatal incidents the $wpdb double reports. */
	public function fatal_count(): int {
		return $this->fatals;
	}

	public function test_three_tests_are_registered_only_while_the_module_is_on(): void {
		$tests = Lafka_Diagnostics_Health::add_tests( array() );
		self::assertSame( array( 'lafka_recent_fatals', 'lafka_payment_failures', 'lafka_cron_health' ), array_keys( $tests['direct'] ) );

		$this->options['lafka_log_settings'] = array( 'diagnostics' => 'disabled' );
		self::assertSame( array(), Lafka_Diagnostics_Health::add_tests( array() ) );
	}

	public function test_recent_lafka_fatal_is_critical(): void {
		self::assertSame( 'good', Lafka_Diagnostics_Health::test_recent_fatals()['status'] );

		$this->fatals = 2;
		$result       = Lafka_Diagnostics_Health::test_recent_fatals();
		self::assertSame( 'critical', $result['status'] );
		self::assertStringContainsString( 'lafka-diagnostics', $result['actions'] );
	}

	public function test_three_payment_failures_in_a_week_is_a_recommendation(): void {
		$today = gmdate( 'Y-m-d' );

		$this->options['lafka_log_checkout_stats'] = array( $today => array( 'payment_avs' => 2, 'store_closed' => 9 ) );
		self::assertSame( 'good', Lafka_Diagnostics_Health::test_payment_failures()['status'] );

		$this->options['lafka_log_checkout_stats'] = array( $today => array( 'payment_avs' => 2, 'payment_declined' => 1 ) );
		self::assertSame( 'recommended', Lafka_Diagnostics_Health::test_payment_failures()['status'] );
	}

	public function test_stalled_daily_job_is_a_recommendation(): void {
		Functions\when( 'as_next_scheduled_action' )->justReturn( time() + 3600 );

		$this->options[ Lafka_Diagnostics::LAST_RUN_OPTION ] = time() - 3600;
		self::assertSame( 'good', Lafka_Diagnostics_Health::test_cron_health()['status'] );

		$this->options[ Lafka_Diagnostics::LAST_RUN_OPTION ] = time() - 3 * 86400;
		self::assertSame( 'recommended', Lafka_Diagnostics_Health::test_cron_health()['status'] );
	}
}
