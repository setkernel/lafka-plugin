<?php
/**
 * GX1 / A4: the incident index — fingerprint dedupe, one upsert per
 * fingerprint per request, write cap, not-installed no-op, retention prune,
 * status changes, digest selection, and the facade → index wiring.
 *
 * @package Lafka_Plugin
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Lafka_Incidents;
use Lafka_Log;
use LafkaPlugin\Tests\Unit\Support\StableClock;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Support/StableClock.php';

require_once dirname( __DIR__, 2 ) . '/incl/observability/class-lafka-log-scrubber.php';
require_once dirname( __DIR__, 2 ) . '/incl/observability/class-lafka-log.php';
require_once dirname( __DIR__, 2 ) . '/incl/observability/class-lafka-incidents.php';

/**
 * $wpdb double: records prepared SQL, simulates the UNIQUE fingerprint upsert.
 */
final class FakeIncidentsWpdb {
	public string $prefix = 'wp_';
	/** @var array<int,string> */
	public array $queries = array();
	/** @var array<string,array<string,mixed>> fingerprint => row */
	public array $rows = array();
	/** @var array<int,array> */
	public array $updates = array();
	/** @var mixed */
	public $results = array();

	public function prepare( $sql, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		foreach ( $args as $a ) {
			$replacement = is_int( $a ) ? (string) $a : "'" . str_replace( "'", "\\'", (string) $a ) . "'";
			$sql         = preg_replace( '/%[dsf]/', $replacement, (string) $sql, 1 );
		}
		return $sql;
	}

	public function query( $sql ) {
		$this->queries[] = (string) $sql;
		if ( preg_match( "/^INSERT INTO \S+ \(fingerprint.*VALUES \('([a-f0-9]{40})'/s", (string) $sql, $m ) ) {
			$print = $m[1];
			if ( isset( $this->rows[ $print ] ) ) {
				++$this->rows[ $print ]['hit_count'];
			} else {
				$this->rows[ $print ] = array( 'hit_count' => 1 );
			}
			return 1;
		}
		return 0;
	}

	public function suppress_errors( $suppress = true ) {
		return false;
	}

	public function get_results( $sql ) {
		$this->queries[] = (string) $sql;
		return $this->results;
	}

	public function get_var( $sql ) {
		$this->queries[] = (string) $sql;
		return 3;
	}

	public function update( $table, $data, $where, $format = null, $where_format = null ) {
		$this->updates[] = array( $table, $data, $where );
		return 1;
	}
}

final class IncidentIndexTest extends TestCase {

	private FakeIncidentsWpdb $wpdb;

	/** @var array<string,mixed> */
	private array $options = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Lafka_Incidents::reset();
		Lafka_Log::reset();
		$this->wpdb      = new FakeIncidentsWpdb();
		$GLOBALS['wpdb'] = $this->wpdb;
		$this->options   = array( Lafka_Incidents::VERSION_OPTION => Lafka_Incidents::DB_VERSION );
		Functions\when( 'get_option' )->alias( fn( $key, $default = false ) => $this->options[ $key ] ?? $default );
		Lafka_Log::set_logger(
			new class() {
				public function log( $level, $message, $context = array() ): void {}
			}
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		Lafka_Log::reset();
		Monkey\tearDown();
		parent::tearDown();
	}

	/** @return array<string,mixed> */
	private static function record( string $message, string $channel = 'payment', string $level = 'warning' ): array {
		return array(
			'level'      => $level,
			'channel'    => $channel,
			'code'       => 'payment_avs',
			'message'    => $message,
			'context'    => array( 'order_id' => 1 ),
			'request_id' => 'abcdef0123456789',
			'file'       => '',
			'line'       => 0,
		);
	}

	public function test_a_record_upserts_one_row_with_on_duplicate_key(): void {
		self::assertTrue( Lafka_Incidents::record( self::record( 'Payment failed (avs)' ) ) );

		self::assertCount( 1, $this->wpdb->queries );
		self::assertStringContainsString( 'INSERT INTO wp_lafka_incidents', $this->wpdb->queries[0] );
		self::assertStringContainsString( 'ON DUPLICATE KEY UPDATE hit_count = hit_count + 1', $this->wpdb->queries[0] );
		self::assertStringContainsString( "status = IF(status = 'resolved', 'open', status)", $this->wpdb->queries[0] );
	}

