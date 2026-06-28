<?php
/**
 * First-order discount — standalone, always-loaded.
 *
 * An automatic percentage discount on a customer's FIRST order, to convert
 * first-time visitors (esp. those arriving from the delivery apps) into direct
 * customers. Like free-delivery, it is its own independently-toggled feature
 * (activates purely when the percent is > 0), NOT behind the BOGO module gate.
 *
 * Abuse-resistance: eligibility is intentionally limited to LOGGED-IN customers
 * with zero prior orders — a guest has no history, so a guest-eligible perk
 * could be claimed forever. Requiring an account also improves retention +
 * tracking. The eligibility test is filterable for operators who want to widen
 * it (e.g. first-order-by-billing-email at checkout).
 *
 * @package Lafka\Plugin\WooCommerce
 * @since   9.33.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'lafka_first_order_discount_percent' ) ) {
	/**
	 * SSOT first-order discount percent (0 = off). Source order:
	 * filter → option → Customizer theme_mod → 0.
	 *
	 * @return float 0–100.
	 */
	function lafka_first_order_discount_percent(): float {
		$percent = 0.0;
		if ( function_exists( 'get_option' ) ) {
			$percent = (float) get_option( 'lafka_first_order_discount_percent', 0 );
		}
		if ( $percent <= 0 && function_exists( 'get_theme_mod' ) ) {
			$percent = (float) get_theme_mod( 'lafka_first_order_discount_percent', 0 );
		}
		$percent = (float) apply_filters( 'lafka_first_order_discount_percent', $percent );
		return min( 100.0, max( 0.0, $percent ) );
	}
}

if ( ! function_exists( 'lafka_is_first_order_customer' ) ) {
	/**
	 * Whether the current visitor qualifies as a first-time customer.
	 * Logged-in + zero prior orders. Filterable.
	 *
	 * @return bool
	 */
	function lafka_is_first_order_customer(): bool {
		$eligible = false;
		if ( function_exists( 'is_user_logged_in' ) && is_user_logged_in() && function_exists( 'wc_get_customer_order_count' ) ) {
			$eligible = 0 === (int) wc_get_customer_order_count( get_current_user_id() );
		}
		return (bool) apply_filters( 'lafka_is_first_order_customer', $eligible );
	}
}

if ( ! function_exists( 'lafka_first_order_eligible' ) ) {
	/** @return bool Feature on AND visitor qualifies. */
	function lafka_first_order_eligible(): bool {
		return lafka_first_order_discount_percent() > 0 && lafka_is_first_order_customer();
	}
}

if ( ! function_exists( 'lafka_first_order_discount_amount' ) ) {
	/**
	 * Pure discount math (testable): percent of a subtotal, 2dp, never negative.
	 *
	 * @param float $subtotal
	 * @param float $percent
	 * @return float
	 */
	function lafka_first_order_discount_amount( float $subtotal, float $percent ): float {
		if ( $percent <= 0 || $subtotal <= 0 ) {
			return 0.0;
		}
		return round( $subtotal * ( min( 100.0, $percent ) / 100 ), 2 );
	}
}

if ( ! function_exists( 'lafka_first_order_discount_component' ) ) {
	add_filter( 'lafka_order_discount_components', 'lafka_first_order_discount_component', 10, 2 );
	/**
	 * Feed the first-order discount into the shared order-discount coordinator
	 * (lafka_order_discount_apply) instead of adding its own cart fee. Returning a
	 * percentage component lets the coordinator stack it sequentially with the
	 * other promos under ONE combined, capped fee — so it can never push the order
	 * to a free/negative total on its own or alongside slow-day / combo.
	 *
	 * @param array         $components Discount components collected so far.
	 * @param \WC_Cart|null $cart      Current cart (unused; eligibility is contextual).
	 * @return array
	 */
	function lafka_first_order_discount_component( $components, $cart = null ) {
		if ( ! is_array( $components ) ) {
			$components = array();
		}
		if ( ! lafka_first_order_eligible() ) {
			return $components;
		}
		$percent = lafka_first_order_discount_percent();
		if ( $percent > 0 ) {
			$components[] = array(
				'source' => 'first_order',
				'type'   => 'percent',
				'value'  => $percent,
				'label'  => sprintf(
					/* translators: %s = discount percent, e.g. 15 */
					__( 'First-order discount (%s%% off)', 'lafka-plugin' ),
					(string) ( (float) $percent )
				),
			);
		}
		return $components;
	}
}

