<?php
declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class DisableAddToCartTest extends TestCase {
	private string $src;

	protected function setUp(): void {
		parent::setUp();
		$this->src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/order-hours/Lafka_Order_Hours.php' );
	}

	public function test_handle_shop_status_gates_disable_block_on_option(): void {
		// New conditional block must check the lafka_order_hours_disable_add_to_cart
		// option before removing the WC add-to-cart action.
		$method_pos = strpos( $this->src, 'function handle_shop_status' );
		$this->assertNotFalse( $method_pos );
		$method_slice = substr( $this->src, $method_pos, 2000 );
		$this->assertStringContainsString( 'lafka_order_hours_disable_add_to_cart', $method_slice );
	}

	public function test_remove_action_for_wc_template_single_add_to_cart(): void {
		// When the option is true, the WC default add-to-cart action must be removed
		// from woocommerce_single_product_summary at priority 30.
		$this->assertMatchesRegularExpression(
			"/remove_action\(\s*['\"]woocommerce_single_product_summary['\"]\s*,\s*['\"]woocommerce_template_single_add_to_cart['\"]\s*,\s*30/",
			$this->src,
			'must remove woocommerce_template_single_add_to_cart from single_product_summary at priority 30'
		);
	}

	public function test_remove_after_add_to_cart_button_when_disabling(): void {
		// When disabling entirely, the after-button hook should also be removed
		// (no button means nothing to render after).
		$method_pos = strpos( $this->src, 'function handle_shop_status' );
		$method_slice = substr( $this->src, $method_pos, 2000 );
		$this->assertMatchesRegularExpression(
			"/remove_action\(\s*['\"]woocommerce_after_add_to_cart_button['\"]/",
			$method_slice,
			'must remove woocommerce_after_add_to_cart_button when disabling'
		);
	}

	public function test_card_renders_in_summary_when_button_removed(): void {
		// The closed-store card must render in the same summary spot the button
		// previously occupied — add_action on woocommerce_single_product_summary at priority 30.
		$method_pos = strpos( $this->src, 'function handle_shop_status' );
		$method_slice = substr( $this->src, $method_pos, 2000 );
		$this->assertMatchesRegularExpression(
			"/add_action\(\s*['\"]woocommerce_single_product_summary['\"]\s*,\s*array\(\s*\\\$this\s*,\s*['\"]echo_closed_store_message['\"]\s*\)\s*,\s*30/",
			$method_slice,
			'must add closed-store card on woocommerce_single_product_summary at priority 30'
		);
	}
}
