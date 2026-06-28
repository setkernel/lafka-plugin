<?php
/**
 * C-6 Site 1: Privileged stored XSS via addon option labels.
 *
 * Source-grep lock that enforces:
 *   - the 3 addon templates (textarea / checkbox / radiobutton) use
 *     `esc_html()` on the label output (label is plain text from a
 *     stored field), and
 *   - the price output uses `wp_kses_post()` (price is wc_price() HTML
 *     containing <span>, <bdi>, <sup> tags that must survive — escaping
 *     it via esc_html() displays raw HTML entities to customers, which
 *     is the v8.17.0 production bug fixed in v8.17.2), and
 *   - `wptexturize()` is no longer the OUTERMOST call (so HTML in a
 *     stored label can no longer reach the browser).
 *
 * Stored labels come from privileged users, but the audit treats the
 * shop manager → public-page surface as XSS-relevant because shop
 * managers can be lower-trust than admins, and the labels render on
 * checkout / product pages to all visitors.
 *
 * @package Lafka\Plugin\Tests\Unit
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class AddonLabelEscapingTest extends TestCase {

	/** @return array<string, array<int, string>> */
	public static function templateProvider(): array {
		$base = dirname( __DIR__, 2 ) . '/incl/addons/templates/';

		return array(
			'textarea'    => array( $base . 'textarea.php' ),
			'checkbox'    => array( $base . 'checkbox.php' ),
			'radiobutton' => array( $base . 'radiobutton.php' ),
		);
	}

	#[DataProvider('templateProvider')]
	public function test_template_uses_esc_html_on_label_and_price( string $path ): void {
		$this->assertFileExists( $path );
		$src = file_get_contents( $path );

		$this->assertStringContainsString(
			"esc_html( wptexturize( \$option['label'] ) )",
			$src,
			"Template {$path} must escape the label via esc_html() (after wptexturize())"
		);
		$this->assertStringContainsString(
			'wp_kses_post( $price )',
			$src,
			"Template {$path} must sanitize the price via wp_kses_post() — esc_html() escapes the wc_price() HTML to entities and renders raw <span> markup to customers"
		);
		$this->assertStringNotContainsString(
			'esc_html( $price )',
			$src,
			"Template {$path} must NOT use esc_html(\$price) — that's the v8.17.0 production bug. Use wp_kses_post(\$price) which preserves wc_price() HTML"
		);
	}

	#[DataProvider('templateProvider')]
	public function test_wptexturize_is_no_longer_outermost_call( string $path ): void {
		$src = file_get_contents( $path );

		// The dangerous pattern: `echo wptexturize( $option['label'] . ...`
		// or `echo wptexturize( $option['label'] ) . ...` with no escaping.
		$this->assertDoesNotMatchRegularExpression(
			'/echo\s+wptexturize\(\s*\$option\[\x27label\x27\]\s*\.\s*\x27 \x27\s*\.\s*\$price\s*\)/',
			$src,
			"Template {$path} must not use bare wptexturize() as outermost call (no HTML escape)"
		);
		$this->assertDoesNotMatchRegularExpression(
			'/echo\s+wptexturize\(\s*\$option\[\x27label\x27\]\s*\)\s*\.\s*\x27 \x27\s*\.\s*\$price\s*;/',
			$src,
			"Template {$path} must not concatenate raw \$price after wptexturize()"
		);
	}
}