if ( ! function_exists( 'lafka_order_discount_combined' ) ) {
	/**
	 * Shared, pure aggregation for all order-level percentage/fixed promos.
	 *
	 * Percentages are applied SEQUENTIALLY against a diminishing balance (so two
	 * 60%-off promos discount 60%, then 60% of the remainder — never 120% of the
	 * raw subtotal), the fixed amount is then taken from what is left, and the
	 * final combined discount is clamped so it can never exceed the payable base
	 * (subtotal minus any coupon discount already applied). This is what prevents
	 * stacked promos — or promos plus coupons — from clamping the total to $0.
	 *
	 * @param float   $base               Pre-coupon subtotal the promos discount.
	 * @param float[] $percents           Percentage promos (each treated as 0–100).
	 * @param float   $fixed              Combined fixed-amount promos.
	 * @param float   $already_discounted Coupon discount already applied to the base.
	 * @return float Non-negative combined discount, 2dp, never above the payable base.
	 */
	function lafka_order_discount_combined( float $base, array $percents, float $fixed = 0.0, float $already_discounted = 0.0 ): float {
		$base = max( 0.0, $base );
		if ( $base <= 0.0 ) {
			return 0.0;
		}
		$remaining = $base;
		foreach ( $percents as $percent ) {
			$percent = (float) $percent;
			if ( $percent <= 0.0 ) {
				continue;
			}
			$percent = min( 100.0, $percent );
			$cut     = round( $remaining * ( $percent / 100 ), 2 );
			if ( $cut > $remaining ) {
				$cut = $remaining;
			}
			$remaining -= $cut;
		}
		$fixed = max( 0.0, $fixed );
		if ( $fixed > 0.0 ) {
			$remaining -= min( $fixed, $remaining );
		}
		$discount = round( $base - $remaining, 2 );
		// Never discount more than the customer still owes (subtotal minus coupons).
		$payable_cap = max( 0.0, $base - max( 0.0, $already_discounted ) );
		if ( $discount > $payable_cap ) {
			$discount = $payable_cap;
		}
		return max( 0.0, round( $discount, 2 ) );
	}
}

if ( ! function_exists( 'lafka_order_discount_tax_class' ) ) {
	/**
	 * Tax class to assign to the combined order-discount fee.
	 *
	 * The fee is added TAXABLE (see lafka_order_discount_apply) so its negative
	 * tax nets out the tax WooCommerce charged on the un-discounted line
	 * subtotals — i.e. so the discount reduces the TAXABLE base, matching the
	 * BOGO module (class-lafka-promotions.php), which lowers the base via
	 * set_price(). The discount is spread proportionally across the whole cart,
	 * so the single fee carries the tax class holding the largest share of the
	 * (ex-tax) cart subtotal. Only taxable line items count toward that share.
	 *
	 * For the typical single-tax-class cart this is exact; a cart that genuinely
	 * mixes tax classes cannot be netted exactly by one fee — it is approximated
	 * via the dominant class, and operators can pin an exact class through the
	 * `lafka_order_discount_tax_class` filter.
	 *
	 * @param \WC_Cart|object $cart Current cart.
	 * @return string WooCommerce tax-class slug ('' = standard rate).
	 */
	function lafka_order_discount_tax_class( $cart ): string {
		$by_class = array();
		if ( is_object( $cart ) && method_exists( $cart, 'get_cart' ) ) {
			foreach ( (array) $cart->get_cart() as $item ) {
				if ( empty( $item['data'] ) || ! is_object( $item['data'] ) ) {
					continue;
				}
				$product = $item['data'];
				if ( method_exists( $product, 'is_taxable' ) && ! $product->is_taxable() ) {
					continue;
				}
				$class              = method_exists( $product, 'get_tax_class' ) ? (string) $product->get_tax_class() : '';
				$line               = isset( $item['line_subtotal'] ) ? (float) $item['line_subtotal'] : 0.0;
				$by_class[ $class ] = ( $by_class[ $class ] ?? 0.0 ) + $line;
			}
		}
		if ( array() !== $by_class ) {
			arsort( $by_class );
		}
		$tax_class = array() === $by_class ? '' : (string) array_key_first( $by_class );
		/**
		 * Filter the tax class assigned to the combined order-discount fee.
		 * Lets operators with mixed-tax-class carts pin an exact class so the
		 * fee's negative tax nets the line tax out precisely.
		 *
		 * @param string          $tax_class Resolved tax-class slug ('' = standard).
		 * @param \WC_Cart|object $cart      Current cart.
		 */
		return (string) apply_filters( 'lafka_order_discount_tax_class', $tax_class, $cart );
	}
}

