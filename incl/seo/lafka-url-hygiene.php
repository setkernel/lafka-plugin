<?php
/**
 * GX URL hygiene — redirects that keep crawlers and customers on the real menu.
 *
 *  - T-07: once WooCommerce products are the menu (lafka_seo_legacy_post_types()
 *    lists `lafka-foodmenu`), the theme's original food-menu CPT — singles,
 *    its archive and its category archives — 301s to the menu page (the
 *    published page with the `menu` slug, else the WooCommerce shop page,
 *    else home). Demo leftovers like /restaurant-menu/angus-burger/ otherwise
 *    stay public and compete with the real menu. Previews are never redirected.
 *    Filters: `lafka_legacy_foodmenu_redirect` (bool, false = keep the CPT
 *    pages), `lafka_legacy_foodmenu_redirect_target` (string URL).
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

if ( ! function_exists( 'lafka_legacy_foodmenu_redirect_target' ) ) {
	/**
	 * Where legacy food-menu URLs point: the menu page, else the shop, else home.
	 *
	 * @return string Absolute URL.
	 */
	function lafka_legacy_foodmenu_redirect_target(): string {
		$target = '';
		$page   = function_exists( 'get_page_by_path' ) ? get_page_by_path( 'menu' ) : null;
		if ( is_object( $page ) && 'publish' === (string) ( $page->post_status ?? '' ) ) {
			$target = (string) get_permalink( $page );
		}
		if ( '' === $target && function_exists( 'wc_get_page_id' ) ) {
			$shop_id = (int) wc_get_page_id( 'shop' );
			if ( $shop_id > 0 ) {
				$target = (string) get_permalink( $shop_id );
			}
		}
		if ( '' === $target ) {
			$target = (string) home_url( '/' );
		}

		/**
		 * Filter where legacy `lafka-foodmenu` URLs redirect to.
		 *
		 * @since 10.3.0
		 * @param string $target Absolute URL (menu page / shop / home).
		 */
		return (string) apply_filters( 'lafka_legacy_foodmenu_redirect_target', $target );
	}
}

if ( ! function_exists( 'lafka_legacy_foodmenu_redirect_url' ) ) {
	/**
	 * The 301 target for the current request, or '' when it is not a legacy
	 * food-menu URL (or redirects are off).
	 *
	 * @return string
	 */
	function lafka_legacy_foodmenu_redirect_url(): string {
		if ( is_admin() || is_preview() ) {
			return '';
		}
		$legacy = function_exists( 'lafka_seo_legacy_post_types' ) ? lafka_seo_legacy_post_types() : array();
		if ( ! in_array( 'lafka-foodmenu', $legacy, true ) ) {
			return '';
		}
		if ( ! is_singular( 'lafka-foodmenu' ) && ! is_post_type_archive( 'lafka-foodmenu' ) && ! is_tax( 'lafka_foodmenu_category' ) ) {
			return '';
		}

		/**
		 * Filter whether legacy food-menu URLs redirect to the menu.
		 *
		 * @since 10.3.0
		 * @param bool $redirect Default true.
		 */
		if ( ! (bool) apply_filters( 'lafka_legacy_foodmenu_redirect', true ) ) {
			return '';
		}
		return lafka_legacy_foodmenu_redirect_target();
	}
}

if ( ! function_exists( 'lafka_legacy_foodmenu_redirect' ) ) {
	/**
	 * `template_redirect`: 301 a legacy food-menu URL to the menu.
	 *
	 * @return void
	 */
	function lafka_legacy_foodmenu_redirect() {
		$url = lafka_legacy_foodmenu_redirect_url();
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
	function lafka_disable_404_guess_redirect( $do ) {
		/**
		 * Filter whether Lafka disables WordPress' 404 permalink guessing.
		 *
		 * @since 10.3.0
		 * @param bool $disable Default true.
		 */
		if ( (bool) apply_filters( 'lafka_disable_404_guess_redirect', true ) ) {
			return false;
		}
		return (bool) $do;
	}
}

add_action( 'template_redirect', 'lafka_legacy_foodmenu_redirect', 1 );
add_filter( 'do_redirect_guess_404_permalink', 'lafka_disable_404_guess_redirect' );
