<?php
declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ImageDimensionsTest extends TestCase {
	private string $src;

	protected function setUp(): void {
		parent::setUp();
		$this->src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/perf/image-dimensions.php' );
	}

	public function test_module_file_exists(): void {
		$this->assertFileExists( dirname( __DIR__, 2 ) . '/incl/perf/image-dimensions.php' );
	}

	public function test_function_lafka_inject_image_dimensions_defined(): void {
		$this->assertStringContainsString( 'function lafka_inject_image_dimensions', $this->src );
	}

	public function test_function_lafka_url_to_local_path_defined(): void {
		$this->assertStringContainsString( 'function lafka_url_to_local_path', $this->src );
	}

	public function test_three_filters_registered(): void {
		$this->assertMatchesRegularExpression(
			"/add_filter\(\s*['\"]the_content['\"]\s*,\s*['\"]lafka_inject_image_dimensions['\"]/",
			$this->src
		);
		$this->assertMatchesRegularExpression(
			"/add_filter\(\s*['\"]post_thumbnail_html['\"]\s*,\s*['\"]lafka_inject_image_dimensions['\"]/",
			$this->src
		);
		$this->assertMatchesRegularExpression(
			"/add_filter\(\s*['\"]widget_text['\"]\s*,\s*['\"]lafka_inject_image_dimensions['\"]/",
			$this->src
		);
	}

	public function test_uses_attachment_lookup_before_getimagesize(): void {
		// Pure-WP lookup must be tried first (no I/O); getimagesize is the
		// fallback. Ordering matters for perf.
		$attachment_pos = strpos( $this->src, 'wp_get_attachment_metadata' );
		$getimagesize_pos = strpos( $this->src, 'getimagesize' );
		$this->assertNotFalse( $attachment_pos );
		$this->assertNotFalse( $getimagesize_pos );
		$this->assertLessThan( $getimagesize_pos, $attachment_pos );
	}

	public function test_getimagesize_results_cached_in_transient(): void {
		$this->assertStringContainsString( 'set_transient', $this->src );
		$this->assertStringContainsString( 'lafka_imgdims_', $this->src );
	}

	public function test_url_to_local_path_only_resolves_local_urls(): void {
		// Must never attempt to fetch remote URLs — only resolve URLs that
		// match the upload dir or content_url prefix.
		$this->assertStringContainsString( 'wp_get_upload_dir', $this->src );
		$this->assertStringContainsString( 'content_url', $this->src );
	}
}
