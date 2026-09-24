<?php
/**
 * The branch order-meta writer records a branch only when one was chosen:
 * an empty `lafka_selected_branch_id` would read as "some branch" to meta
 * queries and let the order escape the no-branch timeslot capacity count.
 *
 * @package Lafka_Plugin
 */

declare(strict_types=1);

namespace Lafka\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Lafka_Branch_Locations;
use Mockery;
use PHPUnit\Framework\TestCase;

final class BranchOrderMetaTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'get_term_meta' )->justReturn( 'delivery_pickup' );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'absint' )->alias( static fn( $v ) => abs( (int) $v ) );
		if ( ! class_exists( 'Lafka_Branch_Locations', false ) ) {
			require_once dirname( __DIR__, 2 ) . '/incl/branches/class-lafka-branch-locations.php';
		}
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Run the writer against a session and return the meta it set.
	 *
	 * @param array<string, mixed> $session_branch lafka_branch_location session value.
	 * @return array<string, mixed>
	 */
	private function written_meta( array $session_branch ): array {
		$session = new class( $session_branch ) {
			public function __construct( private array $branch ) {}
			public function get( $key ) {
				return 'lafka_branch_location' === $key ? $this->branch : null;
			}
		};
		Functions\when( 'WC' )->justReturn( (object) array( 'session' => $session ) );

		$written = array();
		$order   = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'update_meta_data' )->andReturnUsing(
			static function ( $key, $value ) use ( &$written ) {
				$written[ $key ] = $value;
			}
		);

		Lafka_Branch_Locations::checkout_field_update_order_meta_fields( $order );
		return $written;
	}

	public function test_no_branch_in_session_writes_no_branch_meta(): void {
		$this->assertArrayNotHasKey( 'lafka_selected_branch_id', $this->written_meta( array( 'order_type' => 'delivery' ) ) );
		$this->assertArrayNotHasKey( 'lafka_selected_branch_id', $this->written_meta( array( 'branch_id' => '' ) ) );
	}

	public function test_a_chosen_branch_is_recorded_with_its_order_type(): void {
		$meta = $this->written_meta(
			array(
				'branch_id'  => 7,
				'order_type' => 'pickup',
			)
		);

		$this->assertSame( '7', $meta['lafka_selected_branch_id'] );
		$this->assertSame( 'pickup', $meta['lafka_order_type'] );
	}
}
