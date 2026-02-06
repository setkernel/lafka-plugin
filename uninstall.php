<?php
/**
 * Uninstall plugin
 */

// If uninstall not called from WordPress exit
if( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;

//change to standard select type custom attributes
$wpdb->query( $wpdb->prepare(
	"UPDATE {$wpdb->prefix}woocommerce_attribute_taxonomies SET attribute_type = %s WHERE attribute_type != %s",
	'select',
	'text'
) );