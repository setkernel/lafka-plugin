<?php
declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class CheckoutEmailCaptureTest extends TestCase {

    private string $src;

    protected function setUp(): void {
        $this->src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/woocommerce/lafka-checkout-email-capture.php' );
    }

    public function test_render_function(): void {
        $this->assertStringContainsString( 'function lafka_pdp_render_checkout_email_capture', $this->src );
    }

    public function test_save_handler(): void {
        $this->assertStringContainsString( 'function lafka_pdp_save_checkout_email_capture', $this->src );
    }

    public function test_meta_key(): void {
        $this->assertStringContainsString( '_lafka_winback_email', $this->src );
    }

    public function test_hooks_checkout_after_customer_details(): void {
        $this->assertMatchesRegularExpression(
            "/add_action\(\s*['\"]woocommerce_checkout_after_customer_details['\"]/",
            $this->src
        );
    }

    public function test_sanitizes_email(): void {
        $this->assertStringContainsString( 'sanitize_email', $this->src );
    }
}
