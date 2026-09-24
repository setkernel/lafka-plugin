<?php
/**
 * AbandonedCartTest — abandoned-cart recovery engine: row persistence against
 * the table schema, cron scheduling + eligibility (sent / converted / opted-out),
 * capture gating, conversion + account-deletion cascades, cart restore from a
 * resume link, recovery-email copy, and the Customizer sanitizers.
 *
 * @package Lafka\Plugin\Tests\Unit
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
// Capture defines lafka_ac_capture_is_enabled() — the gate the db/cron
// self-heals consult (bootstrap loads it in the same require block).
require_once dirname( __DIR__, 2 ) . '/incl/conversion/lafka-abandoned-cart-capture.php';
require_once dirname( __DIR__, 2 ) . '/incl/conversion/lafka-abandoned-cart-cron.php';
require_once dirname( __DIR__, 2 ) . '/incl/conversion/lafka-abandoned-cart-email.php';
require_once dirname( __DIR__, 2 ) . '/incl/conversion/lafka-abandoned-cart-resume.php';
require_once dirname( __DIR__, 2 ) . '/incl/customizer/class-lafka-customizer-abandoned-cart.php';

/**
 * Recording $wpdb double: get_var/get_row return canned values; insert/update/
 * delete calls are captured for assertion.
 */
class FakeAbandonedCartWpdb {

	public string $prefix = 'wp_';
	public int $insert_id = 0;
	/** @var mixed */
	public $get_var_return = 0;
	/** @var mixed */
	public $get_row_return = null;
	/** @var array<int,array<int,mixed>> */
	public array $prepared = array();
	/** @var array<string,array<int,array<string,mixed>>> */
	public array $calls = array(
		'insert'  => array(),
		'update'  => array(),
		'delete'  => array(),
		'get_row' => array(),
	);

	public function prepare( $sql, ...$args ) {
		$this->prepared[] = $args;
		return $sql;
	}

	public function get_var( $sql ) {
		return $this->get_var_return;
	}

	public function get_row( $sql ) {
		$this->calls['get_row'][] = array( 'sql' => $sql );
		return $this->get_row_return;
	}

	public function insert( $table, $data, $formats = null ) {
		$this->calls['insert'][] = array(
			'table' => $table,
			'data'  => $data,
		);
		$this->insert_id = 99;
		return 1;
	}

	public function update( $table, $data, $where, $formats = null, $where_formats = null ) {
		$this->calls['update'][] = array(
			'data'  => $data,
			'where' => $where,
		);
		return 1;
	}

