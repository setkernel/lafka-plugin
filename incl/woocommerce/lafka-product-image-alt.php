<?php
/**
 * Runtime alt-text backfill for WooCommerce product images.
 *
 * The visual QA pass on 2026-05-18 found 104 of 108 product images on /menu/
 * had `alt=""` because the operator never filled in the attachment's alt
 * meta during upload. WC's `WC_Product::get_image()` reads `_wp_attachment_image_alt`
 * verbatim — empty in, empty out.
 *
 * This filter hooks `wp_get_attachment_image_attributes` and, when an attachment
 * with no alt is attached to (or used as the featured image of) a WooCommerce
 * product, substitutes the product's display name. Operators who do fill in
 * descriptive alt text always win — the filter only kicks in when alt is empty.
 *
 * The CLI command `wp lafka image-alts apply` (incl/cli/lafka-image-alt-backfill.php)
 * remains the canonical way to permanently persist alts. This filter is the
 * runtime safety net for unpersisted images.
 *
 * @package Lafka\Plugin\WooCommerce
 * @since   9.22.2
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'lafka_backfill_product_image_alt' ) ) {
	/**
	 * @param array  $attr       Attribute key/value pairs (alt, src, srcset, …).
	 * @param mixed  $attachment WP_Post for the attachment.
	 * @param string $size       Requested image size (unused).
	 * @return array
	 */
	function lafka_backfill_product_image_alt( $attr, $attachment, $size ) {
		// Fast path: alt already present (operator-set or earlier filter).
		if ( ! empty( $attr['alt'] ) ) {
			return $attr;
		}
		if ( ! function_exists( 'wc_get_product' ) ) {
			return $attr;
		}
		if ( ! is_object( $attachment ) || empty( $attachment->ID ) ) {
			return $attr;
		}

		// Branch 1: attachment is the featured image (or gallery image) of a
		// product whose ID happens to be the attachment's `post_parent`. This
		// is the common case for images uploaded directly from the product
		// edit screen.
		$parent_id = (int) ( $attachment->post_parent ?? 0 );
		if ( $parent_id > 0 ) {
			$product = wc_get_product( $parent_id );
			if ( $product instanceof \WC_Product ) {
				$name = (string) $product->get_name();
				if ( '' !== $name ) {
					$attr['alt'] = $name;
					return $attr;
				}
			}
		}

		// Branch 2: attachment is shared (uploaded once, attached to multiple
		// products as gallery / featured image). Resolve the most recent
		// product that lists it via `_thumbnail_id` or `_product_image_gallery`
		// post meta. We only do this lookup when the parent path failed —
		// it's a DB query per image, so cache the result per request.
		static $shared_cache = array();
		$attachment_id = (int) $attachment->ID;
		if ( ! isset( $shared_cache[ $attachment_id ] ) ) {
			$shared_cache[ $attachment_id ] = lafka_resolve_attachment_product_name( $attachment_id );
		}
		if ( '' !== $shared_cache[ $attachment_id ] ) {
			$attr['alt'] = $shared_cache[ $attachment_id ];
		}

		return $attr;
	}
	add_filter( 'wp_get_attachment_image_attributes', 'lafka_backfill_product_image_alt', 20, 3 );
}

if ( ! function_exists( 'lafka_resolve_attachment_product_name' ) ) {
	/**
	 * Find a WC product that uses this attachment as a featured or gallery
	 * image and return its name. Returns '' when no match.
	 *
	 * Bounded to 1 query result — alt text just needs A product name, not
	 * the canonical one.
	 *
	 * @param int $attachment_id
	 * @return string
	 */
	function lafka_resolve_attachment_product_name( $attachment_id ) {
		if ( $attachment_id <= 0 ) {
			return '';
		}
		if ( ! function_exists( 'get_posts' ) || ! function_exists( 'wc_get_product' ) ) {
			return '';
		}
		$candidates = get_posts(
			array(
				'post_type'      => 'product',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array(
					'relation' => 'OR',
					array(
						'key'   => '_thumbnail_id',
						'value' => (string) $attachment_id,
					),
					array(
						'key'     => '_product_image_gallery',
						'value'   => (string) $attachment_id,
						'compare' => 'LIKE',
					),
				),
			)
		);
		if ( empty( $candidates ) ) {
			return '';
		}
		$product = wc_get_product( (int) $candidates[0] );
		if ( ! ( $product instanceof \WC_Product ) ) {
			return '';
		}
		return (string) $product->get_name();
	}
}
