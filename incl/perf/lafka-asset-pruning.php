<?php
/**
 * P6-PERF-4 (W3-T2, 2026-04-28): Asset pruning — dequeue heavy assets on pages
 * that don't need them (WP block-library CSS, jQuery Migrate).
 *
 * @package LafkaPlugin
 * @since   8.9.0
 */

defined( 'ABSPATH' ) || exit;

/**
 * Dequeue WP block-library CSS on pages that don't use Gutenberg blocks.
 *
 * `wp-block-library` is ~17 KB of CSS that WP enqueues globally. Most Lafka
 * sites render with the theme's native templates and shortcodes, not blocks, so
 * this CSS is dead weight on every page-load. Detection: scan post_content for
 * the block marker `<!-- wp:` — if absent, the post has no Gutenberg blocks.
 *
 * Operator override: add_filter( 'lafka_keep_block_library_css', '__return_true' );
 */
if ( ! function_exists( 'lafka_perf_dequeue_unused_block_library' ) ) {
	add_action( 'wp_enqueue_scripts', 'lafka_perf_dequeue_unused_block_library', 99 );
	function lafka_perf_dequeue_unused_block_library() {
		if ( is_admin() ) {
			return;
		}
		if ( apply_filters( 'lafka_keep_block_library_css', false ) ) {
			return;
		}
		// Always-keep on archive pages — we can't cheaply detect whether the
		// individual posts in the loop use blocks. Only prune on singular pages
		// where we can scan a single post_content.
		if ( ! is_singular() ) {
			return;
		}
		$post = get_post();
		if ( $post && false === strpos( (string) $post->post_content, '<!-- wp:' ) ) {
			wp_dequeue_style( 'wp-block-library' );
			wp_dequeue_style( 'wp-block-library-theme' );
			wp_dequeue_style( 'global-styles' );
			wp_dequeue_style( 'classic-theme-styles' );
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
