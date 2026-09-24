<?php
/**
 * ConversionCustomizerSettingsTest — every setting the conversion Customizer
 * panels (abandoned cart, web push, reviews) register must ship a default and
 * a real sanitize_callback, and every control must point at a registered
 * setting and section. Untrusted Customizer payloads reach theme_mods only
 * through these callbacks.
 *
 * Drives each class's register() against a recording WP_Customize_Manager
 * double instead of grepping the source.
 *
 * @package Lafka\Plugin\Tests\Unit
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/incl/customizer/class-lafka-customizer-abandoned-cart.php';
require_once dirname( __DIR__, 2 ) . '/incl/customizer/class-lafka-customizer-push.php';
require_once dirname( __DIR__, 2 ) . '/incl/customizer/class-lafka-customizer-reviews.php';

final class ConversionCustomizerSettingsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_attr__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'admin_url' )->returnArg();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @return array<string,array{0:class-string}>
	 */
	public static function provider_panels(): array {
		return array(
			'abandoned cart' => array( 'Lafka_Customizer_Abandoned_Cart' ),
			'web push'       => array( 'Lafka_Customizer_Push' ),
			'reviews'        => array( 'Lafka_Customizer_Reviews' ),
		);
	}

	#[DataProvider( 'provider_panels' )]
	public function test_every_setting_has_a_default_and_a_real_sanitizer( string $class ): void {
		$manager = new class() {
			/** @var array<string,array<string,mixed>> */
			public array $settings = array();
			/** @var array<string,array<string,mixed>> */
			public array $controls = array();
			/** @var array<int,string> */
			public array $sections = array();
			public function add_panel( $id, $args = array() ) {}
			public function add_section( $id, $args = array() ) {
				$this->sections[] = $id;
			}
			public function add_setting( $id, $args = array() ) {
				$this->settings[ $id ] = $args;
			}
			public function add_control( $id, $args = array() ) {
				$this->controls[ $id ] = $args;
			}
		};

		$class::register( $manager );

		$this->assertNotEmpty( $manager->settings );
		$core_sanitizers = array( 'sanitize_text_field', 'sanitize_textarea_field' );
		$problems        = array();
		foreach ( $manager->settings as $id => $args ) {
			if ( ! array_key_exists( 'default', $args ) ) {
				$problems[] = "{$id}: no default";
			}
			$cb = $args['sanitize_callback'] ?? null;
			if ( ! ( is_callable( $cb ) || in_array( $cb, $core_sanitizers, true ) ) ) {
				$problems[] = "{$id}: no usable sanitize_callback";
			}
		}
		foreach ( $manager->controls as $id => $args ) {
			if ( ! isset( $manager->settings[ $args['settings'] ?? $id ] ) ) {
				$problems[] = "control {$id}: no matching setting";
			}
			if ( ! in_array( $args['section'] ?? '', $manager->sections, true ) ) {
				$problems[] = "control {$id}: unknown section";
			}
		}
		$this->assertSame( array(), $problems );
	}
}
