<?php
/**
 * PushBroadcastQueueTest — locks down the async broadcast queue (audit f051).
 *
 * The admin "Send now" button used to call lafka_push_broadcast() inline during
 * the page render, looping a blocking per-row curl_exec over up to 5000 rows —
 * any non-trivial audience blew past max_execution_time, killed the request
 * mid-loop, sent partially, and could not resume. This suite asserts the fix:
 *
 *   - lafka_push_enqueue_broadcast() resolves the audience once, persists a job
 *     record, writes a 'queued' activity-log entry, schedules a WP-Cron batch,
 *     and returns immediately (never sends inline).
 *   - lafka_push_run_broadcast_batch() drains the audience across multiple cron
 *     ticks, advancing a row-id cursor so it never re-sends and never skips,
 *     updates the activity-log entry in place, finalises it as 'done', and
 *     cleans up the job record.
 *   - the runner is a safe no-op for an unknown / already-complete job.
 *
 * @package Lafka\Plugin\Tests\Unit
 * @since   9.29.3
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use PHPUnit\Framework\TestCase;

if ( ! defined( 'LAFKA_TESTING' ) ) {
	define( 'LAFKA_TESTING', true );
}
if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

require_once dirname( __DIR__, 2 ) . '/incl/conversion/lafka-push-db.php';
require_once dirname( __DIR__, 2 ) . '/incl/conversion/lafka-push-sender.php';

/**
 * Cursor-aware $wpdb stand-in: get_results() honours the `id > %d` cursor and
 * the LIMIT, so the batch loop behaves exactly as it would against MySQL.
 */
class FakeQueueWpdb {

	public string $prefix = 'wp_';
	/** @var array<int,array<string,mixed>> */
	public array $rows = array();
	/** @var array<int,mixed> */
	public array $last_args = array();

	public function prepare( $sql, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		$this->last_args = $args;
		return $sql;
	}

	public function get_var( $sql ) {
		// COUNT(*) of active rows — the cursor fetch never calls get_var().
		$active = 0;
		foreach ( $this->rows as $row ) {
			if ( empty( $row['unsubscribed_at'] ) ) {
				++$active;
			}
		}
		return $active;
	}

	public function get_results( $sql ) {
		$args  = $this->last_args;
		$limit = (int) array_pop( $args );
		$after = (int) array_pop( $args );

		$matched = array();
		foreach ( $this->rows as $row ) {
			if ( empty( $row['unsubscribed_at'] ) && (int) $row['id'] > $after ) {
				$matched[] = $row;
			}
		}
		usort(
			$matched,
			static function ( $a, $b ) {
				return (int) $a['id'] <=> (int) $b['id'];
			}
		);
		$matched = array_slice( $matched, 0, $limit );

		$out = array();
		foreach ( $matched as $row ) {
			$out[] = (object) $row;
		}
		return $out;
	}
}

#[RunTestsInSeparateProcesses]
#[PreserveGlobalState( false )]
final class PushBroadcastQueueTest extends TestCase {

	/** @var array<string,mixed> In-memory wp_options stand-in. */
	private array $opts = array();
	/** @var array<int,array<string,mixed>> Captured wp_schedule_single_event() calls. */
	private array $scheduled = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->opts      = array();
		$this->scheduled = array();

