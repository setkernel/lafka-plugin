<?php
/**
 * Lafka_Migration — interface every migration class implements.
 *
 * Each migration carries:
 *   - id()                   — unique identifier ('8.13.0')
 *   - target_schema_version()— the version it brings groups to
 *   - migrate_meta($meta)    — pure transform, returns migrated array
 *
 * The Upgrader (next task) discovers all registered migrations, runs them
 * in id-order against every group with a lower schema_version, and stamps
 * the new version when done.
 *
 * @package Lafka_Addons_Engine
 * @since   8.13.0
 */

defined( 'ABSPATH' ) || exit;

interface Lafka_Migration {

	public function id(): string;

	public function target_schema_version(): int;

	/**
	 * Pure transform — takes the meta value (an array of group dicts) and
	 * returns a migrated array. Called per addon-CPT post or per product.
	 *
	 * @param array $meta The raw `_product_addons` meta value.
	 * @return array
	 */
	public function migrate_meta( array $meta ): array;
}
