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

	public function test_excluded_options_are_not_offered_and_empty_groups_are_dropped(): void {
		$stored = array(
			array(
				'name'    => 'Toppings',
				'type'    => 'checkbox',
				'options' => array(
					array( 'id' => 'cheese', 'label' => 'Cheese', 'price' => '1.00', 'included' => true ),
					array( 'id' => 'truffle', 'label' => 'Truffle', 'price' => '9.00', 'included' => false ),
				),
			),
			array(
				'name'     => 'Sauce',
				'type'     => 'radiobutton',
				'required' => 1,
				'options'  => array(
					array( 'id' => 'bbq', 'label' => 'BBQ', 'price' => '', 'included' => false ),
				),
			),
		);
		$product = new class() {
			public function get_meta( $key ) {
				return '';
			}
			public function get_attributes() {
				return array();
			}
		};
		Functions\when( 'wc_get_product' )->justReturn( $product );
		Functions\when( 'wp_get_post_parent_id' )->justReturn( 0 );
		Functions\when( 'get_posts' )->justReturn( array() );
		Functions\when( 'wc_get_object_terms' )->justReturn( array() );
		Functions\when( 'get_post_meta' )->alias(
			static fn( $id, $key ) => '_product_addons' === $key && 7 === $id ? $stored : ''
		);

		$addons = Lafka_Engine_Helper::get_product_addons( 7 );

		self::assertCount( 1, $addons, 'A group with every option excluded must not be offered at all.' );
		self::assertSame( 'Toppings', $addons[0]['name'] );
		self::assertSame( array( 'Cheese' ), array_column( $addons[0]['options'], 'label' ) );
	}

	public function test_is_addon_required_reads_the_required_flag(): void {
		self::assertFalse( Lafka_Engine_Helper::is_addon_required( array() ) );
		self::assertFalse( Lafka_Engine_Helper::is_addon_required( array( 'required' => '0' ) ) );
		self::assertTrue( Lafka_Engine_Helper::is_addon_required( array( 'required' => '1' ) ) );
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
