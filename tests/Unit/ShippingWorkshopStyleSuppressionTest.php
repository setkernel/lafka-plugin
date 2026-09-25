<?php
/**
 * P6-PERF-10: the Address-Field Autocomplete plugin enqueues a stylesheet
 * ('shipping-workshop-block') whose file it never ships, 404ing on every page.
 * The compat shim drops the handle while the file is missing and stands down
 * once the upstream plugin ships it.
 */

declare(strict_types=1);

namespace Lafka\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class ShippingWorkshopStyleSuppressionTest extends TestCase {

	/** @var string[] */
	private array $dropped = array();

	private string $css;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		if ( ! defined( 'WP_PLUGIN_DIR' ) ) {
			// Per process: a shared path let one run's "file shipped" fixture
			// break a concurrent run's "file missing" precondition.
			define( 'WP_PLUGIN_DIR', sys_get_temp_dir() . '/lafka-plugin-tests-wp-plugins-' . getmypid() );
		}
		$this->css = WP_PLUGIN_DIR . '/address-field-autocomplete-for-woocommerce/build/style-index.css';
		Functions\when( 'wp_dequeue_style' )->alias( fn( $handle ) => $this->dropped[] = 'dequeue:' . $handle );
		Functions\when( 'wp_deregister_style' )->alias( fn( $handle ) => $this->dropped[] = 'deregister:' . $handle );
		require_once dirname( __DIR__, 2 ) . '/incl/compat/lafka-address-autocomplete-compat.php';
	}

	protected function tearDown(): void {
		if ( is_file( $this->css ) ) {
			unlink( $this->css );
		}
		// Remove the fixture directories this test created, deepest first.
		for ( $dir = dirname( $this->css ); str_starts_with( $dir, WP_PLUGIN_DIR ) && is_dir( $dir ); $dir = dirname( $dir ) ) {
			@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- best-effort; non-empty dirs stay.
		}
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_missing_stylesheet_is_dropped(): void {
		$this->assertFileDoesNotExist( $this->css, 'Precondition: upstream file absent.' );

		lafka_suppress_missing_shipping_workshop_style();

		$this->assertSame( array( 'dequeue:shipping-workshop-block', 'deregister:shipping-workshop-block' ), $this->dropped );
	}

	public function test_shim_stands_down_once_upstream_ships_the_file(): void {
		if ( ! is_dir( dirname( $this->css ) ) ) {
			mkdir( dirname( $this->css ), 0777, true );
		}
		file_put_contents( $this->css, '' );

		lafka_suppress_missing_shipping_workshop_style();

		$this->assertSame( array(), $this->dropped );
	}
}
