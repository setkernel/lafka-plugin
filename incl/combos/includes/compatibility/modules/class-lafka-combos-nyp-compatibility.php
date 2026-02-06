<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * NYP Compatibility.
 *
 * @version  6.2.2
 */
class WC_LafkaCombos_NYP_Compatibility {

	/**
	 * NYP field name suffix.
	 *
	 * @var array
	 */
	protected static $nyp_suffix = '';

	/**
	 * Initialize!
	 */
	public static function init() {

		// Support for NYP.
		add_action( 'woocommerce_combined_product_add_to_cart', array( __CLASS__, 'nyp_price_input_support' ), 9, 2 );

		if( version_compare( WC_Name_Your_Price()->version, '3.0', '>=' ) ) {
			add_filter( 'wc_nyp_field_suffix', array( __CLASS__, 'nyp_cart_suffix' ), 10, 2 );
		} else {
			add_filter( 'nyp_field_prefix', array( __CLASS__, 'nyp_cart_suffix' ), 10, 2 );
		}

		// Validate add to cart NYP.
		add_filter( 'woocommerce_combined_item_add_to_cart_validation', array( __CLASS__, 'validate_combined_item_nyp' ), 10, 5 );

		// Add NYP identifier to combined item stamp.
		add_filter( 'woocommerce_combined_item_cart_item_identifier', array( __CLASS__, 'combined_item_nyp_stamp' ), 10, 2 );

		// Before and after add-to-cart handling.
		add_action( 'woocommerce_combined_item_before_add_to_cart', array( __CLASS__, 'before_combined_add_to_cart' ), 10, 5 );
		add_action( 'woocommerce_combined_item_after_add_to_cart', array( __CLASS__, 'after_combined_add_to_cart' ), 10, 5 );

		// Load child NYP data from the parent cart item data array.
		add_filter( 'woocommerce_combined_item_cart_data', array( __CLASS__, 'get_combined_cart_item_data_from_parent' ), 10, 2 );

	}

	/**
	 * Support for combined item NYP.
	 *
	 * @param  int              $product_id
	 * @param  WC_Combined_Item  $item
	 * @return void
	 */
	public static function nyp_price_input_support( $product_id, $item ) {

		global $product;

		$the_product = ! empty( WC_LafkaCombos_Compatibility::$compat_product ) ? WC_LafkaCombos_Compatibility::$compat_product : $product;

		if ( 'combo' === $the_product->get_type() && false === $item->is_priced_individually() ) {
			return;
		}

		if ( 'simple' === $item->product->get_type() ) {

			self::$nyp_suffix = $item->get_id();

			if( $item->is_optional() || ! $item->get_quantity( 'min' ) ) {
				add_filter( 'wc_nyp_data_attributes', array( __CLASS__, 'nyp_data_attributes' ) );
			}

			WC_Name_Your_Price()->display->display_price_input( $product_id, self::nyp_cart_suffix( false, $product_id ) );

			remove_filter( 'wc_nyp_data_attributes', array( __CLASS__, 'nyp_data_attributes' ) );

			self::$nyp_suffix = '';
		}
	}

	/**
	 * Adds combined item's optional setting to the NYP attributes.
	 *
	 * @since  6.0.4
	 *
	 * @param  array  $atrributes - The array of attributes.
	 * @return array
	 */
	public static function nyp_data_attributes( $attributes ) {
		$attributes[ 'optional' ] = 'yes';
		return $attributes;
	}

	/**
	 * Sets a unique suffix for unique NYP products. The suffix is set and re-set globally before validating and adding to cart.
	 *
	 * @param  string  $suffix
	 * @param  int     $product_id
	 * @return string
	 */
	public static function nyp_cart_suffix( $suffix, $product_id ) {

		if ( ! empty( self::$nyp_suffix ) ) {
			$suffix = '-' . self::$nyp_suffix;
		}

		if ( ! empty( WC_LafkaCombos_Compatibility::$combo_prefix ) ) {
			$sufffix = '-' . self::$nyp_suffix . '-' . WC_LafkaCombos_Compatibility::$combo_prefix;
		}

		return $suffix;
	}

