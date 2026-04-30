<?php
/**
 * Reads and writes `_product_addons` post meta as Lafka_Addon_Group objects.
 *
 * On read: pulls raw meta, runs registered migrations to bring legacy data
 * to the current schema, hydrates Addon_Group value objects.
 *
 * On write: serializes back to canonical array shape, calls update_post_meta.
 *
 * Phase 1: standalone — used by tests + the new engine. Phase 2 wires the
 * existing admin save handler to use this. Phase 3 wires the cart layer.
 *
 * @package Lafka_Addons_Engine
 * @since   8.13.0
 */

defined( 'ABSPATH' ) || exit;

class Lafka_Addon_Repository {

	private Lafka_Addons_Upgrader $upgrader;

	public function __construct( Lafka_Addons_Upgrader $upgrader ) {
		$this->upgrader = $upgrader;
	}

	/**
	 * @return Lafka_Addon_Group[]
	 */
	public function get_groups( int $post_id ): array {
		if ( ! function_exists( 'get_post_meta' ) ) {
			return array();
		}
		$raw = get_post_meta( $post_id, '_product_addons', true );
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$migrated = $this->upgrader->apply_to_meta( $raw );
		$groups   = array();
		foreach ( $migrated as $group_data ) {
			if ( ! is_array( $group_data ) ) {
				continue;
			}
			$groups[] = Lafka_Addon_Group::from_array( $group_data );
		}
		return $groups;
	}

	/**
	 * @param Lafka_Addon_Group[] $groups
	 */
	public function save_groups( int $post_id, array $groups ): bool {
		if ( ! function_exists( 'update_post_meta' ) ) {
			return false;
		}
		$serialized = array();
		foreach ( $groups as $group ) {
			if ( ! $group instanceof Lafka_Addon_Group ) {
				continue;
			}
			$serialized[] = $group->to_array();
		}
		return (bool) update_post_meta( $post_id, '_product_addons', $serialized );
	}
}
