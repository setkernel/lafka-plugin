<?php
declare(strict_types=1);

/**
 * Locks LafkaMobileGroupedWalker::group_terms() — the path the bundled
 * lafka-theme mobile drawer actually consumes for its Categories section.
 *
 * Background (2026-07 audit): the walker's wp_nav_menu hooks gate on a
 * 'mobile' theme_location that the bundled theme stopped rendering in the
 * v5.55 header rebuild, so the whole grouped-mobile-menu feature (including
 * its live Customizer toggle) was dead code. group_terms() is the repaired
 * contract: the theme hands it the get_terms() list and renders the ordered
 * label => terms buckets.
 *
 * Brain Monkey stubs the WP functions (plugin-suite convention — defining
 * them as plain globals here would DefinedTooEarly-poison every later
 * Monkey test); the walker file is required inside setUp() so add_filter
 * exists at load time. Walker_Nav_Menu is a plain class stub.
 *
 * @package Lafka\Plugin\Tests\Unit
 */

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use LafkaMobileGroupedWalker;
use PHPUnit\Framework\TestCase;

if ( ! class_exists( 'Walker_Nav_Menu' ) ) {
	// Minimal parent so the walker class file can load; group_terms() never
	// touches these.
	class_alias( MobileGroupedWalkerParentStub::class, 'Walker_Nav_Menu' );
}

class MobileGroupedWalkerParentStub {
	public function start_lvl( &$output, $depth = 0, $args = null ) {}
	public function end_lvl( &$output, $depth = 0, $args = null ) {}
	public function start_el( &$output, $item, $depth = 0, $args = null, $id = 0 ) {}
	public function end_el( &$output, $item, $depth = 0, $args = null ) {}
}

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
