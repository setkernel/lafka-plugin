<?php
/**
 * Lafka_Options_Source — contract for option providers.
 *
 * Two implementations in Phase 1: manual (operator typed each option) and
 * attribute (options sourced from a WC attribute taxonomy's terms). Each
 * source can also `sync` — refresh against current source state preserving
 * any per-option settings (price, included flag) that already exist.
 *
 * @package Lafka_Addons_Engine
 * @since   8.13.0
 */

defined( 'ABSPATH' ) || exit;

interface Lafka_Options_Source {

	/**
	 * Source id matching Lafka_Addon_Schema::SOURCE_* constants.
	 */
	public function id(): string;

	public function label(): string;

	/**
	 * Return the canonical option list for this source given the group.
	 *
	 * @return Lafka_Addon_Option[]
	 */
	public function get_options( Lafka_Addon_Group $group ): array;

	/**
	 * Refresh the group's options against the current source state. For the
	 * manual source this is a no-op. For the attribute source this fetches
	 * current taxonomy terms, preserves any matching existing options
	 * (by stable id or label), and adds any new terms as fresh options.
	 */
	public function sync( Lafka_Addon_Group $group ): Lafka_Addon_Group;
}
