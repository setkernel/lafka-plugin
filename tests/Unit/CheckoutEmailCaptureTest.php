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

    public function test_winback_copy_is_operator_configurable(): void {
        // Regression lock for v9.7.8. Pre-fix the headline was hardcoded to
        // "Save 10% on your next order" but the file's own comment said the
        // win-back coupon flow is not implemented yet. Operators on a
        // different discount tier — or no discount at all — were promising
        // 10% they never delivered. Now operator-configurable via Customizer
        // (lafka_pdp_winback_offer_text); empty string hides the field.
        $this->assertStringContainsString(
            'lafka_pdp_winback_offer_text',
            $this->src,
            'Winback headline must read from the Customizer setting, not a hardcoded literal.'
        );
        $this->assertDoesNotMatchRegularExpression(
            "/_e\(\s*'(?:[^']*?)Save 10%[^']*?'/",
            $this->src,
            'Hardcoded "Save 10%" copy must not be reintroduced.'
        );
    }

    public function test_render_returns_early_when_offer_text_blank(): void {
        // Empty string ⇒ feature disabled. The whole render function must
        // bail before emitting any markup so non-Peppery installs don't ship
        // a half-implemented feature.
        $this->assertMatchesRegularExpression(
            "/'' === \\\$headline\s*\)\s*\{[^}]*return;/s",
            $this->src,
            'Render function must return early when winback offer text is blank.'
        );
    }
}
