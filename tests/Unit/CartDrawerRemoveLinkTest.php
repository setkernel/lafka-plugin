<?php
declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * f087 regression lock (plugin fragment render site).
 *
 * The cart-drawer line-item remove (×) control must be a WooCommerce-native
 * remove anchor, not a dead <button>. Pre-fix the drawer fragment rendered
 * <button class="lafka-cart-drawer__remove" data-cart-key="…">×</button>, but no
 * JS anywhere (theme, plugin or child) bound a click handler for it and it
 * lacked WC's a.remove_from_cart_button class, so WC core's add-to-cart.js never
 * bound it either. Clicking × did nothing — a customer could not drop an
 * accidental add from the drawer without leaving the funnel for the full cart
 * page.
 *
 * The fix replaces the button with WC's native remove anchor
 * (href = wc_get_cart_remove_url(key), class includes remove_from_cart_button,
 * data-cart_item_key carried) so WC core's already-tested AJAX remove path binds
 * it, includes the cart nonce in the URL and emits removed_from_cart with
 * fragments that refresh the drawer / sticky cart / fdp components.
 */
final class CartDrawerRemoveLinkTest extends TestCase {

    private string $src;

    protected function setUp(): void {
        parent::setUp();
        $this->src = file_get_contents(
            dirname( __DIR__, 2 ) . '/incl/woocommerce/lafka-cart-drawer-fragments.php'
        );
    }

    public function test_remove_control_is_wc_native_anchor(): void {
        $this->assertStringContainsString(
            'class="lafka-cart-drawer__remove remove_from_cart_button"',
            $this->src,
            'The remove control must carry WC\'s remove_from_cart_button class so add-to-cart.js binds it (and keep lafka-cart-drawer__remove for styling).'
        );
        $this->assertStringContainsString(
            '>×</a>',
            $this->src,
            'The remove control must be an <a> (WC core binds a.remove_from_cart_button), not the old dead <button>.'
        );
    }

    public function test_remove_link_uses_cart_remove_url_with_nonce(): void {
        $this->assertStringContainsString(
            'wc_get_cart_remove_url( $cart_item_key )',
            $this->src,
            'The remove href must come from wc_get_cart_remove_url() so it carries the WC cart nonce and a no-JS fallback URL.'
        );
    }

    public function test_remove_link_carries_cart_item_key_data_attr(): void {
        $this->assertStringContainsString(
            'data-cart_item_key="',
            $this->src,
            'WC add-to-cart.js reads data-cart_item_key for the AJAX remove_from_cart request.'
        );
    }

    public function test_dead_unbound_button_control_removed(): void {
        $this->assertDoesNotMatchRegularExpression(
            '/<button[^>]*lafka-cart-drawer__remove/',
            $this->src,
            'The dead, unbound <button class="lafka-cart-drawer__remove"> must not return.'
        );
    }
}
