<?php
declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Regression lock for the store-closed SERVER-SIDE ordering gate.
 *
 * Audit f003 (HIGH / wiring): handle_shop_status() only ever swapped button
 * HTML and added a body class when the store was closed — there was NO
 * woocommerce_checkout_process / woocommerce_add_to_cart_validation handler and
 * NO Store API (blocks) gate calling is_shop_open(). So a closed store still
 * accepted orders via a replayed/stale classic place-order POST, the wc-ajax
 * add-to-cart path, or the Cart/Checkout Blocks + Store API path (which ignores
 * woocommerce_order_button_html entirely).
 *
 * The fix registers validation gates that re-check is_shop_open() at fire time:
 *   - woocommerce_checkout_process                 (classic checkout abort)
 *   - woocommerce_add_to_cart_validation           (classic add-to-cart reject)
 *   - woocommerce_store_api_validate_add_to_cart    (blocks add-to-cart)
 *
 * NX1-04a correction: the blocks CHECKOUT gate was originally registered on
 * `woocommerce_store_api_validate_cart` — a hook name that WooCommerce never
 * fires (the real hook is `woocommerce_store_api_cart_errors`, from
 * CartController::validate_cart()). That dead registration is removed here and
 * the store-closed checkout gate now lives in Lafka_Store_Api::add_cart_errors(),
 * which reuses this class's is_shop_open() + get_closed_notice_message(). This
 * test therefore also pins that the dead hook is NOT re-introduced.
 *
 * These assertions are structural source-greps (no WP runtime), matching the
 * TimeslotsDatetimeValidationTest approach.
 */
final class OrderHoursServerGateTest extends TestCase {

	private string $src;

	protected function setUp(): void {
		parent::setUp();
		$this->src = file_get_contents(
			dirname( __DIR__, 2 ) . '/incl/order-hours/Lafka_Order_Hours.php'
		);
	}

	/**
	 * @return array<string, array{0:string,1:string}>
	 */
	public static function gateHookProvider(): array {
		return array(
			'classic checkout gate'        => array(
				"/add_action\(\s*['\"]woocommerce_checkout_process['\"]\s*,\s*array\(\s*\\\$this\s*,\s*['\"]gate_checkout_when_closed['\"]/",
				'classic checkout must be gated on woocommerce_checkout_process so the error notice aborts process_checkout()',
			),
			'classic add-to-cart gate'     => array(
				"/add_filter\(\s*['\"]woocommerce_add_to_cart_validation['\"]\s*,\s*array\(\s*\\\$this\s*,\s*['\"]gate_add_to_cart_when_closed['\"]/",
				'classic add-to-cart must be gated on woocommerce_add_to_cart_validation',
			),
			'store api add-to-cart gate'   => array(
				"/add_action\(\s*['\"]woocommerce_store_api_validate_add_to_cart['\"]\s*,\s*array\(\s*\\\$this\s*,\s*['\"]gate_store_api_add_to_cart_when_closed['\"]/",
				'blocks/Store API add-to-cart must be gated on woocommerce_store_api_validate_add_to_cart',
			),
		);
	}

	public function test_dead_validate_cart_hook_is_not_registered(): void {
		// woocommerce_store_api_validate_cart is not a real WC hook — it never
		// fires — so registering the store-closed checkout gate on it was a
		// silent no-op (a closed store could accept a block-checkout order). The
		// working gate now lives in Lafka_Store_Api on
		// woocommerce_store_api_cart_errors. Guard against re-introducing the dead
		// hook here.
		$this->assertDoesNotMatchRegularExpression(
			"/add_action\(\s*['\"]woocommerce_store_api_validate_cart['\"]/",
			$this->src,
			'The store-closed checkout gate must not be registered on the non-firing woocommerce_store_api_validate_cart hook.'
		);
		$this->assertStringNotContainsString(
			'function gate_store_api_cart_when_closed',
			$this->src,
			'The dead gate_store_api_cart_when_closed method must be removed — the checkout gate lives in Lafka_Store_Api::add_cart_errors().'
		);
	}

	#[\PHPUnit\Framework\Attributes\DataProvider( 'gateHookProvider' )]
	public function test_gate_hook_is_registered( string $pattern, string $message ): void {
		$this->assertMatchesRegularExpression( $pattern, $this->src, $message );
	}

	public function test_gates_registered_unconditionally_not_only_when_closed(): void {
		// The four gate registrations must live in handle_shop_status() OUTSIDE
		// the `if ( ! self::is_shop_open() )` UI branch, so the gate callbacks —
		// which re-check is_shop_open() themselves — always run. We assert the
		// registrations appear in the source BEFORE the cosmetic body_class hook
		// that opens the closed-only branch.
		$body_class_pos = strpos( $this->src, "add_filter( 'body_class'" );
		$this->assertNotFalse( $body_class_pos, 'cosmetic body_class hook (closed-only branch marker) not found' );

		foreach ( array(
			"add_action( 'woocommerce_checkout_process'",
			"add_filter( 'woocommerce_add_to_cart_validation'",
			"add_action( 'woocommerce_store_api_validate_add_to_cart'",
		) as $registration ) {
			$pos = strpos( $this->src, $registration );
			$this->assertNotFalse( $pos, "gate registration not found: {$registration}" );
			$this->assertLessThan(
				$body_class_pos,
				$pos,
				"gate registration must precede the closed-only UI branch (be unconditional): {$registration}"
			);
		}
	}

