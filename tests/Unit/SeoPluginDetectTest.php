<?php
/**
 * lafka_seo_plugin_active() (incl/seo/lafka-seo-plugin-detect.php) — f049:
 * Yoast, Rank Math, SEOPress and AIOSEO are each detected, so the JSON-LD,
 * OpenGraph and meta description emitters all defer to them.
 *
 * Each case runs in its own process: the signals are constants and classes,
 * which can't be undefined, and other suites stub the function itself.
 *
 * @package Lafka\Plugin\Tests\Unit
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

final class SeoPluginDetectTest extends TestCase {

	/** @return array<string, array{0: string}> */
	public static function signals(): array {
		return array(
			'none'      => array( '' ),
			'Yoast'     => array( 'define( "WPSEO_VERSION", "1" );' ),
			'Rank Math' => array( 'class RankMath {}' ),
			'SEOPress'  => array( 'define( "SEOPRESS_VERSION", "1" );' ),
			'AIOSEO'    => array( 'namespace AIOSEO\Plugin; class AIOSEO {}' ),
		);
	}

	#[DataProvider( 'signals' )]
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_detects_each_supported_seo_plugin( string $signal ): void {
		if ( '' !== $signal ) {
			eval( $signal ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- declares a fake plugin's constant/class.
		}
		require dirname( __DIR__, 2 ) . '/incl/seo/lafka-seo-plugin-detect.php';

		$this->assertSame( '' !== $signal, lafka_seo_plugin_active() );
	}
}
