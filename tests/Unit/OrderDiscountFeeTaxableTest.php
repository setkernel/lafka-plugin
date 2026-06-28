<?php
declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Brain\Monkey;
use Brain\Monkey\Functions;

require_once dirname( __DIR__, 2 ) . '/incl/woocommerce/lafka-first-order.php';

/**
 * Regression coverage for f046.
 *
 * Order-level promo discounts (first-order / slow-day / combo) used to be added
 * as a single NON-taxable negative fee, so WooCommerce left tax on the full
 * pre-discount line subtotals and the customer was over-charged ~the tax on the
 * discount. The coordinator now adds the combined fee TAXABLE with the cart's
 * dominant tax class, so its negative tax nets the over-collected line tax back
 * out — the discount reduces the taxable base, consistent with the BOGO module,
 * which lowers the base via set_price().
 */
final class OrderDiscountFeeTaxableTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( '__' )->returnArg( 1 );
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'apply_filters' )->returnArg( 2 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	// ---- Tax-class resolution -------------------------------------------------

	public function test_tax_class_is_the_dominant_class_by_subtotal(): void {
		$cart = self::makeCartWithItems(
			array(
				array( 'food', 80.0, true ),
				array( 'drinks', 20.0, true ),
			)
		);
		self::assertSame( 'food', lafka_order_discount_tax_class( $cart ) );
	}

	public function test_tax_class_ignores_non_taxable_lines(): void {
		// The biggest line is non-taxable, so it must not win the tax class.
		$cart = self::makeCartWithItems(
			array(
				array( 'gift-card', 500.0, false ),
				array( 'food', 30.0, true ),
			)
		);
		self::assertSame( 'food', lafka_order_discount_tax_class( $cart ) );
	}

	public function test_tax_class_defaults_to_standard_without_a_cart(): void {
		self::assertSame( '', lafka_order_discount_tax_class( (object) array() ) );
	}

	// ---- Coordinator marks the fee taxable ------------------------------------

	public function test_combined_fee_is_taxable_with_dominant_class(): void {
		Functions\when( 'wc_tax_enabled' )->justReturn( true );
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value = null ) {
				if ( 'lafka_order_discount_components' === $hook ) {
					return array(
						array(
							'type'  => 'percent',
							'value' => 15.0,
							'label' => 'First-order discount',
						),
					);
				}
				return $value;
			}
		);

		$cart = self::makeCartWithItems(
			array(
				array( 'food', 80.0, true ),
				array( 'drinks', 20.0, true ),
			),
			100.0
		);
		lafka_order_discount_apply( $cart );

		self::assertCount( 1, $cart->fees, 'exactly one combined fee must still be added' );
		self::assertSame( -15.0, $cart->fees[0]['amount'] );
		self::assertTrue( $cart->fees[0]['taxable'], 'the discount fee must be taxable so it reduces the taxable base' );
		self::assertSame( 'food', $cart->fees[0]['tax_class'], 'the fee must carry the cart dominant tax class' );
	}

	public function test_fee_is_not_taxable_when_store_taxes_disabled(): void {
		Functions\when( 'wc_tax_enabled' )->justReturn( false );
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value = null ) {
				if ( 'lafka_order_discount_components' === $hook ) {
					return array(
						array(
							'type'  => 'percent',
							'value' => 10.0,
							'label' => 'Slow-day special',
						),
					);
				}
				return $value;
			}
		);

		$cart = self::makeCartWithItems( array( array( 'food', 50.0, true ) ), 50.0 );
		lafka_order_discount_apply( $cart );

		self::assertCount( 1, $cart->fees );
		self::assertFalse( $cart->fees[0]['taxable'], 'with store taxes off the fee carries no tax flag' );
	}

	// ---- Test doubles ---------------------------------------------------------

	/**
	 * Minimal WC_Cart double whose items expose tax class + taxability and whose
	 * add_fee() captures the 4-arg ( name, amount, taxable, tax_class ) call.
	 *
	 * @param array<int,array{0:string,1:float,2:bool}> $items [ tax_class, line_subtotal, is_taxable ]
	 * @param float                                     $subtotal
	 * @return object
	 */
	private static function makeCartWithItems( array $items, float $subtotal = 0.0 ): object {
		$cart_items = array();
		foreach ( $items as $spec ) {
			list( $tax_class, $line, $taxable ) = $spec;
			$cart_items[] = array(
				'line_subtotal' => $line,
				'data'          => new class( $tax_class, $taxable ) {
					public function __construct( private string $tax_class, private bool $taxable ) {}
					public function is_taxable(): bool {
						return $this->taxable;
					}
					public function get_tax_class(): string {
						return $this->tax_class;
					}
				},
			);
		}

		return new class( $cart_items, $subtotal ) {
			/** @var array<int,array<string,mixed>> */
			public array $fees = array();
			/** @param array<int,mixed> $cart_items */
			public function __construct( private array $cart_items, private float $subtotal ) {}
			/** @return array<int,mixed> */
			public function get_cart(): array {
				return $this->cart_items;
			}
			public function get_subtotal(): float {
				return $this->subtotal;
			}
			public function get_discount_total(): float {
				return 0.0;
			}
			public function add_fee( $name, $amount, $taxable = false, $tax_class = '' ): void {
				$this->fees[] = array(
					'name'      => $name,
					'amount'    => $amount,
					'taxable'   => $taxable,
					'tax_class' => $tax_class,
				);
			}
		};
	}
}
