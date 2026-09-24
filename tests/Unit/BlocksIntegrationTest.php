<?php
/**
 * BlocksIntegrationTest — NX1-04b.
 *
 * Locks the build-free block Cart/Checkout integration (Lafka_Blocks_Integration)
 * and its JS component: the integration registers only when WC Blocks is present
 * and only in blocks mode, the script declares the right dependencies, the config
 * payload mirrors the classic timeslot conditions, and the shipped JS is JSX-free
 * (createElement only, no build artifacts).
 *
 * @package Lafka_Plugin
 */

declare(strict_types=1);

namespace Lafka\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Lafka_Blocks_Integration;
use PHPUnit\Framework\TestCase;

final class BlocksIntegrationTest extends TestCase {

	private string $js;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );

		require_once __DIR__ . '/Stubs/wc-blocks-integration-interface-stub.php';
		if ( ! class_exists( 'Lafka_Checkout_Mode', false ) ) {
			require_once dirname( __DIR__, 2 ) . '/incl/checkout/class-lafka-checkout-mode.php';
		}
		if ( ! class_exists( 'Lafka_Blocks_Integration', false ) ) {
			require_once dirname( __DIR__, 2 ) . '/incl/checkout/class-lafka-blocks-integration.php';
		}
		require_once dirname( __DIR__, 2 ) . '/incl/store-api/class-lafka-store-api.php';

		$this->js = (string) file_get_contents(
			dirname( __DIR__, 2 ) . '/incl/checkout/assets/js/lafka-blocks-checkout.js'
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function set_mode( string $mode ): void {
		Functions\when( 'get_option' )->alias(
			static function ( $key, $default = false ) use ( $mode ) {
				if ( 'lafka_checkout_mode' === $key ) {
					return $mode;
				}
				return $default;
			}
		);
	}

	/* ----------------------------------------------------------------- *
	 *  Registration gating
	 * ----------------------------------------------------------------- */

	public function test_integration_registered_in_blocks_mode(): void {
		$this->set_mode( 'blocks' );
		$registry = new FakeIntegrationRegistry();

		Lafka_Blocks_Integration::register_integration( $registry );

		$this->assertCount( 1, $registry->registered );
		$this->assertInstanceOf( Lafka_Blocks_Integration::class, $registry->registered[0] );
		$this->assertSame( 'lafka-checkout', $registry->registered[0]->get_name() );
	}

	public function test_integration_not_registered_in_classic_mode(): void {
		$this->set_mode( 'classic' );
		$registry = new FakeIntegrationRegistry();

		Lafka_Blocks_Integration::register_integration( $registry );

		$this->assertCount( 0, $registry->registered );
	}

	public function test_register_integration_ignores_a_bad_registry(): void {
		$this->set_mode( 'blocks' );
		// Non-object / missing register() must not fatal.
		Lafka_Blocks_Integration::register_integration( null );
		Lafka_Blocks_Integration::register_integration( new \stdClass() );
		$this->addToAssertionCount( 1 );
	}

	/* ----------------------------------------------------------------- *
	 *  Script registration + deps
	 * ----------------------------------------------------------------- */

	public function test_initialize_registers_script_with_block_deps(): void {
		$captured = array();
		Functions\when( 'lafka_plugin_asset_version' )->justReturn( '123' );
		Functions\when( 'plugins_url' )->justReturn( 'https://example.test/lafka.js' );
		Functions\when( 'wp_register_script' )->alias(
			static function ( $handle, $src, $deps, $ver, $in_footer ) use ( &$captured ) {
				$captured = compact( 'handle', 'src', 'deps', 'ver', 'in_footer' );
			}
		);

		( new Lafka_Blocks_Integration() )->initialize();

		$this->assertSame( 'lafka-blocks-checkout', $captured['handle'] );
		$this->assertTrue( $captured['in_footer'] );
		foreach ( array( 'wp-element', 'wp-plugins', 'wc-blocks-checkout', 'wc-settings' ) as $dep ) {
			$this->assertContains( $dep, $captured['deps'], "Missing script dependency: {$dep}" );
		}
	}

	public function test_script_handles_expose_frontend_only(): void {
		$integration = new Lafka_Blocks_Integration();
		$this->assertSame( array( 'lafka-blocks-checkout' ), $integration->get_script_handles() );
		$this->assertSame( array(), $integration->get_editor_script_handles() );
	}

	/* ----------------------------------------------------------------- *
	 *  Script data (timeslot config mirrors classic conditions)
	 * ----------------------------------------------------------------- */

	public function test_timeslot_config_disabled_when_datetime_off(): void {
		Functions\when( 'get_option' )->alias(
			static function ( $key ) {
				if ( 'lafka_shipping_areas_datetime' === $key ) {
					return array( 'enable_datetime_option' => false );
				}
				return array();
			}
		);
		Functions\when( 'admin_url' )->justReturn( 'https://example.test/wp-admin/admin-ajax.php' );
		Functions\when( 'get_woocommerce_currency_symbol' )->justReturn( '$' );

		$data = ( new Lafka_Blocks_Integration() )->get_script_data();

		$this->assertFalse( $data['timeslot']['enabled'] );
		$this->assertSame( '', $data['timeslot']['nonce'], 'No nonce when the picker is off.' );
		$this->assertArrayHasKey( 'ajaxUrl', $data );
		$this->assertArrayHasKey( 'i18n', $data );
	}

	public function test_timeslot_config_enabled_carries_nonce(): void {
		Functions\when( 'get_option' )->alias(
			static function ( $key ) {
				if ( 'lafka_shipping_areas_datetime' === $key ) {
					return array( 'enable_datetime_option' => true );
				}
				return array();
			}
		);
		Functions\when( 'admin_url' )->justReturn( 'https://example.test/wp-admin/admin-ajax.php' );
		Functions\when( 'get_woocommerce_currency_symbol' )->justReturn( '$' );
		Functions\when( 'wp_create_nonce' )->justReturn( 'nonce123' );

		$data = ( new Lafka_Blocks_Integration() )->get_script_data();

		$this->assertTrue( $data['timeslot']['enabled'] );
		$this->assertSame( 'nonce123', $data['timeslot']['nonce'] );
	}

	/* ----------------------------------------------------------------- *
	 *  Shipped JS: build-free, and wired to the PHP side's names
	 * ----------------------------------------------------------------- */

	public function test_js_ships_without_jsx(): void {
		// No build step: JSX (`<Tag`, `</Tag>`, `/>`) would be a syntax error in the browser.
		$jsx = array();
		foreach ( preg_split( '/\R/', $this->js ) as $n => $line ) {
			if ( preg_match( '/<\/?[A-Za-z][A-Za-z0-9]*(\s|>|\/)|\/>/', $line ) ) {
				$jsx[] = ( $n + 1 ) . ': ' . trim( $line );
			}
		}
		$this->assertSame( array(), $jsx );
	}

	public function test_js_talks_to_the_store_api_namespace_and_ajax_action(): void {
		// Renaming either side alone silently breaks the block checkout fields.
		$this->assertStringContainsString( 'extensions.' . \Lafka_Store_Api::LAFKA_NAMESPACE, $this->js );
		$this->assertStringContainsString( "namespace: '" . \Lafka_Store_Api::LAFKA_NAMESPACE . "'", $this->js );
		$this->assertStringContainsString( "'time_slots_for_date'", $this->js, 'The wp_ajax action Lafka_Timeslots serves.' );
	}
}

/**
 * Minimal IntegrationRegistry stand-in capturing register() calls.
 */
final class FakeIntegrationRegistry {
	/** @var object[] */
	public array $registered = array();
	public function register( $integration ) {
		$this->registered[] = $integration;
		return true;
	}
}
