<?php
declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Tracking foundation: global page_context dataLayer push.
 */
final class AnalyticsPageContextTest extends TestCase {

	private string $src;

	protected function setUp(): void {
		$this->src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/analytics/lafka-page-context.php' );
	}

	public function test_emits_page_context_on_wp_head_priority_3(): void {
		$this->assertMatchesRegularExpression(
			"/add_action\(\s*'wp_head',\s*'lafka_analytics_emit_page_context',\s*3\s*\)/",
			$this->src
		);
		$this->assertStringContainsString( "'event'", $this->src );
		$this->assertStringContainsString( 'page_context', $this->src );
	}

	public function test_payload_has_all_dimensions(): void {
		foreach ( array(
			'page_type',
			'fulfilment_method',
			'store_open',
			'customer_logged_in',
			'customer_is_repeat',
			'cart_items_count',
			'cart_value_band',
			'top_category',
		) as $dim ) {
			$this->assertStringContainsString( "'$dim'", $this->src, "page_context must include $dim." );
		}
	}

	public function test_value_bands_match_known_aov(): void {
		$this->assertStringContainsString( 'function lafka_analytics_cart_value_band', $this->src );
		foreach ( array( 'under_25', '25_40', '40_55', '55_plus', 'empty' ) as $band ) {
			$this->assertStringContainsString( "'$band'", $this->src );
		}
	}

	public function test_shared_active_gate_present_and_used(): void {
		$this->assertStringContainsString( 'function lafka_analytics_is_active', $this->src );
		$this->assertStringContainsString( 'lafka_analytics_cf_beacon_token', $this->src,
			'The active gate must include the Cloudflare token destination.' );
		$this->assertMatchesRegularExpression(
			"/!\s*lafka_analytics_is_active\(\)/",
			$this->src,
			'Page context must bail when no analytics destination is configured.'
		);
	}
}
