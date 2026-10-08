<?php
/**
 * Fulfilment helper functions.
 *
 * @package Lafka\Plugin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'lafka_fulfilment_modes' ) ) {
	/**
	 * The fulfilment modes the store offers ('pickup', 'delivery').
	 *
	 * @return string[]
	 */
	function lafka_fulfilment_modes(): array {
		return Lafka_Fulfilment::modes();
	}
}

if ( ! function_exists( 'lafka_fulfilment_preference' ) ) {
	/**
	 * The customer's fulfilment preference: 'pickup', 'delivery' or ''.
	 *
	 * @return string
	 */
	function lafka_fulfilment_preference(): string {
		return Lafka_Fulfilment::preference();
	}
}
