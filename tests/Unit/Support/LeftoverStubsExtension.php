<?php
/**
 * PHPUnit extension: make function_exists() answer the same in every test.
 *
 * Brain Monkey defines a function the first time a test stubs it
 * (`Functions\when( 'wc_get_order' )`) and leaves it defined — but unmocked —
 * for the rest of the process. Plugin code that guards a call with
 * `function_exists()` therefore took a different path depending on whether
 * some earlier test had stubbed that function: a test that relied on the
 * function being absent passed alone and failed after the stubbing test, so
 * the suite's result depended on the order it ran in.
 *
 * Before the first test runs, this extension defines every function any test
 * stubs by name the same way Brain Monkey would leave it: defined, throwing
 * "not defined nor mocked in this test" unless the running test stubs it.
 * Every test now starts from that one state whatever ran before it, so a test
 * that calls such a function without stubbing it fails on every run, in any
 * order, instead of intermittently.
 *
 * Functions that already exist when the suite starts, and functions the
 * plugin itself declares (tests require those files and then stub on top),
 * are left alone.
 *
 * @package Lafka_Plugin
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit\Support;

use Brain\Monkey;
use PHPUnit\Event\TestRunner\ExecutionStarted;
use PHPUnit\Event\TestRunner\ExecutionStartedSubscriber;
use PHPUnit\Runner\Extension\Extension;
use PHPUnit\Runner\Extension\Facade;
use PHPUnit\Runner\Extension\ParameterCollection;
use PHPUnit\TextUI\Configuration\Configuration;

final class LeftoverStubsExtension implements Extension {

	public function bootstrap( Configuration $configuration, Facade $facade, ParameterCollection $parameters ): void {
		$facade->registerSubscriber(
			new class() implements ExecutionStartedSubscriber {
				public function notify( ExecutionStarted $event ): void {
					LeftoverStubsExtension::define_leftover_stubs( dirname( __DIR__ ), dirname( __DIR__, 3 ) );
				}
			}
		);
	}

	/**
	 * Define every stubbed-by-name function as a Brain Monkey leftover.
	 *
	 * @param string $tests_dir  Directory holding the test files.
	 * @param string $plugin_dir Plugin root (its own functions are skipped).
	 * @return string[] The functions defined.
	 */
	public static function define_leftover_stubs( string $tests_dir, string $plugin_dir ): array {
		$names = array_diff( self::stubbed_names( $tests_dir ), self::plugin_functions( $plugin_dir ) );
		$names = array_filter( $names, static fn( string $name ): bool => ! function_exists( $name ) );
		if ( array() === $names ) {
			return array();
		}
		Monkey\setUp();
		foreach ( $names as $name ) {
			Monkey\Functions\when( $name )->justReturn( null );
		}
		Monkey\tearDown();
		return array_values( $names );
	}

	/**
	 * Function names the tests stub: `Functions\when( 'name' )`,
	 * `Functions\expect( 'name' )`, and names looped over as
	 * `foreach ( array( 'a', 'b' ) as $fn ) { Functions\when( $fn ) … }`.
	 *
	 * @param string $dir Tests directory.
	 * @return string[]
	 */
	public static function stubbed_names( string $dir ): array {
		$names = array();
		$files = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ) );
		foreach ( $files as $file ) {
			if ( 'php' !== $file->getExtension() ) {
				continue;
			}
			$tokens = array_values(
				array_filter(
					token_get_all( (string) file_get_contents( $file->getPathname() ) ),
					static fn( $t ): bool => ! is_array( $t ) || ! in_array( $t[0], array( T_WHITESPACE, T_COMMENT, T_DOC_COMMENT ), true )
				)
			);
			$loops = array(); // $var => names from the nearest foreach over a literal array.
			foreach ( $tokens as $i => $token ) {
				if ( is_array( $token ) && T_FOREACH === $token[0] ) {
					self::read_foreach( $tokens, $i, $loops );
					continue;
				}
				if ( ! is_array( $token ) || ! in_array( $token[0], array( T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED ), true ) ) {
					continue;
				}
				if ( ! preg_match( '/(^|\\\\)Functions\\\\(when|expect)$/', $token[1] ) || '(' !== ( $tokens[ $i + 1 ] ?? null ) ) {
					continue;
				}
				$arg = $tokens[ $i + 2 ] ?? null;
				if ( is_array( $arg ) && T_CONSTANT_ENCAPSED_STRING === $arg[0] ) {
					$names[] = substr( $arg[1], 1, -1 );
				} elseif ( is_array( $arg ) && T_VARIABLE === $arg[0] && isset( $loops[ $arg[1] ] ) ) {
					array_push( $names, ...$loops[ $arg[1] ] );
				}
			}
		}
		$names = array_filter( $names, static fn( string $name ): bool => 1 === preg_match( '/^[A-Za-z_][A-Za-z0-9_]*$/', $name ) );
		return array_values( array_unique( $names ) );
	}

	/**
	 * Record `foreach ( array( 'a', … ) as $var )` / `foreach ( [ 'a', … ] as $var )`.
	 *
	 * @param array<int,mixed>                $tokens Tokens without whitespace.
	 * @param int                             $i      Index of the foreach token.
	 * @param array<string,array<int,string>> $loops  Collected loops (by reference).
	 */
	private static function read_foreach( array $tokens, int $i, array &$loops ): void {
		$strings = array();
		for ( $j = $i + 1, $n = count( $tokens ); $j < $n; $j++ ) {
			$t = $tokens[ $j ];
			if ( is_array( $t ) && T_AS === $t[0] ) {
				$var = $tokens[ $j + 1 ] ?? null;
				if ( is_array( $var ) && T_VARIABLE === $var[0] && array() !== $strings ) {
					$loops[ $var[1] ] = $strings;
				}
				return;
			}
			if ( is_array( $t ) && T_CONSTANT_ENCAPSED_STRING === $t[0] ) {
				$strings[] = substr( $t[1], 1, -1 );
				continue;
			}
			if ( ( is_array( $t ) && T_ARRAY === $t[0] ) || in_array( $t, array( '(', ')', '[', ']', ',' ), true ) ) {
				continue;
			}
			return; // Not a plain literal list.
		}
	}

	/**
	 * Global functions the plugin declares (outside vendor/, node_modules/ and tests/).
	 *
	 * @param string $dir Plugin root.
	 * @return string[]
	 */
	public static function plugin_functions( string $dir ): array {
		$names = array();
		$files = new \RecursiveIteratorIterator(
			new \RecursiveCallbackFilterIterator(
				new \RecursiveDirectoryIterator( $dir, \FilesystemIterator::SKIP_DOTS ),
				static fn( \SplFileInfo $f ): bool => ! ( $f->isDir() && in_array( $f->getFilename(), array( 'vendor', 'node_modules', 'tests', '.git' ), true ) )
			)
		);
		foreach ( $files as $file ) {
			if ( 'php' === $file->getExtension() && preg_match_all( '/^[ \t]*function[ \t]+&?[ \t]*([A-Za-z_][A-Za-z0-9_]*)[ \t]*\(/m', (string) file_get_contents( $file->getPathname() ), $m ) ) {
				array_push( $names, ...$m[1] );
			}
		}
		return array_values( array_unique( $names ) );
	}
}
