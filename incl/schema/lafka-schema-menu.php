<?php
/**
 * P6-SEO-2 + GX3: Menu + MenuSection + MenuItem schema generator.
 *
 * GX3 reshaped the Menu node into part of one linked entity graph:
 *   - Restaurant (#restaurant) → hasMenu → Menu (#menu) → provider → #restaurant;
 *   - one MenuSection per LEAF product category — a parent category only
 *     carries the items that sit directly in it (never its children's items
 *     again, which duplicated a 31-item "Pizza" section on top of its four
 *     sub-sections);
 *   - MenuItems link to their product page (`url`/`@id`) and carry
 *     `suitableForDiet` from the operator's dietary tags;
 *   - the full menu is emitted on the menu page and shop (and, opt-in, the
 *     home page); a category archive emits ONLY its own section(s) instead of
 *     the whole ~58 KB menu on every category page.
 *
 * The expensive part — querying every category's products and building the
 * items — is cached as one data structure (transient, 12h, busted on any
 * product / category / stock change). The same structure feeds /llms.txt,
 * /menu.md and /menu.json (incl/seo/lafka-llms-txt.php) and the category
 * {price_from} title/description token, so every surface tells the same story.
 *
 * @package Lafka\Plugin\Schema
 * @since   8.8.1
 */

defined( 'ABSPATH' ) || exit;

/** Transient key for the cached menu data (v2 = GX3 section structure). */
const LAFKA_MENU_JSONLD_TRANSIENT = 'lafka_menu_jsonld_v2';

/**
 * Determine whether the current page is a "menu context" that should receive
 * the Menu schema: the menu page, the shop, a product-category archive, or
 * the home page when the operator opted in.
 *
 * @return bool
 */
function lafka_schema_is_menu_context(): bool {
	if ( ! function_exists( 'is_product_category' ) ) {
		return false;
	}
	if ( is_shop() || is_product_category() || lafka_schema_is_menu_page() ) {
		return true;
	}
	return is_front_page() && function_exists( 'lafka_seo_is_on' ) && lafka_seo_is_on( 'lafka_seo_menu_schema_on_home' );
}

/**
 * Return true when the current page is the /menu/ page (a page with slug
 * 'menu' or 'order'). The front page never counts.
 *
 * @return bool
 */
function lafka_schema_is_menu_page(): bool {
	if ( is_front_page() ) {
		return false;
	}
	// WooCommerce 11.0 made the Shop archive's queried object the Shop *page*
	// (a WP_Post). When that page's slug is `menu` / `order` the slug check
	// below would claim the product archive is "the menu page". The shop is
	// its own, separately handled context (lafka_schema_is_menu_context()),
	// so a product archive is never the menu page.
	if ( ( function_exists( 'is_shop' ) && is_shop() ) || ( function_exists( 'is_post_type_archive' ) && is_post_type_archive( 'product' ) ) ) {
		return false;
	}
	$obj = get_queried_object();
	if ( $obj instanceof WP_Post && in_array( $obj->post_name, array( 'menu', 'order' ), true ) ) {
		return true;
	}
	return false;
}

/**
 * Build (or read from cache) the menu data every menu surface is made from.
 *
 * Shape:
 *   sections: term_id => array{
 *     term_id:int, name:string, slug:string, parent:int, url:string,
 *     description:string, leaf:bool,
 *     items:list<array>        MenuItem nodes — for a parent, ONLY its direct items,
 *     all_count:int            products in the category incl. descendants,
 *     price_min:string, price_max:string   over all_count products ('' when unpriced),
 *   }
 *   order: list<int>  term ids in menu order.
 *
 * @return array{sections:array<int,array<string,mixed>>,order:list<int>}
 */
