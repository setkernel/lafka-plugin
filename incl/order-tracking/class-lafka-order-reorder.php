<?php
/**
 * One-tap reorder.
 *
 * A logged-in customer reorders through WooCommerce's own order-again flow
 * (`?order_again=ID`), which empties the cart and re-adds every line with its
 * add-ons and half-and-half choices through `woocommerce_order_again_cart_item_data`.
 * This class adds the entry points: "Order this again" and "Track" in My
 * Account → Orders.
 *
 * WooCommerce's flow needs a login, so a guest on their order confirmation
 * gets a link that proves the order with its key and then runs the same
 * filters line by line.
 *
 * @package Lafka\Plugin\OrderTracking
 * @since   10.4.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Lafka_Order_Reorder' ) ) {

	/**
	 * Reorder entry points.
	 */
	final class Lafka_Order_Reorder {

		/**
		 * Hook in.
		 *
		 * @return void
		 */
		public static function init(): void {
			add_filter( 'woocommerce_my_account_my_orders_actions', array( __CLASS__, 'account_actions' ), 10, 2 );
			add_action( 'wp_loaded', array( __CLASS__, 'handle_guest' ), 30 );
			add_filter( 'render_block', array( __CLASS__, 'show_notices_on_block_cart' ), 10, 2 );
		}

		/**
		 * The block cart does not print WooCommerce's session notices, so the
		 * messages order-again leaves (items added, items unavailable, a deal to
		 * choose again) would never be seen. Print them above the cart.
		 *
		 * @param string              $content Block output.
		 * @param array<string,mixed> $block   Parsed block.
		 * @return string
		 */
		public static function show_notices_on_block_cart( $content, $block ): string {
			$content = (string) $content;
			if ( 'woocommerce/cart' !== ( $block['blockName'] ?? '' ) || 0 === wc_notice_count() ) {
				return $content;
			}
			ob_start();
			wc_print_notices();
			return ob_get_clean() . $content;
		}

		/**
		 * WooCommerce's order-again URL for an order (nonce included).
		 *
		 * @param WC_Order $order Order.
		 * @return string
		 */
		public static function owner_url( $order ): string {
			return wp_nonce_url( add_query_arg( 'order_again', $order->get_id(), wc_get_cart_url() ), 'woocommerce-order_again' );
		}

		/**
		 * The link that repeats an order from its confirmation page, where
		 * WooCommerce shows no order-again button: its own order-again URL for
		 * the logged-in owner, else a key-checked link for a guest. '' when the
		 * order cannot be repeated.
		 *
		 * @param WC_Order $order Order.
		 * @return string
		 */
		public static function url( $order ): string {
			if ( ! in_array( $order->get_status(), lafka_pdp_reorderable_statuses(), true ) ) {
				return '';
			}
			if ( is_user_logged_in() && current_user_can( 'order_again', $order->get_id() ) ) {
				return self::owner_url( $order );
			}

			return add_query_arg(
				array(
					'lafka_reorder' => $order->get_id(),
					'key'           => $order->get_order_key(),
					'_wpnonce'      => wp_create_nonce( 'lafka_reorder_' . $order->get_id() ),
				),
				wc_get_cart_url()
			);
		}

		/**
		 * My Account → Orders: "Track" while the order is open, "Order this again" once completed.
		 *
		 * @param array<string,array<string,string>> $actions Actions.
		 * @param WC_Order                           $order   Order.
		 * @return array<string,array<string,string>>
		 */
		public static function account_actions( $actions, $order ): array {
			$actions = (array) $actions;
			// The orders list only: the order page already shows the tracker and WooCommerce's own button.
			if ( ! $order instanceof WC_Order || is_wc_endpoint_url( 'view-order' ) || is_wc_endpoint_url( 'order-received' ) ) {
				return $actions;
			}
			if ( ! Lafka_Order_Tracking::state( $order )['final'] ) {
				$actions['lafka_track'] = array(
					'url'  => $order->get_view_order_url(),
					'name' => __( 'Track', 'lafka-plugin' ),
				);
			} elseif ( $order->has_status( 'completed' ) && current_user_can( 'order_again', $order->get_id() ) ) {
				$actions['lafka_reorder'] = array(
					'url'  => self::owner_url( $order ),
					'name' => __( 'Order this again', 'lafka-plugin' ),
				);
			}

			return $actions;
		}

		/**
		 * Guest reorder: check the order key and nonce, refill the cart, open the cart.
		 *
		 * @return void
		 */
		public static function handle_guest(): void {
			$order_id = lafka_input_get_int( 'lafka_reorder' );
			if ( $order_id <= 0 || ! function_exists( 'WC' ) ) {
				return;
			}
			if ( ! WC()->cart ) {
				wc_load_cart();
			}

			$order = wc_get_order( $order_id );
			$key   = lafka_input_get_text( 'key' );
			$valid = $order
				&& '' !== $key
				&& hash_equals( (string) $order->get_order_key(), $key )
				&& wp_verify_nonce( lafka_input_get_text( '_wpnonce' ), 'lafka_reorder_' . $order_id )
				&& in_array( $order->get_status(), lafka_pdp_reorderable_statuses(), true );
			if ( ! $valid ) {
				wc_add_notice( __( 'We could not repeat that order. Please add the items from the menu.', 'lafka-plugin' ), 'error' );
			} else {
				self::fill_cart( $order );
			}

			wp_safe_redirect( wc_get_cart_url() );
			exit;
		}

		/**
		 * Re-add an order's lines to the cart the way WooCommerce's order-again does.
		 *
		 * @param WC_Order $order Order.
		 * @return void
		 */
		private static function fill_cart( $order ): void {
			if ( apply_filters( 'woocommerce_empty_cart_when_order_again', true ) ) {
				WC()->cart->empty_cart();
			}

			$added   = 0;
			$missing = 0;
			$deals   = array();
			foreach ( $order->get_items() as $item ) {
				$product      = $item->get_product();
				$product_id   = (int) apply_filters( 'woocommerce_add_to_cart_product_id', $item->get_product_id() );
				$variation_id = (int) $item->get_variation_id();
				$quantity     = $item->get_quantity();
				$data         = apply_filters( 'woocommerce_order_again_cart_item_data', array(), $item, $order );
				if ( class_exists( 'Lafka_Deals_Cart' ) && isset( $data[ Lafka_Deals_Cart::REORDER_KEY ] ) ) {
					// A deal is chosen again, not repeated: the customer is sent to it below.
					$deals[ (int) $data[ Lafka_Deals_Cart::REORDER_KEY ] ] = true;
					continue;
				}
				$variations = array();
				foreach ( $item->get_meta_data() as $meta ) {
					if ( taxonomy_is_product_attribute( $meta->key ) ) {
						$variations[ 'attribute_' . sanitize_title( $meta->key ) ] = sanitize_title( $meta->value );
					} elseif ( meta_is_product_attribute( $meta->key, $meta->value, $product_id ) ) {
						$variations[ 'attribute_' . sanitize_title( $meta->key ) ] = html_entity_decode( wc_clean( $meta->value ), ENT_QUOTES, get_bloginfo( 'charset' ) );
					}
				}

				$passes = $product
					&& $product->is_in_stock()
					&& ( $variation_id || ! $product->is_type( 'variable' ) )
					&& apply_filters( 'woocommerce_add_to_cart_validation', true, $product_id, $quantity, $variation_id, $variations, $data );
				if ( $passes && WC()->cart->add_to_cart( $product_id, $quantity, $variation_id, $variations, $data ) ) {
					++$added;
				} else {
					++$missing;
				}
			}

			if ( $missing > 0 ) {
				wc_add_notice(
					sprintf(
						/* translators: %d: number of items. */
						_n( '%d item from your previous order is currently unavailable and could not be added to your cart.', '%d items from your previous order are currently unavailable and could not be added to your cart.', $missing, 'lafka-plugin' ),
						$missing
					),
					'error'
				);
			}
			if ( $added > 0 ) {
				wc_add_notice( __( 'The cart has been filled with the items from your previous order.', 'lafka-plugin' ) );
			}
			if ( $deals && class_exists( 'Lafka_Deals_Cart' ) ) {
				Lafka_Deals_Cart::notify_reordered_deals( array_keys( $deals ) );
			}
		}
	}
}
