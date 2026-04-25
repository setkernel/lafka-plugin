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
