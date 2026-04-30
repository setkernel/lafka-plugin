<?php
declare(strict_types=1);
namespace LafkaPlugin\Tests\Unit\Addons;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Lafka_Addons_Engine;
use Lafka_Addon_Repository;
use Lafka_Pricing_Resolver;
use Lafka_Addons_Upgrader;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 3 ) . '/incl/addons/engine/lafka-addons-engine-bootstrap.php';

final class EngineFacadeTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'wp_generate_uuid4' )->justReturn( 'test-uuid-0000' );
		Functions\when( 'apply_filters' )->returnArg( 2 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_instance_returns_singleton(): void {
		$a = Lafka_Addons_Engine::instance();
		$b = Lafka_Addons_Engine::instance();
		self::assertSame( $a, $b );
	}

	public function test_pricing_resolver_available(): void {
		$resolver = Lafka_Addons_Engine::instance()->pricing();
		self::assertInstanceOf( Lafka_Pricing_Resolver::class, $resolver );
	}

	public function test_repository_available(): void {
		$repo = Lafka_Addons_Engine::instance()->repository();
		self::assertInstanceOf( Lafka_Addon_Repository::class, $repo );
	}

	public function test_upgrader_has_v8_13_0_migration_registered(): void {
		$upgrader = Lafka_Addons_Engine::instance()->upgrader();
		self::assertInstanceOf( Lafka_Addons_Upgrader::class, $upgrader );

		$migrations = $upgrader->all();
		self::assertGreaterThanOrEqual( 1, count( $migrations ) );
		self::assertSame( '8.13.0', $migrations[0]->id() );
	}

	public function test_sources_resolves_manual_and_attribute(): void {
		$sources = Lafka_Addons_Engine::instance()->sources();
		self::assertArrayHasKey( 'manual', $sources );
		self::assertArrayHasKey( 'attribute', $sources );
	}
}
