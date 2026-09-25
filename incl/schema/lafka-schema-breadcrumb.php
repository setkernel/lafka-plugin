<?php
/**
 * P6-SEO-6: BreadcrumbList schema generator.
 *
 * Emitted on every non-homepage page. Builds a schema.org/BreadcrumbList
 * that mirrors the visible page hierarchy, giving Google a clean breadcrumb
 * trail to display in SERP in place of raw URL fragments.
 *
 * @package Lafka\Plugin\Schema
 * @since   8.8.1
 */

defined( 'ABSPATH' ) || exit;

/**
 * Build and return the BreadcrumbList schema array for the current page.
 *
 * Logic:
 *  - Homepage is always position 1.
 *  - Single products: Home → Menu → [Parent categories →] Category → Product.
 *  - Product categories: Home → Menu → [Parent categories →] Category.
 *  - Shop / menu page: Home → Menu.
 *  - Standard pages: Home → Page Title.
 *  - Posts / archives: Home → Blog → Post.
 *
 * @return array<string, mixed>|null  Null on homepage or when queried object is unavailable.
 */
function lafka_schema_breadcrumb(): ?array {
	if ( is_front_page() ) {
		return null;
	}

	$home_url  = trailingslashit( home_url( '/' ) );
	$items     = array();
	$position  = 1;

	// Always start with Home. Labels are translatable so non-English stores
	// emit breadcrumbs that match their locale (Google uses the label
	// directly in the SERP breadcrumb trail).
	$items[] = lafka_schema_breadcrumb_item( $position++, __( 'Home', 'lafka-plugin' ), $home_url );

	$obj = get_queried_object();

	if ( function_exists( 'is_product' ) && is_product() && $obj instanceof WP_Post ) {
		// Single product: Home → Menu → [Primary Category] → Product.
		$menu_url = lafka_get_menu_url();
		$items[]  = lafka_schema_breadcrumb_item( $position++, __( 'Menu', 'lafka-plugin' ), $menu_url );

		// Category trail: the same primary term WooCommerce's visible
		// breadcrumb picks (deepest term first — orderby parent DESC, through
		// the same `woocommerce_breadcrumb_product_terms_args` filter), then
		// its ancestors top-down, so structured and visible trails match
		// (Home / Menu / Pizza / Classic pizzas / Works).
		$primary = lafka_schema_breadcrumb_primary_term( (int) $obj->ID );
		if ( $primary instanceof WP_Term ) {
			foreach ( lafka_schema_breadcrumb_term_trail( $primary ) as $trail_term ) {
				$cat_url = get_term_link( $trail_term );
				if ( ! is_wp_error( $cat_url ) ) {
					$items[] = lafka_schema_breadcrumb_item( $position++, $trail_term->name, $cat_url );
				}
			}
		}

		$items[] = lafka_schema_breadcrumb_item( $position++, get_the_title( $obj ), get_permalink( $obj ) );

	} elseif ( function_exists( 'is_product_category' ) && is_product_category() && $obj instanceof WP_Term ) {
		// Product category archive: Home → Menu → Category.
		$menu_url = lafka_get_menu_url();
		$items[]  = lafka_schema_breadcrumb_item( $position++, __( 'Menu', 'lafka-plugin' ), $menu_url );
		foreach ( lafka_schema_breadcrumb_term_trail( $obj ) as $trail_term ) {
			$cat_url = get_term_link( $trail_term );
			if ( ! is_wp_error( $cat_url ) ) {
				$items[] = lafka_schema_breadcrumb_item( $position++, $trail_term->name, $cat_url );
			}
		}
	} elseif ( function_exists( 'is_shop' ) && is_shop() ) {
		// Shop archive: Home → Menu. The "Menu" crumb resolves to the canonical
		// /menu/ browse page (f104) — the SAME target as the product/category
		// crumbs above and the visible breadcrumb in archive-product.php — rather
		// than wc_get_page_permalink( 'shop' ), so structured + visible can't diverge.
		$items[] = lafka_schema_breadcrumb_item( $position++, __( 'Menu', 'lafka-plugin' ), lafka_get_menu_url() );

	} elseif ( is_singular() && $obj instanceof WP_Post ) {
		// Standard pages and posts.
		if ( is_page() ) {
			$items[] = lafka_schema_breadcrumb_item( $position++, get_the_title( $obj ), get_permalink( $obj ) );
		} else {
			// Posts.
			$posts_page = get_option( 'page_for_posts' );
			if ( $posts_page ) {
				$blog_title = get_the_title( (int) $posts_page );
				$blog_url   = get_permalink( (int) $posts_page );
				if ( $blog_url ) {
					$items[] = lafka_schema_breadcrumb_item( $position++, $blog_title, $blog_url );
				}
			}
			$items[] = lafka_schema_breadcrumb_item( $position++, get_the_title( $obj ), get_permalink( $obj ) );
		}   
	} elseif ( is_category() && $obj instanceof WP_Term ) {
		$cat_url = get_term_link( $obj );
		if ( ! is_wp_error( $cat_url ) ) {
			$items[] = lafka_schema_breadcrumb_item( $position++, $obj->name, $cat_url );
		}
	}

	// Need at least Home + one more item to be meaningful.
	if ( count( $items ) < 2 ) {
		return null;
	}

	$schema = array(
		'@type'           => 'BreadcrumbList',
		'itemListElement' => $items,
	);

	/**
	 * Filter the BreadcrumbList schema array before emission.
	 *
	 * @since 8.8.1
	 * @param array<string, mixed> $schema The assembled schema array.
	 */
	return (array) apply_filters( 'lafka_schema_breadcrumb', $schema );
}

