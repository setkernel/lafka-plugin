<?php
declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Tracking foundation: Microsoft Clarity custom tags.
 */
final class AnalyticsClarityTagsTest extends TestCase {

	private string $php;
	private string $js;

	protected function setUp(): void {
		$root      = dirname( __DIR__, 2 );
		$this->php = file_get_contents( $root . '/incl/analytics/lafka-clarity-tags.php' );
		$this->js  = file_get_contents( $root . '/assets/js/lafka-clarity-tags.js' );
	}

	public function test_enqueue_gated_on_clarity_or_filter(): void {
		$this->assertStringContainsString( 'lafka_analytics_clarity_id', $this->php );
		$this->assertStringContainsString( "apply_filters( 'lafka_enable_clarity_tags'", $this->php );
		$this->assertMatchesRegularExpression(
			"/add_action\(\s*'wp_enqueue_scripts',\s*'lafka_analytics_enqueue_clarity_tags'/",
			$this->php
		);
	}

	public function test_js_sets_tags_and_is_safe_without_clarity(): void {
		$this->assertStringContainsString( "window.clarity('set'", $this->js );
		$this->assertStringContainsString( "typeof window.clarity === 'function'", $this->js );
		// must not break GTM: it wraps dataLayer.push but still calls the original.
		$this->assertStringContainsString( 'origPush.apply', $this->js );
	}

	public function test_js_maps_page_context_and_funnel(): void {
		$this->assertStringContainsString( "'page_context'", $this->js );
		$this->assertStringContainsString( 'funnel_step', $this->js );
		$this->assertStringContainsString( "'identify'", $this->js );
	}
}
