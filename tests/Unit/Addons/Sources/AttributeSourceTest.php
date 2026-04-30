<?php
declare(strict_types=1);
namespace LafkaPlugin\Tests\Unit\Addons\Sources;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Lafka_Addon_Group;
use Lafka_Addon_Option;
use Lafka_Addon_Schema;
use Lafka_Attribute_Source;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 4 ) . '/incl/addons/engine/lafka-addons-engine-bootstrap.php';

final class AttributeSourceTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'wp_generate_uuid4' )->justReturn( 'test-uuid-0000' );
		Functions\when( 'taxonomy_exists' )->alias(
			static fn( $tax ) => 'pa_premium_toppings' === $tax
		);
		Functions\when( 'is_wp_error' )->justReturn( false );
		Functions\when( 'get_terms' )->alias(
			static function ( $args ) {
				if ( ( $args['taxonomy'] ?? '' ) !== 'pa_premium_toppings' ) {
					return array();
				}
				return array(
					(object) array( 'slug' => 'cheese', 'name' => 'Cheese' ),
					(object) array( 'slug' => 'truffle', 'name' => 'Truffle' ),
					(object) array( 'slug' => 'bacon', 'name' => 'Bacon' ),
				);
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_id_and_label(): void {
		$source = new Lafka_Attribute_Source();
		self::assertSame( Lafka_Addon_Schema::SOURCE_ATTRIBUTE, $source->id() );
		self::assertNotEmpty( $source->label() );
	}

	public function test_get_options_returns_term_based_options(): void {
		$group = Lafka_Addon_Group::from_array( array(
			'name'                     => 'G',
			'options_source'           => Lafka_Addon_Schema::SOURCE_ATTRIBUTE,
			'options_source_attribute' => 'pa_premium_toppings',
			'options'                  => array(),
		) );
		$source  = new Lafka_Attribute_Source();
		$options = $source->get_options( $group );

		self::assertCount( 3, $options );
		self::assertSame( 'Cheese', $options[0]->label );
		self::assertSame( 'Truffle', $options[1]->label );
		self::assertSame( 'Bacon', $options[2]->label );
	}

	public function test_sync_preserves_existing_option_settings_by_label(): void {
		$group = Lafka_Addon_Group::from_array( array(
			'name'                     => 'G',
			'options_source'           => Lafka_Addon_Schema::SOURCE_ATTRIBUTE,
			'options_source_attribute' => 'pa_premium_toppings',
			'options'                  => array(
				array( 'label' => 'Cheese', 'price' => '1.50', 'included' => true ),
				array( 'label' => 'Truffle', 'price' => '3.00', 'included' => false ),
			),
		) );
		$source = new Lafka_Attribute_Source();
		$synced = $source->sync( $group );

		self::assertCount( 3, $synced->options );

		$cheese  = $this->find_option( $synced->options, 'Cheese' );
		$truffle = $this->find_option( $synced->options, 'Truffle' );
		$bacon   = $this->find_option( $synced->options, 'Bacon' );

		self::assertSame( '1.50', $cheese->price );
		self::assertTrue( $cheese->included );
		self::assertSame( '3.00', $truffle->price );
		self::assertFalse( $truffle->included );
		self::assertSame( '', $bacon->price );
		self::assertTrue( $bacon->included );
	}

	public function test_sync_returns_unchanged_if_attribute_unset(): void {
		$group = Lafka_Addon_Group::from_array( array(
			'name'                     => 'G',
			'options_source'           => Lafka_Addon_Schema::SOURCE_ATTRIBUTE,
			'options_source_attribute' => '',
			'options'                  => array( array( 'label' => 'A', 'price' => '1' ) ),
		) );
		$source = new Lafka_Attribute_Source();
		$synced = $source->sync( $group );

		self::assertCount( 1, $synced->options );
		self::assertSame( 'A', $synced->options[0]->label );
	}

	public function test_sync_returns_unchanged_if_taxonomy_does_not_exist(): void {
		$group = Lafka_Addon_Group::from_array( array(
			'name'                     => 'G',
			'options_source'           => Lafka_Addon_Schema::SOURCE_ATTRIBUTE,
			'options_source_attribute' => 'pa_does_not_exist',
			'options'                  => array( array( 'label' => 'A', 'price' => '1' ) ),
		) );
		$source = new Lafka_Attribute_Source();
		$synced = $source->sync( $group );

		self::assertCount( 1, $synced->options );
		self::assertSame( 'A', $synced->options[0]->label );
	}

	/** @param Lafka_Addon_Option[] $options */
	private function find_option( array $options, string $label ): ?Lafka_Addon_Option {
		foreach ( $options as $option ) {
			if ( $option->label === $label ) {
				return $option;
			}
		}
		return null;
	}
}
