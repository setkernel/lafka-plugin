<?php
/**
 * Phase 1 Task 1: bootstrap loads the engine namespace and exposes the version constant.
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit\Addons;

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 3 ) . '/incl/addons/engine/lafka-addons-engine-bootstrap.php';

final class AddonEngineBootstrapTest extends TestCase {

	public function test_engine_version_constant_defined(): void {
		self::assertTrue( defined( 'LAFKA_ADDONS_ENGINE_VERSION' ) );
		self::assertSame( 2, LAFKA_ADDONS_ENGINE_VERSION );
	}

	public function test_engine_path_constant_defined(): void {
		self::assertTrue( defined( 'LAFKA_ADDONS_ENGINE_PATH' ) );
		self::assertDirectoryExists( LAFKA_ADDONS_ENGINE_PATH );
	}
}
