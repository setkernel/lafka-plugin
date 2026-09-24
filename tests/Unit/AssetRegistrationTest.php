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

	/** @var array<string, array{src: string, args: mixed}> */
	private array $scripts = array();
	/** @var array<string, string> */
	private array $styles = array();
	private string $template = 'twentytwentyfive';
	private string $maps_key = '';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'plugins_url' )->alias( static fn( $path ) => 'https://example.test/plugin/' . $path );
		Functions\when( 'plugin_dir_path' )->justReturn( dirname( __DIR__, 2 ) . '/' );
		Functions\when( 'lafka_plugin_asset_version' )->justReturn( '1' );
		Functions\when( 'get_locale' )->justReturn( 'en_US' );
		Functions\when( 'get_stylesheet_directory' )->justReturn( '/nonexistent' );
		Functions\when( 'get_stylesheet_directory_uri' )->justReturn( 'https://example.test/theme' );
		Functions\when( 'get_template_directory_uri' )->justReturn( 'https://example.test/theme' );
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
			'args' => array( 'strategy' => 'defer' ),
		);
		$this->styles['font_awesome_6'] = 'https://example.test/theme/styles/font-awesome/css/all.min.css';

		$this->run_front_end();

		$this->assertSame( array( 'strategy' => 'defer' ), $this->scripts['typed']['args'] );
		$this->assertSame( 'https://example.test/theme/styles/font-awesome/css/all.min.css', $this->styles['font_awesome_6'] );
		$this->assertArrayHasKey( 'magnific', $this->scripts, 'Handles the theme leaves to the plugin are filled in.' );
	}

	public function test_google_maps_is_registered_only_with_a_key(): void {
		lafka_register_plugin_scripts();
		$this->assertArrayNotHasKey( 'lafka-google-maps', $this->scripts );

		$this->maps_key = 'key with&chars';
		lafka_register_plugin_scripts();
		$this->assertStringContainsString( 'key=key%20with%26chars&', $this->scripts['lafka-google-maps']['src'] );
	}

	public function test_admin_google_maps_is_registered_only_with_a_key(): void {
		Functions\when( 'get_current_screen' )->justReturn( null );

		lafka_register_admin_plugin_scripts();

		$this->assertArrayNotHasKey( 'lafka-google-maps', $this->scripts, 'No key must mean no (401-ing) Maps loader in wp-admin either.' );
	}
}