	/**
	 * Add nyp identifier to combined item stamp, in order to generate new cart ids for combos with different nyp configurations.
	 *
	 * @param  array   $combined_item_stamp
	 * @param  string  $combined_item_id
	 * @return array
	 */
	public static function combined_item_nyp_stamp( $combined_item_stamp, $combined_item_id ) {

		$nyp_data = array();

		// Set nyp suffix.
		self::$nyp_suffix = $combined_item_id;

		$combined_product_id = $combined_item_stamp[ 'product_id' ];

		$nyp_data = WC_Name_Your_Price()->cart->add_cart_item_data( $nyp_data, $combined_product_id, '' );

		// Reset nyp suffix.
		self::$nyp_suffix = '';

		if ( ! empty( $nyp_data[ 'nyp' ] ) ) {
			$combined_item_stamp[ 'nyp' ] = $nyp_data[ 'nyp' ];
		}

		return $combined_item_stamp;
	}

	/**
	 * Validate combined item NYP.
	 *
	 * @param  bool  $add
	 * @param  int   $product_id
	 * @param  int   $quantity
	 * @return bool
	 */
	public static function validate_combined_item_nyp( $add, $combo, $combined_item, $quantity, $variation_id ) {

		// Ordering again? When ordering again, do not revalidate.
		$order_again = isset( $_GET[ 'order_again' ] ) && isset( $_GET[ '_wpnonce' ] ) && wp_verify_nonce( wc_clean( $_GET[ '_wpnonce' ] ), 'woocommerce-order_again' );

		if ( $order_again  ) {
			return $add;
		}

		$combined_item_id = $combined_item->get_id();
		$product_id      = $combined_item->get_product_id();

		if ( $combined_item->is_priced_individually() ) {

			self::$nyp_suffix = $combined_item_id;

			if ( ! WC_Name_Your_Price()->cart->validate_add_cart_item( true, $product_id, $quantity ) ) {
				$add = false;
			}

			self::$nyp_suffix = '';
		}

		return $add;
	}

	/**
	 * Runs before adding a combined item to the cart.
	 *
	 * @param  int    $product_id
	 * @param  int    $quantity
	 * @param  int    $variation_id
	 * @param  array  $variations
	 * @param  array  $combined_item_cart_data
	 * @return void
	 */
	public static function after_combined_add_to_cart( $product_id, $quantity, $variation_id, $variations, $combined_item_cart_data ) {

		// Reset nyp suffix.
		self::$nyp_suffix = '';

		add_filter( 'woocommerce_add_cart_item_data', array( WC_Name_Your_Price()->cart, 'add_cart_item_data' ), 5, 3 );
	}

	/**
	 * Runs after adding a combined item to the cart.
	 *
	 * @param  int    $product_id
	 * @param  int    $quantity
	 * @param  int    $variation_id
	 * @param  array  $variations
	 * @param  array  $combined_item_cart_data
	 * @return void
	 */
	public static function before_combined_add_to_cart( $product_id, $quantity, $variation_id, $variations, $combined_item_cart_data ) {

		// Set nyp suffix.
		self::$nyp_suffix = $combined_item_cart_data[ 'combined_item_id' ];

		remove_filter( 'woocommerce_add_cart_item_data', array( WC_Name_Your_Price()->cart, 'add_cart_item_data' ), 5, 3 );
	}

	/**
	 * Retrieve child cart item data from the parent cart item data array, if necessary.
	 *
	 * @param  array  $combined_item_cart_data
	 * @param  array  $cart_item_data
	 * @return array
	 */
	public static function get_combined_cart_item_data_from_parent( $combined_item_cart_data, $cart_item_data ) {

		// NYP cart item data is already stored in the composite_data array, so we can grab it from there instead of allowing NYP to re-add it.
		if ( isset( $combined_item_cart_data[ 'combined_item_id' ] ) && isset( $cart_item_data[ 'stamp' ][ $combined_item_cart_data[ 'combined_item_id' ] ][ 'nyp' ] ) ) {
			$combined_item_cart_data[ 'nyp' ] = $cart_item_data[ 'stamp' ][ $combined_item_cart_data[ 'combined_item_id' ] ][ 'nyp' ];
		}

		return $combined_item_cart_data;
	}

	/**
	 * Sets a unique prefix for unique NYP products. The prefix is set and re-set globally before validating and adding to cart.
	 *
	 * @param  string  $prefix
	 * @param  int     $product_id
	 * @return string
	 * @deprecated 6.2.2
	 */
	public static function nyp_cart_prefix( $prefix, $product_id ) {
		wc_deprecated_function( __METHOD__, '6.2.2', 'Method has been renamed nyp_cart_suffix' );
		return self::nyp_cart_suffix( $prefix, $product_id );
	}

}
WC_LafkaCombos_NYP_Compatibility::init();
