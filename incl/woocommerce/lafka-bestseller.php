<?php
/**
 * Best-seller eyebrow data + render.
 *
 * 90-day order data → top product IDs, cached as a transient for 6 hours.
 * The eyebrow uses the top 3 (where rank gets displayed); the upsell
 * fallback consumes the wider list. Render function checks the eyebrow
 * Customizer toggle.
 *
 * @package Lafka\Plugin\WooCommerce
 * @since   8.12.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'lafka_pdp_get_bestseller_ids' ) ) {
	function lafka_pdp_get_bestseller_ids(): array {
		$cached = get_transient( 'lafka_pdp_bestsellers' );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}

		global $wpdb;
		if ( ! $wpdb ) {
			return array();
		}

		// HPOS-aware: pre-v9.7.7 this hardcoded {$wpdb->prefix}wc_orders, so
		// any site that hadn't migrated to High-Performance Order Storage got
		// an empty bestseller list silently. Now we route the join based on
		// what's active. The status + date filters mirror the legacy WC
		// post-statuses + the new wc_orders.status enum, both of which use
		// the same `wc-completed` / `wc-processing` shape.
		$is_hpos = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

		if ( $is_hpos ) {
			$sql = "SELECT p.ID
				   FROM {$wpdb->prefix}woocommerce_order_items oi
			  LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim
				     ON oi.order_item_id = oim.order_item_id
				    AND oim.meta_key = '_product_id'
			  LEFT JOIN {$wpdb->posts} p
				     ON p.ID = CAST(oim.meta_value AS UNSIGNED)
				    AND p.post_type = 'product'
			  LEFT JOIN {$wpdb->prefix}wc_orders o
				     ON o.id = oi.order_id
				  WHERE o.date_created_gmt > DATE_SUB(NOW(), INTERVAL 90 DAY)
				    AND o.status IN ('wc-completed','wc-processing')
				    AND p.ID IS NOT NULL
				  GROUP BY p.ID
				  ORDER BY COUNT(*) DESC
				  LIMIT 10";
		} else {
			// Legacy CPT order storage — orders live in wp_posts as `shop_order` rows.
			$sql = "SELECT p.ID
				   FROM {$wpdb->prefix}woocommerce_order_items oi
			  LEFT JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim
				     ON oi.order_item_id = oim.order_item_id
				    AND oim.meta_key = '_product_id'
			  LEFT JOIN {$wpdb->posts} p
				     ON p.ID = CAST(oim.meta_value AS UNSIGNED)
				    AND p.post_type = 'product'
			  LEFT JOIN {$wpdb->posts} o
				     ON o.ID = oi.order_id
				    AND o.post_type = 'shop_order'
				  WHERE o.post_date_gmt > DATE_SUB(NOW(), INTERVAL 90 DAY)
				    AND o.post_status IN ('wc-completed','wc-processing')
				    AND p.ID IS NOT NULL
				  GROUP BY p.ID
				  ORDER BY COUNT(*) DESC
				  LIMIT 10";
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_col( $sql );

		$ids = array_map( 'intval', (array) $rows );
		set_transient( 'lafka_pdp_bestsellers', $ids, 6 * HOUR_IN_SECONDS );
		return $ids;
	}
}

if ( ! function_exists( 'lafka_pdp_render_bestseller_eyebrow' ) ) {
	function lafka_pdp_render_bestseller_eyebrow( int $product_id ): void {
		if ( 'no' === get_theme_mod( 'lafka_pdp_show_bestseller_eyebrow', 'yes' ) ) {
			return;
		}
		// Eyebrow only renders for top-3 (the wider list serves the upsell fallback).
		$ids  = array_slice( lafka_pdp_get_bestseller_ids(), 0, 3 );
		$rank = array_search( $product_id, $ids, true );
		if ( false === $rank ) {
			return;
		}
		$rank = (int) $rank + 1;
		printf(
			'<span class="lafka-pdp-eyebrow lafka-pdp-eyebrow--bestseller">%s</span>',
			esc_html( '★ #' . $rank . ' BEST SELLER' )
		);
	}
}

add_action( 'woocommerce_order_status_completed', 'lafka_pdp_flush_bestseller_cache' );
add_action( 'woocommerce_order_status_processing', 'lafka_pdp_flush_bestseller_cache' );
if ( ! function_exists( 'lafka_pdp_flush_bestseller_cache' ) ) {
	function lafka_pdp_flush_bestseller_cache(): void {
		delete_transient( 'lafka_pdp_bestsellers' );
	}
}
