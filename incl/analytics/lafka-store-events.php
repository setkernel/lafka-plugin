<?php
/**
 * Store-specific event tracking — enqueues lafka-store-events.js.
 *
 * The JS pushes restaurant-specific funnel signals (order_channel_click,
 * select_fulfilment, select_addon, store_closed_view) into window.dataLayer.
 * Loaded only when an analytics destination is configured, mirroring the
 * lafka-dl-client.js gate, so unconfigured installs pay no request cost.
 *
 * @package Lafka\Plugin\Analytics
 * @since   9.31.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'lafka_analytics_enqueue_store_events' ) ) {
	add_action( 'wp_enqueue_scripts', 'lafka_analytics_enqueue_store_events', 20 );

	/**
	 * Enqueue the store-events client JS when analytics is active.
	 *
	 * @return void
	 */
	function lafka_analytics_enqueue_store_events(): void {
		if ( is_admin() ) {
			return;
		}
		if ( ! function_exists( 'lafka_analytics_is_active' ) || ! lafka_analytics_is_active() ) {
			return;
		}
		$rel     = 'assets/js/lafka-store-events.js';
		$version = function_exists( 'lafka_plugin_asset_version' ) ? lafka_plugin_asset_version( $rel ) : '9.31.0';
		wp_enqueue_script(
			'lafka-store-events',
			plugins_url( $rel, LAFKA_PLUGIN_FILE ),
			array(),
			$version,
			true
		);
	}
}
