<?php
/**
 * Address-Field Autocomplete for WooCommerce — compat shim (P6-PERF-10).
 *
 * The plugin (v1.3.1 address-field-autocomplete-for-woocommerce) enqueues a
 * stylesheet handle 'shipping-workshop-block' whose source file
 * `build/style-index.css` was never shipped in the plugin distribution.
 * Every front-end page load therefore produces a 404 that is visible in the
 * browser console and wastes a round-trip.
 *
 * We cannot modify the third-party plugin (changes would revert on update).
 * Instead, this shim hooks `wp_enqueue_scripts` at priority 999 — well after
 * the plugin's own enqueue — and dequeues the handle only when the file is
 * still absent on disk. The moment a future plugin release ships the file,
 * `file_exists()` returns true and the suppression stops automatically.
 *
 * @package Lafka\Plugin\Compat
 * @since   8.7.6
 */

defined( 'ABSPATH' ) || exit;

/**
 * Suppress the broken 'shipping-workshop-block' stylesheet when its source
 * file does not exist on disk.
 *
 * Runs at wp_enqueue_scripts priority 999 so it fires after all normal plugin
 * enqueues (typically priority 10–100).
 *
 * @return void
 */
function lafka_suppress_missing_shipping_workshop_style(): void {
	$plugin_css = WP_PLUGIN_DIR
		. '/address-field-autocomplete-for-woocommerce/build/style-index.css';

	if ( file_exists( $plugin_css ) ) {
		// File now exists — a future plugin update shipped it; nothing to do.
		return;
	}

	wp_dequeue_style( 'shipping-workshop-block' );
	wp_deregister_style( 'shipping-workshop-block' );
}

add_action( 'wp_enqueue_scripts', 'lafka_suppress_missing_shipping_workshop_style', 999 );
