<?php
/**
 * With a branch chosen, product listings are narrowed to that branch's
 * products — by the branch TERM id the session stores (term_taxonomy_id can
 * differ from it, which silently matched another term or nothing).
 *
 * @package Lafka_Plugin
 */

declare(strict_types=1);

namespace Lafka\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Lafka_Branch_Locations;
use PHPUnit\Framework\TestCase;

final class BranchProductFilterTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'get_option' )->justReturn( array() );
		if ( ! class_exists( 'Lafka_Branch_Locations', false ) ) {
			require_once dirname( __DIR__, 2 ) . '/incl/branches/class-lafka-branch-locations.php';
		}
		$session = new class() {
			public function get( $key ) {
				return 'lafka_branch_location' === $key ? array( 'branch_id' => '7' ) : null;
			}
		};
		Functions\when( 'WC' )->justReturn( (object) array( 'session' => $session ) );
		// Branch term 7 lives at term_taxonomy_id 42.
		Functions\when( 'get_term' )->justReturn(
			(object) array(
				'term_id'          => 7,
				'term_taxonomy_id' => 42,
				'taxonomy'         => 'lafka_branch_location',
			)
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_shop_tax_query_filters_by_the_branch_term_id(): void {
		$tax_query = Lafka_Branch_Locations::modify_products_tax_query_to_get_branch_products( array(), null );

		$this->assertSame(
			array(
				'taxonomy' => 'lafka_branch_location',
				'field'    => 'term_id',
				'terms'    => 7,
			),
			$tax_query[0]
		);
	}

	public function test_widget_tax_query_filters_by_the_branch_term_id(): void {
		$args = Lafka_Branch_Locations::modify_products_tax_query_to_get_branch_products_for_widgets( array( 'tax_query' => array() ) );

		$this->assertSame( 'term_id', $args['tax_query'][0]['field'] );
		$this->assertSame( 7, $args['tax_query'][0]['terms'] );
	}

	public function test_branch_map_data_builds_for_geocoded_branches(): void {
		// Regression: the term-meta cache priming called a WordPress function
		// that does not exist (update_term_meta_cache), fataling every shop
		// page once a branch had a geocoded address.
		Functions\when( 'get_terms' )->justReturn( array( 7 => 'Downtown' ) );
		Functions\when( 'update_termmeta_cache' )->justReturn( array() );
		Functions\when( 'get_term_meta' )->justReturn( array() );
		Functions\when( 'get_posts' )->justReturn( array() );
		if ( ! class_exists( 'Lafka_Shipping_Areas', false ) ) {
			require_once dirname( __DIR__, 2 ) . '/incl/shipping-areas/class-lafka-shipping-areas.php';
		}

		$method = ( new \ReflectionClass( Lafka_Branch_Locations::class ) )->getMethod( 'get_branch_locations_json_data' );
		$json   = $method->invoke( null );

		$this->assertIsArray( json_decode( (string) $json, true ) );
	}

	public function test_related_products_join_uses_the_term_taxonomy_id(): void {
		$previous        = $GLOBALS['wpdb'] ?? null;
		$GLOBALS['wpdb'] = (object) array( 'prefix' => 'wp_' );

		$query = Lafka_Branch_Locations::modify_related_products_query_to_get_branch_products(
			array(
				'join'  => '',
				'where' => '',
			)
		);

		$GLOBALS['wpdb'] = $previous;
		$this->assertStringEndsWith( 'term_rel.term_taxonomy_id = 42', $query['where'] );
	}
}
