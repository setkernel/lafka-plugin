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

// ─── GX3: indexing hygiene ───────────────────────────────────────────────
//
// Shared predicates (also used by the wp_robots filter in lafka-robots.php and
// by Site Health): what must never be indexed or listed in the sitemap.

if ( ! function_exists( 'lafka_seo_excluded_taxonomies' ) ) {
	/**
	 * Taxonomies whose archives are thin / duplicate for a restaurant:
	 * WooCommerce attribute taxonomies (`pa_*`, e.g. /size/large/ listing
	 * every product with that size) and the legacy food-menu categories.
	 *
	 * @return list<string>
	 */
	function lafka_seo_excluded_taxonomies(): array {
		$taxonomies = array();
		if ( function_exists( 'get_taxonomies' ) ) {
			foreach ( (array) get_taxonomies( array(), 'names' ) as $name ) {
				if ( 0 === strpos( (string) $name, 'pa_' ) ) {
					$taxonomies[] = (string) $name;
				}
			}
		}
		if ( in_array( 'lafka-foodmenu', lafka_seo_legacy_post_types(), true ) ) {
			$taxonomies[] = 'lafka_foodmenu_category';
		}
		/**
		 * Filter the taxonomies kept out of the sitemap and noindexed.
		 *
		 * @since 10.2.0
		 * @param list<string> $taxonomies Taxonomy names.
		 */
		return array_values( array_unique( array_map( 'strval', (array) apply_filters( 'lafka_seo_excluded_taxonomies', $taxonomies ) ) ) );
	}
}

if ( ! function_exists( 'lafka_seo_legacy_post_types' ) ) {
	/**
	 * Post types superseded on this install: the theme's original
	 * `lafka-foodmenu` CPT once WooCommerce products are the menu (demo
	 * leftovers like "/restaurant-menu/angus-burger/" otherwise compete with
	 * the real menu).
	 *
	 * @return list<string>
	 */
	function lafka_seo_legacy_post_types(): array {
		$types = ( class_exists( 'WooCommerce' ) || function_exists( 'wc_get_products' ) ) ? array( 'lafka-foodmenu' ) : array();
		/**
		 * Filter the post types treated as legacy (not in sitemap, noindex).
		 *
		 * @since 10.2.0
		 * @param list<string> $types Post type names.
		 */
		return array_values( array_map( 'strval', (array) apply_filters( 'lafka_seo_legacy_post_types', $types ) ) );
	}
}

if ( ! function_exists( 'lafka_seo_noindex_author_archives' ) ) {
	/**
	 * Whether author archives are noindexed: by default when at most one
	 * user has published posts (the archive then duplicates the blog).
	 *
	 * @return bool
	 */
	function lafka_seo_noindex_author_archives(): bool {
		static $single = null;
		if ( null === $single ) {
			$authors = function_exists( 'get_users' )
				? get_users(
					array(
						'has_published_posts' => true,
						'fields'              => 'ID',
						'number'              => 2,
					)
				)
				: array();
			$single  = count( (array) $authors ) <= 1;
		}
		/**
		 * Filter whether author archives are noindexed.
		 *
		 * @since 10.2.0
		 * @param bool $single Default: true on single-author sites.
		 */
		return (bool) apply_filters( 'lafka_seo_noindex_author_archives', $single );
	}
}

if ( ! function_exists( 'lafka_sitemap_filter_taxonomies' ) ) {
	/**
	 * `wp_sitemaps_taxonomies`: drop attribute / legacy taxonomies.
	 *
	 * @param array<string,mixed> $taxonomies Taxonomy objects keyed by name.
	 * @return array<string,mixed>
	 */
	function lafka_sitemap_filter_taxonomies( $taxonomies ) {
		if ( ! is_array( $taxonomies ) ) {
			return $taxonomies;
		}
		foreach ( lafka_seo_excluded_taxonomies() as $name ) {
			unset( $taxonomies[ $name ] );
		}
		return $taxonomies;
	}
}

if ( ! function_exists( 'lafka_sitemap_filter_post_types' ) ) {
	/**
	 * `wp_sitemaps_post_types`: drop legacy post types.
	 *
	 * @param array<string,mixed> $post_types Post type objects keyed by name.
	 * @return array<string,mixed>
	 */
	function lafka_sitemap_filter_post_types( $post_types ) {
		if ( ! is_array( $post_types ) ) {
			return $post_types;
		}
		foreach ( lafka_seo_legacy_post_types() as $name ) {
			unset( $post_types[ $name ] );
		}
		return $post_types;
	}
}

