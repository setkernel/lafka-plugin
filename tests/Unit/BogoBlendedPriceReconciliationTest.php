<?php
/**
 * Regression lock for the BOGO blended-price / displayed-subtotal mismatch.
 *
 * Bug (f001): blended_price() treated `bogo_discount` as the fraction the
 * customer PAYS, while the savings line, render_bogo_subtotal() and the admin
 * UI all treat it as the fraction taken OFF. The two interpretations only
 * coincide at 0.5 (its own complement) — the single value the original
 * LafkaPromotionsTest covered — so any other knob value silently charged an
 * amount different from the displayed cart subtotal (worst case: "1 = free"
 * charged full price).
 *
 * These tests pin blended_price() at NON-0.5 knob values and assert the
 * invariant that what we charge ( blended * qty ) equals what we display
 * ( orig_subtotal - savings ) for arbitrary knobs.
 *
 * knob() caches the `get_option` result in a function-local static for the
 * life of the process, so each value-specific case runs in its own process
 * (PreserveGlobalState disabled) to get a clean cache, with get_option stubbed
 * before the first knob() call.
 */

declare(strict_types=1);

namespace Lafka\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Lafka_Promotions;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\PreserveGlobalState;
use PHPUnit\Framework\Attributes\RunInSeparateProcess;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/incl/promotions/class-lafka-promotions.php';

final class BogoBlendedPriceReconciliationTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * Inject a `bogo_discount` knob value through get_option. Must run before
	 * the first knob() call in the process so the static cache picks it up.
	 */
	private function set_bogo_discount( float $discount ): void {
		Functions\when( 'get_option' )->justReturn( array( 'bogo_discount' => $discount ) );
	}

	// ─── exact-value pins at non-0.5 (the values the audit reproduced) ────────

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_full_discount_charges_complement_not_full_price(): void {
		// bogo_discount = 1 ("free"): the discounted unit must cost $0, so a
		// 2-unit pair @ $10 blends to $5/unit → charged $10 == displayed $10.
		// The bug charged $20 (full price) while the cart showed $10.
		$this->set_bogo_discount( 1.0 );
		self::assertSame( 5.0, Lafka_Promotions::blended_price( 10.0, 2, 1 ) );
	}

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_quarter_discount_blends_to_complement(): void {
		// bogo_discount = 0.25 (25% off): discounted unit costs $7.50, so a
		// 2-unit pair @ $10 blends to $8.75/unit → charged $17.50 == displayed
		// $17.50. The bug charged $12.50 while the cart showed $17.50.
		$this->set_bogo_discount( 0.25 );
		self::assertSame( 8.75, Lafka_Promotions::blended_price( 10.0, 2, 1 ) );
	}

	// ─── 0.5 stays value-preserving (the case the old test locked) ───────────

	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_half_discount_unchanged(): void {
		$this->set_bogo_discount( 0.5 );
		self::assertSame( 7.5, Lafka_Promotions::blended_price( 10.0, 2, 1 ) );
	}

	// ─── invariant: charge == display for arbitrary knob values ──────────────

	/**
	 * @return array<string,array{0:float,1:int,2:int,3:float}>
	 *         [ orig, qty, disc_qty, bogo_discount ]
	 */
	public static function reconciliation_cases(): array {
		return array(
			'free pair'            => array( 10.0, 2, 1, 1.0 ),
			'quarter off pair'     => array( 10.0, 2, 1, 0.25 ),
			'half off pair'        => array( 10.0, 2, 1, 0.5 ),
			'37% off four units'   => array( 12.0, 4, 2, 0.37 ),
			'free six units'       => array( 8.5, 6, 3, 1.0 ),
			'ten percent uneven'   => array( 9.99, 5, 2, 0.1 ),
		);
	}

	#[DataProvider( 'reconciliation_cases' )]
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_charge_reconciles_with_displayed_subtotal( float $orig, int $qty, int $disc_qty, float $discount ): void {
		$this->set_bogo_discount( $discount );

		// What WooCommerce charges for the line: blended unit price * qty.
		$charged = Lafka_Promotions::blended_price( $orig, $qty, $disc_qty ) * $qty;

		// What render_bogo_subtotal() displays: orig_subtotal - savings, where
		// savings mirrors apply_bogo_to_cart()'s computation exactly.
		$orig_subtotal = $orig * $qty;
		$savings       = $orig * (float) Lafka_Promotions::knob( 'bogo_discount' ) * $disc_qty;
		$displayed     = $orig_subtotal - $savings;

		self::assertEqualsWithDelta(
			$displayed,
			$charged,
			1e-9,
			'Charged line total must equal the displayed (orig_subtotal - savings) total.'
		);
	}
}
