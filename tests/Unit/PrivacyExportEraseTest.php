<?php
/**
 * PrivacyExportEraseTest — locks the NX1-06 GDPR exporter/eraser contract for
 * the two conversion tables that hold personal data.
 *
 *   - register() wires both the exporter and eraser filters.
 *   - register_exporters()/register_erasers() add a push + abandoned-cart entry
 *     with a friendly name and a callable callback.
 *   - push export/erase resolve the request email to a WP user and match on
 *     user_id; a non-user email yields nothing.
 *   - abandoned-cart export/erase match on the email column.
 *   - export output follows core's group/item/data shape and the paging `done`
 *     flag flips only when a full page is returned.
 *   - erase returns the core removed/retained/messages/done shape.
 *
 * @package Lafka\Plugin\Tests\Unit
 * @since   9.36.0
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Lafka_Conversion_Privacy;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/incl/conversion/lafka-push-db.php';
require_once dirname( __DIR__, 2 ) . '/incl/conversion/lafka-abandoned-cart-db.php';
require_once dirname( __DIR__, 2 ) . '/incl/conversion/class-lafka-conversion-privacy.php';

/**
 * In-memory $wpdb double returning a canned row set + recording deletes.
 */
class FakePrivacyWpdb {

	public string $prefix = 'wp_';
	/** @var array<int,object> */
	public array $results = array();
	public int $delete_return = 0;
	/** @var array<int,array{table:string,where:array}> */
	public array $deletes = array();

	public function prepare( $sql, ...$args ) {
		return (string) $sql;
	}

	public function get_results( $sql ) {
		return $this->results;
	}

	public function delete( $table, $where, $formats = null ) {
		$this->deletes[] = array(
			'table' => $table,
			'where' => $where,
		);
		return $this->delete_return;
	}
}