if ( ! function_exists( 'lafka_sitemap_exclude_noindexed' ) ) {
	/**
	 * `wp_sitemaps_posts_query_args`: leave out posts the operator marked
	 * "hide from search engines" (`_lafka_seo_noindex`).
	 *
	 * @param array<string,mixed> $args WP_Query args.
	 * @return array<string,mixed>
	 */
	function lafka_sitemap_exclude_noindexed( $args ) {
		if ( ! is_array( $args ) ) {
			return $args;
		}
		$clause = array(
			'key'     => '_lafka_seo_noindex',
			'compare' => 'NOT EXISTS',
		);
		$existing           = isset( $args['meta_query'] ) && is_array( $args['meta_query'] ) ? $args['meta_query'] : array();
		$combined           = array( $clause );
		if ( ! empty( $existing ) ) {
			$combined = array(
				'relation' => 'AND',
				$existing,
				$clause,
			);
		}
		$args['meta_query'] = $combined; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- sitemap sub-query only.
		return $args;
	}
}

// ─── GX3: product image entries ──────────────────────────────────────────

if ( ! function_exists( 'lafka_sitemap_images_enabled' ) ) {
	/**
	 * @return bool
	 */
	function lafka_sitemap_images_enabled(): bool {
		/**
		 * Filter whether product sitemap entries carry <image:image> tags.
		 *
		 * @since 10.2.0
		 * @param bool $enabled Default true.
		 */
		return (bool) apply_filters( 'lafka_sitemap_images_enabled', true );
	}
}

if ( ! function_exists( 'lafka_sitemap_use_image_renderer' ) ) {
	/**
	 * `wp_sitemaps_init`: core's renderer only knows loc/lastmod/changefreq/
	 * priority, so swap in a subclass that also writes image entries.
	 *
	 * @param object $wp_sitemaps WP_Sitemaps.
	 * @return void
	 */
	function lafka_sitemap_use_image_renderer( $wp_sitemaps ) {
		if ( ! lafka_sitemap_images_enabled() || ! is_object( $wp_sitemaps ) || ! class_exists( 'WP_Sitemaps_Renderer' ) ) {
			return;
		}
		require_once __DIR__ . '/class-lafka-sitemaps-image-renderer.php';
		$wp_sitemaps->renderer          = new Lafka_Sitemaps_Image_Renderer();
		$GLOBALS['lafka_sitemap_images'] = true;
	}
}

if ( ! function_exists( 'lafka_sitemap_product_images' ) ) {
	/**
	 * `wp_sitemaps_posts_entry`: add a product's featured + gallery images
	 * (only when the image-aware renderer is active; core would reject the
	 * unknown key).
	 *
	 * @param array<string,mixed> $entry     Sitemap entry.
	 * @param object              $post      WP_Post.
	 * @param string              $post_type Post type.
	 * @return array<string,mixed>
	 */
	function lafka_sitemap_product_images( $entry, $post, $post_type = '' ) {
		if ( empty( $GLOBALS['lafka_sitemap_images'] ) || 'product' !== $post_type || ! is_object( $post ) || ! isset( $post->ID ) ) {
			return $entry;
		}
		$ids = array();
		if ( function_exists( 'get_post_thumbnail_id' ) ) {
			$ids[] = (int) get_post_thumbnail_id( $post->ID );
		}
		$gallery = (string) get_post_meta( (int) $post->ID, '_product_image_gallery', true );
		foreach ( array_filter( array_map( 'absint', explode( ',', $gallery ) ) ) as $id ) {
			$ids[] = $id;
		}
		$urls = array();
		foreach ( array_slice( array_values( array_unique( array_filter( $ids ) ) ), 0, 10 ) as $id ) {
			$url = (string) wp_get_attachment_image_url( $id, 'full' );
			if ( '' !== $url ) {
				$urls[] = $url;
			}
		}
		if ( ! empty( $urls ) ) {
			$entry['lafka_images'] = $urls;
		}
		return $entry;
	}
}

if ( function_exists( 'add_filter' ) ) {
	add_filter( 'wp_sitemaps_posts_query_args', 'lafka_sitemap_filter_page_args', 10, 2 );
	add_filter( 'wp_sitemaps_add_provider', 'lafka_sitemap_drop_users_provider', 10, 2 );
	add_filter( 'wp_sitemaps_posts_query_args', 'lafka_sitemap_exclude_noindexed', 20 );
	add_filter( 'wp_sitemaps_taxonomies', 'lafka_sitemap_filter_taxonomies' );
	add_filter( 'wp_sitemaps_post_types', 'lafka_sitemap_filter_post_types' );
	add_filter( 'wp_sitemaps_posts_entry', 'lafka_sitemap_product_images', 10, 3 );
	add_action( 'wp_sitemaps_init', 'lafka_sitemap_use_image_renderer' );
}
