<?php
/**
 * PHPUnit bootstrap for the Lafka plugin test harness.
 *
 * Loads Composer's autoloader (which pulls in PHPUnit + Brain Monkey + Mockery)
 * and defines the bare-minimum WP constants that plugin source files reference
 * at file-include time. We do NOT boot WordPress here — these are unit tests;
 * any WP function a unit test invokes must be mocked via Brain Monkey.
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

if ( ! defined( 'LAFKA_PLUGIN_FILE' ) ) {
	define( 'LAFKA_PLUGIN_FILE', dirname( __DIR__ ) . '/lafka-plugin.php' );
}

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

/**
 * Minimal WP function stubs for modules that call add_filter/add_action at
 * file-include time but whose logic is independently testable.
 * These are no-ops — actual filter invocation tests require Brain Monkey.
 */
if ( ! function_exists( 'add_filter' ) ) {
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
	function add_filter( $tag, $callback, $priority = 10, $accepted_args = 1 ): bool { // phpcs:ignore
		return true;
	}
}
if ( ! function_exists( 'add_action' ) ) {
	// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
	function add_action( $tag, $callback, $priority = 10, $accepted_args = 1 ): bool { // phpcs:ignore
		return true;
	}
}

/**
 * Minimal Walker_Nav_Menu stub so walker subclasses can be tested without
 * a full WordPress bootstrap (P6-UX-6 W3-T10).
 * NOTE: Only the class stub lives here. WP function stubs (apply_filters,
 * get_theme_mod, etc.) are NOT defined globally because Brain\Monkey /
 * Patchwork needs to be able to redefine them in other test classes. Those
 * stubs are defined inline in MobileGroupedWalkerTest via a test-local
 * subclass that bypasses the constructor.
 */
if ( ! class_exists( 'Walker_Nav_Menu' ) ) {
	class Walker_Nav_Menu { // phpcs:ignore
		public function start_lvl( &$output, $depth = 0, $args = null ) {}
		public function end_lvl( &$output, $depth = 0, $args = null ) {}
		public function start_el( &$output, $item, $depth = 0, $args = null, $id = 0 ) {}
		public function end_el( &$output, $item, $depth = 0, $args = null ) {}
	}
}
