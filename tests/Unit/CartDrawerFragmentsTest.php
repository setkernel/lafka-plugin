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

    public function test_free_delivery_threshold_uses_ssot_resolver(): void {
        // F045: the drawer hint must read the canonical SSOT resolver,
        // lafka_get_free_delivery_threshold(), so it can never disagree with the
        // amount the shipping rule (lafka_free_delivery_apply_rates) enforces. An
        // operator who sets the threshold via the WC option used to see the rule
        // applied at $X while the drawer showed $0/stale. Assert the resolver is
        // called rather than locking the raw theme_mod source.
        $src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/woocommerce/lafka-cart-drawer-fragments.php' );
        $this->assertMatchesRegularExpression(
            "/function_exists\(\s*'lafka_get_free_delivery_threshold'\s*\)/",
            $src,
            'Drawer must resolve the threshold through the SSOT resolver, not a raw theme_mod.'
        );
        $this->assertStringContainsString(
            'lafka_get_free_delivery_threshold()',
            $src,
            'Drawer must call the canonical resolver for the free-delivery threshold.'
        );
    }

    public function test_free_delivery_threshold_fallback_defaults_to_zero(): void {
        // Regression lock for v9.7.8. Pre-fix the threshold defaulted to 40
        // (Peppery's value) — every other operator running this OSS plugin got
        // "Add $X more for free delivery" copy with a wrong number. The
        // plugin-absent fallback must still default to 0 (feature off) and the
        // hardcoded 40 must never return.
        $src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/woocommerce/lafka-cart-drawer-fragments.php' );
        $this->assertMatchesRegularExpression(
            "/get_theme_mod\(\s*'lafka_pdp_free_delivery_threshold'\s*,\s*0\s*\)/",
            $src,
            'Plugin-absent fallback must default to 0 (disabled), not a Peppery-specific value.'
        );
        $this->assertDoesNotMatchRegularExpression(
            "/apply_filters\(\s*'lafka_pdp_free_delivery_threshold'\s*,\s*40\s*\)/",
            $src,
            'Hardcoded 40 default must not be reintroduced.'
        );
    }
}
