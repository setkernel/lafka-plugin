<?php
/**
 * Phase 2 (v9.26.0) — robots.txt audit + WooCommerce-aware Disallow directives.
 *
 * WordPress serves a virtual /robots.txt at runtime via `do_robots()`. The
 * default output contains only two lines:
 *
 *     User-agent: *
 *     Disallow: /wp-admin/
 *     Allow: /wp-admin/admin-ajax.php
 *
 * That leaves cart / checkout / my-account / add-to-cart links and WC's AJAX
 * query-arg endpoints fully crawlable, which (a) wastes Google's crawl budget
 * on transactional pages it should never rank, and (b) pollutes the index
 * with cart-state URLs that change per session.
 *
 * Strategy: hook `robots_txt` to append explicit Disallow lines for the
 * canonical funnel paths and WC's query-arg endpoints. We preserve the
 * caller's existing content (don't replace) so WP-core's `Allow: /wp-admin/
 * admin-ajax.php` line stays intact.
 *
 * @package Lafka\Plugin\SEO
 * @since   9.26.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'lafka_robots_disallow_paths' ) ) {
	/**
	 * Canonical list of paths and query-string prefixes to disallow.
	 *
	 * Trailing-slash variants are NOT added — Google treats `/cart/` and
	 * `/cart` as equivalent for path-prefix disallow rules per robots.txt
	 * spec. We keep the trailing slash because that's how the funnel pages
	 * resolve in WP's pretty-permalink mode.
	 *
	 * @return array<int, string>
	 */
	function lafka_robots_disallow_paths(): array {
		$paths = array(
			// WC funnel pages.
			'/cart/',
			'/checkout/',
			'/my-account/',
			// Add-to-cart and WC AJAX query strings.
			'/?add-to-cart=',
			'/?wc-ajax=',
			// Shop-archive filter + sort variants (huge crawl-budget drain on
			// WC stores; canonical already strips these via lafka-shop-canonical.php
			// but crawlers waste budget hitting them in the first place).
			'/?orderby=',
			'/?min_price=',
			'/?max_price=',
		);
		/**
		 * Filter the list of paths and query strings disallowed in robots.txt.
		 *
		 * @since 9.26.0
		 * @param array<int, string> $paths Default disallow list.
		 */
		if ( function_exists( 'apply_filters' ) ) {
			$paths = (array) apply_filters( 'lafka_robots_disallow_paths', $paths );
		}
		return array_values( array_unique( array_filter( array_map( 'strval', $paths ) ) ) );
	}
}

if ( ! function_exists( 'lafka_robots_filter' ) ) {
	/**
	 * Append Lafka Disallow lines to the rendered robots.txt body.
	 *
	 * Hook signature: ($output, $public). When $public is 0/false the site
	 * is in "Discourage search engines" mode — WP-core emits `Disallow: /`
	 * for the entire site, so we leave it alone (adding more lines would be
	 * misleading and might confuse a future un-discourage operation).
	 *
	 * Idempotency: every disallow line we'd emit is checked against the
	 * incoming output via `false === strpos(...)`. This prevents duplicate
	 * lines if another plugin or filter ran first and already added the same
	 * directive.
	 *
	 * @param string   $output The default robots.txt content.
	 * @param int|bool $public Whether search engines are allowed (1) or not (0).
	 * @return string
	 */
	function lafka_robots_filter( $output, $public = 1 ): string {
		$output = (string) $output;
		// Don't touch the body when the site is set to "Discourage search engines" —
		// WP core's blanket `Disallow: /` already handles that case and stacking
		// more rules underneath it is noisy + confuses operators reviewing the file.
		if ( empty( $public ) ) {
			return $output;
		}

		// Trim trailing whitespace once so we can append cleanly with a single newline.
		$output = rtrim( $output ) . "\n";

		$lines = array();
		foreach ( lafka_robots_disallow_paths() as $path ) {
			$line = 'Disallow: ' . $path;
			// De-dupe against anything that may already be present (e.g. another
			// plugin or a manually edited theme filter).
			if ( false !== strpos( $output, $line ) ) {
				continue;
			}
			// De-dupe within our own list too, just in case the filter introduced
			// a repeat.
			if ( in_array( $line, $lines, true ) ) {
				continue;
			}
			$lines[] = $line;
		}

		if ( empty( $lines ) ) {
			return $output;
		}

		// Append under the existing User-agent: * directive that WP core emits.
		// We don't emit a new User-agent: * header because the existing one
		// already scopes our additions correctly.
		$output .= implode( "\n", $lines ) . "\n";

		return $output;
	}
}

if ( function_exists( 'add_filter' ) ) {
	add_filter( 'robots_txt', 'lafka_robots_filter', 10, 2 );
}
