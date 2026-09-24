<?php
/**
 * The shipping-areas front stylesheet loads only where its markup renders:
 * cart/checkout, or sitewide while branch selection (modal + mini-cart bar)
 * is enabled.
 *
 * @package Lafka_Plugin
 */

declare(strict_types=1);

namespace Lafka\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Lafka_Shipping_Areas;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class ShippingAreasFrontAssetsTest extends TestCase {

	/** @var array<string, mixed> */
	private array $options = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		require_once dirname( __DIR__, 2 ) . '/incl/shipping-areas/class-lafka-shipping-areas.php';
		Functions\when( 'get_option' )->alias( fn( $key ) => $this->options[ $key ] ?? array() );
		Functions\when( 'is_cart' )->justReturn( false );
		Functions\when( 'is_checkout' )->justReturn( false );
		Functions\when( 'plugins_url' )->returnArg();
		Functions\when( 'lafka_plugin_asset_version' )->justReturn( '1' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function styles_enqueued(): array {
		$styles = array();
		Functions\when( 'wp_enqueue_style' )->alias(
			static function ( $handle ) use ( &$styles ) {
				$styles[] = $handle;
			}
		);
		( new ReflectionClass( Lafka_Shipping_Areas::class ) )->newInstanceWithoutConstructor()->enqueue_scripts();
		return $styles;
	}

	public function test_no_stylesheet_on_ordinary_pages(): void {
		$this->assertSame( array(), $this->styles_enqueued() );
	}

	public function test_stylesheet_sitewide_while_branch_selection_is_on(): void {
		$this->options['lafka_shipping_areas_branches'] = array( 'enable_branch_selection_modal' => '1' );

		$this->assertSame( array( 'lafka-shipping-areas-front' ), $this->styles_enqueued() );
	}
}
