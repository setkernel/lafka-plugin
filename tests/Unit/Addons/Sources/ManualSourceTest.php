<?php
declare(strict_types=1);
namespace LafkaPlugin\Tests\Unit\Addons\Sources;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Lafka_Addon_Group;
use Lafka_Addon_Schema;
use Lafka_Manual_Source;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 4 ) . '/incl/addons/engine/lafka-addons-engine-bootstrap.php';

final class ManualSourceTest extends TestCase {

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

	public function test_id_and_label(): void {
		$source = new Lafka_Manual_Source();
		self::assertSame( Lafka_Addon_Schema::SOURCE_MANUAL, $source->id() );
		self::assertNotEmpty( $source->label() );
	}

	public function test_get_options_returns_group_options_unchanged(): void {
		$group = Lafka_Addon_Group::from_array( array(
			'name'    => 'G',
			'options' => array(
				array( 'label' => 'A', 'price' => '1.00' ),
				array( 'label' => 'B', 'price' => '2.00' ),
			),
		) );
		$source  = new Lafka_Manual_Source();
		$options = $source->get_options( $group );

		self::assertCount( 2, $options );
		self::assertSame( 'A', $options[0]->label );
		self::assertSame( 'B', $options[1]->label );
	}

	public function test_sync_is_a_noop_for_manual_source(): void {
		$group = Lafka_Addon_Group::from_array( array(
			'name'    => 'G',
			'options' => array( array( 'label' => 'A', 'price' => '1.00' ) ),
		) );
		$source = new Lafka_Manual_Source();
		$synced = $source->sync( $group );

		self::assertSame( $group->options, $synced->options );
	}
}
