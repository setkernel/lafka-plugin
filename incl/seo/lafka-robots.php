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

		// Insert INSIDE the `User-agent: *` group WP core emits — before the
		// blank line / `Sitemap:` line that closes it (core's sitemap filter
		// runs first, at priority 0). Appended after `Sitemap:` the rules sat
		// outside any group (GX QA M-43). No group found: append.
		$body   = explode( "\n", rtrim( $output, "\n" ) );
		$in_ua  = false;
		$insert = null;
		foreach ( $body as $i => $row ) {
			$row = trim( $row );
			if ( 0 === stripos( $row, 'user-agent:' ) ) {
				if ( $in_ua ) {
					continue; // Consecutive User-agent lines share one group.
				}
				$in_ua = '*' === trim( substr( $row, strlen( 'user-agent:' ) ) );
				continue;
			}
			if ( $in_ua && ( '' === $row || 0 === stripos( $row, 'sitemap:' ) ) ) {
				$insert = $i;
				break;
			}
		}
		if ( null === $insert ) {
			return $output . implode( "\n", $lines ) . "\n";
		}
		array_splice( $body, $insert, 0, $lines );
		return implode( "\n", $body ) . "\n";
	}
}

if ( ! function_exists( 'lafka_seo_should_noindex' ) ) {
	/**
	 * GX3: whether the current request is a thin / legacy / operator-hidden
	 * URL that must carry `noindex` (it stays crawlable — robots.txt must
	 * NOT block it, or the noindex would never be seen):
	 *
	 *   - attribute (`pa_*`) and legacy food-menu taxonomy archives;
	 *   - legacy post types (the `lafka-foodmenu` demo CPT) — singles and archive;
	 *   - author archives on a single-author site (filterable);
	 *   - any post / page the operator marked "hide from search engines".
	 *
	 * Predicates shared with the sitemap exclusions (lafka-sitemap.php).
	 *
	 * @return bool
	 */
	function lafka_seo_should_noindex(): bool {
		$noindex  = false;
		$excluded = function_exists( 'lafka_seo_excluded_taxonomies' ) ? lafka_seo_excluded_taxonomies() : array();
		$legacy   = function_exists( 'lafka_seo_legacy_post_types' ) ? lafka_seo_legacy_post_types() : array();

		if ( ! empty( $excluded ) && is_tax( $excluded ) ) {
			$noindex = true;
		} elseif ( ! empty( $legacy ) && ( is_singular( $legacy ) || is_post_type_archive( $legacy ) ) ) {
			$noindex = true;
		} elseif ( is_author() && function_exists( 'lafka_seo_noindex_author_archives' ) && lafka_seo_noindex_author_archives() ) {
			$noindex = true;
		} elseif ( is_singular() && '1' === (string) get_post_meta( (int) get_queried_object_id(), '_lafka_seo_noindex', true ) ) {
			$noindex = true;
		}

		/**
		 * Filter whether the current request is noindexed by Lafka.
		 *
		 * @since 10.2.0
		 * @param bool $noindex Computed decision.
		 */
		return (bool) apply_filters( 'lafka_seo_noindex', $noindex );
	}
}

if ( ! function_exists( 'lafka_seo_wp_robots' ) ) {
	/**
	 * `wp_robots`: add `noindex, follow` where lafka_seo_should_noindex() says so.
	 *
	 * @param array<string,mixed> $robots Directives.
	 * @return array<string,mixed>
	 */
	function lafka_seo_wp_robots( $robots ) {
		$robots = is_array( $robots ) ? $robots : array();
		if ( lafka_seo_should_noindex() ) {
			unset( $robots['index'] );
			$robots['noindex'] = true;
			$robots['follow']  = true;
		}
		return $robots;
	}
}

if ( function_exists( 'add_filter' ) ) {
	add_filter( 'robots_txt', 'lafka_robots_filter', 10, 2 );
	add_filter( 'wp_robots', 'lafka_seo_wp_robots', 20 );
}
