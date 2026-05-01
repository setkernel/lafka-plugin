<?php
declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class LcpPreloadTest extends TestCase {
	private string $src;

	protected function setUp(): void {
		parent::setUp();
		$this->src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/perf/lcp-preload.php' );
	}

	public function test_module_file_exists(): void {
		$this->assertFileExists( dirname( __DIR__, 2 ) . '/incl/perf/lcp-preload.php' );
	}

	public function test_lcp_image_url_filter_registered(): void {
		$this->assertMatchesRegularExpression(
			"/add_filter\(\s*['\"]lafka_lcp_image_url['\"]/",
			$this->src
		);
	}

	public function test_filter_gated_to_front_page(): void {
		$this->assertStringContainsString( 'is_front_page()', $this->src );
	}

	public function test_resolves_attachment_id_via_wp_get_attachment_image_url(): void {
		$this->assertStringContainsString( 'wp_get_attachment_image_url', $this->src );
		$this->assertStringContainsString( 'is_numeric', $this->src );
	}

	public function test_falls_back_to_full_url_for_string_value(): void {
		$this->assertStringContainsString( 'esc_url_raw', $this->src );
	}

	public function test_fetchpriority_applied_to_hero_attachment(): void {
		$this->assertMatchesRegularExpression(
			"/add_filter\(\s*['\"]wp_get_attachment_image_attributes['\"]/",
			$this->src
		);
		$this->assertStringContainsString( "'fetchpriority'", $this->src );
		$this->assertStringContainsString( "'high'", $this->src );
		$this->assertStringContainsString( "'eager'", $this->src );
	}

	public function test_attachment_id_pulled_from_known_option(): void {
		$this->assertStringContainsString( 'lafka_homepage_hero_attachment_id', $this->src );
	}
}
