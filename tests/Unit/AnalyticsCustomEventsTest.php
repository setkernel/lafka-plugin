<?php
/**
 * Custom interaction-events bundle is enqueued only when a dataLayer-consuming
 * destination (GTM / GA4 / Clarity / Meta Pixel) is configured.
 *
 * @package Lafka\Plugin\Tests\Unit
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/incl/customizer/class-lafka-customizer-analytics.php';
require_once dirname( __DIR__, 2 ) . '/incl/analytics/lafka-analytics-emitter.php';
require_once dirname( __DIR__, 2 ) . '/incl/analytics/lafka-custom-events.php';

final class AnalyticsCustomEventsTest extends TestCase {

	/** @var list<string> */
	private array $enqueued = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->enqueued = array();
		Functions\when( 'get_theme_mod' )->returnArg( 2 );
		Functions\when( 'plugins_url' )->returnArg();
		Functions\when( 'lafka_plugin_asset_version' )->justReturn( '1' );
		Functions\when( 'wp_enqueue_script' )->alias(
			function ( $handle ) {
				$this->enqueued[] = $handle;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_not_enqueued_without_a_destination(): void {
		\lafka_register_custom_events_script();
		$this->assertSame( array(), $this->enqueued );
	}

	/**
	 * @return array<string, array{0:string, 1:string}>
	 */
	public static function destinations(): array {
		return array(
			'gtm'     => array( 'lafka_gtm_container_id', 'GTM-XYZ987' ),
			'ga4'     => array( 'lafka_ga4_measurement_id', 'G-ABCDE12345' ),
			'clarity' => array( 'lafka_clarity_project_id', 'abcdef12345' ),
			'pixel'   => array( 'lafka_meta_pixel_id', '1234567890123456' ),
		);
	}

	#[DataProvider( 'destinations' )]
	public function test_enqueued_for_each_datalayer_destination( string $key, string $value ): void {
		Functions\when( 'get_theme_mod' )->alias(
			static fn( $k, $default = '' ) => $k === $key ? $value : $default
		);
		\lafka_register_custom_events_script();
		$this->assertSame( array( 'lafka-custom-events' ), $this->enqueued );
	}
}
