<?php
/**
 * Uninstall plugin
 */

// If uninstall not called from WordPress exit
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

//change to standard select type custom attributes
$wpdb->query(
	$wpdb->prepare(
		"UPDATE {$wpdb->prefix}woocommerce_attribute_taxonomies SET attribute_type = %s WHERE attribute_type != %s",
		'select',
		'text'
	)
);

// v9.27.0 (Phase 3B): drop the abandoned-cart table on plugin uninstall.
// Deactivation keeps the table so flip-off/on doesn't lose pending rows; only
// a full uninstall removes the schema + option marker.
$ac_table = $wpdb->prefix . 'lafka_abandoned_carts';
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a code-controlled prefix concatenation.
$wpdb->query( "DROP TABLE IF EXISTS {$ac_table}" );
delete_option( 'lafka_abandoned_cart_db_version' );

// v9.29.0 (Phase 3E): drop the push-subscriptions table + activity log option.
// Same retention policy as Phase 3B - deactivation keeps the table, uninstall
// wipes everything.
$push_table = $wpdb->prefix . 'lafka_push_subscriptions';
// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is a code-controlled prefix concatenation.
$wpdb->query( "DROP TABLE IF EXISTS {$push_table}" );
delete_option( 'lafka_push_db_version' );
delete_option( 'lafka_push_activity_log' );
