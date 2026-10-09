<?php
/**
 * The Deal product type. Its price is the deal price; it is never a cart
 * line itself — the builder adds the chosen items (Lafka_Deals_Builder).
 *
 * @package Lafka\Plugin\Deals
 * @since   10.4.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'WC_Product_Lafka_Deal' ) && class_exists( 'WC_Product' ) ) {

	/**
	 * Deal product.
	 */
	class WC_Product_Lafka_Deal extends WC_Product {

		/**
		 * Product type.
		 *
		 * @return string
		 */
		public function get_type() {
			return Lafka_Deals::TYPE;
		}

		/**
		 * The deal price. A discount deal (percent / amount off, cheapest
		 * free) has no price of its own: it reads as the least it can cost,
		 * so lists and cards have a "from" figure.
		 *
		 * @param string $context 'view' or 'edit'.
		 * @return string
		 */
		public function get_price( $context = 'view' ) {
			if ( 'view' === $context && ! Lafka_Deals::is_fixed( $this ) ) {
				return (string) Lafka_Deals::from_price( $this );
			}
			return parent::get_price( $context );
		}

		/**
		 * "From $x" for a discount deal, the price otherwise.
		 *
		 * @param string $deprecated Unused.
		 * @return string
		 */
		public function get_price_html( $deprecated = '' ) {
			if ( Lafka_Deals::is_fixed( $this ) ) {
				return parent::get_price_html( $deprecated );
			}
			/* translators: 1: lowest price, 2: the offer, e.g. "20% off". */
			$html = sprintf( __( 'From %1$s (%2$s)', 'lafka-plugin' ), wc_price( (float) $this->get_price() ), esc_html( Lafka_Deals::offer_text( $this ) ) );
			return (string) apply_filters( 'woocommerce_get_price_html', $html, $this );
		}

		/**
		 * Purchasable when it has a price (or discounts the chosen items), at
		 * least one slot, and nothing stops it today: its days and hours, its
		 * limits (Lafka_Deals::unavailable_reason()).
		 *
		 * @return bool
		 */
		public function is_purchasable() {
			return $this->purchasable( false );
		}

		/**
		 * Whether a deal already in the cart can stay there. The same gate, but
		 * a customer's own use limit does not remove it: the cart and checkout
		 * refuse that with a message instead (Lafka_Deals_Conditions).
		 *
		 * @return bool
		 */
		public function is_purchasable_in_cart(): bool {
			return $this->purchasable( true );
		}

		/**
		 * The purchasable gate.
		 *
		 * @param bool $in_cart Judging a deal already in the cart.
		 * @return bool
		 */
		private function purchasable( bool $in_cart ): bool {
			$purchasable = $this->exists() && 'publish' === $this->get_status() && '' !== (string) $this->get_price() && array() !== Lafka_Deals::get_slots( $this ) && '' === Lafka_Deals::unavailable_reason( $this, $in_cart );
			return (bool) apply_filters( 'woocommerce_is_purchasable', $purchasable, $this );
		}

		/**
		 * Archive buttons link to the builder.
		 *
		 * @return string
		 */
		public function add_to_cart_url() {
			return (string) apply_filters( 'woocommerce_product_add_to_cart_url', $this->get_permalink(), $this );
		}

		/**
		 * Archive button label.
		 *
		 * @return string
		 */
		public function add_to_cart_text() {
			return (string) apply_filters( 'woocommerce_product_add_to_cart_text', __( 'Build your deal', 'lafka-plugin' ), $this );
		}

		/**
		 * Single-page button label (the builder's own button reads this too).
		 *
		 * @return string
		 */
		public function single_add_to_cart_text() {
			return (string) apply_filters( 'woocommerce_product_single_add_to_cart_text', __( 'Add to order', 'lafka-plugin' ), $this );
		}

		/**
		 * Never an AJAX add from a list: the items must be chosen first.
		 *
		 * @param string $feature Feature.
		 * @return bool
		 */
		public function supports( $feature ) {
			return 'ajax_add_to_cart' === $feature ? false : parent::supports( $feature );
		}

		/**
		 * The deal has no shipping of its own.
		 *
		 * @return bool
		 */
		public function is_virtual() {
			return true;
		}
	}
}
