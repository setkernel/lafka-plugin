<?php
/**
 * AbandonedCartTest — locks down the Phase 3B (v9.27.0) abandoned-cart engine:
 *
 *   - DB schema contains every expected column (source-grep + functional)
 *   - Activation hook installs the table + schedules the cron events
 *   - Cron registers `every_fifteen_minutes` interval via cron_schedules
 *   - Cron handler skips rows where recovery_sent_at IS NOT NULL
 *   - Cron handler skips rows whose email is on the operator opt-out list
 *   - Cron handler skips rows already linked to an order
 *   - Resume URL is built from the row's token
 *   - Email class registers via woocommerce_email_classes filter
 *   - Customizer panel + section + every setting has default + sanitize_callback
 *   - Sanitizers reject malformed input
 *   - Main plugin file requires every conversion module
 *   - Activation + deactivation hooks present in main file
 *
 * Source-grep heavy: the modules use $wpdb globally + WC()->cart at runtime,
 * which aren't worth booting WP for in unit tests. We mock the in-process
 * helpers (token generation, opt-out parsing, eligibility) directly and grep
 * the rest.
 *
 * @package Lafka\Plugin\Tests\Unit
 * @since   9.27.0
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Lafka_Customizer_Abandoned_Cart;
use PHPUnit\Framework\TestCase;

// WC_Email stub must load before the email module so its class_exists guard sees it.
require_once dirname( __DIR__ ) . '/Unit/Stubs/wc-email-stub.php';

require_once dirname( __DIR__, 2 ) . '/incl/conversion/lafka-abandoned-cart-db.php';
require_once dirname( __DIR__, 2 ) . '/incl/conversion/lafka-abandoned-cart-cron.php';
require_once dirname( __DIR__, 2 ) . '/incl/conversion/lafka-abandoned-cart-email.php';
require_once dirname( __DIR__, 2 ) . '/incl/customizer/class-lafka-customizer-abandoned-cart.php';

final class AbandonedCartTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
		Functions\when( '_x' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_attr__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_html_e' )->alias(
			static function ( $text ) {
				echo $text;
			}
		);
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_textarea_field' )->returnArg();
		Functions\when( 'sanitize_email' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'get_theme_mod' )->returnArg( 2 );
		Functions\when( 'wp_salt' )->justReturn( 'unit-test-salt' );
		Functions\when( 'wp_generate_password' )->justReturn( 'PREVIEWTOKEN0000PREVIEWTOKEN0000' );
		Functions\when( 'is_email' )->alias(
			static function ( $email ) {
				return is_string( $email ) && false !== strpos( $email, '@' );
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ─────────────────────────────────────────────────────────────────────────
	// 1. DB schema (source-grep)
	// ─────────────────────────────────────────────────────────────────────────

	public function test_db_module_defines_table_name_helper(): void {
		$src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/conversion/lafka-abandoned-cart-db.php' );
		$this->assertStringContainsString( 'function lafka_ac_table_name', $src );
		$this->assertStringContainsString( 'lafka_abandoned_carts', $src );
	}

	public function test_schema_sql_contains_create_table_statement(): void {
		$sql = \lafka_ac_schema_sql();
		$this->assertStringContainsString( 'CREATE TABLE', $sql );
		$this->assertStringContainsString( 'lafka_abandoned_carts', $sql );
	}

	public function test_schema_sql_contains_every_expected_column(): void {
		$sql = \lafka_ac_schema_sql();
		$required = array(
			'id',
			'customer_email',
			'session_id',
			'resume_token',
			'cart_contents',
			'cart_total',
			'currency',
			'order_id',
			'recovery_sent_at',
			'created_at',
			'last_seen_at',
		);
		foreach ( $required as $column ) {
			$this->assertStringContainsString( $column, $sql, "Schema must include column {$column}" );
		}
	}

	public function test_schema_sql_declares_primary_key_and_indexes(): void {
		$sql = \lafka_ac_schema_sql();
		$this->assertStringContainsString( 'PRIMARY KEY', $sql );
		$this->assertStringContainsString( 'KEY customer_email', $sql );
		$this->assertStringContainsString( 'KEY session_id', $sql );
		$this->assertStringContainsString( 'KEY resume_token', $sql );
	}

	public function test_install_table_calls_dbdelta(): void {
		$src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/conversion/lafka-abandoned-cart-db.php' );
		$this->assertStringContainsString( 'dbDelta', $src );
		$this->assertStringContainsString( 'lafka_abandoned_cart_db_version', $src );
	}

	public function test_generate_resume_token_uses_wp_generate_password_when_available(): void {
		$src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/conversion/lafka-abandoned-cart-db.php' );
		$this->assertStringContainsString( 'wp_generate_password( 32, false, false )', $src );
	}

	public function test_generate_resume_token_returns_a_url_safe_string(): void {
		// With wp_generate_password stubbed in setUp, the function returns a
		// fixed alnum string. Confirm format expectations hold.
		$token = \lafka_ac_generate_resume_token();
		$this->assertIsString( $token );
		$this->assertGreaterThanOrEqual( 16, strlen( $token ) );
		$this->assertSame( 1, preg_match( '/^[A-Za-z0-9]+$/', $token ) );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// 2. Cron scheduling
	// ─────────────────────────────────────────────────────────────────────────

	public function test_cron_registers_every_fifteen_minutes_schedule(): void {
		$schedules = \lafka_ac_register_cron_schedule( array() );
		$this->assertArrayHasKey( 'every_fifteen_minutes', $schedules );
		$this->assertSame( 15 * 60, $schedules['every_fifteen_minutes']['interval'] );
		$this->assertArrayHasKey( 'display', $schedules['every_fifteen_minutes'] );
	}

	public function test_cron_preserves_existing_schedules(): void {
		$existing  = array(
			'hourly' => array(
				'interval' => 3600,
				'display'  => 'Hourly',
			),
		);
		$schedules = \lafka_ac_register_cron_schedule( $existing );
		$this->assertArrayHasKey( 'hourly', $schedules );
		$this->assertArrayHasKey( 'every_fifteen_minutes', $schedules );
	}

	public function test_cron_module_references_check_event_hook(): void {
		$src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/conversion/lafka-abandoned-cart-cron.php' );
		$this->assertStringContainsString( 'lafka_check_abandoned_carts', $src );
		$this->assertStringContainsString( 'lafka_cleanup_abandoned_carts', $src );
		$this->assertStringContainsString( 'wp_schedule_event', $src );
	}

	public function test_cron_get_delay_minutes_clamps_to_safe_window(): void {
		Functions\when( 'get_theme_mod' )->alias(
			static function ( $key, $default = null ) {
				return 'lafka_ac_delay_minutes' === $key ? 2 : $default;
			}
		);
		$this->assertGreaterThanOrEqual( 5, \lafka_ac_get_delay_minutes() );

		Functions\when( 'get_theme_mod' )->alias(
			static function ( $key, $default = null ) {
				return 'lafka_ac_delay_minutes' === $key ? 99999 : $default;
			}
		);
		$this->assertLessThanOrEqual( 1440, \lafka_ac_get_delay_minutes() );

		Functions\when( 'get_theme_mod' )->alias(
			static function ( $key, $default = null ) {
				return 'lafka_ac_delay_minutes' === $key ? 75 : $default;
			}
		);
		$this->assertSame( 75, \lafka_ac_get_delay_minutes() );
	}

	public function test_cron_get_opt_out_list_parses_textarea(): void {
		Functions\when( 'get_theme_mod' )->alias(
			static function ( $key, $default = null ) {
				return 'lafka_ac_global_opt_out' === $key
					? "alice@example.com\nbob@example.com\n  carol@example.com  "
					: $default;
			}
		);
		$list = \lafka_ac_get_opt_out_list();
		$this->assertContains( 'alice@example.com', $list );
		$this->assertContains( 'bob@example.com', $list );
		$this->assertContains( 'carol@example.com', $list );
	}

	public function test_cron_is_opted_out_matches_case_insensitively(): void {
		Functions\when( 'get_theme_mod' )->alias(
			static function ( $key, $default = null ) {
				return 'lafka_ac_global_opt_out' === $key
					? 'blocked@example.com'
					: $default;
			}
		);
		$this->assertTrue( \lafka_ac_is_opted_out( 'BLOCKED@example.com' ) );
		$this->assertFalse( \lafka_ac_is_opted_out( 'allowed@example.com' ) );
	}

	public function test_cron_row_is_eligible_rejects_already_sent(): void {
		$row = (object) array(
			'id'               => 1,
			'customer_email'   => 'alice@example.com',
			'recovery_sent_at' => '2026-05-18 12:00:00',
			'order_id'         => 0,
		);
		$this->assertFalse( \lafka_ac_row_is_eligible( $row ) );
	}

	public function test_cron_row_is_eligible_rejects_already_ordered(): void {
		$row = (object) array(
			'id'               => 1,
			'customer_email'   => 'alice@example.com',
			'recovery_sent_at' => null,
			'order_id'         => 1234,
		);
		$this->assertFalse( \lafka_ac_row_is_eligible( $row ) );
	}

	public function test_cron_row_is_eligible_rejects_opted_out_email(): void {
		Functions\when( 'get_theme_mod' )->alias(
			static function ( $key, $default = null ) {
				return 'lafka_ac_global_opt_out' === $key
					? 'blocked@example.com'
					: $default;
			}
		);
		$row = (object) array(
			'id'               => 1,
			'customer_email'   => 'blocked@example.com',
			'recovery_sent_at' => null,
			'order_id'         => 0,
		);
		$this->assertFalse( \lafka_ac_row_is_eligible( $row ) );
	}

	public function test_cron_row_is_eligible_accepts_pending_unopted_row(): void {
		Functions\when( 'get_theme_mod' )->alias(
			static function ( $key, $default = null ) {
				return $default;
			}
		);
		$row = (object) array(
			'id'               => 1,
			'customer_email'   => 'alice@example.com',
			'recovery_sent_at' => null,
			'order_id'         => 0,
		);
		$this->assertTrue( \lafka_ac_row_is_eligible( $row ) );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// 3. Resume URL + email rendering
	// ─────────────────────────────────────────────────────────────────────────

	public function test_resume_url_contains_token_query_arg(): void {
		Functions\when( 'home_url' )->justReturn( 'https://lafka.test/' );
		Functions\when( 'add_query_arg' )->alias(
			static function ( $args, $base ) {
				$pairs = array();
				foreach ( $args as $k => $v ) {
					$pairs[] = $k . '=' . rawurlencode( (string) $v );
				}
				$sep = ( false === strpos( $base, '?' ) ) ? '?' : '&';
				return $base . $sep . implode( '&', $pairs );
			}
		);
		$url = \lafka_ac_email_resume_url( 'TEST_TOKEN_1234567890ABCDEF' );
		$this->assertStringContainsString( 'lafka_resume_cart=', $url );
		$this->assertStringContainsString( 'TEST_TOKEN_1234567890ABCDEF', $url );
	}

	public function test_email_subject_default_substitutes_site_token(): void {
		Functions\when( 'get_bloginfo' )->justReturn( 'Peppery Pizza' );
		Functions\when( 'get_theme_mod' )->alias(
			static function ( $key, $default = null ) {
				return 'lafka_ac_subject' === $key
					? 'Your cart at {site} is waiting'
					: $default;
			}
		);
		$out = \lafka_ac_email_subject_default();
		$this->assertStringContainsString( 'Peppery Pizza', $out );
		$this->assertStringNotContainsString( '{site}', $out );
	}

	public function test_email_subject_default_fallback_when_unset(): void {
		Functions\when( 'get_bloginfo' )->justReturn( 'Sample Site' );
		Functions\when( 'get_theme_mod' )->alias(
			static function ( $key, $default = null ) {
				return $default;
			}
		);
		$out = \lafka_ac_email_subject_default();
		$this->assertNotEmpty( $out );
		$this->assertStringContainsString( 'Sample Site', $out );
	}

	public function test_email_intro_heading_returns_configured_value(): void {
		Functions\when( 'get_theme_mod' )->alias(
			static function ( $key, $default = null ) {
				return 'lafka_ac_intro_heading' === $key ? 'Come back!' : $default;
			}
		);
		$this->assertSame( 'Come back!', \lafka_ac_email_intro_heading() );
	}

	public function test_email_cta_label_returns_default_when_empty(): void {
		Functions\when( 'get_theme_mod' )->alias(
			static function ( $key, $default = null ) {
				return $default;
			}
		);
		$this->assertNotEmpty( \lafka_ac_email_cta_label() );
		$this->assertSame( 'Resume my order', \lafka_ac_email_cta_label() );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// 4. Email class registration
	// ─────────────────────────────────────────────────────────────────────────

	public function test_email_module_registers_wc_email_class_filter(): void {
		$src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/conversion/lafka-abandoned-cart-email.php' );
		$this->assertStringContainsString( "add_filter( 'woocommerce_email_classes'", $src );
		$this->assertStringContainsString( 'LAFKA_Abandoned_Cart_Email', $src );
	}

	public function test_email_class_file_extends_wc_email(): void {
		$src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/conversion/class-lafka-abandoned-cart-email-class.php' );
		$this->assertStringContainsString( 'class LAFKA_Abandoned_Cart_Email extends WC_Email', $src );
		$this->assertStringContainsString( 'add_action( \'lafka_abandoned_cart_email_trigger\'', $src );
	}

	public function test_email_class_uses_woocommerce_header_and_footer_actions(): void {
		$src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/conversion/lafka-abandoned-cart-email.php' );
		$this->assertStringContainsString( 'woocommerce_email_header', $src );
		$this->assertStringContainsString( 'woocommerce_email_footer', $src );
	}

	public function test_email_class_registration_filter_returns_array_with_new_class(): void {
		// WC_Email stub is required at file top so the class_exists guard in the
		// production module sees the stub and lazy-loads the subclass file.
		$classes = \lafka_ac_register_email_class( array() );
		$this->assertArrayHasKey( 'LAFKA_Abandoned_Cart_Email', $classes );
		$this->assertInstanceOf( 'LAFKA_Abandoned_Cart_Email', $classes['LAFKA_Abandoned_Cart_Email'] );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// 5. Customizer panel + sanitizers
	// ─────────────────────────────────────────────────────────────────────────

	public function test_customizer_registers_lafka_abandoned_cart_panel(): void {
		$src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/customizer/class-lafka-customizer-abandoned-cart.php' );
		$this->assertStringContainsString( 'add_panel', $src );
		$this->assertStringContainsString( "'lafka_abandoned_cart'", $src );
	}

	public function test_customizer_registers_all_required_settings(): void {
		$src      = file_get_contents( dirname( __DIR__, 2 ) . '/incl/customizer/class-lafka-customizer-abandoned-cart.php' );
		$required = array(
			'lafka_ac_enabled',
			'lafka_ac_delay_minutes',
			'lafka_ac_subject',
			'lafka_ac_intro_heading',
			'lafka_ac_intro_body',
			'lafka_ac_cta_label',
			'lafka_ac_global_opt_out',
		);
		foreach ( $required as $setting ) {
			$this->assertStringContainsString(
				"'" . $setting . "'",
				$src,
				"Customizer setting {$setting} must be registered."
			);
		}
	}

	public function test_every_customizer_setting_has_default_and_sanitize_callback(): void {
		$src             = file_get_contents( dirname( __DIR__, 2 ) . '/incl/customizer/class-lafka-customizer-abandoned-cart.php' );
		$default_count   = substr_count( $src, "'default'" );
		$sanitize_count  = substr_count( $src, "'sanitize_callback'" );
		$add_setting_cnt = substr_count( $src, '$wp_customize->add_setting' );
		$this->assertGreaterThanOrEqual( $add_setting_cnt, $default_count, 'Every add_setting() call must include a default.' );
		$this->assertGreaterThanOrEqual( $add_setting_cnt, $sanitize_count, 'Every add_setting() call must include a sanitize_callback.' );
	}

	public function test_sanitize_checkbox_normalises_truthy_input(): void {
		$this->assertSame( '1', Lafka_Customizer_Abandoned_Cart::sanitize_checkbox( '1' ) );
		$this->assertSame( '1', Lafka_Customizer_Abandoned_Cart::sanitize_checkbox( 1 ) );
		$this->assertSame( '1', Lafka_Customizer_Abandoned_Cart::sanitize_checkbox( true ) );
		$this->assertSame( '0', Lafka_Customizer_Abandoned_Cart::sanitize_checkbox( 'off' ) );
		$this->assertSame( '0', Lafka_Customizer_Abandoned_Cart::sanitize_checkbox( 0 ) );
		$this->assertSame( '0', Lafka_Customizer_Abandoned_Cart::sanitize_checkbox( '' ) );
	}

	public function test_sanitize_delay_minutes_clamps_low_and_high(): void {
		$this->assertSame( 5, Lafka_Customizer_Abandoned_Cart::sanitize_delay_minutes( 0 ) );
		$this->assertSame( 5, Lafka_Customizer_Abandoned_Cart::sanitize_delay_minutes( -10 ) );
		$this->assertSame( 1440, Lafka_Customizer_Abandoned_Cart::sanitize_delay_minutes( 99999 ) );
		$this->assertSame( 75, Lafka_Customizer_Abandoned_Cart::sanitize_delay_minutes( 75 ) );
	}

	public function test_sanitize_opt_out_list_keeps_only_valid_emails(): void {
		$input = "alice@example.com\nnot-an-email\nbob@example.com\n  CAROL@EXAMPLE.COM  ";
		$out   = Lafka_Customizer_Abandoned_Cart::sanitize_opt_out_list( $input );
		$this->assertStringContainsString( 'alice@example.com', $out );
		$this->assertStringContainsString( 'bob@example.com', $out );
		$this->assertStringContainsString( 'carol@example.com', $out );
		$this->assertStringNotContainsString( 'not-an-email', $out );
	}

	public function test_sanitize_opt_out_list_handles_non_scalar(): void {
		$this->assertSame( '', Lafka_Customizer_Abandoned_Cart::sanitize_opt_out_list( array( 'x@example.com' ) ) );
		$this->assertSame( '', Lafka_Customizer_Abandoned_Cart::sanitize_opt_out_list( null ) );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// 6. Capture + resume modules (source-grep)
	// ─────────────────────────────────────────────────────────────────────────

	public function test_capture_module_hooks_woocommerce_actions(): void {
		$src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/conversion/lafka-abandoned-cart-capture.php' );
		$this->assertStringContainsString( 'woocommerce_checkout_update_order_review', $src );
		$this->assertStringContainsString( 'woocommerce_checkout_order_processed', $src );
	}

	public function test_capture_module_cascades_account_deletion(): void {
		$src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/conversion/lafka-abandoned-cart-capture.php' );
		$this->assertStringContainsString( 'woocommerce_account_delete_completed', $src );
		$this->assertStringContainsString( 'delete_user', $src );
	}

	public function test_capture_self_gates_on_enabled_toggle(): void {
		$src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/conversion/lafka-abandoned-cart-capture.php' );
		$this->assertStringContainsString( 'lafka_ac_capture_is_enabled', $src );
		$this->assertStringContainsString( 'lafka_ac_enabled', $src );
	}

	public function test_resume_module_hooks_init_priority_5(): void {
		$src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/conversion/lafka-abandoned-cart-resume.php' );
		$this->assertStringContainsString( "add_action( 'init', 'lafka_ac_handle_resume_request', 5 )", $src );
	}

	public function test_resume_module_reads_get_token_and_restores_cart(): void {
		$src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/conversion/lafka-abandoned-cart-resume.php' );
		$this->assertStringContainsString( 'lafka_resume_cart', $src );
		$this->assertStringContainsString( 'add_to_cart', $src );
		$this->assertStringContainsString( 'wp_safe_redirect', $src );
	}

	public function test_resume_module_redirects_to_cart_after_restore(): void {
		$src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/conversion/lafka-abandoned-cart-resume.php' );
		$this->assertStringContainsString( 'wc_get_cart_url', $src );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// 7. Main plugin wiring
	// ─────────────────────────────────────────────────────────────────────────

	public function test_main_plugin_requires_all_conversion_modules(): void {
		$main = file_get_contents( dirname( __DIR__, 2 ) . '/lafka-plugin.php' );
		$this->assertStringContainsString( 'incl/conversion/lafka-abandoned-cart-db.php', $main );
		$this->assertStringContainsString( 'incl/conversion/lafka-abandoned-cart-capture.php', $main );
		$this->assertStringContainsString( 'incl/conversion/lafka-abandoned-cart-cron.php', $main );
		$this->assertStringContainsString( 'incl/conversion/lafka-abandoned-cart-email.php', $main );
		$this->assertStringContainsString( 'incl/conversion/lafka-abandoned-cart-resume.php', $main );
		$this->assertStringContainsString( 'incl/customizer/class-lafka-customizer-abandoned-cart.php', $main );
	}

	public function test_main_plugin_registers_activation_and_deactivation_hooks(): void {
		$main = file_get_contents( dirname( __DIR__, 2 ) . '/lafka-plugin.php' );
		$this->assertStringContainsString( 'register_activation_hook', $main );
		$this->assertStringContainsString( 'register_deactivation_hook', $main );
		$this->assertStringContainsString( 'lafka_ac_install_table', $main );
		$this->assertStringContainsString( 'lafka_ac_schedule_events', $main );
		$this->assertStringContainsString( 'lafka_ac_unschedule_events', $main );
	}

	public function test_main_plugin_version_bumped_to_at_least_9_27_0(): void {
		// Phase 3B shipped at 9.27.0; subsequent phases bump the same plugin
		// version forward (3D = 9.28.0). Assert presence of the Version: header
		// in a major.minor.patch shape ≥ 9.27.0 rather than pinning to one
		// release.
		$main = file_get_contents( dirname( __DIR__, 2 ) . '/lafka-plugin.php' );
		$this->assertMatchesRegularExpression( '/Version:\s*9\.(2[7-9]|[3-9]\d|\d{3,})\.\d+/', $main );
	}

	public function test_uninstall_drops_abandoned_cart_table(): void {
		$src = file_get_contents( dirname( __DIR__, 2 ) . '/uninstall.php' );
		$this->assertStringContainsString( 'lafka_abandoned_carts', $src );
		$this->assertStringContainsString( 'DROP TABLE IF EXISTS', $src );
		$this->assertStringContainsString( 'lafka_abandoned_cart_db_version', $src );
	}
}
