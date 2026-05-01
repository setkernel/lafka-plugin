<?php
declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class PerfBootstrapTest extends TestCase {
	public function test_bootstrap_requires_image_dimensions_module(): void {
		$src = file_get_contents( dirname( __DIR__, 2 ) . '/lafka-plugin.php' );
		$this->assertMatchesRegularExpression(
			"/require(_once)?\s+.*incl\/perf\/image-dimensions\.php/",
			$src,
			'lafka-plugin.php must require incl/perf/image-dimensions.php during bootstrap.'
		);
	}

	public function test_bootstrap_requires_lcp_preload_module(): void {
		$src = file_get_contents( dirname( __DIR__, 2 ) . '/lafka-plugin.php' );
		$this->assertMatchesRegularExpression(
			"/require(_once)?\s+.*incl\/perf\/lcp-preload\.php/",
			$src,
			'lafka-plugin.php must require incl/perf/lcp-preload.php during bootstrap.'
		);
	}
}
