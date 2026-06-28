<?php
/**
 * P6-SEO-1/2/3/6: JSON-LD structured data emitter.
 *
 * Registers a wp_head callback at priority 11 (after the meta description
 * from W1-T15 at priority 1). Picks generators based on page context:
 *
 *  - Restaurant (LocalBusiness + FoodEstablishment): sitewide, every page.
 *  - Product: single WooCommerce product pages only.
 *  - Menu: /menu/, product-category archives, shop page.
 *  - BreadcrumbList: every page except the homepage.
 *
 * All output is wrapped in an @graph array per Google's recommended
 * multi-type pattern. JSON encoded via wp_json_encode (handles UTF-8 and
 * ensures any "&" in the operator-configured restaurant name is not
 * HTML-escaped inside JSON-LD).
 *
 * @package Lafka\Plugin\Schema
 * @since   8.8.1
 */

defined( 'ABSPATH' ) || exit;

/**
 * Suppress WooCommerce's native Product structured data on product pages.
 * Lafka's @graph block at wp_head priority 11 carries Product + Restaurant +
 * BreadcrumbList in one merge-friendly @graph, with proper escaping (HEX_TAG).
 * WC's native block can contain double-encoded entities (&amp;amp;) that
 * invalidate it — so removing it tightens the schema surface.
 *
 * Filterable for operators who need WC's native block back.
 */
add_filter( 'woocommerce_structured_data_product', 'lafka_schema_suppress_wc_native_product', 99 );
if ( ! function_exists( 'lafka_schema_suppress_wc_native_product' ) ) {
	function lafka_schema_suppress_wc_native_product( $markup ) {
		if ( apply_filters( 'lafka_schema_keep_wc_native_product', false ) ) {
			return $markup;
		}
		return array();
	}
}

require_once __DIR__ . '/lafka-schema-helpers.php';
require_once __DIR__ . '/lafka-schema-website.php';
require_once __DIR__ . '/lafka-schema-restaurant.php';
require_once __DIR__ . '/lafka-schema-menu.php';
require_once __DIR__ . '/lafka-schema-product.php';
require_once __DIR__ . '/lafka-schema-breadcrumb.php';
require_once __DIR__ . '/lafka-schema-faq.php';

if ( ! class_exists( 'Lafka_JSON_LD' ) ) {

	/**
	 * Orchestrates JSON-LD structured data emission on wp_head.
	 *
	 * @since 8.8.1
	 */
	final class Lafka_JSON_LD {

		/**
		 * Register the wp_head callback.
		 *
		 * Called once at file include time. The emit() method is a pure static
		 * callback so no instance is stored.
		 *
		 * @return void
		 */
		public static function init(): void {
			add_action( 'wp_head', array( __CLASS__, 'emit' ), 11 );
		}

		/**
		 * Build and echo the JSON-LD <script> block.
		 *
		 * Runs at wp_head priority 11. Skips admin screens, feeds, and 404 pages.
		 *
		 * @return void
		 */
		public static function emit(): void {
			if ( is_admin() || is_feed() || is_404() ) {
				return;
			}

			/*
			 * v9.19.0: defer to SEO ecosystem plugins when active. Yoast SEO,
			 * Rank Math, and SEOPress all emit Organization/LocalBusiness
			 * JSON-LD on every page. Emitting our Restaurant graph alongside
			 * theirs creates "two top-level entities" warnings in Google
			 * Search Console. The convention is: if the operator has
			 * installed a dedicated SEO plugin, defer the @graph to it.
			 *
			 * Detection lives in lafka_seo_plugin_active() (lafka-plugin.php),
			 * the single source of truth shared with the OpenGraph and meta
			 * description head emitters. A function_exists() guard keeps this
			 * module safe if it is ever loaded without the main plugin file
			 * (e.g. in isolated unit tests).
			 *
			 * Operators who want our schema regardless can override via
			 * the `lafka_schema_force_emit` filter (return true).
			 */
			$seo_plugin_active = function_exists( 'lafka_seo_plugin_active' )
				? lafka_seo_plugin_active()
				: (
					defined( 'WPSEO_VERSION' )                      // Yoast SEO.
					|| class_exists( 'RankMath' )                   // Rank Math.
					|| defined( 'SEOPRESS_VERSION' )                // SEOPress.
					|| class_exists( '\\AIOSEO\\Plugin\\AIOSEO' )   // All in One SEO.
				);
			$force = (bool) apply_filters( 'lafka_schema_force_emit', false );
			if ( $seo_plugin_active && ! $force ) {
				return;
			}

			$graph = array();

			// Restaurant schema — emitted on every public page IF the operator has
			// populated the basics via Customizer ("Lafka — Restaurant Information").
			// We refuse to emit Restaurant JSON-LD when name/street/city/postal/phone
			// are missing, otherwise an unconfigured install would advertise empty
			// strings to Google and degrade the knowledge-panel signal.
			// WebSite entity (site-wide) — canonical site name + sitelinks search.
			$graph[] = lafka_schema_website();

			// Shared predicate (defined in lafka-schema-website.php) — also gates
			// WebSite.publisher's link to #restaurant, so the publisher reference
			// can never point at a Restaurant node we didn't add to the @graph.
			$has_basics = lafka_schema_has_restaurant_basics();
			if ( $has_basics ) {
				$graph[] = lafka_schema_restaurant();
			}

			// Single product page.
			if ( function_exists( 'is_product' ) && is_product() ) {
				$graph[] = lafka_schema_product();
			}

			// Menu context: /menu/, shop, product-category archives.
			if ( lafka_schema_is_menu_context() ) {
				$graph[] = lafka_schema_menu();
			}

			// BreadcrumbList — every page except the homepage.
			if ( ! is_front_page() ) {
				$graph[] = lafka_schema_breadcrumb();
			}

			// FAQPage — only on the contact page (slug `contact` / `contact-us`
			// or any page using the editorial template-contact.php template).
			// Returns null when no FAQ items resolve, in which case array_filter
			// below drops it from the @graph.
			$graph[] = lafka_schema_faq();

			/**
			 * Filter the full @graph array before emission.
			 *
			 * Use this to add, remove, or reorder schema blocks sitewide.
			 *
			 * @since 8.8.1
			 * @param array<int, array<string, mixed>> $graph Array of schema node arrays.
			 */
			$graph = apply_filters( 'lafka_json_ld_graph', array_values( array_filter( $graph ) ) );

			if ( empty( $graph ) ) {
				return;
			}

			$payload = array(
				'@context' => 'https://schema.org',
				'@graph'   => $graph,
			);

			// JSON_UNESCAPED_SLASHES: URLs render as-is, not with \/ escaping.
			// JSON_HEX_TAG / HEX_AMP / HEX_APOS / HEX_QUOT: stored-XSS defense
			// inside <script> context. Without HEX_TAG a `</script>` substring in
			// any value (e.g. a product name) would close the tag and let the
			// remainder render as HTML. HEX_TAG escapes `<` and `>` as < /
			// > so the closing tag literal cannot reach the parser.
			// Not pretty-printed in production — saves ~20 % bytes.
			// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo "\n<script type=\"application/ld+json\">"
				. wp_json_encode(
					$payload,
					JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT
				)
				. "</script>\n";
		}
	}

	Lafka_JSON_LD::init();
}
