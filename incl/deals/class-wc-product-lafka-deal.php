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
		 * Purchasable when it has a price and at least one slot.
		 *
		 * @return bool
		 */
		public function is_purchasable() {
			$purchasable = $this->exists() && 'publish' === $this->get_status() && '' !== (string) $this->get_price() && array() !== Lafka_Deals::get_slots( $this );
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
