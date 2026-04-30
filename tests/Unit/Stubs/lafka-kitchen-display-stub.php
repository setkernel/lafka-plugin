<?php
/**
 * Test-only stub for the Lafka_Kitchen_Display singleton — used by formatter
 * tests that need `get_order_type()` to return a controllable value without
 * loading the full kitchen-display module (which would hook itself into a
 * non-existent WP runtime at file-include time).
 *
 * @package Lafka_Kitchen_Display
 */

declare(strict_types=1);

if ( ! class_exists( 'Lafka_Kitchen_Display' ) ) {
	class Lafka_Kitchen_Display { // phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound

		/**
		 * Per-test override of the order type. Defaults to 'pickup' to match
		 * the production fallback when no shipping methods are present.
		 */
		public static string $type = 'pickup';

		public static function get_order_type( $order ): string { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
			return self::$type;
		}
	}
}
