<?php
/**
 * Registers the Lafka widgets and keeps the popular-posts widget cache fresh.
 *
 * The widget classes live in their own class-*.php files and are required by
 * lafka-plugin.php before widgets_init fires.
 *
 * @package Lafka_Plugin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Register the Lafka widgets.
 *
 * The WooCommerce-dependent product filter is loaded on widgets_init priority 5
 * (it extends WC_Widget), so it is registered only when its class exists.
 *
 * @return void
 */
function lafka_register_widgets() {
	register_widget( 'Lafka_About_Widget' );
	register_widget( 'Lafka_Contacts_Widget' );
	register_widget( 'Lafka_Payment_Options_Widget' );
	register_widget( 'Lafka_Popular_Posts_Widget' );
	register_widget( 'Lafka_Latest_Menu_Entries_Widget' );
	if ( class_exists( 'Lafka_Product_Filter_Widget' ) ) {
		register_widget( 'Lafka_Product_Filter_Widget' );
	}
}
add_action( 'widgets_init', 'lafka_register_widgets' );

/**
 * Bust the popular-posts widget cache whenever post comment counts can change.
 * The widget caches a list of post IDs ordered by `comment_count`, which
 * shifts on `save_post`, `deleted_post`, `wp_set_comment_status`, and
 * `comment_post`. We don't have a per-instance cache key, so flush the whole
 * `widget` cache group prefix here: the impact is one extra cache miss for
 * any other widget consuming that group, which is negligible.
 *
 * @return void
 */
function lafka_popular_posts_widget_flush() {
	// Bump a single integer "version" so cached entries become unreachable.
	$ver = (int) wp_cache_get( 'lafka_popular_widget_ver', 'widget' );
	wp_cache_set( 'lafka_popular_widget_ver', $ver + 1, 'widget' );
}
add_action( 'save_post', 'lafka_popular_posts_widget_flush' );
add_action( 'deleted_post', 'lafka_popular_posts_widget_flush' );
add_action( 'wp_set_comment_status', 'lafka_popular_posts_widget_flush' );
add_action( 'comment_post', 'lafka_popular_posts_widget_flush' );