final class PrivacyExportEraseTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( '_n' )->alias(
			static function ( $single, $plural, $number ) {
				return 1 === (int) $number ? $single : $plural;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		unset( $GLOBALS['wpdb'] );
		parent::tearDown();
	}

	// ─── Registration ─────────────────────────────────────────────────────────

	public function test_register_hooks_both_privacy_filters(): void {
		// add_filter is defined by the test bootstrap before Patchwork loads, so
		// it can't be spied on via Brain Monkey. Assert the wiring by source: the
		// class's register() must add both core privacy filters, and calling it
		// must not error against the bootstrap's no-op add_filter.
		$src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/conversion/class-lafka-conversion-privacy.php' );
		$this->assertStringContainsString( "add_filter( 'wp_privacy_personal_data_exporters'", $src );
		$this->assertStringContainsString( "add_filter( 'wp_privacy_personal_data_erasers'", $src );

		( new Lafka_Conversion_Privacy() )->register();
		$this->assertTrue( true );
	}

	public function test_register_exporters_adds_push_and_ac(): void {
		$out = ( new Lafka_Conversion_Privacy() )->register_exporters( array() );
		$this->assertArrayHasKey( Lafka_Conversion_Privacy::EXPORTER_PUSH, $out );
		$this->assertArrayHasKey( Lafka_Conversion_Privacy::EXPORTER_AC, $out );
		$this->assertIsCallable( $out[ Lafka_Conversion_Privacy::EXPORTER_PUSH ]['callback'] );
		$this->assertArrayHasKey( 'exporter_friendly_name', $out[ Lafka_Conversion_Privacy::EXPORTER_AC ] );
	}

	public function test_register_erasers_adds_push_and_ac(): void {
		$out = ( new Lafka_Conversion_Privacy() )->register_erasers( array() );
		$this->assertArrayHasKey( Lafka_Conversion_Privacy::EXPORTER_PUSH, $out );
		$this->assertArrayHasKey( Lafka_Conversion_Privacy::EXPORTER_AC, $out );
		$this->assertIsCallable( $out[ Lafka_Conversion_Privacy::EXPORTER_AC ]['callback'] );
	}

	// ─── Push export ──────────────────────────────────────────────────────────

	public function test_export_push_shapes_rows_for_matched_user(): void {
		$wpdb          = new FakePrivacyWpdb();
		$wpdb->results = array(
			(object) array(
				'id'           => 7,
				'endpoint'     => 'https://push.example/abc',
				'user_agent'   => 'Mozilla/5.0',
				'locale'       => 'en_US',
				'created_at'   => '2026-01-01 10:00:00',
				'last_seen_at' => '2026-02-02 11:00:00',
			),
		);
		$GLOBALS['wpdb'] = $wpdb;
		Functions\when( 'get_user_by' )->justReturn( (object) array( 'ID' => 5 ) );

		$result = ( new Lafka_Conversion_Privacy() )->export_push( 'user@example.com', 1 );

		$this->assertTrue( $result['done'] );
		$this->assertCount( 1, $result['data'] );
		$item = $result['data'][0];
		$this->assertSame( 'lafka_push_subscriptions', $item['group_id'] );
		$this->assertSame( 'lafka-push-7', $item['item_id'] );

		$values = array_column( $item['data'], 'value' );
		$this->assertContains( 'https://push.example/abc', $values );
		$this->assertContains( 'Mozilla/5.0', $values );
		$this->assertContains( 'en_US', $values );
	}

	public function test_export_push_empty_when_no_matching_user(): void {
		$GLOBALS['wpdb'] = new FakePrivacyWpdb();
		Functions\when( 'get_user_by' )->justReturn( false );

		$result = ( new Lafka_Conversion_Privacy() )->export_push( 'nobody@example.com', 1 );

		$this->assertSame( array(), $result['data'] );
		$this->assertTrue( $result['done'] );
	}

	public function test_export_push_not_done_when_page_is_full(): void {
		$wpdb          = new FakePrivacyWpdb();
		$wpdb->results = array_fill(
			0,
			Lafka_Conversion_Privacy::PAGE_SIZE,
			(object) array( 'id' => 1, 'endpoint' => 'x' )
		);
		$GLOBALS['wpdb'] = $wpdb;
		Functions\when( 'get_user_by' )->justReturn( (object) array( 'ID' => 5 ) );

		$result = ( new Lafka_Conversion_Privacy() )->export_push( 'user@example.com', 1 );
		$this->assertFalse( $result['done'], 'A full page means there may be more — done must be false.' );
	}

	// ─── Push erase ───────────────────────────────────────────────────────────

	public function test_erase_push_hard_deletes_by_user(): void {
		$wpdb                = new FakePrivacyWpdb();
		$wpdb->delete_return = 3;
		$GLOBALS['wpdb']     = $wpdb;
		Functions\when( 'get_user_by' )->justReturn( (object) array( 'ID' => 5 ) );

		$result = ( new Lafka_Conversion_Privacy() )->erase_push( 'user@example.com', 1 );

		$this->assertSame( 3, $result['items_removed'] );
		$this->assertFalse( $result['items_retained'] );
		$this->assertSame( array(), $result['messages'] );
		$this->assertTrue( $result['done'] );
		$this->assertSame( array( 'user_id' => 5 ), $wpdb->deletes[0]['where'] );
	}

	public function test_erase_push_removes_nothing_for_unknown_email(): void {
		$GLOBALS['wpdb'] = new FakePrivacyWpdb();
		Functions\when( 'get_user_by' )->justReturn( false );

		$result = ( new Lafka_Conversion_Privacy() )->erase_push( 'nobody@example.com', 1 );
		$this->assertSame( 0, $result['items_removed'] );
		$this->assertTrue( $result['done'] );
	}

	// ─── Abandoned-cart export ────────────────────────────────────────────────

	public function test_export_abandoned_carts_shapes_rows(): void {
		$wpdb          = new FakePrivacyWpdb();
		$wpdb->results = array(
			(object) array(
				'id'               => 9,
				'customer_email'   => 'buyer@example.com',
				'cart_contents'    => json_encode( array( array( 'quantity' => 2 ), array( 'quantity' => 1 ) ) ),
				'cart_total'       => '25.0000',
				'currency'         => 'USD',
				'created_at'       => '2026-03-01 09:00:00',
				'last_seen_at'     => '2026-03-01 09:30:00',
				'recovery_sent_at' => '',
			),
		);
		$GLOBALS['wpdb'] = $wpdb;

		$result = ( new Lafka_Conversion_Privacy() )->export_abandoned_carts( 'buyer@example.com', 1 );

		$this->assertTrue( $result['done'] );
		$item = $result['data'][0];
		$this->assertSame( 'lafka_abandoned_carts', $item['group_id'] );
		$this->assertSame( 'lafka-ac-9', $item['item_id'] );

		$values = array_column( $item['data'], 'value' );
		$this->assertContains( 'buyer@example.com', $values );
		$summary = implode( ' | ', $values );
		$this->assertStringContainsString( '2 line items', $summary );
		$this->assertStringContainsString( '3 total quantity', $summary );
	}

	// ─── Abandoned-cart erase ─────────────────────────────────────────────────

	public function test_erase_abandoned_carts_deletes_by_email(): void {
		$wpdb                = new FakePrivacyWpdb();
		$wpdb->delete_return = 2;
		$GLOBALS['wpdb']     = $wpdb;

		$result = ( new Lafka_Conversion_Privacy() )->erase_abandoned_carts( 'buyer@example.com', 1 );

		$this->assertSame( 2, $result['items_removed'] );
		$this->assertTrue( $result['done'] );
		$this->assertSame( array( 'customer_email' => 'buyer@example.com' ), $wpdb->deletes[0]['where'] );
	}

	// ─── Runtime wiring ───────────────────────────────────────────────────────

	public function test_main_plugin_registers_conversion_privacy(): void {
		$main = file_get_contents( dirname( __DIR__, 2 ) . '/lafka-plugin.php' );
		$this->assertStringContainsString( 'incl/conversion/class-lafka-conversion-privacy.php', $main );
		$this->assertStringContainsString( 'new Lafka_Conversion_Privacy()', $main );
	}
}
