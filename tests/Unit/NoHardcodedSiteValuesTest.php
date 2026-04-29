<?php
/**
 * W2-T1 OSS-safety regression lock.
 *
 * The lafka-plugin repository ships publicly at github.com/setkernel/lafka-plugin.
 * It MUST NOT contain any restaurant-specific literals — operator content
 * (NAP, geo, hours, citation URLs, hero image URLs) flows through the
 * Customizer panel "Lafka — Restaurant Information" and is read via
 * `lafka_get_restaurant_info()` (lafka-schema-helpers.php).
 *
 * This test scans every PHP file under incl/ and the main plugin file for
 * forbidden literals. Test fixtures (under tests/) and Markdown docs (*.md)
 * are exempt because they describe the historical / canonical Peppery values
 * for migration purposes.
 *
 * @package Lafka\Plugin\Tests\Unit
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class NoHardcodedSiteValuesTest extends TestCase {

	/**
	 * Forbidden literals — restaurant-specific values that must come from
	 * Customizer/options/filters, never from source code.
	 */
	private const FORBIDDEN = array(
		'Peppery',
		'Sackville Drive',
		'B4C 2R8',
		'19022525353',
		'902-252-5353',
		'44.7720',
		'-63.6789',
		'three.ppps',
	);

	public function test_no_hardcoded_site_values_in_plugin_source(): void {
		$root  = dirname( __DIR__, 2 );
		$files = $this->collect_php_files( $root );

		$violations = array();
		foreach ( $files as $file ) {
			$contents = file_get_contents( $file );
			foreach ( self::FORBIDDEN as $needle ) {
				if ( str_contains( $contents, $needle ) ) {
					$violations[] = sprintf( '%s -> "%s"', substr( $file, strlen( $root ) + 1 ), $needle );
				}
			}
		}

		$this->assertSame(
			array(),
			$violations,
			"OSS-safety violation: hardcoded restaurant-specific literal(s) found:\n  " . implode( "\n  ", $violations ) .
				"\nThese values must come from Customizer settings (lafka_business_*), not from source code."
		);
	}

	/**
	 * Collect PHP files under lafka-plugin/incl/ and the main plugin file.
	 * Excludes tests/ (fixtures), vendor/ (deps), and *.md files (docs).
	 *
	 * @return list<string>
	 */
	private function collect_php_files( string $root ): array {
		$out   = array();
		$incl  = $root . '/incl';
		$main  = $root . '/lafka-plugin.php';

		if ( file_exists( $main ) ) {
			$out[] = $main;
		}

		if ( is_dir( $incl ) ) {
			$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $incl, RecursiveDirectoryIterator::SKIP_DOTS ) );
			foreach ( $it as $f ) {
				if ( ! $f->isFile() ) {
					continue;
				}
				$path = $f->getPathname();
				if ( '.php' !== substr( $path, -4 ) ) {
					continue;
				}
				$out[] = $path;
			}
		}
		return $out;
	}
}
