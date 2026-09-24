<?php
/**
 * lafka_seo_plugin_active(): whether Yoast / Rank Math / SEOPress / AIOSEO
 * owns head metadata, so every Lafka head emitter defers to it.
 *
 * Moved verbatim out of lafka-plugin.php; the function name is unchanged.
 *
 * @package Lafka\Plugin\SEO
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'lafka_seo_plugin_active' ) ) {
	/**
	 * Whether a dedicated SEO plugin is managing head metadata.
	 *
	 * Single source of truth for the "an SEO plugin owns head metadata"
	 * decision, shared by every Lafka head emitter: the JSON-LD @graph
	 * (incl/schema/class-lafka-json-ld.php), the OpenGraph / Twitter Card
	 * tags (lafka_insert_og_tags), and the meta description
	 * (lafka_render_meta_description).
	 *
	 * When any of these plugins is active it emits its own
	 * Organization/LocalBusiness JSON-LD, <meta name="description">, and
	 * og:* / twitter:* tags — so Lafka must defer to avoid duplicate,
	 * conflicting metadata being served to search engines and social
	 * scrapers on every public page.
	 *
	 * Detects: Yoast SEO, Rank Math, SEOPress, All in One SEO.
	 *
	 * Loaded before the schema module so the JSON-LD emitter can
	 * reuse it as its single source of truth rather than duplicating the
	 * detection inline.
	 *
	 * @since 9.23.0
	 * @return bool True when a dedicated SEO plugin is active.
	 */
	function lafka_seo_plugin_active() {
		return (
			defined( 'WPSEO_VERSION' )                      // Yoast SEO.
			|| class_exists( 'RankMath' )                   // Rank Math.
			|| defined( 'SEOPRESS_VERSION' )                // SEOPress.
			|| class_exists( '\\AIOSEO\\Plugin\\AIOSEO' )   // All in One SEO.
		);
	}
}
