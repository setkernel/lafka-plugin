<?php
/**
 * Optional checkout win-back email field: rendered only when the operator
 * wrote an offer, honest about what happens, stored on the order when valid.
 *
 * @package Lafka_Plugin
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Mockery;
use PHPUnit\Framework\TestCase;

final class CheckoutEmailCaptureTest extends TestCase {

	private string $offer = '';

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$_POST = array();
		Functions\when( 'get_theme_mod' )->alias( fn( $key, $default = '' ) => 'lafka_pdp_winback_offer_text' === $key ? $this->offer : $default );
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_html_e' )->alias( static function ( $text ) {
			echo $text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} );
		Functions\when( 'esc_attr_e' )->alias( static function ( $text ) {
			echo $text; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
		} );
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'sanitize_email' )->alias( static fn( $v ) => trim( (string) $v ) );
		Functions\when( 'is_email' )->alias( static fn( $v ) => false !== filter_var( $v, FILTER_VALIDATE_EMAIL ) );
		require_once dirname( __DIR__, 2 ) . '/incl/woocommerce/lafka-checkout-email-capture.php';
	}

	protected function tearDown(): void {
		$_POST = array();
		Monkey\tearDown();
		parent::tearDown();
	}

	private function render(): string {
		ob_start();
		lafka_pdp_render_checkout_email_capture();
		return (string) ob_get_clean();
	}

	public function test_no_field_without_an_operator_offer(): void {
		$this->assertSame( '', $this->render() );
	}

	public function test_field_shows_the_offer_and_promises_no_automatic_email(): void {
		$this->offer = 'Get a treat on your next visit';

		$html = $this->render();

		$this->assertStringContainsString( 'Get a treat on your next visit', $html );
		$this->assertStringContainsString( 'name="lafka_winback_email"', $html );
		$this->assertStringNotContainsString( 'email you', $html, 'Nothing sends an email, so the hint must not promise one.' );
	}

	public function test_a_valid_address_is_stored_on_the_order(): void {
		$_POST['lafka_winback_email'] = 'guest@example.test';
		$order                        = Mockery::mock( 'WC_Order' );
		$order->shouldReceive( 'update_meta_data' )->once()->with( '_lafka_winback_email', 'guest@example.test' );
		$order->shouldReceive( 'save' )->once();
		Functions\when( 'wc_get_order' )->justReturn( $order );

		lafka_pdp_save_checkout_email_capture( 12 );
		$this->addToAssertionCount( 1 );
	}

	public function test_an_invalid_address_is_ignored(): void {
		$_POST['lafka_winback_email'] = 'not-an-email';
		Functions\expect( 'wc_get_order' )->never();

		lafka_pdp_save_checkout_email_capture( 12 );
		$this->addToAssertionCount( 1 );
	}
}
