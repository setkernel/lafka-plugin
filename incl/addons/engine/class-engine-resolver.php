<?php
/**
 * Lafka_Engine_Resolver — composes the merged addon group list for a product.
 *
 * Walks four sources in priority order:
 *   1. Parent product's groups (if $inc_parent and product has a parent)
 *   2. The product's own groups
 *   3. Global addons marked _all_products = 1
 *   4. Global addons whose product_cat term matches one of the product's
 *      categories
 *
 * Globals are sorted by their post-level _priority meta. Product + parent
 * groups default to priority 10 (legacy convention).
 *
 * Per-request caches keyed on (product_id, inc_parent, inc_global, extra)
 * avoid rebuilding the same list across the four-or-more callsites that
 * touch it on a single PDP render: display, totals, check_required,
 * validate, add_cart_item_data.
 *
 * Phase 7 (v8.15.0) replaces the legacy WC_Product_Addons_Helper read
 * path. Helpers continue to expose a back-compat array shape via
 * Lafka_Engine_Helper::get_product_addons() so templates and any third-
 * party hook receivers see the same structure they always have.
 *
 * @package Lafka_Addons_Engine
 * @since   8.15.0
 */

defined( 'ABSPATH' ) || exit;

class Lafka_Engine_Resolver {

	/**
	 * Per-request cache: cache_key → Lafka_Addon_Group[]
	 *
	 * @var array<string, Lafka_Addon_Group[]>
	 */
	private static array $group_cache = array();

	/**
	 * Per-request cache for global all-products posts query.
	 *
	 * @var WP_Post[]|null
	 */
	private static ?array $global_all_products_cache = null;

	/**
	 * Per-request cache for category-scoped global posts.
	 *
	 * @var array<string, WP_Post[]>
	 */
	private static array $category_addons_cache = array();

	private Lafka_Addon_Repository $repository;

	public function __construct( ?Lafka_Addon_Repository $repository = null ) {
		$this->repository = $repository ?? Lafka_Addons_Engine::instance()->repository();
	}

	/**
	 * Compose the merged group list for a product.
	 *
	 * @return Lafka_Addon_Group[]
	 */
	public function resolve_for_product( int $product_id, bool $inc_parent = true, bool $inc_global = true ): array {
		if ( $product_id <= 0 ) {
			return array();
		}

		$extra_key = (string) apply_filters( 'lafka_addons_resolver_cache_key_extra', '', $product_id );
		$cache_key = $product_id . '|' . (int) $inc_parent . '|' . (int) $inc_global . '|' . $extra_key;
		if ( isset( self::$group_cache[ $cache_key ] ) ) {
			return self::$group_cache[ $cache_key ];
		}

		$product = function_exists( 'wc_get_product' ) ? wc_get_product( $product_id ) : null;
		if ( ! $product ) {
			self::$group_cache[ $cache_key ] = array();
			return array();
		}

		$buckets = array();

		// Parent product.
		$parent_id = wp_get_post_parent_id( $product_id );
		if ( $inc_parent && $parent_id > 0 ) {
			foreach ( $this->repository->get_groups( $parent_id ) as $group ) {
				$buckets[10][] = $group;
			}
		}

		// Product's own groups.
		foreach ( $this->repository->get_groups( $product_id ) as $group ) {
			$buckets[10][] = $group;
		}

		// Global addons.
		$exclude_global = '1' === (string) $product->get_meta( '_product_addons_exclude_global' );
		if ( $inc_global && ! $exclude_global ) {
			foreach ( $this->get_all_products_global_posts() as $post ) {
				$priority = (int) get_post_meta( $post->ID, '_priority', true );
				foreach ( $this->repository->get_groups( (int) $post->ID ) as $group ) {
					$buckets[ $priority ][] = $group;
				}
			}

			$product_terms = $this->get_product_term_ids( $product_id );
			foreach ( $this->get_category_global_posts( $product_terms ) as $post ) {
				$priority = (int) get_post_meta( $post->ID, '_priority', true );
				foreach ( $this->repository->get_groups( (int) $post->ID ) as $group ) {
					$buckets[ $priority ][] = $group;
				}
			}
		}

		ksort( $buckets );

		$flat = array();
		foreach ( $buckets as $priority_bucket ) {
			foreach ( $priority_bucket as $group ) {
				$flat[] = $group;
			}
		}

		$flat = $this->filter_attribute_groups_for_product( $flat, $product );
		$flat = (array) apply_filters( 'lafka_addons_resolved_groups', $flat, $product_id );

		self::$group_cache[ $cache_key ] = $flat;
		return $flat;
	}

