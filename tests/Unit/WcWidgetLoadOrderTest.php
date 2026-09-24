<?php
declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Regression lock (audit 2026-06-27): LafkaProductFilterWidget extends
 * WC_Widget, which is not loaded at plugins_loaded (and is absent during CLI
 * `wp plugin activate`). Requiring it there fataled with
 * `Class "WC_Widget" not found`. WC-dependent widgets now load on
 * widgets_init behind a class_exists( 'WC_Widget' ) guard.
 *
 * Source-level guard: the loader lives in lafka-plugin.php, which cannot be
 * loaded in a unit test.
 */
final class WcWidgetLoadOrderTest extends TestCase {

	public function test_wc_widgets_load_on_widgets_init(): void {
		$src = (string) file_get_contents( dirname( __DIR__, 2 ) . '/lafka-plugin.php' );

		$this->assertMatchesRegularExpression(
			"/add_action\(\s*'widgets_init'\s*,\s*'lafka_load_wc_dependent_widgets'/",
			$src,
			'WC-dependent widgets must be loaded from a widgets_init hook, not required on plugins_loaded.'
		);
	}

	public function test_every_wc_widget_require_is_guarded_by_class_exists(): void {
		$src = (string) file_get_contents( dirname( __DIR__, 2 ) . '/lafka-plugin.php' );

		$this->assertGreaterThan( 0, preg_match_all( '#widgets/wc_widgets/#', $src, $m, PREG_OFFSET_CAPTURE ) );
		$unguarded = array();
		foreach ( $m[0] as $hit ) {
			$window = substr( $src, max( 0, $hit[1] - 240 ), 240 );
			if ( ! preg_match( "/class_exists\(\s*'WC_Widget'\s*\)/", $window ) ) {
				$unguarded[] = $hit[1];
			}
		}
		$this->assertSame( array(), $unguarded, 'A wc_widgets require without a class_exists( \'WC_Widget\' ) guard can fatal before WooCommerce loads.' );
	}
}
