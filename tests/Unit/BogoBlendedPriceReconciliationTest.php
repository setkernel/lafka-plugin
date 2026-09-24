<?php
/**
 * BOGO: what the cart charges must equal the subtotal it displays, for any
 * configured discount.
 *
 * Regression (f001): blended_price() treated `bogo_discount` as the fraction
 * the customer PAYS while the savings line and render_bogo_subtotal() treat it
 * as the fraction taken OFF. The two only agree at 0.5, so any other setting
 * charged a different amount than the cart showed (worst case "1 = free"
 * charged full price).
 *
 * Lafka_Promotions::knob() caches the option in a function-local static for
 * the life of the process, so each knob value needs its own process. The
 * charge is linear in the knob, so two values off 0.5 (a partial discount and
 * "free") pin it; 0.5 itself is covered in-process by LafkaPromotionsTest.
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
	 * @return array<string, array{0: float, 1: float}> [ bogo_discount, blended unit price of a $10 pair ]
	 */
	public static function discounts(): array {
		return array(
			'quarter off' => array( 0.25, 8.75 ), // $10 + $7.50 over 2 units.
			'free'        => array( 1.0, 5.0 ),   // $10 + $0 over 2 units.
		);
	}

	#[DataProvider( 'discounts' )]
	#[RunInSeparateProcess]
	#[PreserveGlobalState( false )]
	public function test_charged_total_equals_displayed_subtotal( float $discount, float $pair_unit_price ): void {
		Functions\when( 'get_option' )->justReturn( array( 'bogo_discount' => $discount ) );
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'did_action' )->justReturn( 1 );
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'wc_price' )->alias( static fn( $amount ) => sprintf( '[%.2f]', $amount ) );

		self::assertSame( $pair_unit_price, Lafka_Promotions::blended_price( 10.0, 2, 1 ) );

		// Six units: the three cheapest (the $4 side and two $9.60 pizzas) are discounted.
		$cart = self::cart(
			array(
				'pizza' => array( 9.60, 3 ),
				'wings' => array( 12.0, 2 ),
				'side'  => array( 4.0, 1 ),
			)
		);
		$promotions = Lafka_Promotions::instance();
		$promotions->apply_bogo_to_cart( $cart );

		$charged   = 0.0;
		$displayed = 0.0;
		foreach ( $cart->cart_contents as $key => $item ) {
			$line     = $item['data']->get_price() * $item['quantity'];
			$charged += $line;
			// Discounted lines render "<del>[was]</del> [now]<br>You save [x]"; others pass through.
			$html = $promotions->render_bogo_subtotal( sprintf( '[%.2f]', $line ), $item, $key );
			preg_match_all( '/\[(\d+\.\d{2})\]/', $html, $amounts );
			$shown = (float) ( 1 === count( $amounts[1] ) ? $amounts[1][0] : $amounts[1][1] );

			self::assertEqualsWithDelta( round( $line, 2 ), $shown, 0.005, "Line '{$key}' charges a different amount than it shows." );
			$displayed += $shown;
		}

		$full_price = 9.60 * 3 + 12.0 * 2 + 4.0;
		self::assertEqualsWithDelta( $full_price - $discount * ( 4.0 + 2 * 9.60 ), $charged, 1e-9 );
		self::assertEqualsWithDelta( $charged, $displayed, 0.01 );
	}

	/**
	 * A WC_Cart double: cart_contents keyed by item key, each with a product
	 * whose price apply_bogo_to_cart() rewrites.
	 *
	 * @param array<string, array{0: float, 1: int}> $lines key => [ unit price, qty ]
	 */
	private static function cart( array $lines ): object {
		$contents = array();
		foreach ( $lines as $key => $line ) {
			$contents[ $key ] = array(
				'quantity' => $line[1],
				'data'     => new class( $line[0] ) {
					public function __construct( private float $price ) {}
					public function get_price() {
						return $this->price;
					}
					public function set_price( $price ): void {
						$this->price = (float) $price;
					}
				},
			);
		}

		return new class( $contents ) {
			public function __construct( public array $cart_contents ) {}
			public function get_cart(): array {
				return $this->cart_contents;
			}
		};
	}
}
