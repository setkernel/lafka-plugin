<?php
/**
 * KDSAllowedTransitionsTest — locks down the KDS workflow transition map.
 *
 * Before v9.7.1 the transition map lived as inline arrays in two places —
 * the admin bulk-action handler and the AJAX update_status endpoint. Drift
 * between them would mean a status change permitted via one entry point
 * (e.g. bulk-action "ready → completed") could be rejected on the other,
 * silently confusing operators. v9.7.1 hoisted it into a single static and
 * added the `lafka_kds_allowed_transitions` filter for extensibility.
 *
 * This test pins the canonical map so a refactor doesn't quietly drop a
 * legal transition (e.g. accidentally removing "ready → preparing" undo).
 *
 * @package Lafka_Kitchen_Display
 */

declare(strict_types=1);

namespace Lafka\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Lafka_KDS_Order_Statuses;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/incl/kitchen-display/includes/class-lafka-kds-order-statuses.php';

final class KDSAllowedTransitionsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// Filter is the only WP function the static touches; default behaviour:
		// pass the value through unchanged so we see the canonical map.
		Functions\when( 'apply_filters' )->alias(
			static fn( $hook, $value ) => $value
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_canonical_transition_map(): void {
		$expected = array(
			'processing' => array( 'accepted', 'rejected' ),
			'on-hold'    => array( 'accepted' ),
			'accepted'   => array( 'preparing', 'rejected', 'processing' ),
			'preparing'  => array( 'ready', 'accepted' ),
			'ready'      => array( 'completed', 'preparing' ),
		);
		$this->assertSame( $expected, Lafka_KDS_Order_Statuses::get_allowed_transitions() );
	}

	public function test_reject_path_reachable_from_processing_and_accepted(): void {
		$map = Lafka_KDS_Order_Statuses::get_allowed_transitions();
		$this->assertContains( 'rejected', $map['processing'] );
		$this->assertContains( 'rejected', $map['accepted'] );
	}

	public function test_undo_paths_present(): void {
		// "Undo" = step back to previous state. Operators rely on these to
		// recover from misclicks during a busy service.
		$map = Lafka_KDS_Order_Statuses::get_allowed_transitions();
		$this->assertContains( 'processing', $map['accepted'], 'accepted → processing (undo accept) missing' );
		$this->assertContains( 'accepted', $map['preparing'], 'preparing → accepted (undo start prep) missing' );
		$this->assertContains( 'preparing', $map['ready'], 'ready → preparing (undo mark ready) missing' );
	}

	public function test_completed_is_terminal(): void {
		// completed is the workflow exit; nothing should transition out of it
		// via this map (refunds etc. are a separate WC concern).
		$map = Lafka_KDS_Order_Statuses::get_allowed_transitions();
		$this->assertArrayNotHasKey( 'completed', $map );
		$this->assertArrayNotHasKey( 'rejected', $map );
	}

	public function test_filter_can_extend_map(): void {
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value ) {
				if ( 'lafka_kds_allowed_transitions' === $hook ) {
					$value['on-hold'][] = 'rejected';
				}
				return $value;
			}
		);

		$map = Lafka_KDS_Order_Statuses::get_allowed_transitions();
		$this->assertContains( 'rejected', $map['on-hold'], 'filter did not extend on-hold transitions' );
	}
}
