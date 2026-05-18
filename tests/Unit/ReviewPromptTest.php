<?php
/**
 * ReviewPromptTest — locks down the Phase 3D (v9.28.0) post-purchase review
 * prompt engine across all three layers:
 *
 *   - WC_Email subclass registers via woocommerce_email_classes filter
 *   - Cron scheduler hooks woocommerce_order_status_completed and skips
 *     orders without billing_email / with _lafka_review_email_sent / with
 *     user-level opt-out
 *   - 24-hour scheduling math correct (configurable hours → seconds → time())
 *   - Subject template substitutes {firstname} + {site}
 *   - Star tap row produces 5 links with rating=1..5 query params
 *   - REST routes registered + permission_callback gating works
 *   - Banner cookie set when user has in-window completed order, cleared
 *     when dismissed, never set for guests
 *   - Banner-shown endpoint rate-limited via transient
 *   - Unsubscribe URL produces stable HMAC token; tampering rejected
 *   - Customizer panel + section + every setting has default + sanitize_callback
 *   - Main plugin file requires all three new modules
 *
 * Source-grep where booting WP would cost more than the test signals; otherwise
 * Brain Monkey stubs the WP/WC functions the helpers call.
 *
 * @package Lafka\Plugin\Tests\Unit
 * @since   9.28.0
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Lafka_Customizer_Reviews;
use PHPUnit\Framework\TestCase;

// WC_Email stub must load before the email module so its class_exists guard sees it.
require_once dirname( __DIR__ ) . '/Unit/Stubs/wc-email-stub.php';

// Mark the runtime as test mode so wp_safe_redirect in the unsubscribe handler
// doesn't exit() and end the PHPUnit process. The production guard is `! defined`,
// so defining it BEFORE requiring the source files is the only window we can
// flip it.
if ( ! defined( 'LAFKA_TESTING' ) ) {
	define( 'LAFKA_TESTING', true );
}

if ( ! defined( 'HOUR_IN_SECONDS' ) ) {
	define( 'HOUR_IN_SECONDS', 3600 );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}
if ( ! defined( 'MINUTE_IN_SECONDS' ) ) {
	define( 'MINUTE_IN_SECONDS', 60 );
}

require_once dirname( __DIR__, 2 ) . '/incl/conversion/lafka-review-prompt-email.php';
require_once dirname( __DIR__, 2 ) . '/incl/conversion/lafka-review-prompt-banner.php';
require_once dirname( __DIR__, 2 ) . '/incl/customizer/class-lafka-customizer-reviews.php';

/**
 * Lightweight WC_Order stand-in for tests — implements the methods the renderer
 * + scheduler + skip-helper actually call. Tracks update_meta_data writes so a
 * test can assert idempotence flips.
 */
class FakeOrder {

	public string $billing_email      = 'alice@example.com';
	public string $billing_first_name = 'Alice';
	public int $customer_id           = 42;
	public int $id                    = 1001;
	public string $order_number       = '1001';
	public array $meta                = array();
	/** @var array<int, FakeOrderItem> */
	public array $items = array();
	public bool $saved  = false;

	public function get_billing_email(): string {
		return $this->billing_email;
	}
	public function get_billing_first_name(): string {
		return $this->billing_first_name;
	}
	public function get_customer_id(): int {
		return $this->customer_id;
	}
	public function get_id(): int {
		return $this->id;
	}
	public function get_order_number(): string {
		return $this->order_number;
	}
	public function get_meta( string $key, bool $single = true ): string {
		return isset( $this->meta[ $key ] ) ? (string) $this->meta[ $key ] : '';
	}
	public function update_meta_data( string $key, $value ): void {
		$this->meta[ $key ] = (string) $value;
	}
	public function save(): void {
		$this->saved = true;
	}
	public function get_items(): array {
		return $this->items;
	}
	public function get_date_completed() {
		return new FakeDateTime( time() - DAY_IN_SECONDS ); // 1 day ago
	}
	public function get_date_modified() {
		return new FakeDateTime( time() - DAY_IN_SECONDS );
	}
}

