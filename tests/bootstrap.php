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
 * add_filter()/add_action() for modules that register hooks at include time.
 *
 * They must exist before any module is required, so they are defined here —
 * before Brain Monkey could — and Brain Monkey then leaves them alone. Every
 * registration is recorded in $GLOBALS['lafka_test_hooks'] so tests can
 * assert on what a module hooked (see tests/Unit/Support/Hooks.php).
 */
$GLOBALS['lafka_test_hooks'] = array();
if ( ! function_exists( 'add_filter' ) ) {
	function add_filter( $tag, $callback, $priority = 10, $accepted_args = 1 ): bool { // phpcs:ignore
		$GLOBALS['lafka_test_hooks'][] = array( $tag, $callback, $priority, $accepted_args );
		return true;
	}
}
if ( ! function_exists( 'add_action' ) ) {
	function add_action( $tag, $callback, $priority = 10, $accepted_args = 1 ): bool { // phpcs:ignore
		return add_filter( $tag, $callback, $priority, $accepted_args );
	}
}
