<?php
/**
 * Phase 2: behavioral tests for Lafka_Engine_Editor's save path.
 *
 * Locks the editor's POST → Lafka_Addon_Group[] → expand → repository
 * pipeline. Each pricing mode round-trips a representative POST shape
 * and asserts the stored option-prices match what the strategy expanded
 * (so downstream cart/display reads see canonical shape regardless of
 * which mode the operator picked).
 *
 * @package Lafka\Plugin\Tests\Unit
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit\Addons;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Lafka_Addon_Group;
use Lafka_Addon_Repository;
use Lafka_Addon_Schema;
use Lafka_Addons_Engine;
use Lafka_Addons_Upgrader;
use Lafka_Engine_Editor;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

require_once dirname( __DIR__, 3 ) . '/incl/addons/engine/lafka-addons-engine-bootstrap.php';

final class EngineEditorSaveTest extends TestCase {

	private array $stored_meta = array();
	/** @var array<int, array<string, mixed>> */
	private array $stored_post_meta = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->stored_meta      = array();
		$this->stored_post_meta = array();

		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'wp_generate_uuid4' )->justReturn( 'test-uuid-0000' );
		Functions\when( 'sanitize_text_field' )->returnArg( 1 );
		Functions\when( 'sanitize_title' )->alias( static fn( $v ) => strtolower( (string) $v ) );
		Functions\when( 'sanitize_key' )->alias( static fn( $v ) => preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) $v ) ) );
		Functions\when( 'wp_kses_post' )->returnArg( 1 );
		Functions\when( 'wc_format_decimal' )->alias( static fn( $v ) => (string) $v );
		Functions\when( 'wc_attribute_taxonomy_name_by_id' )->alias(
			static fn( $id ) => 1 === (int) $id ? 'pa_size' : ''
		);
		Functions\when( 'apply_filters' )->returnArg( 2 );

		Functions\when( 'get_post_meta' )->alias(
			function ( $post_id, $key, $single ) {
				if ( '_product_addons' === $key ) {
					return $this->stored_meta[ $post_id ] ?? array();
				}
				return $this->stored_post_meta[ $post_id ][ $key ] ?? '';
			}
		);
		Functions\when( 'update_post_meta' )->alias(
			function ( $post_id, $key, $value ) {
				if ( '_product_addons' === $key ) {
					$this->stored_meta[ $post_id ] = $value;
					return true;
				}
				$this->stored_post_meta[ $post_id ][ $key ] = $value;
				return true;
			}
		);

		// Reset the singleton so each test gets a fresh repository wired to our mock.
		$this->reset_engine_singleton();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		$this->reset_engine_singleton();
		parent::tearDown();
	}

	private function reset_engine_singleton(): void {
		$ref      = new ReflectionClass( Lafka_Addons_Engine::class );
		$instance = $ref->getProperty( 'instance' );
		$instance->setValue( null, null );
	}

	private function call_parse_groups( array $post_data ): array {
		$editor = new Lafka_Engine_Editor();
		$method = new ReflectionMethod( $editor, 'parse_groups' );
		return $method->invoke( $editor, $post_data );
	}

	private function call_expand_groups( array $groups ): array {
		$editor = new Lafka_Engine_Editor();
		$method = new ReflectionMethod( $editor, 'expand_groups' );
		return $method->invoke( $editor, $groups );
	}

	public function test_parse_groups_handles_flat_per_option_mode(): void {
		$post_data = array(
			'lafka_addon_groups' => array(
				array(
					'name'           => 'Toppings',
					'type'           => 'checkbox',
					'pricing_mode'   => Lafka_Addon_Schema::PRICING_FLAT_PER_OPTION,
					'options_source' => Lafka_Addon_Schema::SOURCE_MANUAL,
					'options'        => array(
						array( 'id' => 'opt-1', 'label' => 'Cheese', 'price' => '1.00', 'included' => '1' ),
						array( 'id' => 'opt-2', 'label' => 'Mushroom', 'price' => '0.50', 'included' => '1' ),
					),
				),
			),
		);
		$groups = $this->call_parse_groups( $post_data );

		self::assertCount( 1, $groups );
		self::assertSame( 'Toppings', $groups[0]->name );
		self::assertSame( Lafka_Addon_Schema::PRICING_FLAT_PER_OPTION, $groups[0]->pricing_mode );
		self::assertCount( 2, $groups[0]->options );
		self::assertSame( '1.00', $groups[0]->options[0]->price );
		self::assertSame( '0.50', $groups[0]->options[1]->price );
	}

	public function test_expand_groups_applies_flat_group_strategy_to_every_option(): void {
		$group = Lafka_Addon_Group::from_array( array(
			'name'             => 'Toppings',
			'pricing_mode'     => Lafka_Addon_Schema::PRICING_FLAT_GROUP,
			'group_flat_price' => '1.50',
			'options'          => array(
				array( 'id' => 'opt-1', 'label' => 'Cheese', 'price' => '' ),
				array( 'id' => 'opt-2', 'label' => 'Mushroom', 'price' => '' ),
			),
		) );

		$expanded = $this->call_expand_groups( array( $group ) );

		self::assertSame( '1.50', $expanded[0]->options[0]->price );
		self::assertSame( '1.50', $expanded[0]->options[1]->price );
	}

	public function test_expand_groups_applies_flat_per_size_to_every_option_as_matrix(): void {
		$group = Lafka_Addon_Group::from_array( array(
			'name'              => 'Toppings',
			'pricing_mode'      => Lafka_Addon_Schema::PRICING_FLAT_PER_SIZE,
			'variations'        => 1,
			'attribute'         => 1,
			'group_size_prices' => array( 'small' => '0.50', 'medium' => '1.00' ),
			'options'           => array(
				array( 'id' => 'opt-1', 'label' => 'Cheese', 'price' => '' ),
			),
		) );

		$expanded = $this->call_expand_groups( array( $group ) );
		$expected = array( 'pa_size' => array( 'small' => '0.50', 'medium' => '1.00' ) );

		self::assertSame( $expected, $expanded[0]->options[0]->price );
	}

	public function test_repository_round_trip_preserves_v2_fields(): void {
		$repo = Lafka_Addons_Engine::instance()->repository();
		$group = Lafka_Addon_Group::from_array( array(
			'name'                     => 'Premium',
			'pricing_mode'             => Lafka_Addon_Schema::PRICING_FLAT_PER_SIZE,
			'options_source'           => Lafka_Addon_Schema::SOURCE_ATTRIBUTE,
			'options_source_attribute' => 'pa_premium_toppings',
			'group_size_prices'        => array( 'medium' => '1.00' ),
			'included_size_slugs'      => array( 'medium', 'large' ),
			'options'                  => array( array( 'id' => 'opt-1', 'label' => 'Cheese' ) ),
		) );
		$repo->save_groups( 100, array( $group ) );

		$loaded = $repo->get_groups( 100 );

		self::assertCount( 1, $loaded );
		self::assertSame( 'Premium', $loaded[0]->name );
		self::assertSame( Lafka_Addon_Schema::PRICING_FLAT_PER_SIZE, $loaded[0]->pricing_mode );
		self::assertSame( Lafka_Addon_Schema::SOURCE_ATTRIBUTE, $loaded[0]->options_source );
		self::assertSame( 'pa_premium_toppings', $loaded[0]->options_source_attribute );
		self::assertSame( array( 'medium' => '1.00' ), $loaded[0]->group_size_prices );
		self::assertSame( array( 'medium', 'large' ), $loaded[0]->included_size_slugs );
	}

	public function test_parse_one_group_strips_unknown_pricing_mode(): void {
		// Operator submits an unrecognized pricing_mode (URL tampering, stale form);
		// editor falls back to flat_per_option rather than persisting nonsense.
		$post_data = array(
			'lafka_addon_groups' => array(
				array(
					'name'         => 'G',
					'pricing_mode' => 'something_unknown',
					'options'      => array( array( 'id' => 'x', 'label' => 'X', 'price' => '1.00' ) ),
				),
			),
		);
		$groups = $this->call_parse_groups( $post_data );

		self::assertSame( Lafka_Addon_Schema::PRICING_FLAT_PER_OPTION, $groups[0]->pricing_mode );
	}

	public function test_parse_one_group_normalizes_included_size_slugs(): void {
		$post_data = array(
			'lafka_addon_groups' => array(
				array(
					'name'                => 'G',
					'pricing_mode'        => Lafka_Addon_Schema::PRICING_FLAT_PER_SIZE,
					'included_size_slugs' => array( 'Small', 'Medium', 'Large' ),
					'group_size_prices'   => array( 'small' => '0.50', 'medium' => '1.00' ),
					'options'             => array( array( 'id' => 'x', 'label' => 'X' ) ),
				),
			),
		);
		$groups = $this->call_parse_groups( $post_data );

		self::assertSame( array( 'small', 'medium', 'large' ), $groups[0]->included_size_slugs );
	}
}
