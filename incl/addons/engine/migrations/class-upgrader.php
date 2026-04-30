<?php
/**
 * Discovers and runs registered migrations against `_product_addons` meta.
 *
 * In Phase 1 the upgrader is invoked manually (or in tests). Phase 2 wires
 * it to the plugin activation hook and a "Run migrations" admin action.
 *
 * @package Lafka_Addons_Engine
 * @since   8.13.0
 */

defined( 'ABSPATH' ) || exit;

class Lafka_Addons_Upgrader {

	/** @var Lafka_Migration[] */
	private array $migrations = array();

	public function register( Lafka_Migration $migration ): void {
		$this->migrations[] = $migration;
	}

	/**
	 * @return Lafka_Migration[]
	 */
	public function all(): array {
		$sorted = $this->migrations;
		usort( $sorted, static fn( Lafka_Migration $a, Lafka_Migration $b ) => version_compare( $a->id(), $b->id() ) );
		return $sorted;
	}

	/**
	 * Apply every registered migration to a single meta value (an array of
	 * group dicts). Each migration runs in id-order; later migrations see
	 * the output of earlier ones.
	 */
	public function apply_to_meta( array $meta ): array {
		foreach ( $this->all() as $migration ) {
			$meta = $migration->migrate_meta( $meta );
		}
		return $meta;
	}
}
