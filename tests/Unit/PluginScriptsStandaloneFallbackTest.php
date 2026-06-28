<?php
declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Regression lock (f093): lafka_register_plugin_scripts() must register the
 * plugin-OWNED frontend assets (flatpickr, flatpickr-local, flatpickr style,
 * lafka-google-maps — all plugins_url() / maps.googleapis URLs) ABOVE the
 * `if ( ! $lafka_theme_active ) return;` early-return guard so they remain
 * available when a non-Lafka theme is active. Only the theme-directory assets
 * (magnific, typed, nice-select, lafka-dialog, isotope, etc. — all
 * get_template_directory_uri() URLs) — which would 404 without the Lafka theme —
 * may stay below the guard.
 *
 * Bug: the v9.12.0 guard returned BEFORE the plugin-owned registrations, so
 * with any non-Lafka active theme the 'flatpickr'/'flatpickr-local' handles
 * were never registered, the checkout delivery date/time picker (a submit-path
 * control, dependency 'flatpickr') silently failed, and the [lafka_map]
 * shortcode lost its maps script.
 */
final class PluginScriptsStandaloneFallbackTest extends TestCase {

	/**
	 * Returns just the body of lafka_register_plugin_scripts() (the frontend
	 * wp_enqueue_scripts callback), excluding the admin callback that follows,
	 * so substring offsets are unambiguous.
	 */
	private static function frontend_callback_body(): string {
		$source = file_get_contents( dirname( __DIR__, 2 ) . '/lafka-plugin.php' );
		self::assertNotFalse( $source, 'Could not read lafka-plugin.php' );

		$start = strpos( $source, 'function lafka_register_plugin_scripts' );
		self::assertNotFalse( $start, 'lafka_register_plugin_scripts() not found' );

		// The admin registration callback is the reliable end boundary.
		$end = strpos( $source, "add_action( 'admin_enqueue_scripts', 'lafka_register_admin_plugin_scripts' )", $start );
		self::assertNotFalse( $end, 'admin_enqueue_scripts boundary not found' );

		return substr( $source, $start, $end - $start );
	}

	private static function guard_offset( string $body ): int {
		$pos = strpos( $body, 'if ( ! $lafka_theme_active )' );
		self::assertNotFalse( $pos, 'Theme-active early-return guard not found' );

		return $pos;
	}

	/**
	 * Plugin-owned assets that MUST be registered before the theme guard.
	 *
	 * @return array<string, array{0:string}>
	 */
	public static function plugin_owned_registrations(): array {
		return array(
			'flatpickr script'       => array( "wp_register_script( 'flatpickr', plugins_url" ),
			'flatpickr style'        => array( "wp_register_style( 'flatpickr', plugins_url" ),
			'flatpickr-local alias'  => array( "wp_register_script( 'flatpickr-local'" ),
			'google-maps handle'     => array( "'lafka-google-maps'" ),
			'google-maps remote url' => array( 'maps.googleapis.com' ),
		);
	}

	/**
	 * Theme-directory assets that MUST stay below the guard (they 404 without
	 * the Lafka theme present to serve them).
	 *
	 * @return array<string, array{0:string}>
	 */
	public static function theme_dir_registrations(): array {
		return array(
			'magnific'     => array( "wp_register_script( 'magnific'" ),
			'typed'        => array( "wp_register_script( 'typed'" ),
			'nice-select'  => array( "wp_register_script( 'nice-select'" ),
			'lafka-dialog' => array( "wp_register_script( 'lafka-dialog'" ),
			'isotope'      => array( "wp_register_script( 'isotope'" ),
		);
	}

	#[DataProvider('plugin_owned_registrations')]
	public function test_plugin_owned_assets_registered_before_theme_guard( string $needle ): void {
		$body  = self::frontend_callback_body();
		$guard = self::guard_offset( $body );

		$pos = strpos( $body, $needle );
		$this->assertNotFalse( $pos, "Registration not found in frontend callback: $needle" );
		$this->assertLessThan(
			$guard,
			$pos,
			"Plugin-owned registration must precede the `if ( ! \$lafka_theme_active ) return;` guard so it survives a non-Lafka active theme: $needle"
		);
	}

	#[DataProvider('theme_dir_registrations')]
	public function test_theme_dir_assets_registered_after_theme_guard( string $needle ): void {
		$body  = self::frontend_callback_body();
		$guard = self::guard_offset( $body );

		$pos = strpos( $body, $needle );
		$this->assertNotFalse( $pos, "Registration not found in frontend callback: $needle" );
		$this->assertGreaterThan(
			$guard,
			$pos,
			"Theme-directory registration must stay below the guard (it 404s without the Lafka theme): $needle"
		);
	}

	public function test_guard_still_short_circuits_with_return(): void {
		$body  = self::frontend_callback_body();
		$guard = self::guard_offset( $body );

		// The guard must still early-return; we are only changing WHAT precedes it.
		$after = substr( $body, $guard, 80 );
		$this->assertStringContainsString( 'return;', $after, 'Theme guard no longer early-returns' );
	}
}