/**
 * Build a single ListItem for a BreadcrumbList.
 *
 * @param int    $position 1-based position.
 * @param string $name     Label shown in SERP.
 * @param string $url      Absolute URL of the crumb target.
 * @return array{@type: string, position: int, name: string, item: string}
 */
function lafka_schema_breadcrumb_item( int $position, string $name, string $url ): array {
	return array(
		'@type'    => 'ListItem',
		'position' => $position,
		'name'     => $name,
		'item'     => $url,
	);
}

/**
 * The product's primary category, chosen exactly as WooCommerce's visible
 * breadcrumb chooses it (WC_Breadcrumb::add_crumbs_single(): the first of the
 * product's terms ordered by parent DESC — i.e. a subcategory before its
 * parent — through the `woocommerce_breadcrumb_product_terms_args` filter).
 *
 * @param int $product_id Product id.
 * @return WP_Term|null
 */
function lafka_schema_breadcrumb_primary_term( int $product_id ): ?WP_Term {
	if ( function_exists( 'wc_get_product_terms' ) ) {
		$terms = wc_get_product_terms(
			$product_id,
			'product_cat',
			(array) apply_filters(
				'woocommerce_breadcrumb_product_terms_args',
				array(
					'orderby' => 'parent',
					'order'   => 'DESC',
				)
			)
		);
	} else {
		$terms = get_the_terms( $product_id, 'product_cat' );
	}
	if ( ! is_array( $terms ) || empty( $terms ) ) {
		return null;
	}
	$primary = reset( $terms );
	return $primary instanceof WP_Term ? $primary : null;
}

/**
 * A category and its ancestors, top-level first.
 *
 * @param WP_Term $term Category.
 * @return list<WP_Term>
 */
function lafka_schema_breadcrumb_term_trail( WP_Term $term ): array {
	$trail = array();
	if ( function_exists( 'get_ancestors' ) && ! empty( $term->term_id ) ) {
		foreach ( array_reverse( (array) get_ancestors( (int) $term->term_id, (string) $term->taxonomy, 'taxonomy' ) ) as $ancestor_id ) {
			$ancestor = get_term( (int) $ancestor_id, (string) $term->taxonomy );
			if ( $ancestor instanceof WP_Term ) {
				$trail[] = $ancestor;
			}
		}
	}
	$trail[] = $term;
	return $trail;
}
