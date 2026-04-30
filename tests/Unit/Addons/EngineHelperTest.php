<?php
declare(strict_types=1);
namespace LafkaPlugin\Tests\Unit\Addons;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Lafka_Addon_Group;
use Lafka_Engine_Helper;
use Lafka_Engine_Resolver;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 3 ) . '/incl/addons/engine/lafka-addons-engine-bootstrap.php';

/**
 * Helper produces the legacy array shape templates and field classes expect.
 * Field-name assignment must be deterministic so display + cart agree.
 */
final class EngineHelperTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Lafka_Engine_Resolver::clear_cache();
		Lafka_Engine_Helper::clear_cache();

		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'wp_generate_uuid4' )->justReturn( 'test-uuid-0000' );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'sanitize_title' )->alias(
			fn( $s ) => strtolower( preg_replace( '/[^a-z0-9-]+/i', '-', (string) $s ) )
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		Lafka_Engine_Resolver::clear_cache();
		Lafka_Engine_Helper::clear_cache();
		parent::tearDown();
	}

	public function test_get_product_addons_returns_empty_when_post_id_zero(): void {
		self::assertSame( array(), Lafka_Engine_Helper::get_product_addons( 0 ) );
	}

	public function test_group_to_legacy_array_has_required_keys(): void {
		$group = Lafka_Addon_Group::from_array(
			array(
				'name'        => 'Toppings',
				'description' => 'Pick your favorites',
				'type'        => 'checkbox',
				'required'    => 1,
				'limit'       => 3,
				'variations'  => 0,
				'attribute'   => 0,
				'options'     => array(
					array( 'id' => 'opt-1', 'label' => 'Cheese', 'price' => '1.50' ),
				),
			)
		);

		$arr = Lafka_Engine_Helper::group_to_legacy_array( $group );

		self::assertSame( 'Toppings', $arr['name'] );
		self::assertSame( 'Pick your favorites', $arr['description'] );
		self::assertSame( 'checkbox', $arr['type'] );
		self::assertSame( '1', $arr['required'] );
		self::assertSame( '3', $arr['limit'] );
		self::assertSame( 0, $arr['variations'] );
		self::assertSame( 0, $arr['attribute'] );
		self::assertCount( 1, $arr['options'] );
		self::assertSame( 'Cheese', $arr['options'][0]['label'] );
		self::assertSame( '1.50', $arr['options'][0]['price'] );
		self::assertSame( 'opt-1', $arr['options'][0]['id'] );
	}

	public function test_legacy_class_alias_dropped_in_v8_18_0(): void {
		// v8.18.0 removed the WC_Product_Addons_Helper class_alias. Internal
		// callers (templates, combos compat) all reference Lafka_Engine_Helper
		// directly now. Third-party themes/plugins that depended on the old
		// class name need to update — this is a deliberate breaking change
		// in the major-cleanup release.
		self::assertFalse(
			class_exists( 'WC_Product_Addons_Helper', false ),
			'WC_Product_Addons_Helper alias should be gone — Lafka_Engine_Helper is the canonical class'
		);
	}

	public function test_is_addon_required_with_empty(): void {
		self::assertFalse( Lafka_Engine_Helper::is_addon_required( array() ) );
	}

	public function test_is_addon_required_with_one(): void {
		self::assertTrue( Lafka_Engine_Helper::is_addon_required( array( 'required' => '1' ) ) );
	}

	public function test_is_addon_required_with_zero(): void {
		self::assertFalse( Lafka_Engine_Helper::is_addon_required( array( 'required' => '0' ) ) );
	}

	public function test_should_display_description_requires_both_enable_and_text(): void {
		self::assertFalse(
			Lafka_Engine_Helper::should_display_description(
				array( 'description_enable' => 1, 'description' => '' )
			)
		);
		self::assertTrue(
			Lafka_Engine_Helper::should_display_description(
				array( 'description_enable' => 1, 'description' => 'hi' )
			)
		);
		self::assertFalse(
			Lafka_Engine_Helper::should_display_description(
				array( 'description_enable' => 0, 'description' => 'hi' )
			)
		);
	}
}
