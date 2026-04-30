<?php
/**
 * WP Importer ↔ WC attributes bridge.
 *
 * The wordpressdotorg WordPress Importer plugin imports WXR files but doesn't
 * know about WooCommerce — when a WXR contains products with `pa_*`
 * (product-attribute) taxonomy terms whose taxonomy doesn't yet exist on the
 * target site, the importer skips the term assignment silently. The product
 * is created without its attribute values.
 *
 * This bridge hooks `wp_import_posts` (fires before the importer's per-post
 * processing loop, with the full posts array as the filter value) and walks
 * the posts looking for `pa_*` terms whose taxonomy is missing. For each
 * missing taxonomy it calls `wc_create_attribute()` (creates the WC attribute
 * row, which makes the taxonomy available) and then `register_taxonomy()`
 * inline so the import flow can immediately assign terms in the same request.
 *
 * Replaces the v0.7 LafkaImport fork's `lafka_proccess_woocommerce_taxonomy()`
 * method, deleted in v9.7.17 along with the rest of the dead fork. Net change:
 * ~2,260 lines (fork) → ~50 lines (bridge), and the operator gets all of
 * upstream's accumulated security/bug fixes for free.
 *
 * Loads only when both classes the bridge needs are present:
 *   - `WP_Import` (upstream wordpressdotorg/wordpress-importer plugin active)
 *   - `wc_create_attribute` (WooCommerce active)
 *
 * @package Lafka\Plugin\Compat
 * @since   9.7.18
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'lafka_compat_wp_importer_create_missing_wc_attrs' ) ) {

	/**
	 * Walk imported posts and pre-create any missing WC product-attribute taxonomies.
	 *
	 * @param array $posts Array of post arrays as parsed from the WXR file.
	 * @return array Same posts array, returned untouched (filter passthrough).
	 */
	function lafka_compat_wp_importer_create_missing_wc_attrs( $posts ) {
		if ( ! is_array( $posts ) || empty( $posts ) ) {
			return $posts;
		}
		if ( ! function_exists( 'wc_create_attribute' ) || ! function_exists( 'wc_sanitize_taxonomy_name' ) ) {
			return $posts;
		}

		foreach ( $posts as $post ) {
			if ( ! is_array( $post ) || 'product' !== ( $post['post_type'] ?? '' ) ) {
				continue;
			}
			if ( empty( $post['terms'] ) || ! is_array( $post['terms'] ) ) {
				continue;
			}

			foreach ( $post['terms'] as $term ) {
				$taxonomy = is_array( $term ) ? (string) ( $term['domain'] ?? '' ) : '';
				if ( '' === $taxonomy || 0 !== strpos( $taxonomy, 'pa_' ) ) {
					continue;
				}
				if ( taxonomy_exists( $taxonomy ) ) {
					continue;
				}

				$attribute_name = wc_sanitize_taxonomy_name( substr( $taxonomy, 3 ) );
				wc_create_attribute(
					array(
						'name'         => $attribute_name,
						'slug'         => $attribute_name,
						'type'         => 'select',
						'order_by'     => 'menu_order',
						'has_archives' => false,
					)
				);

				// Register inline so the importer's term assignment in this same
				// request sees the taxonomy. WC's normal flow registers `pa_*`
				// taxonomies on init, which already fired by the time the
				// importer dispatches.
				register_taxonomy(
					$taxonomy,
					apply_filters( 'woocommerce_taxonomy_objects_' . $taxonomy, array( 'product' ) ),
					apply_filters(
						'woocommerce_taxonomy_args_' . $taxonomy,
						array(
							'hierarchical' => true,
							'show_ui'      => false,
							'query_var'    => true,
							'rewrite'      => false,
						)
					)
				);
			}
		}

		return $posts;
	}

	add_filter( 'wp_import_posts', 'lafka_compat_wp_importer_create_missing_wc_attrs' );
}
