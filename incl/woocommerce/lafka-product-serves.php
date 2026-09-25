<?php
/**
 * "Serves N": an optional product field for per-person deal value (GX4).
 *
 * Deals and platters can say how many people they feed. When they do, a theme
 * can print an honest "For 2: about $11.50 each" line. When they don't, it
 * prints nothing: the value is never inferred from the product name.
 *
 * Storage: product meta `_lafka_serves` (whole people, 1-50). 0 or missing
 * means "not set".
 *
 * Public API:
 *   · lafka_get_product_serves( WC_Product|int $product ): int — 0 when unset.
 *   · filter `lafka_product_serves` ( int $serves, WC_Product $product ) — the
 *     saved value is applied at priority 10 only when an earlier callback
 *     returned 0; a variation falls back to its parent's value.
 *   · REST: `lafka_serves` (integer, 0-50) on `product` (wc/v3/products and
 *     wp/v2/product); writes need `edit_post` on the product.
 *   · Store API: `extensions.lafka.serves` on the product endpoint
 *     (incl/store-api/lafka-store-api-product.php).
 *
 * Operator surface: Products → Edit → Product data → General → "Serves
 * (people)".
 *
 * @package Lafka\Plugin\WooCommerce
 * @since   10.2.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'LAFKA_PRODUCT_SERVES_META' ) ) {
	define( 'LAFKA_PRODUCT_SERVES_META', '_lafka_serves' );
}

if ( ! defined( 'LAFKA_PRODUCT_SERVES_MAX' ) ) {
	define( 'LAFKA_PRODUCT_SERVES_MAX', 50 );
}

if ( ! function_exists( 'lafka_sanitize_product_serves' ) ) {
	/**
	 * A "serves" value as whole people, 0 (not set) to LAFKA_PRODUCT_SERVES_MAX.
	 *
	 * @param mixed $value Raw value.
	 * @return int
	 */
	function lafka_sanitize_product_serves( $value ): int {
		if ( ! is_scalar( $value ) || ! is_numeric( trim( (string) $value ) ) ) {
			return 0;
		}

		return max( 0, min( LAFKA_PRODUCT_SERVES_MAX, (int) floor( (float) $value ) ) );
	}
}

if ( ! function_exists( 'lafka_get_product_serves' ) ) {
	/**
	 * How many people a product feeds (0 = not set).
	 *
	 * @param mixed $product WC_Product or product id.
	 * @return int
	 */
	function lafka_get_product_serves( $product ): int {
		if ( is_numeric( $product ) && function_exists( 'wc_get_product' ) ) {
			$product = wc_get_product( (int) $product );
		}
		if ( ! is_object( $product ) ) {
			return 0;
		}

		/**
		 * Filter how many people a product feeds (0 = not set).
		 *
		 * @since 10.2.0
		 * @param int        $serves  People (0 = not set).
		 * @param WC_Product $product Product.
		 */
		return lafka_sanitize_product_serves( apply_filters( 'lafka_product_serves', 0, $product ) );
	}
}

if ( ! function_exists( 'lafka_product_serves_from_meta' ) ) {
	/**
	 * `lafka_product_serves` @10: the saved value, unless an earlier callback
	 * already answered. A variation without its own value reads its parent.
	 *
	 * @param mixed $serves  Value so far.
	 * @param mixed $product WC_Product.
	 * @return int
	 */
	function lafka_product_serves_from_meta( $serves, $product ): int {
		$serves = lafka_sanitize_product_serves( $serves );
		if ( $serves > 0 || ! is_object( $product ) || ! method_exists( $product, 'get_meta' ) ) {
			return $serves;
		}
		$serves = lafka_sanitize_product_serves( $product->get_meta( LAFKA_PRODUCT_SERVES_META, true ) );
		if ( 0 === $serves && method_exists( $product, 'get_parent_id' ) && (int) $product->get_parent_id() > 0 && function_exists( 'wc_get_product' ) ) {
			$parent = wc_get_product( (int) $product->get_parent_id() );
			if ( is_object( $parent ) && method_exists( $parent, 'get_meta' ) ) {
				$serves = lafka_sanitize_product_serves( $parent->get_meta( LAFKA_PRODUCT_SERVES_META, true ) );
			}
		}

		return $serves;
	}
}

if ( ! function_exists( 'lafka_product_serves_field' ) ) {
	/**
	 * The "Serves (people)" field on Product data → General.
	 *
	 * @return void
	 */
	function lafka_product_serves_field() {
		if ( ! function_exists( 'woocommerce_wp_text_input' ) ) {
			return;
		}
		global $product_object;
		$value = is_object( $product_object ) && method_exists( $product_object, 'get_meta' )
			? lafka_sanitize_product_serves( $product_object->get_meta( LAFKA_PRODUCT_SERVES_META, true ) )
			: 0;

		echo '<div class="options_group">';
		woocommerce_wp_text_input(
			array(
				'id'                => LAFKA_PRODUCT_SERVES_META,
				'label'             => __( 'Serves (people)', 'lafka-plugin' ),
				'type'              => 'number',
				'value'             => $value > 0 ? (string) $value : '',
				'placeholder'       => '0',
				'desc_tip'          => true,
				'description'       => __( 'How many people this feeds, for deals and platters. When set, the menu can show an "about $X each" line. Leave empty to show nothing.', 'lafka-plugin' ),
				'custom_attributes' => array(
					'min'  => '0',
					'max'  => (string) LAFKA_PRODUCT_SERVES_MAX,
					'step' => '1',
				),
			)
		);
		echo '</div>';
	}
}

