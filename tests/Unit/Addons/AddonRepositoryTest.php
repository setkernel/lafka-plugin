<?php
declare(strict_types=1);
namespace LafkaPlugin\Tests\Unit\Addons;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Lafka_Addon_Group;
use Lafka_Addon_Repository;
use Lafka_Addon_Schema;
use Lafka_Addons_Upgrader;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 3 ) . '/incl/addons/engine/lafka-addons-engine-bootstrap.php';

final class AddonRepositoryTest extends TestCase {

	private array $stored_meta = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->stored_meta = array();

		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'wp_generate_uuid4' )->justReturn( 'test-uuid-0000' );

		Functions\when( 'get_post_meta' )->alias(
			function ( $post_id, $key, $single ) {
				if ( '_product_addons' === $key ) {
					return $this->stored_meta[ $post_id ] ?? array();
				}
				return '';
			}
		);
		Functions\when( 'update_post_meta' )->alias(
			function ( $post_id, $key, $value ) {
				if ( '_product_addons' === $key ) {
					$this->stored_meta[ $post_id ] = $value;
					return true;
				}
				return false;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function repo(): Lafka_Addon_Repository {
		// v8.13.0 ships no built-in migrations; the upgrader is empty.
		return new Lafka_Addon_Repository( new Lafka_Addons_Upgrader() );
	}

	public function test_get_groups_returns_empty_array_for_post_with_no_meta(): void {
		$groups = $this->repo()->get_groups( 999 );
		self::assertSame( array(), $groups );
	}

	public function test_save_groups_persists_canonical_shape(): void {
		$group = Lafka_Addon_Group::from_array( array(
			'name'         => 'Toppings',
			'pricing_mode' => Lafka_Addon_Schema::PRICING_FLAT_GROUP,
			'options'      => array( array( 'label' => 'Cheese', 'price' => '1.00' ) ),
		) );
		$repo = $this->repo();
		$repo->save_groups( 42, array( $group ) );

		self::assertArrayHasKey( 42, $this->stored_meta );
		self::assertCount( 1, $this->stored_meta[42] );
		self::assertSame( 'Toppings', $this->stored_meta[42][0]['name'] );
		self::assertSame( 'flat_group', $this->stored_meta[42][0]['pricing_mode'] );
		self::assertSame( 2, $this->stored_meta[42][0]['schema_version'] );
	}

	public function test_get_groups_normalizes_legacy_shape_via_schema_defaults(): void {
		// Even without built-in migrations, raw-shape data round-trips
		// through Lafka_Addon_Group::from_array which fills v2 fields from
		// schema defaults — fresh groups land at flat_per_option mode.
		$this->stored_meta[7] = array(
			array(
				'name'    => 'Old Group',
				'options' => array( array( 'label' => 'X', 'price' => '1' ) ),
			),
		);
		$groups = $this->repo()->get_groups( 7 );

		self::assertCount( 1, $groups );
		self::assertSame( 'Old Group', $groups[0]->name );
		self::assertSame( Lafka_Addon_Schema::PRICING_FLAT_PER_OPTION, $groups[0]->pricing_mode );
		self::assertSame( 2, $groups[0]->schema_version );
	}

	public function test_round_trip_save_then_load(): void {
		$original = Lafka_Addon_Group::from_array( array(
			'name'                     => 'Premium Toppings',
			'pricing_mode'             => Lafka_Addon_Schema::PRICING_FLAT_PER_SIZE,
			'variations'               => 1,
			'attribute'                => 1,
			'options_source'           => Lafka_Addon_Schema::SOURCE_ATTRIBUTE,
			'options_source_attribute' => 'pa_premium_toppings',
			'group_size_prices'        => array( 'small' => '0.50', 'medium' => '1.00' ),
			'included_size_slugs'      => array( 'small', 'medium' ),
			'options'                  => array(
				array( 'id' => 'opt-1', 'label' => 'Cheese', 'price' => '', 'included' => true ),
			),
		) );
		$repo = $this->repo();
		$repo->save_groups( 100, array( $original ) );

		$loaded = $repo->get_groups( 100 );

		self::assertCount( 1, $loaded );
		self::assertSame( 'Premium Toppings', $loaded[0]->name );
		self::assertSame( Lafka_Addon_Schema::PRICING_FLAT_PER_SIZE, $loaded[0]->pricing_mode );
		self::assertSame( 'pa_premium_toppings', $loaded[0]->options_source_attribute );
		self::assertSame( array( 'small' => '0.50', 'medium' => '1.00' ), $loaded[0]->group_size_prices );
		self::assertSame( array( 'small', 'medium' ), $loaded[0]->included_size_slugs );
		self::assertCount( 1, $loaded[0]->options );
		self::assertSame( 'opt-1', $loaded[0]->options[0]->id );
	}
}