if ( ! function_exists( 'lafka_order_discount_apply' ) ) {
	add_action( 'woocommerce_cart_calculate_fees', 'lafka_order_discount_apply' );
	/**
	 * Order-level discount coordinator.
	 *
	 * Replaces the former per-module cart-fee hooks (first-order, slow-day, combo
	 * each added their own negative fee off the raw subtotal with only an
	 * individual cap, so they stacked additively and could exceed 100% → free
	 * orders). It gathers every enabled promo via the
	 * `lafka_order_discount_components` filter, aggregates them with
	 * lafka_order_discount_combined(), and adds ONE capped negative fee. This is
	 * the single place that enforces the combined cap, so no configuration of the
	 * individual promos can drive an order to a free/negative total.
	 *
	 * Tax treatment (the single agreed treatment for ALL order-level promos):
	 * the combined fee is added TAXABLE with the cart's dominant tax class so its
	 * negative tax nets out the tax WooCommerce charged on the un-discounted line
	 * subtotals — i.e. the discount reduces the TAXABLE base, matching the BOGO
	 * module (class-lafka-promotions.php), which lowers the base via set_price().
	 * The old non-taxable fee left tax on the full pre-discount subtotal and so
	 * over-charged the order in jurisdictions where discounts lower the taxable
	 * base. One fee carries one tax class, so this is exact for the common
	 * single-tax-class cart; carts that genuinely mix tax classes are netted via
	 * the dominant class — see lafka_order_discount_tax_class() and its filter.
	 *
	 * @param \WC_Cart $cart
	 * @return void
	 */
	function lafka_order_discount_apply( $cart ) {
		if ( is_admin() && ! ( defined( 'DOING_AJAX' ) && DOING_AJAX ) ) {
			return;
		}
		if ( ! is_object( $cart ) ) {
			return;
		}
		$components = apply_filters( 'lafka_order_discount_components', array(), $cart );
		if ( ! is_array( $components ) || array() === $components ) {
			return;
		}
		$percents = array();
		$fixed    = 0.0;
		$labels   = array();
		foreach ( $components as $component ) {
			if ( ! is_array( $component ) ) {
				continue;
			}
			$value = isset( $component['value'] ) ? (float) $component['value'] : 0.0;
			if ( $value <= 0.0 ) {
				continue;
			}
			if ( isset( $component['type'] ) && 'fixed' === $component['type'] ) {
				$fixed += $value;
			} else {
				$percents[] = $value;
			}
			if ( ! empty( $component['label'] ) ) {
				$labels[] = (string) $component['label'];
			}
		}
		if ( array() === $percents && $fixed <= 0.0 ) {
			return;
		}
		$already = 0.0;
		if ( method_exists( $cart, 'get_discount_total' ) ) {
			$already = (float) $cart->get_discount_total();
		}
		$amount = lafka_order_discount_combined( (float) $cart->get_subtotal(), $percents, $fixed, $already );
		if ( $amount <= 0.0 ) {
			return;
		}
		$label = array() === $labels ? __( 'Discount', 'lafka-plugin' ) : implode( ' + ', $labels );
		/**
		 * Filter the single combined discount fee label shown in the cart/checkout.
		 *
		 * @param string        $label  Default label (active-promo labels joined by " + ").
		 * @param string[]      $labels Individual active-promo labels.
		 * @param \WC_Cart|null $cart   Current cart.
		 */
		$label = (string) apply_filters( 'lafka_order_discount_label', $label, $labels, $cart );
		// Add the discount as a TAXABLE negative fee carrying the cart's dominant
		// tax class, so its negative tax nets out the tax charged on the
		// un-discounted line subtotals. The discount thus reduces the taxable
		// base, consistent with BOGO; a non-taxable fee (the old behaviour) left
		// tax on the full pre-discount subtotal and over-charged the order.
		$tax_class = lafka_order_discount_tax_class( $cart );
		$taxable   = function_exists( 'wc_tax_enabled' ) ? (bool) wc_tax_enabled() : true;
		$cart->add_fee( $label, -$amount, $taxable, $tax_class );
	}
}
