<?php
/**
 * LCP image preload + fetchpriority for the homepage hero.
 *
 * Migrated from lafka-child v5.10.6 in lafka-plugin v9.7.25.
 *
 * The hero is resolved once, by lafka_lcp_hero(), for BOTH hints so they can
 * never disagree (v9.30.x shipped them reading different keys):
 *
 *   1. the theme Customizer setting `lafka_home_hero_image_id` (attachment ID —
 *      the canonical key used by partials/home-hero.php and the Home panel);
 *   2. legacy keys: the `lafka_homepage_hero_image` theme_mod (attachment ID or
 *      URL) and the `lafka_homepage_hero_attachment_id` option.
 *
 * With no configured hero the preload falls back to the first <img> in the
 * front page's content (cached); fetchpriority needs an attachment, so it only
 * applies to a configured one. Nothing is emitted when nothing is set — keeps
 * the OSS plugin free of any restaurant-specific media URL.
 *
 * @package LafkaPlugin
 * @since   9.7.25
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'lafka_lcp_hero' ) ) {
	/**
	 * The operator-configured homepage hero.
	 *
	 * @return array{id:int,url:string} Attachment id (0 when the hero is a bare
	 *                                   URL) and URL ('' when none is set).
	 */
	function lafka_lcp_hero(): array {
		$candidates = array(
			get_theme_mod( 'lafka_home_hero_image_id', 0 ),
			get_theme_mod( 'lafka_homepage_hero_image', '' ),
			get_option( 'lafka_homepage_hero_attachment_id', 0 ),
		);
		foreach ( $candidates as $value ) {
			if ( is_numeric( $value ) && (int) $value > 0 ) {
				$url = wp_get_attachment_image_url( (int) $value, 'full' );
				if ( $url ) {
					return array(
						'id'  => (int) $value,
						'url' => (string) $url,
					);
				}
			} elseif ( is_string( $value ) && '' !== $value && ! is_numeric( $value ) ) {
				return array(
					'id'  => 0,
					'url' => esc_url_raw( $value ),
				);
			}
		}

		return array(
			'id'  => 0,
			'url' => '',
		);
	}
}

if ( ! function_exists( 'lafka_lcp_image_url' ) ) {
	/**
	 * `lafka_lcp_image_url` filter: the homepage hero to preload.
	 *
	 * @param string $url URL from earlier filters.
	 * @return string
	 */
	function lafka_lcp_image_url( $url ) {
		if ( ! is_front_page() ) {
			return $url;
		}

		// Tier 1: the operator-configured hero (the operator knows the LCP element).
		$hero = lafka_lcp_hero();
		if ( '' !== $hero['url'] ) {
			return $hero['url'];
		}

		// Tier 2: auto-detect — the first <img> in the front page's content (a
		// hero built in the block editor / WPBakery), cached for 12h and flushed
		// when the front page is saved. The preload lets the browser fetch it in
		// parallel with CSS instead of after it.
		$cache_key = 'lafka_lcp_auto_hero';
		$cached    = get_transient( $cache_key );
		if ( false !== $cached ) {
			return $cached ? $cached : $url;
		}
		$front_id   = (int) get_option( 'page_on_front' );
		$front_post = $front_id ? get_post( $front_id ) : null;
		if ( $front_post && ! empty( $front_post->post_content )
			&& preg_match( '/<img[^>]+src=["\']([^"\']+)["\']/i', (string) $front_post->post_content, $m ) ) {
			set_transient( $cache_key, $m[1], 12 * HOUR_IN_SECONDS );
			return $m[1];
		}
		set_transient( $cache_key, '', 6 * HOUR_IN_SECONDS );
		return $url;
	}
	add_filter( 'lafka_lcp_image_url', 'lafka_lcp_image_url' );
}

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

if ( ! function_exists( 'lafka_lcp_hero_image_attributes' ) ) {
	/**
	 * `wp_get_attachment_image_attributes`: fetchpriority="high" +
	 * loading="eager" on the configured hero attachment, front page only.
	 *
	 * @param array $attr       Image attributes.
	 * @param mixed $attachment Attachment post.
	 * @return array
	 */
	function lafka_lcp_hero_image_attributes( $attr, $attachment ) {
		if ( ! is_front_page() || ! is_object( $attachment ) ) {
			return $attr;
		}
		$hero = lafka_lcp_hero();
		if ( $hero['id'] > 0 && $hero['id'] === (int) $attachment->ID ) {
			$attr['fetchpriority'] = 'high';
			$attr['loading']       = 'eager';
		}
		return $attr;
	}
	add_filter( 'wp_get_attachment_image_attributes', 'lafka_lcp_hero_image_attributes', 10, 2 );
}
