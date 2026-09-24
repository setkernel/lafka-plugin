<?php
declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Lafka_Order_Notifications;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

/**
 * f075: under HPOS the new-order poller must route by the branch meta stored on
 * the order (wc_orders_meta), not by wp_postmeta — which is empty there, so a
 * raw get_post_meta() read notified every operator of every branch's orders.
 *
 * Runs in its own process so WooCommerce's OrderUtil can report HPOS as on;
 * the shared unit-test stub hard-codes it off for the rest of the suite.
 */
final class OrderNotificationHposMetaTest extends TestCase {

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_under_hpos_branch_orders_only_alert_their_operator(): void {
		class_alias( HposEnabledOrderUtil::class, 'Automattic\WooCommerce\Utilities\OrderUtil' );
		Monkey\setUp();
		try {
			Functions\when( '__' )->returnArg();
			Functions\when( 'esc_html__' )->returnArg();
			Functions\when( 'esc_html' )->returnArg();
			Functions\when( 'esc_url_raw' )->returnArg();
			Functions\when( 'apply_filters' )->returnArg( 2 );
			Functions\when( 'plugins_url' )->justReturn( 'https://example.test/icon.png' );
			Functions\when( 'admin_url' )->alias( static fn( $path = '' ) => 'https://example.test/wp-admin/' . $path );
			Functions\when( 'get_option' )->justReturn( '' );
			Functions\when( 'get_user_meta' )->justReturn( array() );
			Functions\when( 'update_user_meta' )->justReturn( true );
			Functions\when( 'get_term' )->justReturn( null );
			Functions\when( 'wc_get_orders' )->justReturn( array( 201 ) );
			// wp_postmeta holds nothing for an HPOS order; the order object has it.
			Functions\when( 'get_post_meta' )->justReturn( '' );
			Functions\when( 'wc_get_order' )->justReturn(
				new class() {
					public function get_meta( $key ) {
						return 'lafka_selected_branch_id' === $key ? '55' : '';
					}
				}
			);
			Functions\when( 'get_term_meta' )->alias(
				static fn( $term_id, $key ) => 55 === (int) $term_id && 'lafka_branch_user' === $key ? '99' : ''
			);
			Functions\expect( 'update_meta_cache' )->never();
			require_once dirname( __DIR__, 2 ) . '/incl/shipping-areas/class-lafka-shipping-areas.php';
			require_once dirname( __DIR__, 2 ) . '/incl/class-lafka-options.php';
			require_once dirname( __DIR__, 2 ) . '/incl/admin/class-lafka-order-notifications.php';

			Functions\when( 'get_current_user_id' )->justReturn( 42 );
			$this->assertSame( '', Lafka_Order_Notifications::compute_notification(), 'Another branch operator is not alerted.' );

			Functions\when( 'get_current_user_id' )->justReturn( 99 );
			$this->assertStringContainsString( '#201', Lafka_Order_Notifications::compute_notification()['body'] ?? '' );
		} finally {
			Monkey\tearDown();
		}
	}
}

/** OrderUtil reporting High-Performance Order Storage as enabled. */
final class HposEnabledOrderUtil {
	public static function custom_orders_table_usage_is_enabled(): bool {
		return true;
	}
}
