<?php
/**
 * [lafka_shipping_areas] renders without WPBakery.
 *
 * @package Lafka_Plugin
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class ShippingAreasShortcodeTest extends TestCase {

	/** @var array<int, array{0: string, 1: string}> */
	private array $enqueued = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'shortcode_atts' )->alias( static fn( $defaults, $atts ) => array_merge( $defaults, (array) $atts ) );
		Functions\when( 'get_option' )->alias( static fn( $key, $fallback = false ) => 'lafka_shipping_areas_advanced' === $key ? array() : '' );
		Functions\when( 'wp_unique_id' )->alias( static fn( $prefix ) => $prefix . '1' );
		Functions\when( 'wp_script_is' )->justReturn( true );
		Functions\when( 'plugins_url' )->alias( static fn( $path ) => 'https://example.test/wp-content/plugins/lafka-plugin/' . $path );
		Functions\when( 'lafka_plugin_asset_version' )->justReturn( '1' );
		Functions\when( 'wp_enqueue_script' )->alias(
			function ( $handle, $src ) {
				$this->enqueued[] = array( $handle, $src );
			}
		);
		Functions\when( 'wp_localize_script' )->justReturn( true );
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		require_once dirname( __DIR__, 2 ) . '/incl/map-shortcode/shortcode-lafka-shipping-areas.php';
		if ( ! class_exists( 'Lafka_Shipping_Areas', false ) ) {
			require_once dirname( __DIR__, 2 ) . '/incl/shipping-areas/class-lafka-shipping-areas.php';
		}
		Functions\when( 'WC' )->justReturn( (object) array( 'countries' => null ) );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_renders_the_map_container_without_wpbakery(): void {
		$this->assertFalse( defined( 'VC_SHORTCODE_CUSTOM_CSS_FILTER_TAG' ), 'Precondition: WPBakery is not loaded.' );

		$html = lafka_shipping_areas_shortcode( array( 'title' => 'Where we deliver' ) );

		$this->assertStringContainsString( 'id="lafka_shipping_areas_shortcode1_map"', $html );
		$this->assertStringContainsString( 'Where we deliver', $html );
	}

	public function test_enqueues_the_map_script_from_the_plugin_assets(): void {
		lafka_shipping_areas_shortcode( array() );

		$this->assertSame(
			'https://example.test/wp-content/plugins/lafka-plugin/incl/shipping-areas/assets/js/frontend/lafka-shipping-areas-shortcode.min.js',
			$this->enqueued[0][1] ?? null
		);
	}
}
