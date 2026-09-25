<?php
/**
 * Pin a wall-clock-derived expectation to the same instant the code under
 * test read the clock.
 *
 * Some production code reads the real clock (`time()`, `new DateTime( 'now' )`)
 * with no injectable "now". A test that derives its expectation from the clock
 * separately races it: when a day (or second) boundary passes between the two
 * reads, the expectation and the result disagree and the test flakes at, say,
 * 23:59:59.999. StableClock::run() re-runs the act until the clock key it
 * formats is the same before and after the call, so the expectation always
 * describes the instant the code actually saw.
 *
 * @package Lafka_Plugin
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit\Support;

final class StableClock {

	/**
	 * Run $act while $key() (e.g. today's date) stays the same.
	 *
	 * $act must be repeatable: reset any state it records at its start.
	 *
	 * @param callable():string $key Formats the clock at the granularity the assertion depends on.
	 * @param callable():mixed  $act Calls the code under test.
	 * @return array{0: mixed, 1: string} The act's result and the key it ran under.
	 */
	public static function run( callable $key, callable $act ): array {
		for ( $attempt = 0; $attempt < 5; $attempt++ ) {
			$before = $key();
			$result = $act();
			if ( $key() === $before ) {
				return array( $result, $before );
			}
		}
		throw new \RuntimeException( 'The clock kept crossing the boundary this test depends on.' );
	}
}
