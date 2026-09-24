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
use Lafka_Pricing_Strategy;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 4 ) . '/incl/addons/engine/lafka-addons-engine-bootstrap.php';

final class PricingResolverTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'wp_generate_uuid4' )->justReturn( 'test-uuid-0000' );
		Functions\when( 'apply_filters' )->returnArg( 2 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private static function group( string $mode ): Lafka_Addon_Group {
		return Lafka_Addon_Group::from_array(
			array(
				'name'         => 'G',
				'pricing_mode' => $mode,
			)
		);
	}

	public function test_each_stored_mode_resolves_to_its_strategy(): void {
		$resolver = new Lafka_Pricing_Resolver();
		$resolved = array();
		foreach ( Lafka_Addon_Schema::pricing_modes() as $mode ) {
			$resolved[ $mode ] = get_class( $resolver->for_group( self::group( $mode ) ) );
		}

		self::assertSame(
			array(
				Lafka_Addon_Schema::PRICING_FLAT_GROUP      => Lafka_Flat_Group_Pricing::class,
				Lafka_Addon_Schema::PRICING_FLAT_PER_OPTION => Lafka_Flat_Per_Option_Pricing::class,
				Lafka_Addon_Schema::PRICING_FLAT_PER_SIZE   => Lafka_Flat_Per_Size_Pricing::class,
				Lafka_Addon_Schema::PRICING_MATRIX          => Lafka_Matrix_Pricing::class,
			),
			$resolved
		);
	}

	public function test_unknown_mode_falls_back_to_flat_per_option(): void {
		$strategy = ( new Lafka_Pricing_Resolver() )->for_group( self::group( 'something_unknown' ) );
		self::assertSame( Lafka_Addon_Schema::PRICING_FLAT_PER_OPTION, $strategy->id() );
	}

	public function test_third_party_strategy_registered_via_filter_is_resolved(): void {
		$custom = new class() implements Lafka_Pricing_Strategy {
			public function id(): string {
				return 'bulk_tier';
			}
			public function label(): string {
				return 'Bulk tier';
			}
			public function expand( Lafka_Addon_Group $group ): Lafka_Addon_Group {
				return $group;
			}
			public function validate( Lafka_Addon_Group $group ): array {
				return array();
			}
		};
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $strategies ) use ( $custom ) {
				if ( 'lafka_addons_register_pricing_strategy' === $hook ) {
					$strategies['bulk_tier'] = $custom;
				}
				return $strategies;
			}
		);

		$resolver = new Lafka_Pricing_Resolver();

		self::assertSame( $custom, $resolver->for_group( self::group( 'bulk_tier' ) ) );
		self::assertInstanceOf(
			Lafka_Flat_Group_Pricing::class,
			$resolver->for_group( self::group( Lafka_Addon_Schema::PRICING_FLAT_GROUP ) ),
			'Registering a strategy must not displace the built-ins.'
		);
	}
}
