<?php
/**
 * LCP image preload + fetchpriority for the homepage hero.
 *
 * Migrated from lafka-child v5.10.6 in lafka-plugin v9.7.25.
 *
 * Hero image comes from the theme Customizer setting `lafka_home_hero_image_id`
 * (an attachment ID — the canonical key used by partials/home-hero.php and the
 * Home Customizer panel). The legacy `lafka_homepage_hero_image` theme_mod and
 * `lafka_homepage_hero_attachment_id` option are still honoured as fallbacks.
 * When unset, no preload is emitted — keeps the OSS plugin free of any
 * restaurant-specific media URL.
 *
 * Perf fix (v9.30.x): Hook 1 + Hook 2 previously read the legacy keys only, so
 * a hero set via the canonical key never got <link rel=preload fetchpriority>
 * → large mobile-LCP regression once an operator added a hero. (Baseline #perf.)
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
		// Tier 1: operator-configured hero. Canonical key first — the theme_mod
		// `lafka_home_hero_image_id` (attachment ID) used by home-hero.php + the
		// Home Customizer. Wins because the operator knows the LCP element.
		$hero_id = (int) get_theme_mod( 'lafka_home_hero_image_id', 0 );
		if ( $hero_id > 0 ) {
			$resolved = wp_get_attachment_image_url( $hero_id, 'full' );
			if ( $resolved ) {
				return $resolved;
			}
		}
		// Legacy fallback keys (older installs).
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
 * The hero attachment ID is the theme_mod `lafka_home_hero_image_id` (canonical),
 * falling back to the legacy `lafka_homepage_hero_attachment_id` option. When
 * neither is set (default 0), this hook no-ops — the parent theme can still load
 * the hero <img>, just without the priority hints.
 */
add_filter(
    'wp_get_attachment_image_attributes',
    function ( $attr, $attachment ) {
		if ( ! is_front_page() ) {
			return $attr;
		}
		$hero_attachment_id = (int) get_theme_mod( 'lafka_home_hero_image_id', 0 );
		if ( ! $hero_attachment_id ) {
			$hero_attachment_id = (int) get_option( 'lafka_homepage_hero_attachment_id', 0 ); // legacy fallback
		}
		if ( $hero_attachment_id && $hero_attachment_id === (int) ( is_object( $attachment ) ? $attachment->ID : 0 ) ) {
			$attr['fetchpriority'] = 'high';
			$attr['loading']       = 'eager';
		}
		return $attr;
	},
    10,
    2 
);