	public function test_checkout_gate_emits_blocking_error_notice_when_closed(): void {
		$body = $this->method_body( 'gate_checkout_when_closed' );
		$this->assertNotSame( '', $body, 'gate_checkout_when_closed body not found' );
		$this->assertMatchesRegularExpression(
			"/if\s*\(\s*self::is_shop_open\(\)\s*\)\s*\{\s*return;/s",
			$body,
			'checkout gate must short-circuit when the store is open'
		);
		$this->assertMatchesRegularExpression(
			"/wc_add_notice\(.*?,\s*['\"]error['\"]\s*\)/s",
			$body,
			'checkout gate must call wc_add_notice( ..., \'error\' ) to abort checkout'
		);
	}

	public function test_classic_add_to_cart_gate_returns_false_when_closed(): void {
		$body = $this->method_body( 'gate_add_to_cart_when_closed' );
		$this->assertNotSame( '', $body, 'gate_add_to_cart_when_closed body not found' );
		$this->assertStringContainsString( 'is_shop_open()', $body, 'add-to-cart gate must consult is_shop_open()' );
		$this->assertStringContainsString( 'return false;', $body, 'add-to-cart gate must reject the add (return false) when closed' );
		$this->assertMatchesRegularExpression(
			"/wc_add_notice\(.*?,\s*['\"]error['\"]\s*\)/s",
			$body,
			'add-to-cart gate must surface an error notice when blocking'
		);
	}

	public function test_store_api_add_to_cart_gate_throws_route_exception_when_closed(): void {
		$method = 'gate_store_api_add_to_cart_when_closed';
		$body   = $this->method_body( $method );
		$this->assertNotSame( '', $body, "{$method} body not found" );
		$this->assertStringContainsString( 'is_shop_open()', $body, "{$method} must consult is_shop_open()" );
		$this->assertMatchesRegularExpression(
			'/throw\s+new\s+\\\\Automattic\\\\WooCommerce\\\\StoreApi\\\\Exceptions\\\\RouteException/',
			$body,
			"{$method} must throw \\Automattic\\WooCommerce\\StoreApi\\Exceptions\\RouteException so the Store API aborts the request"
		);
	}

	public function test_add_to_cart_gates_respect_disable_option_but_checkout_gates_do_not(): void {
		// Add-to-cart blocking is opt-in (mirrors the UI button removal), so both
		// add-to-cart gates must consult the disable option helper. Checkout gates
		// must NOT — checkout is always blocked while closed.
		$this->assertStringContainsString(
			'is_add_to_cart_disabled_when_closed',
			$this->method_body( 'gate_add_to_cart_when_closed' ),
			'classic add-to-cart gate must respect lafka_order_hours_disable_add_to_cart'
		);
		$this->assertStringContainsString(
			'is_add_to_cart_disabled_when_closed',
			$this->method_body( 'gate_store_api_add_to_cart_when_closed' ),
			'Store API add-to-cart gate must respect lafka_order_hours_disable_add_to_cart'
		);
		$this->assertStringNotContainsString(
			'is_add_to_cart_disabled_when_closed',
			$this->method_body( 'gate_checkout_when_closed' ),
			'classic checkout must be blocked whenever closed, regardless of the add-to-cart option'
		);
	}

	public function test_closed_notice_message_falls_back_to_translatable_default(): void {
		$body = $this->method_body( 'get_closed_notice_message' );
		$this->assertNotSame( '', $body, 'get_closed_notice_message body not found' );
		$this->assertStringContainsString(
			'lafka_order_hours_message',
			$body,
			'notice text must prefer the operator-configured message'
		);
		$this->assertMatchesRegularExpression(
			"/__\(\s*['\"][^'\"]+['\"]\s*,\s*['\"]lafka-plugin['\"]\s*\)/",
			$body,
			'notice text must fall back to an i18n-wrapped default in the lafka-plugin text domain'
		);
	}

	/**
	 * Crude PHP-source method-body extractor: returns the text between a
	 * `function <name>` and the next method declaration (` function `) or EOF.
	 * Good enough for these structural regression assertions.
	 */
	private function method_body( string $name ): string {
		$start = strpos( $this->src, 'function ' . $name );
		if ( false === $start ) {
			return '';
		}
		$rest = substr( $this->src, $start + strlen( 'function ' . $name ) );
		$next = strpos( $rest, ' function ' );
		return false === $next ? $rest : substr( $rest, 0, $next );
	}
}
