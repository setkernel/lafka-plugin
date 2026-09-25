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

	/**
	 * Run a filter through the callbacks recorded for it, in priority order
	 * (registration order within a priority) — what apply_filters() would do
	 * with only those registrations. Alias apply_filters to this to exercise a
	 * module's real wiring.
	 *
	 * @param string $tag   Hook name.
	 * @param mixed  $value Value to filter.
	 * @param mixed  ...$args Extra arguments.
	 * @return mixed
	 */
	public static function apply( string $tag, $value, ...$args ) {
		$matching = array();
		foreach ( $GLOBALS['lafka_test_hooks'] ?? array() as $index => $registration ) {
			if ( $registration[0] === $tag ) {
				$matching[] = array( (int) $registration[2], $index, $registration[1], (int) $registration[3] );
			}
		}
		usort( $matching, static fn( $a, $b ) => array( $a[0], $a[1] ) <=> array( $b[0], $b[1] ) );
		foreach ( $matching as $entry ) {
			$value = call_user_func_array( $entry[2], array_slice( array_merge( array( $value ), $args ), 0, max( 1, $entry[3] ) ) );
		}
		return $value;
	}
}
