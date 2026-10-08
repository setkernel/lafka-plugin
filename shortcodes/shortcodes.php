<?php
/**
 * Retired shortcode library — deprecation stub.
 *
 * The page-builder-era `[lafka_*]` shortcodes (counters, typed text, blog and
 * product carousels, banners, icon boxes, pricing tables, countdowns, the map,
 * the Ajax contact form, the food-menu grid) are retired: WooCommerce products,
 * the block editor and the theme templates replace them. Old pages may still
 * carry the tags in stored content, so each tag stays registered and renders
 * only the content it encloses (nothing for the self-closing form) — a stray
 * tag never prints as raw text.
 *
 * `[lafka_nap]` (incl/schema/lafka-nap-shortcode.php) and
 * `[lafka_shipping_areas]` (incl/map-shortcode/) are current and live elsewhere.
 *
 * @package Lafka\Plugin
 * @since   10.4.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'lafka_retired_shortcode_tags' ) ) {
	/**
	 * The retired shortcode tags.
	 *
	 * @return list<string>
	 */
	function lafka_retired_shortcode_tags(): array {
		return array(
			'lafka_counter',
			'lafka_typed',
			'lafkablogposts',
			'lafka_foodmenu',
			'lafka_latest_posts',
			'lafka_banner',
			'lafka_cloudzoom_gallery',
			'lafka_icon_teaser',
			'lafka_icon_box',
			'lafka_countdown',
			'lafka_map',
			'lafka_pricing_table',
			'lafka_contact_form',
			'lafka_woo_top_rated_carousel',
			'lafka_woo_recent_carousel',
			'lafka_woo_featured_carousel',
			'lafka_woo_sale_carousel',
			'lafka_woo_best_selling_carousel',
			'lafka_woo_product_category_carousel',
			'lafka_woo_recent_viewed_products',
			'lafka_woo_product_categories_carousel',
			'lafka_woo_products_slider',
		);
	}
}

if ( ! function_exists( 'lafka_retired_shortcode' ) ) {
	/**
	 * Render a retired shortcode: its enclosed content only.
	 *
	 * @param array|string $atts    Shortcode attributes (ignored).
	 * @param string|null  $content Enclosed content, null for the self-closing form.
	 * @return string
	 */
	function lafka_retired_shortcode( $atts, $content = null ) {
		unset( $atts );

		return null === $content ? '' : do_shortcode( (string) $content );
	}
}

foreach ( lafka_retired_shortcode_tags() as $lafka_retired_tag ) {
	if ( ! shortcode_exists( $lafka_retired_tag ) ) {
		add_shortcode( $lafka_retired_tag, 'lafka_retired_shortcode' );
	}
}
unset( $lafka_retired_tag );
