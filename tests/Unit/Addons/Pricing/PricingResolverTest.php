<?php
declare(strict_types=1);
namespace LafkaPlugin\Tests\Unit\Addons\Pricing;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Lafka_Addon_Group;
use Lafka_Addon_Schema;
use Lafka_Flat_Group_Pricing;
use Lafka_Flat_Per_Option_Pricing;
use Lafka_Flat_Per_Size_Pricing;
use Lafka_Matrix_Pricing;
use Lafka_Pricing_Resolver;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 4 ) . '/incl/addons/engine/lafka-addons-engine-bootstrap.php';

final class PricingResolverTest extends TestCase {

	private Lafka_Pricing_Resolver $resolver;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'wp_generate_uuid4' )->justReturn( 'test-uuid-0000' );
		// Resolver calls apply_filters in its constructor — return the strategies arg unchanged.
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$this->resolver = new Lafka_Pricing_Resolver();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_returns_flat_group_for_flat_group_mode(): void {
		$group = Lafka_Addon_Group::from_array( array(
			'name'         => 'G',
			'pricing_mode' => Lafka_Addon_Schema::PRICING_FLAT_GROUP,
		) );
		$strategy = $this->resolver->for_group( $group );
		self::assertInstanceOf( Lafka_Flat_Group_Pricing::class, $strategy );
	}

	public function test_returns_flat_per_option_for_flat_per_option_mode(): void {
		$group = Lafka_Addon_Group::from_array( array(
			'name'         => 'G',
			'pricing_mode' => Lafka_Addon_Schema::PRICING_FLAT_PER_OPTION,
		) );
		self::assertInstanceOf( Lafka_Flat_Per_Option_Pricing::class, $this->resolver->for_group( $group ) );
	}

	public function test_returns_flat_per_size_for_flat_per_size_mode(): void {
		$group = Lafka_Addon_Group::from_array( array(
			'name'         => 'G',
			'pricing_mode' => Lafka_Addon_Schema::PRICING_FLAT_PER_SIZE,
		) );
		self::assertInstanceOf( Lafka_Flat_Per_Size_Pricing::class, $this->resolver->for_group( $group ) );
	}

	public function test_returns_matrix_for_matrix_mode(): void {
		$group = Lafka_Addon_Group::from_array( array(
			'name'         => 'G',
			'pricing_mode' => Lafka_Addon_Schema::PRICING_MATRIX,
		) );
		self::assertInstanceOf( Lafka_Matrix_Pricing::class, $this->resolver->for_group( $group ) );
	}

	public function test_unknown_mode_falls_back_to_flat_per_option(): void {
		// v8.13.0 dropped the legacy strategy. Unknown modes fall back to
		// the canonical default (flat_per_option), which is also the schema
		// default for fresh groups.
		$group = Lafka_Addon_Group::from_array( array(
			'name'         => 'G',
			'pricing_mode' => 'something_unknown',
		) );
		$strategy = $this->resolver->for_group( $group );
		self::assertSame( Lafka_Addon_Schema::PRICING_FLAT_PER_OPTION, $strategy->id() );
	}

	public function test_register_filter_allows_third_party_strategies(): void {
		$resolver = new Lafka_Pricing_Resolver();
		$strategies = $resolver->all_strategies();

		self::assertCount( 4, $strategies );
		self::assertArrayHasKey( Lafka_Addon_Schema::PRICING_FLAT_GROUP, $strategies );
		self::assertArrayHasKey( Lafka_Addon_Schema::PRICING_FLAT_PER_OPTION, $strategies );
		self::assertArrayHasKey( Lafka_Addon_Schema::PRICING_FLAT_PER_SIZE, $strategies );
		self::assertArrayHasKey( Lafka_Addon_Schema::PRICING_MATRIX, $strategies );
	}
}
