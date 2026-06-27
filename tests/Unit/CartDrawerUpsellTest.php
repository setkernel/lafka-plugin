<?php
declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Conversion: cart-drawer "Complete your meal" upsell.
 */
final class CartDrawerUpsellTest extends TestCase {

	private string $src;

	protected function setUp(): void {
		$this->src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/woocommerce/lafka-cart-drawer-upsell.php' );
	}

	public function test_render_fn_and_helpers_exist(): void {
		$this->assertStringContainsString( 'function lafka_cart_drawer_render_upsell', $this->src );
		$this->assertStringContainsString( 'function lafka_cart_drawer_get_upsell_ids', $this->src );
	}

	public function test_only_one_tap_simple_products(): void {
		// Must exclude variable products (one-tap add needs simple).
		$this->assertMatchesRegularExpression( "/!\s*\\\$p->is_type\(\s*'variable'\s*\)/", $this->src );
		$this->assertStringContainsString( "'type'         => 'simple'", $this->src );
		$this->assertStringContainsString( 'is_purchasable', $this->src );
	}

	public function test_excludes_in_cart_items(): void {
		$this->assertStringContainsString( "WC()->cart->get_cart()", $this->src );
		$this->assertStringContainsString( 'in_array( $id, $in_cart', $this->src );
	}

	public function test_wired_into_fragment_refresh(): void {
		$this->assertMatchesRegularExpression(
			"/add_filter\(\s*'woocommerce_add_to_cart_fragments',\s*'lafka_cart_drawer_upsell_fragment'/",
			$this->src
		);
		$this->assertStringContainsString( "\$fragments['div.lafka-cart-drawer__upsell']", $this->src );
	}

	public function test_uses_native_ajax_add(): void {
		$this->assertStringContainsString( 'ajax_add_to_cart', $this->src );
		$this->assertStringContainsString( "wp_enqueue_script( 'wc-add-to-cart' )", $this->src );
	}
}
