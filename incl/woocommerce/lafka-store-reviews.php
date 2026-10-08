<?php
/**
 * Real customer reviews for the storefront: what customers actually wrote as
 * WooCommerce product reviews, and the store-wide average of their star
 * ratings. Nothing is typed in by hand or invented: with no approved reviews
 * the helpers return nothing, and a template shows no reviews section.
 *
 * @package Lafka\Plugin\WooCommerce
 * @since   10.4.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'lafka_store_reviews_collect' ) ) {
	/**
	 * Every approved product review that carries a star rating, newest first,
	 * as plain rows. Cached; the cache is dropped whenever a review changes.
	 *
	 * @since 10.4.0
	 * @return array<int,array{id:int,product_id:int,author:string,text:string,stars:int,time:int}>
	 */
	function lafka_store_reviews_collect(): array {
		$cached = get_transient( 'lafka_store_reviews' );
		if ( is_array( $cached ) ) {
			return $cached;
		}
		$rows = array();
		if ( function_exists( 'wc_review_ratings_enabled' ) && wc_review_ratings_enabled() ) {
			$ids = get_comments(
				array(
					'type'     => 'review',
					'status'   => 'approve',
					'number'   => 500,
					'orderby'  => 'comment_date_gmt',
					'order'    => 'DESC',
					'fields'   => 'ids',
					'meta_key' => 'rating',
				)
			);
			update_meta_cache( 'comment', $ids );
			foreach ( $ids as $id ) {
				$comment = get_comment( (int) $id );
				$stars   = (int) get_comment_meta( (int) $id, 'rating', true );
				if ( ! $comment || $stars < 1 || $stars > 5 ) {
					continue;
				}
				$rows[] = array(
					'id'         => (int) $id,
					'product_id' => (int) $comment->comment_post_ID,
					'author'     => (string) $comment->comment_author,
					'text'       => trim( wp_strip_all_tags( (string) $comment->comment_content ) ),
					'stars'      => $stars,
					'time'       => (int) strtotime( (string) $comment->comment_date_gmt . ' UTC' ),
				);
			}
		}
		set_transient( 'lafka_store_reviews', $rows, 6 * HOUR_IN_SECONDS );
		return $rows;
	}
}

if ( ! function_exists( 'lafka_get_store_review_summary' ) ) {
	/**
	 * Average star rating and number of rated reviews across the store.
	 *
	 * @since 10.4.0
	 * @return array{avg:float,count:int} Zeros when there are no reviews.
	 */
	function lafka_get_store_review_summary(): array {
		$rows  = lafka_store_reviews_collect();
		$count = count( $rows );
		if ( 0 === $count ) {
			return array(
				'avg'   => 0.0,
				'count' => 0,
			);
		}
		return array(
			'avg'   => round( array_sum( array_column( $rows, 'stars' ) ) / $count, 1 ),
			'count' => $count,
		);
	}
}

if ( ! function_exists( 'lafka_get_store_reviews' ) ) {
	/**
	 * The newest real reviews that have text and at least $min_stars stars,
	 * one per product, ready to print as quotes.
	 *
	 * @since 10.4.0
	 * @param int $limit     How many to return.
	 * @param int $min_stars Lowest rating shown (default 4: the quotes on a
	 *                       "what neighbours say" band are the happy ones).
	 * @return array<int,array{quote:string,author:string,date:string,stars:int,product_id:int}>
	 */
	function lafka_get_store_reviews( int $limit = 3, int $min_stars = 4 ): array {
		$out  = array();
		$seen = array();
		foreach ( lafka_store_reviews_collect() as $row ) {
			if ( $row['stars'] < $min_stars || '' === $row['text'] || isset( $seen[ $row['product_id'] ] ) ) {
				continue;
			}
			$seen[ $row['product_id'] ] = true;
			$out[]                      = array(
				'quote'      => wp_trim_words( $row['text'], 32 ),
				'author'     => $row['author'],
				'date'       => human_time_diff( $row['time'] ) . ' ' . __( 'ago', 'lafka-plugin' ),
				'stars'      => $row['stars'],
				'product_id' => $row['product_id'],
			);
			if ( count( $out ) >= $limit ) {
				break;
			}
		}
		return $out;
	}
}

if ( ! function_exists( 'lafka_store_reviews_flush' ) ) {
	/**
	 * Drop the cached review list when any review is posted, edited, moderated
	 * or deleted.
	 *
	 * @since 10.4.0
	 * @return void
	 */
	function lafka_store_reviews_flush(): void {
		delete_transient( 'lafka_store_reviews' );
	}
	foreach ( array( 'comment_post', 'edit_comment', 'wp_set_comment_status', 'deleted_comment', 'trashed_comment', 'untrashed_comment', 'spammed_comment' ) as $lafka_review_hook ) {
		add_action( $lafka_review_hook, 'lafka_store_reviews_flush' );
	}
	unset( $lafka_review_hook );
}
