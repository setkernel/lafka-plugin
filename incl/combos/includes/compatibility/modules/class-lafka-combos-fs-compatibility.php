<?php
// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Flatsome integration.
 *
 * @version  6.3.6
 */
class WC_LafkaCombos_FS_Compatibility {

	public static function init() {
		// Add hooks if the active parent theme is Flatsome.
		add_action( 'after_setup_theme', array( __CLASS__, 'maybe_add_hooks' ) );
	}

	/**
	 * Add hooks if the active parent theme is Flatsome.
	 */
	public static function maybe_add_hooks() {

		if ( function_exists( 'flatsome_quickview' ) ) {
			// Initialize combos in quick view modals.
			add_action( 'wp_enqueue_scripts', array( __CLASS__, 'add_quickview_integration' ), 999 );
			// Resolves image update mixups in quickview modals.
			add_filter( 'woocommerce_combined_product_gallery_classes', array( __CLASS__, 'combined_product_gallery_classes' ) );
			// Lowers the responsive styling breakpoint to prevent issues in quickview modals.
			add_filter( 'woocommerce_combo_front_end_params', array( __CLASS__, 'adjust_responsive_breakpoint' ), 10 );
		}
	}

	/**
	 * Initializes combos in quick view modals.
	 *
	 * @return array
	 */
	public static function add_quickview_integration() {

		wp_enqueue_style( 'wc-combo-css' );
		wp_enqueue_script( 'wc-add-to-cart-combo' );
		wp_add_inline_script( 'wc-add-to-cart-combo',
		'
			jQuery( document ).on( "mfpOpen", function( e ) {

				jQuery( ".combo_form .combo_data" ).each( function() {

					var $combo_data    = jQuery( this ),
						$composite_form = $combo_data.closest( ".composite_form" );

					if ( $composite_form.length === 0 ) {
						$combo_data.wc_pb_combo_form();
					}

				} );

			} );
		' );
	}

	/**
	 * Lower the responsive styling breakpoint for Flatsome.
	 *
	 * @param  array  $params
	 * @return array
	 */
	public static function adjust_responsive_breakpoint( $params ) {
		$params[ 'responsive_breakpoint' ] = 320;
		return $params;
	}

	/**
	 * Resolve image update mixups in quickview modals.
	 *
	 * @param  WC_Combined_Item  $combined_item
	 * @return array
	 */
	public static function combined_product_gallery_classes( $combined_item ) {
		return array( 'combined_product_images' );
	}
}

WC_LafkaCombos_FS_Compatibility::init();
