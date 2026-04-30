<?php
/**
 * Common scaffolding for options sources.
 *
 * @package Lafka_Addons_Engine
 * @since   8.13.0
 */

defined( 'ABSPATH' ) || exit;

abstract class Lafka_Abstract_Options_Source implements Lafka_Options_Source {

	/**
	 * Index a list of options by their lowercased label for fast lookup
	 * during sync. (We use label rather than id because attribute terms
	 * have no stable id matching the option id — they have a slug.)
	 *
	 * @param Lafka_Addon_Option[] $options
	 * @return array<string, Lafka_Addon_Option>
	 */
	protected function index_by_label( array $options ): array {
		$index = array();
		foreach ( $options as $option ) {
			$index[ strtolower( $option->label ) ] = $option;
		}
		return $index;
	}
}
