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
			// WC funnel pages. (The account area is NOT blocked: T-29 noindexes
			// it instead, and a robots block would hide that noindex.)
			'/cart/',
			'/checkout/',
			// Add-to-cart and WC AJAX query strings — on ANY path and in any
			// position of the query (`/*?*` — Google/Bing wildcard syntax);
			// the pre-GX `/?orderby=` form only matched the home page.
			'/*?*add-to-cart=',
			'/*?*wc-ajax=',
			// Shop-archive filter + sort variants (huge crawl-budget drain on
			// WC stores; the canonical already strips these via
			// lafka-shop-canonical.php but crawlers waste budget hitting them).
			'/*?*orderby=',
			'/*?*min_price=',
			'/*?*max_price=',
			'/*?*filter_',
			'/*?*rating_filter=',
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
	 * Merge the Lafka Disallow lines into the rendered robots.txt body.
	 *
	 * Hook signature: ($output, $public). When $public is 0/false the site
	 * is in "Discourage search engines" mode, so the body is left alone.
	 *
	 * T-29 (GX): WordPress core's sitemap module appends "\nSitemap: …" at
	 * priority 0, BEFORE this filter — so appending rules at the end put them
	 * after a blank line and the Sitemap line, i.e. outside any User-agent
	 * group, where crawlers ignore them. The body is now rebuilt:
	 *
	 *   1. Sitemap lines are lifted out (with the blank lines around them);
	 *   2. every Lafka rule is inserted at the end of the first
	 *      `User-agent: *` group (one is created when the body has none);
	 *   3. the Sitemap lines close the file after one blank line.
	 *
	 * Idempotent: a rule already present anywhere is not added again, so
	 * running the filter twice (or after another plugin) changes nothing.
	 *
	 * @param string   $output The default robots.txt content.
	 * @param int|bool $public Whether search engines are allowed (1) or not (0).
	 * @return string
	 */
	function lafka_robots_filter( $output, $public = 1 ): string {
		$output = (string) $output;
		if ( empty( $public ) ) {
			return $output;
		}

		$lines    = preg_split( '/\r\n|\r|\n/', rtrim( $output ) );
		$sitemaps = array();
		$body     = array();
		foreach ( (array) $lines as $line ) {
			if ( preg_match( '/^\s*sitemap\s*:/i', (string) $line ) ) {
				$sitemaps[] = trim( (string) $line );
				continue;
			}
			$body[] = rtrim( (string) $line );
		}

		// New rules, de-duplicated against the body and among themselves.
		$rules = array();
		foreach ( lafka_robots_disallow_paths() as $path ) {
			$rule = 'Disallow: ' . $path;
			if ( in_array( $rule, $body, true ) || in_array( $rule, $rules, true ) ) {
				continue;
			}
			$rules[] = $rule;
		}

		// Locate the first `User-agent: *` group and its last line.
		$start = null;
		foreach ( $body as $i => $line ) {
			if ( preg_match( '/^\s*user-agent\s*:\s*\*\s*$/i', $line ) ) {
				$start = $i;
				break;
			}
		}
		if ( null === $start ) {
			$body = array_merge( array( 'User-agent: *' ), $rules, array( '' ), $body );
		} else {
			$end   = $start;
			$count = count( $body );
			for ( $i = $start + 1; $i < $count; $i++ ) {
				if ( '' === trim( $body[ $i ] ) ) {
					break;
				}
				// A new User-agent line after rules starts the next group.
				if ( preg_match( '/^\s*user-agent\s*:/i', $body[ $i ] ) && $i > $start + 1 && ! preg_match( '/^\s*user-agent\s*:/i', $body[ $i - 1 ] ) ) {
					break;
				}
				$end = $i;
			}
			array_splice( $body, $end + 1, 0, $rules );
		}

		// Collapse blank-line runs and trim the edges.
		$clean = array();
		foreach ( $body as $line ) {
			if ( '' === trim( $line ) && ( empty( $clean ) || '' === end( $clean ) ) ) {
				continue;
			}
			$clean[] = '' === trim( $line ) ? '' : $line;
		}
		while ( ! empty( $clean ) && '' === end( $clean ) ) {
			array_pop( $clean );
		}

		$out = implode( "\n", $clean ) . "\n";
		if ( ! empty( $sitemaps ) ) {
			$out .= "\n" . implode( "\n", array_values( array_unique( $sitemaps ) ) ) . "\n";
		}
		return $out;
	}
}

if ( ! function_exists( 'lafka_seo_is_account_page' ) ) {
	/**
	 * T-29: whether the request is the customer-account area — WooCommerce's
	 * "My account" page (and its endpoints), or a page carrying the
	 * `[woocommerce_my_account]` shortcode while that setting is unset.
	 *
	 * @return bool
	 */
	function lafka_seo_is_account_page(): bool {
		if ( function_exists( 'is_account_page' ) && is_account_page() ) {
			return true;
		}
		if ( is_singular( 'page' ) ) {
			$post = get_post( (int) get_queried_object_id() );
			return is_object( $post ) && false !== strpos( (string) ( $post->post_content ?? '' ), '[woocommerce_my_account' );
		}
		return false;
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
	 *   - any post / page the operator marked "hide from search engines";
	 *   - (T-29) the customer-account area (login / dashboard / endpoints).
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
		} elseif ( lafka_seo_is_account_page() ) {
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