final class FakeOrderItem {
	public int $product_id = 0;
	public function __construct( int $pid ) {
		$this->product_id = $pid;
	}
	public function get_product_id(): int {
		return $this->product_id;
	}
}

final class FakeDateTime {
	public int $ts = 0;
	public function __construct( int $ts ) {
		$this->ts = $ts;
	}
	public function getTimestamp(): int {
		return $this->ts;
	}
}

final class ReviewPromptTest extends TestCase {

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
		Functions\when( 'esc_url_raw' )->returnArg();
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
		Functions\when( 'get_bloginfo' )->justReturn( 'Test Site' );
		Functions\when( 'home_url' )->alias(
			static function ( $path = '/' ) {
				return 'https://lafka.test' . ( '' === $path ? '/' : $path );
			}
		);
		Functions\when( 'add_query_arg' )->alias(
			static function ( $args, $base = '' ) {
				if ( ! is_array( $args ) ) {
					return (string) $base;
				}
				$pairs = array();
				foreach ( $args as $k => $v ) {
					$pairs[] = $k . '=' . rawurlencode( (string) $v );
				}
				$sep = ( false === strpos( (string) $base, '?' ) ) ? '?' : '&';
				return (string) $base . $sep . implode( '&', $pairs );
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	private function stub_theme_mods( array $values ): void {
		Functions\when( 'get_theme_mod' )->alias(
			static function ( $key, $default = null ) use ( $values ) {
				return array_key_exists( $key, $values ) ? $values[ $key ] : $default;
			}
		);
	}

	// ─────────────────────────────────────────────────────────────────────────
	// 1. WC_Email registration
	// ─────────────────────────────────────────────────────────────────────────

	public function test_email_module_registers_wc_email_class_filter(): void {
		$src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/conversion/lafka-review-prompt-email.php' );
		$this->assertStringContainsString( "add_filter( 'woocommerce_email_classes'", $src );
		$this->assertStringContainsString( 'LAFKA_Review_Prompt_Email', $src );
	}

	public function test_email_class_file_extends_wc_email_and_binds_trigger(): void {
		$src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/conversion/class-lafka-review-prompt-email-class.php' );
		$this->assertStringContainsString( 'class LAFKA_Review_Prompt_Email extends WC_Email', $src );
		$this->assertStringContainsString( "add_action( 'lafka_review_prompt_email_trigger'", $src );
	}

	public function test_email_class_registration_filter_returns_array_with_new_class(): void {
		$classes = \lafka_review_email_register_class( array() );
		$this->assertArrayHasKey( 'LAFKA_Review_Prompt_Email', $classes );
		$this->assertInstanceOf( 'LAFKA_Review_Prompt_Email', $classes['LAFKA_Review_Prompt_Email'] );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// 2. Scheduling + cron + idempotence
	// ─────────────────────────────────────────────────────────────────────────

	public function test_scheduler_module_hooks_woocommerce_order_status_completed(): void {
		$src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/conversion/lafka-review-prompt-email.php' );
		$this->assertStringContainsString( "add_action( 'woocommerce_order_status_completed', 'lafka_review_email_schedule_for_order'", $src );
		$this->assertStringContainsString( "add_action( 'lafka_send_review_email', 'lafka_review_email_run_cron'", $src );
	}

	public function test_email_uses_wp_schedule_single_event(): void {
		$src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/conversion/lafka-review-prompt-email.php' );
		$this->assertStringContainsString( 'wp_schedule_single_event', $src );
	}

	public function test_delay_hours_clamps_to_safe_window(): void {
		$this->stub_theme_mods( array( 'lafka_review_email_delay_hours' => 0 ) );
		$this->assertGreaterThanOrEqual( 1, \lafka_review_email_delay_hours() );

		$this->stub_theme_mods( array( 'lafka_review_email_delay_hours' => 99999 ) );
		$this->assertLessThanOrEqual( 336, \lafka_review_email_delay_hours() );

		$this->stub_theme_mods( array( 'lafka_review_email_delay_hours' => 24 ) );
		$this->assertSame( 24, \lafka_review_email_delay_hours() );
	}

	public function test_scheduler_skips_when_feature_disabled(): void {
		$this->stub_theme_mods( array( 'lafka_review_email_enabled' => '0' ) );
		$scheduled = false;
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_schedule_single_event' )->alias(
			static function () use ( &$scheduled ) {
				$scheduled = true;
				return true;
			}
		);
		Functions\when( 'wc_get_order' )->alias(
			static function ( $id ) {
				return new FakeOrder();
			}
		);
		\lafka_review_email_schedule_for_order( 1001 );
		$this->assertFalse( $scheduled, 'Scheduler must no-op when toggle is OFF.' );
	}

	public function test_scheduler_schedules_event_24h_when_enabled(): void {
		$this->stub_theme_mods(
			array(
				'lafka_review_email_enabled'     => '1',
				'lafka_review_email_delay_hours' => 24,
			)
		);
		$captured_when = 0;
		$captured_hook = '';
		$captured_args = array();
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_schedule_single_event' )->alias(
			static function ( $when, $hook, $args ) use ( &$captured_when, &$captured_hook, &$captured_args ) {
				$captured_when = (int) $when;
				$captured_hook = (string) $hook;
				$captured_args = (array) $args;
				return true;
			}
		);
		Functions\when( 'wc_get_order' )->alias(
			static function ( $id ) {
				return new FakeOrder();
			}
		);
		$before = time();
		\lafka_review_email_schedule_for_order( 1001 );
		$after = time() + 1;

		$this->assertSame( 'lafka_send_review_email', $captured_hook );
		$this->assertSame( array( 1001 ), $captured_args );
		$this->assertGreaterThanOrEqual( $before + 24 * 3600, $captured_when );
		$this->assertLessThanOrEqual( $after + 24 * 3600, $captured_when );
	}

	public function test_scheduler_bails_when_already_sent(): void {
		$this->stub_theme_mods( array( 'lafka_review_email_enabled' => '1' ) );
		$scheduled = false;
		Functions\when( 'wp_next_scheduled' )->justReturn( false );
		Functions\when( 'wp_schedule_single_event' )->alias(
			static function () use ( &$scheduled ) {
				$scheduled = true;
				return true;
			}
		);
		$order = new FakeOrder();
		$order->meta['_lafka_review_email_sent'] = (string) time();
		Functions\when( 'wc_get_order' )->alias(
			static function ( $id ) use ( $order ) {
				return $order;
			}
		);
		\lafka_review_email_schedule_for_order( $order->id );
		$this->assertFalse( $scheduled, 'Already-sent meta must veto re-scheduling.' );
	}

	public function test_scheduler_bails_when_event_already_scheduled(): void {
		$this->stub_theme_mods( array( 'lafka_review_email_enabled' => '1' ) );
		$scheduled = false;
		Functions\when( 'wp_next_scheduled' )->justReturn( time() + 3600 ); // already in queue
		Functions\when( 'wp_schedule_single_event' )->alias(
			static function () use ( &$scheduled ) {
				$scheduled = true;
				return true;
			}
		);
		Functions\when( 'wc_get_order' )->alias(
			static function ( $id ) {
				return new FakeOrder();
			}
		);
		\lafka_review_email_schedule_for_order( 1001 );
		$this->assertFalse( $scheduled, 'Already-scheduled events must not double-up.' );
	}

	public function test_should_skip_order_returns_true_when_user_opted_out(): void {
		Functions\when( 'get_user_meta' )->alias(
			static function ( $user_id, $key, $single = true ) {
				return ( '_lafka_review_email_optout' === $key && 42 === (int) $user_id ) ? '1' : '';
			}
		);
		$order = new FakeOrder();
		$this->assertTrue( \lafka_review_email_should_skip_order( $order ) );
	}

	public function test_should_skip_order_returns_true_when_already_sent(): void {
		Functions\when( 'get_user_meta' )->justReturn( '' );
		$order = new FakeOrder();
		$order->meta['_lafka_review_email_sent'] = (string) time();
		$this->assertTrue( \lafka_review_email_should_skip_order( $order ) );
	}

	public function test_should_skip_order_returns_true_when_no_email(): void {
		Functions\when( 'get_user_meta' )->justReturn( '' );
		$order                = new FakeOrder();
		$order->billing_email = '';
		$this->assertTrue( \lafka_review_email_should_skip_order( $order ) );
	}

	public function test_should_skip_order_returns_false_for_eligible_order(): void {
		Functions\when( 'get_user_meta' )->justReturn( '' );
		$order = new FakeOrder();
		$this->assertFalse( \lafka_review_email_should_skip_order( $order ) );
	}

	public function test_cron_handler_no_ops_when_disabled(): void {
		$this->stub_theme_mods( array( 'lafka_review_email_enabled' => '0' ) );
		Functions\when( 'wc_get_order' )->alias(
			static function ( $id ) {
				return new FakeOrder();
			}
		);
		$triggered = 0;
		Functions\when( 'do_action' )->alias(
			static function ( $hook ) use ( &$triggered ) {
				if ( 'lafka_review_prompt_email_trigger' === $hook ) {
					$triggered++;
				}
			}
		);
		\lafka_review_email_run_cron( 1001 );
		$this->assertSame( 0, $triggered );
	}

	public function test_cron_handler_triggers_email_action(): void {
		$this->stub_theme_mods( array( 'lafka_review_email_enabled' => '1' ) );
		Functions\when( 'wc_get_order' )->alias(
			static function ( $id ) {
				return new FakeOrder();
			}
		);
		Functions\when( 'get_user_meta' )->justReturn( '' );
		$triggered_with = null;
		Functions\when( 'do_action' )->alias(
			static function ( $hook, $arg = null ) use ( &$triggered_with ) {
				if ( 'lafka_review_prompt_email_trigger' === $hook ) {
					$triggered_with = $arg;
				}
			}
		);
		\lafka_review_email_run_cron( 1001 );
		$this->assertSame( 1001, $triggered_with );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// 3. Subject / token substitution / star tap URL
	// ─────────────────────────────────────────────────────────────────────────

	public function test_subject_default_falls_back_to_default_when_unset(): void {
		$this->stub_theme_mods( array() );
		$this->assertSame( 'How was your order, {firstname}?', \lafka_review_email_subject_default() );
	}

	public function test_subject_default_honours_customizer_override(): void {
		$this->stub_theme_mods( array( 'lafka_review_email_subject' => 'Rate us, {firstname}!' ) );
		$this->assertSame( 'Rate us, {firstname}!', \lafka_review_email_subject_default() );
	}

	public function test_email_class_get_subject_substitutes_firstname_and_site(): void {
		$this->stub_theme_mods( array( 'lafka_review_email_subject' => 'Hi {firstname}, how was {site}?' ) );
		$email         = new \LAFKA_Review_Prompt_Email();
		$email->object = new FakeOrder();
		$out           = $email->get_subject();
		$this->assertStringContainsString( 'Alice', $out );
		$this->assertStringContainsString( 'Test Site', $out );
		$this->assertStringNotContainsString( '{firstname}', $out );
		$this->assertStringNotContainsString( '{site}', $out );
	}

	public function test_email_class_get_subject_falls_back_to_there_when_no_firstname(): void {
		$this->stub_theme_mods( array( 'lafka_review_email_subject' => 'Hi {firstname}!' ) );
		$order                     = new FakeOrder();
		$order->billing_first_name = '';
		$email                     = new \LAFKA_Review_Prompt_Email();
		$email->object             = $order;
		$out                       = $email->get_subject();
		$this->assertStringContainsString( 'there', $out );
	}

	public function test_resolve_target_url_appends_rating_param_when_configured(): void {
		$this->stub_theme_mods( array( 'lafka_review_target_url' => 'https://g.page/r/CXX/review' ) );
		$url = \lafka_review_email_resolve_target_url( 3, new FakeOrder() );
		$this->assertStringContainsString( 'rating=3', $url );
		$this->assertStringContainsString( 'g.page/r/CXX/review', $url );
	}

	public function test_resolve_target_url_clamps_rating_to_1_5(): void {
		$this->stub_theme_mods( array( 'lafka_review_target_url' => 'https://example.com/r' ) );
		$this->assertStringContainsString( 'rating=1', \lafka_review_email_resolve_target_url( -3, new FakeOrder() ) );
		$this->assertStringContainsString( 'rating=5', \lafka_review_email_resolve_target_url( 9, new FakeOrder() ) );
	}

	public function test_resolve_target_url_falls_back_to_product_when_url_unset(): void {
		$this->stub_theme_mods( array() );
		Functions\when( 'get_permalink' )->alias(
			static function ( $id ) {
				return 'https://lafka.test/product/' . $id;
			}
		);
		$order          = new FakeOrder();
		$order->items[] = new FakeOrderItem( 555 );
		$url            = \lafka_review_email_resolve_target_url( 5, $order );
		$this->assertStringContainsString( '/product/555', $url );
		$this->assertStringContainsString( '#reviews', $url );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// 4. Unsubscribe token + handler
	// ─────────────────────────────────────────────────────────────────────────

	public function test_unsubscribe_token_is_deterministic_and_user_specific(): void {
		$t1  = \lafka_review_email_unsubscribe_token( 42 );
		$t1b = \lafka_review_email_unsubscribe_token( 42 );
		$t2  = \lafka_review_email_unsubscribe_token( 43 );
		$this->assertNotEmpty( $t1 );
		$this->assertSame( $t1, $t1b );
		$this->assertNotSame( $t1, $t2 );
	}

	public function test_unsubscribe_token_empty_for_invalid_user_id(): void {
		$this->assertSame( '', \lafka_review_email_unsubscribe_token( 0 ) );
		$this->assertSame( '', \lafka_review_email_unsubscribe_token( -1 ) );
	}

	public function test_unsubscribe_url_contains_token_and_user_id(): void {
		$url = \lafka_review_email_unsubscribe_url( 42 );
		$this->assertStringContainsString( 'lafka_unsubscribe_reviews=', $url );
		$this->assertStringContainsString( 'u=42', $url );
	}

	public function test_unsubscribe_handler_flips_meta_on_valid_token(): void {
		$user_id   = 42;
		$valid_tok = \lafka_review_email_unsubscribe_token( $user_id );
		$updated   = array();
		Functions\when( 'update_user_meta' )->alias(
			static function ( $uid, $key, $val ) use ( &$updated ) {
				$updated[ $key ] = array( $uid, $val );
				return true;
			}
		);
		Functions\when( 'wp_safe_redirect' )->justReturn( true );
		$_GET['lafka_unsubscribe_reviews'] = $valid_tok;
		$_GET['u']                         = (string) $user_id;
		\lafka_review_email_handle_unsubscribe_request();
		unset( $_GET['lafka_unsubscribe_reviews'], $_GET['u'] );
		$this->assertArrayHasKey( '_lafka_review_email_optout', $updated );
		$this->assertSame( $user_id, $updated['_lafka_review_email_optout'][0] );
		$this->assertSame( '1', $updated['_lafka_review_email_optout'][1] );
	}

	public function test_unsubscribe_handler_rejects_tampered_token(): void {
		$updated = false;
		Functions\when( 'update_user_meta' )->alias(
			static function () use ( &$updated ) {
				$updated = true;
				return true;
			}
		);
		Functions\when( 'wp_safe_redirect' )->justReturn( true );
		$_GET['lafka_unsubscribe_reviews'] = 'totally-bogus-token-0000';
		$_GET['u']                         = '42';
		\lafka_review_email_handle_unsubscribe_request();
		unset( $_GET['lafka_unsubscribe_reviews'], $_GET['u'] );
		$this->assertFalse( $updated );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// 5. Banner — cookie gating
	// ─────────────────────────────────────────────────────────────────────────

	public function test_banner_should_not_set_cookie_for_guests(): void {
		$this->stub_theme_mods( array( 'lafka_review_banner_enabled' => '1' ) );
		$this->assertFalse( \lafka_review_banner_should_set_cookie( 0 ) );
	}

	public function test_banner_should_not_set_cookie_when_feature_disabled(): void {
		$this->stub_theme_mods( array( 'lafka_review_banner_enabled' => '0' ) );
		$this->assertFalse( \lafka_review_banner_should_set_cookie( 42 ) );
	}

	public function test_banner_should_not_set_cookie_when_user_dismissed(): void {
		$this->stub_theme_mods( array( 'lafka_review_banner_enabled' => '1' ) );
		Functions\when( 'get_user_meta' )->alias(
			static function ( $uid, $key, $single = true ) {
				return '_lafka_review_banner_dismissed' === $key ? (string) time() : '';
			}
		);
		Functions\when( 'wc_get_orders' )->justReturn( array() );
		$this->assertFalse( \lafka_review_banner_should_set_cookie( 42 ) );
	}

	public function test_banner_should_set_cookie_when_order_in_window(): void {
		$this->stub_theme_mods(
			array(
				'lafka_review_banner_enabled'     => '1',
				'lafka_review_banner_window_days' => 7,
			)
		);
		Functions\when( 'get_user_meta' )->justReturn( '' );
		Functions\when( 'wc_get_orders' )->alias(
			static function () {
				return array( new FakeOrder() );
			}
		);
		$this->assertTrue( \lafka_review_banner_should_set_cookie( 42 ) );
	}

	public function test_banner_should_not_set_cookie_when_order_outside_window(): void {
		$this->stub_theme_mods(
			array(
				'lafka_review_banner_enabled'     => '1',
				'lafka_review_banner_window_days' => 1, // 1 day only
			)
		);
		Functions\when( 'get_user_meta' )->justReturn( '' );
		Functions\when( 'wc_get_orders' )->alias(
			static function () {
				return array(
					new class() extends FakeOrder {
						public function get_date_completed() {
							return new FakeDateTime( time() - ( 5 * DAY_IN_SECONDS ) );
						}
						public function get_date_modified() {
							return new FakeDateTime( time() - ( 5 * DAY_IN_SECONDS ) );
						}
					},
				);
			}
		);
		$this->assertFalse( \lafka_review_banner_should_set_cookie( 42 ) );
	}

	public function test_banner_should_clear_cookie_when_user_dismissed(): void {
		$this->stub_theme_mods( array( 'lafka_review_banner_enabled' => '1' ) );
		Functions\when( 'get_user_meta' )->alias(
			static function ( $uid, $key, $single = true ) {
				return '_lafka_review_banner_dismissed' === $key ? (string) time() : '';
			}
		);
		Functions\when( 'wc_get_orders' )->justReturn( array() );
		$this->assertTrue( \lafka_review_banner_should_clear_cookie( 42 ) );
	}

	public function test_banner_window_days_clamps_to_safe_range(): void {
		$this->stub_theme_mods( array( 'lafka_review_banner_window_days' => 0 ) );
		$this->assertSame( 1, \lafka_review_banner_window_days() );
		$this->stub_theme_mods( array( 'lafka_review_banner_window_days' => 999 ) );
		$this->assertSame( 30, \lafka_review_banner_window_days() );
		$this->stub_theme_mods( array( 'lafka_review_banner_window_days' => 7 ) );
		$this->assertSame( 7, \lafka_review_banner_window_days() );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// 6. Banner REST endpoints
	// ─────────────────────────────────────────────────────────────────────────

	public function test_banner_module_registers_rest_routes_under_lafka_v1(): void {
		$src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/conversion/lafka-review-prompt-banner.php' );
		$this->assertStringContainsString( 'register_rest_route', $src );
		$this->assertStringContainsString( "'lafka/v1'", $src );
		$this->assertStringContainsString( '/review-banner-dismiss', $src );
		$this->assertStringContainsString( '/review-banner-shown', $src );
	}

	public function test_dismiss_permission_rejects_guests(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( false );
		$result = \lafka_review_banner_rest_dismiss_permission();
		$this->assertInstanceOf( 'WP_Error', $result );
	}

	public function test_dismiss_permission_accepts_logged_in_with_read_cap(): void {
		Functions\when( 'is_user_logged_in' )->justReturn( true );
		Functions\when( 'current_user_can' )->alias(
			static function ( $cap ) {
				return 'read' === $cap;
			}
		);
		$this->assertTrue( \lafka_review_banner_rest_dismiss_permission() );
	}

	public function test_dismiss_handler_flips_user_meta(): void {
		Functions\when( 'get_current_user_id' )->justReturn( 77 );
		$captured = null;
		Functions\when( 'update_user_meta' )->alias(
			static function ( $uid, $key, $val ) use ( &$captured ) {
				$captured = array( $uid, $key, $val );
				return true;
			}
		);
		Functions\when( 'is_ssl' )->justReturn( false );
		// Note: headers_sent is a PHP internal that Patchwork can't redefine,
		// so we don't stub it. The dismiss handler's setcookie call uses the
		// `@` operator to suppress headers-already-sent warnings in test
		// environments — production paths run before PHP emits output.
		$result = \lafka_review_banner_rest_dismiss( null );
		$this->assertSame( 77, $captured[0] );
		$this->assertSame( '_lafka_review_banner_dismissed', $captured[1] );
		$this->assertTrue( $result['dismissed'] );
	}

	public function test_shown_handler_returns_ok_on_first_call(): void {
		Functions\when( 'get_transient' )->justReturn( false );
		Functions\when( 'set_transient' )->justReturn( true );
		$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
		$result                 = \lafka_review_banner_rest_shown( null );
		unset( $_SERVER['REMOTE_ADDR'] );
		$this->assertIsArray( $result );
		$this->assertTrue( $result['ok'] );
	}

	public function test_shown_handler_returns_rate_limited_when_transient_exists(): void {
		Functions\when( 'get_transient' )->justReturn( 1 );
		Functions\when( 'set_transient' )->justReturn( true );
		$_SERVER['REMOTE_ADDR'] = '127.0.0.1';
		$result                 = \lafka_review_banner_rest_shown( null );
		unset( $_SERVER['REMOTE_ADDR'] );
		// Either WP_REST_Response (when class exists) or plain array.
		if ( is_object( $result ) && method_exists( $result, 'get_status' ) ) {
			$this->assertSame( 429, $result->get_status() );
		} else {
			$this->assertFalse( $result['ok'] );
			$this->assertSame( 'rate_limited', $result['code'] );
		}
	}

	// ─────────────────────────────────────────────────────────────────────────
	// 7. Customizer panel + sanitizers
	// ─────────────────────────────────────────────────────────────────────────

	public function test_customizer_registers_lafka_reviews_panel(): void {
		$src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/customizer/class-lafka-customizer-reviews.php' );
		$this->assertStringContainsString( 'add_panel', $src );
		$this->assertStringContainsString( "'lafka_reviews'", $src );
	}

	public function test_customizer_registers_all_required_settings(): void {
		$src      = file_get_contents( dirname( __DIR__, 2 ) . '/incl/customizer/class-lafka-customizer-reviews.php' );
		$required = array(
			'lafka_review_email_enabled',
			'lafka_review_email_delay_hours',
			'lafka_review_email_subject',
			'lafka_review_email_intro',
			'lafka_review_target_url',
			'lafka_review_target_label',
			'lafka_review_banner_enabled',
			'lafka_review_banner_window_days',
			'lafka_review_banner_copy',
			'lafka_review_banner_cta_label',
		);
		foreach ( $required as $setting ) {
			$this->assertStringContainsString( "'" . $setting . "'", $src, "Setting {$setting} must register." );
		}
	}

	public function test_every_customizer_setting_has_default_and_sanitize_callback(): void {
		$src             = file_get_contents( dirname( __DIR__, 2 ) . '/incl/customizer/class-lafka-customizer-reviews.php' );
		$default_count   = substr_count( $src, "'default'" );
		$sanitize_count  = substr_count( $src, "'sanitize_callback'" );
		$add_setting_cnt = substr_count( $src, '$wp_customize->add_setting' );
		$this->assertGreaterThanOrEqual( $add_setting_cnt, $default_count, 'Every add_setting() must include a default.' );
		$this->assertGreaterThanOrEqual( $add_setting_cnt, $sanitize_count, 'Every add_setting() must include a sanitize_callback.' );
	}

	public function test_sanitize_checkbox_normalises_truthy_input(): void {
		$this->assertSame( '1', Lafka_Customizer_Reviews::sanitize_checkbox( '1' ) );
		$this->assertSame( '1', Lafka_Customizer_Reviews::sanitize_checkbox( 1 ) );
		$this->assertSame( '1', Lafka_Customizer_Reviews::sanitize_checkbox( true ) );
		$this->assertSame( '0', Lafka_Customizer_Reviews::sanitize_checkbox( 'off' ) );
		$this->assertSame( '0', Lafka_Customizer_Reviews::sanitize_checkbox( 0 ) );
		$this->assertSame( '0', Lafka_Customizer_Reviews::sanitize_checkbox( '' ) );
	}

	public function test_sanitize_delay_hours_clamps_low_and_high(): void {
		$this->assertSame( 1, Lafka_Customizer_Reviews::sanitize_delay_hours( 0 ) );
		$this->assertSame( 1, Lafka_Customizer_Reviews::sanitize_delay_hours( -10 ) );
		$this->assertSame( 336, Lafka_Customizer_Reviews::sanitize_delay_hours( 99999 ) );
		$this->assertSame( 24, Lafka_Customizer_Reviews::sanitize_delay_hours( 24 ) );
	}

	public function test_sanitize_window_days_clamps_low_and_high(): void {
		$this->assertSame( 1, Lafka_Customizer_Reviews::sanitize_window_days( 0 ) );
		$this->assertSame( 30, Lafka_Customizer_Reviews::sanitize_window_days( 999 ) );
		$this->assertSame( 7, Lafka_Customizer_Reviews::sanitize_window_days( 7 ) );
	}

	public function test_sanitize_review_url_accepts_https_and_rejects_garbage(): void {
		$this->assertSame( '', Lafka_Customizer_Reviews::sanitize_review_url( '' ) );
		$this->assertSame( '', Lafka_Customizer_Reviews::sanitize_review_url( null ) );
		$this->assertSame( '', Lafka_Customizer_Reviews::sanitize_review_url( array( 'evil' ) ) );
		// Brain Monkey stubs esc_url_raw → returnArg, so a clean string survives.
		$this->assertSame( 'https://g.page/r/CXX/review', Lafka_Customizer_Reviews::sanitize_review_url( 'https://g.page/r/CXX/review' ) );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// 8. Main plugin wiring
	// ─────────────────────────────────────────────────────────────────────────

	public function test_main_plugin_requires_all_phase_3d_modules(): void {
		$main = file_get_contents( dirname( __DIR__, 2 ) . '/lafka-plugin.php' );
		$this->assertStringContainsString( 'incl/conversion/lafka-review-prompt-email.php', $main );
		$this->assertStringContainsString( 'incl/conversion/lafka-review-prompt-banner.php', $main );
		$this->assertStringContainsString( 'incl/customizer/class-lafka-customizer-reviews.php', $main );
	}

	public function test_main_plugin_version_bumped_to_at_least_9_28_0(): void {
		// Phase 3D shipped at 9.28.0; subsequent phases (3E = 9.29.0) bump the
		// version forward. Assert presence of the Version: header in a
		// major.minor.patch shape ≥ 9.28.0 rather than pinning to one release.
		$main = file_get_contents( dirname( __DIR__, 2 ) . '/lafka-plugin.php' );
		$this->assertMatchesRegularExpression( '/Version:\s*9\.(2[8-9]|[3-9]\d|\d{3,})\.\d+/', $main );
	}

	public function test_legacy_review_prompt_email_file_is_inert(): void {
		// The original P6-UX-8 file is deprecated as of 9.28.0 — confirm it no
		// longer registers any hooks that would collide with the new pipeline.
		$src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/emails/lafka-review-prompt-email.php' );
		$this->assertStringNotContainsString( "add_action( 'woocommerce_order_status_completed'", $src );
		$this->assertStringNotContainsString( "add_action( 'lafka_review_prompt_send'", $src );
	}

	public function test_cli_module_still_present(): void {
		// CLI helpers are independent of the Phase 3D email pipeline and remain
		// available for the operator (wp lafka reviews status / enable / disable).
		$this->assertNotEmpty( file_get_contents( dirname( __DIR__, 2 ) . '/incl/cli/lafka-reviews-cli.php' ) );
	}
}
