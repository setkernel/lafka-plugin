<?php
/**
 * Per-variation "Show in Catalog?" option: saved from the variations form
 * under the frozen `_lafka_variable_in_catalog` key the theme reads, never
 * touched by out-of-band variation saves.
 *
 * @package Lafka_Plugin
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class VariableInCatalogMetaKeyTest extends TestCase {

	/** @var array<int, array{0: int, 1: string, 2: mixed}> */
	private array $writes = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		require_once dirname( __DIR__, 2 ) . '/incl/woocommerce-functions.php';
		$_POST = array();
		Functions\when( 'update_post_meta' )->alias(
			function ( $id, $key, $value ) {
				$this->writes[] = array( $id, $key, $value );
				return true;
			}
		);
	}

	protected function tearDown(): void {
		$_POST = array();
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_ticking_saves_on_under_the_key_the_theme_reads(): void {
		$_POST = array(
			'_lafka_variable_in_catalog_field' => array( 0 => '1' ),
			'_lafka_variable_in_catalog'       => array( 0 => 'on' ),
		);

		lafka_save_variable_in_catalog_option( 61, 0 );

		$this->assertSame( array( array( 61, '_lafka_variable_in_catalog', true ) ), $this->writes );
	}

	public function test_unticking_the_only_ticked_variation_saves_off(): void {
		// No checkbox is posted at all once the last one is unticked.
		$_POST = array( '_lafka_variable_in_catalog_field' => array( 0 => '1' ) );

		lafka_save_variable_in_catalog_option( 61, 0 );

		$this->assertSame( array( array( 61, '_lafka_variable_in_catalog', false ) ), $this->writes );
	}

	public function test_out_of_band_variation_saves_leave_the_option_alone(): void {
		lafka_save_variable_in_catalog_option( 61, 0 );

		$this->assertSame( array(), $this->writes );
	}
}
