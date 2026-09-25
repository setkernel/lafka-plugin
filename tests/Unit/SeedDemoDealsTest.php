<?php
/**
 * GX4: `wp lafka seed-demo` ships a Deals category so the counter layout's
 * deals section, co-stars and "serves" line have data on the demo store:
 *
 *   - "Deals" (slug in the theme's default deal slugs) is first in WooCommerce
 *     category order; Pizzas and Sides follow (the automatic co-stars);
 *   - three simple combos with numeric prices, one "serves 2", one featured;
 *   - the seeder writes the order as WooCommerce's `order` term meta and the
 *     serves value as `_lafka_serves` (cleared on re-seed when unset).
 *
 * @package Lafka\Plugin\Tests\Unit
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Lafka_CLI_Seed_Demo;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/incl/cli/class-lafka-cli-seed-demo.php';

final class SeedDemoDealsTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg( 1 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/** @return list<array> */
	private static function deals(): array {
		return array_values(
			array_filter( Lafka_CLI_Seed_Demo::fixtures()['products'], static fn( $p ) => 'deals' === $p['category'] )
		);
	}

	public function test_deals_come_first_in_category_order_then_the_co_stars(): void {
		$slugs = Lafka_CLI_Seed_Demo::categories_in_menu_order( Lafka_CLI_Seed_Demo::fixtures()['categories'] );

		self::assertSame( array( 'deals', 'pizzas', 'sides' ), array_slice( $slugs, 0, 3 ) );
		self::assertCount( count( Lafka_CLI_Seed_Demo::fixtures()['categories'] ), array_unique( array_map( static fn( $c ) => $c['order'], Lafka_CLI_Seed_Demo::fixtures()['categories'] ) ), 'Every category has its own position.' );
	}

	public function test_three_simple_combos_one_for_two_one_featured(): void {
		$deals = self::deals();

		self::assertCount( 3, $deals );
		foreach ( $deals as $deal ) {
			self::assertSame( 'simple', $deal['type'] );
			self::assertIsNumeric( $deal['price'] );
			self::assertNotEmpty( $deal['short_description'] );
		}
		self::assertSame( array( 2 ), array_values( array_filter( array_map( static fn( $d ) => $d['serves'] ?? 0, $deals ) ) ) );
		self::assertCount( 1, array_filter( $deals, static fn( $d ) => ! empty( $d['featured'] ) ) );
	}

	public function test_serves_is_written_when_set_and_cleared_when_not(): void {
		$deals   = self::deals();
		$for_two = array_values( array_filter( $deals, static fn( $d ) => 2 === ( $d['serves'] ?? 0 ) ) )[0];
		$other   = array_values( array_filter( $deals, static fn( $d ) => empty( $d['serves'] ) ) )[0];

		self::assertSame( array( '_lafka_serves' => 2 ), Lafka_CLI_Seed_Demo::product_meta( $for_two ) );
		self::assertSame( array( '_lafka_serves' => null ), Lafka_CLI_Seed_Demo::product_meta( $other ), 'A re-seed clears a stale value.' );
	}
}
