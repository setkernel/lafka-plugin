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
		// Two-tier cache: object cache (μs hot path) over DB transient (warm path).
		// On Redis/Memcached-equipped sites this avoids the transient SELECT entirely
		// after the first request per cache cycle.
		$cached = wp_cache_get( 'lafka_pdp_bestsellers', 'lafka' );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}
		$cached = get_transient( 'lafka_pdp_bestsellers' );
		if ( false !== $cached && is_array( $cached ) ) {
			wp_cache_set( 'lafka_pdp_bestsellers', $cached, 'lafka', HOUR_IN_SECONDS );
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
		//
		// Query structure (modern, index-friendly):
		//   1. Drive from the indexed orders table (date_created_gmt + status filter).
		//   2. INNER JOIN order_items + order_itemmeta — both have PRIMARY/MUL indices.
		//   3. Group by meta_value directly (varchar product id) — skip CAST+posts join.
		//   4. Post-filter dead/non-product IDs in PHP via wc_get_product() cache.
		$is_hpos = class_exists( '\Automattic\WooCommerce\Utilities\OrderUtil' )
			&& \Automattic\WooCommerce\Utilities\OrderUtil::custom_orders_table_usage_is_enabled();

		if ( $is_hpos ) {
			$sql = "SELECT oim.meta_value AS product_id
				   FROM {$wpdb->prefix}wc_orders o
			 INNER JOIN {$wpdb->prefix}woocommerce_order_items oi
				     ON oi.order_id = o.id
			 INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim
				     ON oim.order_item_id = oi.order_item_id
				    AND oim.meta_key = '_product_id'
				  WHERE o.date_created_gmt > DATE_SUB(NOW(), INTERVAL 90 DAY)
				    AND o.status IN ('wc-completed','wc-processing')
				  GROUP BY oim.meta_value
				  ORDER BY COUNT(*) DESC
				  LIMIT 25";
		} else {
			// Legacy CPT order storage — orders live in wp_posts as `shop_order` rows.
			$sql = "SELECT oim.meta_value AS product_id
				   FROM {$wpdb->posts} o
			 INNER JOIN {$wpdb->prefix}woocommerce_order_items oi
				     ON oi.order_id = o.ID
			 INNER JOIN {$wpdb->prefix}woocommerce_order_itemmeta oim
				     ON oim.order_item_id = oi.order_item_id
				    AND oim.meta_key = '_product_id'
				  WHERE o.post_type = 'shop_order'
				    AND o.post_date_gmt > DATE_SUB(NOW(), INTERVAL 90 DAY)
				    AND o.post_status IN ('wc-completed','wc-processing')
				  GROUP BY oim.meta_value
				  ORDER BY COUNT(*) DESC
				  LIMIT 25";
		}

		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- no user input; all values are hardcoded SQL literals / table names.
		$rows = $wpdb->get_col( $sql );

		// Filter to live products only. wc_get_product() uses the WC object cache,
		// so this loop costs ~0 extra DB queries when products are already cached.
		$ids = array();
		foreach ( (array) $rows as $raw ) {
			$id = (int) $raw;
			if ( $id > 0 && wc_get_product( $id ) instanceof WC_Product ) {
				$ids[] = $id;
				if ( count( $ids ) >= 10 ) {
					break;
				}
			}
		}

		set_transient( 'lafka_pdp_bestsellers', $ids, 6 * HOUR_IN_SECONDS );
		wp_cache_set( 'lafka_pdp_bestsellers', $ids, 'lafka', HOUR_IN_SECONDS );
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
		wp_cache_delete( 'lafka_pdp_bestsellers', 'lafka' );
	}
}
