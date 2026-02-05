<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cost of Goods Compatibility.
 *
 * @version  5.11.0
 */
class WC_LafkaCombos_COG_Compatibility {

	/**
	 * Initialize integration.
	 */
	public static function init() {

		// Filter parent/child cost meta.
		add_filter( 'wc_cost_of_goods_set_order_item_cost_meta_item_cost', array( __CLASS__, 'set_combined_order_item_cost' ), 10, 3 );

		// Update combined item cost meta when calling 'WC_LafkaCombos_Order::add_combo_to_order'.
		add_filter( 'woocommerce_combo_added_to_order', array( __CLASS__, 'set_combo_added_to_order_item_cost' ), 10, 2 );
	}

	/**
	 * Update combined item cost meta when calling 'WC_LafkaCombos_Order::add_combo_to_order'.
	 *
	 * @since  5.11.0
	 *
	 * @param  WC_Order_Item  $container_order_item
	 * @param  WC_Order       $order
	 * @return void
	 */
	public static function set_combo_added_to_order_item_cost( $container_order_item, $order ) {
		wc_cog()->set_order_cost_meta( $order->get_id(), true );
	}

	/**
	 * Cost of goods compatibility: Zero order item cost for combined products that belong to statically priced combos.
	 *
	 * @param  double    $cost
	 * @param  array     $item
	 * @param  WC_Order  $order
	 * @return double
	 */
	public static function set_combined_order_item_cost( $cost, $item, $order ) {

		if ( $parent_item = wc_pc_get_combined_order_item_container( $item, $order ) ) {

			$combined_item_priced_individually = isset( $item[ 'combined_item_priced_individually' ] ) ? 'yes' === $item[ 'combined_item_priced_individually' ] : null;

			// Back-compat.
			if ( null === $combined_item_priced_individually ) {
				if ( isset( $parent_item[ 'per_product_pricing' ] ) ) {
					$combined_item_priced_individually = 'yes' === $parent_item[ 'per_product_pricing' ];
				} elseif ( isset( $item[ 'combined_item_id' ] ) ) {
					if ( $combo = wc_get_product( $parent_item[ 'product_id' ] ) ) {
						$combined_item_id                  = $item[ 'combined_item_id' ];
						$combined_item                     = $combo->get_combined_item( $combined_item_id );
						$combined_item_priced_individually = ( $combined_item instanceof WC_Combined_Item ) ? $combined_item->is_priced_individually() : false;
					}
				}
			}

			if ( false === $combined_item_priced_individually ) {
				$cost = 0;
			}
		}

		return $cost;
	}
}

WC_LafkaCombos_COG_Compatibility::init();
