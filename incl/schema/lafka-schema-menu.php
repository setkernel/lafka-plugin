<?php
/**
 * P6-SEO-2: Menu + MenuSection + MenuItem schema generator.
 *
 * Emitted on /menu/, product-category archives, and (optionally) the homepage.
 * Queries WooCommerce products grouped by product category. Results are cached
 * in a transient (12-hour TTL) so the heavy product query runs at most every
 * 12 hours per page type.
 *
 * Cache busting: the `save_post_product` action deletes the transient when
 * any product is saved in the admin, so menu changes appear within one page load.
 *
 * @package Lafka\Plugin\Schema
 * @since   8.8.1
 */

defined( 'ABSPATH' ) || exit;

/** Transient key for the Menu JSON-LD cache. */
const LAFKA_MENU_JSONLD_TRANSIENT = 'lafka_menu_jsonld';

/**
 * Determine whether the current page is a "menu context" that should receive
 * the Menu schema (menu page, product category archive, or shop page).
 *
 * @return bool
 */
function lafka_schema_is_menu_context(): bool {
	if ( ! function_exists( 'is_product_category' ) ) {
		return false;
	}
	return is_shop() || is_product_category() || lafka_schema_is_menu_page();
}

/**
 * Return true when the current page is the /menu/ page by slug or the WC
 * shop page, or a page whose slug is 'menu'.
 *
 * @return bool
 */
function lafka_schema_is_menu_page(): bool {
	if ( is_front_page() ) {
		return false;
	}
	// Check for a page with slug 'menu' or 'order'.
	$obj = get_queried_object();
	if ( $obj instanceof WP_Post && in_array( $obj->post_name, array( 'menu', 'order' ), true ) ) {
		return true;
	}
	return false;
}

/**
 * Build and return the Menu schema array, with transient caching.
 *
 * @return array<string, mixed>|null  Returns null when WooCommerce is absent or
 *                                    no products exist.
 */
function lafka_schema_menu(): ?array {
	if ( ! function_exists( 'wc_get_products' ) ) {
		return null;
	}

	// Try transient cache first.
	$cached = get_transient( LAFKA_MENU_JSONLD_TRANSIENT );
	if ( false !== $cached && is_array( $cached ) ) {
		return $cached;
	}

	$nap      = lafka_schema_get_nap();
	$home_url = trailingslashit( home_url( '/' ) );
	// Canonical menu URL (f104) — shared with the BreadcrumbList "Menu" crumb
	// and the on-page CTAs so the Menu node's @id/url can't drift from them.
	$menu_url = lafka_get_menu_url();

	// Fetch all published product categories (excluding hidden/empty).
	$categories = get_terms(
		array(
			'taxonomy'   => 'product_cat',
			'hide_empty' => true,
			'orderby'    => 'name',
			'order'      => 'ASC',
		)
	);

	if ( is_wp_error( $categories ) || empty( $categories ) ) {
		return null;
	}

	$sections = array();

	foreach ( $categories as $cat ) {
		if ( ! ( $cat instanceof WP_Term ) ) {
			continue;
		}

		// Skip the default WooCommerce "Uncategorized" category.
		if ( 'uncategorized' === $cat->slug ) {
			continue;
		}

		// Fetch products in this category (lightweight: only IDs + essential fields).
		$products = wc_get_products(
			array(
				'category' => array( $cat->slug ),
				'status'   => 'publish',
				'limit'    => 200,
				'return'   => 'objects',
				'orderby'  => 'menu_order',
				'order'    => 'ASC',
			)
		);

		if ( empty( $products ) ) {
			continue;
		}

		$items = array();
		foreach ( $products as $product ) {
			if ( ! ( $product instanceof WC_Product ) ) {
				continue;
			}

			$item = lafka_schema_build_menu_item( $product );
			if ( null !== $item ) {
				$items[] = $item;
			}
		}

		if ( empty( $items ) ) {
			continue;
		}

		$cat_url = get_term_link( $cat );
		$section = array(
			'@type'       => 'MenuSection',
			'name'        => $cat->name,
			'hasMenuItem' => $items,
		);
		if ( ! is_wp_error( $cat_url ) ) {
			$section['url'] = $cat_url;
		}

		$sections[] = $section;
	}

	if ( empty( $sections ) ) {
		return null;
	}

	$schema = array(
		'@type'          => 'Menu',
		'@id'            => $menu_url . '#menu',
		'name'           => $nap['name'] . ' Menu',
		'url'            => $menu_url,
		'hasMenuSection' => $sections,
	);

	/**
	 * Filter the Menu schema array before caching and emission.
	 *
	 * @since 8.8.1
	 * @param array<string, mixed> $schema The assembled schema array.
	 */
	$schema = (array) apply_filters( 'lafka_schema_menu', $schema );

	// Cache for 12 hours. Busted by save_post_product action (see below).
	set_transient( LAFKA_MENU_JSONLD_TRANSIENT, $schema, 12 * HOUR_IN_SECONDS );

	return $schema;
}

/**
 * Bust the menu schema transient cache when anything affecting menu output
 * changes. Without this set of hooks the 12-hour TTL would let stale data
 * drift after legitimate edits — a product going out of stock would still
 * read `availability: InStock` to crawlers for hours.
 *
 * Triggers:
 *   - save_post_product                     — product create/update (admin save)
 *   - delete_post                           — product trash/delete (filtered to product post-type)
 *   - woocommerce_product_set_stock_status  — explicit stock toggle
 *   - woocommerce_variation_set_stock_status — variation stock toggle
 *   - woocommerce_update_product            — programmatic API/CLI updates
 *   - edited_product_cat / created_product_cat / delete_product_cat — category taxonomy edits
 */
$lafka_schema_menu_cache_bust = static function () {
	delete_transient( LAFKA_MENU_JSONLD_TRANSIENT );
};

add_action( 'save_post_product', $lafka_schema_menu_cache_bust );
add_action( 'woocommerce_product_set_stock_status', $lafka_schema_menu_cache_bust );
add_action( 'woocommerce_variation_set_stock_status', $lafka_schema_menu_cache_bust );
add_action( 'woocommerce_update_product', $lafka_schema_menu_cache_bust );
add_action( 'edited_product_cat', $lafka_schema_menu_cache_bust );
add_action( 'created_product_cat', $lafka_schema_menu_cache_bust );
add_action( 'delete_product_cat', $lafka_schema_menu_cache_bust );

// Trash/delete is post-type-agnostic; filter by product post type to avoid
// busting the cache on every unrelated post deletion sitewide.
add_action(
	'delete_post',
	static function ( $post_id ) use ( $lafka_schema_menu_cache_bust ) {
		if ( 'product' === get_post_type( $post_id ) ) {
			$lafka_schema_menu_cache_bust();
		}
	}
);
