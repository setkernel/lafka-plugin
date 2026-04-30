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

    public function test_free_delivery_threshold_defaults_to_zero(): void {
        // Regression lock for v9.7.8. Pre-fix the threshold defaulted to 40
        // (Peppery's value) — every other operator running this OSS plugin
        // got "Add $X more for free delivery" copy with a wrong number until
        // they noticed and either set their own value or installed a filter.
        // Default 0 means "feature off" — the threshold block conditional on
        // $free_threshold > 0 means no copy renders.
        $src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/woocommerce/lafka-cart-drawer-fragments.php' );
        $this->assertMatchesRegularExpression(
            "/get_theme_mod\(\s*'lafka_pdp_free_delivery_threshold'\s*,\s*0\s*\)/",
            $src,
            'Free-delivery threshold must default to 0 (disabled) in OSS, not a Peppery-specific value.'
        );
        $this->assertDoesNotMatchRegularExpression(
            "/apply_filters\(\s*'lafka_pdp_free_delivery_threshold'\s*,\s*40\s*\)/",
            $src,
            'Hardcoded 40 default must not be reintroduced.'
        );
    }
}
