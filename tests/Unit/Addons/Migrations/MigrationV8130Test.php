<?php
declare(strict_types=1);
namespace LafkaPlugin\Tests\Unit\Addons\Migrations;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Lafka_Addon_Schema;
use Lafka_Migration_V8_13_0;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 4 ) . '/incl/addons/engine/lafka-addons-engine-bootstrap.php';

final class MigrationV8130Test extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'wp_generate_uuid4' )->justReturn( 'test-uuid-0000' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_id_and_target_version(): void {
		$migration = new Lafka_Migration_V8_13_0();
		self::assertSame( '8.13.0', $migration->id() );
		self::assertSame( 2, $migration->target_schema_version() );
	}

	public function test_migrate_legacy_meta_adds_v2_fields_with_legacy_pricing(): void {
		$legacy_meta = array(
			array(
				'name'       => 'Toppings',
				'type'       => 'checkbox',
				'variations' => 1,
				'attribute'  => 1,
				'options'    => array(
					array( 'id' => 'opt-1', 'label' => 'Cheese', 'price' => '1.00' ),
				),
			),
		);
		$migration = new Lafka_Migration_V8_13_0();
		$migrated  = $migration->migrate_meta( $legacy_meta );

		self::assertCount( 1, $migrated );
		self::assertSame( Lafka_Addon_Schema::PRICING_LEGACY, $migrated[0]['pricing_mode'] );
		self::assertSame( Lafka_Addon_Schema::SOURCE_MANUAL, $migrated[0]['options_source'] );
		self::assertSame( 2, $migrated[0]['schema_version'] );
		self::assertSame( '', $migrated[0]['group_flat_price'] );
		self::assertSame( array(), $migrated[0]['group_size_prices'] );
		self::assertSame( array(), $migrated[0]['included_size_slugs'] );
	}

	public function test_migrate_preserves_existing_v1_data(): void {
		$legacy_meta = array(
			array(
				'name'        => 'Toppings',
				'description' => 'Choose your toppings',
				'limit'       => 5,
				'required'    => 1,
				'options'     => array(
					array( 'id' => 'opt-1', 'label' => 'Cheese', 'price' => '1.00', 'min' => '0', 'max' => '3' ),
				),
			),
		);
		$migration = new Lafka_Migration_V8_13_0();
		$migrated  = $migration->migrate_meta( $legacy_meta );

		self::assertSame( 'Toppings', $migrated[0]['name'] );
		self::assertSame( 'Choose your toppings', $migrated[0]['description'] );
		self::assertSame( 5, $migrated[0]['limit'] );
		self::assertSame( 1, $migrated[0]['required'] );
		self::assertSame( '1.00', $migrated[0]['options'][0]['price'] );
	}

	public function test_migrate_is_idempotent(): void {
		$original = array(
			array(
				'name'           => 'G',
				'pricing_mode'   => Lafka_Addon_Schema::PRICING_FLAT_GROUP,
				'options_source' => Lafka_Addon_Schema::SOURCE_MANUAL,
				'schema_version' => 2,
				'options'        => array( array( 'id' => 'opt-1', 'label' => 'X', 'price' => '1' ) ),
			),
		);
		$migration = new Lafka_Migration_V8_13_0();
		$first     = $migration->migrate_meta( $original );
		$second    = $migration->migrate_meta( $first );

		self::assertSame( Lafka_Addon_Schema::PRICING_FLAT_GROUP, $second[0]['pricing_mode'] );
		self::assertSame( 2, $second[0]['schema_version'] );
		self::assertSame( $first, $second );
	}

	public function test_migrate_skips_non_array_entries(): void {
		$bad_meta = array(
			'corrupt-string',
			42,
			array( 'name' => 'Valid', 'options' => array() ),
		);
		$migration = new Lafka_Migration_V8_13_0();
		$migrated  = $migration->migrate_meta( $bad_meta );

		self::assertCount( 1, $migrated );
		self::assertSame( 'Valid', $migrated[0]['name'] );
	}

	public function test_empty_meta_returns_empty(): void {
		$migration = new Lafka_Migration_V8_13_0();
		self::assertSame( array(), $migration->migrate_meta( array() ) );
	}
}
