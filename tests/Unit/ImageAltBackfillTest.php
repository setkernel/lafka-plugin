<?php
declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * P6-A11Y-9 W2-T7: regression-lock the image-alt backfill CLI logic.
 */
final class ImageAltBackfillTest extends TestCase {

	private string $module;

	protected function setUp(): void {
		parent::setUp();
		$this->module = file_get_contents(
			dirname( __DIR__, 2 ) . '/incl/cli/lafka-image-alt-backfill.php'
		);
	}

	public function test_module_exists(): void {
		$this->assertNotEmpty( $this->module );
	}

	public function test_cli_command_registered(): void {
		$this->assertMatchesRegularExpression(
			"/WP_CLI::add_command\(\s*['\"]lafka image-alts['\"]\s*,/",
			$this->module
		);
	}

	public function test_subcommands_present(): void {
		$this->assertMatchesRegularExpression( '/public function scan\(/', $this->module );
		$this->assertMatchesRegularExpression( '/public function apply\(/', $this->module );
	}

	public function test_uses_wp_attachment_image_alt_meta(): void {
		$this->assertStringContainsString( "'_wp_attachment_image_alt'", $this->module );
	}

	public function test_filename_patterns_filtered_as_not_meaningful(): void {
		// Make sure the heuristic catches the audit's actual offenders.
		$this->assertStringContainsString( 'untitled', $this->module );
		$this->assertStringContainsString( 'gemini_generated', $this->module );
		$this->assertMatchesRegularExpression( '/-\\\\d\{2,4\}x\\\\d\{2,4\}/', $this->module );
	}

	public function test_main_plugin_requires_module_under_wp_cli(): void {
		$main = file_get_contents( dirname( __DIR__, 2 ) . '/lafka-plugin.php' );
		$this->assertStringContainsString( 'lafka-image-alt-backfill.php', $main );
	}
}
