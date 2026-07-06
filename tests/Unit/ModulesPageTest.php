<?php
/**
 * NX1-01: Lafka_Modules_Page — the Lafka → Modules admin dashboard.
 *
 * Locks in the security + rendering contract of the one-screen module manager:
 *
 *   - the top-level menu is registered with the manage_woocommerce capability
 *     and the dashicons-store icon,
 *   - both the render and the toggle handler refuse users without the
 *     capability (wp_die),
 *   - the toggle handler verifies its nonce before writing anything,
 *   - a toggle writes the SAME option the gate already reads (round-trip),
 *   - read-only modules (analytics) cannot be flipped via a forged POST,
 *   - the page renders exactly one row per registered module and offers a
 *     toggle form only for the flippable ones.
 */

declare(strict_types=1);

namespace Lafka\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Lafka_Module_Registry;
use Lafka_Modules_Page;
use Lafka_Options;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;

require_once dirname( __DIR__, 2 ) . '/incl/class-lafka-options.php';
require_once dirname( __DIR__, 2 ) . '/incl/class-lafka-module-registry.php';
require_once dirname( __DIR__, 2 ) . '/incl/admin/class-lafka-modules-page.php';

final class ModulesPageTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->reset_lafka_options_static_state();
		Lafka_Module_Registry::reset();

		// Shared no-op stubs used across bootstrap + render.
		Functions\when( 'esc_html__' )->returnArg( 1 );
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'esc_html' )->returnArg( 1 );
		Functions\when( 'esc_attr' )->returnArg( 1 );
		Functions\when( 'esc_url' )->returnArg( 1 );
		Functions\when( 'admin_url' )->returnArg( 1 );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'do_action' )->justReturn( null );
		Functions\when( 'did_action' )->justReturn( 1 );
		Functions\when( 'wp_nonce_field' )->justReturn( '' );
	}

	protected function tearDown(): void {
		unset( $_POST['lafka_module'], $_POST['lafka_module_enabled'], $_GET['lafka_updated'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	// ─── Menu registration ──────────────────────────────────────────────────

	public function test_registers_top_level_menu_with_woocommerce_cap_and_store_icon(): void {
		$menu    = array();
		$submenu = 0;
		Functions\when( 'add_menu_page' )->alias(
			static function ( ...$args ) use ( &$menu ) {
				$menu = $args;
			}
		);
		Functions\when( 'add_submenu_page' )->alias(
			static function () use ( &$submenu ) {
				$submenu++;
			}
		);

		Lafka_Modules_Page::instance()->register_menu();

		self::assertSame( 'manage_woocommerce', $menu[2], 'Top-level menu capability.' );
		self::assertSame( 'lafka-modules', $menu[3], 'Top-level menu slug.' );
		self::assertSame( 'dashicons-store', $menu[5], 'Top-level menu icon.' );
		self::assertSame( 1, $submenu, 'A single "Modules" submenu is registered.' );
	}

	// ─── Capability enforcement ─────────────────────────────────────────────

	public function test_render_requires_capability(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\when( 'wp_die' )->alias(
			static function () {
				throw new RuntimeException( 'wp_die' );
			}
		);

		$this->expectException( RuntimeException::class );
		Lafka_Modules_Page::instance()->render_page();
	}

	public function test_toggle_requires_capability(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		$wrote = false;
		Functions\when( 'update_option' )->alias(
			static function () use ( &$wrote ) {
				$wrote = true;
				return true;
			}
		);
		Functions\when( 'wp_die' )->alias(
			static function () {
				throw new RuntimeException( 'wp_die' );
			}
		);

		$_POST['lafka_module']         = 'promotions';
		$_POST['lafka_module_enabled'] = '1';

		try {
			Lafka_Modules_Page::instance()->handle_toggle();
			self::fail( 'Expected wp_die() to abort the toggle.' );
		} catch ( RuntimeException $e ) {
			// expected
		}

		self::assertFalse( $wrote, 'A capability-less request must not write any option.' );
	}

	// ─── Nonce enforcement ──────────────────────────────────────────────────

	public function test_toggle_verifies_nonce_before_writing(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\expect( 'check_admin_referer' )->once()->with( Lafka_Modules_Page::NONCE_ACTION );
		$this->wire_passthrough_sanitizers();
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'update_option' )->justReturn( true );
		Functions\when( 'add_query_arg' )->justReturn( 'x' );
		$this->stop_before_exit();

		$_POST['lafka_module']         = 'promotions';
		$_POST['lafka_module_enabled'] = '1';

		try {
			Lafka_Modules_Page::instance()->handle_toggle();
		} catch ( RuntimeException $e ) {
			// wp_safe_redirect stub throws to avoid the real exit.
		}

		// check_admin_referer expectation is verified on tearDown.
		self::assertTrue( true );
	}

	// ─── Toggle writes the real option (round-trip through the page) ────────

	public function test_toggle_enables_flag_module_via_lafka_option(): void {
		$store         = array( 'lafka' => array() );
		$redirect_args = array();
		$this->wire_toggle_environment( $store, $redirect_args );

		$_POST['lafka_module']         = 'promotions';
		$_POST['lafka_module_enabled'] = '1';

		try {
			Lafka_Modules_Page::instance()->handle_toggle();
		} catch ( RuntimeException $e ) {
			// redirect short-circuit
		}

		self::assertSame( 'enabled', $store['lafka']['promotions'] );
		self::assertSame( 'enabled', $redirect_args['lafka_updated'] );
	}

	public function test_toggle_disables_flag_module(): void {
		$store         = array( 'lafka' => array( 'kitchen_display' => 'enabled' ) );
		$redirect_args = array();
		$this->wire_toggle_environment( $store, $redirect_args );

		$_POST['lafka_module']         = 'kitchen_display';
		$_POST['lafka_module_enabled'] = '0';

		try {
			Lafka_Modules_Page::instance()->handle_toggle();
		} catch ( RuntimeException $e ) {
			// redirect short-circuit
		}

		self::assertSame( 'disabled', $store['lafka']['kitchen_display'] );
	}

	public function test_read_only_module_cannot_be_toggled(): void {
		$store         = array( 'lafka' => array() );
		$redirect_args = array();
		$this->wire_toggle_environment( $store, $redirect_args );

		$_POST['lafka_module']         = 'analytics';
		$_POST['lafka_module_enabled'] = '1';

		try {
			Lafka_Modules_Page::instance()->handle_toggle();
		} catch ( RuntimeException $e ) {
			// redirect short-circuit
		}

		self::assertSame(
			'invalid',
			$redirect_args['lafka_updated'],
			'A read-only module (analytics) must be rejected by the toggle handler.'
		);
	}

	public function test_unknown_module_is_rejected(): void {
		$store         = array( 'lafka' => array() );
		$redirect_args = array();
		$this->wire_toggle_environment( $store, $redirect_args );

		$_POST['lafka_module']         = 'totally_made_up';
		$_POST['lafka_module_enabled'] = '1';

		try {
			Lafka_Modules_Page::instance()->handle_toggle();
		} catch ( RuntimeException $e ) {
			// redirect short-circuit
		}

		self::assertSame( 'invalid', $redirect_args['lafka_updated'] );
		self::assertSame( array(), $store['lafka'] );
	}

	// ─── Rendering ──────────────────────────────────────────────────────────

	public function test_renders_one_row_per_registered_module(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'get_theme_mod' )->returnArg( 2 );

		ob_start();
		Lafka_Modules_Page::instance()->render_page();
		$html = (string) ob_get_clean();

		$module_count = count( Lafka_Module_Registry::all() );

		self::assertSame(
			$module_count,
			substr_count( $html, 'data-lafka-module="' ),
			'Exactly one row per registered module.'
		);
		self::assertStringContainsString( 'data-lafka-module="promotions"', $html );
		self::assertStringContainsString( 'data-lafka-module="analytics"', $html );
	}

	public function test_toggle_form_offered_only_for_flippable_modules(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'get_option' )->justReturn( array() );
		Functions\when( 'get_theme_mod' )->returnArg( 2 );

		ob_start();
		Lafka_Modules_Page::instance()->render_page();
		$html = (string) ob_get_clean();

		$flippable = 0;
		foreach ( Lafka_Module_Registry::all() as $module ) {
			if ( ! $module->is_read_only() ) {
				$flippable++;
			}
		}

		self::assertSame(
			$flippable,
			substr_count( $html, 'name="lafka_module"' ),
			'Only flippable modules get a toggle form (analytics is read-only).'
		);
	}

	// ─── Helpers ────────────────────────────────────────────────────────────

	private function wire_passthrough_sanitizers(): void {
		Functions\when( 'sanitize_key' )->returnArg( 1 );
		Functions\when( 'sanitize_text_field' )->returnArg( 1 );
		Functions\when( 'wp_unslash' )->returnArg( 1 );
	}

	private function stop_before_exit(): void {
		Functions\when( 'wp_safe_redirect' )->alias(
			static function () {
				throw new RuntimeException( 'redirect' );
			}
		);
	}

	/**
	 * Full toggle environment: capability granted, nonce OK, a backing 'lafka'
	 * option store, and a captured redirect payload — with the real exit
	 * replaced by a throw so control returns to the test.
	 *
	 * @param array<string,mixed> $store         Backing option store (by ref).
	 * @param array<string,mixed> $redirect_args Captured add_query_arg payload (by ref).
	 */
	private function wire_toggle_environment( array &$store, array &$redirect_args ): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'check_admin_referer' )->justReturn( true );
		$this->wire_passthrough_sanitizers();
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
		Functions\when( 'set_theme_mod' )->justReturn( true );
		Functions\when( 'get_theme_mod' )->returnArg( 2 );
		Functions\when( 'add_query_arg' )->alias(
			static function ( $args ) use ( &$redirect_args ) {
				$redirect_args = $args;
				return 'redirect-url';
			}
		);
		$this->stop_before_exit();
	}

	private function reset_lafka_options_static_state(): void {
		Lafka_Options::flush();
		$reflection    = new ReflectionClass( Lafka_Options::class );
		$defaults_prop = $reflection->getProperty( 'defaults' );
		$defaults_prop->setValue( null, array() );
	}
}
