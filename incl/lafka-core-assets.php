<?php
/**
 * The shared storefront script (`lafka-core`): window.lafka.{track, cookie,
 * money, debounce, api}. Registered once here; plugin and theme scripts that
 * need it list `lafka-core` as a dependency, so it loads only where a
 * dependent script loads.
 *
 * Its one localized object, `lafkaCore`, carries the REST root and nonce and
 * the WooCommerce currency every script formats prices from.
 *
 * @package Lafka\Plugin
 * @since   10.4.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'lafka_core_currency' ) ) {
	/**
	 * The store currency as the shared money formatter reads it: WooCommerce's
	 * own symbol, price format (position), separators and decimals.
	 *
	 * @return array{symbol:string,format:string,decimals:int,decimalSep:string,thousandSep:string}
	 */
	function lafka_core_currency(): array {
		if ( ! function_exists( 'get_woocommerce_currency_symbol' ) ) {
			return array(
				'symbol'      => '',
				'format'      => '%1$s%2$s',
				'decimals'    => 2,
				'decimalSep'  => '.',
				'thousandSep' => ',',
			);
		}

		return array(
			'symbol'      => html_entity_decode( get_woocommerce_currency_symbol(), ENT_QUOTES, 'UTF-8' ),
			'format'      => html_entity_decode( (string) get_woocommerce_price_format(), ENT_QUOTES, 'UTF-8' ),
			'decimals'    => (int) wc_get_price_decimals(),
			'decimalSep'  => (string) wc_get_price_decimal_separator(),
			'thousandSep' => (string) wc_get_price_thousand_separator(),
		);
	}
}

if ( ! function_exists( 'lafka_core_register_script' ) ) {
	/**
	 * Register `lafka-core` (not enqueued: dependents pull it in). Registered
	 * early so any script, block integrations included, can depend on it.
	 *
	 * @return void
	 */
	function lafka_core_register_script(): void {
		wp_register_script(
			'lafka-core',
			plugins_url( lafka_plugin_script_path( 'assets/js/lafka-core.min.js' ), LAFKA_PLUGIN_FILE ),
			array(),
			LAFKA_PLUGIN_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
	}
	add_action( 'init', 'lafka_core_register_script', 1 );
}

if ( ! function_exists( 'lafka_core_localize_script' ) ) {
	/**
	 * Attach the REST root, nonce and store currency. Runs once the request's
	 * currency is settled (multi-currency plugins switch it after init).
	 *
	 * @return void
	 */
	function lafka_core_localize_script(): void {
		wp_localize_script(
			'lafka-core',
			'lafkaCore',
			array(
				'restRoot' => esc_url_raw( rest_url() ),
				'nonce'    => wp_create_nonce( 'wp_rest' ),
				'currency' => lafka_core_currency(),
			)
		);
	}
	add_action( 'wp_enqueue_scripts', 'lafka_core_localize_script', 1 );
}
