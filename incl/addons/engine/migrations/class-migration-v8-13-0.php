<?php
/**
 * Lafka Addons schema v2 migration — adds pricing_mode, options_source,
 * included_size_slugs, group_flat_price, group_size_prices, schema_version
 * fields to every group.
 *
 * Defaults preserve current behavior:
 *   pricing_mode   = legacy   (existing reads/writes through the legacy code path)
 *   options_source = manual   (operator entered options by hand)
 *
 * Idempotent — re-running over already-v2 data is a no-op.
 *
 * @package Lafka_Addons_Engine
 * @since   8.13.0
 */

defined( 'ABSPATH' ) || exit;

class Lafka_Migration_V8_13_0 implements Lafka_Migration {

	public function id(): string {
		return '8.13.0';
	}

	public function target_schema_version(): int {
		return 2;
	}

	public function migrate_meta( array $meta ): array {
		$migrated = array();
		foreach ( $meta as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			// Use Addon_Group::from_array which fills missing v2 fields from
			// schema defaults, then to_array to get the canonical shape.
			$group      = Lafka_Addon_Group::from_array( $entry );
			$migrated[] = $group->to_array();
		}
		return $migrated;
	}
}
