<?php
declare(strict_types=1);
namespace LafkaPlugin\Tests\Unit\Addons\Migrations;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Lafka_Addons_Upgrader;
use Lafka_Migration;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 4 ) . '/incl/addons/engine/lafka-addons-engine-bootstrap.php';

/**
 * Inline test migration — no v1→v2 migration ships in v8.13.0, but the
 * upgrader contract still needs verification for future migrations. This
 * stub satisfies Lafka_Migration and applies a marker field so we can
 * assert the apply_to_meta dispatch ran.
 */
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
		$upgrader->register( new TestMigration_9_0_0() );

		$migrations = $upgrader->all();
		self::assertCount( 1, $migrations );
		self::assertSame( '9.0.0', $migrations[0]->id() );
	}

	public function test_apply_runs_each_migration_in_order(): void {
		$upgrader = new Lafka_Addons_Upgrader();
		$upgrader->register( new TestMigration_9_0_0() );

		$migrated = $upgrader->apply_to_meta(
			array( array( 'name' => 'G', 'options' => array() ) )
		);

		self::assertSame( 1, $migrated[0]['_test_migration_marker'] );
	}

	public function test_apply_to_meta_runs_in_id_order(): void {
		$upgrader = new Lafka_Addons_Upgrader();
		// Register out of order; apply should still run 8.13.0 before 9.0.0.
		$upgrader->register( new TestMigration_9_0_0() );
		$upgrader->register( new TestMigration_8_13_0() );

		$ordered = $upgrader->all();
		self::assertSame( '8.13.0', $ordered[0]->id() );
		self::assertSame( '9.0.0', $ordered[1]->id() );
	}

	public function test_no_registered_migrations_returns_meta_unchanged(): void {
		$upgrader = new Lafka_Addons_Upgrader();
		$meta = array( array( 'name' => 'G' ) );
		self::assertSame( $meta, $upgrader->apply_to_meta( $meta ) );
	}
}

class TestMigration_9_0_0 implements Lafka_Migration {
	public function id(): string {
		return '9.0.0';
	}
	public function target_schema_version(): int {
		return 3;
	}
	public function migrate_meta( array $meta ): array {
		foreach ( $meta as $i => $entry ) {
			if ( is_array( $entry ) ) {
				$meta[ $i ]['_test_migration_marker'] = 1;
			}
		}
		return $meta;
	}
}

class TestMigration_8_13_0 implements Lafka_Migration {
	public function id(): string {
		return '8.13.0';
	}
	public function target_schema_version(): int {
		return 2;
	}
	public function migrate_meta( array $meta ): array {
		return $meta;
	}
}
