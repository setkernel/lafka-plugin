<?php
/**
 * P6-PERF-4 (W3-T2, 2026-04-28): Asset pruning — dequeue heavy third-party
 * scripts/styles on pages that don't need them.
 *
 * Currently handles Revolution Slider (~150 KB CSS+JS). Revslider's plugin
 * unconditionally enqueues sr7.css + sr7.js + tptools.js on every page even
 * when no slider is present. This module hooks late into wp_enqueue_scripts
 * and dequeues those assets when the current page has no slider attached.
 *
 * Detection strategy (two-pass):
 *   1. Post meta `lafka_rev_slider` — set by Lafka's meta box when an editor
 *      attaches a slider. Empty or "none" means no slider.
 *   2. Shortcode scan — fallback for pages that embed sliders via [rev_slider]
 *      shortcode directly in the content body.
 *
 * @package LafkaPlugin
 * @since   8.9.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * P6-PERF-4: dequeue Revolution Slider on pages that don't use it.
 *
 * Revslider unconditionally enqueues sr7.css + sr7.js + tptools.js on every
 * page (~150 KB). Most pages have no slider.
 *
 * The check: see if the current queried object has a Revslider attached.
 * Lafka stores this in post meta `lafka_rev_slider`. If it's empty or 'none',
 * we don't need Revslider's assets.
 */
add_action( 'wp_enqueue_scripts', 'lafka_perf_dequeue_unused_revslider', 99 );
function lafka_perf_dequeue_unused_revslider() {
	if ( is_admin() ) {
		return;
	}
	$qid    = get_queried_object_id();
	$slider = $qid ? get_post_meta( $qid, 'lafka_rev_slider', true ) : '';
	$has_slider = $slider && 'none' !== $slider;

	// Also keep Revslider available if a [rev_slider] shortcode is in the content.
	if ( ! $has_slider && is_singular() ) {
		$post = get_post( $qid );
		if ( $post && false !== strpos( (string) $post->post_content, '[rev_slider' ) ) {
			$has_slider = true;
		}
	}

	if ( ! $has_slider ) {
		// Revslider's standard handles — names may vary across versions; dequeue
		// the family. Use wp_styles()->registered to find by URL pattern as a
		// safety net.
		$handles = array( 'rs-plugin-settings', 'rs-pro-settings', 'revmin', 'revbuilder', 'revslider-public', 'sr7' );
		foreach ( $handles as $h ) {
			wp_dequeue_style( $h );
			wp_deregister_style( $h );
			wp_dequeue_script( $h );
			wp_deregister_script( $h );
		}
		// URL-based fallback for unknown handle names
		if ( wp_styles() ) {
			foreach ( wp_styles()->registered as $handle => $obj ) {
				if ( false !== strpos( (string) $obj->src, '/revslider/' ) ) {
					wp_dequeue_style( $handle );
					wp_deregister_style( $handle );
				}
			}
		}
		if ( wp_scripts() ) {
			foreach ( wp_scripts()->registered as $handle => $obj ) {
				if ( false !== strpos( (string) $obj->src, '/revslider/' ) ) {
					wp_dequeue_script( $handle );
					wp_deregister_script( $handle );
				}
			}
		}
	}
}

/**
 * P6-PERF-7 (W3-T7): dequeue jquery-migrate on the front-end since lafka
 * first-party code is now Migrate-clean. Filterable for compat with stragglers.
 *
 * Uses wp_default_scripts (not wp_dequeue_script) because migrate is registered
 * as a dependency of the 'jquery' bundle, not as a standalone enqueue.
 *
 * Operator override: add_filter( 'lafka_keep_jquery_migrate', '__return_true' );
 */
if ( ! function_exists( 'lafka_perf_dequeue_jquery_migrate' ) ) {
	add_action( 'wp_default_scripts', 'lafka_perf_dequeue_jquery_migrate' );
	function lafka_perf_dequeue_jquery_migrate( $scripts ) {
		if ( is_admin() ) {
			return; // admin still uses migrate-y stuff in some core/plugin pages
		}
		if ( apply_filters( 'lafka_keep_jquery_migrate', false ) ) {
			return;
		}
		if ( isset( $scripts->registered['jquery'] ) ) {
			$deps = $scripts->registered['jquery']->deps;
			if ( is_array( $deps ) ) {
				$scripts->registered['jquery']->deps = array_diff( $deps, array( 'jquery-migrate' ) );
			}
		}
	}
}
