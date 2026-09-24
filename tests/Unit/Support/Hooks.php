<?php
/**
 * Read the hook registrations tests/bootstrap.php records.
 *
 * @package Lafka_Plugin
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit\Support;

final class Hooks {

	/** Forget every registration recorded so far. */
	public static function reset(): void {
		$GLOBALS['lafka_test_hooks'] = array();
	}

	/**
	 * "hook -> method" for every registration whose callback is a method
	 * (array callable) or a named function, in registration order.
	 *
	 * @return string[]
	 */
	public static function registered(): array {
		$out = array();
		foreach ( $GLOBALS['lafka_test_hooks'] ?? array() as $registration ) {
			list( $tag, $callback ) = $registration;
			if ( is_array( $callback ) && isset( $callback[1] ) ) {
				$out[] = $tag . ' -> ' . $callback[1];
			} elseif ( is_string( $callback ) ) {
				$out[] = $tag . ' -> ' . $callback;
			}
		}
		return $out;
	}
}
