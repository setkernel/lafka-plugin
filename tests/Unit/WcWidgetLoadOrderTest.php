<?php
declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Regression lock for the WooCommerce-dependent widget load order.
 *
 * Audit 2026-06-27: LafkaProductFilterWidget.php (which declares
 * `class LafkaProductFilterWidget extends WC_Widget`) was require_once'd inside
 * lafka_plugin_after_plugins_loaded() on `plugins_loaded`, gated only by
 * LAFKA_PLUGIN_IS_WOOCOMMERCE ("WooCommerce is active"). But WC_Widget is not
 * guaranteed to be loaded at plugins_loaded (and is absent during CLI
 * `wp plugin activate`), so requiring the file threw:
 *   Uncaught Error: Class "WC_Widget" not found … LafkaProductFilterWidget.php:13
 *
 * The fix loads WC-dependent widgets on `widgets_init` (when WC_Widget exists),
 * guarded by class_exists( 'WC_Widget' ), so the require can never fatal. The
 * widget still self-registers on widgets_init.
 */
final class WcWidgetLoadOrderTest extends TestCase {

	private string $src;

	protected function setUp(): void {
		$this->src = file_get_contents( dirname( __DIR__, 2 ) . '/lafka-plugin.php' );
	}

	public function test_wc_widgets_loaded_on_widgets_init(): void {
		$this->assertMatchesRegularExpression(
			"/add_action\(\s*['\"]widgets_init['\"]\s*,\s*['\"]lafka_load_wc_dependent_widgets['\"]/",
			$this->src,
			'WC-dependent widgets must be loaded via a widgets_init hook, not required directly on plugins_loaded.'
		);
	}

	public function test_loader_function_exists(): void {
		$this->assertStringContainsString(
			'function lafka_load_wc_dependent_widgets',
			$this->src,
			'A dedicated loader for WC-dependent widgets must exist.'
		);
	}

	public function test_wc_widget_require_is_guarded_by_class_exists(): void {
		$body = $this->function_body( 'lafka_load_wc_dependent_widgets' );
		$this->assertNotSame( '', $body, 'lafka_load_wc_dependent_widgets body not found.' );
		$this->assertMatchesRegularExpression(
			"/class_exists\(\s*['\"]WC_Widget['\"]\s*\)/",
			$body,
			'The loader must guard on class_exists( \'WC_Widget\' ) before requiring the widget.'
		);
		$this->assertStringContainsString(
			'widgets/wc_widgets/',
			$body,
			'The loader must be the place that requires the wc_widgets file.'
		);
	}

	public function test_no_unguarded_wc_widget_require_remains(): void {
		// Every occurrence of the wc_widgets require must be preceded (within the
		// same ~240 chars) by a WC_Widget guard — i.e. it only lives in the
		// guarded loader, never in the bare plugins_loaded path.
		$offset = 0;
		$needle = "widgets/wc_widgets/";
		while ( false !== ( $pos = strpos( $this->src, $needle, $offset ) ) ) {
			$window = substr( $this->src, max( 0, $pos - 240 ), 240 );
			$this->assertMatchesRegularExpression(
				"/class_exists\(\s*['\"]WC_Widget['\"]\s*\)/",
				$window,
				"An unguarded require of $needle remains at offset $pos — it can fatal when WC_Widget is not loaded."
			);
			$offset = $pos + strlen( $needle );
		}
	}

	private function function_body( string $name ): string {
		$start = strpos( $this->src, 'function ' . $name );
		if ( false === $start ) {
			return '';
		}
		$rest = substr( $this->src, $start + strlen( 'function ' . $name ) );
		$next = strpos( $rest, "\nfunction " );
		return false === $next ? $rest : substr( $rest, 0, $next );
	}
}
