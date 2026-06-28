<?php
/**
 * EmailUnsubscribeTest — locks down the generic per-recipient unsubscribe layer
 * shared by the abandoned-cart recovery email and the review-prompt email
 * (CAN-SPAM / GDPR fix, audit f017):
 *
 *   - Token is a deterministic, email-specific, forgery-resistant HMAC
 *   - Storage key is salt-independent (opt-out survives wp_salt() rotation)
 *   - Unsubscribe URL carries the token + the recipient email
 *   - record_opt_out() + is_opted_out() round-trip through the option store
 *   - The init handler opts the recipient out on a valid token and refuses a
 *     tampered token
 *   - The woocommerce_email_headers filter appends List-Unsubscribe +
 *     List-Unsubscribe-Post for the Lafka email ids only, scoped per recipient
 *   - The postal-address footer helper reads the Customizer-driven NAP source
 *   - The abandoned-cart body renderer short-circuits (no send) for an
 *     opted-out recipient
 *
 * The generic helpers are defined inside the two conversion email modules behind
 * function_exists guards; loading the abandoned-cart module is enough to expose
 * them here.
 *
 * @package Lafka\Plugin\Tests\Unit
 * @since   9.29.0
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

// WC_Email stub must load before the email module so its class_exists guard sees it.
require_once dirname( __DIR__ ) . '/Unit/Stubs/wc-email-stub.php';

// Flip test mode so the unsubscribe handler's wp_safe_redirect doesn't exit() and
// kill the PHPUnit process. Must be defined before the source files load.
if ( ! defined( 'LAFKA_TESTING' ) ) {
	define( 'LAFKA_TESTING', true );
}

// The postal-address footer pulls from the canonical NAP resolver. Load the real
// helpers so lafka_schema_get_nap() resolves from the Customizer/option source of
// truth (the function is already defined by sibling test files before Patchwork
// initializes, so it cannot be Brain-Monkey-stubbed — we drive the real chain).
require_once dirname( __DIR__, 2 ) . '/incl/schema/lafka-schema-helpers.php';
require_once dirname( __DIR__, 2 ) . '/incl/conversion/lafka-abandoned-cart-email.php';
require_once dirname( __DIR__, 2 ) . '/incl/conversion/lafka-review-prompt-email.php';

final class EmailUnsubscribeTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_url' )->returnArg();
		Functions\when( 'esc_html_e' )->alias(
			static function ( $text ) {
				echo $text;
			}
		);
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_json_encode' )->alias( 'json_encode' );
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'sanitize_email' )->returnArg();
		Functions\when( 'wp_salt' )->justReturn( 'unit-test-salt' );
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

	/**
	 * Back the option store with an in-memory array so record/check round-trips.
	 *
	 * @return array<string, mixed> Reference-friendly store (returned by ref via closure use).
	 */
	private function bind_option_store( array &$store ): void {
		Functions\when( 'get_option' )->alias(
			static function ( $name, $default = false ) use ( &$store ) {
				return array_key_exists( $name, $store ) ? $store[ $name ] : $default;
			}
		);
		Functions\when( 'update_option' )->alias(
			static function ( $name, $value, $autoload = null ) use ( &$store ) {
				$store[ $name ] = $value;
				return true;
			}
		);
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Token + storage key
	// ─────────────────────────────────────────────────────────────────────────

	public function test_token_is_deterministic_email_specific_and_case_insensitive(): void {
		$a       = \lafka_unsub_token( 'Alice@Example.com' );
		$a_again = \lafka_unsub_token( 'alice@example.com' );
		$b       = \lafka_unsub_token( 'bob@example.com' );
		$this->assertNotEmpty( $a );
		$this->assertSame( $a, $a_again, 'Token must be case-insensitive on the email.' );
		$this->assertNotSame( $a, $b, 'Token must be email-specific.' );
	}

	public function test_token_empty_for_empty_email(): void {
		$this->assertSame( '', \lafka_unsub_token( '' ) );
		$this->assertSame( '', \lafka_unsub_token( '   ' ) );
	}

	public function test_store_key_survives_salt_rotation_and_differs_from_token(): void {
		Functions\when( 'wp_salt' )->justReturn( 'salt-one' );
		$key_before = \lafka_unsub_store_key( 'alice@example.com' );
		$token      = \lafka_unsub_token( 'alice@example.com' );

		Functions\when( 'wp_salt' )->justReturn( 'salt-two-rotated' );
		$key_after = \lafka_unsub_store_key( 'alice@example.com' );

		$this->assertNotEmpty( $key_before );
		$this->assertSame( $key_before, $key_after, 'Opt-out storage key must not depend on wp_salt().' );
		$this->assertNotSame( $token, $key_before, 'Storage key must differ from the URL token.' );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// URL
	// ─────────────────────────────────────────────────────────────────────────

	public function test_url_contains_token_and_email_args(): void {
		$url = \lafka_unsub_url( 'alice@example.com' );
		$this->assertStringContainsString( 'lafka_unsubscribe=', $url );
		$this->assertStringContainsString( 'e=', $url );
		$this->assertStringContainsString( '://lafka.test', $url );
	}

	public function test_url_empty_for_empty_email(): void {
		$this->assertSame( '', \lafka_unsub_url( '' ) );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Persisted opt-out store
	// ─────────────────────────────────────────────────────────────────────────

	public function test_record_and_check_opt_out_round_trip(): void {
		$store = array();
		$this->bind_option_store( $store );

		$this->assertFalse( \lafka_unsub_is_opted_out( 'alice@example.com' ) );
		\lafka_unsub_record_opt_out( 'Alice@Example.com' );
		$this->assertTrue( \lafka_unsub_is_opted_out( 'alice@example.com' ), 'Opt-out must be case-insensitive.' );
		$this->assertFalse( \lafka_unsub_is_opted_out( 'bob@example.com' ) );
	}

	public function test_record_opt_out_is_idempotent(): void {
		$store = array();
		$this->bind_option_store( $store );

		\lafka_unsub_record_opt_out( 'alice@example.com' );
		\lafka_unsub_record_opt_out( 'alice@example.com' );
		$list = $store['lafka_email_unsub_list'];
		$this->assertCount( 1, $list );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// init handler
	// ─────────────────────────────────────────────────────────────────────────

	public function test_handler_records_opt_out_on_valid_token(): void {
		$store = array();
		$this->bind_option_store( $store );
		Functions\when( 'wp_safe_redirect' )->justReturn( true );

		$email                             = 'guest@example.com';
		$_GET['lafka_unsubscribe']         = \lafka_unsub_token( $email );
		$_GET['e']                         = $email;
		\lafka_unsub_handle_request();
		unset( $_GET['lafka_unsubscribe'], $_GET['e'] );

		$this->assertTrue( \lafka_unsub_is_opted_out( $email ) );
	}

	public function test_handler_rejects_tampered_token(): void {
		$store = array();
		$this->bind_option_store( $store );
		Functions\when( 'wp_safe_redirect' )->justReturn( true );

		$_GET['lafka_unsubscribe'] = 'totally-bogus-token';
		$_GET['e']                 = 'guest@example.com';
		\lafka_unsub_handle_request();
		unset( $_GET['lafka_unsubscribe'], $_GET['e'] );

		$this->assertFalse( \lafka_unsub_is_opted_out( 'guest@example.com' ) );
	}

	public function test_handler_noop_without_params(): void {
		$store = array();
		$this->bind_option_store( $store );
		Functions\when( 'wp_safe_redirect' )->justReturn( true );

		\lafka_unsub_handle_request();
		$this->assertSame( array(), $store );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// List-Unsubscribe header
	// ─────────────────────────────────────────────────────────────────────────

	public function test_email_headers_appends_list_unsubscribe_for_lafka_ids(): void {
		$email = new class() {
			public function get_recipient(): string {
				return 'alice@example.com';
			}
		};
		$out = \lafka_unsub_email_headers( "Content-Type: text/html\r\n", 'lafka_abandoned_cart', null, $email );
		$this->assertStringContainsString( 'Content-Type: text/html', $out );
		$this->assertStringContainsString( 'List-Unsubscribe: <', $out );
		$this->assertStringContainsString( 'List-Unsubscribe-Post: List-Unsubscribe=One-Click', $out );
	}

	public function test_email_headers_noop_for_unrelated_email_id(): void {
		$in  = "Content-Type: text/html\r\n";
		$out = \lafka_unsub_email_headers( $in, 'customer_completed_order', null, null );
		$this->assertSame( $in, $out );
	}

	public function test_email_headers_noop_when_recipient_missing(): void {
		$email = new class() {
			public function get_recipient(): string {
				return '';
			}
		};
		$in  = "Content-Type: text/html\r\n";
		$out = \lafka_unsub_email_headers( $in, 'lafka_review_prompt', null, $email );
		$this->assertSame( $in, $out );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Postal address (CAN-SPAM footer) — sourced, never hardcoded
	// ─────────────────────────────────────────────────────────────────────────

	public function test_postal_address_reads_nap_source(): void {
		// Drive the REAL NAP resolver (lafka_schema_get_nap → lafka_get_restaurant_info)
		// from the Customizer/option source of truth, then assert the footer helper
		// renders those operator-entered values verbatim — proving the address is
		// sourced, never a hardcoded literal.
		$store = array(
			'lafka_business_name'    => 'Peppery Pizza',
			'lafka_business_street'  => '742 Evergreen Terrace',
			'lafka_business_city'    => 'Springfield',
			'lafka_business_region'  => 'IL',
			'lafka_business_postal'  => '62704',
			'lafka_business_country' => 'US',
		);
		$this->bind_option_store( $store );
		Functions\when( 'get_theme_mod' )->returnArg( 2 );
		Functions\when( 'get_bloginfo' )->justReturn( '' );
		Functions\when( 'get_site_icon_url' )->justReturn( '' );
		Functions\when( 'trailingslashit' )->alias(
			static function ( $url ) {
				return rtrim( (string) $url, '/' ) . '/';
			}
		);
		Functions\when( 'apply_filters' )->returnArg( 2 );

		$addr = \lafka_unsub_postal_address();
		$this->assertStringContainsString( 'Peppery Pizza', $addr );
		$this->assertStringContainsString( '742 Evergreen Terrace', $addr );
		$this->assertStringContainsString( '62704', $addr );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Abandoned-cart enforcement — render short-circuits for an opted-out guest
	// ─────────────────────────────────────────────────────────────────────────

	public function test_abandoned_cart_body_short_circuits_for_opted_out_recipient(): void {
		$store = array();
		$this->bind_option_store( $store );
		\lafka_unsub_record_opt_out( 'guest@example.com' );

		$row                = new \stdClass();
		$row->customer_email = 'guest@example.com';
		$row->resume_token   = 'TOKEN0000TOKEN0000';
		$row->cart_contents  = wp_json_encode(
			array(
				'items'    => array(
					array(
						'name'     => 'Margherita',
						'quantity' => 1,
						'price'    => 10.0,
					),
				),
				'subtotal' => 10.0,
				'currency' => '',
			)
		);

		$this->assertSame( '', \lafka_ac_render_email_body( $row, null ), 'Opted-out recipient must produce no email body (no send).' );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Review email enforcement — should_skip_order() honours the store (guests)
	// ─────────────────────────────────────────────────────────────────────────

	public function test_review_should_skip_order_honours_email_opt_out_for_guest(): void {
		$store = array();
		$this->bind_option_store( $store );
		Functions\when( 'get_user_meta' )->justReturn( '' );
		\lafka_unsub_record_opt_out( 'guest@example.com' );

		$order = new class() {
			public function get_meta( $key, $single = true ): string {
				return '';
			}
			public function get_billing_email(): string {
				return 'guest@example.com';
			}
			public function get_customer_id(): int {
				return 0; // guest checkout — no WP user, no user meta
			}
		};
		$this->assertTrue( \lafka_review_email_should_skip_order( $order ) );
	}

	public function test_review_should_skip_order_false_for_non_opted_guest(): void {
		$store = array();
		$this->bind_option_store( $store );
		Functions\when( 'get_user_meta' )->justReturn( '' );

		$order = new class() {
			public function get_meta( $key, $single = true ): string {
				return '';
			}
			public function get_billing_email(): string {
				return 'newguest@example.com';
			}
			public function get_customer_id(): int {
				return 0;
			}
		};
		$this->assertFalse( \lafka_review_email_should_skip_order( $order ) );
	}
}
