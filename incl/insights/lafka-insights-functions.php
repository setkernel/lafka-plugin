<?php
/**
 * Insights helper functions.
 *
 * @package Lafka\Plugin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'lafka_insights_is_collecting' ) ) {
	/**
	 * Procedural gate for the analytics destination check.
	 *
	 * @return bool
	 */
	function lafka_insights_is_collecting(): bool {
		return Lafka_Insights::is_active() && Lafka_Insights::is_collecting();
	}
}

if ( ! function_exists( 'lafka_insights_needs_consent_banner' ) ) {
	/**
	 * True when Insights itself requires the consent banner (consent_required mode).
	 *
	 * @return bool
	 */
	function lafka_insights_needs_consent_banner(): bool {
		return lafka_insights_is_collecting() && Lafka_Insights::MODE_CONSENT === Lafka_Insights::consent_mode();
	}
}
