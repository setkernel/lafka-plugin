<?php
/**
 * Manual options source — operator typed each option label and price by hand.
 * The default source for every group; matches the legacy Lafka behavior.
 *
 * @package Lafka_Addons_Engine
 * @since   8.13.0
 */

defined( 'ABSPATH' ) || exit;

class Lafka_Manual_Source extends Lafka_Abstract_Options_Source {

	public function id(): string {
		return Lafka_Addon_Schema::SOURCE_MANUAL;
	}

	public function label(): string {
		return __( 'Manual (type each option)', 'lafka-plugin' );
	}

	public function get_options( Lafka_Addon_Group $group ): array {
		return $group->options;
	}

	public function sync( Lafka_Addon_Group $group ): Lafka_Addon_Group {
		// Manual options have no upstream to sync from.
		return $group;
	}
}