function lafka_schema_menu_data(): array {
	$empty = array(
		'sections' => array(),
		'order'    => array(),
	);
	if ( ! function_exists( 'wc_get_products' ) ) {
		return $empty;
	}

	$cached = get_transient( LAFKA_MENU_JSONLD_TRANSIENT );
	if ( is_array( $cached ) && isset( $cached['sections'], $cached['order'] ) ) {
		return $cached;
	}

	$categories = get_terms(
		array(
			'taxonomy'   => 'product_cat',
			'hide_empty' => true,
			'orderby'    => 'menu_order',
			'order'      => 'ASC',
		)
	);
	if ( is_wp_error( $categories ) || empty( $categories ) ) {
		return $empty;
	}

	$terms    = array();
	$children = array();
	foreach ( $categories as $cat ) {
		if ( ! ( $cat instanceof WP_Term ) || 'uncategorized' === $cat->slug ) {
			continue;
		}
		$terms[ (int) $cat->term_id ] = $cat;
	}
	foreach ( $terms as $id => $cat ) {
		$parent = (int) $cat->parent;
		if ( $parent && isset( $terms[ $parent ] ) ) {
			$children[ $parent ][] = $id;
		}
	}

	// Products per category (WC's category query includes descendants).
	$items_by_term = array();
	$ids_by_term   = array();
	$prices        = array();
	foreach ( $terms as $id => $cat ) {
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
		$items_by_term[ $id ] = array();
		$ids_by_term[ $id ]   = array();
		$prices[ $id ]        = array();
		foreach ( (array) $products as $product ) {
			if ( ! ( $product instanceof WC_Product ) ) {
				continue;
			}
			$item = lafka_schema_build_menu_item( $product );
			if ( null === $item ) {
				continue;
			}
			$pid                           = (int) $product->get_id();
			$items_by_term[ $id ][ $pid ]  = $item;
			$ids_by_term[ $id ][]          = $pid;
			$low                           = lafka_schema_menu_item_low_price( $item );
			$high                          = lafka_schema_menu_item_high_price( $item );
			if ( null !== $low ) {
				$prices[ $id ][] = $low;
			}
			if ( null !== $high ) {
				$prices[ $id ][] = $high;
			}
		}
	}

	$descendants = static function ( int $id ) use ( &$descendants, $children ): array {
		$out = array();
		foreach ( $children[ $id ] ?? array() as $child ) {
			$out[] = $child;
			$out   = array_merge( $out, $descendants( $child ) );
		}
		return $out;
	};

	$sections = array();
	$order    = array();
	foreach ( $terms as $id => $cat ) {
		$leaf  = empty( $children[ $id ] );
		$items = $items_by_term[ $id ];
		if ( ! $leaf ) {
			// A parent keeps only the items no descendant section already lists.
			foreach ( $descendants( $id ) as $child ) {
				foreach ( $ids_by_term[ $child ] ?? array() as $pid ) {
					unset( $items[ $pid ] );
				}
			}
		}
		if ( empty( $items_by_term[ $id ] ) ) {
			continue;
		}
		$url = get_term_link( $cat );

		$sections[ $id ] = array(
			'term_id'     => $id,
			'name'        => (string) $cat->name,
			'slug'        => (string) $cat->slug,
			'parent'      => (int) $cat->parent,
			'url'         => is_wp_error( $url ) ? '' : (string) $url,
			'description' => (string) $cat->description,
			'leaf'        => $leaf,
			'items'       => array_values( $items ),
			'all_count'   => count( $ids_by_term[ $id ] ),
			'price_min'   => empty( $prices[ $id ] ) ? '' : number_format( min( $prices[ $id ] ), 2, '.', '' ),
			'price_max'   => empty( $prices[ $id ] ) ? '' : number_format( max( $prices[ $id ] ), 2, '.', '' ),
		);
		$order[]         = $id;
	}

	$data = array(
		'sections' => $sections,
		'order'    => $order,
	);

	// Cache for 12 hours. Busted by the hooks at the bottom of this file.
	set_transient( LAFKA_MENU_JSONLD_TRANSIENT, $data, 12 * HOUR_IN_SECONDS );

	return $data;
}

/**
 * Lowest price in a MenuItem's offer, or null.
 *
 * @param array<string,mixed> $item MenuItem node.
 * @return float|null
 */
function lafka_schema_menu_item_low_price( array $item ): ?float {
	$offer = $item['offers'] ?? null;
	if ( ! is_array( $offer ) ) {
		return null;
	}
	if ( isset( $offer['lowPrice'] ) ) {
		return (float) $offer['lowPrice'];
	}
	return isset( $offer['price'] ) ? (float) $offer['price'] : null;
}

/**
 * Highest price in a MenuItem's offer, or null.
 *
 * @param array<string,mixed> $item MenuItem node.
 * @return float|null
 */
function lafka_schema_menu_item_high_price( array $item ): ?float {
	$offer = $item['offers'] ?? null;
	if ( ! is_array( $offer ) ) {
		return null;
	}
	if ( isset( $offer['highPrice'] ) ) {
		return (float) $offer['highPrice'];
	}
	return isset( $offer['price'] ) ? (float) $offer['price'] : null;
}

/**
 * The MenuSection node for one cached section (null when it has no items of
 * its own — a parent whose products all live in sub-categories).
 *
 * @param array<string,mixed> $section Section from lafka_schema_menu_data().
 * @return array<string,mixed>|null
 */
function lafka_schema_menu_section_node( array $section ): ?array {
	if ( empty( $section['items'] ) ) {
		return null;
	}
	$node = array(
		'@type'       => 'MenuSection',
		'name'        => (string) $section['name'],
		'hasMenuItem' => array_values( $section['items'] ),
	);
	if ( '' !== (string) $section['url'] ) {
		$node['@id'] = (string) $section['url'] . '#menusection';
		$node['url'] = (string) $section['url'];
	}
	return $node;
}

