<?php
/**
 * Phase 3: behavioral tests for Lafka_Engine_Product_Panel.
 *
 * Verifies the per-product panel save handler:
 *   - Bails on autosave / wrong post type / missing capability / missing marker
 *   - Persists groups via the repository (same shape as global editor)
 *   - Honors the exclude-global checkbox
 *
 * @package Lafka\Plugin\Tests\Unit
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit\Addons;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Lafka_Addon_Group;
use Lafka_Addon_Schema;
use Lafka_Addons_Engine;
use Lafka_Engine_Editor;
use Lafka_Engine_Product_Panel;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

require_once dirname( __DIR__, 3 ) . '/incl/addons/engine/lafka-addons-engine-bootstrap.php';

final class EngineProductPanelTest extends TestCase {

	private array $stored_meta = array();
	/** @var array<int, array<string, mixed>> */
	private array $stored_post_meta = array();
	private array $post_types = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->stored_meta      = array();
		$this->stored_post_meta = array();
		$this->post_types       = array();

		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'wp_generate_uuid4' )->justReturn( 'test-uuid-0000' );
		Functions\when( 'wp_unslash' )->returnArg( 1 );
		Functions\when( 'sanitize_text_field' )->returnArg( 1 );
		Functions\when( 'sanitize_title' )->alias( static fn( $v ) => strtolower( (string) $v ) );
		Functions\when( 'sanitize_key' )->alias( static fn( $v ) => preg_replace( '/[^a-z0-9_]/', '', strtolower( (string) $v ) ) );
		Functions\when( 'wp_kses_post' )->returnArg( 1 );
		Functions\when( 'wc_format_decimal' )->alias( static fn( $v ) => (string) $v );
		Functions\when( 'wc_attribute_taxonomy_name_by_id' )->alias(
			static fn( $id ) => 1 === (int) $id ? 'pa_size' : ''
		);
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'wp_is_post_revision' )->justReturn( false );
		Functions\when( 'wp_is_post_autosave' )->justReturn( false );
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_post_type' )->alias(
			fn( $id ) => $this->post_types[ $id ] ?? 'product'
		);

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

		$this->reset_engine_singleton();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		$this->reset_engine_singleton();
		$_POST = array();
		parent::tearDown();
	}

	private function reset_engine_singleton(): void {
		$ref      = new ReflectionClass( Lafka_Addons_Engine::class );
		$instance = $ref->getProperty( 'instance' );
		$instance->setValue( null, null );
	}

	private function panel(): Lafka_Engine_Product_Panel {
		return new Lafka_Engine_Product_Panel( new Lafka_Engine_Editor() );
	}

	public function test_save_persists_groups_to_repository(): void {
		$_POST = array(
			'lafka_addon_groups' => array(
				array(
					'name'           => 'Toppings',
					'type'           => 'checkbox',
					'pricing_mode'   => Lafka_Addon_Schema::PRICING_FLAT_PER_OPTION,
					'options_source' => Lafka_Addon_Schema::SOURCE_MANUAL,
					'options'        => array(
						array( 'id' => 'opt-1', 'label' => 'Cheese', 'price' => '1.00', 'included' => '1' ),
					),
				),
			),
		);
		$this->panel()->save( 42 );

		$loaded = Lafka_Addons_Engine::instance()->repository()->get_groups( 42 );
		self::assertCount( 1, $loaded );
		self::assertSame( 'Toppings', $loaded[0]->name );
		self::assertSame( 'Cheese', $loaded[0]->options[0]->label );
		self::assertSame( '1.00', $loaded[0]->options[0]->price );
	}

	public function test_save_bails_when_addon_marker_absent(): void {
		// No lafka_addon_groups in $_POST → save should NOT touch meta.
		$_POST = array( 'some_other_field' => 'value' );
		$this->panel()->save( 42 );

		self::assertArrayNotHasKey( 42, $this->stored_meta );
	}

	public function test_save_bails_for_non_product_post_type(): void {
		$this->post_types[ 99 ] = 'page';
		$_POST = array(
			'lafka_addon_groups' => array(
				array( 'name' => 'X', 'options' => array( array( 'id' => 'a', 'label' => 'A', 'price' => '1' ) ) ),
			),
		);
		$this->panel()->save( 99 );

		self::assertArrayNotHasKey( 99, $this->stored_meta );
	}

	public function test_save_bails_when_user_lacks_capability(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		$_POST = array(
			'lafka_addon_groups' => array(
				array( 'name' => 'X', 'options' => array( array( 'id' => 'a', 'label' => 'A', 'price' => '1' ) ) ),
			),
		);
		$this->panel()->save( 42 );

		self::assertArrayNotHasKey( 42, $this->stored_meta );
	}

	public function test_save_persists_exclude_global_flag(): void {
		$_POST = array(
			'lafka_addon_groups'         => array(),
			'lafka_addons_exclude_global' => '1',
		);
		$this->panel()->save( 42 );

		self::assertSame( 1, $this->stored_post_meta[42]['_product_addons_exclude_global'] );
	}

	public function test_save_clears_exclude_global_when_unchecked(): void {
		$_POST = array(
			'lafka_addon_groups' => array(),
			// Checkbox unchecked → key absent from POST.
		);
		$this->panel()->save( 42 );

		self::assertSame( 0, $this->stored_post_meta[42]['_product_addons_exclude_global'] );
	}

	public function test_save_expands_flat_group_pricing_to_options(): void {
		// Operator picks flat_group, enters a single $1.50 — every option
		// should land with that price after save (strategy expand pipeline).
		$_POST = array(
			'lafka_addon_groups' => array(
				array(
					'name'             => 'Premium',
					'pricing_mode'     => Lafka_Addon_Schema::PRICING_FLAT_GROUP,
					'options_source'   => Lafka_Addon_Schema::SOURCE_MANUAL,
					'group_flat_price' => '1.50',
					'options'          => array(
						array( 'id' => 'a', 'label' => 'A' ),
						array( 'id' => 'b', 'label' => 'B' ),
					),
				),
			),
		);
		$this->panel()->save( 42 );

		$loaded = Lafka_Addons_Engine::instance()->repository()->get_groups( 42 );
		self::assertSame( '1.50', $loaded[0]->options[0]->price );
		self::assertSame( '1.50', $loaded[0]->options[1]->price );
	}
}
