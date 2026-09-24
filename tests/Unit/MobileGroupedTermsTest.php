<?php
declare(strict_types=1);

/**
 * LafkaMobileGroupedWalker::group_terms() — the mobile drawer's category
 * grouping: heuristic buckets in declared order, an "Everything else" tail,
 * no empty groups, and operator reshaping through lafka_mobile_menu_groups.
 *
 * @package Lafka\Plugin\Tests\Unit
 */

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use LafkaMobileGroupedWalker;
use PHPUnit\Framework\TestCase;

final class MobileGroupedTermsTest extends TestCase {

	/** @var array<int,callable> Filter callbacks for lafka_mobile_menu_groups. */
	private array $group_filters = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->group_filters = array();

		Functions\when( 'apply_filters' )->alias(
			function ( $hook, $value = null, ...$rest ) {
				if ( 'lafka_mobile_menu_groups' === $hook ) {
					foreach ( $this->group_filters as $cb ) {
						$value = $cb( $value, ...$rest );
					}
				}
				return $value;
			}
		);

		if ( ! class_exists( LafkaMobileGroupedWalker::class, false ) ) {
			require_once dirname( __DIR__, 2 ) . '/incl/menu/class-lafka-mobile-grouped-walker.php';
		}
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @param string $slug Term slug.
	 * @return object Term-like stub.
	 */
	private function term( string $slug ): object {
		return (object) array(
			'slug' => $slug,
			'name' => ucfirst( $slug ),
		);
	}

	public function test_groups_follow_the_heuristic_and_declared_order(): void {
		$grouped = LafkaMobileGroupedWalker::group_terms(
			array(
				$this->term( 'beer-and-coolers' ),
				$this->term( 'speciality-pizzas' ),
				$this->term( 'poutine' ),
				$this->term( 'donair' ),
			)
		);

		$this->assertSame(
			array( 'Pizzas', 'Mains', 'Sides', 'Drinks' ),
			array_keys( $grouped ),
			'Buckets must follow the declared group order, not input order.'
		);
		$this->assertSame( 'speciality-pizzas', $grouped['Pizzas'][0]->slug );
		$this->assertSame( 'donair', $grouped['Mains'][0]->slug );
	}

	public function test_unmatched_slugs_land_in_everything_else_last(): void {
		$grouped = LafkaMobileGroupedWalker::group_terms(
			array(
				$this->term( 'weekly-mystery-box' ),
				$this->term( 'pizza' ),
			)
		);

		$keys = array_keys( $grouped );
		$this->assertSame( 'Everything else', end( $keys ), 'Unmatched terms must trail — nothing may disappear.' );
		$this->assertCount( 1, $grouped['Everything else'] );
	}

	public function test_empty_groups_are_omitted(): void {
		$grouped = LafkaMobileGroupedWalker::group_terms( array( $this->term( 'pizza' ) ) );
		$this->assertSame( array( 'Pizzas' ), array_keys( $grouped ) );
	}

	public function test_operator_filter_reshapes_the_groups(): void {
		$this->group_filters[] = static function ( $groups ) {
			return array( 'Noodles' => array( 'noodles' ) );
		};

		$grouped = LafkaMobileGroupedWalker::group_terms(
			array(
				$this->term( 'noodles' ),
				$this->term( 'pizza' ),
			)
		);

		$this->assertSame( array( 'Noodles', 'Everything else' ), array_keys( $grouped ) );
		$this->assertSame( 'pizza', $grouped['Everything else'][0]->slug, 'With Pizzas filtered away, pizza falls through.' );
	}
}
