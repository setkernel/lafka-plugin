<?php
/**
 * Microsoft Clarity custom tags — enqueues lafka-clarity-tags.js.
 *
 * Mirrors dataLayer funnel signals into Clarity custom tags + identify so
 * session replays/heatmaps are segmentable. Enqueued when Clarity is configured
 * directly; for Clarity loaded via GTM, enable with:
 *   add_filter( 'lafka_enable_clarity_tags', '__return_true' );
 * The JS is a safe no-op if the clarity() global never appears.
 *
 * @package Lafka\Plugin\Analytics
 * @since   9.31.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'lafka_analytics_enqueue_clarity_tags' ) ) {
	add_action( 'wp_enqueue_scripts', 'lafka_analytics_enqueue_clarity_tags', 21 );

	/**
	 * Enqueue the Clarity custom-tags client when Clarity is in play.
	 *
	 * @return void
	 */
	function lafka_analytics_enqueue_clarity_tags(): void {
		if ( is_admin() ) {
			return;
		}
		$clarity_direct = function_exists( 'lafka_analytics_clarity_id' ) && '' !== lafka_analytics_clarity_id();
		if ( ! $clarity_direct && ! apply_filters( 'lafka_enable_clarity_tags', false ) ) {
			return;
		}
		$rel     = 'assets/js/lafka-clarity-tags.js';
		$version = function_exists( 'lafka_plugin_asset_version' ) ? lafka_plugin_asset_version( $rel ) : '9.31.0';
		wp_enqueue_script(
			'lafka-clarity-tags',
			plugins_url( $rel, LAFKA_PLUGIN_FILE ),
			array(),
			$version,
			true
		);
	}
}
