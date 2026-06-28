<?php
declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Regression lock (audit f100): the variation-swatches constructor must be
 * hooked to plugins_loaded in exactly ONE place — the swatches file that
 * defines it. The previous duplicate registration in the main plugin file
 * relied on undocumented hook-execution ordering (the function was only
 * defined later, when lafka_plugin_after_plugins_loaded required the swatches
 * file mid-dispatch). Re-introducing that line is the defect this guards.
 */
final class VariationSwatchesHookRegistrationTest extends TestCase {

	/**
	 * Regex matching an add_action that hooks the swatches constructor to
	 * plugins_loaded, regardless of single/double quotes or inner whitespace.
	 */
	private const HOOK_REGEX = "/add_action\(\s*['\"]plugins_loaded['\"]\s*,\s*['\"]lafka_wc_variation_swatches_constructor['\"]/";

	private static function read( string $relative ): string {
		$contents = file_get_contents( dirname( __DIR__, 2 ) . $relative );

		return false === $contents ? '' : $contents;
	}

	public function test_main_plugin_does_not_register_swatches_constructor(): void {
		$main  = self::read( '/lafka-plugin.php' );
		$count = preg_match_all( self::HOOK_REGEX, $main );

		$this->assertSame(
			0,
			$count,
			'lafka-plugin.php must not hook lafka_wc_variation_swatches_constructor to plugins_loaded; the swatches file owns that registration.'
		);
	}

	public function test_swatches_file_owns_single_registration(): void {
		$swatches = self::read( '/incl/swatches/variation-swatches.php' );
		$count    = preg_match_all( self::HOOK_REGEX, $swatches );

		$this->assertSame(
			1,
			$count,
			'variation-swatches.php must self-register its constructor on plugins_loaded exactly once.'
		);
	}

	public function test_constructor_keeps_woocommerce_guard(): void {
		$swatches = self::read( '/incl/swatches/variation-swatches.php' );

		$this->assertMatchesRegularExpression(
			"/function\s+lafka_wc_variation_swatches_constructor\s*\(\s*\)\s*\{\s*if\s*\(\s*function_exists\(\s*['\"]WC['\"]\s*\)\s*\)/",
			$swatches,
			'The swatches constructor must keep its function_exists( "WC" ) guard.'
		);
	}
}