		Functions\when( '__' )->returnArg();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'get_current_user_id' )->justReturn( 0 );
		Functions\when( 'get_locale' )->justReturn( 'en_US' );
		// Push left disabled → lafka_push_send() fails fast (no crypto/network).
		Functions\when( 'get_theme_mod' )->returnArg( 2 );

		// Tiny batch + one batch per tick so the resume/reschedule path runs
		// deterministically without depending on wall-clock time.
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value = null ) {
				if ( 'lafka_push_broadcast_batch_size' === $hook ) {
					return 2;
				}
				if ( 'lafka_push_broadcast_max_batches_per_tick' === $hook ) {
					return 1;
				}
				return $value;
			}
		);

		// In-memory option store so job state + activity log persist across calls.
		Functions\when( 'get_option' )->alias(
			function ( $key, $default = false ) {
				return array_key_exists( $key, $this->opts ) ? $this->opts[ $key ] : $default;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $key, $value, $autoload = null ) {
				$this->opts[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( $key ) {
				unset( $this->opts[ $key ] );
				return true;
			}
		);

		Functions\when( 'wp_schedule_single_event' )->alias(
			function ( $when, $hook, $args = array() ) {
				$this->scheduled[] = array(
					'when' => $when,
					'hook' => $hook,
					'args' => $args,
				);
				return true;
			}
		);

		global $wpdb;
		$wpdb = new FakeQueueWpdb();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Seed N active subscription rows in the fake DB.
	 *
	 * @param int $count
	 */
	private function seed_rows( int $count ): void {
		global $wpdb;
		for ( $i = 1; $i <= $count; $i++ ) {
			$wpdb->rows[] = array(
				'id'              => $i,
				'user_id'         => $i,
				'endpoint'        => 'https://fcm.googleapis.com/fcm/send/row' . $i,
				'p256dh'          => 'pub' . $i,
				'auth'            => 'auth' . $i,
				'unsubscribed_at' => null,
			);
		}
	}

	private function payload(): array {
		return array(
			'title' => 'Lunch deal',
			'body'  => 'Two for one today.',
			'url'   => 'https://example.com/menu/',
			'icon'  => '',
		);
	}

	// ─────────────────────────────────────────────────────────────────────────
	// enqueue: never sends inline, returns a queued descriptor, schedules cron
	// ─────────────────────────────────────────────────────────────────────────

	public function test_enqueue_returns_queued_descriptor_with_audience_size(): void {
		$this->seed_rows( 5 );
		$result = \lafka_push_enqueue_broadcast( 'all', $this->payload() );

		$this->assertIsArray( $result );
		$this->assertTrue( $result['queued'] );
		$this->assertNotEmpty( $result['job_id'] );
		$this->assertSame( 5, $result['audience_size'] );
		$this->assertSame( 0, $result['sent'] );
		$this->assertSame( 0, $result['failed'] );
	}

	public function test_enqueue_schedules_a_single_background_batch(): void {
		$this->seed_rows( 5 );
		$result = \lafka_push_enqueue_broadcast( 'all', $this->payload() );

		$this->assertCount( 1, $this->scheduled, 'Enqueue must schedule exactly one cron batch.' );
		$this->assertSame( 'lafka_push_broadcast_batch', $this->scheduled[0]['hook'] );
		$this->assertSame( array( $result['job_id'] ), $this->scheduled[0]['args'] );
	}

	public function test_enqueue_writes_a_queued_activity_log_entry(): void {
		$this->seed_rows( 5 );
		$result = \lafka_push_enqueue_broadcast( 'all', $this->payload() );

		$log = $this->opts['lafka_push_activity_log'] ?? array();
		$this->assertCount( 1, $log );
		$this->assertSame( 'queued', $log[0]['status'] );
		$this->assertSame( $result['job_id'], $log[0]['job'] );
		$this->assertSame( 5, $log[0]['size'] );
		$this->assertSame( 'Lunch deal', $log[0]['title'] );
	}

	public function test_enqueue_persists_a_job_record_with_zeroed_cursor(): void {
		$this->seed_rows( 5 );
		$result = \lafka_push_enqueue_broadcast( 'all', $this->payload() );

		$job = \lafka_push_get_job( $result['job_id'] );
		$this->assertNotNull( $job );
		$this->assertSame( 'queued', $job['status'] );
		$this->assertSame( 0, $job['cursor'] );
		$this->assertNull( $job['user_ids'], 'The "all" audience must store a null user-id filter.' );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// batch runner: drains across ticks, never re-sends, finalises + cleans up
	// ─────────────────────────────────────────────────────────────────────────

	public function test_runner_drains_audience_across_multiple_ticks(): void {
		$this->seed_rows( 5 );
		$enqueue = \lafka_push_enqueue_broadcast( 'all', $this->payload() );
		$job_id  = $enqueue['job_id'];

		// batch_size=2, max_batches_per_tick=1 → 2 rows drained per tick.
		\lafka_push_run_broadcast_batch( $job_id ); // rows 1,2
		$mid = \lafka_push_get_job( $job_id );
		$this->assertNotNull( $mid, 'Job must still exist mid-flight.' );
		$this->assertSame( 2, $mid['cursor'], 'Cursor must advance to the last sent row id.' );
		$this->assertSame( 2, $mid['processed'] );
		$this->assertSame( 'sending', $mid['status'] );

		\lafka_push_run_broadcast_batch( $job_id ); // rows 3,4
		$mid2 = \lafka_push_get_job( $job_id );
		$this->assertNotNull( $mid2 );
		$this->assertSame( 4, $mid2['cursor'] );
		$this->assertSame( 4, $mid2['processed'] );

		\lafka_push_run_broadcast_batch( $job_id ); // row 5 → complete
		$this->assertNull( \lafka_push_get_job( $job_id ), 'Completed job record must be cleaned up.' );
	}

	public function test_runner_never_resends_a_row(): void {
		$this->seed_rows( 5 );
		$enqueue = \lafka_push_enqueue_broadcast( 'all', $this->payload() );
		$job_id  = $enqueue['job_id'];

		// Drain fully (3 ticks for 5 rows at 2/tick).
		\lafka_push_run_broadcast_batch( $job_id );
		\lafka_push_run_broadcast_batch( $job_id );
		\lafka_push_run_broadcast_batch( $job_id );

		$log   = $this->opts['lafka_push_activity_log'] ?? array();
		$entry = $log[0];
		// Push is disabled in this harness, so every send fails exactly once.
		// sent + failed must equal the audience size with no double counting.
		$this->assertSame( 0, $entry['sent'] );
		$this->assertSame( 5, $entry['failed'] );
		$this->assertSame( 'done', $entry['status'] );
		$this->assertCount( 1, $log, 'A broadcast must occupy exactly one activity-log row.' );
	}

	public function test_runner_reschedules_only_while_rows_remain(): void {
		$this->seed_rows( 5 );
		$enqueue = \lafka_push_enqueue_broadcast( 'all', $this->payload() );
		$job_id  = $enqueue['job_id'];

		// One schedule from enqueue.
		$this->assertCount( 1, $this->scheduled );

		\lafka_push_run_broadcast_batch( $job_id ); // rows 1,2 → reschedule
		\lafka_push_run_broadcast_batch( $job_id ); // rows 3,4 → reschedule
		\lafka_push_run_broadcast_batch( $job_id ); // row 5 → complete, no reschedule

		// enqueue(1) + tick1(1) + tick2(1) = 3; the completing tick must not reschedule.
		$this->assertCount( 3, $this->scheduled );
		foreach ( $this->scheduled as $event ) {
			$this->assertSame( 'lafka_push_broadcast_batch', $event['hook'] );
			$this->assertSame( array( $job_id ), $event['args'] );
		}
	}

	public function test_runner_is_a_noop_for_unknown_job(): void {
		$this->seed_rows( 3 );
		\lafka_push_run_broadcast_batch( 'does-not-exist' );

		$this->assertSame( array(), $this->opts['lafka_push_activity_log'] ?? array() );
		$this->assertCount( 0, $this->scheduled );
	}

	public function test_runner_is_a_noop_for_completed_job(): void {
		$job_id = 'already-done';
		$this->opts[ \lafka_push_job_option_key( $job_id ) ] = array(
			'id'     => $job_id,
			'status' => 'complete',
		);
		\lafka_push_run_broadcast_batch( $job_id );

		// No new schedule, job record left untouched.
		$this->assertCount( 0, $this->scheduled );
		$this->assertNotNull( \lafka_push_get_job( $job_id ) );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// source-pins: the admin path must be off-thread, and the wiring present
	// ─────────────────────────────────────────────────────────────────────────

	public function test_admin_send_path_uses_the_async_enqueue(): void {
		$src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/admin/class-lafka-push-admin.php' );
		$this->assertStringContainsString( 'lafka_push_enqueue_broadcast', $src );
		$this->assertStringNotContainsString( 'return lafka_push_broadcast(', $src );
	}

	public function test_sender_registers_the_batch_cron_handler(): void {
		$src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/conversion/lafka-push-sender.php' );
		$this->assertStringContainsString( "add_action( 'lafka_push_broadcast_batch', 'lafka_push_run_broadcast_batch'", $src );
		$this->assertStringContainsString( 'set_time_limit( 0 )', $src );
		$this->assertStringContainsString( 'ignore_user_abort( true )', $src );
	}
}
