<?php
/**
 * C-6 Site 3: JSON-LD <script> stored-XSS defense.
 *
 * `wp_json_encode()` with only `JSON_UNESCAPED_SLASHES` lets a `</script>`
 * substring (e.g. inside a product name) close the script tag and turn the
 * remainder of the JSON into HTML. Adding `JSON_HEX_TAG` (and friends) escapes
 * `<`, `>`, `&`, `'`, `"` to their `\uXXXX` equivalents inside the JSON, so the
 * literal `</script>` tag-end can never reach the parser.
 *
 * Source-grep lock: assert the wp_json_encode() call in the JSON-LD orchestrator
 * uses `JSON_HEX_TAG` (and the other three hex flags).
 *
 * @package Lafka\Plugin\Tests\Unit
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class JsonLdScriptDefenseTest extends TestCase {

	private function source(): string {
		$path = dirname( __DIR__, 2 ) . '/incl/schema/class-lafka-json-ld.php';
		$this->assertFileExists( $path );

		return file_get_contents( $path );
	}

	public function test_wp_json_encode_uses_json_hex_tag(): void {
		$src = $this->source();

		$this->assertStringContainsString(
			'JSON_HEX_TAG',
			$src,
			'JSON-LD <script> emit must include JSON_HEX_TAG to neutralise </script>'
		);
	}

	public function test_wp_json_encode_uses_full_hex_flag_set(): void {
		$src = $this->source();

		$this->assertStringContainsString( 'JSON_HEX_AMP', $src );
		$this->assertStringContainsString( 'JSON_HEX_APOS', $src );
		$this->assertStringContainsString( 'JSON_HEX_QUOT', $src );
	}

	public function test_unescaped_slashes_still_used_for_url_readability(): void {
		$src = $this->source();
		// We didn't drop UNESCAPED_SLASHES; URLs still render as-is.
		$this->assertStringContainsString( 'JSON_UNESCAPED_SLASHES', $src );
	}

	public function test_wp_json_encode_still_called(): void {
		$src = $this->source();
		$this->assertMatchesRegularExpression(
			'/wp_json_encode\(\s*\$payload/',
			$src,
			'wp_json_encode( $payload, ... ) call must still be present'
		);
	}
}
