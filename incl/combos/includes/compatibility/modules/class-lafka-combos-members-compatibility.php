<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Memberships Integration: Discounts inheritance.
 *
 * @version  6.2.5
 */
class WC_LafkaCombos_Members_Compatibility {

	/**
	 * Runtime cache.
	 *
	 * @var boolean
	 */
	private static $member_is_logged_in;

	/**
	 * Flag used to prevent 'wc_memberships_exclude_product_from_member_discounts' from changing the return value.
	 *
	 * @var boolean
	 */
	private static $calculating_inherited_discounts = false;

	/**
	 * Control flag used in inherit_member_discount().
	 *
	 * @var boolean
	 */
	private static $inherit_member_discount;

	/**
	 * Initialization.
	 */
	public static function init() {

		// See 'WC_Memberships_Member_Discounts'.
		if ( ! ( is_admin() && ! wp_doing_ajax() ) ) {

			if ( 'filters' === WC_LafkaCombos_Product_Prices::get_combined_cart_item_discount_method() ) {

				// Combo membership discounts are inherited by combined items and applied here.
				add_filter( 'woocommerce_combined_item_discount', array( __CLASS__, 'inherit_member_discount' ), 10, 3 );

				// Enable/disable discount filtering.
				add_action( 'wc_memberships_discounts_enable_price_adjustments', array( __CLASS__, 'enable_member_discount_inheritance' ) );
				add_action( 'wc_memberships_discounts_disable_price_adjustments', array( __CLASS__, 'disable_member_discount_inheritance' ) );
			}
		}

		// Prevent Memberships from applying member discounts to combined products -- membership discounts are inherited.
		add_filter( 'wc_memberships_exclude_product_from_member_discounts', array( __CLASS__, 'exclude_combined_product_from_member_discounts' ), 10, 2 );
	}

	/**
	 * Whether the current user has an active membership.
	 *
	 * @return bool
	 */
	private static function member_is_logged_in() {

		if ( null === self::$member_is_logged_in ) {
			self::$member_is_logged_in = wc_memberships_is_user_member( get_current_user_id() );
		}

		return self::$member_is_logged_in;
	}

	/**
	 * Inherit Memberships discounts as combined item discounts.
	 *
	 * @param  mixed            $discount
	 * @param  WC_Combined_Item  $combined_item
	 * @return mixed
	 */
	public static function inherit_member_discount( $discount, $combined_item, $context ) {

		if ( 'sync' === $context ) {
			return $discount;
		}

		if ( ! self::member_is_logged_in() ) {
			return $discount;
		}

		if ( ! self::$inherit_member_discount ) {
			return $discount;
		}

		// Flag to prevent 'exclude_combined_product_from_member_discounts' from kicking in.
		self::$calculating_inherited_discounts = true;

		$combo          = $combined_item->get_combo();
		$combined_product = $combined_item->get_product();

		// If the combo is excluded from member discounts, don't apply any discounts.
		if ( wc_memberships()->get_member_discounts_instance()->is_product_excluded_from_member_discounts( $combo ) ) {
			return $discount;
		}

		// If the product itself is excluded from member discounts, don't apply any discounts.
		if ( wc_memberships()->get_member_discounts_instance()->is_product_excluded_from_member_discounts( $combined_product ) ) {
			return $discount;
		}

		$member_id             = get_current_user_id();
		$parent_discount_rules = array();
		$child_discount_rules  = array();
		$discount_rules        = array();

		if ( wc_memberships()->get_member_discounts_instance()->user_has_member_discount( $combo ) ) {
			$parent_discount_rules = wc_memberships()->get_rules_instance()->get_user_product_purchasing_discount_rules( $member_id, $combo->get_id() );
		}

		if ( wc_memberships()->get_member_discounts_instance()->user_has_member_discount( $combined_product ) ) {
			$child_discount_rules = wc_memberships()->get_rules_instance()->get_user_product_purchasing_discount_rules( $member_id, $combined_product->get_id() );
		}

		$discount_rules_merged = array_merge( $parent_discount_rules, $child_discount_rules );

		// Make sure we don't apply the same membership discount twice.
		foreach ( $discount_rules_merged as $discount_rule ) {
			if ( empty( $discount_rules[ $discount_rule->get_id() ] ) ) {
				$discount_rules[ $discount_rule->get_id() ] = $discount_rule;
			}
		}

		/**
		 * 'woocommerce_combined_item_member_discount_rules' filter.
		 *
		 * Use this filter to modify the discount rules, for example to use combo-level or product-level discount rules only.
		 *
		 * @param  array            $discount_rules
		 * @param  array            $parent_discount_rules
		 * @param  array            $child_discount_rules
		 * @param  WC_Combined_Item  $combined_item
		 */
		$discount_rules = apply_filters( 'woocommerce_combined_item_member_discount_rules', $discount_rules, $parent_discount_rules, $child_discount_rules, $combined_item );

		if ( empty( $discount_rules ) ) {
			return $discount;
		}

		$allow_cumulative = apply_filters( 'wc_memberships_allow_cumulative_member_discounts', true, $member_id, $combo );
		$rule_discounts   = array();

		foreach ( $discount_rules as $rule ) {

			// Only '%' discounts are supported!
			if ( 'percentage' !== $rule->get_discount_type() ) {
				continue;
			}

			if ( $rule_discount = (float) $rule->get_discount_amount() ) {
				$rule_discounts[ $rule->get_id() ] = $rule_discount;
			}
		}

		$discount       = (float) $discount;
		$rules_discount = 0;

		if ( $allow_cumulative ) {

			foreach ( $rule_discounts as $rule_discount ) {
				$rules_discount = $rules_discount + $rule_discount - ( $rule_discount * $rules_discount ) / 100;
			}

		} else {

			$rules_discount = max( $rule_discounts );
		}

		$discount = $discount + $rules_discount - ( $rules_discount * $discount ) / 100;

		self::$calculating_inherited_discounts = false;

		/**
		 * 'woocommerce_combined_item_member_discount' filter.
		 *
		 * Use this filter to modify the membership discount applied on combined products.
		 *
		 * @param  float            $discount
		 * @param  array            $discount_rules
		 * @param  WC_Combined_Item  $combined_item
		 */
		return apply_filters( 'woocommerce_combined_item_member_discount', $discount, $discount_rules, $combined_item );
	}

	/**
	 * Prevent Memberships from applying member discounts to combined products -- membership discounts are inherited.
	 *
	 * @param  boolean     $exclude
	 * @param  WC_Product  $product
	 * @return boolean
	 */
	public static function exclude_combined_product_from_member_discounts( $exclude, $product ) {

		if ( WC_LafkaCombos_Product_Prices::is_combined_pricing_context( $product, 'catalog' ) && ! self::$calculating_inherited_discounts ) {
			$exclude = true;
		}

		if ( WC_LafkaCombos_Product_Prices::is_combined_pricing_context( $product, 'cart' ) ) {
			$exclude = true;
		}

		return $exclude;
	}

	/**
	 * Enables discount filtering.
	 */
	public static function enable_member_discount_inheritance() {
		self::$inherit_member_discount = true;
	}

	/**
	 * Disables discount filtering.
	 */
	public static function disable_member_discount_inheritance() {
		self::$inherit_member_discount = false;
	}
}

WC_LafkaCombos_Members_Compatibility::init();
