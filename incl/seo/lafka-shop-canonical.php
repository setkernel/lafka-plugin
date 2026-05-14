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
	 * Returns the base archive URL with sort/filter query params stripped.
	 * For paginated archives the /page/N/ path component is preserved so each
	 * paginated page self-canonicals correctly.
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

		// get_pagenum_link() returns HTML-entity-encoded ampersands (&#038;) which
		// break remove_query_arg() — decode first so wp_parse_url() sees real '&'.
		$paged = get_query_var( 'paged', 0 );

		if ( $paged >= 2 ) {
			// Paginated page: self-canonical (preserve /page/N/ path, drop filter params).
			$raw_base = html_entity_decode( get_pagenum_link( $paged ), ENT_QUOTES, 'UTF-8' );
		} else {
			// Page 1 / unfiltered: canonical = clean base archive URL.
			$raw_base = html_entity_decode( get_pagenum_link( 1 ), ENT_QUOTES, 'UTF-8' );
		}

		// Build the canonical from the raw path only (scheme + host + port + path),
		// then re-add any query args that are NOT sort/filter params.
		// Independent audit 2026-04-29 caught the missing port: when site_url
		// includes a non-default port (e.g. http://localhost:8891 in dev,
		// https://example.com:8443 in some prod), the rebuild dropped it,
		// emitting a canonical that didn't match the host the page was served
		// from — Google treats that as a deduplication signal and depowers.
		$parsed = wp_parse_url( $raw_base );
		$path   = ( isset( $parsed['scheme'] ) ? $parsed['scheme'] . '://' : '' )
			. ( isset( $parsed['host'] ) ? $parsed['host'] : '' )
			. ( isset( $parsed['port'] ) ? ':' . $parsed['port'] : '' )
			. ( isset( $parsed['path'] ) ? $parsed['path'] : '' );

		// Collect any surviving (non-filter) query args from the raw URL.
		$surviving_args = array();
		if ( ! empty( $parsed['query'] ) ) {
			parse_str( $parsed['query'], $args );

			// Params to strip entirely.
			$deny_exact = array(
				'orderby',
				'min_price',
				'max_price',
				'rating_filter',
				'product_cat',
				'product_tag',
				'filter_attr',
				'paged',
			);
			foreach ( $args as $k => $v ) {
				if ( in_array( $k, $deny_exact, true ) ) {
					continue;
				}
				// Strip WC attribute-filter and price-range wildcard keys.
				if ( 0 === strpos( $k, 'filter_' )
					|| 0 === strpos( $k, 'min_price' )
					|| 0 === strpos( $k, 'max_price' )
				) {
					continue;
				}
				$surviving_args[ $k ] = $v;
			}
		}

		$url = $path;
		if ( ! empty( $surviving_args ) ) {
			$url .= '?' . http_build_query( $surviving_args );
		}

		return $url;
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
