<?php
/**
 * Suppress WooCommerce's default BreadcrumbList JSON-LD on product pages.
 *
 * Why: Lafka emits its own BreadcrumbList inside the consolidated `@graph`
 * (see lafka-plugin/incl/schema/lafka-schema-breadcrumb.php), shaped to the
 * customer-facing nav (Home → Menu → Product) rather than WooCommerce's deep
 * shop/category/subcategory trail. Both BreadcrumbLists in the page confuse
 * Google about which one is canonical and double the maintenance surface for
 * crumb naming. Drop WC's so only Lafka's ships.
 *
 * Filter: `woocommerce_structured_data_breadcrumblist`
 *   - Fires inside WC_Structured_Data::generate_breadcrumblist_data().
 *   - Returning an empty array prevents WC from adding the entity to its
 *     queued structured data, so no second `<script type="application/ld+json">`
 *     block is emitted by WC's footer hook.
 *
 * Scope: applies on product singular pages (the only place WC emits this
 * particular structured-data entity). Other WC entities (Product, Offer,
 * AggregateOffer) are unaffected — those are NOT duplicated by Lafka and
 * remain valuable for rich-result eligibility.
 *
 * @package Lafka\Plugin\SEO
 * @since   9.22.0
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'woocommerce_structured_data_breadcrumblist', 'lafka_suppress_wc_breadcrumb_jsonld', 99 );

if ( ! function_exists( 'lafka_suppress_wc_breadcrumb_jsonld' ) ) {
	/**
	 * Drop WooCommerce's BreadcrumbList — unless Lafka yields structured data
	 * to an SEO plugin, in which case Lafka emits no breadcrumb and WC's stays.
	 *
	 * @param mixed $markup WC's breadcrumb markup.
	 * @return mixed
	 */
	function lafka_suppress_wc_breadcrumb_jsonld( $markup ) {
		if ( function_exists( 'lafka_schema_yields_to_seo_plugin' ) && lafka_schema_yields_to_seo_plugin() ) {
			return $markup;
		}
		return array();
	}
}
