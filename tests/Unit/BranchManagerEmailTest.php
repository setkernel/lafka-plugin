<?php
/**
 * Branch managers receive their branch's new/cancelled/failed order emails on
 * any WooCommerce store — not only when WC Analytics' Order override is loaded.
 *
 * @package Lafka_Plugin
 */

declare(strict_types=1);

namespace Lafka\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Lafka_Shipping_Areas;
use Mockery;
use PHPUnit\Framework\TestCase;

final class BranchManagerEmailTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		require_once dirname( __DIR__, 2 ) . '/incl/shipping-areas/class-lafka-shipping-areas.php';
		Functions\when( 'get_term_meta' )->alias( static fn( $term_id, $key ) => 3 === (int) $term_id && 'lafka_branch_user' === $key ? 42 : '' );
		Functions\when( 'get_userdata' )->alias( static fn( $id ) => 42 === (int) $id ? (object) array( 'user_email' => 'manager@example.test' ) : false );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function order_for_branch( string $branch_id ) {
		$order = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'get_meta' )->with( 'lafka_selected_branch_id' )->andReturn( $branch_id );
		return $order;
	}

	public function test_a_plain_wc_order_adds_the_branch_manager(): void {
		$this->assertSame(
			'owner@example.test,manager@example.test',
			Lafka_Shipping_Areas::add_recipient_to_order_emails( 'owner@example.test', $this->order_for_branch( '3' ), null )
		);
	}

	public function test_an_order_without_a_branch_keeps_the_recipients(): void {
		$this->assertSame(
			'owner@example.test',
			Lafka_Shipping_Areas::add_recipient_to_order_emails( 'owner@example.test', $this->order_for_branch( '' ), null )
		);
	}
}
