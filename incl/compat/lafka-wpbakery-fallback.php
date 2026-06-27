<?php
/**
 * WPBakery (js_composer) graceful-fallback shim.
 *
 * Lets the operator DEACTIVATE the heavy WPBakery plugin without breaking pages
 * whose stored content was built with it. WPBakery's `[vc_row]/[vc_column]/...`
 * are layout WRAPPERS; the real content inside them is plain HTML plus first-
 * party shortcodes (lafka_map, lafka_contact_form, lafka_foodmenu) and
 * WooCommerce shortcodes — all of which keep working on their own.
 *
 * When js_composer is NOT loaded, this strips orphaned `[vc_*]` wrapper tags
 * from rendered content (the_content, widget_text, term descriptions) while
 * preserving the inner content + nested shortcodes. VC-specific column styling
 * is lost (it degrades to a clean single-column flow), but no raw `[vc_row]`
 * text ever leaks to visitors and every real element still renders.
 *
 * No-ops entirely when WPBakery IS active (it handles its own shortcodes).
 *
 * @package Lafka\Plugin\Compat
 * @since   9.35.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'lafka_wpbakery_is_active' ) ) {
	/**
	 * Whether WPBakery / Visual Composer is loaded and owning its shortcodes.
	 *
	 * @return bool
	 */
	function lafka_wpbakery_is_active(): bool {
		return defined( 'WPB_VC_VERSION' ) || class_exists( 'Vc_Manager' ) || function_exists( 'vc_map' );
	}
}

if ( ! function_exists( 'lafka_wpbakery_strip_orphans' ) ) {
	/**
	 * Strip orphaned WPBakery shortcode tags, keeping inner content intact.
	 *
	 * Runs at priority 9 on the content filters — BEFORE core's do_shortcode
	 * (priority 11) — so the surviving first-party / WooCommerce shortcodes still
	 * execute normally. Cheap-exits when there is no `[vc_` marker.
	 *
	 * @param string $content
	 * @return string
	 */
	function lafka_wpbakery_strip_orphans( $content ) {
		$content = (string) $content;
		if ( '' === $content || false === strpos( $content, '[vc_' ) ) {
			return $content;
		}
		if ( lafka_wpbakery_is_active() ) {
			return $content; // WPBakery present → leave its shortcodes alone.
		}
		// Remove opening tags (with any attributes) and closing tags for the whole
		// vc_* family; keep everything between them. Attributes never contain a
		// literal ']' so [^\]]* is a safe, fast match.
		$stripped = preg_replace( '/\[\/?vc_[a-z0-9_]+(?:[^\]]*)\]/i', '', $content );

		return ( null === $stripped ) ? $content : $stripped;
	}
}

if ( ! lafka_wpbakery_is_active() && function_exists( 'add_filter' ) ) {
	// Content surfaces that may carry stored VC markup.
	add_filter( 'the_content', 'lafka_wpbakery_strip_orphans', 9 );
	add_filter( 'widget_text_content', 'lafka_wpbakery_strip_orphans', 9 );
	add_filter( 'term_description', 'lafka_wpbakery_strip_orphans', 9 );

	// Decorative / self-closing VC elements that carry no inner content: register
	// them as no-ops so they vanish cleanly rather than relying on the regex.
	if ( function_exists( 'add_shortcode' ) ) {
		foreach ( array( 'vc_separator', 'vc_empty_space', 'vc_icon', 'vc_hoverbox', 'vc_gap' ) as $lafka_vc_decorative ) {
			if ( ! shortcode_exists( $lafka_vc_decorative ) ) {
				add_shortcode( $lafka_vc_decorative, '__return_empty_string' );
			}
		}
	}
}

if ( ! function_exists( 'lafka_revslider_is_active' ) ) {
	/** @return bool Whether Slider Revolution is loaded and owning its shortcodes. */
	function lafka_revslider_is_active(): bool {
		return class_exists( 'RevSlider' ) || defined( 'RS_PLUGIN_PATH' ) || function_exists( 'rev_slider_shortcode' );
	}
}

// RevSlider: this install has orphaned slider definitions but ZERO placements
// (no shortcode, page-option, or template usage). When the plugin is deactivated,
// turn any stray [rev_slider] / [rev_slider_vc] into a clean no-op instead of raw
// shortcode text, so RevSlider can be dropped too. No-op when RevSlider is active.
if ( ! lafka_revslider_is_active() && function_exists( 'add_shortcode' ) ) {
	foreach ( array( 'rev_slider', 'rev_slider_vc' ) as $lafka_rs_tag ) {
		if ( ! shortcode_exists( $lafka_rs_tag ) ) {
			add_shortcode( $lafka_rs_tag, '__return_empty_string' );
		}
	}
}
