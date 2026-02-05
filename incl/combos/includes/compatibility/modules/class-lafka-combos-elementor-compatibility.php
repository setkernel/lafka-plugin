<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Elementor compatibility.
 *
 * @version  6.5.0
 */
class WC_LafkaCombos_Elementor_Compatibility {

	public static function init() {
		add_filter( 'woocommerce_combo_form_classes', array( __CLASS__, 'additional_form_classes' ), 10, 2 );
		add_action( 'elementor/widget/render_content', array( __CLASS__, 'render_add_to_cart_widget' ) , 10, 2);
	}

	/**
	 * If Elementor is enabled, we add an additional class `grouped_form`
	 * This class does not have additional default WC styling, and
	 * Elementor is using it to exclude it from some styling it does
	 *
	 * @param array             $form_classes
	 * @param WC_Product_Combo $product
	 *
	 * @return array
	 */
	public static function additional_form_classes( $form_classes, $product ) {

		$form_classes[] = 'grouped_form';

		return $form_classes;
	}

	/**
	 * Flex layout issues. Elementor has no hooks we can use to add a class on a higher level
	 * so, we'll add the class by search/replace in the generated markup
	 *
	 * @since  6.5.2
	 *
	 * @param string                 $widget_content The content of the widget.
	 * @param \Elementor\Widget_Base $widget The widget.
	 *
	 * @return string
	 */
	public static function render_add_to_cart_widget( $widget_content, $widget ) {

		if ( 'woocommerce-product-add-to-cart' !== $widget->get_name() ) {
			return $widget_content;
		}

		global $product;

		if ( $product && ! is_a( $product, 'WC_Product' ) ) {
			return $widget_content;
		}

		$combo_sell_ids = WC_LafkaCombos_BS_Product::get_combo_sell_ids( $product );

		if ( empty( $combo_sell_ids ) ) {
			return $widget_content;
		}

		// Space is important at the end of the $needle / $replace strings.
		$needle         = 'class="elementor-add-to-cart ';
		$replace        = 'class="elementor-add-to-cart elementor-add-to-cart-wc-pb ';
		$found_position = strpos( $widget_content, $needle );
		if ( false !== $found_position ) {
			$widget_content = substr_replace( $widget_content, $replace, $found_position, strlen( $needle ) );
		}

		return $widget_content;
	}
}

WC_LafkaCombos_Elementor_Compatibility::init();
