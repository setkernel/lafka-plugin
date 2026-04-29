<?php
/**
 * C-10: load_plugin_textdomain() must run before init:5 so that CPT/taxonomy
 * labels (registered at init:5) see the loaded text domain.
 *
 * The original code hooked the textdomain load on `init` (priority 10), AFTER
 * label registration, so labels rendered untranslated on non-default locales.
 * The fix hooks on `plugins_loaded`, which runs strictly before `init`.
 *
 * Source-grep lock: assert the hook is `plugins_loaded`, not `init`.
 *
 * @package Lafka\Plugin\Tests\Unit
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class TextDomainPriorityTest extends TestCase {

	private function source(): string {
		$path = dirname( __DIR__, 2 ) . '/lafka-plugin.php';
		$this->assertFileExists( $path );

		return file_get_contents( $path );
	}

	public function test_textdomain_hooked_on_plugins_loaded(): void {
		$src = $this->source();

		$this->assertMatchesRegularExpression(
			"/add_action\(\s*'plugins_loaded'\s*,\s*'lafka_load_plugin_text_domain'\s*\)/",
			$src,
			'Textdomain loader must hook on plugins_loaded so it runs before init:5'
		);
	}

	public function test_textdomain_NOT_hooked_on_init(): void {
		$src = $this->source();

		$this->assertDoesNotMatchRegularExpression(
			"/add_action\(\s*'init'\s*,\s*'lafka_load_plugin_text_domain'\s*\)/",
			$src,
			'Textdomain loader must NOT hook on init (would run too late for init:5 label registration)'
		);
	}

	public function test_load_plugin_textdomain_call_present(): void {
		$src = $this->source();

		$this->assertStringContainsString(
			"load_plugin_textdomain( 'lafka-plugin'",
			$src
		);
	}
}
