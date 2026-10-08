<?php
/**
 * P6-PERF-4 (W3-T2, 2026-04-28): Asset pruning — dequeue heavy assets on pages
 * that don't need them (WP block-library CSS, Font Awesome, jQuery Migrate).
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
 * Dequeue Font Awesome on pages that don't render any FA icons.
 *
 * Lafka registers `font_awesome_6` (~22 KB) as a frontend stylesheet. Many
 * pages don't use FA icons (esp. landing pages made of image grids).
 * Detection: scan post_content for FA class markers
 * (`fa-`, `fas`, `far`, `fab`, `fal`) OR for any `[lafka_icon_` shortcode
 * (which renders an FA icon).
 *
 * Conservative: when in doubt, keep FA. The marker scan errs on the side
 * of keeping it.
 *
 * Operator override: add_filter( 'lafka_keep_font_awesome_css', '__return_true' );
 */
if ( ! function_exists( 'lafka_perf_dequeue_unused_font_awesome' ) ) {
	add_action( 'wp_enqueue_scripts', 'lafka_perf_dequeue_unused_font_awesome', 99 );
	function lafka_perf_dequeue_unused_font_awesome() {
		if ( is_admin() ) {
			return;
		}
		if ( apply_filters( 'lafka_keep_font_awesome_css', false ) ) {
			return;
		}
		// The active theme's header may render FA icons (search/account/cart) on
		// EVERY page; the post_content scan below can't see that, so dequeuing FA
		// there would tofu the header. Themes signal "my header needs FA
		// site-wide" via this filter — keep FA when set. (Real win: move header
		// icons to inline SVG, then the theme stops setting this.)
		if ( apply_filters( 'lafka_header_renders_fa_icons', false ) ) {
			return;
		}
		if ( ! is_singular() ) {
			return;
		}
		$post = get_post();
		if ( ! $post ) {
			return;
		}
		$content = (string) $post->post_content;
		// Any FA class marker, or any lafka icon shortcode → keep FA.
		if ( preg_match( '/\b(fa-|class=["\'"][^"\']*\b(fa[sblr]?)\b|\[lafka_icon)/i', $content ) ) {
			return;
		}
		// Mega-menu items may use FA icons via menu item meta — conservative
		// keep when the current layout has a mega-menu active.
		if ( has_nav_menu( 'primary' ) ) {
			$mega_menu_in_use = wp_cache_get( 'lafka_mega_menu_has_icons', 'lafka' );
			if ( false === $mega_menu_in_use ) {
				global $wpdb;
				// phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- one-shot cached lookup; result memoized for the request.
				$count = (int) $wpdb->get_var(
					"SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = '_lafka-menu-item-icon' AND meta_value != ''"
				);
				$mega_menu_in_use = $count > 0 ? 1 : 0;
				wp_cache_set( 'lafka_mega_menu_has_icons', $mega_menu_in_use, 'lafka', HOUR_IN_SECONDS );
			}
			if ( $mega_menu_in_use ) {
				return;
			}
		}
		foreach ( array( 'font_awesome_6', 'font_awesome_6_v4shims', 'font-awesome' ) as $h ) {
			wp_dequeue_style( $h );
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
