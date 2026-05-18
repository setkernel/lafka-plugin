<?php
/**
 * AnalyticsCustomEventsTest — locks down the Phase 1C (v9.25.0) custom event
 * taxonomy layer:
 *
 *   - PHP enqueue function exists, is hooked on wp_enqueue_scripts
 *   - Enqueue is conditional on at least one analytics ID being set
 *   - JS bundle file exists at the expected path
 *   - JS source contains every event name in the spec table
 *   - JS uses requestAnimationFrame for scroll throttling (not raw scroll)
 *   - JS uses document-level delegation (one click listener, not per-element)
 *   - JS source-determination switch handles all seven sections
 *   - JS dataLayer.push is wrapped in a defensive `window.dataLayer` guard
 *   - JS outbound-detection covers both absolute and relative hrefs
 *   - Plugin wires the new module + bumps version to 9.25.0
 *
 * @package Lafka\Plugin\Tests\Unit
 * @since   9.25.0
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

// The custom-events PHP module reads the analytics helpers (lafka_analytics_*).
// Require the emitter (and its dependency) first so those functions exist.
require_once dirname( __DIR__, 2 ) . '/incl/customizer/class-lafka-customizer-analytics.php';
require_once dirname( __DIR__, 2 ) . '/incl/analytics/lafka-analytics-emitter.php';
require_once dirname( __DIR__, 2 ) . '/incl/analytics/lafka-custom-events.php';

final class AnalyticsCustomEventsTest extends TestCase {

	private const JS_PATH  = '/assets/js/lafka-custom-events.js';
	private const PHP_PATH = '/incl/analytics/lafka-custom-events.php';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// Default: no theme_mods set unless a test overrides.
		Functions\when( 'get_theme_mod' )->returnArg( 2 );
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_js' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function plugin_root(): string {
		return dirname( __DIR__, 2 );
	}

	private function js_src(): string {
		return (string) file_get_contents( $this->plugin_root() . self::JS_PATH );
	}

	private function php_src(): string {
		return (string) file_get_contents( $this->plugin_root() . self::PHP_PATH );
	}

	// ────────────────────────────────────────────────────────────────────
	// 1. Files exist + plugin wires them
	// ────────────────────────────────────────────────────────────────────

	public function test_js_bundle_exists_at_expected_path(): void {
		$this->assertFileExists( $this->plugin_root() . self::JS_PATH );
	}

	public function test_php_module_exists_at_expected_path(): void {
		$this->assertFileExists( $this->plugin_root() . self::PHP_PATH );
	}

	public function test_plugin_requires_custom_events_module(): void {
		$src = (string) file_get_contents( $this->plugin_root() . '/lafka-plugin.php' );
		$this->assertStringContainsString( 'incl/analytics/lafka-custom-events.php', $src );
	}

	public function test_plugin_version_at_least_9_25_0(): void {
		$src = (string) file_get_contents( $this->plugin_root() . '/lafka-plugin.php' );
		if ( ! preg_match( '/Version:\s*(\d+)\.(\d+)\.(\d+)/', $src, $m ) ) {
			$this->fail( 'Plugin header must declare a SemVer version.' );
		}
		$major = (int) $m[1];
		$minor = (int) $m[2];
		$patch = (int) $m[3];
		// Phase 1C added the custom-events module — every subsequent release
		// (Phase 2 v9.26.0+, Phase 3 v9.27.0+, ...) must keep the version
		// monotonically increasing. We assert ≥ 9.25.0, not exact equality,
		// so each new phase's own version-bump test owns its exact lock.
		$at_least = ( $major > 9 )
			|| ( 9 === $major && $minor > 25 )
			|| ( 9 === $major && 25 === $minor && $patch >= 0 );
		$this->assertTrue( $at_least, "Plugin version {$m[0]} must be ≥ 9.25.0 (Phase 1C floor)." );
	}

	// ────────────────────────────────────────────────────────────────────
	// 2. PHP module: enqueue function + hook registration
	// ────────────────────────────────────────────────────────────────────

	public function test_php_module_defines_register_function(): void {
		$src = $this->php_src();
		$this->assertStringContainsString(
			'function lafka_register_custom_events_script',
			$src,
			'PHP module must expose lafka_register_custom_events_script().'
		);
	}

	public function test_php_module_hooks_wp_enqueue_scripts(): void {
		$src = $this->php_src();
		$this->assertMatchesRegularExpression(
			"/add_action\(\s*'wp_enqueue_scripts',\s*'lafka_register_custom_events_script'\s*,\s*20/",
			$src,
			'Enqueue must be hooked on wp_enqueue_scripts priority 20.'
		);
	}

	// ────────────────────────────────────────────────────────────────────
	// 3. Enqueue gates on analytics ID
	// ────────────────────────────────────────────────────────────────────

	public function test_enqueue_skipped_when_no_analytics_id(): void {
		$called = false;
		Functions\when( 'wp_enqueue_script' )->alias( static function () use ( &$called ) {
			$called = true;
		} );
		Functions\when( 'plugins_url' )->returnArg();
		Functions\when( 'lafka_plugin_asset_version' )->justReturn( '1' );
		\lafka_register_custom_events_script();
		$this->assertFalse(
			$called,
			'wp_enqueue_script must NOT fire when no analytics ID is configured.'
		);
	}

	public function test_enqueue_fires_with_gtm_id_set(): void {
		Functions\when( 'get_theme_mod' )->alias( static function ( $key, $default = null ) {
			if ( 'lafka_gtm_container_id' === $key ) {
				return 'GTM-XYZ987';
			}
			return null === $default ? '' : $default;
		} );
		$handle = null;
		Functions\when( 'wp_enqueue_script' )->alias( static function ( $h ) use ( &$handle ) {
			$handle = $h;
		} );
		Functions\when( 'plugins_url' )->returnArg();
		Functions\when( 'lafka_plugin_asset_version' )->justReturn( '1' );
		\lafka_register_custom_events_script();
		$this->assertSame( 'lafka-custom-events', $handle );
	}

	public function test_enqueue_fires_with_ga4_id_set(): void {
		Functions\when( 'get_theme_mod' )->alias( static function ( $key, $default = null ) {
			if ( 'lafka_ga4_measurement_id' === $key ) {
				return 'G-ABCDE12345';
			}
			return null === $default ? '' : $default;
		} );
		$handle = null;
		Functions\when( 'wp_enqueue_script' )->alias( static function ( $h ) use ( &$handle ) {
			$handle = $h;
		} );
		Functions\when( 'plugins_url' )->returnArg();
		Functions\when( 'lafka_plugin_asset_version' )->justReturn( '1' );
		\lafka_register_custom_events_script();
		$this->assertSame( 'lafka-custom-events', $handle );
	}

	public function test_enqueue_fires_with_pixel_id_set(): void {
		Functions\when( 'get_theme_mod' )->alias( static function ( $key, $default = null ) {
			if ( 'lafka_meta_pixel_id' === $key ) {
				return '1234567890123456';
			}
			return null === $default ? '' : $default;
		} );
		$handle = null;
		Functions\when( 'wp_enqueue_script' )->alias( static function ( $h ) use ( &$handle ) {
			$handle = $h;
		} );
		Functions\when( 'plugins_url' )->returnArg();
		Functions\when( 'lafka_plugin_asset_version' )->justReturn( '1' );
		\lafka_register_custom_events_script();
		$this->assertSame( 'lafka-custom-events', $handle );
	}

	public function test_enqueue_fires_with_clarity_id_set(): void {
		Functions\when( 'get_theme_mod' )->alias( static function ( $key, $default = null ) {
			if ( 'lafka_clarity_project_id' === $key ) {
				return 'abcdef12345';
			}
			return null === $default ? '' : $default;
		} );
		$handle = null;
		Functions\when( 'wp_enqueue_script' )->alias( static function ( $h ) use ( &$handle ) {
			$handle = $h;
		} );
		Functions\when( 'plugins_url' )->returnArg();
		Functions\when( 'lafka_plugin_asset_version' )->justReturn( '1' );
		\lafka_register_custom_events_script();
		$this->assertSame( 'lafka-custom-events', $handle );
	}

	// ────────────────────────────────────────────────────────────────────
	// 4. JS bundle source-greps: every event name from the spec is present
	// ────────────────────────────────────────────────────────────────────

	public function test_js_bundle_contains_all_eight_event_names(): void {
		$src    = $this->js_src();
		$events = array(
			'phone_click',
			'email_click',
			'get_directions_click',
			'faq_open',
			'filter_apply',
			'scroll_milestone',
			'outbound_link',
			'sticky_cart_open',
		);
		foreach ( $events as $event ) {
			$this->assertStringContainsString(
				"'" . $event . "'",
				$src,
				"JS bundle must reference event {$event}."
			);
		}
	}

	public function test_js_event_names_are_ga4_compliant_snake_case_under_40_chars(): void {
		$events = array(
			'phone_click',
			'email_click',
			'get_directions_click',
			'faq_open',
			'filter_apply',
			'scroll_milestone',
			'outbound_link',
			'sticky_cart_open',
		);
		foreach ( $events as $event ) {
			$this->assertMatchesRegularExpression(
				'/^[a-z][a-z0-9_]*$/',
				$event,
				"{$event} must be snake_case (GA4 spec)."
			);
			$this->assertLessThanOrEqual(
				40,
				strlen( $event ),
				"{$event} must be ≤40 chars (GA4 spec)."
			);
		}
	}

	// ────────────────────────────────────────────────────────────────────
	// 5. JS architecture: delegation + RAF + defensive guard
	// ────────────────────────────────────────────────────────────────────

	public function test_js_uses_document_level_click_delegation(): void {
		$src = $this->js_src();
		// Exactly one document-level click listener — not per-element.
		$this->assertMatchesRegularExpression(
			'/document\.addEventListener\(\s*[\'"]click[\'"]/',
			$src,
			'JS bundle must use document-level click delegation.'
		);
		$count = preg_match_all( '/document\.addEventListener\(\s*[\'"]click[\'"]/', $src );
		$this->assertSame(
			1,
			$count,
			'Exactly one document-level click listener — additional listeners suggest per-element binding.'
		);
	}

	public function test_js_uses_request_animation_frame_for_scroll_throttle(): void {
		$src = $this->js_src();
		$this->assertStringContainsString(
			'requestAnimationFrame',
			$src,
			'Scroll-milestone handler must throttle via requestAnimationFrame (not raw scroll spam).'
		);
	}

	public function test_js_pushes_are_guarded_by_window_datalayer_check(): void {
		$src = $this->js_src();
		// The defensive guard around dataLayer.push.
		$this->assertMatchesRegularExpression(
			'/if\s*\(\s*window\.dataLayer\s*\)/',
			$src,
			'dataLayer.push() calls must be wrapped in a `if (window.dataLayer)` guard.'
		);
	}

	// ────────────────────────────────────────────────────────────────────
	// 6. JS source-determination handles all seven sections
	// ────────────────────────────────────────────────────────────────────

	public function test_js_source_determination_handles_seven_sections(): void {
		$src      = $this->js_src();
		$sections = array(
			'announce_bar',
			'header',
			'footer',
			'contact',
			'cart',
			'pdp',
			'menu',
		);
		foreach ( $sections as $section ) {
			$this->assertMatchesRegularExpression(
				"/return\s+['\"]" . preg_quote( $section, '/' ) . "['\"]/",
				$src,
				"resolveSource() must be able to return '{$section}'."
			);
		}
	}

	// ────────────────────────────────────────────────────────────────────
	// 7. JS outbound handles both absolute + relative hrefs
	// ────────────────────────────────────────────────────────────────────

	public function test_js_outbound_detection_handles_relative_and_absolute_urls(): void {
		$src = $this->js_src();
		// `new URL(href, window.location.href)` is the canonical relative-
		// resolution pattern; the explicit current-host comparison handles
		// the absolute case.
		$this->assertMatchesRegularExpression(
			'/new\s+URL\(\s*\w+\s*,\s*window\.location\.href\s*\)/',
			$src,
			'Outbound detector must resolve relative URLs via the URL() constructor with location as base.'
		);
		$this->assertStringContainsString(
			'window.location.host',
			$src,
			'Outbound detector must compare against window.location.host.'
		);
	}

	// ────────────────────────────────────────────────────────────────────
	// 8. JS faq_open uses the toggle event (not click)
	// ────────────────────────────────────────────────────────────────────

	public function test_js_faq_open_listens_on_toggle_event(): void {
		$src = $this->js_src();
		$this->assertMatchesRegularExpression(
			'/document\.addEventListener\(\s*[\'"]toggle[\'"]/',
			$src,
			'faq_open must be wired on the toggle event (delegated, capture phase).'
		);
		$this->assertStringContainsString(
			'lafka-contact__faq-item',
			$src,
			'faq_open handler must target the lafka-contact__faq-item class.'
		);
	}

	// ────────────────────────────────────────────────────────────────────
	// 9. JS sticky_cart_open uses IntersectionObserver
	// ────────────────────────────────────────────────────────────────────

	public function test_js_sticky_cart_uses_intersection_observer(): void {
		$src = $this->js_src();
		$this->assertStringContainsString(
			'IntersectionObserver',
			$src,
			'sticky_cart_open must use IntersectionObserver for the viewport trigger.'
		);
		$this->assertStringContainsString(
			'lafka-sticky-cart',
			$src,
			'sticky_cart_open must target the lafka-sticky-cart class.'
		);
	}

	// ────────────────────────────────────────────────────────────────────
	// 10. JS filter_apply binds to menu chip classes
	// ────────────────────────────────────────────────────────────────────

	public function test_js_filter_apply_binds_to_menu_chip_classes(): void {
		$src = $this->js_src();
		$this->assertStringContainsString(
			'lafka-menu__chip',
			$src,
			'filter_apply must bind to .lafka-menu__chip.'
		);
		$this->assertStringContainsString(
			'lafka-menu__category-chip',
			$src,
			'filter_apply must bind to .lafka-menu__category-chip.'
		);
	}

	// ────────────────────────────────────────────────────────────────────
	// 11. JS get_directions matches the four URL patterns + text
	// ────────────────────────────────────────────────────────────────────

	public function test_js_get_directions_handles_all_four_url_patterns(): void {
		$src = $this->js_src();
		$this->assertStringContainsString( 'maps.google.', $src );
		$this->assertStringContainsString( 'maps.apple.', $src );
		$this->assertStringContainsString( 'goo.gl/maps', $src );
		$this->assertStringContainsString( '/maps/dir/', $src );
		// Also accepts a link whose visible text contains "directions".
		$this->assertMatchesRegularExpression(
			'/directions/',
			$src,
			'isDirectionsLink() must also match links whose text contains "directions".'
		);
	}

	// ────────────────────────────────────────────────────────────────────
	// 12. JS never calls gtag() — GTM owns routing
	// ────────────────────────────────────────────────────────────────────

	public function test_js_never_calls_gtag_directly(): void {
		$src  = $this->js_src();
		// Strip /* */ and // comments so the doc-comment "never gtag()" isn't a false positive.
		$code = (string) preg_replace( '#/\*.*?\*/#s', '', $src );
		$code = (string) preg_replace( '#//.*#', '', $code );
		$this->assertStringNotContainsString(
			'gtag(',
			$code,
			'Custom-events JS must push only to dataLayer — GTM owns platform routing.'
		);
	}
}
