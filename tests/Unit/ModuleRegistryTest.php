<?php
/**
 * NX1-01: Lafka_Module_Registry contract.
 *
 * Locks in the typed module registry that the Feature Modules dashboard, Site
 * Health, and (later) the setup wizard all read from:
 *
 *   - registration completeness — every gated module is present,
 *   - default states match the current per-module behaviour,
 *   - get_enabled()/set_enabled() round-trip through the REAL storage each
 *     module already uses (the 'lafka' option array for the five flags,
 *     theme_mods for the conversion modules) — zero new storage invented,
 *   - is_configured() reflects whether the module has what it needs to run,
 *   - analytics is read-only (enabled == a destination is configured).
 */

declare(strict_types=1);

namespace Lafka\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Lafka_Module;
use Lafka_Module_Registry;
use Lafka_Options;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

require_once dirname( __DIR__, 2 ) . '/incl/class-lafka-options.php';
require_once dirname( __DIR__, 2 ) . '/incl/class-lafka-module-registry.php';

// The analytics module reads the REAL lafka_analytics_is_active() gate (which
// cannot be Brain-Monkey-redefined once a sibling test has loaded it), so we
// pull in its definition + the ID accessors it delegates to and drive the
// outcome through get_theme_mod — exactly as AnalyticsSharedGateTest does.
require_once dirname( __DIR__, 2 ) . '/incl/customizer/class-lafka-customizer-analytics.php';
require_once dirname( __DIR__, 2 ) . '/incl/analytics/lafka-analytics-emitter.php';
require_once dirname( __DIR__, 2 ) . '/incl/analytics/lafka-wc-events.php';
require_once dirname( __DIR__, 2 ) . '/incl/analytics/lafka-cf-analytics.php';
require_once dirname( __DIR__, 2 ) . '/incl/analytics/lafka-page-context.php';

final class ModuleRegistryTest extends TestCase {

	/**
	 * Every module the registry must ship (NX1-01 build list).
	 *
	 * @var array<int,string>
	 */
	private const EXPECTED_MODULES = array(
		'product_addons',
		'shipping_areas',
		'order_hours',
		'kitchen_display',
		'promotions',
		'abandoned_cart',
		'push',
		'review_prompt',
		'analytics',
	);

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->reset_lafka_options_static_state();
		Lafka_Module_Registry::reset();

