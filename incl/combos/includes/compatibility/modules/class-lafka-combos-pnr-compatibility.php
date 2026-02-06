<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Points and Rewards Compatibility.
 *
 * @version  5.5.0
 */
class WC_LafkaCombos_PnR_Compatibility {

	/**
	 * Combo points - @see 'WC_LafkaCombos_PnR_Compatibility::replace_points'.
	 * @var boolean
	 */
	private static $combo_price_max = false;
	private static $combo_price_min = false;

	/**
	 * Bypass 'wc_points_rewards_single_product_message' filter.
	 * @var boolean
	 */
	private static $single_product_message_filter_active = true;

	/**
	 * Initialize.
	 */
	public static function init() {

		// Points earned filters.
		add_filter( 'woocommerce_points_earned_for_cart_item', array( __CLASS__, 'points_earned_for_combined_cart_item' ), 10, 3 );
		add_filter( 'woocommerce_points_earned_for_order_item', array( __CLASS__, 'points_earned_for_combined_order_item' ), 10, 5 );

		// Change earn points message for Combos that contain individually-priced items.
		add_filter( 'wc_points_rewards_single_product_message', array( __CLASS__, 'points_rewards_combo_message' ), 10, 2 );

		// Remove PnR message from combined variations.
		add_filter( 'option_wc_points_rewards_single_product_message', array( __CLASS__, 'return_empty_message' ) );
	}

	/**
	 * Return zero points for combined cart items if container item has product- or category-level points or combined item is not priced individually.
	 *
	 * @param  int     $points
	 * @param  string  $cart_item_key
	 * @param  array   $cart_item_values
	 * @return int
	 */
	public static function points_earned_for_combined_cart_item( $points, $cart_item_key, $cart_item_values ) {

		if ( $parent = wc_pc_get_combined_cart_item_container( $cart_item_values ) ) {

			$combo          = $parent[ 'data' ];
			$combined_item_id = $cart_item_values[ 'combined_item_id' ];
			$combined_item    = $combo->get_combined_item( $combined_item_id );

			if ( self::has_fixed_points( $combo ) || false === $combined_item->is_priced_individually() ) {
				$points = 0;
			} else {
				$points = WC_Points_Rewards_Manager::calculate_points( $cart_item_values[ 'data' ]->get_price() );
			}
		}

		return $points;
	}

	/**
	 * Return zero points for combined order items if container item has product- or category-level points or combined item is not priced individually.
	 *
	 * @param  int       $points
	 * @param  string    $item_key
	 * @param  array     $item
	 * @param  WC_Order  $order
	 * @return int
	 */
	public static function points_earned_for_combined_order_item( $points, $product, $item_key, $item, $order ) {

		if ( $parent_item = wc_pc_get_combined_order_item_container( $item, $order ) ) {

			$combined_item_priced_individually = isset( $item[ 'combined_item_priced_individually' ] ) ? 'yes' === $item[ 'combined_item_priced_individually' ] : null;

			if ( $combo = wc_get_product( $parent_item[ 'product_id' ] ) ) {

				// Back-compat.
				if ( null === $combined_item_priced_individually ) {
					if ( isset( $parent_item[ 'per_product_pricing' ] ) ) {
						$combined_item_priced_individually = 'yes' === $parent_item[ 'per_product_pricing' ];
					} elseif ( isset( $item[ 'combined_item_id' ] ) ) {
						$combined_item_id                  = $item[ 'combined_item_id' ];
						$combined_item                     = $combo->get_combined_item( $combined_item_id );
						$combined_item_priced_individually = ( $combined_item instanceof WC_Combined_Item ) ? $combined_item->is_priced_individually() : false;
					}
				}

				if ( self::has_fixed_points( $combo ) || false === $combined_item_priced_individually ) {
					$points = 0;
				} else {
					$points = WC_Points_Rewards_Manager::calculate_points( $product->get_price() );
				}
			}
		}

		return $points;
	}

	/**
	 * Points and Rewards single product message for Combos.
	 *
	 * @param  string                     $message
	 * @param  WC_Points_Rewards_Product  $points_n_rewards
	 * @return string
	 */
	public static function points_rewards_combo_message( $message, $points_n_rewards ) {

		global $product;

		if ( $product->is_type( 'combo' ) && self::$single_product_message_filter_active ) {

			if ( false === self::has_fixed_points( $product ) && $product->contains( 'priced_individually' ) ) {

				$max_combo_price = $product->get_combo_price( 'max' );
				$min_combo_price = $product->get_combo_price( 'min' );

				if ( '' !== $max_combo_price ) {
					self::$combo_price_max = $max_combo_price;
				} else {
					self::$combo_price_min = $min_combo_price;
				}

				// 'WC_Points_Rewards_Product' relies on 'get_price', which only returns the base price of a combo.
				add_filter( 'woocommerce_product_get_price', array( __CLASS__, 'replace_price' ), 9999, 2 );

				$combo_points = WC_Points_Rewards_Product::get_points_earned_for_product_purchase( $product );

				if ( '' !== $max_combo_price ) {

					if ( $max_combo_price === $min_combo_price ) {
						self::$single_product_message_filter_active = false;
						$message = $points_n_rewards->render_product_message();
						self::$single_product_message_filter_active = true;
					} else {
						$message = $points_n_rewards->create_variation_message_to_product_summary( $combo_points );
					}

				} else {
					$message = $points_n_rewards->create_at_least_message_to_product_summary( $combo_points );
				}

				remove_filter( 'woocommerce_product_get_price', array( __CLASS__, 'replace_price' ), 9999, 2 );

				self::$combo_price_min = self::$combo_price_max = false;
			}
		}

		return $message;
	}

	/**
	 * @see points_rewards_remove_price_html_messages
	 *
	 * @param  string  $message
	 * @return void
	 */
	public static function return_empty_message( $message ) {
		if ( did_action( 'woocommerce_combined_product_price_filters_added' ) > did_action( 'woocommerce_combined_product_price_filters_removed' ) ) {
			$message = false;
		}
		return $message;
	}

	/**
	 * Filter combo price returned by 'get_price' to return the min/max combo price.
	 *
	 * @param  mixed              $price
	 * @param  WC_Product_Combo  $product
	 * @return mixed
	 */
	public static function replace_price( $price, $product ) {
		if ( false !== self::$combo_price_max ) {
			$price = self::$combo_price_max;
		} elseif ( false !== self::$combo_price_min ) {
			$price = self::$combo_price_min;
		}
		return $price;
	}

	/**
	 * True if the combo has fixed product- or category-level points.
	 *
	 * @param  WC_Product_Combo  $combo
	 * @return boolean
	 */
	private static function has_fixed_points( $combo ) {

		$combo_product_points  = WC_Points_Rewards_Product::get_product_points( $combo );
		$combo_category_points = is_callable( array( 'WC_Points_Rewards_Product', 'get_category_points' ) ) ? WC_Points_Rewards_Product::get_category_points( $combo ) : '';

		return is_numeric( $combo_product_points ) || is_numeric( $combo_category_points );
	}
}

WC_LafkaCombos_PnR_Compatibility::init();