if ( ! function_exists( 'lafka_product_serves_save' ) ) {
	/**
	 * Save the field with the product (woocommerce_admin_process_product_object).
	 *
	 * @param mixed $product WC_Product being saved.
	 * @return void
	 */
	function lafka_product_serves_save( $product ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- WooCommerce verified woocommerce_meta_nonce and edit_post before firing woocommerce_admin_process_product_object.
		if ( ! is_object( $product ) || ! isset( $_POST[ LAFKA_PRODUCT_SERVES_META ] ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- see above; sanitised to an int by lafka_sanitize_product_serves().
		$serves = lafka_sanitize_product_serves( wp_unslash( $_POST[ LAFKA_PRODUCT_SERVES_META ] ) );
		lafka_product_serves_write( $product, $serves );
	}
}

if ( ! function_exists( 'lafka_product_serves_write' ) ) {
	/**
	 * Store (or clear, at 0) the value on a product object. The caller saves.
	 *
	 * @param object $product WC_Product.
	 * @param int    $serves  Sanitised value.
	 * @return void
	 */
	function lafka_product_serves_write( $product, int $serves ) {
		if ( $serves > 0 ) {
			$product->update_meta_data( LAFKA_PRODUCT_SERVES_META, $serves );
		} else {
			$product->delete_meta_data( LAFKA_PRODUCT_SERVES_META );
		}
	}
}

if ( ! function_exists( 'lafka_product_serves_rest_get' ) ) {
	/**
	 * REST read of `lafka_serves`.
	 *
	 * @param array $data Prepared response data (has `id`).
	 * @return int
	 */
	function lafka_product_serves_rest_get( $data ): int {
		$id = is_array( $data ) ? (int) ( $data['id'] ?? 0 ) : 0;
		if ( $id <= 0 || ! function_exists( 'wc_get_product' ) ) {
			return 0;
		}
		$product = wc_get_product( $id );

		return is_object( $product ) ? lafka_product_serves_from_meta( 0, $product ) : 0;
	}
}

if ( ! function_exists( 'lafka_product_serves_rest_update' ) ) {
	/**
	 * REST write of `lafka_serves`. WooCommerce's controller passes the
	 * WC_Product (and saves it afterwards); the core posts controller passes a
	 * WP_Post, which is loaded and saved here.
	 *
	 * @param mixed $value  Requested value.
	 * @param mixed $object WC_Product or WP_Post.
	 * @return true|WP_Error
	 */
	function lafka_product_serves_rest_update( $value, $object ) {
		$is_wc_object = is_object( $object ) && method_exists( $object, 'update_meta_data' );
		$id           = $is_wc_object && method_exists( $object, 'get_id' ) ? (int) $object->get_id() : (int) ( is_object( $object ) && isset( $object->ID ) ? $object->ID : 0 );
		if ( $id <= 0 || ! current_user_can( 'edit_post', $id ) ) {
			return new WP_Error( 'lafka_serves_forbidden', __( 'Sorry, you are not allowed to edit this product.', 'lafka-plugin' ), array( 'status' => 403 ) );
		}
		$product = $is_wc_object ? $object : ( function_exists( 'wc_get_product' ) ? wc_get_product( $id ) : null );
		if ( ! is_object( $product ) ) {
			return new WP_Error( 'lafka_serves_invalid_product', __( 'Invalid product.', 'lafka-plugin' ), array( 'status' => 404 ) );
		}
		lafka_product_serves_write( $product, lafka_sanitize_product_serves( $value ) );
		if ( ! $is_wc_object && method_exists( $product, 'save' ) ) {
			$product->save();
		}

		return true;
	}
}

if ( ! function_exists( 'lafka_product_serves_register_rest' ) ) {
	/**
	 * Register the `lafka_serves` REST field on products.
	 *
	 * @return void
	 */
	function lafka_product_serves_register_rest() {
		if ( ! function_exists( 'register_rest_field' ) ) {
			return;
		}
		register_rest_field(
			'product',
			'lafka_serves',
			array(
				'get_callback'    => 'lafka_product_serves_rest_get',
				'update_callback' => 'lafka_product_serves_rest_update',
				'schema'          => array(
					'description' => __( 'How many people the product feeds (0 = not set).', 'lafka-plugin' ),
					'type'        => 'integer',
					'minimum'     => 0,
					'maximum'     => LAFKA_PRODUCT_SERVES_MAX,
					'context'     => array( 'view', 'edit' ),
				),
			)
		);
	}
}

if ( ! function_exists( 'lafka_product_serves_init' ) ) {
	/**
	 * Wire the field, the save, the resolver and the REST field.
	 *
	 * @return void
	 */
	function lafka_product_serves_init() {
		add_filter( 'lafka_product_serves', 'lafka_product_serves_from_meta', 10, 2 );
		add_action( 'woocommerce_product_options_general_product_data', 'lafka_product_serves_field' );
		add_action( 'woocommerce_admin_process_product_object', 'lafka_product_serves_save' );
		add_action( 'rest_api_init', 'lafka_product_serves_register_rest' );
	}
}
