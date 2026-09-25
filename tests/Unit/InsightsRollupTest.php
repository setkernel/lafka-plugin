<?php
/**
 * Insights storage + nightly job (GX2 / B2): session bitmasks → cumulative
 * funnel and "why no order" rows, idempotent catch-up, prune + secret
 * rotation, the report's biggest leak, and the local-time scheduler.
 *
 * @package Lafka\Plugin\Tests\Unit
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey\Functions;
use Lafka_Insights_DB as DB;
use Lafka_Insights_Queries;
use Lafka_Insights_Rollup;
use Lafka_Insights_Scheduler;
use Lafka_Insights_Session;
use LafkaPlugin\Tests\Unit\Support\InsightsHarness;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Support/InsightsHarness.php';

final class InsightsRollupTest extends TestCase {

	use InsightsHarness;

	protected function setUp(): void {
		parent::setUp();
		$this->set_up_insights();
	}

	protected function tearDown(): void {
		$this->tear_down_insights();
		parent::tearDown();
	}

	/** @return array<int,array<string,mixed>> */
	private function sessions(): array {
		return array(
			// Bounced from the home page (mobile, typed in).
			array( 'stages' => DB::STAGE_VISIT, 'device' => 1, 'source_type' => 'typein', 'source' => '(direct)', 'hour' => 18, 'dow' => 5 ),
			// Quick-added from the menu (no product page), reached checkout, outside the zone.
			array( 'stages' => DB::STAGE_VISIT | DB::STAGE_MENU | DB::STAGE_ADD | DB::STAGE_CART | DB::STAGE_CHECKOUT, 'last_block' => 'outside_delivery_zone', 'device' => 1, 'source_type' => 'organic', 'source' => 'google.com', 'landing' => 'home', 'hour' => 18, 'dow' => 5 ),
			// Ordered after a failed card.
			array( 'stages' => DB::STAGE_VISIT | DB::STAGE_MENU | DB::STAGE_PRODUCT | DB::STAGE_ADD | DB::STAGE_CART | DB::STAGE_CHECKOUT | DB::STAGE_PAY_ATTEMPT | DB::STAGE_ORDER | DB::STAGE_PAY_FAILED, 'last_block' => 'payment_avs', 'device' => 3, 'source_type' => 'utm', 'source' => 'newsletter', 'campaign' => 'fall', 'hour' => 12, 'dow' => 2 ),
			// Visited while closed.
			array( 'stages' => DB::STAGE_VISIT | DB::STAGE_MENU | DB::STAGE_CLOSED, 'device' => 1, 'source_type' => 'typein', 'hour' => 10, 'dow' => 0 ),
			// Cart abandoned with no refusal shown.
			array( 'stages' => DB::STAGE_VISIT | DB::STAGE_ADD | DB::STAGE_CART, 'device' => 0, 'hour' => 10, 'dow' => 0 ),
		);
	}

	public function test_funnel_is_cumulative_from_the_furthest_stage(): void {
		$out = Lafka_Insights_Rollup::rollup_rows( $this->sessions() );

		$this->assertSame(
			array(
				'visit'       => 5,
				'menu'        => 4,
				'product'     => 3,
				'add'         => 3,
				'cart'        => 3,
				'checkout'    => 2,
				'pay_attempt' => 1,
				'order'       => 1,
				'pay_failed'  => 1,
				'closed'      => 1,
			),
			$out['funnel'],
			'Quick-add visits count for the product step they skipped.'
		);
	}

	public function test_abandoned_carts_are_grouped_by_last_refusal(): void {
		$out = Lafka_Insights_Rollup::rollup_rows( $this->sessions() );
		$this->assertSame(
			array(
				'outside_delivery_zone' => 1,
				'none'                  => 1,
			),
			$out['abandon'],
			'Ordered visits are not abandoned even after a failed card.'
		);
	}

	public function test_audience_dimensions(): void {
		$out = Lafka_Insights_Rollup::rollup_rows( $this->sessions() );
		$this->assertSame( array( 'mobile' => 3, 'desktop' => 1, 'unknown' => 1 ), $out['device'] );
		$this->assertSame( array( 'typein' => 2, 'organic' => 1, 'utm' => 1, 'unknown' => 1 ), $out['source'] );
		$this->assertSame( array( 'fall' => 1 ), $out['campaign'] );
		$this->assertSame( 2, $out['hour_dow']['5-18'] );
		$this->assertSame( array( '0-10' => 1 ), $out['closed_hour_dow'] );
	}

	public function test_catch_up_rolls_every_unrolled_finished_day_idempotently(): void {
		$this->options[ Lafka_Insights_Rollup::ROLLED_OPTION ] = '2026-09-21';
		$this->wpdb->results['FROM wp_lafka_insights_sessions'] = $this->sessions();

		$rolled = Lafka_Insights_Rollup::catch_up( '2026-09-24' );

		$this->assertSame( array( '2026-09-22', '2026-09-23' ), $rolled, 'Today is still open, so it is not rolled.' );
		$this->assertSame( '2026-09-23', $this->options[ Lafka_Insights_Rollup::ROLLED_OPTION ] );
		$writes = $this->wpdb->writes();
		$this->assertCount( 2, $writes );
		$this->assertStringContainsString( 'value = VALUES(value)', $writes[0], 'A re-run replaces rollup rows, never double-counts.' );
		$this->assertStringContainsString( "('2026-09-22','funnel','visit',5)", $writes[0] );

		$this->wpdb->queries = array();
		$this->assertSame( array(), Lafka_Insights_Rollup::catch_up( '2026-09-24' ), 'Nothing left to roll.' );
	}

	public function test_nightly_job_reschedules_rolls_prunes_and_rotates(): void {
		$scheduled = array();
		Functions\when( 'as_get_scheduled_actions' )->justReturn( array() );
		Functions\when( 'as_schedule_single_action' )->alias(
			static function ( $when, $hook, $args, $group ) use ( &$scheduled ) {
				$scheduled[] = array( $hook, $when, $group );
				return 1;
			}
		);
		Functions\when( 'wp_timezone' )->alias( static fn() => new \DateTimeZone( 'UTC' ) );
		$this->options[ Lafka_Insights_Session::SECRET_OPTION ] = array(
			'day' => '2026-09-23',
			'key' => str_repeat( 'a', 64 ),
		);
		$this->options[ Lafka_Insights_Rollup::ROLLED_OPTION ]  = '2026-09-23';

		Lafka_Insights_Rollup::run_nightly();

		$this->assertSame( 'lafka_insights_nightly', $scheduled[0][0] );
		$this->assertSame( 'lafka-insights', $scheduled[0][2] );
		$deletes = implode( "\n", $this->wpdb->writes() );
		$this->assertStringContainsString( "DELETE FROM wp_lafka_insights_sessions WHERE day < '2026-08-20'", $deletes, '35-day session retention.' );
		$this->assertStringContainsString( 'DELETE FROM wp_lafka_insights_daily WHERE day <', $deletes );
		$this->assertArrayNotHasKey( Lafka_Insights_Session::SECRET_OPTION, $this->options, "Yesterday's secret is deleted." );
	}

	public function test_disabled_module_ends_the_nightly_chain(): void {
		$this->options['lafka'] = array( 'insights' => 'disabled' );
		Functions\expect( 'as_schedule_single_action' )->never();
		Lafka_Insights_Rollup::run_nightly();
		$this->assertSame( array(), $this->wpdb->queries );
	}

	public function test_scheduler_pins_jobs_to_local_wall_clock_time(): void {
		// Thu 2026-09-24 15:30 UTC == 11:30 in Toronto (EDT, UTC-4).
		$now = gmmktime( 15, 30, 0, 9, 24, 2026 );

		$nightly = Lafka_Insights_Scheduler::next_local( '03:10', null, $now, 'America/Toronto' );
		$this->assertSame( '2026-09-25 03:10 Fri', ( new \DateTime( '@' . $nightly ) )->setTimezone( new \DateTimeZone( 'America/Toronto' ) )->format( 'Y-m-d H:i D' ) );

		$weekly = Lafka_Insights_Scheduler::next_local( '08:00', 1, $now, 'America/Toronto' );
		$this->assertSame( '2026-09-28 08:00 Mon', ( new \DateTime( '@' . $weekly ) )->setTimezone( new \DateTimeZone( 'America/Toronto' ) )->format( 'Y-m-d H:i D' ) );

		// Across the November DST change the wall-clock time holds.
		$after_dst = Lafka_Insights_Scheduler::next_local( '08:00', 1, gmmktime( 12, 0, 0, 11, 2, 2026 ), 'America/Toronto' );
		$this->assertSame( '08:00', ( new \DateTime( '@' . $after_dst ) )->setTimezone( new \DateTimeZone( 'America/Toronto' ) )->format( 'H:i' ) );
	}

	public function test_scheduler_does_not_double_book_a_pending_job(): void {
		Functions\when( 'as_get_scheduled_actions' )->justReturn( array( 12 ) );
		Functions\expect( 'as_schedule_single_action' )->never();
		Functions\when( 'wp_timezone' )->alias( static fn() => new \DateTimeZone( 'UTC' ) );
		Lafka_Insights_Scheduler::ensure_scheduled();
		$this->addToAssertionCount( 1 );
	}

	public function test_biggest_leak_is_the_largest_step_to_step_loss(): void {
		$leak = Lafka_Insights_Queries::biggest_leak(
			array(
				'visit'    => 112,
				'menu'     => 31,
				'add'      => 9,
				'checkout' => 3,
				'order'    => 1,
			)
		);
		$this->assertSame(
			array(
				'from' => 'visit',
				'to'   => 'menu',
				'lost' => 81,
				'of'   => 112,
			),
			$leak
		);
		$this->assertNull( Lafka_Insights_Queries::biggest_leak( array( 'visit' => 0, 'menu' => 0 ) ) );
	}

	public function test_report_merges_finished_days_with_todays_live_sessions(): void {
		Functions\when( 'wc_get_orders' )->justReturn(
			array(
				new class() {
					public function get_meta( $k ) {
						return 'organic';
					}
				},
			)
		);
		Functions\when( 'wc_get_product' )->justReturn( null );
		$this->wpdb->results = array(
			"metric IN ('funnel','abandon'"   => array(
				array( 'metric' => 'funnel', 'dim' => 'visit', 'value' => 10 ),
				array( 'metric' => 'funnel', 'dim' => 'order', 'value' => 1 ),
			),
			"metric IN ('item_view'"         => array(
				array( 'metric' => 'item_view', 'dim' => '42', 'value' => 6 ),
				array( 'metric' => 'pay_fail', 'dim' => 'avs', 'value' => 2 ),
			),
			'FROM wp_lafka_insights_sessions' => $this->sessions(),
		);

		$report = Lafka_Insights_Queries::build( 7, '2026-09-24' );

		$this->assertSame( '2026-09-18', $report['from'] );
		$this->assertSame( 15, $report['funnel']['visit'], '10 rolled + 5 live today.' );
		$this->assertSame( 2, $report['funnel']['order'] );
		$this->assertSame( array( 'avs' => 2 ), $report['pay_fail'] );
		$this->assertSame( 6, $report['items']['42']['views'] );
		$this->assertSame( array( 'organic' => 1 ), $report['orders_by_source'] );
		$this->assertIsArray( $report['leak'] );
	}
}
