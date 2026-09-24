<?php
/**
 * Store-events client JS is enqueued only when an analytics destination is
 * configured (including a CF-beacon-only site), so unconfigured installs pay
 * no request cost.
 *
 * @package Lafka\Plugin\Tests\Unit
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/incl/analytics/lafka-analytics-emitter.php';
require_once dirname( __DIR__, 2 ) . '/incl/analytics/lafka-wc-events.php';
require_once dirname( __DIR__, 2 ) . '/incl/analytics/lafka-cf-analytics.php';
require_once dirname( __DIR__, 2 ) . '/incl/analytics/lafka-page-context.php';
require_once dirname( __DIR__, 2 ) . '/incl/analytics/lafka-store-events.php';

final class AnalyticsStoreEventsTest extends TestCase {

	/** @var list<string> */
	private array $enqueued = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->enqueued = array();
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'get_theme_mod' )->returnArg( 2 );
		Functions\when( 'plugins_url' )->returnArg();
		Functions\when( 'lafka_plugin_asset_version' )->justReturn( '1' );
		Functions\when( 'wp_enqueue_script' )->alias(
			function ( $handle ) {
				$this->enqueued[] = $handle;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function configure( string $key, string $value ): void {
		Functions\when( 'get_theme_mod' )->alias(
			static fn( $k, $default = '' ) => $k === $key ? $value : $default
		);
	}

	public function test_not_enqueued_without_a_destination(): void {
		lafka_analytics_enqueue_store_events();
		$this->assertSame( array(), $this->enqueued );
	}

	public function test_enqueued_for_a_datalayer_destination_or_cf_beacon(): void {
		$this->configure( 'lafka_gtm_container_id', 'GTM-XYZ987' );
		lafka_analytics_enqueue_store_events();

		$this->configure( 'lafka_cf_beacon_token', 'abcdef0123456789abcdef0123456789' );
		lafka_analytics_enqueue_store_events();

		$this->assertSame( array( 'lafka-store-events', 'lafka-store-events' ), $this->enqueued );
	}

	public function test_not_enqueued_in_admin(): void {
		$this->configure( 'lafka_gtm_container_id', 'GTM-XYZ987' );
		Functions\when( 'is_admin' )->justReturn( true );
		lafka_analytics_enqueue_store_events();
		$this->assertSame( array(), $this->enqueued );
	}
}