		// Bootstrap builds labels/descriptions via the i18n + hook helpers.
		Functions\when( 'esc_html__' )->returnArg( 1 );
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'apply_filters' )->returnArg( 1 );
		Functions\when( 'do_action' )->justReturn( null );
		Functions\when( 'admin_url' )->returnArg( 1 );
		// Lafka_Options::is_enabled() branches on did_action('init').
		Functions\when( 'did_action' )->justReturn( 1 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ─── Registration completeness ──────────────────────────────────────────

	public function test_all_expected_modules_are_registered(): void {
		$ids = array_keys( Lafka_Module_Registry::all() );
		sort( $ids );

		$expected = self::EXPECTED_MODULES;
		sort( $expected );

		self::assertSame( $expected, $ids );
	}

	public function test_get_returns_module_object_or_null(): void {
		self::assertInstanceOf( Lafka_Module::class, Lafka_Module_Registry::get( 'promotions' ) );
		self::assertNull( Lafka_Module_Registry::get( 'not_a_real_module' ) );
	}

	public function test_every_module_exposes_required_metadata(): void {
		foreach ( Lafka_Module_Registry::all() as $id => $module ) {
			self::assertInstanceOf( Lafka_Module::class, $module );
			self::assertSame( $id, $module->get_id() );
			self::assertNotSame( '', $module->get_label(), "$id must have a label" );
			self::assertNotSame( '', $module->get_category(), "$id must have a category" );
			self::assertNotSame( '', $module->get_description(), "$id must have a description" );
			self::assertIsBool( $module->default_enabled() );
		}
	}

	// ─── Default states match current behaviour ─────────────────────────────

	public function test_default_enabled_matches_current_defaults(): void {
		// Theme options-framework std values + conversion-module theme_mod
		// defaults, verified against lafka-theme/incl/lafka-options-framework/
		// lafka-options.php and each Customizer panel's 'default'.
		$expected = array(
			'product_addons'  => true,   // std => 'enabled'
			'shipping_areas'  => false,  // std => ''
			'order_hours'     => false,  // std => ''
			'kitchen_display' => false,  // std => ''
			'promotions'      => false,  // gated module, default OFF
			'abandoned_cart'  => false,  // lafka_ac_enabled default '0'
			'push'            => false,  // lafka_push_enabled default '0'
			'review_prompt'   => false,  // lafka_review_email_enabled default '0'
			'analytics'       => false,  // derived — no destination by default
		);

		foreach ( $expected as $id => $default ) {
			self::assertSame(
				$default,
				Lafka_Module_Registry::get( $id )->default_enabled(),
				"$id default_enabled mismatch"
			);
		}
	}

	// ─── Flag modules read/write the SAME 'lafka' option array ──────────────

	public function test_flag_module_reads_lafka_option_array(): void {
		Functions\when( 'get_option' )->justReturn( array( 'kitchen_display' => 'enabled' ) );

		self::assertTrue( Lafka_Module_Registry::get( 'kitchen_display' )->is_enabled() );
		self::assertFalse( Lafka_Module_Registry::get( 'promotions' )->is_enabled() );
	}

	public function test_flag_module_off_when_option_empty(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		foreach ( array( 'product_addons', 'shipping_areas', 'order_hours', 'kitchen_display', 'promotions' ) as $id ) {
			self::assertFalse(
				Lafka_Module_Registry::get( $id )->is_enabled(),
				"$id must be OFF when the 'lafka' option is empty (matches is_lafka_*() gates)"
			);
		}
	}

	public function test_set_enabled_round_trips_through_lafka_option(): void {
		$store = array( 'lafka' => array() );
		$this->wire_option_store( $store );

		$module = Lafka_Module_Registry::get( 'promotions' );

		self::assertFalse( $module->is_enabled() );

		self::assertTrue( $module->set_enabled( true ) );
		self::assertSame( 'enabled', $store['lafka']['promotions'] );
		self::assertTrue( $module->is_enabled() );

		self::assertTrue( $module->set_enabled( false ) );
		self::assertSame( 'disabled', $store['lafka']['promotions'] );
		self::assertFalse( $module->is_enabled() );
	}

	public function test_set_enabled_does_not_disturb_sibling_flags(): void {
		$store = array( 'lafka' => array( 'product_addons' => 'enabled' ) );
		$this->wire_option_store( $store );

		Lafka_Module_Registry::get( 'kitchen_display' )->set_enabled( true );

		self::assertSame( 'enabled', $store['lafka']['product_addons'] );
		self::assertSame( 'enabled', $store['lafka']['kitchen_display'] );
	}

	// ─── theme_mod-backed conversion modules ────────────────────────────────

	public function test_theme_mod_module_round_trips(): void {
		$mods = array();
		$this->wire_theme_mod_store( $mods );

		$module = Lafka_Module_Registry::get( 'abandoned_cart' );

		self::assertFalse( $module->is_enabled() );
		self::assertTrue( $module->set_enabled( true ) );
		self::assertSame( '1', $mods['lafka_ac_enabled'] );
		self::assertTrue( $module->is_enabled() );

		$module->set_enabled( false );
		self::assertSame( '0', $mods['lafka_ac_enabled'] );
	}

	public function test_push_and_review_use_their_real_enable_keys(): void {
		$mods = array();
		$this->wire_theme_mod_store( $mods );

		Lafka_Module_Registry::get( 'push' )->set_enabled( true );
		Lafka_Module_Registry::get( 'review_prompt' )->set_enabled( true );

		self::assertSame( '1', $mods['lafka_push_enabled'] );
		self::assertSame( '1', $mods['lafka_review_email_enabled'] );
	}

	// ─── Analytics is read-only (derived from configured destinations) ──────

	public function test_analytics_module_is_read_only(): void {
		$module = Lafka_Module_Registry::get( 'analytics' );

		self::assertTrue( $module->is_read_only() );
		// A write attempt is a no-op that reports failure.
		self::assertFalse( $module->set_enabled( true ) );
	}

	public function test_analytics_enabled_when_a_destination_is_configured(): void {
		// A configured GTM container id is a dataLayer destination → active.
		Functions\when( 'get_theme_mod' )->alias(
			static function ( $key, $default = '' ) {
				return 'lafka_gtm_container_id' === $key ? 'GTM-XYZ987' : $default;
			}
		);

		$module = Lafka_Module_Registry::get( 'analytics' );
		self::assertTrue( $module->is_enabled() );
		self::assertTrue( $module->is_configured() );
	}

	public function test_analytics_disabled_when_no_destination(): void {
		// Every theme_mod unset → no destination → inactive.
		Functions\when( 'get_theme_mod' )->returnArg( 2 );

		self::assertFalse( Lafka_Module_Registry::get( 'analytics' )->is_enabled() );
	}

	// ─── is_configured() logic ──────────────────────────────────────────────

	public function test_module_without_extra_config_is_always_configured(): void {
		Functions\when( 'get_option' )->justReturn( array() );

		self::assertTrue( Lafka_Module_Registry::get( 'product_addons' )->is_configured() );
		self::assertTrue( Lafka_Module_Registry::get( 'kitchen_display' )->is_configured() );
	}

	public function test_push_needs_vapid_keys_to_be_configured(): void {
		$mods = array();
		$this->wire_theme_mod_store( $mods );

		self::assertFalse(
			Lafka_Module_Registry::get( 'push' )->is_configured(),
			'Push is not configured until VAPID keys are present.'
		);

		$mods['lafka_push_vapid_public_key']  = 'pub';
		$mods['lafka_push_vapid_private_key'] = 'priv';

		self::assertTrue( Lafka_Module_Registry::get( 'push' )->is_configured() );
	}

	public function test_review_prompt_needs_target_url_to_be_configured(): void {
		$mods = array();
		$this->wire_theme_mod_store( $mods );

		self::assertFalse( Lafka_Module_Registry::get( 'review_prompt' )->is_configured() );

		$mods['lafka_review_target_url'] = 'https://example.test/review';
		self::assertTrue( Lafka_Module_Registry::get( 'review_prompt' )->is_configured() );
	}

	public function test_order_hours_configured_once_schedule_saved(): void {
		Functions\when( 'get_option' )->alias(
			static function ( $key ) {
				return 'lafka_order_hours_options' === $key
					? array( 'lafka_order_hours_schedule' => 'x' )
					: array();
			}
		);

		self::assertTrue( Lafka_Module_Registry::get( 'order_hours' )->is_configured() );
	}

	// ─── Settings URL + docs ────────────────────────────────────────────────

	public function test_settings_url_wraps_admin_url(): void {
		Functions\when( 'admin_url' )->alias(
			static function ( $path ) {
				return 'http://site.test/wp-admin/' . $path;
			}
		);

		self::assertStringContainsString(
			'page=lafka_order_hours',
			Lafka_Module_Registry::get( 'order_hours' )->get_settings_url()
		);
	}

	// ─── Storage classification (used by Site Health rewire) ────────────────

	public function test_option_flag_modules_are_exactly_the_five_flags(): void {
		$flags = array_keys( Lafka_Module_Registry::modules_by_storage( 'lafka_option' ) );
		sort( $flags );

		$expected = array( 'kitchen_display', 'order_hours', 'product_addons', 'promotions', 'shipping_areas' );

		self::assertSame( $expected, $flags );
	}

	// ─── Helpers ────────────────────────────────────────────────────────────

	/**
	 * Wire get_option()/update_option() against a shared backing store so the
	 * flag setters/getters exercise the real 'lafka' option round-trip.
	 *
	 * @param array<string,mixed> $store Passed by reference.
	 */
	private function wire_option_store( array &$store ): void {
		Functions\when( 'get_option' )->alias(
			static function ( $key, $default = false ) use ( &$store ) {
				return array_key_exists( $key, $store ) ? $store[ $key ] : $default;
			}
		);
		Functions\when( 'update_option' )->alias(
			static function ( $key, $value ) use ( &$store ) {
				$store[ $key ] = $value;
				return true;
			}
		);
	}

	/**
	 * Wire get_theme_mod()/set_theme_mod() against a shared backing store.
	 *
	 * @param array<string,mixed> $mods Passed by reference.
	 */
	private function wire_theme_mod_store( array &$mods ): void {
		Functions\when( 'get_theme_mod' )->alias(
			static function ( $key, $default = false ) use ( &$mods ) {
				return array_key_exists( $key, $mods ) ? $mods[ $key ] : $default;
			}
		);
		Functions\when( 'set_theme_mod' )->alias(
			static function ( $key, $value ) use ( &$mods ) {
				$mods[ $key ] = $value;
				return true;
			}
		);
	}

	private function reset_lafka_options_static_state(): void {
		Lafka_Options::flush();
		$reflection    = new ReflectionClass( Lafka_Options::class );
		$defaults_prop = $reflection->getProperty( 'defaults' );
		$defaults_prop->setValue( null, array() );
	}
}
