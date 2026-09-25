<?php
/**
 * Store location for delivery areas (GX0): a missing, malformed, or legacy
 * placeholder location (the old built-in Sydney default the admin map used
 * to save by itself) is "not configured" — delivery maths falls back to
 * geocoding the WooCommerce store address, and the operator is told through
 * an admin notice and Site Health.
 *
 * @package Lafka_Plugin
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use LafkaPlugin\Tests\Unit\Support\Hooks;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Support/Hooks.php';

final class StoreLocationTest extends TestCase {

	private const SYDNEY = '%7B%22lat%22%3A-33.8688197%2C%22lng%22%3A151.2092955%7D';
	private const PINNED = '%7B%22lat%22%3A12.34%2C%22lng%22%3A-56.78%7D';

	/** @var array<string, mixed> */
	private array $advanced = array();

	private bool $can_manage = true;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'admin_url' )->alias( static fn( $path = '' ) => 'https://shop.example.test/wp-admin/' . $path );
		Functions\when( 'current_user_can' )->alias( fn() => $this->can_manage );
		Functions\when( 'get_option' )->alias(
			fn( $key, $fallback = false ) => 'lafka_shipping_areas_advanced' === $key ? $this->advanced : $fallback
		);
		require_once dirname( __DIR__, 2 ) . '/incl/shipping-areas/lafka-store-location.php';
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @return array<string, array{0: mixed, 1: ?array}>
	 */
	public static function raw_locations(): array {
		return array(
			'url-encoded JSON (as the map saves it)' => array( self::PINNED, array( 'lat' => 12.34, 'lng' => -56.78 ) ),
			'plain JSON'                             => array( '{"lat":12.34,"lng":-56.78}', array( 'lat' => 12.34, 'lng' => -56.78 ) ),
			'empty'                                  => array( '', null ),
			'garbage'                                => array( '%7Bnot-json', null ),
			'out of range'                           => array( '{"lat":95,"lng":10}', null ),
			'non-numeric'                            => array( '{"lat":"north","lng":10}', null ),
			'the legacy Sydney placeholder'          => array( self::SYDNEY, null ),
			'not a string'                           => array( array( 'lat' => 1 ), null ),
		);
	}

	/**
	 * @param mixed $raw
	 */
	#[DataProvider( 'raw_locations' )]
	public function test_parse( $raw, ?array $expected ): void {
		$this->assertSame( $expected, lafka_parse_store_map_location( $raw ) );
	}

	public function test_a_picked_location_is_used_as_is(): void {
		$this->advanced = array(
			'set_store_location' => 'pick_store_address',
			'store_map_location' => self::PINNED,
		);

		$this->assertSame(
			array(
				'status'   => 'ok',
				'mode'     => 'pick_store_address',
				'location' => self::PINNED,
			),
			lafka_store_location_settings()
		);
	}

	public function test_the_sydney_placeholder_falls_back_to_geocoding_the_store_address(): void {
		$this->advanced = array(
			'set_store_location' => 'pick_store_address',
			'store_map_location' => self::SYDNEY,
		);

		$this->assertSame(
			array(
				'status'   => 'placeholder',
				'mode'     => 'geo_woo_store',
				'location' => '',
			),
			lafka_store_location_settings()
		);
	}

	public function test_a_missing_pick_falls_back_to_geocoding(): void {
		$this->advanced = array( 'set_store_location' => 'pick_store_address' );

		$this->assertSame( 'missing', lafka_store_location_settings()['status'] );
		$this->assertSame( 'geo_woo_store', lafka_store_location_settings()['mode'] );
	}

	public function test_geocode_mode_needs_no_pinned_location(): void {
		$this->advanced = array();

		$this->assertSame(
			array(
				'status'   => 'geocode',
				'mode'     => 'geo_woo_store',
				'location' => '',
			),
			lafka_store_location_settings()
		);
	}

	private function notice(): string {
		ob_start();
		lafka_store_location_admin_notice();
		return (string) ob_get_clean();
	}

	public function test_the_admin_notice_explains_and_links_to_the_setting(): void {
		$this->advanced = array(
			'set_store_location' => 'pick_store_address',
			'store_map_location' => self::SYDNEY,
		);

		$html = $this->notice();

		$this->assertStringContainsString( 'notice-warning', $html );
		$this->assertStringContainsString( 'Delivery areas need your store location', $html );
		$this->assertStringContainsString( 'Sydney, Australia', $html );
		$this->assertStringContainsString( 'page=lafka_shipping_areas_admin&tab=advanced', $html );
	}

	public function test_no_notice_when_configured_or_for_users_who_cannot_fix_it(): void {
		$this->advanced = array(
			'set_store_location' => 'pick_store_address',
			'store_map_location' => self::PINNED,
		);
		$this->assertSame( '', $this->notice() );

		$this->advanced   = array( 'set_store_location' => 'pick_store_address' );
		$this->can_manage = false;
		$this->assertSame( '', $this->notice() );
	}

	public function test_site_health_reports_the_state(): void {
		$this->advanced = array( 'set_store_location' => 'pick_store_address' );
		$result         = lafka_store_location_site_health_test();
		$this->assertSame( 'recommended', $result['status'] );
		$this->assertSame( 'Delivery areas need your store location', $result['label'] );

		$this->advanced = array(
			'set_store_location' => 'pick_store_address',
			'store_map_location' => self::PINNED,
		);
		$this->assertSame( 'good', lafka_store_location_site_health_test()['status'] );

		$tests = lafka_store_location_register_site_health( array() );
		$this->assertSame( 'lafka_store_location_site_health_test', $tests['direct']['lafka_store_location']['test'] );
	}

	public function test_hooks(): void {
		Hooks::reset();

		lafka_store_location_init();

		$this->assertSame(
			array(
				'admin_notices -> lafka_store_location_admin_notice',
				'site_status_tests -> lafka_store_location_register_site_health',
			),
			Hooks::registered()
		);
	}
}
