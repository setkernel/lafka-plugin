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
 *  - Single products: Home → Menu → Category → Product.
 *  - Product categories: Home → Menu → Category.
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

	// Always start with Home.
	$items[] = lafka_schema_breadcrumb_item( $position++, 'Home', $home_url );

	$obj = get_queried_object();

	if ( function_exists( 'is_product' ) && is_product() && $obj instanceof WP_Post ) {
		// Single product: Home → Menu → [Primary Category] → Product.
		$menu_url = trailingslashit( home_url( '/menu/' ) );
		$items[]  = lafka_schema_breadcrumb_item( $position++, 'Menu', $menu_url );

		// Primary category (first term).
		$terms = get_the_terms( $obj->ID, 'product_cat' );
		if ( is_array( $terms ) && ! empty( $terms ) ) {
			$primary = reset( $terms );
			if ( $primary instanceof WP_Term ) {
				$cat_url = get_term_link( $primary );
				if ( ! is_wp_error( $cat_url ) ) {
					$items[] = lafka_schema_breadcrumb_item( $position++, $primary->name, $cat_url );
				}
			}
		}

		$items[] = lafka_schema_breadcrumb_item( $position++, get_the_title( $obj ), get_permalink( $obj ) );

	} elseif ( function_exists( 'is_product_category' ) && is_product_category() && $obj instanceof WP_Term ) {
		// Product category archive: Home → Menu → Category.
		$menu_url = trailingslashit( home_url( '/menu/' ) );
		$items[]  = lafka_schema_breadcrumb_item( $position++, 'Menu', $menu_url );
		$cat_url  = get_term_link( $obj );
		if ( ! is_wp_error( $cat_url ) ) {
			$items[] = lafka_schema_breadcrumb_item( $position++, $obj->name, $cat_url );
		}

	} elseif ( function_exists( 'is_shop' ) && is_shop() ) {
		// Shop / menu page: Home → Menu.
		$shop_url = function_exists( 'wc_get_page_permalink' ) ? wc_get_page_permalink( 'shop' ) : home_url( '/menu/' );
		$shop_url = $shop_url ?: home_url( '/menu/' );
		$items[]  = lafka_schema_breadcrumb_item( $position++, 'Menu', $shop_url );

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
