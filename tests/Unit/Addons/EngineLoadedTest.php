<?php
declare(strict_types=1);
namespace LafkaPlugin\Tests\Unit\Addons;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 3 ) . '/incl/addons/lafka-product-addons.php';

final class EngineLoadedTest extends TestCase {

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

	public function test_engine_constants_loaded_via_main_plugin(): void {
		self::assertTrue( defined( 'LAFKA_ADDONS_ENGINE_VERSION' ) );
	}

	public function test_engine_classes_available(): void {
		self::assertTrue( class_exists( 'Lafka_Addon_Schema' ) );
		self::assertTrue( class_exists( 'Lafka_Addon_Group' ) );
		self::assertTrue( class_exists( 'Lafka_Addon_Option' ) );
		self::assertTrue( class_exists( 'Lafka_Pricing_Resolver' ) );
		self::assertTrue( class_exists( 'Lafka_Addon_Repository' ) );
	}
}
