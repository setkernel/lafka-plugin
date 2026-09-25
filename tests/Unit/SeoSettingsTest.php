<?php
/**
 * GX3 Search & AI settings: option accessors fall back to working defaults
 * (llms.txt on, IndexNow off, templates filled), a cleared template restores
 * its default, and every option is editable in WooCommerce → Settings →
 * Restaurant (Search & AI / Schema & Geo sections).
 *
 * @package Lafka\Plugin\Tests\Unit
 */

declare(strict_types=1);

namespace {
	if ( ! class_exists( 'WC_Settings_Page' ) ) {
		// Minimal parent for the Restaurant settings tab.
		class WC_Settings_Page { // phpcs:ignore
			public $id    = '';
			public $label = '';
			public function __construct() {
			}
		}
	}
}

namespace LafkaPlugin\Tests\Unit {

	use Brain\Monkey;
	use Brain\Monkey\Functions;
	use PHPUnit\Framework\TestCase;

	final class SeoSettingsTest extends TestCase {

		/** @var array<string, mixed> */
		private array $options = array();

		protected function setUp(): void {
			parent::setUp();
			Monkey\setUp();
			$this->options = array();
			Functions\when( 'get_option' )->alias( fn( $key, $default = false ) => $this->options[ $key ] ?? $default );
			Functions\when( 'apply_filters' )->returnArg( 2 );
			Functions\when( '__' )->returnArg();
			Functions\when( 'esc_html__' )->returnArg();
			Functions\when( 'esc_html' )->returnArg();
			Functions\when( 'esc_url' )->returnArg();
			Functions\when( 'wp_kses_post' )->returnArg();
			Functions\when( 'admin_url' )->returnArg();
			Functions\when( 'home_url' )->alias( static fn( $p = '' ) => 'https://example.test' . $p );
			Functions\when( 'get_terms' )->justReturn( array() );
			require_once dirname( __DIR__, 2 ) . '/incl/seo/lafka-seo-settings.php';
			require_once dirname( __DIR__, 2 ) . '/incl/admin/class-lafka-wc-settings-restaurant.php';
		}

		protected function tearDown(): void {
			Monkey\tearDown();
			parent::tearDown();
		}

		public function test_defaults_ship_llms_on_and_indexnow_off(): void {
			self::assertTrue( lafka_seo_is_on( 'lafka_seo_llms_enabled' ) );
			self::assertFalse( lafka_seo_is_on( 'lafka_seo_indexnow_enabled' ) );
			self::assertFalse( lafka_seo_is_on( 'lafka_seo_menu_schema_on_home' ) );
			self::assertStringContainsString( '{term}', lafka_seo_get( 'lafka_seo_title_category' ) );
		}

		public function test_stored_values_win_and_blank_restores_the_default(): void {
			$this->options['lafka_seo_indexnow_enabled'] = 'yes';
			$this->options['lafka_seo_llms_enabled']     = 'no';
			$this->options['lafka_seo_title_home']       = '   ';
			self::assertTrue( lafka_seo_is_on( 'lafka_seo_indexnow_enabled' ) );
			self::assertFalse( lafka_seo_is_on( 'lafka_seo_llms_enabled' ) );
			self::assertSame( lafka_seo_defaults()['lafka_seo_title_home'], lafka_seo_get( 'lafka_seo_title_home' ) );
		}

		public function test_every_option_is_editable_in_the_restaurant_settings_tab(): void {
			if ( ! class_exists( 'Lafka_WC_Settings_Restaurant' ) ) {
				lafka_define_wc_settings_restaurant_class();
			}
			$page = new \Lafka_WC_Settings_Restaurant();

			self::assertArrayHasKey( 'search', $page->get_sections() );

			$ids = array();
			foreach ( array( 'search', 'schema' ) as $section ) {
				foreach ( $page->get_settings_for_section_core( $section ) as $field ) {
					if ( isset( $field['id'] ) ) {
						$ids[] = $field['id'];
					}
				}
			}
			foreach ( array_keys( lafka_seo_defaults() ) as $option ) {
				self::assertContains( $option, $ids, $option . ' has no settings field' );
			}
			foreach ( array( 'lafka_business_description', 'lafka_business_map_url', 'lafka_business_service_areas' ) as $option ) {
				self::assertContains( $option, $ids );
			}
		}
	}
}
