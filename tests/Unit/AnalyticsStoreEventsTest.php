<?php
declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Tracking foundation: store-specific events (enqueue + JS contracts).
 */
final class AnalyticsStoreEventsTest extends TestCase {

	private string $php;
	private string $js;

	protected function setUp(): void {
		$root      = dirname( __DIR__, 2 );
		$this->php = file_get_contents( $root . '/incl/analytics/lafka-store-events.php' );
		$this->js  = file_get_contents( $root . '/assets/js/lafka-store-events.js' );
	}

	public function test_enqueue_gated_on_analytics_active(): void {
		$this->assertMatchesRegularExpression(
			"/add_action\(\s*'wp_enqueue_scripts',\s*'lafka_analytics_enqueue_store_events'/",
			$this->php
		);
		$this->assertStringContainsString( 'lafka_analytics_is_active', $this->php );
		$this->assertStringContainsString( "wp_enqueue_script(\n\t\t\t'lafka-store-events'", $this->php );
	}

	public function test_js_emits_the_store_events(): void {
		foreach ( array( 'order_channel_click', 'select_fulfilment', 'select_addon', 'store_closed_view' ) as $ev ) {
			$this->assertStringContainsString( "event: '$ev'", $this->js, "store-events.js must push $ev." );
		}
	}

	public function test_js_binds_the_data_attr_contracts(): void {
		foreach ( array(
			'[data-lafka-order-channel]',
			'[data-lafka-fulfilment]',
			'.product-addon',
			'.lafka-store-closed-card',
		) as $sel ) {
			$this->assertStringContainsString( $sel, $this->js, "store-events.js must bind $sel." );
		}
	}

	public function test_order_channel_contract_documented(): void {
		$doc = file_get_contents( dirname( __DIR__, 2 ) . '/docs/TRACKING.md' );
		$this->assertStringContainsString( 'data-lafka-order-channel', $doc,
			'The order_channel data-attr contract must be documented for the conversion workstream.' );
	}
}
