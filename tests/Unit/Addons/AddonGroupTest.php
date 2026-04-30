<?php
declare(strict_types=1);
namespace LafkaPlugin\Tests\Unit\Addons;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Lafka_Addon_Group;
use Lafka_Addon_Option;
use Lafka_Addon_Schema;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 3 ) . '/incl/addons/engine/lafka-addons-engine-bootstrap.php';

final class AddonGroupTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// Brain Monkey + Patchwork keep wp_generate_uuid4 around once any
		// earlier test stubbed it; provide an expectation here for strict mode.
		Functions\when( 'wp_generate_uuid4' )->justReturn( 'test-uuid-0000' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_from_array_normalizes_and_constructs_options(): void {
		$group = Lafka_Addon_Group::from_array( array(
			'name'         => 'Toppings',
			'pricing_mode' => Lafka_Addon_Schema::PRICING_FLAT_GROUP,
			'options'      => array(
				array( 'label' => 'Cheese', 'price' => '1.00' ),
				array( 'label' => 'Mushroom', 'price' => '0.50' ),
			),
		) );

		self::assertSame( 'Toppings', $group->name );
		self::assertSame( Lafka_Addon_Schema::PRICING_FLAT_GROUP, $group->pricing_mode );
		self::assertCount( 2, $group->options );
		self::assertContainsOnlyInstancesOf( Lafka_Addon_Option::class, $group->options );
		self::assertSame( 'Cheese', $group->options[0]->label );
	}

	public function test_default_pricing_mode_is_flat_per_option_when_unset(): void {
		$group = Lafka_Addon_Group::from_array( array(
			'name'    => 'Fresh Group',
			'options' => array( array( 'label' => 'X', 'price' => '1.00' ) ),
		) );

		self::assertSame( Lafka_Addon_Schema::PRICING_FLAT_PER_OPTION, $group->pricing_mode );
		self::assertSame( Lafka_Addon_Schema::SOURCE_MANUAL, $group->options_source );
	}

	public function test_to_array_round_trips_options(): void {
		$original = Lafka_Addon_Group::from_array( array(
			'name'    => 'Group',
			'options' => array( array( 'id' => 'opt-1', 'label' => 'A', 'price' => '1.00' ) ),
		) );
		$array = $original->to_array();

		self::assertSame( 'Group', $array['name'] );
		self::assertCount( 1, $array['options'] );
		self::assertSame( 'opt-1', $array['options'][0]['id'] );
	}

	public function test_with_options_returns_new_instance(): void {
		$original = Lafka_Addon_Group::from_array( array( 'name' => 'G', 'options' => array() ) );
		$new_options = array(
			Lafka_Addon_Option::from_array( array( 'label' => 'New' ) ),
		);
		$updated = $original->with_options( $new_options );

		self::assertCount( 0, $original->options );
		self::assertCount( 1, $updated->options );
		self::assertNotSame( $original, $updated );
	}

	public function test_uses_per_attribute_pricing_when_variations_set(): void {
		$with_variations = Lafka_Addon_Group::from_array( array(
			'name'       => 'G',
			'variations' => 1,
			'attribute'  => 5,
		) );
		$without_variations = Lafka_Addon_Group::from_array( array( 'name' => 'G' ) );

		self::assertTrue( $with_variations->uses_per_attribute_pricing() );
		self::assertFalse( $without_variations->uses_per_attribute_pricing() );
	}

	public function test_size_terms_included_when_empty_means_all(): void {
		$group = Lafka_Addon_Group::from_array( array(
			'name'                => 'G',
			'included_size_slugs' => array(),
		) );

		self::assertTrue( $group->includes_size( 'small' ) );
		self::assertTrue( $group->includes_size( 'medium' ) );
	}

	public function test_size_terms_excluded_when_subset(): void {
		$group = Lafka_Addon_Group::from_array( array(
			'name'                => 'G',
			'included_size_slugs' => array( 'medium', 'large' ),
		) );

		self::assertFalse( $group->includes_size( 'small' ) );
		self::assertTrue( $group->includes_size( 'medium' ) );
		self::assertTrue( $group->includes_size( 'large' ) );
	}
}
