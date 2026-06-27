<?php
/**
 * Phase 2 (v9.26.0) — sitemap audit + exclusions.
 *
 * WordPress core has shipped a virtual sitemap at `/wp-sitemap.xml` since 5.5.
 * It auto-discovers every public post type and includes every page on the site,
 * INCLUDING the WooCommerce funnel pages (cart, checkout, my-account,
 * order-received, order-pay) — none of which should ever appear in Google's
 * index. They're transactional endpoints, not landing pages.
 *
 * Strategy: filter `wp_sitemaps_posts_query_args` with a `post__not_in` clause
 * resolved from the canonical WooCommerce page slugs. The exclusion list is
 * filterable via `lafka_sitemap_excluded_slugs` so operators can prune (or
 * extend, e.g. a `thank-you` post-purchase page) without forking.
 *
 * Why not unhook the provider entirely? Core's sitemap is otherwise valuable
 * (products, categories, content pages all surface correctly). We only want
 * to curate the page list, not replace the sitemap.
 *
 * @package Lafka\Plugin\SEO
 * @since   9.26.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'lafka_sitemap_excluded_slugs' ) ) {
	/**
	 * Canonical list of page slugs that should NEVER appear in the sitemap.
	 *
	 * These are the WooCommerce transactional pages by convention. We match on
	 * slug rather than ID so the list survives WC page reassignment (operator
	 * moves cart to a different page → slug stays the same → exclusion still
	 * applies). For operators who renamed the slugs, the filter below lets
	 * them add the renamed versions.
	 *
	 * @return array<int, string>
	 */
	function lafka_sitemap_excluded_slugs(): array {
		$slugs = array(
			'cart',
			'checkout',
			'my-account',
			'order-received',
			'order-pay',
		);
		/**
		 * Filter the list of page slugs excluded from the WP core sitemap.
		 *
		 * Operators can extend (e.g. add a `thank-you` page) or prune as needed.
		 *
		 * @since 9.26.0
		 * @param array<int, string> $slugs Default slug list.
		 */
		if ( function_exists( 'apply_filters' ) ) {
			$slugs = (array) apply_filters( 'lafka_sitemap_excluded_slugs', $slugs );
		}
		return array_values( array_unique( array_filter( array_map( 'strval', $slugs ) ) ) );
	}
}

if ( ! function_exists( 'lafka_sitemap_resolve_excluded_ids' ) ) {
	/**
	 * Resolve excluded slugs → post IDs for the current request.
	 *
	 * Uses get_page_by_path() which is keyed on `page` post_type by default.
	 * WC's cart/checkout/my-account pages are all stored as `page`, so this
	 * resolves them whether they came from WC's onboarding wizard or were
	 * created manually by the operator.
	 *
	 * Memoised per-request because the same args resolve multiple times during
	 * sitemap pagination.
	 *
	 * @return array<int, int>
	 */
	function lafka_sitemap_resolve_excluded_ids(): array {
		// No in-process memoization here — get_page_by_path() is already cached
		// by WP's object cache, and sitemap-sub args are queried at most a few
		// times per request. A per-request static would shadow operator filter
		// changes mid-request (and complicate unit tests that exercise the
		// resolver with different slug stubs).
		$ids = array();
		if ( ! function_exists( 'get_page_by_path' ) ) {
			return $ids;
		}
		foreach ( lafka_sitemap_excluded_slugs() as $slug ) {
			$page = get_page_by_path( $slug, OBJECT, 'page' );
			if ( $page instanceof \WP_Post ) {
				$ids[] = (int) $page->ID;
			}
		}
		return array_values( array_unique( $ids ) );
	}
}

if ( ! function_exists( 'lafka_sitemap_filter_page_args' ) ) {
	/**
	 * Filter wp_sitemaps_posts_query_args to drop WC funnel pages.
	 *
	 * Hook fires for every post-type sub-sitemap. We only act when the second
	 * argument is `page` — products and other public post types pass through
	 * unchanged. The list of excluded IDs is merged into `post__not_in` so it
	 * stacks cleanly with any other plugin's exclusions instead of overwriting.
	 *
	 * @param array<string, mixed> $args      WP_Query args used to build the sitemap.
	 * @param string               $post_type Post type slug for this sub-sitemap.
	 * @return array<string, mixed>
	 */
	function lafka_sitemap_filter_page_args( $args, $post_type = '' ) {
		if ( ! is_array( $args ) ) {
			return $args;
		}
		if ( 'page' !== $post_type ) {
			return $args;
		}
		$excluded = lafka_sitemap_resolve_excluded_ids();
		if ( empty( $excluded ) ) {
			return $args;
		}
		$existing = isset( $args['post__not_in'] ) && is_array( $args['post__not_in'] )
			? array_map( 'intval', $args['post__not_in'] )
			: array();
		$args['post__not_in'] = array_values( array_unique( array_merge( $existing, $excluded ) ) );
		return $args;
	}
}

if ( ! function_exists( 'lafka_sitemap_drop_users_provider' ) ) {
	/**
	 * Drop the core "users" sitemap (wp-sitemap-users-N.xml).
	 *
	 * Author archives are thin/duplicate for a single-location restaurant and
	 * the provider enumerates usernames — no SEO value, mild privacy win.
	 * Filterable so a content-heavy install can opt back in.
	 *
	 * @param mixed  $provider The sitemap provider (or already-filtered value).
	 * @param string $name     Provider name: posts | taxonomies | users.
	 * @return mixed False to remove, otherwise the provider unchanged.
	 */
	function lafka_sitemap_drop_users_provider( $provider, $name ) {
		if ( 'users' === $name && ! apply_filters( 'lafka_sitemap_keep_users', false ) ) {
			return false;
		}
		return $provider;
	}
}

if ( function_exists( 'add_filter' ) ) {
	add_filter( 'wp_sitemaps_posts_query_args', 'lafka_sitemap_filter_page_args', 10, 2 );
	add_filter( 'wp_sitemaps_add_provider', 'lafka_sitemap_drop_users_provider', 10, 2 );
}
