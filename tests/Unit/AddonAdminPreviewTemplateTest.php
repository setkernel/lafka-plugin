<?php
/**
 * v8.12.5: integration test for the addon admin preview-template generation.
 *
 * Exercises the exact production path that fataled in v8.12.4: styles() builds
 * a preview HTML template by include()-ing html-addon-option.php and
 * html-addon.php with a synthesized $addon/$option in scope. If the option-row
 * or addon-group template trips over an undefined variable, strict typehint,
 * or unmocked function, this test will fatal — exactly the way production did.
 *
 * The behavioral resolver tests (AddonAttributeResolverTest) verify the
 * methods in isolation. THIS test verifies they work when invoked from the
 * actual template files in the same way styles() invokes them. Neither test
 * alone caught the v8.12.4 bug; together they pin both the unit contract and
 * the integration contract.
 *
 * @package Lafka\Plugin\Tests\Unit
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Lafka_Product_Addon_Admin;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/incl/addons/admin/class-lafka-product-addon-admin.php';

final class AddonAdminPreviewTemplateTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Minimal stubs for every WP/WC/Lafka function the option-row + addon
		// templates touch. The point is for the include() to complete without
		// fataling — output content is irrelevant.
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_html_e' )->alias( static function ( $text ) { echo $text; } );
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_textarea' )->returnArg();
		Functions\when( '_e' )->alias( static function ( $text ) { echo $text; } );
		Functions\when( '__' )->returnArg();
		Functions\when( 'selected' )->justReturn( '' );
		Functions\when( 'checked' )->justReturn( '' );
		Functions\when( 'do_action' )->justReturn( null );
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'wc_format_localized_price' )->returnArg();
		Functions\when( 'wc_attribute_taxonomy_name_by_id' )->justReturn( '' );
		Functions\when( 'taxonomy_exists' )->justReturn( false );
		Functions\when( 'get_terms' )->justReturn( array() );
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'is_product' )->justReturn( false );
		Functions\when( 'wp_generate_uuid4' )->justReturn( 'test-uuid-1234' );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'lafka_medialibrary_uploader' )->justReturn( '' );
		Functions\when( 'wc_attribute_taxonomy_id_by_name' )->justReturn( 0 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function preview_addon(): array {
		return array(
			'name'        => '',
			'limit'       => '',
			'description' => '',
			'required'    => '',
			'attribute'   => 0,
			'type'        => 'checkbox',
			'variations'  => 0,
			'options'     => array(),
		);
	}

	private function preview_option(): array {
		return Lafka_Product_Addon_Admin::get_new_addon_option();
	}

	/**
	 * The exact regression: include the option-row template with a preview
	 * shape and confirm it doesn't fatal. v8.12.4 fataled here because the
	 * resolver had a strict `array $addon` typehint and styles() didn't pass
	 * an $addon at all.
	 */
	public function test_option_row_template_does_not_fatal_in_preview_context(): void {
		$option = $this->preview_option();
		$addon  = $this->preview_addon();
		$loop   = '{loop}';

		ob_start();
		try {
			include __DIR__ . '/../../incl/addons/admin/views/html-addon-option.php';
			$rendered = ob_get_clean();
		} catch ( \Throwable $e ) {
			ob_end_clean();
			$this->fail( 'Option-row preview include fataled: ' . $e->getMessage() );
		}

		// If we got here, the template rendered. Anchor on a stable marker so
		// silent template breakage (e.g. early `<tr>` missing) also fails.
		self::assertStringContainsString( '<tr>', $rendered );
		self::assertStringContainsString( 'product_addon_option_label', $rendered );
	}

	public function test_addon_group_template_does_not_fatal_in_preview_context(): void {
		$addon                = $this->preview_addon();
		$addon['options']     = array( $this->preview_option() );
		$loop                 = '{loop}';
		$attribute_taxonomies = array();

		ob_start();
		try {
			include __DIR__ . '/../../incl/addons/admin/views/html-addon.php';
			$rendered = ob_get_clean();
		} catch ( \Throwable $e ) {
			ob_end_clean();
			$this->fail( 'Addon-group preview include fataled: ' . $e->getMessage() );
		}

		self::assertStringContainsString( 'lafka_product_addon', $rendered );
		self::assertStringContainsString( 'product_addon_type', $rendered );
	}

	/**
	 * Belt-and-suspenders: confirm the option-row template does NOT fatal
	 * even when $addon is the worst plausible shape — null. This catches
	 * any future template change that re-introduces the strict-typehint
	 * trap (e.g. by adding `: array` to a helper or by accessing $addon
	 * without a guard).
	 */
	public function test_option_row_template_survives_addon_being_null(): void {
		$option = $this->preview_option();
		$addon  = null;
		$loop   = '{loop}';

		ob_start();
		try {
			include __DIR__ . '/../../incl/addons/admin/views/html-addon-option.php';
			ob_get_clean();
		} catch ( \Throwable $e ) {
			ob_end_clean();
			$this->fail( 'Option-row template fataled with $addon = null: ' . $e->getMessage() );
		}
		// Reaching here passes — assertion is the absence of a fatal.
		self::assertTrue( true );
	}
}
