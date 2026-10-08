<?php
/**
 * SEO-plugin compatibility helpers for the JSON-LD emitter.
 *
 * @package Lafka\Plugin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'lafka_schema_yields_to_seo_plugin' ) ) {
	/**
	 * Whether a dedicated SEO plugin (Yoast, Rank Math, SEOPress, AIOSEO) owns
	 * the WebSite, breadcrumb and product structured data; Lafka then emits
	 * only its restaurant, menu and FAQ nodes. Operators can force Lafka's
	 * full graph with the `lafka_schema_force_emit` filter.
	 *
	 * @return bool
	 */
	function lafka_schema_yields_to_seo_plugin() {
		// One detector, shared with the OpenGraph and meta-description emitters.
		return lafka_seo_plugin_active() && ! (bool) apply_filters( 'lafka_schema_force_emit', false );
	}
}

/**
 * Suppress WooCommerce's native Product structured data on product pages —
 * but only when Lafka emits its own Product node. Lafka's @graph block at
 * wp_head priority 11 carries Product + Restaurant + BreadcrumbList in one
 * merge-friendly @graph, with proper escaping (HEX_TAG); WC's native block can
 * contain double-encoded entities (&amp;amp;). When Lafka yields to an SEO
 * plugin it emits nothing, so WC's block is left alone (otherwise the page
 * would carry no Product schema at all).
 *
 * Filterable for operators who need WC's native block back.
 */
add_filter( 'woocommerce_structured_data_product', 'lafka_schema_suppress_wc_native_product', 99 );
if ( ! function_exists( 'lafka_schema_suppress_wc_native_product' ) ) {
	function lafka_schema_suppress_wc_native_product( $markup ) {
		if ( lafka_schema_yields_to_seo_plugin() || apply_filters( 'lafka_schema_keep_wc_native_product', false ) ) {
			return $markup;
		}
		return array();
	}
}
