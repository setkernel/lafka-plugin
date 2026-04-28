<?php
/**
 * Regression guard for P6-PERF-10 — suppress the broken
 * 'shipping-workshop-block' stylesheet when its source file is absent.
 *
 * This is a source-grep test: it verifies that the suppression code is
 * present and structurally correct in the compat shim, without booting
 * WordPress or requiring the third-party plugin to be installed. If a future
 * refactor silently removes the fix, CI catches it here.
 */

declare(strict_types=1);

namespace Lafka\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ShippingWorkshopStyleSuppressionTest extends TestCase {

	private string $compat_file;

	protected function setUp(): void {
		parent::setUp();
		$this->compat_file = dirname( __DIR__, 2 )
			. '/incl/compat/lafka-address-autocomplete-compat.php';
	}

	// ─── File presence ───────────────────────────────────────────────────────

	public function test_compat_file_exists(): void {
		self::assertFileExists(
			$this->compat_file,
			'incl/compat/lafka-address-autocomplete-compat.php must exist.'
		);
	}

	// ─── Source contains the suppression hook ────────────────────────────────

	public function test_file_registers_wp_enqueue_scripts_hook(): void {
		$source = file_get_contents( $this->compat_file );
		self::assertIsString( $source );
		self::assertStringContainsString(
			"add_action( 'wp_enqueue_scripts'",
			$source,
			"The shim must register a wp_enqueue_scripts action to fire the suppression."
		);
	}

	public function test_hook_runs_at_late_priority(): void {
		$source = file_get_contents( $this->compat_file );
		self::assertIsString( $source );
		// Priority must be >= 100 so it fires after the third-party plugin's enqueue.
		preg_match(
			"/add_action\s*\(\s*'wp_enqueue_scripts'\s*,\s*'lafka_suppress_missing_shipping_workshop_style'\s*,\s*(\d+)\s*\)/",
			$source,
			$matches
		);
		self::assertNotEmpty(
			$matches,
			'add_action() call for lafka_suppress_missing_shipping_workshop_style not found.'
		);
		self::assertGreaterThanOrEqual(
			100,
			(int) $matches[1],
			'Hook priority must be >= 100 to fire after the third-party plugin enqueue.'
		);
	}

	public function test_suppression_function_dequeues_correct_handle(): void {
		$source = file_get_contents( $this->compat_file );
		self::assertIsString( $source );
		self::assertStringContainsString(
			"wp_dequeue_style( 'shipping-workshop-block' )",
			$source,
			"The suppression function must dequeue the 'shipping-workshop-block' handle."
		);
	}

	public function test_suppression_function_deregisters_correct_handle(): void {
		$source = file_get_contents( $this->compat_file );
		self::assertIsString( $source );
		self::assertStringContainsString(
			"wp_deregister_style( 'shipping-workshop-block' )",
			$source,
			"The suppression function must deregister the 'shipping-workshop-block' handle."
		);
	}

	public function test_suppression_guarded_by_file_exists_check(): void {
		$source = file_get_contents( $this->compat_file );
		self::assertIsString( $source );
		self::assertStringContainsString(
			'file_exists( $plugin_css )',
			$source,
			'Suppression must be guarded by file_exists() so it self-heals when the upstream ships the file.'
		);
	}

	public function test_suppression_targets_correct_plugin_path(): void {
		$source = file_get_contents( $this->compat_file );
		self::assertIsString( $source );
		self::assertStringContainsString(
			'address-field-autocomplete-for-woocommerce/build/style-index.css',
			$source,
			'The checked path must reference the known-missing style-index.css from the autocomplete plugin.'
		);
	}

	// ─── Plugin main file wires the compat shim ──────────────────────────────

	public function test_main_plugin_file_requires_compat_shim(): void {
		$main_plugin = dirname( __DIR__, 2 ) . '/lafka-plugin.php';
		self::assertFileExists( $main_plugin );
		$source = file_get_contents( $main_plugin );
		self::assertIsString( $source );
		self::assertStringContainsString(
			'incl/compat/lafka-address-autocomplete-compat.php',
			$source,
			'lafka-plugin.php must require the address-autocomplete compat shim.'
		);
	}
}
