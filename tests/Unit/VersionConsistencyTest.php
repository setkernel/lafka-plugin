<?php
/**
 * Guards the version single-source-of-truth.
 *
 * package.json is canonical (bump via `npm version`). This locks in that the
 * machine-critical mirrors never drift from it: the plugin file's WordPress
 * header and both version fields in package-lock.json. Comprehensive drift
 * (incl. docs) is checked by `npm run check-version`; this PHP guard runs in
 * `composer test` so it also fires in the pre-push hook, even with no node_modules.
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class VersionConsistencyTest extends TestCase {

	private const ROOT = __DIR__ . '/../..';

	private function canonical_version(): string {
		$pkg = json_decode( (string) file_get_contents( self::ROOT . '/package.json' ), true );
		self::assertIsArray( $pkg, 'package.json unreadable' );
		self::assertArrayHasKey( 'version', $pkg );
		self::assertMatchesRegularExpression( '/^\d+\.\d+\.\d+/', (string) $pkg['version'] );

		return (string) $pkg['version'];
	}

	public function test_plugin_header_matches_package_json(): void {
		$header = substr( (string) file_get_contents( self::ROOT . '/lafka-plugin.php' ), 0, 8192 );
		self::assertSame( 1, preg_match( '/^\s*Version:\s*(\d+\.\d+\.\d+[^\s]*)/m', $header, $m ), 'no Version header in lafka-plugin.php' );
		self::assertSame( $this->canonical_version(), $m[1], 'plugin header Version drifted from package.json — run `npm version`' );
	}

	public function test_readme_stable_tag_matches_package_json(): void {
		// wp.org shows Stable tag as THE installable version. It drifted 65
		// releases (9.35.0 vs 10.0.0) because versionSync never covered it —
		// this guard + the package.json versionSync entry close that hole.
		$readme = (string) file_get_contents( self::ROOT . '/readme.txt' );
		self::assertSame( 1, preg_match( '/^Stable tag:\s*(\d+\.\d+\.\d+[^\s]*)/m', $readme, $m ), 'no Stable tag in readme.txt' );
		self::assertSame( $this->canonical_version(), $m[1], 'readme.txt Stable tag drifted from package.json — run `npm version`' );
	}

	public function test_package_lock_matches_package_json(): void {
		$lock = json_decode( (string) file_get_contents( self::ROOT . '/package-lock.json' ), true );
		self::assertIsArray( $lock, 'package-lock.json unreadable' );

		$version = $this->canonical_version();
		self::assertSame( $version, (string) ( $lock['version'] ?? '' ), 'package-lock root version drifted' );
		self::assertSame( $version, (string) ( $lock['packages']['']['version'] ?? '' ), 'package-lock packages[""] version drifted' );
	}
}
