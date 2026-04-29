<?php
declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class CartDrawerFragmentsTest extends TestCase {

    public function test_fragment_callback_exists(): void {
        $src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/woocommerce/lafka-cart-drawer-fragments.php' );
        $this->assertStringContainsString( 'function lafka_pdp_cart_drawer_fragments', $src );
    }

    public function test_filter_hooks_woocommerce_add_to_cart_fragments(): void {
        $src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/woocommerce/lafka-cart-drawer-fragments.php' );
        $this->assertMatchesRegularExpression(
            "/add_filter\(\s*['\"]woocommerce_add_to_cart_fragments['\"]/",
            $src
        );
    }

    public function test_drawer_selectors_keyed(): void {
        $src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/woocommerce/lafka-cart-drawer-fragments.php' );
        $this->assertStringContainsString( 'lafka-cart-drawer__items', $src );
        $this->assertStringContainsString( 'lafka-cart-drawer__total', $src );
    }
}