	public function delete( $table, $where, $formats = null ) {
		$this->calls['delete'][] = array(
			'table' => $table,
			'where' => $where,
		);
		return 1;
	}
}

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
		Functions\when( 'is_email' )->alias(
			static function ( $email ) {
				return is_string( $email ) && false !== strpos( $email, '@' );
			}
		);
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		$_POST = array();
		$_GET  = array();
		Monkey\tearDown();
		parent::tearDown();
	}

	private function install_fake_wpdb(): FakeAbandonedCartWpdb {
		$wpdb            = new FakeAbandonedCartWpdb();
		$GLOBALS['wpdb'] = $wpdb;
		return $wpdb;
	}

	private function enable_module(): void {
		Functions\when( 'get_theme_mod' )->alias(
			static fn( $key, $default = '' ) => 'lafka_ac_enabled' === $key ? '1' : $default
		);
	}

	// ─────────────────────────────────────────────────────────────────────────
	// 1. Persistence
	// ─────────────────────────────────────────────────────────────────────────

	public function test_save_cart_inserts_only_columns_the_schema_defines(): void {
		$wpdb = $this->install_fake_wpdb();
		Functions\when( 'current_time' )->justReturn( '2026-06-28 12:00:00' );
		Functions\when( 'wp_generate_password' )->justReturn( str_repeat( 'a', 32 ) );

		$id = \lafka_ac_save_cart( 'alice@example.com', array( 'items' => array( array( 'product_id' => 1 ) ) ), 'sess-1', 12.5, 'USD' );

		$this->assertSame( 99, $id );
		$this->assertCount( 1, $wpdb->calls['insert'] );
		$row = $wpdb->calls['insert'][0]['data'];
		$this->assertSame( 'alice@example.com', $row['customer_email'] );
		$this->assertSame( 0, $row['order_id'] );
		$this->assertNull( $row['recovery_sent_at'] );

		// Every column the writer persists must exist in the CREATE TABLE, or the
		// insert fails on a real database.
		$sql     = \lafka_ac_schema_sql();
		$missing = array_values(
			array_filter(
				array_keys( $row ),
				static fn( $col ) => 1 !== preg_match( '/^\s+' . preg_quote( $col, '/' ) . '\s/m', $sql )
			)
		);
		$this->assertSame( array(), $missing, 'Inserted columns missing from lafka_ac_schema_sql().' );
	}

	public function test_save_cart_updates_the_pending_row_for_the_same_email_and_session(): void {
		$wpdb                 = $this->install_fake_wpdb();
		$wpdb->get_var_return = 7; // an existing pending row
		Functions\when( 'current_time' )->justReturn( '2026-06-28 12:00:00' );

		$id = \lafka_ac_save_cart( 'alice@example.com', array( 'items' => array( array( 'product_id' => 1 ) ) ), 'sess-1', 20.0 );

		$this->assertSame( 7, $id );
		$this->assertSame( array(), $wpdb->calls['insert'], 'A second capture must not create a duplicate row.' );
		$this->assertSame( array( 'id' => 7 ), $wpdb->calls['update'][0]['where'] );
	}

	public function test_resume_token_requests_32_url_safe_characters(): void {
		// The token is the only credential on the resume link: it must be long and
		// free of URL-hostile special characters.
		Functions\expect( 'wp_generate_password' )->once()->with( 32, false, false )->andReturn( 'TOKEN' );
		$this->assertSame( 'TOKEN', \lafka_ac_generate_resume_token() );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// 2. Cron scheduling
	// ─────────────────────────────────────────────────────────────────────────

	public function test_cron_adds_fifteen_minute_schedule_and_keeps_existing_ones(): void {
		$schedules = \lafka_ac_register_cron_schedule(
			array(
				'hourly' => array(
					'interval' => 3600,
					'display'  => 'Hourly',
				),
			)
		);
		$this->assertArrayHasKey( 'hourly', $schedules );
		$this->assertSame( 15 * 60, $schedules['every_fifteen_minutes']['interval'] );
	}

	public function test_schedule_events_stays_inert_when_capture_disabled(): void {
		// get_theme_mod default stub returns '0' → module OFF (its default).
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\expect( 'wp_schedule_event' )->never();

		\lafka_ac_schedule_events();
		$this->assertTrue( true ); // Mockery's never() above is the real assertion.
	}

	public function test_schedule_events_drops_stale_events_when_disabled(): void {
		Functions\when( 'wp_next_scheduled' )->justReturn( 1234567890 );
		Functions\expect( 'wp_schedule_event' )->never();
		$cleared = array();
		Functions\when( 'wp_clear_scheduled_hook' )->alias(
			static function ( $hook ) use ( &$cleared ) {
				$cleared[] = $hook;
			}
		);

		\lafka_ac_schedule_events();

		$this->assertContains( 'lafka_check_abandoned_carts', $cleared, 'A former opt-in must be unscheduled once the module is off.' );
	}

	public function test_schedule_events_schedules_when_capture_enabled(): void {
		Functions\when( 'get_theme_mod' )->alias(
			static fn( $key, $default = '' ) => 'lafka_ac_enabled' === $key ? '1' : $default
		);
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		$scheduled = array();
		Functions\when( 'wp_schedule_event' )->alias(
			static function ( $ts, $recurrence, $hook ) use ( &$scheduled ) {
				$scheduled[] = $hook;
				return true;
			}
		);

		\lafka_ac_schedule_events();

		$this->assertContains( 'lafka_check_abandoned_carts', $scheduled );
		$this->assertContains( 'lafka_cleanup_abandoned_carts', $scheduled );
	}

	public function test_maybe_install_table_inert_when_capture_disabled(): void {
		$read = array();
		Functions\when( 'get_option' )->alias(
			static function ( $key, $default = false ) use ( &$read ) {
				$read[] = $key;
				return $default;
			}
		);

		\lafka_ac_maybe_install_table();

		$this->assertNotContains( 'lafka_abandoned_cart_db_version', $read, 'Disabled module must not probe/install its table.' );
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
		Functions\when( 'get_bloginfo' )->justReturn( 'Example Restaurant' );
		Functions\when( 'get_theme_mod' )->alias(
			static function ( $key, $default = null ) {
				return 'lafka_ac_subject' === $key
					? 'Your cart at {site} is waiting'
					: $default;
			}
		);
		$out = \lafka_ac_email_subject_default();
		$this->assertStringContainsString( 'Example Restaurant', $out );
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

	public function test_email_class_registration_filter_returns_array_with_new_class(): void {
		// WC_Email stub is required at file top so the class_exists guard in the
		// production module sees the stub and lazy-loads the subclass file.
		$classes = \lafka_ac_register_email_class( array() );
		$this->assertArrayHasKey( 'LAFKA_Abandoned_Cart_Email', $classes );
		$this->assertInstanceOf( 'LAFKA_Abandoned_Cart_Email', $classes['LAFKA_Abandoned_Cart_Email'] );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// 5. Customizer sanitizers
	// ─────────────────────────────────────────────────────────────────────────

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
	// 6. Capture, conversion + deletion cascades, resume
	// ─────────────────────────────────────────────────────────────────────────

	public function test_capture_reads_the_email_from_the_checkout_review_payload(): void {
		Functions\when( 'is_email' )->justReturn( true );
		$_POST = array( 'post_data' => 'billing_first_name=Ann&billing_email=Guest%40Example.test' );
		$this->assertSame( 'guest@example.test', \lafka_ac_capture_from_post() );

		// Only the checkout form counts — not a stray top-level field.
		$_POST = array( 'email' => 'other@example.test' );
		$this->assertSame( '', \lafka_ac_capture_from_post() );
		$_POST = array();
	}

	public function test_checkout_capture_stores_nothing_while_module_is_disabled(): void {
		// Default-OFF module: typing an email on /checkout/ must not persist it.
		$wpdb  = $this->install_fake_wpdb();
		$_POST = array( 'post_data' => 'billing_email=guest%40example.test' );

		\lafka_ac_handle_update_order_review();

		$this->assertSame( array(), $wpdb->calls['insert'] );
		$this->assertSame( array(), $wpdb->calls['update'] );
	}

	public function test_placed_order_marks_the_pending_cart_row_recovered(): void {
		$this->enable_module();
		$wpdb                 = $this->install_fake_wpdb();
		$wpdb->get_var_return = 7;
		Functions\when( 'wc_get_order' )->justReturn(
			new class() {
				public function get_billing_email(): string {
					return 'Buyer@Example.test';
				}
			}
		);

		\lafka_ac_handle_order_processed( 55 );

		$this->assertSame( array( 'buyer@example.test' ), $wpdb->prepared[0], 'Row lookup must use the lowercased billing email.' );
		$this->assertSame(
			array(
				'data'  => array( 'order_id' => 55 ),
				'where' => array( 'id' => 7 ),
			),
			$wpdb->calls['update'][0],
			'The converted cart must be linked to the order so no recovery email goes out.'
		);
	}

	public function test_account_deletion_purges_the_users_cart_rows(): void {
		$wpdb = $this->install_fake_wpdb();
		Functions\when( 'get_userdata' )->justReturn( (object) array( 'user_email' => 'Member@Example.test' ) );

		\lafka_ac_handle_account_deleted( (object) array( 'user_email' => 'Guest@Example.test' ) );
		\lafka_ac_handle_account_deleted( 12 );
		\lafka_ac_handle_account_deleted( 0 );

		$this->assertSame(
			array(
				array( 'customer_email' => 'guest@example.test' ),
				array( 'customer_email' => 'member@example.test' ),
			),
			array_column( $wpdb->calls['delete'], 'where' )
		);
	}

	public function test_restore_cart_replaces_the_cart_with_the_saved_items(): void {
		$cart = new class() {
			public bool $emptied = false;
			/** @var array<int,array<int,int>> */
			public array $added = array();
			public function empty_cart(): void {
				$this->emptied = true;
			}
			public function add_to_cart( $product_id, $qty, $variation_id ) {
				$this->added[] = array( $product_id, $qty, $variation_id );
				return 'key';
			}
		};
		Functions\when( 'WC' )->justReturn( (object) array( 'cart' => $cart ) );

		\lafka_ac_restore_cart_from_payload(
			array(
				'items' => array(
					array(
						'product_id' => 10,
						'quantity'   => 2,
					),
					array(
						'product_id'   => 11,
						'variation_id' => 12,
						'quantity'     => 0,
					),
					array( 'product_id' => 0 ),
				),
			)
		);

		$this->assertTrue( $cart->emptied, 'Existing cart contents must not double up with the restored items.' );
		$this->assertSame( array( array( 10, 2, 0 ), array( 11, 1, 12 ) ), $cart->added );
	}

	public function test_resume_request_ignores_short_and_unknown_tokens(): void {
		$wpdb = $this->install_fake_wpdb();
		Functions\when( 'WC' )->justReturn( null );
		Functions\expect( 'wp_safe_redirect' )->never();

		$_GET = array( 'lafka_resume_cart' => 'short' );
		\lafka_ac_handle_resume_request();
		$this->assertSame( array(), $wpdb->calls['get_row'], 'Tokens under 16 chars must not hit the database.' );

		$_GET = array( 'lafka_resume_cart' => str_repeat( 'z', 32 ) );
		\lafka_ac_handle_resume_request(); // get_row returns null → no restore, no redirect.
		$this->assertCount( 1, $wpdb->calls['get_row'] );
	}

	/**
	 * The resume handler runs after WooCommerce loads the session cart
	 * (`wp_loaded` 10). On `init` a guest's restored cart was never persisted:
	 * WC sets its session/cart cookies only once `wp_loaded` has fired.
	 */
	public function test_resume_handler_runs_after_wc_loads_the_session_cart(): void {
		$GLOBALS['lafka_test_hooks'] = array();
		include dirname( __DIR__, 2 ) . '/incl/conversion/lafka-abandoned-cart-resume.php'; // Functions are guarded; re-runs the add_action().
		$this->assertSame( array( array( 'wp_loaded', 'lafka_ac_handle_resume_request', 20, 1 ) ), $GLOBALS['lafka_test_hooks'] );
	}

	/**
	 * A valid token restores the saved cart and redirects to it; a converted
	 * row or an empty payload redirects without touching the cart. The
	 * `lafka_ac_resume_redirect_exit` seam keeps the request alive here.
	 */
	public function test_resume_request_restores_the_cart_then_redirects(): void {
		$wpdb = $this->install_fake_wpdb();
		$cart = new class() {
			/** @var array<int,array<int,int>> */
			public array $added = array();
			public int $emptied = 0;
			public function empty_cart(): void {
				++$this->emptied;
			}
			public function add_to_cart( $product_id, $qty, $variation_id ) {
				$this->added[] = array( $product_id, $qty, $variation_id );
				return 'key';
			}
		};
		Functions\when( 'WC' )->justReturn( (object) array( 'cart' => $cart ) );
		Functions\when( 'wc_get_cart_url' )->justReturn( 'https://example.test/cart/' );
		Functions\when( 'apply_filters' )->alias(
			static fn( $hook, $value ) => 'lafka_ac_resume_redirect_exit' === $hook ? false : $value
		);
		$redirects = array();
		Functions\when( 'wp_safe_redirect' )->alias(
			static function ( $url, $status ) use ( &$redirects ) {
				$redirects[] = array( $url, $status );
			}
		);
		$_GET = array( 'lafka_resume_cart' => str_repeat( 'a', 32 ) );

		$wpdb->get_row_return = (object) array(
			'order_id'      => 0,
			'cart_contents' => json_encode(
				array(
					'items' => array(
						array(
							'product_id' => 10,
							'quantity'   => 2,
						),
					),
				)
			),
		);
		\lafka_ac_handle_resume_request();
		$this->assertSame( array( array( 10, 2, 0 ) ), $cart->added );
		$this->assertSame( array( array( 'https://example.test/cart/', 302 ) ), $redirects );

		// Already converted: onward to the cart, nothing restored.
		$wpdb->get_row_return->order_id = 55;
		\lafka_ac_handle_resume_request();
		// Empty payload: the same.
		$wpdb->get_row_return = (object) array(
			'order_id'      => 0,
			'cart_contents' => '{"items":[]}',
		);
		\lafka_ac_handle_resume_request();

		$this->assertSame( 1, $cart->emptied );
		$this->assertCount( 3, $redirects );
	}
}