	/**
	 * Drop attribute-variant groups whose source attribute isn't on the product.
	 * Mirrors the legacy filter applied in WC_Product_Addons_Helper.
	 *
	 * @param Lafka_Addon_Group[] $groups
	 * @return Lafka_Addon_Group[]
	 */
	private function filter_attribute_groups_for_product( array $groups, $product ): array {
		$attributes = $product->get_attributes();
		$out        = array();
		foreach ( $groups as $group ) {
			if ( $group->variations && $group->attribute > 0 ) {
				$attr_obj = function_exists( 'wc_get_attribute' ) ? wc_get_attribute( $group->attribute ) : null;
				if ( $attr_obj && ! isset( $attributes[ $attr_obj->slug ] ) ) {
					continue;
				}
			}
			$out[] = $group;
		}
		return $out;
	}

	/**
	 * @return WP_Post[]
	 */
	private function get_all_products_global_posts(): array {
		if ( null !== self::$global_all_products_cache ) {
			return self::$global_all_products_cache;
		}
		self::$global_all_products_cache = (array) get_posts(
			array(
				'posts_per_page' => -1,
				'orderby'        => 'meta_value',
				'order'          => 'ASC',
				'meta_key'       => '_priority',
				'post_type'      => 'lafka_glb_addon',
				'post_status'    => 'publish',
				'meta_query'     => array(
					array(
						'key'   => '_all_products',
						'value' => '1',
					),
				),
			)
		);
		return self::$global_all_products_cache;
	}

	/**
	 * @param int[] $term_ids
	 * @return WP_Post[]
	 */
	private function get_category_global_posts( array $term_ids ): array {
		if ( empty( $term_ids ) ) {
			return array();
		}
		$cache_key = implode( ',', $term_ids );
		if ( isset( self::$category_addons_cache[ $cache_key ] ) ) {
			return self::$category_addons_cache[ $cache_key ];
		}
		$args = (array) apply_filters(
			'get_product_addons_global_query_args',
			array(
				'posts_per_page' => -1,
				'orderby'        => 'meta_value',
				'order'          => 'ASC',
				'meta_key'       => '_priority',
				'post_type'      => 'lafka_glb_addon',
				'post_status'    => 'publish',
				'tax_query'      => array(
					array(
						'taxonomy'         => 'product_cat',
						'field'            => 'id',
						'terms'            => $term_ids,
						'include_children' => false,
					),
				),
			),
			$term_ids
		);
		self::$category_addons_cache[ $cache_key ] = (array) get_posts( $args );
		return self::$category_addons_cache[ $cache_key ];
	}

	/**
	 * @return int[]
	 */
	private function get_product_term_ids( int $product_id ): array {
		if ( ! function_exists( 'wc_get_object_terms' ) ) {
			return array();
		}
		$terms = (array) apply_filters(
			'get_product_addons_product_terms',
			wc_get_object_terms( $product_id, 'product_cat', 'term_id' ),
			$product_id
		);
		return array_map( 'intval', $terms );
	}

	/**
	 * Test affordance + cache invalidation hook target. Resets all per-request
	 * caches. Called from the addon CPT save/trash/delete hooks.
	 */
	public static function clear_cache(): void {
		self::$group_cache               = array();
		self::$global_all_products_cache = null;
		self::$category_addons_cache     = array();
	}
}
