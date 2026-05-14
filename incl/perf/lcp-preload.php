<?php
/**
 * LCP image preload + fetchpriority for the homepage hero.
 *
 * Migrated from lafka-child v5.10.6 in lafka-plugin v9.7.25.
 *
 * Hero image URL comes from the Customizer setting `lafka_homepage_hero_image`
 * (registered in incl/customizer/class-lafka-customizer-restaurant-info.php).
 * Accepts either a full URL or a numeric attachment ID. When unset, no
 * preload is emitted — keeps the OSS plugin free of any restaurant-specific
 * media URL.
 *
 * @package LafkaPlugin
 * @since   9.7.25
 */

defined( 'ABSPATH' ) || exit;

add_filter(
	'lafka_lcp_image_url',
	function ( $url ) {
		if ( ! is_front_page() ) {
			return $url;
		}
		// Tier 1: operator-configured hero (Customizer theme_mod). Wins
		// because the operator knows which image is the LCP element.
		$hero = get_theme_mod( 'lafka_homepage_hero_image', '' );
		if ( '' !== $hero && null !== $hero ) {
			if ( is_numeric( $hero ) ) {
				$resolved = wp_get_attachment_image_url( (int) $hero, 'full' );
				return $resolved ? $resolved : $url;
			}
			return esc_url_raw( (string) $hero );
		}
		// Tier 2: auto-detect — first <img> in front-page post_content.
		// This catches the common case where the operator built the home
		// page in WPBakery / Gutenberg with a hero image but never set
		// the Customizer theme_mod. Without Tier 2, that hero loads
		// after the CSS parses (LCP ~ 8-11s on mobile). With Tier 2 we
		// emit a <link rel="preload" fetchpriority="high"> in <head>
		// that lets the browser kick off the image fetch in parallel
		// with CSS parsing — typically shaving 1.5-3s off mobile LCP.
		//
		// Cache the result for 12h to avoid re-parsing post_content per
		// request. Invalidated on post save via the action below.
		$cache_key = 'lafka_lcp_auto_hero';
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached ? $cached : $url;
		}
		$front_id = (int) get_option( 'page_on_front' );
		if ( ! $front_id ) {
			set_transient( $cache_key, '', 6 * HOUR_IN_SECONDS );
			return $url;
		}
		$front_post = get_post( $front_id );
		if ( ! $front_post || empty( $front_post->post_content ) ) {
			set_transient( $cache_key, '', 6 * HOUR_IN_SECONDS );
			return $url;
		}
		$content = (string) $front_post->post_content;
		if ( preg_match( '/<img[^>]+src=["\']([^"\']+)["\']/i', $content, $m ) ) {
			$auto = $m[1];
			set_transient( $cache_key, $auto, 12 * HOUR_IN_SECONDS );
			return $auto;
		}
		set_transient( $cache_key, '', 6 * HOUR_IN_SECONDS );
		return $url;
	}
);

// Invalidate the auto-hero cache when the front page is saved.
if ( ! function_exists( 'lafka_lcp_flush_auto_hero_cache' ) ) {
	add_action( 'save_post', 'lafka_lcp_flush_auto_hero_cache' );
	add_action( 'customize_save_after', 'lafka_lcp_flush_auto_hero_cache' );
	function lafka_lcp_flush_auto_hero_cache( $post_id = 0 ) {
		$front_id = (int) get_option( 'page_on_front' );
		if ( ! $post_id || (int) $post_id === $front_id ) {
			delete_transient( 'lafka_lcp_auto_hero' );
		}
	}
}

/**
 * Apply fetchpriority="high" + loading="eager" to the homepage hero <img>.
 *
 * The hero attachment ID is stored in the `lafka_homepage_hero_attachment_id`
 * OPTION (not the `lafka_homepage_hero_image` theme_mod that Hook 1 reads).
 * The option is the canonical numeric ID; the theme_mod can also be a string
 * URL. Customizer code is expected to keep the two in sync when an attachment
 * (vs. raw URL) is selected. When the option is unset (default 0), this hook
 * no-ops — the parent theme can still load the hero <img>, just without the
 * priority hints.
 */
add_filter(
    'wp_get_attachment_image_attributes',
    function ( $attr, $attachment ) {
		if ( ! is_front_page() ) {
			return $attr;
		}
		$hero_attachment_id = (int) get_option( 'lafka_homepage_hero_attachment_id', 0 );
		if ( $hero_attachment_id && $hero_attachment_id === (int) ( is_object( $attachment ) ? $attachment->ID : 0 ) ) {
			$attr['fetchpriority'] = 'high';
			$attr['loading']       = 'eager';
		}
		return $attr;
	},
    10,
    2 
);
