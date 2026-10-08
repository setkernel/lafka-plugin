<?php
/**
 * GX URL hygiene — redirects that keep crawlers and customers on the real menu.
 *
 *  - T-07: the theme's original "Restaurant Menu" post type is gone (the
 *    WooCommerce products are the menu), but search engines still hold its old
 *    URLs — /restaurant-menu/<entry>/ and /restaurant-menu-category/<term>/. A
 *    request under one of those path prefixes that would otherwise 404 is sent
 *    with a 301 to the menu (lafka_get_menu_url(), else home). A real page or
 *    post that happens to live under the same path is never redirected.
 *    Filter: `lafka_legacy_foodmenu_path_prefixes` (list of path prefixes,
 *    an empty list turns the redirect off).
 *
 *  - T-36: WordPress' "guess the permalink" redirect for 404s sends a mistyped
 *    or retired URL to the closest-named post — e.g. /pa_size/large/ landed on
 *    an unrelated combo product. A real 404 is better for customers and
 *    crawlers. Filter: `lafka_disable_404_guess_redirect` (bool, default true).
 *
 * @package Lafka\Plugin\SEO
 * @since   10.3.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'lafka_legacy_foodmenu_redirect_url' ) ) {
	/**
	 * The 301 target for a request path, or '' when the path is not under a
	 * retired food-menu prefix.
	 *
	 * @param string $request_path Request path, e.g. "/restaurant-menu/angus/".
	 * @return string Absolute URL, or ''.
	 */
	function lafka_legacy_foodmenu_redirect_url( string $request_path ): string {
		$home_path = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$path      = '/' . ltrim( (string) wp_parse_url( $request_path, PHP_URL_PATH ), '/' );
		if ( '' !== trim( $home_path, '/' ) && 0 === strpos( $path, rtrim( $home_path, '/' ) . '/' ) ) {
			$path = substr( $path, strlen( rtrim( $home_path, '/' ) ) );
		}
		$path = trailingslashit( strtolower( $path ) );

		/**
		 * Filter the path prefixes of the retired food-menu URLs.
		 *
		 * @since 10.4.0
		 * @param list<string> $prefixes Path prefixes relative to the site root.
		 */
		$prefixes = (array) apply_filters( 'lafka_legacy_foodmenu_path_prefixes', array( '/restaurant-menu/', '/restaurant-menu-category/' ) );
		foreach ( $prefixes as $prefix ) {
			$prefix = trailingslashit( '/' . ltrim( strtolower( (string) $prefix ), '/' ) );
			if ( '/' !== $prefix && 0 === strpos( $path, $prefix ) ) {
				$target = function_exists( 'lafka_get_menu_url' ) ? lafka_get_menu_url() : '';
				$target = (string) wp_validate_redirect( $target, '' );

				return '' !== $target ? $target : (string) home_url( '/' );
			}
		}

		return '';
	}
}

if ( ! function_exists( 'lafka_legacy_foodmenu_redirect' ) ) {
	/**
	 * `template_redirect`: 301 a retired food-menu URL that would 404 to the menu.
	 *
	 * @return void
	 */
	function lafka_legacy_foodmenu_redirect() {
		if ( ! is_404() || ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return;
		}
		$url = lafka_legacy_foodmenu_redirect_url( esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ) );
		if ( '' === $url ) {
			return;
		}
		wp_safe_redirect( $url, 301, 'Lafka' );
		exit;
	}
}

if ( ! function_exists( 'lafka_disable_404_guess_redirect' ) ) {
	/**
	 * `do_redirect_guess_404_permalink`: turn off core's closest-match guess.
	 *
	 * @param bool $do Whether core should guess.
	 * @return bool
	 */
	function lafka_disable_404_guess_redirect( $do_guess ) {
		/**
		 * Filter whether Lafka disables WordPress' 404 permalink guessing.
		 *
		 * @since 10.3.0
		 * @param bool $disable Default true.
		 */
		if ( (bool) apply_filters( 'lafka_disable_404_guess_redirect', true ) ) {
			return false;
		}
		return (bool) $do_guess;
	}
}

add_action( 'template_redirect', 'lafka_legacy_foodmenu_redirect', 1 );
add_filter( 'do_redirect_guess_404_permalink', 'lafka_disable_404_guess_redirect' );
