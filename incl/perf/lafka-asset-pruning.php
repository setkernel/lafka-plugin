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
 * Dequeue WP block-library CSS on pages that don't use Gutenberg blocks.
 *
 * `wp-block-library` is ~17 KB of CSS that WP enqueues globally. Most Lafka
 * sites build with WPBakery (VC) shortcodes, not blocks, so this CSS is dead
 * weight on every page-load. Detection: scan post_content for the block
 * marker `<!-- wp:` — if absent, the post has no Gutenberg blocks.
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
 * Dequeue WPBakery (VC) front-end CSS on pages that don't use VC shortcodes.
 *
 * `js_composer_front` is ~48 KB and is enqueued unconditionally by WPBakery
 * whenever the plugin is active, but only ~5 KB of it applies on a typical
 * landing page that uses a handful of vc_row / vc_column shortcodes. Empty
 * landing pages (no VC content at all) waste the entire 48 KB.
 *
 * Detection: scan post_content for the `[vc_` shortcode prefix. If absent,
 * VC isn't being used on the post.
 *
 * Operator override: add_filter( 'lafka_keep_vc_css', '__return_true' );
 */
if ( ! function_exists( 'lafka_perf_dequeue_unused_vc' ) ) {
	add_action( 'wp_enqueue_scripts', 'lafka_perf_dequeue_unused_vc', 99 );
	function lafka_perf_dequeue_unused_vc() {
		if ( is_admin() ) {
			return;
		}
		if ( apply_filters( 'lafka_keep_vc_css', false ) ) {
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
		// Cheap content marker: [vc_ shortcode is the canonical VC entry.
		if ( false !== strpos( $content, '[vc_' ) ) {
			return;
		}
		// VC handles vary slightly by version; dequeue the family.
		foreach ( array( 'js_composer_front', 'vc_animate-css', 'vc_settings', 'vc_lightbox', 'vc_carousel', 'vc_carousel_skin', 'vc_pretty-photo', 'vc_tta_style', 'vc_font_awesome_5_shims', 'vc_font_awesome_5_brands', 'vc_font_awesome_5_solid', 'vc_font_awesome_5' ) as $h ) {
			wp_dequeue_style( $h );
		}
	}
}

/**
 * Dequeue Font Awesome on pages that don't render any FA icons.
 *
 * Lafka registers `font_awesome_6` (~22 KB) as a frontend stylesheet. Many
 * pages don't use FA icons (esp. landing pages composed of WPBakery content
 * sliders + image grids). Detection: scan post_content for FA class markers
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
