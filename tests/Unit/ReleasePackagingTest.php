<?php
/**
 * The release zip ships what an installed plugin needs and nothing dev-only.
 *
 * Runs release.yml's own rsync command (its --exclude list, parsed from the
 * workflow) as a dry run over this checkout and inspects the resulting file
 * list — so it tests what the release step would actually copy.
 *
 * @package Lafka_Plugin
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ReleasePackagingTest extends TestCase {

	private const ROOT = __DIR__ . '/../..';

	/** @var list<string>|null */
	private static ?array $shipped = null;

	/**
	 * Relative paths release.yml's rsync would copy (directories end in "/").
	 *
	 * @return list<string>
	 */
	private static function shipped_paths(): array {
		if ( null !== self::$shipped ) {
			return self::$shipped;
		}
		$yml = (string) file_get_contents( self::ROOT . '/.github/workflows/release.yml' );
		self::assertStringContainsString( 'zip -r lafka-plugin.zip lafka-plugin/', $yml, 'release.yml no longer zips the rsync destination' );
		self::assertGreaterThan( 0, preg_match_all( "/--exclude='([^']*)'/", $yml, $m ), 'no rsync --exclude entries found in release.yml' );

		$cmd = 'rsync -a --dry-run --out-format=%n';
		foreach ( $m[1] as $pattern ) {
			$cmd .= ' ' . escapeshellarg( '--exclude=' . $pattern );
		}
		$cmd .= ' ' . escapeshellarg( realpath( self::ROOT ) . '/' ) . ' ' . escapeshellarg( sys_get_temp_dir() . '/lafka-release-dry-run/' ) . ' 2>/dev/null';

		exec( $cmd, $lines, $status ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec -- test harness.
		if ( 0 !== $status ) {
			self::markTestSkipped( 'rsync is not available.' );
		}
		self::$shipped = array_values( array_filter( $lines, static fn( $l ) => '' !== $l && './' !== $l ) );
		return self::$shipped;
	}

	public function test_dev_only_files_stay_out_of_the_zip(): void {
		$dev_prefixes = array(
			'.git/',
			'.github/',
			'.githooks/',
			'.gitignore',
			'.npmrc',
			'.wp-env',
			'.phpcs',
			'.phpunit',
			'.stylelintrc.json',
			'.eslintcache',
			'.stylelintcache',
			'node_modules/',
			'vendor/',
			'tests/',
			'scripts/',
			'package.json',
			'package-lock.json',
			'composer.json',
			'composer.lock',
			'eslint.config.mjs',
			'phpunit.xml.dist',
			'CONTRIBUTING.md',
			'README.md',
		);

		$leaked = array();
		foreach ( self::shipped_paths() as $path ) {
			foreach ( $dev_prefixes as $prefix ) {
				if ( str_starts_with( $path, $prefix ) ) {
					$leaked[] = $path;
				}
			}
		}
		$this->assertSame( array(), $leaked, 'Dev-only files would ship in the release zip.' );
	}

	public function test_runtime_files_ship(): void {
		$shipped = self::shipped_paths();

		$missing = array();
		foreach ( array(
			'lafka-plugin.php',
			'uninstall.php',
			'readme.txt',
			'LICENSE', // GPLv2 §1: recipients get a copy of the licence.
			'CREDITS.md',
			'COMPATIBILITY.md',
			'wpml-config.xml',
			'languages/lafka-plugin.pot',
			'docs/TRACKING.md',
			'incl/lafka-asset-registration.php',
			'assets/vendor/font-awesome/css/all.min.css',
			'incl/shipping-areas/assets/js/frontend/lafka-shipping-areas-handle-shipping.js',
			'incl/shipping-areas/assets/js/frontend/lafka-shipping-areas-handle-shipping.min.js',
			'shortcodes/shortcodes.php',
			'widgets/LafkaAboutWidget.php',
		) as $file ) {
			if ( ! in_array( $file, $shipped, true ) ) {
				$missing[] = $file;
			}
		}
		$this->assertSame( array(), $missing, 'Runtime files are missing from the release zip.' );
	}
}