	public function test_same_problem_is_written_once_per_request_and_counted_across_requests(): void {
		Lafka_Incidents::record( self::record( 'Payment failed for order 12' ) );
		Lafka_Incidents::record( self::record( 'Payment failed for order 13' ) ); // same fingerprint (numbers normalized).
		self::assertCount( 1, $this->wpdb->rows );
		self::assertSame( 1, reset( $this->wpdb->rows )['hit_count'] );

		Lafka_Incidents::reset(); // next request.
		Lafka_Incidents::record( self::record( 'Payment failed for order 14' ) );
		self::assertSame( 2, reset( $this->wpdb->rows )['hit_count'] );
	}

	public function test_different_problems_get_different_fingerprints(): void {
		self::assertNotSame(
			Lafka_Incidents::fingerprint( 'payment', 'payment_avs', 'x' ),
			Lafka_Incidents::fingerprint( 'payment', 'payment_cvv', 'x' )
		);
		self::assertNotSame(
			Lafka_Incidents::fingerprint( 'php', '', 'Fatal', 'plugins/lafka-plugin/a.php:10' ),
			Lafka_Incidents::fingerprint( 'php', '', 'Fatal', 'plugins/lafka-plugin/a.php:11' )
		);
		self::assertSame(
			Lafka_Incidents::fingerprint( 'rest', 'x', 'took 120 ms' ),
			Lafka_Incidents::fingerprint( 'rest', 'x', 'took  95 ms' )
		);
	}

	public function test_writes_per_request_are_capped(): void {
		for ( $i = 0; $i < 50; $i++ ) {
			Lafka_Incidents::record( self::record( 'distinct problem', 'core-' . $i ) );
		}

		self::assertCount( Lafka_Incidents::MAX_WRITES_PER_REQUEST, $this->wpdb->queries );
	}

	public function test_nothing_is_written_before_the_table_is_installed(): void {
		$this->options = array();

		self::assertFalse( Lafka_Incidents::record( self::record( 'x' ) ) );
		self::assertSame( array(), $this->wpdb->queries );
	}

	public function test_facade_indexes_warnings_but_not_info(): void {
		$this->options['lafka_log_settings'] = array( 'min_level' => 'debug' );

		Lafka_Log::info( 'checkout', 'Checkout blocked: store_closed' );
		self::assertSame( array(), $this->wpdb->queries );

		Lafka_Log::warning( 'payment', 'Payment failed (avs)', array( 'code' => 'payment_avs' ) );
		self::assertCount( 1, $this->wpdb->queries );
	}

	public function test_prune_deletes_rows_older_than_the_retention_window(): void {
		// prune() reads the real clock; pin the expected cutoff day to the
		// instant it ran so a run straddling midnight UTC cannot disagree.
		list( , $cutoff ) = StableClock::run(
			static fn(): string => gmdate( 'Y-m-d', time() - 90 * 86400 ),
			function (): void {
				$this->wpdb->queries = array();
				Lafka_Incidents::prune( 90 );
			}
		);

		self::assertCount( 1, $this->wpdb->queries );
		self::assertStringStartsWith( 'DELETE FROM wp_lafka_incidents WHERE last_seen < ', $this->wpdb->queries[0] );
		self::assertStringContainsString( $cutoff, $this->wpdb->queries[0] );
	}

	public function test_status_changes_are_validated(): void {
		self::assertTrue( Lafka_Incidents::set_status( 7, 'muted' ) );
		self::assertFalse( Lafka_Incidents::set_status( 7, 'deleted' ) );
		self::assertFalse( Lafka_Incidents::set_status( 0, 'resolved' ) );

		self::assertSame( array( 'wp_lafka_incidents', array( 'status' => 'muted' ), array( 'id' => 7 ) ), $this->wpdb->updates[0] );
		self::assertCount( 1, $this->wpdb->updates );
	}

	public function test_digest_selects_new_or_recurring_open_problems_on_the_right_channels(): void {
		Lafka_Incidents::pending_digest();

		$sql = $this->wpdb->queries[0];
		self::assertStringContainsString( "status = 'open'", $sql );
		self::assertStringContainsString( 'notified_at IS NULL OR last_seen > notified_at', $sql );
		self::assertStringContainsString( "channel IN ('payment','checkout','php','js')", $sql );
		self::assertStringContainsString( "level IN ('error','critical','alert','emergency')", $sql );
	}

	public function test_schema_has_the_dedupe_key_and_lifecycle_columns(): void {
		$sql = Lafka_Incidents::schema_sql();

		self::assertStringContainsString( 'UNIQUE KEY fingerprint (fingerprint)', $sql );
		foreach ( array( 'hit_count', 'first_seen', 'last_seen', 'status', 'notified_at', 'sample_context', 'last_request_id' ) as $column ) {
			self::assertStringContainsString( $column, $sql );
		}
	}
}
