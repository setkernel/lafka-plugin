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
	 * Whether Lafka stays out of structured data because a dedicated SEO
	 * plugin (Yoast, Rank Math, SEOPress, AIOSEO) owns it. Operators can force
	 * Lafka's graph regardless with the `lafka_schema_force_emit` filter.
	 *
	 * @return bool
	 */
	function lafka_schema_yields_to_seo_plugin() {
		// Detection lives in lafka_seo_plugin_active() (incl/seo/lafka-seo-plugin-detect.php), shared
		// with the OpenGraph and meta-description emitters; the inline fallback
		// keeps this module usable when loaded without the main plugin file.
		$seo_plugin_active = function_exists( 'lafka_seo_plugin_active' )
			? lafka_seo_plugin_active()
			: (
				defined( 'WPSEO_VERSION' )                      // Yoast SEO.
				|| class_exists( 'RankMath' )                   // Rank Math.
				|| defined( 'SEOPRESS_VERSION' )                // SEOPress.
				|| class_exists( '\\AIOSEO\\Plugin\\AIOSEO' )   // All in One SEO.
			);

		return $seo_plugin_active && ! (bool) apply_filters( 'lafka_schema_force_emit', false );
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
