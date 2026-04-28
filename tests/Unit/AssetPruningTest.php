<?php
declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * P6-PERF-4 W3-T2 regression lock: unused asset libraries must not be
 * enqueued on pages that don't need them.
 */
final class AssetPruningTest extends TestCase {

	public function test_revslider_pruning_module_exists(): void {
		$module = file_get_contents(
			dirname( __DIR__, 2 ) . '/incl/perf/lafka-asset-pruning.php'
		);
		$this->assertNotEmpty( $module );
	}

	public function test_revslider_dequeue_hooks_wp_enqueue_scripts(): void {
		$module = file_get_contents(
			dirname( __DIR__, 2 ) . '/incl/perf/lafka-asset-pruning.php'
		);
		$this->assertMatchesRegularExpression(
			"/add_action\(\s*['\"]wp_enqueue_scripts['\"]\s*,\s*['\"]lafka_perf_dequeue_unused_revslider['\"]/",
			$module
		);
	}

	public function test_revslider_pruning_checks_rev_slider_meta(): void {
		$module = file_get_contents(
			dirname( __DIR__, 2 ) . '/incl/perf/lafka-asset-pruning.php'
		);
		$this->assertStringContainsString( "'lafka_rev_slider'", $module );
	}

	public function test_revslider_pruning_checks_rev_slider_shortcode(): void {
		$module = file_get_contents(
			dirname( __DIR__, 2 ) . '/incl/perf/lafka-asset-pruning.php'
		);
		$this->assertStringContainsString( '[rev_slider', $module );
	}

	public function test_main_plugin_requires_module(): void {
		$main = file_get_contents( dirname( __DIR__, 2 ) . '/lafka-plugin.php' );
		$this->assertStringContainsString( 'lafka-asset-pruning.php', $main );
	}
}
