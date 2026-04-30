<?php
declare(strict_types=1);
namespace LafkaPlugin\Tests\Unit\Addons;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Lafka_Addon_Schema;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 3 ) . '/incl/addons/engine/lafka-addons-engine-bootstrap.php';

final class AddonSchemaTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// default_option() calls wp_generate_uuid4() when WP is loaded; under
		// the unit harness Brain Monkey + Patchwork keep the symbol around once
		// any earlier test has stubbed it, so we must always provide an
		// expectation here for strict mode.
		Functions\when( 'wp_generate_uuid4' )->justReturn( 'test-uuid-0000' );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_pricing_mode_constants_defined(): void {
		self::assertSame( 'flat_group', Lafka_Addon_Schema::PRICING_FLAT_GROUP );
		self::assertSame( 'flat_per_option', Lafka_Addon_Schema::PRICING_FLAT_PER_OPTION );
		self::assertSame( 'flat_per_size', Lafka_Addon_Schema::PRICING_FLAT_PER_SIZE );
		self::assertSame( 'matrix', Lafka_Addon_Schema::PRICING_MATRIX );
		self::assertSame( 'legacy', Lafka_Addon_Schema::PRICING_LEGACY );
	}

	public function test_source_constants_defined(): void {
		self::assertSame( 'manual', Lafka_Addon_Schema::SOURCE_MANUAL );
		self::assertSame( 'attribute', Lafka_Addon_Schema::SOURCE_ATTRIBUTE );
	}

	public function test_default_group_returns_canonical_shape(): void {
		$defaults = Lafka_Addon_Schema::default_group();

		self::assertSame( 'legacy', $defaults['pricing_mode'] );
		self::assertSame( 'manual', $defaults['options_source'] );
		self::assertSame( 2, $defaults['schema_version'] );
		self::assertSame( '', $defaults['name'] );
		self::assertSame( 0, $defaults['variations'] );
		self::assertSame( array(), $defaults['options'] );
		self::assertSame( array(), $defaults['included_size_slugs'] );
		self::assertSame( '', $defaults['group_flat_price'] );
		self::assertSame( array(), $defaults['group_size_prices'] );
	}

	public function test_default_option_returns_canonical_shape(): void {
		$defaults = Lafka_Addon_Schema::default_option();

		self::assertArrayHasKey( 'id', $defaults );
		self::assertSame( '', $defaults['label'] );
		self::assertSame( '', $defaults['price'] );
		self::assertSame( '', $defaults['default'] );
		self::assertTrue( $defaults['included'] );
	}

	public function test_pricing_modes_returns_all_known_modes(): void {
		$modes = Lafka_Addon_Schema::pricing_modes();
		self::assertCount( 5, $modes );
		self::assertContains( 'flat_group', $modes );
		self::assertContains( 'matrix', $modes );
		self::assertContains( 'legacy', $modes );
	}
}
