<?php
/**
 * PDP redesign helper function.
 *
 * @package Lafka\Plugin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'lafka_pdp_redesign_enabled' ) ) {
	function lafka_pdp_redesign_enabled(): bool {
		if ( ! function_exists( 'lafka_setting' ) ) {
			return true;
		}
		return 'no' !== lafka_setting( 'lafka_pdp_redesign_enabled', 'yes' );
	}
}
