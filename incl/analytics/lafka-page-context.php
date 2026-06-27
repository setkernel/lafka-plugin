<?php
/**
 * Global page-context dataLayer push.
 *
 * Emits ONE server-rendered `page_context` event into window.dataLayer on every
 * front-end page (wp_head priority 3 — after dataLayer init + consent at pri 1,
 * before the ecommerce view events). This gives GTM / GA4 / Clarity a consistent
 * set of dimensions on every hit so funnels, audiences and Clarity custom tags
 * can segment without per-page wiring:
 *
 *   page_type · fulfilment_method · store_open · customer_logged_in ·
 *   customer_is_repeat · cart_items_count · cart_value_band · top_category
 *
 * Pushed (never gtag()) — GTM is the routing layer. Only emitted when an
 * analytics destination is configured.
 *
 * @package Lafka\Plugin\Analytics
 * @since   9.31.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'lafka_analytics_is_active' ) ) {
	/**
	 * True when any analytics destination is configured (GTM, GA4, Clarity,
	 * Meta Pixel, or the Cloudflare beacon). Shared gate for all emitters.
	 *
	 * @return bool
	 */
	function lafka_analytics_is_active(): bool {
		foreach ( array(
			'lafka_analytics_gtm_id',
			'lafka_analytics_ga4_id',
			'lafka_analytics_clarity_id',
			'lafka_analytics_meta_pixel_id',
			'lafka_analytics_cf_beacon_token',
		) as $fn ) {
			if ( function_exists( $fn ) && '' !== $fn() ) {
				return true;
			}
		}
		return false;
	}
}

if ( ! function_exists( 'lafka_analytics_page_type' ) ) {
	/**
	 * Coarse page-type bucket for the current request.
	 *
	 * @return string
	 */
	function lafka_analytics_page_type(): string {
		if ( function_exists( 'is_order_received_page' ) && is_order_received_page() ) {
			return 'purchase';
		}
		if ( function_exists( 'is_checkout' ) && is_checkout() ) {
			return 'checkout';
		}
		if ( function_exists( 'is_cart' ) && is_cart() ) {
			return 'cart';
		}
		if ( function_exists( 'is_product' ) && is_product() ) {
			return 'product';
		}
		if ( function_exists( 'is_product_category' ) && is_product_category() ) {
			return 'category';
		}
		if ( function_exists( 'is_shop' ) && is_shop() ) {
			return 'shop';
		}
		if ( function_exists( 'is_account_page' ) && is_account_page() ) {
			return 'account';
		}
		if ( is_front_page() ) {
			return 'home';
		}
		if ( is_page() ) {
			return 'page';
		}
		if ( is_singular( 'post' ) ) {
			return 'post';
		}
		if ( is_search() ) {
			return 'search';
		}
		return 'other';
	}
}

if ( ! function_exists( 'lafka_analytics_cart_value_band' ) ) {
	/**
	 * Bucket a cart/order total into the store's known AOV bands.
	 *
	 * @param float $total Cart total.
	 * @return string
	 */
	function lafka_analytics_cart_value_band( float $total ): string {
		if ( $total <= 0 ) {
			return 'empty';
		}
		if ( $total < 25 ) {
			return 'under_25';
		}
		if ( $total < 40 ) {
			return '25_40';
		}
		if ( $total < 55 ) {
			return '40_55';
		}
		return '55_plus';
	}
}

if ( ! function_exists( 'lafka_analytics_emit_page_context' ) ) {
	add_action( 'wp_head', 'lafka_analytics_emit_page_context', 3 );

	/**
	 * Emit the page_context dataLayer push.
	 *
	 * @return void
	 */
	function lafka_analytics_emit_page_context(): void {
		if ( is_admin() || ! lafka_analytics_is_active() ) {
			return;
		}

		$cart_count = 0;
		$cart_total = 0.0;
		if ( function_exists( 'WC' ) && WC() && isset( WC()->cart ) && WC()->cart ) {
			$cart_count = (int) WC()->cart->get_cart_contents_count();
			$cart_total = (float) WC()->cart->get_cart_contents_total();
		}

		$logged_in   = is_user_logged_in();
		$is_repeat    = false;
		if ( $logged_in && function_exists( 'wc_get_customer_order_count' ) ) {
			$is_repeat = wc_get_customer_order_count( get_current_user_id() ) > 1;
		}

		// Fulfilment method from the order-method cookie (set by order-method.js).
		$fulfilment = '';
		if ( isset( $_COOKIE['lafka_order_method'] ) ) {
			$fulfilment = sanitize_key( wp_unslash( $_COOKIE['lafka_order_method'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only analytics dimension, no state change.
		}

		$store_open = null;
		if ( function_exists( 'lafka_pdp_is_store_open' ) ) {
			$store_open = (bool) lafka_pdp_is_store_open();
		}

		$top_category = '';
		if ( ( function_exists( 'is_product_category' ) && is_product_category() ) || is_tax( 'product_cat' ) ) {
			$term = get_queried_object();
			if ( $term && isset( $term->name ) ) {
				$top_category = (string) $term->name;
			}
		}

		$ctx = array(
			'event'               => 'page_context',
			'page_type'           => lafka_analytics_page_type(),
			'fulfilment_method'   => $fulfilment,
			'store_open'          => $store_open,
			'customer_logged_in'  => $logged_in,
			'customer_is_repeat'  => $is_repeat,
			'cart_items_count'    => $cart_count,
			'cart_value_band'     => lafka_analytics_cart_value_band( $cart_total ),
			'top_category'        => $top_category,
		);

		echo '<script>window.dataLayer = window.dataLayer || [];window.dataLayer.push(' . wp_json_encode( $ctx ) . ');</script>' . "\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_json_encode output is safe JSON inside a script context.
	}
}
