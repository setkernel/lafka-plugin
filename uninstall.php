<?php
/**
 * Uninstall Lafka Plugin.
 *
 * Thin bootstrap only. WordPress includes this file when the operator deletes
 * the plugin. All cleanup logic lives in Lafka_Uninstall
 * (incl/tools/class-lafka-uninstall.php). Behaviour:
 *
 *   - Toggle OFF (default): minimal cleanup — revert custom product-attribute
 *     types to 'select', DROP the custom tables (declared in Lafka_Schema),
 *     delete their version/marker options, and unschedule every Lafka job.
 *     Everything else is kept.
 *   - Toggle ON ('Remove all data on uninstall', set on Lafka → Modules): full
 *     inventory-driven cleanup on top of the minimal pass — every lafka* option,
 *     the Lafka CPTs' posts, lafka_branch_location terms (term meta cascades),
 *     lafka-prefixed transients, and plugin-owned product/user meta. Orders and order-item meta are intentionally retained.
 */

// If uninstall not called from WordPress exit.
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

require_once plugin_dir_path( __FILE__ ) . 'incl/settings/lafka-settings.php';
require_once plugin_dir_path( __FILE__ ) . 'incl/tools/class-lafka-schema.php';
require_once plugin_dir_path( __FILE__ ) . 'incl/tools/class-lafka-uninstall.php';

Lafka_Uninstall::run();
