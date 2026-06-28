<?php
/**
 * AnalyticsSharedGateTest — locks the SSOT consolidation of the analytics
 * "is a destination configured?" gates (f023).
 *
 * Before this fix three near-duplicate implementations existed with divergent
 * coverage: lafka_analytics_is_active() (GTM/GA4/Clarity/Pixel + CF beacon),
 * lafka_custom_events_has_analytics_id() (4-check, no beacon) and an inline
 * $has_id block in lafka-wc-events.php (4-check, no beacon). They are now
 * collapsed onto one shared gate:
 *
 *   - lafka_analytics_has_datalayer_destination()  → GTM/GA4/Clarity/Pixel
 *   - lafka_analytics_is_active()                  → the above OR the CF beacon
 *
 * This test pins:
 *   - the shared gate exists and detects each dataLayer destination
 *   - a CF-beacon-only site is NOT a dataLayer destination (the client bundles
 *     stay un-enqueued) but IS "active" (cheap server pushes still emit)
 *   - the custom-events gate never drifts from the shared gate
 *
 * @package Lafka\Plugin\Tests\Unit
 * @since   9.31.1
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

// Bring the analytics ID accessors + every gate definition into scope.
require_once dirname( __DIR__, 2 ) . '/incl/customizer/class-lafka-customizer-analytics.php';
require_once dirname( __DIR__, 2 ) . '/incl/analytics/lafka-analytics-emitter.php';
require_once dirname( __DIR__, 2 ) . '/incl/analytics/lafka-wc-events.php';
require_once dirname( __DIR__, 2 ) . '/incl/analytics/lafka-custom-events.php';
require_once dirname( __DIR__, 2 ) . '/incl/analytics/lafka-cf-analytics.php';
require_once dirname( __DIR__, 2 ) . '/incl/analytics/lafka-page-context.php';

final class AnalyticsSharedGateTest extends TestCase {

	/** Valid (format-passing) example value per Customizer theme_mod key. */
	private const VALID = array(
		'lafka_gtm_container_id'  => 'GTM-XYZ987',
		'lafka_ga4_measurement_id' => 'G-ABCDE12345',
		'lafka_clarity_project_id' => 'abcdef12345',
		'lafka_meta_pixel_id'      => '1234567890123456',
		'lafka_cf_beacon_token'    => 'abcdef0123456789abcdef0123456789',
	);

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// Default: every theme_mod unset (return the supplied default).
		Functions\when( 'get_theme_mod' )->returnArg( 2 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Stub get_theme_mod so only the given keys resolve to a configured value.
	 *
	 * @param array<string, string> $set key => value for the keys that are "set".
	 */
	private function stub_theme_mods( array $set ): void {
		Functions\when( 'get_theme_mod' )->alias(
			static function ( $key, $default = '' ) use ( $set ) {
				return array_key_exists( $key, $set ) ? $set[ $key ] : $default;
			}
		);
	}

	public function test_shared_gate_function_exists(): void {
		$this->assertTrue(
			function_exists( 'lafka_analytics_has_datalayer_destination' ),
			'The shared dataLayer-destination gate must be defined.'
		);
	}

	public function test_nothing_configured_means_no_destination_and_inactive(): void {
		$this->assertFalse( \lafka_analytics_has_datalayer_destination() );
		$this->assertFalse( \lafka_custom_events_has_analytics_id() );
		$this->assertFalse( \lafka_analytics_is_active() );
	}

	/**
	 * @return array<string, array{0:string}>
	 */
	public static function datalayer_destination_keys(): array {
		return array(
			'gtm'    => array( 'lafka_gtm_container_id' ),
			'ga4'    => array( 'lafka_ga4_measurement_id' ),
			'clarity' => array( 'lafka_clarity_project_id' ),
			'pixel'  => array( 'lafka_meta_pixel_id' ),
		);
	}

	#[DataProvider( 'datalayer_destination_keys' )]
	public function test_each_datalayer_destination_trips_every_gate( string $key ): void {
		$this->stub_theme_mods( array( $key => self::VALID[ $key ] ) );
		$this->assertTrue(
			\lafka_analytics_has_datalayer_destination(),
			"{$key} must register as a dataLayer destination."
		);
		$this->assertTrue(
			\lafka_custom_events_has_analytics_id(),
			"{$key} must trip the custom-events gate (it delegates to the shared gate)."
		);
		$this->assertTrue(
			\lafka_analytics_is_active(),
			"{$key} must make analytics active."
		);
	}

	public function test_cf_beacon_only_is_active_but_not_a_datalayer_destination(): void {
		$this->stub_theme_mods( array( 'lafka_cf_beacon_token' => self::VALID['lafka_cf_beacon_token'] ) );

		// The core of the fix: the cookieless beacon never consumes the dataLayer,
		// so it must NOT enqueue the dataLayer client bundles.
		$this->assertFalse(
			\lafka_analytics_has_datalayer_destination(),
			'A CF-beacon-only site has no dataLayer-consuming destination.'
		);
		$this->assertFalse(
			\lafka_custom_events_has_analytics_id(),
			'custom-events JS must stay un-enqueued on a CF-beacon-only site.'
		);

		// ...but the union gate still reports active so the cheap server-rendered
		// page_context / store-events pushes keep firing.
		$this->assertTrue(
			\lafka_analytics_is_active(),
			'A configured CF beacon must still count as "analytics active".'
		);
	}

	public function test_custom_events_gate_never_drifts_from_shared_gate(): void {
		$configs = array(
			array(),
			array( 'lafka_gtm_container_id' => self::VALID['lafka_gtm_container_id'] ),
			array( 'lafka_cf_beacon_token' => self::VALID['lafka_cf_beacon_token'] ),
			array(
				'lafka_clarity_project_id' => self::VALID['lafka_clarity_project_id'],
				'lafka_cf_beacon_token'    => self::VALID['lafka_cf_beacon_token'],
			),
		);
		foreach ( $configs as $config ) {
			$this->stub_theme_mods( $config );
			$this->assertSame(
				\lafka_analytics_has_datalayer_destination(),
				\lafka_custom_events_has_analytics_id(),
				'custom-events gate must equal the shared gate for config: ' . implode( ',', array_keys( $config ) )
			);
		}
	}
}