/**
 * Term ids of a category and all its descendants, in menu order.
 *
 * @param array{sections:array<int,array<string,mixed>>,order:list<int>} $data    Menu data.
 * @param int                                                            $term_id Root term.
 * @return list<int>
 */
function lafka_schema_menu_subtree( array $data, int $term_id ): array {
	$ids = array();
	foreach ( $data['order'] as $id ) {
		$cursor = (int) $id;
		$guard  = 0;
		while ( $cursor && $guard++ < 20 ) {
			if ( $cursor === $term_id ) {
				$ids[] = (int) $id;
				break;
			}
			$cursor = (int) ( $data['sections'][ $cursor ]['parent'] ?? 0 );
		}
	}
	return $ids;
}

/**
 * Build the Menu schema node for the current request.
 *
 * On a product-category archive only that category's section(s) are
 * included (its own direct items + its descendants' sections); everywhere
 * else the full menu.
 *
 * @return array<string, mixed>|null Null when WooCommerce is absent or the menu is empty.
 */
function lafka_schema_menu(): ?array {
	$data = lafka_schema_menu_data();
	if ( empty( $data['sections'] ) ) {
		return null;
	}

	$ids   = $data['order'];
	$scope = 'full';
	if ( function_exists( 'is_product_category' ) && is_product_category() ) {
		$term = get_queried_object();
		if ( $term instanceof WP_Term ) {
			$ids   = lafka_schema_menu_subtree( $data, (int) $term->term_id );
			$scope = 'category';
		}
	}

	return lafka_schema_menu_node( $data, $ids, $scope );
}

/**
 * Assemble a Menu node from cached menu data.
 *
 * @param array{sections:array<int,array<string,mixed>>,order:list<int>} $data  Menu data.
 * @param list<int>                                                      $ids   Section term ids to include, in order.
 * @param string                                                         $scope 'full' | 'category' (passed to the filter).
 * @return array<string,mixed>|null
 */
function lafka_schema_menu_node( array $data, array $ids, string $scope = 'full' ): ?array {
	$sections = array();
	foreach ( $ids as $id ) {
		if ( ! isset( $data['sections'][ $id ] ) ) {
			continue;
		}
		$node = lafka_schema_menu_section_node( $data['sections'][ $id ] );
		if ( null !== $node ) {
			$sections[] = $node;
		}
	}
	if ( empty( $sections ) ) {
		return null;
	}

	$nap      = lafka_schema_get_nap();
	$menu_url = lafka_get_menu_url();

	$schema = array(
		'@type'          => 'Menu',
		'@id'            => $menu_url . '#menu',
		/* translators: %s: restaurant name. */
		'name'           => trim( sprintf( __( '%s Menu', 'lafka-plugin' ), $nap['name'] ) ),
		'url'            => $menu_url,
		'hasMenuSection' => $sections,
	);

	// Link back to the Restaurant node — only when it is in the @graph (same
	// predicate as WebSite.publisher, so the reference never dangles).
	if ( function_exists( 'lafka_schema_has_restaurant_basics' ) && lafka_schema_has_restaurant_basics() ) {
		$home               = trailingslashit( home_url( '/' ) );
		$schema['provider'] = array( '@id' => $home . '#restaurant' );
	}

	/**
	 * Filter the Menu schema array before emission.
	 *
	 * @since 8.8.1
	 * @since 10.2.0 Second argument: 'full' or 'category'.
	 * @param array<string, mixed> $schema The assembled schema array.
	 * @param string               $scope  Which slice of the menu this is.
	 */
	return (array) apply_filters( 'lafka_schema_menu', $schema, $scope );
}

/**
 * Bust the menu data cache when anything affecting menu output changes.
 * Without this set of hooks the 12-hour TTL would let stale data drift after
 * legitimate edits — a product going out of stock would still read
 * `availability: InStock` to crawlers for hours.
 *
 * Also fires `lafka_menu_data_changed` so derived caches (llms.txt,
 * menu.md / menu.json) are dropped in the same breath.
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
	if ( function_exists( 'do_action' ) ) {
		do_action( 'lafka_menu_data_changed' );
	}
};

add_action( 'save_post_product', $lafka_schema_menu_cache_bust );
add_action( 'woocommerce_product_set_stock_status', $lafka_schema_menu_cache_bust );
add_action( 'woocommerce_variation_set_stock_status', $lafka_schema_menu_cache_bust );
add_action( 'woocommerce_update_product', $lafka_schema_menu_cache_bust );
add_action( 'edited_product_cat', $lafka_schema_menu_cache_bust );
add_action( 'created_product_cat', $lafka_schema_menu_cache_bust );
add_action( 'delete_product_cat', $lafka_schema_menu_cache_bust );
add_action( 'edited_product_tag', $lafka_schema_menu_cache_bust );

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
