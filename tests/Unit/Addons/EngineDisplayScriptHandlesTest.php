<?php
/**
 * The add-on price script depends on WooCommerce's canonical `wc-accounting`
 * handle and never claims `accounting`, WooCommerce's deprecated alias: it
 * reuses WooCommerce's registration when present and only registers the same
 * bundled file under `wc-accounting` when it is not.
 *
 * @package Lafka_Plugin
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit\Addons;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Lafka_Engine_Display;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

require_once dirname( __DIR__, 3 ) . '/incl/addons/engine/lafka-addons-engine-bootstrap.php';
require_once dirname( __DIR__, 3 ) . '/incl/addons/engine/display/class-engine-display.php';

final class EngineDisplayScriptHandlesTest extends TestCase {

	/** @var array<string, array{src: mixed, deps: array}> */
	private array $registered = array();
	/** @var array<string, array> */
	private array $enqueued = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'WC' )->justReturn(
			new class() {
				public function plugin_url() {
					return 'https://example.test/wc';
				}
				public function ajax_url() {
					return 'https://example.test/wp-admin/admin-ajax.php';
				}
			}
		);
		Functions\when( 'plugins_url' )->alias( static fn( $path ) => 'https://example.test/plugin/' . $path );
		Functions\when( 'lafka_plugin_asset_version' )->justReturn( '1' );
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'get_option' )->justReturn( '' );
		Functions\when( 'absint' )->alias( static fn( $v ) => abs( (int) $v ) );
		Functions\when( 'get_woocommerce_currency_symbol' )->justReturn( '$' );
		Functions\when( 'get_woocommerce_price_format' )->justReturn( '%1$s%2$s' );
		Functions\when( 'wp_localize_script' )->justReturn( true );
		Functions\when( 'wp_script_is' )->alias( fn( $handle ) => isset( $this->registered[ $handle ] ) );
		Functions\when( 'wp_register_script' )->alias(
			function ( $handle, $src, $deps = array() ) {
				if ( ! isset( $this->registered[ $handle ] ) ) {
					$this->registered[ $handle ] = array(
						'src'  => $src,
						'deps' => $deps,
					);
				}
				return true;
			}
		);
		Functions\when( 'wp_enqueue_script' )->alias(
			function ( $handle, $src = '', $deps = array() ) {
				$this->enqueued[ $handle ] = $deps;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function enqueue(): void {
		( new ReflectionClass( Lafka_Engine_Display::class ) )->newInstanceWithoutConstructor()->enqueue_scripts();
	}

	public function test_addons_depend_on_wc_accounting_and_never_claim_the_alias(): void {
		$this->enqueue();

		$this->assertSame( array( 'jquery', 'wc-accounting' ), $this->enqueued['lafka-addons'] );
		$this->assertStringStartsWith( 'https://example.test/wc/assets/js/accounting/accounting', (string) $this->registered['wc-accounting']['src'] );
		$this->assertArrayNotHasKey( 'accounting', $this->registered );
	}

	public function test_woocommerces_own_registration_is_reused(): void {
		$this->registered['wc-accounting'] = array(
			'src'  => 'https://example.test/wc/assets/js/accounting/accounting.min.js?wc',
			'deps' => array( 'jquery' ),
		);

		$this->enqueue();

		$this->assertSame( 'https://example.test/wc/assets/js/accounting/accounting.min.js?wc', $this->registered['wc-accounting']['src'] );
		$this->assertSame( array( 'jquery', 'wc-accounting' ), $this->enqueued['lafka-addons'] );
	}
}
