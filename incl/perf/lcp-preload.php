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

add_filter( 'lafka_lcp_image_url', function ( $url ) {
	if ( ! is_front_page() ) {
		return $url;
	}
	$hero = get_theme_mod( 'lafka_homepage_hero_image', '' );
	if ( '' === $hero || null === $hero ) {
		return $url;
	}
	if ( is_numeric( $hero ) ) {
		$resolved = wp_get_attachment_image_url( (int) $hero, 'full' );
		return $resolved ? $resolved : $url;
	}
	return esc_url_raw( (string) $hero );
} );

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
add_filter( 'wp_get_attachment_image_attributes', function ( $attr, $attachment ) {
	if ( ! is_front_page() ) {
		return $attr;
	}
	$hero_attachment_id = (int) get_option( 'lafka_homepage_hero_attachment_id', 0 );
	if ( $hero_attachment_id && $hero_attachment_id === (int) ( is_object( $attachment ) ? $attachment->ID : 0 ) ) {
		$attr['fetchpriority'] = 'high';
		$attr['loading']       = 'eager';
	}
	return $attr;
}, 10, 2 );
