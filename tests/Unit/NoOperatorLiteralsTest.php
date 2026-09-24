<?php
/**
 * This repository is public and the plugin is sold to any restaurant: no file
 * may carry the launch operator's identifying details (brand, domain,
 * address, phone, coordinates). Operator content comes from the Customizer /
 * options at runtime. Shipped docs and the demo-store fixture must also not
 * read as that operator's menu.
 *
 * The deny-list lives, hashed, in Support\OperatorLiterals.
 *
 * @package Lafka_Plugin
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use LafkaPlugin\Tests\Unit\Support\OperatorLiterals;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

require_once __DIR__ . '/Support/OperatorLiterals.php';

final class NoOperatorLiteralsTest extends TestCase {

	private const ROOT = __DIR__ . '/../..';

	/**
	 * Every text file under the given paths (relative to the repo root).
	 *
	 * @param string[] $paths Files or directories.
	 * @return string[]
	 */
	private static function text_files( array $paths ): array {
		$root  = realpath( self::ROOT );
		$files = array();
		foreach ( $paths as $path ) {
			$full = $root . '/' . $path;
			if ( is_file( $full ) ) {
				$files[] = $full;
				continue;
			}
			if ( ! is_dir( $full ) ) {
				continue;
			}
			$it = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $full, RecursiveDirectoryIterator::SKIP_DOTS ) );
			foreach ( $it as $file ) {
				if ( preg_match( '/\.(php|js|mjs|css|md|txt|json|xml|yml)$/', $file->getFilename() ) && ! str_ends_with( $file->getFilename(), '.min.js' ) ) {
					$files[] = $file->getPathname();
				}
			}
		}
		return $files;
	}

	/**
	 * @param string[] $files       Absolute paths.
	 * @param bool     $include_menu Also deny signature menu items.
	 * @return string[] "path: literal" violations.
	 */
	private static function violations( array $files, bool $include_menu ): array {
		$root = realpath( self::ROOT );
		$out  = array();
		foreach ( $files as $file ) {
			foreach ( OperatorLiterals::find( (string) file_get_contents( $file ), $include_menu ) as $hit ) {
				$out[] = substr( $file, strlen( $root ) + 1 ) . ': ' . $hit;
			}
		}
		return $out;
	}

	public function test_no_file_carries_the_operators_identifying_details(): void {
		$files = self::text_files(
			array( 'lafka-plugin.php', 'uninstall.php', 'incl', 'shortcodes', 'widgets', 'assets/js', 'assets/css', 'docs', 'tests', 'scripts', '.github', 'readme.txt', 'README.md', 'CHANGELOG.md', 'COMPATIBILITY.md', 'CONTRIBUTING.md', 'CREDITS.md', 'wpml-config.xml', 'package.json', 'composer.json' )
		);

		$this->assertSame( array(), self::violations( $files, false ) );
	}

	public function test_docs_and_demo_content_do_not_read_as_the_operators_menu(): void {
		$files = self::text_files( array( 'docs', 'readme.txt', 'README.md', 'incl/cli/data' ) );

		$this->assertSame( array(), self::violations( $files, true ) );
	}

	public function test_the_matcher_normalises_names_phones_and_coordinates(): void {
		// Synthetic stand-ins exercise the normalisation without real literals.
		$hashes = array( sha1( 'example cafe' ), sha1( '5550100199' ), sha1( '12.3450' ), sha1( 'examplecafe' ) );
		$text   = "Example  Cafe — call +1 (555) 010-0199, find us at 12.345, -45.6 or www.ExampleCafe.test";

		$this->assertEqualsCanonicalizing(
			array( 'example cafe', '5550100199', '12.3450', 'examplecafe' ),
			OperatorLiterals::find_hashed( $text, $hashes )
		);
		$this->assertSame( array(), OperatorLiterals::find_hashed( 'Example of a cafe', $hashes ) );
	}
}
