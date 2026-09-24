<?php
/**
 * Every literal path handed to lafka_plugin_asset_version() names a file
 * that exists — otherwise the helper falls back to a constant version and
 * cache-busting silently stops for that asset.
 *
 * @package Lafka_Plugin
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class AssetVersionPathsTest extends TestCase {

	public function test_versioned_asset_paths_exist(): void {
		$root  = dirname( __DIR__, 2 );
		$paths = array();
		$files = array( $root . '/lafka-plugin.php' );
		foreach ( array( 'incl', 'shortcodes', 'widgets' ) as $dir ) {
			$it = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $root . '/' . $dir, \FilesystemIterator::SKIP_DOTS ) );
			foreach ( $it as $file ) {
				if ( 'php' === $file->getExtension() ) {
					$files[] = $file->getPathname();
				}
			}
		}
		foreach ( $files as $file ) {
			if ( preg_match_all( "/lafka_plugin_asset_version\\(\\s*'([^'\\$]+)'\\s*\\)/", (string) file_get_contents( $file ), $m ) ) {
				foreach ( $m[1] as $path ) {
					$paths[ $path ] = substr( $file, strlen( $root ) + 1 );
				}
			}
		}

		$this->assertNotEmpty( $paths );
		foreach ( $paths as $path => $source ) {
			$this->assertFileExists( $root . '/' . $path, "{$source} versions '{$path}', which does not exist." );
		}
	}
}
