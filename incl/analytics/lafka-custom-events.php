<?php
/**
 * Phase 1C: Custom dataLayer interaction events.
 *
 * Enqueues the lafka-custom-events.js bundle, which binds eight selector-driven
 * interaction events to window.dataLayer. All routing happens inside GTM — this
 * module never calls gtag() and never branches on consent state at the push
 * level (Consent Mode v2 was wired in Phase 1A; GTM filters at tag-fire time).
 *
 * Events shipped (selector → dataLayer event):
 *   - a[href^="tel:"]                               → phone_click
 *   - a[href^="mailto:"]                            → email_click
 *   - a[href] containing maps / "directions"        → get_directions_click
 *   - details.lafka-contact__faq-item toggle        → faq_open
 *   - .lafka-menu__chip / .lafka-menu__category-chip → filter_apply
 *   - 25/50/75/100% page scroll                     → scroll_milestone (once/page)
 *   - a[href] to a foreign host                     → outbound_link
 *   - .lafka-sticky-cart entering viewport          → sticky_cart_open (once/page)
 *
 * Enqueue gates on at least one configured analytics ID (same pattern as
 * Phase 1B) so unconfigured installs pay zero request cost.
 *
 * @package Lafka\Plugin\Analytics
 * @since   9.25.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'lafka_custom_events_has_analytics_id' ) ) {
	/**
	 * True when at least one of GTM / GA4 / Clarity / Meta Pixel ID is set.
	 *
	 * Mirrors the gating rule established for the consent banner + Phase 1B
	 * client JS so all three enqueue paths share one definition of "analytics
	 * is configured for this site".
	 *
	 * @return bool
	 */
	function lafka_custom_events_has_analytics_id(): bool {
		if ( function_exists( 'lafka_analytics_gtm_id' ) && '' !== lafka_analytics_gtm_id() ) {
			return true;
		}
		if ( function_exists( 'lafka_analytics_ga4_id' ) && '' !== lafka_analytics_ga4_id() ) {
			return true;
		}
		if ( function_exists( 'lafka_analytics_meta_pixel_id' ) && '' !== lafka_analytics_meta_pixel_id() ) {
			return true;
		}
		if ( function_exists( 'lafka_analytics_clarity_id' ) && '' !== lafka_analytics_clarity_id() ) {
			return true;
		}
		return false;
	}
}

if ( ! function_exists( 'lafka_register_custom_events_script' ) ) {
	/**
	 * Register + enqueue the custom-events JS bundle.
	 *
	 * Hooked on wp_enqueue_scripts priority 20 — runs after Phase 1B's
	 * lafka_dl_enqueue_client (also priority 20) so the dependency-free
	 * bundle lands deterministically. Both files are self-contained IIFEs;
	 * load order between them does not matter.
	 *
	 * No-ops when no analytics ID is configured.
	 */
	function lafka_register_custom_events_script(): void {
		if ( ! lafka_custom_events_has_analytics_id() ) {
			return;
		}
		if ( ! defined( 'LAFKA_PLUGIN_FILE' ) ) {
			return;
		}

		$rel     = 'assets/js/lafka-custom-events.js';
		$src     = plugins_url( $rel, LAFKA_PLUGIN_FILE );
		$version = function_exists( 'lafka_plugin_asset_version' ) ? lafka_plugin_asset_version( $rel ) : '9.25.0';

		wp_enqueue_script(
			'lafka-custom-events',
			$src,
			array(),
			$version,
			true
		);
	}
}

if ( function_exists( 'add_action' ) ) {
	add_action( 'wp_enqueue_scripts', 'lafka_register_custom_events_script', 20 );
}
