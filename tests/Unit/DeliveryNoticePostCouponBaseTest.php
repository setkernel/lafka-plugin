<?php
/**
 * Regression (audit F061): the delivery-minimum customer notice must read the
 * same post-coupon base that apply_delivery_minimum()'s rate-hiding gate uses.
 *
 * Previously render_delivery_notice() summed pre-coupon $item['line_subtotal'],
 * while apply_delivery_minimum() gated on $package['contents_cost'] (post-coupon
 * line totals). With WC coupons/discounts active the two bases diverged, so the
 * storefront could promise delivery while the shipping step still hid every
 * delivery method (or vice-versa). The fix routes the notice through the cart's
 * post-coupon get_cart_contents_total() and the shared should_block_delivery()
 * predicate, so the message and the rate decision always agree.
 *
 * Lafka_Promotions skips WP-runtime hook registration when add_action() isn't
 * defined, but the test bootstrap defines a no-op add_action(), so the file
 * auto-instantiates the singleton on require — instance() just returns it.
 */

declare(strict_types=1);

namespace Lafka\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Lafka_Promotions;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/incl/promotions/class-lafka-promotions.php';

final class DeliveryNoticePostCouponBaseTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		// knob() falls back to constants (DELIVERY_MIN = 30) when option empty.
		Functions\when( 'get_option' )->justReturn( array() );
		// Output helpers — passthrough so we can assert on the rendered markup.
		Functions\when( 'esc_html__' )->returnArg( 1 );
		Functions\when( 'wp_kses_post' )->returnArg( 1 );
		Functions\when( 'wc_price' )->alias( static fn( $n ) => '$' . number_format( (float) $n, 2 ) );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Render the notice against a cart whose PRE-coupon line subtotals total
	 * $100 but whose POST-coupon contents total is $contents_total. A correct
	 * fix keys off the post-coupon figure and ignores the line subtotals.
	 */
	private function render_with_cart( float $contents_total ): string {
		$cart = new class( $contents_total ) {
			public function __construct( private float $total ) {}

			public function is_empty(): bool {
				return false;
			}

			public function get_cart_contents_total(): float {
				return $this->total;
			}

			/**
			 * Booby-trapped: if the notice ever sums these pre-coupon line
			 * subtotals again it would read $100 and disagree with the gate.
			 */
			public function get_cart(): array {
				return array(
					array( 'line_subtotal' => 100.0 ),
				);
			}
		};

		Functions\when( 'WC' )->justReturn( (object) array( 'cart' => $cart ) );

		ob_start();
		Lafka_Promotions::instance()->render_delivery_notice();
		return (string) ob_get_clean();
	}

	public function test_notice_hidden_when_post_coupon_base_meets_minimum(): void {
		// Post-coupon contents total = $30 exactly → gate ALLOWS delivery, so
		// the notice must NOT render even though pre-coupon subtotals are $100.
		self::assertSame( '', $this->render_with_cart( 30.0 ) );
	}

	public function test_notice_shown_with_remaining_off_post_coupon_base(): void {
		// Post-coupon contents total = $20 (< $30) → gate BLOCKS delivery, so
		// the notice renders and "remaining" is $10 ($30 − $20), computed off
		// the post-coupon base — NOT $0 off the $100 pre-coupon line subtotals.
		$out = $this->render_with_cart( 20.0 );
		self::assertStringContainsString( 'lafka-delivery-min-notice', $out );
		self::assertStringContainsString( 'Add $10.00 more', $out );
	}
}
