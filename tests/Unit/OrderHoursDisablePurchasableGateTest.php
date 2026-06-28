<?php
declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Regression lock (audit f067): when the operator opts into
 * lafka_order_hours_disable_add_to_cart and the store is closed, the add-to-cart
 * must be blocked by an AUTHORITATIVE, template-agnostic server-side guard —
 * not merely by swapping button HTML on woocommerce_single_product_summary,
 * which the redesigned PDP never fires.
 *
 * The fix adds `add_filter( 'woocommerce_is_purchasable', '__return_false' )`
 * inside the disable branch (backing the woocommerce_add_to_cart_validation gate)
 * so a non-purchasable product cannot be added by ANY template — classic form,
 * the redesigned PDP's own <form class="cart">, wc-ajax, the Store API/blocks,
 * or quick-view. It also makes echo_closed_store_message() static so the
 * redesigned PDP can render the same closed-store card inline.
 *
 * Source-structural assertions, matching OrderHoursServerGateTest.
 */
final class OrderHoursDisablePurchasableGateTest extends TestCase {

	private string $src;

	protected function setUp(): void {
		parent::setUp();
		$this->src = file_get_contents(
			dirname( __DIR__, 2 ) . '/incl/order-hours/Lafka_Order_Hours.php'
		);
	}

	public function test_is_purchasable_block_is_registered(): void {
		$this->assertMatchesRegularExpression(
			"/add_filter\(\s*['\"]woocommerce_is_purchasable['\"]\s*,\s*['\"]__return_false['\"]\s*\)/",
			$this->src,
			'disable_add_to_cart must register a woocommerce_is_purchasable => __return_false server-side block'
		);
	}

	public function test_is_purchasable_block_is_gated_on_the_disable_option(): void {
		// The block must live INSIDE the disable_add_to_cart branch, so a closed
		// store where the operator did NOT opt in still lets customers build a
		// cart. We assert the filter registration appears after the option gate.
		$gate_pos = strpos(
			$this->src,
			"if ( ! empty( self::\$lafka_order_hours_options['lafka_order_hours_disable_add_to_cart'] ) )"
		);
		$this->assertNotFalse( $gate_pos, 'disable_add_to_cart option gate not found' );

		$filter_pos = strpos( $this->src, "add_filter( 'woocommerce_is_purchasable', '__return_false' )" );
		$this->assertNotFalse( $filter_pos, 'woocommerce_is_purchasable block not found' );

		$this->assertGreaterThan(
			$gate_pos,
			$filter_pos,
			'woocommerce_is_purchasable block must be gated inside the disable_add_to_cart branch (opt-in only)'
		);
	}

	public function test_closed_store_card_renderer_is_static(): void {
		// The redesigned PDP (lafka-theme/partials/pdp-summary.php) renders the
		// closed-store card inline via Lafka_Order_Hours::echo_closed_store_message();
		// that requires the renderer to be static.
		$this->assertMatchesRegularExpression(
			'/public\s+static\s+function\s+echo_closed_store_message\s*\(/',
			$this->src,
			'echo_closed_store_message() must be static so theme templates can render the card inline'
		);
	}
}
