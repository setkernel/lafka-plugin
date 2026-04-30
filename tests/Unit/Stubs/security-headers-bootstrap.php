<?php
/**
 * Loader for the Lafka_Security_Headers source file in unit tests.
 *
 * The source file gates its auto-instantiation on `function_exists( 'wp_safe_redirect' )`
 * (a WP-runtime function the test bootstrap deliberately doesn't stub), so
 * loading the file here doesn't fire the constructor — tests that need an
 * instance call ::instance() explicitly under Brain Monkey-stubbed get_option.
 *
 * Lafka_Options is the legacy theme-options accessor; we provide a tiny
 * stub so the file loads cleanly without booting the theme. Brain Monkey
 * overrides this on a per-test basis via reflection — but most tests don't
 * need to.
 *
 * @package Lafka\Plugin\Tests
 */

declare(strict_types=1);

if ( ! class_exists( 'Lafka_Options' ) ) {
	class Lafka_Options { // phpcs:ignore Generic.Files.OneObjectStructurePerFile.MultipleFound
		public static function get( $key, $default = '' ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
			return $default;
		}
	}
}

require_once dirname( __DIR__, 3 ) . '/incl/security/class-lafka-security-headers.php';
