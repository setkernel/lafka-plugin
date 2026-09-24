<?php
/**
 * NX1-01: Site Health still lists the same feature flags — now sourced from the
 * module registry instead of a hand-maintained list.
 *
 * Verifies that Lafka_Site_Health::add_debug_information() still surfaces the
 * five 'lafka'-option flags (product_addons, shipping_areas, order_hours,
 * kitchen_display, promotions) with the same value formatting, and that the
 * flag list is exactly the registry's 'lafka_option'-storage modules (so the
 * panel and the Modules dashboard can never drift).
 */

declare(strict_types=1);

namespace Lafka\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Lafka_Module_Registry;
use Lafka_Options;
use Lafka_Site_Health;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

require_once dirname( __DIR__, 2 ) . '/incl/class-lafka-options.php';
require_once dirname( __DIR__, 2 ) . '/incl/class-lafka-module-registry.php';
require_once dirname( __DIR__, 2 ) . '/incl/site-health/class-lafka-site-health.php';

final class SiteHealthModulesTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->reset_lafka_options_static_state();
		Lafka_Module_Registry::reset();

		Functions\when( 'esc_html__' )->returnArg( 1 );
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'do_action' )->justReturn( null );
		Functions\when( 'did_action' )->justReturn( 1 );
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( false );
		Functions\when( 'wp_get_theme' )->justReturn( null );
		Functions\when( 'is_child_theme' )->justReturn( false );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_security_recommendation_points_at_the_option_the_toggle_uses(): void {
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'esc_html' )->returnArg( 1 );
		require_once dirname( __DIR__, 2 ) . '/incl/security/class-lafka-security-headers.php';

		$result = Lafka_Site_Health::instance()->test_security_headers();

		self::assertSame( 'recommended', $result['status'] );
		self::assertStringContainsString( 'Tools → Lafka Security', $result['description'] );
		self::assertStringContainsString( 'wp option patch insert lafka_security_options enable_security_headers enabled', $result['description'] );
	}

	public function test_flag_rows_are_exactly_the_registry_option_flags(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		$fields         = Lafka_Site_Health::instance()->add_debug_information( array() )['lafka']['fields'];
		$registry_flags = array_keys( Lafka_Module_Registry::modules_by_storage( 'lafka_option' ) );

		foreach ( array( 'product_addons', 'shipping_areas', 'order_hours', 'kitchen_display', 'promotions' ) as $flag ) {
			self::assertContains( $flag, $registry_flags );
		}
		self::assertSame( array(), array_diff( $registry_flags, array_keys( $fields ) ), 'Every registry flag must have a Site Health row.' );
	}

	public function test_flag_values_read_the_lafka_option(): void {
		Functions\when( 'get_option' )->justReturn( array( 'kitchen_display' => 'enabled' ) );

		$fields = Lafka_Site_Health::instance()->add_debug_information( array() )['lafka']['fields'];

		self::assertSame( 'Enabled', $fields['kitchen_display']['value'] );
		self::assertSame( 'Disabled (default)', $fields['promotions']['value'] );
	}

	public function test_non_flag_rows_still_present(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		$fields = Lafka_Site_Health::instance()->add_debug_information( array() )['lafka']['fields'];

		foreach ( array( 'plugin_version', 'security_headers', 'wc_active', 'options_count', 'object_cache' ) as $key ) {
			self::assertArrayHasKey( $key, $fields );
		}
	}

	private function reset_lafka_options_static_state(): void {
		Lafka_Options::flush();
		$reflection    = new ReflectionClass( Lafka_Options::class );
		$defaults_prop = $reflection->getProperty( 'defaults' );
		$defaults_prop->setValue( null, array() );
	}
}
