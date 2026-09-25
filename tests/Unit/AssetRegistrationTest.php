<?php
/**
 * Handle registration: plugin-owned assets under any theme, theme-owned
 * handles never overridden, Google Maps only with a key.
 *
 * @package Lafka_Plugin
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class AssetRegistrationTest extends TestCase {

	/** @var array<string, array{src: string, deps: mixed, args: mixed}> */
	private array $scripts = array();
	/** @var array<string, string> */
	private array $styles = array();
	private string $template = 'twentytwentyfive';
	private string $maps_key = '';
	private string $theme_dir = '/nonexistent-theme';
	private string $locale   = 'en_US';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'plugins_url' )->alias( static fn( $path ) => 'https://example.test/plugin/' . $path );
		Functions\when( 'plugin_dir_path' )->justReturn( dirname( __DIR__, 2 ) . '/' );
		Functions\when( 'lafka_plugin_asset_version' )->justReturn( '1' );
		Functions\when( 'get_locale' )->alias( fn() => $this->locale );
		Functions\when( 'get_stylesheet_directory' )->justReturn( '/nonexistent' );
		Functions\when( 'get_stylesheet_directory_uri' )->justReturn( 'https://example.test/theme' );
		Functions\when( 'get_template_directory_uri' )->justReturn( 'https://example.test/theme' );
		Functions\when( 'get_template_directory' )->alias( fn() => $this->theme_dir );
		Functions\when( 'lafka_asset_version' )->justReturn( '1' );
		Functions\when( 'lafka_get_option' )->alias( fn( $name ) => 'google_maps_api_key' === $name ? $this->maps_key : '' );
		Functions\when( 'wp_get_theme' )->alias(
			fn() => new class( $this->template ) {
				public function __construct( private string $template ) {}
				public function get_template() {
					return $this->template;
				}
				public function get( $key ) {
					return '1.0';
				}
			}
		);
		Functions\when( 'wp_register_script' )->alias(
			function ( $handle, $src, $deps = array(), $ver = false, $args = array() ) {
				if ( isset( $this->scripts[ $handle ] ) ) {
					return false; // WP keeps the first registration.
				}
				$this->scripts[ $handle ] = array(
					'src'  => $src,
					'deps' => $deps,
					'args' => $args,
				);
				return true;
			}
		);
		Functions\when( 'wp_register_style' )->alias(
			function ( $handle, $src ) {
				if ( ! isset( $this->styles[ $handle ] ) ) {
					$this->styles[ $handle ] = $src;
				}
				return true;
			}
		);
		Functions\when( 'wp_script_is' )->alias( fn( $handle ) => isset( $this->scripts[ $handle ] ) );
		Functions\when( 'wp_scripts' )->alias(
			fn() => new class( $this->scripts ) {
				public function __construct( private array $scripts ) {}
				public function query( $handle ) {
					return (object) array(
						'src'  => $this->scripts[ $handle ]['src'],
						'deps' => $this->scripts[ $handle ]['deps'],
						'ver'  => '1',
					);
				}
			}
		);
		Functions\when( 'wp_style_is' )->alias( fn( $handle ) => isset( $this->styles[ $handle ] ) );
		require_once dirname( __DIR__, 2 ) . '/incl/lafka-asset-registration.php';
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function run_front_end(): void {
		lafka_register_plugin_scripts();
		lafka_register_theme_script_fallbacks();
	}

	public function test_plugin_owned_assets_and_font_awesome_register_under_any_theme(): void {
		$this->run_front_end();

		$this->assertArrayHasKey( 'flatpickr', $this->scripts );
		$this->assertArrayHasKey( 'flatpickr', $this->styles );
		$this->assertStringStartsWith( 'https://example.test/plugin/assets/vendor/font-awesome/', $this->styles['font_awesome_6'] );
		$this->assertArrayNotHasKey( 'magnific', $this->scripts, 'Theme-directory handles would 404 under another theme.' );
	}

	public function test_the_lafka_themes_own_registration_wins(): void {
		$this->template = 'lafka';
		// The theme registered at priority 10 with its defer strategy.
		$this->scripts['typed'] = array(
			'src'  => 'https://example.test/theme/js/typed.min.js',
			'deps' => array(),
			'args' => array( 'strategy' => 'defer' ),
		);
		$this->styles['font_awesome_6'] = 'https://example.test/theme/styles/font-awesome/css/all.min.css';

		$this->run_front_end();

		$this->assertSame( array( 'strategy' => 'defer' ), $this->scripts['typed']['args'] );
		$this->assertSame( 'https://example.test/theme/styles/font-awesome/css/all.min.css', $this->styles['font_awesome_6'] );
		$this->assertArrayHasKey( 'magnific', $this->scripts, 'Handles the theme leaves to the plugin are filled in.' );
	}

	/**
	 * Only the site locale's flatpickr l10n file is registered (never the whole
	 * directory), picked full-locale first then by language code; English needs
	 * none. 'flatpickr-local' stays as a back-compat alias of the same file.
	 */
	public function test_flatpickr_registers_only_the_site_locales_l10n_file(): void {
		$this->locale = 'fr_CA'; // No fr-ca.js / fr_ca.js ships, so the language file is used.
		lafka_register_plugin_scripts();

		$l10n = array_filter( $this->scripts, static fn( $s ) => str_contains( (string) $s['src'], '/flatpickr/l10n/' ) );
		$this->assertSame( array( 'flatpickr-l10n', 'flatpickr-local' ), array_keys( $l10n ) );
		$this->assertSame( 'https://example.test/plugin/assets/js/flatpickr/l10n/fr.js', $this->scripts['flatpickr-l10n']['src'] );
		$this->assertSame( $this->scripts['flatpickr-l10n']['src'], $this->scripts['flatpickr-local']['src'] );
		$this->assertSame( array( 'flatpickr' ), $this->scripts['flatpickr-l10n']['deps'] );
	}

	public function test_flatpickr_registers_no_l10n_file_for_english(): void {
		lafka_register_plugin_scripts();

		$this->assertArrayHasKey( 'flatpickr', $this->scripts );
		$this->assertArrayNotHasKey( 'flatpickr-l10n', $this->scripts );
		$this->assertArrayNotHasKey( 'flatpickr-local', $this->scripts );
	}

	public function test_google_maps_is_registered_only_with_a_key(): void {
		lafka_register_plugin_scripts();
		$this->assertArrayNotHasKey( 'lafka-google-maps', $this->scripts );

		$this->maps_key = 'key with&chars';
		lafka_register_plugin_scripts();
		$this->assertStringContainsString( 'key=key%20with%26chars&', $this->scripts['lafka-google-maps']['src'] );
	}

	/**
	 * The Maps loader is deferred through the WP strategy API (no script-tag
	 * string rewriting), in the footer, front end and admin alike.
	 */
	public function test_google_maps_loader_uses_the_defer_strategy(): void {
		$this->maps_key = 'abc';
		Functions\when( 'get_current_screen' )->justReturn( null );
		$expected = array(
			'in_footer' => true,
			'strategy'  => 'defer',
		);

		lafka_register_plugin_scripts();
		$this->assertSame( $expected, $this->scripts['lafka-google-maps']['args'] );

		$this->scripts = array();
		lafka_register_admin_plugin_scripts();
		$this->assertSame( $expected, $this->scripts['lafka-google-maps']['args'] );
	}

	public function test_admin_google_maps_is_registered_only_with_a_key(): void {
		Functions\when( 'get_current_screen' )->justReturn( null );

		lafka_register_admin_plugin_scripts();

		$this->assertArrayNotHasKey( 'lafka-google-maps', $this->scripts, 'No key must mean no (401-ing) Maps loader in wp-admin either.' );
	}

	public function test_dialog_fallback_uses_the_themes_min_build_only_when_it_exists(): void {
		$this->template = 'lafka';
		lafka_register_theme_script_fallbacks();
		$this->assertSame( 'https://example.test/theme/js/lafka-dialog.js', $this->scripts['lafka-dialog']['src'], 'No .min on disk: the source.' );

		$this->scripts   = array();
		$this->theme_dir = sys_get_temp_dir() . '/lafka-theme-' . getmypid();
		@mkdir( $this->theme_dir . '/js', 0777, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		touch( $this->theme_dir . '/js/lafka-dialog.min.js' );
		lafka_register_theme_script_fallbacks();
		unlink( $this->theme_dir . '/js/lafka-dialog.min.js' );
		rmdir( $this->theme_dir . '/js' );
		rmdir( $this->theme_dir );

		$this->assertSame( 'https://example.test/theme/js/lafka-dialog.min.js', $this->scripts['lafka-dialog']['src'] );
	}
}
