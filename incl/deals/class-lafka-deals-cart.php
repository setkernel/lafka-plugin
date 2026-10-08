<?php
/**
 * Deals in the cart: price the chosen items, keep each deal whole, and carry
 * it onto the order.
 *
 * Each chosen item is a normal cart line with `lafka_deal` data:
 *   { deal_id, deal_name, group, slot, slot_label, slots, reference, upcharge }
 * `reference` is the item's base price for the split (its à la carte price,
 * or the slot's cheapest price when the slot charges premiums, in which case
 * `upcharge` is the premium). Line price = share of the deal price (split by
 * reference, the last line taking the rounding) + upcharge + its add-ons.
 *
 * @package Lafka\Plugin\Deals
 * @since   10.4.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Lafka_Deals_Cart' ) ) {

	/**
	 * Deal pricing and integrity.
	 */
	final class Lafka_Deals_Cart {

		/** Cart item data marking a deal line that "order again" must not re-add (the value is the deal id). */
		const REORDER_KEY = 'lafka_reorder_deal';

		/**
		 * Re-entrancy guard for group removal / restore.
		 *
		 * @var bool
		 */
		private static $busy = false;

		/**
		 * Hook in.
		 *
		 * @return void
		 */
		public static function init(): void {
			// After the add-on engine (its price hooks run at 20 and earlier).
			add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'price_lines' ), 30 );
			add_filter( 'woocommerce_get_cart_item_from_session', array( __CLASS__, 'restore' ), 30, 2 );
			add_action( 'woocommerce_cart_loaded_from_session', array( __CLASS__, 'drop_broken_groups' ), 30 );
			add_action( 'woocommerce_cart_item_removed', array( __CLASS__, 'remove_group' ), 10, 2 );
			add_action( 'woocommerce_cart_item_restored', array( __CLASS__, 'restore_group' ), 10, 2 );
			add_action( 'woocommerce_after_cart_item_quantity_update', array( __CLASS__, 'lock_quantity' ), 10, 4 );
			add_filter( 'woocommerce_cart_item_quantity', array( __CLASS__, 'quantity_html' ), 10, 3 );
			add_filter( 'woocommerce_store_api_product_quantity_editable', array( __CLASS__, 'quantity_editable' ), 10, 3 );
			add_filter( 'woocommerce_get_item_data', array( __CLASS__, 'item_data' ), 5, 2 );
			add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'order_line_item' ), 10, 3 );
			add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'block_direct_add' ), 5, 2 );
			add_filter( 'woocommerce_order_again_cart_item_data', array( __CLASS__, 'order_again_data' ), 20, 2 );
			add_action( 'woocommerce_cart_loaded_from_session', array( __CLASS__, 'drop_reordered_deals' ), 31 );
		}

		/**
		 * Deal data of a cart item, or null.
		 *
		 * @param array<string,mixed> $item Cart item.
		 * @return array<string,mixed>|null
		 */
		public static function deal_of( array $item ): ?array {
			$deal = $item[ Lafka_Deals::CART_KEY ] ?? null;
			return is_array( $deal ) && ! empty( $deal['group'] ) ? $deal : null;
		}

		/**
		 * Cart items grouped by deal group key.
		 *
		 * @param array<string, array<string,mixed>> $contents Cart contents.
		 * @return array<string, array<string, array<string,mixed>>> group => [cart key => item]
		 */
		public static function groups( array $contents ): array {
			$groups = array();
			foreach ( $contents as $key => $item ) {
				$deal = self::deal_of( (array) $item );
				if ( $deal ) {
					$groups[ (string) $deal['group'] ][ $key ] = $item;
				}
			}
			return $groups;
		}

		/**
		 * The add-ons total of a cart item (after the engine's per-size pricing).
		 *
		 * @param array<string,mixed> $item Cart item.
		 * @return float
		 */
		private static function extras( array $item ): float {
			$total = 0.0;
			foreach ( (array) ( $item['addons'] ?? array() ) as $addon ) {
				$total += (float) ( $addon['price'] ?? 0 );
			}
			return $total;
		}

		/**
		 * Split a deal price across lines by reference price; the last line
		 * takes the rounding so the shares add up exactly.
		 *
		 * @param float                $price      Deal price.
		 * @param array<string, float> $references Cart key => reference price.
		 * @return array<string, float> Cart key => share.
		 */
		public static function split( float $price, array $references ): array {
			$sum = array_sum( $references );
			if ( $sum <= 0 ) {
				// No references (or only optional add-ons): split evenly.
				$references = array_fill_keys( array_keys( $references ), 1.0 );
				$sum        = (float) count( $references );
			}
			// The rounding goes to the last line that carries part of the price.
			$last = null;
			foreach ( $references as $key => $reference ) {
				if ( $reference > 0 ) {
					$last = $key;
				}
			}
			$shares = array();
			$left   = round( $price, 2 );
			foreach ( $references as $key => $reference ) {
				if ( $key === $last ) {
					continue;
				}
				$shares[ $key ] = round( $price * $reference / $sum, 2 );
				$left          -= $shares[ $key ];
			}
			$shares[ $last ] = round( $left, 2 );
			return $shares;
		}

		/**
		 * Set each deal line's price.
		 *
		 * @param WC_Cart $cart Cart.
		 * @return void
		 */
		public static function price_lines( $cart ): void {
			if ( ! $cart instanceof WC_Cart ) {
				return;
			}
			foreach ( self::groups( $cart->get_cart() ) as $items ) {
				$first = self::deal_of( reset( $items ) );
				$deal  = wc_get_product( (int) $first['deal_id'] );
				if ( ! $deal ) {
					continue;
				}
				$references = array();
				foreach ( $items as $key => $item ) {
					$references[ $key ] = (float) self::deal_of( $item )['reference'];
				}
				$shares = self::split( (float) $deal->get_price(), $references );
				foreach ( $items as $key => $item ) {
					$data = self::deal_of( $item );
					$cart->cart_contents[ $key ]['data']->set_price( $shares[ $key ] + (float) $data['upcharge'] + self::extras( $item ) );
				}
			}
		}

		/**
		 * Keep the deal data through the session.
		 *
		 * @param array<string,mixed> $item   Item being restored.
		 * @param array<string,mixed> $values Stored values.
		 * @return array<string,mixed>
		 */
		public static function restore( $item, $values ): array {
			$item = (array) $item;
			if ( isset( $values[ Lafka_Deals::CART_KEY ] ) ) {
				$item[ Lafka_Deals::CART_KEY ] = $values[ Lafka_Deals::CART_KEY ];
			}
			return $item;
		}

		/**
		 * Remove a group that lost a line (an item went out of stock or was
		 * deleted) or whose deal is gone: a half deal must never stay priced
		 * as a deal.
		 *
		 * @param WC_Cart $cart Cart.
		 * @return void
		 */
		public static function drop_broken_groups( $cart ): void {
			if ( ! $cart instanceof WC_Cart ) {
				return;
			}
			foreach ( self::groups( $cart->get_cart() ) as $items ) {
				$first = self::deal_of( reset( $items ) );
				$deal  = wc_get_product( (int) $first['deal_id'] );
				if ( $deal && $deal->is_purchasable() && count( $items ) === (int) $first['slots'] ) {
					continue;
				}
				self::$busy = true;
				foreach ( array_keys( $items ) as $key ) {
					$cart->remove_cart_item( $key );
				}
				self::$busy = false;
				/* translators: %s: deal name. */
				wc_add_notice( sprintf( __( '"%s" is no longer available as chosen and was removed from your order.', 'lafka-plugin' ), (string) $first['deal_name'] ), 'notice' );
			}
		}

		/**
		 * Removing one item of a deal removes the whole deal.
		 *
		 * @param string  $key  Removed cart key.
		 * @param WC_Cart $cart Cart.
		 * @return void
		 */
		public static function remove_group( $key, $cart ): void {
			if ( self::$busy || ! $cart instanceof WC_Cart ) {
				return;
			}
			$deal = self::deal_of( (array) ( $cart->removed_cart_contents[ $key ] ?? array() ) );
			if ( ! $deal ) {
				return;
			}
			self::$busy = true;
			foreach ( self::groups( $cart->get_cart() )[ $deal['group'] ] ?? array() as $other => $item ) {
				$cart->remove_cart_item( $other );
			}
			self::$busy = false;
		}

		/**
		 * "Undo" on one item of a deal brings the whole deal back.
		 *
		 * @param string  $key  Restored cart key.
		 * @param WC_Cart $cart Cart.
		 * @return void
		 */
		public static function restore_group( $key, $cart ): void {
			if ( self::$busy || ! $cart instanceof WC_Cart ) {
				return;
			}
			$deal = self::deal_of( (array) ( $cart->cart_contents[ $key ] ?? array() ) );
			if ( ! $deal ) {
				return;
			}
			self::$busy = true;
			foreach ( $cart->removed_cart_contents as $other => $item ) {
				$other_deal = self::deal_of( (array) $item );
				if ( $other_deal && $other_deal['group'] === $deal['group'] ) {
					$cart->restore_cart_item( $other );
				}
			}
			self::$busy = false;
		}

		/**
		 * Deal items are one each; a changed quantity snaps back to 1.
		 *
		 * @param string  $key      Cart key.
		 * @param int     $quantity New quantity.
		 * @param int     $old      Old quantity.
		 * @param WC_Cart $cart     Cart.
		 * @return void
		 */
		public static function lock_quantity( $key, $quantity, $old, $cart ): void {
			if ( $cart instanceof WC_Cart && 1 !== (int) $quantity && self::deal_of( (array) ( $cart->cart_contents[ $key ] ?? array() ) ) ) {
				$cart->cart_contents[ $key ]['quantity'] = 1;
			}
		}

		/**
		 * The classic cart shows "1" instead of a quantity box for deal items.
		 *
		 * @param string              $html Quantity HTML.
		 * @param string              $key  Cart key.
		 * @param array<string,mixed> $item Cart item.
		 * @return string
		 */
		public static function quantity_html( $html, $key, $item = array() ): string {
			return self::deal_of( (array) $item ) ? '<span class="lafka-deal-qty">1</span>' : (string) $html;
		}

		/**
		 * The block cart cannot change a deal item's quantity.
		 *
		 * @param bool                $editable Editable.
		 * @param WC_Product          $product  Product.
		 * @param array<string,mixed> $item     Cart item.
		 * @return bool
		 */
		public static function quantity_editable( $editable, $product, $item = array() ): bool {
			return self::deal_of( (array) $item ) ? false : (bool) $editable;
		}

		/**
		 * "Deal: Any 2 pizzas · Pizza 1" under the item name, first.
		 *
		 * @param array<int, array<string,string>> $data Item data.
		 * @param array<string,mixed>              $item Cart item.
		 * @return array<int, array<string,string>>
		 */
		public static function item_data( $data, $item ): array {
			$data = (array) $data;
			$deal = self::deal_of( (array) $item );
			if ( $deal ) {
				array_unshift(
					$data,
					array(
						'key'   => __( 'Deal', 'lafka-plugin' ),
						'value' => $deal['deal_name'] . ' · ' . $deal['slot_label'],
					)
				);
			}
			return $data;
		}

		/**
		 * Carry the deal onto the order line (visible meta for kitchen
		 * tickets and emails, hidden keys for reporting and reorder).
		 *
		 * @param WC_Order_Item_Product $line  Order line.
		 * @param string                $key   Cart key.
		 * @param array<string,mixed>   $item  Cart item.
		 * @return void
		 */
		public static function order_line_item( $line, $key, $item ): void {
			$deal = self::deal_of( (array) $item );
			if ( ! $deal || ! $line instanceof WC_Order_Item_Product ) {
				return;
			}
			$line->add_meta_data( __( 'Deal', 'lafka-plugin' ), $deal['deal_name'] . ' · ' . $deal['slot_label'] );
			$line->add_meta_data( '_lafka_deal_id', (int) $deal['deal_id'] );
			$line->add_meta_data( '_lafka_deal_group', (string) $deal['group'] );
			$line->add_meta_data( '_lafka_deal_slot', (int) $deal['slot'] );
		}

		/**
		 * A deal product itself never enters the cart; only its builder adds
		 * the chosen items.
		 *
		 * @param bool $passed     Validation so far.
		 * @param int  $product_id Product being added.
		 * @return bool
		 */
		public static function block_direct_add( $passed, $product_id ): bool {
			if ( $passed && Lafka_Deals::is_deal( (int) $product_id ) ) {
				wc_add_notice( __( 'Choose the items of this deal on its page.', 'lafka-plugin' ), 'error' );
				return false;
			}
			return (bool) $passed;
		}

		/**
		 * "Order again" does not re-apply a deal: it may have changed or ended, and
		 * its items are the customer's choice. A deal line is marked here so the
		 * cart can leave it out and point the customer at the deal instead.
		 *
		 * @param array<string,mixed>        $data Cart item data.
		 * @param WC_Order_Item_Product|null $item Order line.
		 * @return array<string,mixed>
		 */
		public static function order_again_data( $data, $item = null ): array {
			$data = (array) $data;
			unset( $data[ Lafka_Deals::CART_KEY ] );
			$deal_id = $item instanceof WC_Order_Item ? (int) $item->get_meta( '_lafka_deal_id' ) : 0;
			if ( $deal_id > 0 ) {
				$data[ self::REORDER_KEY ] = $deal_id;
			}
			return $data;
		}

		/**
		 * After WooCommerce's order-again filled the cart: take the deal lines
		 * out and tell the customer which deals to choose again.
		 *
		 * @param WC_Cart $cart Cart.
		 * @return void
		 */
		public static function drop_reordered_deals( $cart ): void {
			if ( ! $cart instanceof WC_Cart ) {
				return;
			}
			$deals = array();
			foreach ( $cart->cart_contents as $key => $item ) {
				if ( isset( $item[ self::REORDER_KEY ] ) ) {
					$deals[ (int) $item[ self::REORDER_KEY ] ] = true;
					unset( $cart->cart_contents[ $key ] );
				}
			}
			if ( $deals ) {
				self::notify_reordered_deals( array_keys( $deals ) );
			}
		}

		/**
		 * A notice per deal from the repeated order: choose its items again.
		 *
		 * @param int[] $deal_ids Deal product ids.
		 * @return void
		 */
		public static function notify_reordered_deals( array $deal_ids ): void {
			foreach ( $deal_ids as $deal_id ) {
				$deal = wc_get_product( (int) $deal_id );
				if ( $deal && $deal->is_purchasable() ) {
					wc_add_notice(
						sprintf(
							/* translators: 1: deal name, 2: link to the deal. */
							__( 'Your "%1$s" deal was not added. Choose its items again: %2$s', 'lafka-plugin' ),
							esc_html( $deal->get_name() ),
							'<a href="' . esc_url( $deal->get_permalink() ) . '">' . esc_html__( 'choose your items', 'lafka-plugin' ) . '</a>'
						),
						'notice'
					);
				} else {
					wc_add_notice( __( 'A deal from your previous order is no longer available.', 'lafka-plugin' ), 'notice' );
				}
			}
		}
	}
}
