<?php
/**
 * Minimal stub of WooCommerce's OrderUtil for unit tests.
 *
 * Several Lafka accessors (e.g. Lafka_Shipping_Areas::get_order_meta_backward_compatible())
 * branch on OrderUtil::custom_orders_table_usage_is_enabled() to stay HPOS-safe.
 * Under the unit harness WooCommerce is not booted, so we provide the class with a
 * conservative default (legacy CPT storage / HPOS off). Tests that need the HPOS
 * branch can Brain Monkey the surrounding reads instead.
 */

declare(strict_types=1);

namespace Automattic\WooCommerce\Utilities;

if ( ! class_exists( __NAMESPACE__ . '\\OrderUtil' ) ) {
	class OrderUtil {
		public static function custom_orders_table_usage_is_enabled(): bool {
			return false;
		}
	}
}
