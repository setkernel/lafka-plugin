<?php
declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/incl/menu/class-lafka-mobile-grouped-walker.php';

/**
 * P6-UX-6 W3-T10: regression lock for LafkaMobileGroupedWalker.
 *
 * resolve_group and item_to_slug are protected; we access them via a small
 * anonymous test-subclass so no reflection is needed (avoids the PHP 8.5
 * deprecation of ReflectionMethod::setAccessible()).
 */
final class MobileGroupedWalkerTest extends TestCase {

	/** @var object Anonymous subclass that exposes protected helpers */
	private $walker;

	protected function setUp(): void {
		// Anonymous subclass that:
		//   1. Overrides __construct to bypass apply_filters (not available without WP),
		//      loading groups directly from default_groups() instead.
		//   2. Promotes the two protected methods to public so tests can call them.
		$this->walker = new class() extends \LafkaMobileGroupedWalker {
			public function __construct() {
				// Bypass parent constructor's apply_filters call; use defaults directly.
				// In production, the real constructor filters via lafka_mobile_menu_groups.
				// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase
				$this->groups = \LafkaMobileGroupedWalker::default_groups();
			}
			public function resolve_group_pub( string $slug ): string {
				return $this->resolve_group( $slug );
			}
			public function item_to_slug_pub( object $item ): string {
				return $this->item_to_slug( $item );
			}
		};
	}

	public function test_class_exists(): void {
		$this->assertTrue( class_exists( 'LafkaMobileGroupedWalker' ) );
	}

	public function test_default_groups_have_six_clusters(): void {
		$groups   = \LafkaMobileGroupedWalker::default_groups();
		$expected = array( 'Pizzas', 'Mains', 'Sides', 'Combos & Kids', 'Desserts', 'Drinks' );
		$this->assertEquals( $expected, array_keys( $groups ) );
	}

	public function test_pizza_slugs_resolve_to_pizzas(): void {
		foreach ( array( 'pizza', 'pizzas', 'speciality-pizzas', 'new-york-style-pies', 'vegan-pizzas' ) as $slug ) {
			$this->assertEquals( 'Pizzas', $this->walker->resolve_group_pub( $slug ), "slug=$slug" );
		}
	}

	public function test_drinks_slugs_resolve_to_drinks(): void {
		foreach ( array( 'beer-and-coolers', 'soft-drinks', 'wine', 'beer', 'coolers' ) as $slug ) {
			$this->assertEquals( 'Drinks', $this->walker->resolve_group_pub( $slug ), "slug=$slug" );
		}
	}

	public function test_unknown_slug_falls_through_to_everything_else(): void {
		$this->assertEquals( 'Everything else', $this->walker->resolve_group_pub( 'some-random-thing-789' ) );
	}

	public function test_groups_filter_can_override(): void {
		// Verify the filter hook is invoked in the constructor — source-grep approach
		// since we can't call add_filter without a full WP bootstrap.
		$src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/menu/class-lafka-mobile-grouped-walker.php' );
		$this->assertStringContainsString( "apply_filters( 'lafka_mobile_menu_groups'", $src );
	}

	public function test_walker_only_activates_when_toggle_yes(): void {
		$src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/menu/class-lafka-mobile-grouped-walker.php' );
		$this->assertStringContainsString( "get_theme_mod( 'lafka_mobile_menu_grouping', 'no' )", $src );
	}

	public function test_main_plugin_requires_module(): void {
		$main = file_get_contents( dirname( __DIR__, 2 ) . '/lafka-plugin.php' );
		$this->assertStringContainsString( 'class-lafka-mobile-grouped-walker.php', $main );
	}

	public function test_resolve_group_and_item_to_slug_are_protected(): void {
		$r         = new \ReflectionClass( 'LafkaMobileGroupedWalker' );
		$resolve   = $r->getMethod( 'resolve_group' );
		$item_slug = $r->getMethod( 'item_to_slug' );
		// Must be protected so sort filter can call them on a plain instance.
		$this->assertTrue( $resolve->isProtected(), 'resolve_group should be protected' );
		$this->assertTrue( $item_slug->isProtected(), 'item_to_slug should be protected' );
	}
}
