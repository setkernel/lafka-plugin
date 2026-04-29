<?php
declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class LastOrderCardTest extends TestCase {

    private string $src;

    protected function setUp(): void {
        $this->src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/woocommerce/lafka-last-order-card.php' );
    }

    public function test_set_cookie_function_exists(): void {
        $this->assertStringContainsString( 'function lafka_pdp_set_last_order_cookie', $this->src );
    }

    public function test_get_last_order_function_exists(): void {
        $this->assertStringContainsString( 'function lafka_pdp_get_last_order', $this->src );
    }

    public function test_render_card_function_exists(): void {
        $this->assertStringContainsString( 'function lafka_pdp_render_last_order_card', $this->src );
    }

    public function test_cookie_constant(): void {
        $this->assertStringContainsString( "'lafka_recent_order'", $this->src );
    }

    public function test_hooks_thankyou(): void {
        $this->assertMatchesRegularExpression(
            "/add_action\(\s*['\"]woocommerce_thankyou['\"]/",
            $this->src
        );
    }

    public function test_cookie_samesite_lax(): void {
        $this->assertStringContainsString( "'samesite' => 'Lax'", $this->src );
    }

    public function test_reorder_ajax_handler(): void {
        $this->assertStringContainsString( 'function lafka_pdp_reorder_ajax', $this->src );
        $this->assertMatchesRegularExpression(
            "/add_action\(\s*['\"]wp_ajax(_nopriv)?_lafka_pdp_reorder['\"]/",
            $this->src
        );
    }

    public function test_reorder_uses_nonce(): void {
        $this->assertStringContainsString( 'check_ajax_referer', $this->src );
    }
}
