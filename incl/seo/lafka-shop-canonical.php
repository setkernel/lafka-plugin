<?php
/**
 * P6-SEO-12 W2-T6: canonical URL for shop archives.
 *
 * WooCommerce shop/archive pages can produce duplicate-content variants via
 * query params (?orderby=price, ?min_price=10, etc.) and pagination
 * (/page/2/). WordPress core's rel_canonical() only fires on singular posts,
 * so archives emit no canonical tag at all by default.
 *
 * Strategy:
 *  - Filtered/sorted variants (?orderby, ?min_price, ?max_price, filter_*):
 *    emit canonical pointing at the base archive URL (query stripped).
 *  - Paginated archives (/page/2/): self-canonical — each paginated page is
 *    its own URL per Google's modern guidance; we preserve the page path.
 *  - Hook into wp_head (priority 1) so we run before themes can add their own.
 *  - Also register wpseo_canonical filter for forward-compat if Yoast lands.
 *    (The WP-core get_canonical_url filter is intentionally included per spec
 *     even though rel_canonical() skips non-singular; it will be active if a
 *     future SEO plugin calls wp_get_canonical_url() on archives.)
 *
 * @package Lafka\Plugin\SEO
 * @since   8.8.2
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'lafka_seo_shop_canonical_url' ) ) {

	/**
	 * Compute the canonical URL for the current shop/product-taxonomy archive.
	 *
	 * T-09 (GX): the CLEAN archive URL — the queried term's own
	 * get_term_link() on a product-taxonomy archive, the shop page permalink
	 * on the shop — and `/page/N/` on paginated archives (each paginated page
	 * self-canonicalises, per Google's guidance). Every request query arg is
	 * dropped: sort / filter params AND tracking params (utm_*, fbclid, gclid …),
	 * which previously leaked into the canonical and split the signal across
	 * shared links.
	 *
	 * Filter: `lafka_seo_shop_canonical_url` (string $url, int $paged).
	 *
	 * @return string|false Canonical URL string, or false when not on a shop/taxonomy archive.
	 */
	function lafka_seo_shop_canonical_url() {
		if ( ! function_exists( 'is_shop' ) ) {
			return false;
		}

		if ( ! is_shop() && ! is_product_taxonomy() ) {
			return false;
		}

		$paged = (int) get_query_var( 'paged', 0 );
		$base  = '';

		if ( is_product_taxonomy() ) {
			$term = get_queried_object();
			if ( is_object( $term ) && isset( $term->term_id ) ) {
				$link = get_term_link( $term );
				if ( is_string( $link ) && ! is_wp_error( $link ) ) {
					$base = $link;
				}
			}
		} elseif ( function_exists( 'wc_get_page_permalink' ) ) {
			$base = (string) wc_get_page_permalink( 'shop' );
		}

		if ( '' === $base ) {
			// Fallback: the requested archive URL, query string removed.
			// get_pagenum_link() returns HTML-entity-encoded ampersands.
			$raw    = html_entity_decode( (string) get_pagenum_link( 1 ), ENT_QUOTES, 'UTF-8' );
			$parsed = wp_parse_url( $raw );
			// Keep the port (a dev/staging host on :8443 must not canonicalise elsewhere).
			$base = ( isset( $parsed['scheme'] ) ? $parsed['scheme'] . '://' : '' )
				. ( $parsed['host'] ?? '' )
				. ( isset( $parsed['port'] ) ? ':' . $parsed['port'] : '' )
				. ( $parsed['path'] ?? '' );
		}

		$url = $base;
		if ( $paged >= 2 ) {
			$pretty = '' !== (string) get_option( 'permalink_structure', '' ) && false === strpos( $base, '?' );
			$url    = $pretty
				? trailingslashit( $base ) . 'page/' . $paged . '/'
				: add_query_arg( 'paged', $paged, $base );
		}

		/**
		 * Filter the canonical URL of a shop / product-taxonomy archive.
		 *
		 * @since 10.3.0
		 * @param string $url   Clean canonical URL.
		 * @param int    $paged Current page number (0 or 1 = first page).
		 */
		return (string) apply_filters( 'lafka_seo_shop_canonical_url', $url, $paged );
	}
}

if ( ! function_exists( 'lafka_seo_emit_shop_canonical' ) ) {

	/**
	 * Emit <link rel="canonical"> for shop/product-taxonomy archives via wp_head.
	 *
	 * WP core's rel_canonical() skips all non-singular pages, so shop archives
	 * get no canonical tag at all without this hook.
	 */
	function lafka_seo_emit_shop_canonical(): void {
		if ( is_admin() ) {
			return;
		}

		// An SEO plugin prints its own canonical (and receives ours through the
		// wpseo_canonical / get_canonical_url filters below) — a second
		// <link rel="canonical"> would make Google ignore both.
		if ( function_exists( 'lafka_seo_plugin_active' ) && lafka_seo_plugin_active() ) {
			return;
		}

		$url = lafka_seo_shop_canonical_url();
		if ( empty( $url ) ) {
			return;
		}

		echo '<link rel="canonical" href="' . esc_url( $url ) . '" />' . "\n";
	}

	add_action( 'wp_head', 'lafka_seo_emit_shop_canonical', 1 );
}

if ( ! function_exists( 'lafka_seo_filter_shop_canonical' ) ) {

	/**
	 * Filter canonical URL for shop archives.
	 *
	 * Hooked into:
	 *   - get_canonical_url  (WP core — called via wp_get_canonical_url(), which
	 *     is currently skipped for archives but may be invoked by future plugins).
	 *   - wpseo_canonical    (Yoast SEO — forward-compat if Yoast is ever installed).
	 *
	 * @param string       $url  Incoming canonical URL.
	 * @param WP_Post|null $post Post object (may be null on archive context).
	 * @return string             Filtered canonical URL.
	 */
	function lafka_seo_filter_shop_canonical( string $url, $post = null ): string {
		if ( is_admin() ) {
			return $url;
		}

		$shop_url = lafka_seo_shop_canonical_url();
		if ( ! empty( $shop_url ) ) {
			return $shop_url;
		}

		return $url;
	}

	add_filter( 'get_canonical_url', 'lafka_seo_filter_shop_canonical', 99, 2 );
	add_filter( 'wpseo_canonical', 'lafka_seo_filter_shop_canonical', 99, 2 );
}
