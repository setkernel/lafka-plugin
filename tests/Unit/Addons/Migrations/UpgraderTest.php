<?php
declare(strict_types=1);
namespace LafkaPlugin\Tests\Unit\Addons\Migrations;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Lafka_Addons_Upgrader;
use Lafka_Migration_V8_13_0;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 4 ) . '/incl/addons/engine/lafka-addons-engine-bootstrap.php';

final class UpgraderTest extends TestCase {

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

	public function test_register_and_get_migrations(): void {
		$upgrader = new Lafka_Addons_Upgrader();
		$upgrader->register( new Lafka_Migration_V8_13_0() );

		$migrations = $upgrader->all();
		self::assertCount( 1, $migrations );
		self::assertSame( '8.13.0', $migrations[0]->id() );
	}

	public function test_apply_runs_each_migration_in_order(): void {
		$upgrader = new Lafka_Addons_Upgrader();
		$upgrader->register( new Lafka_Migration_V8_13_0() );

		$legacy = array(
			array( 'name' => 'G', 'options' => array( array( 'label' => 'X', 'price' => '1' ) ) ),
		);
		$migrated = $upgrader->apply_to_meta( $legacy );

		self::assertSame( 2, $migrated[0]['schema_version'] );
	}

	public function test_apply_to_meta_handles_already_migrated_data(): void {
		$upgrader = new Lafka_Addons_Upgrader();
		$upgrader->register( new Lafka_Migration_V8_13_0() );

		$already_v2 = array(
			array(
				'name'           => 'G',
				'schema_version' => 2,
				'pricing_mode'   => 'flat_group',
				'options_source' => 'manual',
				'options'        => array( array( 'id' => 'x', 'label' => 'X', 'price' => '1' ) ),
			),
		);
		$migrated = $upgrader->apply_to_meta( $already_v2 );

		self::assertSame( 2, $migrated[0]['schema_version'] );
		self::assertSame( 'flat_group', $migrated[0]['pricing_mode'] );
	}

	public function test_no_registered_migrations_returns_meta_unchanged(): void {
		$upgrader = new Lafka_Addons_Upgrader();
		$meta = array( array( 'name' => 'G' ) );
		self::assertSame( $meta, $upgrader->apply_to_meta( $meta ) );
	}
}
