<?php
/**
 * GX4 required-addons probe (incl/addons/lafka-required-addons.php): a
 * listing's quick add must not skip a choice the customer has to make, so a
 * product with any required add-on group goes to its product page ("Choose")
 * instead of a 2-tap add. Runs the real add-on engine over stored groups.
 *
 * @package Lafka\Plugin\Tests\Unit
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Lafka_Engine_Helper;
use Lafka_Engine_Resolver;
use Lafka_Required_Addons;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/incl/addons/engine/lafka-addons-engine-bootstrap.php';

final class RequiredAddonsTest extends TestCase {

	/** @var array<int, array> product id => stored `_product_addons` */
	private array $stored = array();

	private bool $module_on = true;

	/** @var array<string, callable> */
	private array $filters = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->stored    = array();
		$this->module_on = true;
		$this->filters   = array();

		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'wp_generate_uuid4' )->justReturn( 'test-uuid-0000' );
		Functions\when( 'sanitize_title' )->alias( static fn( $s ) => strtolower( preg_replace( '/[^a-z0-9-]+/i', '-', (string) $s ) ) );
		Functions\when( 'apply_filters' )->alias(
			fn( $hook, $value, ...$args ) => isset( $this->filters[ $hook ] ) ? ( $this->filters[ $hook ] )( $value, ...$args ) : $value
		);
		Functions\when( 'is_lafka_product_addons' )->alias( fn() => $this->module_on );
		Functions\when( 'wc_get_product' )->justReturn(
			new class() {
				public function get_meta( $key ) {
					return '';
				}
				public function get_attributes() {
					return array();
				}
			}
		);
		Functions\when( 'wp_get_post_parent_id' )->justReturn( 0 );
		Functions\when( 'get_posts' )->justReturn( array() );
		Functions\when( 'wc_get_object_terms' )->justReturn( array() );
		Functions\when( 'get_post_meta' )->alias( fn( $id, $key ) => '_product_addons' === $key ? ( $this->stored[ (int) $id ] ?? '' ) : '' );

		require_once dirname( __DIR__, 2 ) . '/incl/addons/lafka-required-addons.php';
		Lafka_Engine_Resolver::clear_cache();
		Lafka_Engine_Helper::clear_cache();
		Lafka_Required_Addons::flush();
	}

	protected function tearDown(): void {
		Lafka_Engine_Resolver::clear_cache();
		Lafka_Engine_Helper::clear_cache();
		Lafka_Required_Addons::flush();
		Monkey\tearDown();
		parent::tearDown();
	}

	private static function group( string $name, int $required, bool $included = true ): array {
		return array(
			'name'     => $name,
			'type'     => 'radiobutton',
			'required' => $required,
			'options'  => array(
				array( 'id' => sanitize_title( $name ) . '-a', 'label' => 'A', 'price' => '', 'included' => $included ),
			),
		);
	}

	public function test_a_required_group_means_the_customer_must_choose(): void {
		$this->stored[7] = array( self::group( 'Toppings', 0 ), self::group( 'Pick your pizzas', 1 ) );

		self::assertTrue( lafka_product_has_required_addons( 7 ) );
	}

	public function test_optional_groups_alone_allow_a_quick_add(): void {
		$this->stored[7] = array( self::group( 'Premium toppings', 0 ), self::group( 'Regular toppings', 0 ) );

		self::assertFalse( lafka_product_has_required_addons( 7 ) );
	}

	public function test_a_required_group_with_nothing_on_offer_is_not_shown_so_it_does_not_count(): void {
		$this->stored[7] = array( self::group( 'Sauce', 1, false ) );

		self::assertFalse( lafka_product_has_required_addons( 7 ) );
	}

	public function test_no_addons_or_no_product_means_no_required_choice(): void {
		self::assertFalse( lafka_product_has_required_addons( 7 ) );
		self::assertFalse( lafka_product_has_required_addons( 0 ) );
	}

	public function test_add_ons_module_off_means_nothing_is_required(): void {
		$this->module_on = false;
		$this->stored[7] = array( self::group( 'Pick your pizzas', 1 ) );

		self::assertFalse( lafka_product_has_required_addons( 7 ) );
	}

	public function test_the_answer_is_filterable_per_product(): void {
		$this->stored[7] = array( self::group( 'Toppings', 0 ) );
		$this->filters['lafka_product_has_required_addons'] = static fn( $has, $id ) => 7 === $id ? true : $has;

		self::assertTrue( lafka_product_has_required_addons( 7 ) );
	}

	public function test_the_answer_is_cached_for_the_request(): void {
		$this->stored[7] = array( self::group( 'Pick your pizzas', 1 ) );
		self::assertTrue( lafka_product_has_required_addons( 7 ) );

		$this->stored[7] = array();
		Lafka_Engine_Helper::clear_cache();
		self::assertTrue( lafka_product_has_required_addons( 7 ), 'Listing pages probe many rows; one resolve per product per request.' );

		Lafka_Required_Addons::flush();
		Lafka_Engine_Resolver::clear_cache();
		self::assertFalse( lafka_product_has_required_addons( 7 ) );
	}
}
