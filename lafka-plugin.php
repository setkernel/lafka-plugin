<?php
/*
	Plugin Name: Lafka Plugin
	Plugin URI: https://github.com/setkernel/lafka-plugin
	Description: Companion plugin for the Lafka WooCommerce theme. Originally by theAlThemist, now community-maintained.
	Version: 9.35.0
	Author: theAlThemist, Contributors
	Author URI: https://github.com/setkernel/lafka-plugin
	Requires at least: 6.6
	Requires PHP: 8.1
	WC requires at least: 9.5
	WC tested up to: 10.9
	License: GPL v2 or later
	License URI: https://www.gnu.org/licenses/gpl-2.0.html
 */
defined( 'ABSPATH' ) || exit;

if ( ! defined( 'LAFKA_PLUGIN_FILE' ) ) {
	define( 'LAFKA_PLUGIN_FILE', __FILE__ );
}

/**
 * Return filemtime-based version string for a plugin asset.
 *
 * @param string $relative_path Path relative to this plugin's root directory, e.g. 'assets/js/file.js'.
 * @return string|false
 * @since 8.6.0
 */
if ( ! function_exists( 'lafka_plugin_asset_version' ) ) {
	/**
	 * Returns a cache-busting version string for an asset.
	 *
	 * Memoizes filemtime lookups per request — the original implementation
	 * stat'd every asset on every page load, which is measurable on slow/
	 * networked storage when 20+ assets are enqueued.
	 *
	 * @param string $relative_path Path relative to the plugin root (e.g. "assets/js/foo.js").
	 * @return string mtime as string, or fallback "1.0.0" if file missing.
	 */
	function lafka_plugin_asset_version( $relative_path ) {
		static $cache = array();
		if ( isset( $cache[ $relative_path ] ) ) {
			return $cache[ $relative_path ];
		}
		$file                    = plugin_dir_path( LAFKA_PLUGIN_FILE ) . $relative_path;
		$cache[ $relative_path ] = file_exists( $file ) ? (string) filemtime( $file ) : '1.0.0';
		return $cache[ $relative_path ];
	}
}

// Load shared options helper — available to both plugin and theme.
require_once plugin_dir_path( __FILE__ ) . 'incl/class-lafka-options.php';

// Typed feature-module registry (NX1-01) — the single list of gated modules
// the Modules dashboard, Site Health and (later) the setup wizard read from.
// Foundational: required before Site Health / the Modules page below.
require_once plugin_dir_path( __FILE__ ) . 'incl/class-lafka-module-registry.php';

/**
 * Checkout-experience SSOT (NX1-04b): the `lafka_checkout_mode` option and its
 * fresh/existing migration. Required EARLY and its activation hook registered
 * FIRST (before the defaults seeder below) so a genuinely fresh install is seen
 * as fresh (no pre-existing `lafka` option) and defaults to 'blocks', while every
 * existing install is migrated to an explicit 'classic' — byte-identical to its
 * pre-update behaviour. The on-load migration covers in-place updates that never
 * fire the activation hook. The block-cart shim and the block integration below
 * both read this SSOT.
 */
require_once plugin_dir_path( __FILE__ ) . 'incl/checkout/class-lafka-checkout-mode.php';
Lafka_Checkout_Mode::init();
if ( function_exists( 'register_activation_hook' ) ) {
	register_activation_hook( __FILE__, array( 'Lafka_Checkout_Mode', 'on_activation' ) );
}

if ( ! function_exists( 'lafka_write_log' ) ) {
	function lafka_write_log( $log ) {
		if ( is_array( $log ) || is_object( $log ) ) {
			error_log( print_r( $log, true ) );
		} else {
			error_log( $log );
		}
	}
}

/**
 * Plugin shim for lafka_get_option().
 *
 * Precedence: Lafka_Options class (plugin canonical) → theme fallback → false.
 * When both plugin + theme load, the plugin defines this shim first (plugins_loaded
 * fires before after_setup_theme), so the theme's function_exists() guard prevents
 * double-definition. Lafka_Options::get() is the single source of truth.
 *
 * @see incl/class-lafka-options.php  Canonical implementation (Lafka_Options::get).
 * @see lafka-theme/incl/system/core-functions.php  Fallback: only fires if lafka-plugin is not active.
 *      Plugin's class-lafka-options.php definition supersedes this when both load.
 */
if ( ! function_exists( 'lafka_get_option' ) ) {
	function lafka_get_option( $name, $default = false ) {
		// Defensive guard: if this shim fires before Lafka_Options loaded, surface
		// a clear error rather than a silent fatal on the next line.
		if ( ! class_exists( 'Lafka_Options' ) ) {
			_doing_it_wrong(
				__FUNCTION__,
				'lafka_get_option() called before Lafka_Options class loaded. Ensure class-lafka-options.php is required before this shim.',
				'8.8.1'
			);
			return $default;
		}
		// Match the theme's helper: any falsy "no default given" sentinel falls
		// through to registered defaults. Only an explicit truthy default short-
		// circuits the framework defaults lookup.
		return Lafka_Options::get( $name, $default ?: null );
	}
}

// Check if WooCommerce is active (supports regular plugins and MU-plugins)
if ( in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ), true )
	|| ( is_multisite() && array_key_exists( 'woocommerce/woocommerce.php', get_site_option( 'active_sitewide_plugins', array() ) ) )
	|| class_exists( 'WooCommerce' ) ) {
	define( 'LAFKA_PLUGIN_IS_WOOCOMMERCE', true );
} else {
	define( 'LAFKA_PLUGIN_IS_WOOCOMMERCE', false );
}

// Check if bbPress is active
if ( class_exists( 'bbPress' ) ) {
	define( 'LAFKA_PLUGIN_IS_BBPRESS', true );
} else {
	define( 'LAFKA_PLUGIN_IS_BBPRESS', false );
}

if ( in_array( 'revslider/revslider.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ), true )
	|| ( is_multisite() && array_key_exists( 'revslider/revslider.php', get_site_option( 'active_sitewide_plugins', array() ) ) )
	|| class_exists( 'RevSliderBase' ) ) {
	define( 'LAFKA_PLUGIN_IS_REVOLUTION', true );
} else {
	define( 'LAFKA_PLUGIN_IS_REVOLUTION', false );
}

// Check if WC Marketplace is active
if ( in_array( 'dc-woocommerce-multi-vendor/dc_product_vendor.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ), true )
	|| ( is_multisite() && array_key_exists( 'dc-woocommerce-multi-vendor/dc_product_vendor.php', get_site_option( 'active_sitewide_plugins', array() ) ) )
	|| class_exists( 'WCMp' ) ) {
	define( 'LAFKA_PLUGIN_IS_WC_MARKETPLACE', true );
} else {
	define( 'LAFKA_PLUGIN_IS_WC_MARKETPLACE', false );
}

/*
 * Shared LAFKA_IS_* constants — canonical detection flags consumed by both
 * plugin and theme. The plugin loads first (plugins_loaded), so it sets these
 * early. The theme's config.php skips re-defining them when already set.
 */
if ( ! defined( 'LAFKA_IS_WOOCOMMERCE' ) ) {
	define( 'LAFKA_IS_WOOCOMMERCE', LAFKA_PLUGIN_IS_WOOCOMMERCE );
}
if ( ! defined( 'LAFKA_IS_BBPRESS' ) ) {
	define( 'LAFKA_IS_BBPRESS', LAFKA_PLUGIN_IS_BBPRESS );
}
if ( ! defined( 'LAFKA_IS_REVOLUTION' ) ) {
	define( 'LAFKA_IS_REVOLUTION', LAFKA_PLUGIN_IS_REVOLUTION );
}
if ( ! defined( 'LAFKA_IS_WC_MARKETPLACE' ) ) {
	define( 'LAFKA_IS_WC_MARKETPLACE', LAFKA_PLUGIN_IS_WC_MARKETPLACE );
}

// Feature-flag checks — accept legacy $lafka_options array for backward compat,
// but delegate to Lafka_Options::is_enabled() for consistent access.
function is_lafka_product_addons( $lafka_options = null ) {
	return Lafka_Options::is_enabled( 'product_addons' );
}

function is_lafka_shipping_areas( $lafka_options = null ) {
	return Lafka_Options::is_enabled( 'shipping_areas' );
}

function is_lafka_order_hours( $lafka_options = null ) {
	return Lafka_Options::is_enabled( 'order_hours' );
}

function is_lafka_kitchen_display( $lafka_options = null ) {
	return Lafka_Options::is_enabled( 'kitchen_display' );
}

/**
 * BOGO + delivery-minimum + promo banner (P2-01).
 *
 * When OFF (default), the legacy implementation in lafka-child/functions.php
 * stays active. When ON, that child code self-gates off and this plugin module
 * owns all promo behavior. Mutual-exclusion gate prevents double-applied hooks
 * during rollout.
 */
function is_lafka_promotions( $lafka_options = null ) {
	return Lafka_Options::is_enabled( 'promotions' );
}

/**
 * Security headers + user-enum hardening (P2-05). Loaded unconditionally;
 * the module itself decides whether to attach hooks (see Lafka_Security_Headers::is_active()).
 */
require_once plugin_dir_path( __FILE__ ) . 'incl/security/class-lafka-security-headers.php';

/**
 * Admin UI for the security-headers toggle (P2-05a). Self-gates to is_admin().
 */
require_once plugin_dir_path( __FILE__ ) . 'incl/security/class-lafka-security-admin.php';

/**
 * Site Health diagnostics (P5-02). Self-gates to is_admin().
 */
require_once plugin_dir_path( __FILE__ ) . 'incl/site-health/class-lafka-site-health.php';

/**
 * Feature Modules dashboard (NX1-01) — top-level "Lafka" menu → "Modules".
 * One screen to see/flip every gated module. Self-gates to is_admin().
 */
if ( is_admin() ) {
	require_once plugin_dir_path( __FILE__ ) . 'incl/admin/class-lafka-modules-page.php';
}

/**
 * Settings export / import (NX1-05) — "Lafka → Tools". Export/import a versioned
 * config bundle (flags, business, order-hours, shipping areas, branches, zones,
 * add-on groups, lafka_ theme_mods) to move a configured install between sites.
 * Secrets + KDS + personal-data tables are excluded by design. Self-gates to
 * is_admin() inside the class file; the WP-CLI surface is registered separately.
 */
if ( is_admin() ) {
	require_once plugin_dir_path( __FILE__ ) . 'incl/tools/class-lafka-config-bundle.php';
	require_once plugin_dir_path( __FILE__ ) . 'incl/admin/class-lafka-tools-page.php';
}

/**
 * Admin new-order notification poller (NX1-08b) — MOVED from the parent theme.
 * Registers the wp_ajax_lafka_new_orders_notification handler, the admin poller
 * JS + its service worker, and the browser-permission dialog. Self-gates to
 * is_admin() (admin-ajax is admin context) and internally to WooCommerce + the
 * shared `order_notifications` flag + the shop-manager capability.
 */
if ( is_admin() ) {
	require_once plugin_dir_path( __FILE__ ) . 'incl/admin/class-lafka-order-notifications.php';
}

/**
 * Block Cart/Checkout compat shim. Detects WC's default Block-based cart and
 * checkout pages (which silently disable Lafka's classic-cart notices and
 * branch selector) and rewrites them to the supported shortcodes. Self-gates
 * to admin context and runs once per site. See class for full rationale.
 */
if ( is_admin() ) {
	require_once plugin_dir_path( __FILE__ ) . 'incl/compat/class-lafka-block-cart-shim.php';
}

/**
 * Suppress the 404 from address-field-autocomplete-for-woocommerce when its
 * build/style-index.css is absent (P6-PERF-10). Self-heals when the upstream
 * plugin ships the file.
 */
require_once plugin_dir_path( __FILE__ ) . 'incl/compat/lafka-address-autocomplete-compat.php';

/**
 * Bridge for upstream wordpressdotorg/wordpress-importer (replaces v9.7.17
 * deleted Lafka fork). Auto-creates missing WC product-attribute taxonomies
 * during WXR imports so products land with their attribute terms intact.
 */
require_once plugin_dir_path( __FILE__ ) . 'incl/compat/wp-importer-wc-attrs-bridge.php';

/**
 * WPBakery (js_composer) graceful-fallback shim — strips orphaned [vc_*] wrapper
 * tags when the heavy WPBakery plugin is deactivated, so VC-built pages still
 * render (content + first-party/WC shortcodes preserved). Makes WPBakery a
 * non-dependency. No-ops when WPBakery is active.
 */
require_once plugin_dir_path( __FILE__ ) . 'incl/compat/lafka-wpbakery-fallback.php';

if ( ! function_exists( 'lafka_seo_plugin_active' ) ) {
	/**
	 * Whether a dedicated SEO plugin is managing head metadata.
	 *
	 * Single source of truth for the "an SEO plugin owns head metadata"
	 * decision, shared by every Lafka head emitter: the JSON-LD @graph
	 * (incl/schema/class-lafka-json-ld.php), the OpenGraph / Twitter Card
	 * tags (lafka_insert_og_tags), and the meta description
	 * (lafka_render_meta_description).
	 *
	 * When any of these plugins is active it emits its own
	 * Organization/LocalBusiness JSON-LD, <meta name="description">, and
	 * og:* / twitter:* tags — so Lafka must defer to avoid duplicate,
	 * conflicting metadata being served to search engines and social
	 * scrapers on every public page.
	 *
	 * Detects: Yoast SEO, Rank Math, SEOPress, All in One SEO.
	 *
	 * Defined before the schema require below so the JSON-LD module can
	 * reuse it as its single source of truth rather than duplicating the
	 * detection inline.
	 *
	 * @since 9.23.0
	 * @return bool True when a dedicated SEO plugin is active.
	 */
	function lafka_seo_plugin_active() {
		return (
			defined( 'WPSEO_VERSION' )                      // Yoast SEO.
			|| class_exists( 'RankMath' )                   // Rank Math.
			|| defined( 'SEOPRESS_VERSION' )                // SEOPress.
			|| class_exists( '\\AIOSEO\\Plugin\\AIOSEO' )   // All in One SEO.
		);
	}
}

/**
 * P6-SEO-1/2/3/6: JSON-LD structured data — Restaurant, Menu, Product,
 * BreadcrumbList. Loads on both frontend and admin (admin is gated inside the
 * class). The helpers file also provides lafka_schema_get_nap() which is the
 * single source-of-truth for the [lafka_nap] shortcode below.
 */
require_once plugin_dir_path( __FILE__ ) . 'incl/schema/class-lafka-json-ld.php';

/**
 * P6-SEO-4 (W2-T2): Per-post meta description override meta box.
 * Provides the admin UI for _lafka_meta_description, which
 * lafka_resolve_meta_description() already reads as its highest-priority source.
 */
if ( is_admin() ) {
	require_once plugin_dir_path( __FILE__ ) . 'incl/admin/lafka-meta-description-box.php';
}

/**
 * P6-SEO-12 (W2-T6): Canonical URL for paginated/filtered shop archives.
 * WP core's rel_canonical() skips non-singular pages; this module emits
 * canonical for shop/product-taxonomy archives and strips sort/filter params.
 */
require_once plugin_dir_path( __FILE__ ) . 'incl/seo/lafka-shop-canonical.php';

/**
 * v9.22.0: suppress WooCommerce's default BreadcrumbList JSON-LD on product
 * pages — Lafka already emits a cleaner one in the consolidated @graph.
 */
require_once plugin_dir_path( __FILE__ ) . 'incl/seo/lafka-suppress-wc-breadcrumb-jsonld.php';

/**
 * v9.26.0 (Phase 2 — Analytics + SEO + Conversion plan):
 *
 *   - incl/seo/lafka-sitemap.php hooks `wp_sitemaps_posts_query_args` to
 *     exclude WooCommerce funnel pages (cart, checkout, my-account,
 *     order-received, order-pay) from the WP-core sitemap. Operators can
 *     extend the slug list via the `lafka_sitemap_excluded_slugs` filter.
 *   - incl/seo/lafka-robots.php hooks `robots_txt` to append Disallow lines
 *     for the same funnel paths plus WC's `?add-to-cart=`, `?wc-ajax=`,
 *     and shop-archive sort/filter query-arg prefixes. Idempotent — won't
 *     duplicate lines if another plugin already emitted them.
 *
 * Both modules are safe to load unconditionally — sitemap filter only acts
 * on `page` sub-sitemaps, robots filter no-ops when the site is in
 * "Discourage search engines" mode.
 */
require_once plugin_dir_path( __FILE__ ) . 'incl/seo/lafka-sitemap.php';
require_once plugin_dir_path( __FILE__ ) . 'incl/seo/lafka-robots.php';

/**
 * v9.23.0 (Phase 1A — Analytics + SEO + Conversion plan):
 *
 *   - incl/customizer/class-lafka-customizer-analytics.php registers the
 *     `lafka_analytics` Customizer panel (GTM container, direct GA4/Clarity/
 *     Pixel IDs, GSC verification, Consent Mode v2 defaults + banner copy).
 *     The class self-gates inside customize_register, so it is safe to load
 *     on both admin and front-end.
 *   - incl/analytics/lafka-analytics-emitter.php hooks wp_head (priority 1
 *     for consent default + GSC, priority 2 for the tag snippets),
 *     wp_body_open (GTM noscript), and wp_footer (consent banner +
 *     no-wp_body_open fallback). All emitters no-op when their respective
 *     IDs are empty, so loading unconditionally is zero-cost. The consent
 *     banner additionally gates on lafka_analytics_is_active() so a default
 *     install with no tracking destination never shows a cookie banner for
 *     cookies it does not set.
 */
require_once plugin_dir_path( __FILE__ ) . 'incl/customizer/class-lafka-customizer-analytics.php';
require_once plugin_dir_path( __FILE__ ) . 'incl/analytics/lafka-analytics-emitter.php';

/**
 * v9.24.0 (Phase 1B — Analytics + SEO + Conversion plan):
 *
 *   - incl/analytics/lafka-wc-events.php emits GA4-shape ecommerce events
 *     (view_item, view_item_list, view_cart, begin_checkout, add_to_cart,
 *     remove_from_cart, purchase) to window.dataLayer. View events are
 *     synchronous server-rendered <script> tags hooked on the natural WC
 *     template flow; interaction events queue to the WC session and flush
 *     on the next page-load wp_footer pass. AJAX add-to-cart payloads ride
 *     the wc-ajax fragment response so the client JS picks them up in the
 *     same tick the cart fragment refreshes.
 *   - assets/js/lafka-dl-client.js binds the JS-only events (select_item,
 *     search, add_shipping_info, add_payment_info) and mirrors the AJAX
 *     add_to_cart / remove_from_cart fragments into dataLayer. The script
 *     is enqueued only when at least one analytics ID is configured, so
 *     unconfigured sites pay zero request cost.
 *
 *   purchase is idempotent: the order's `_lafka_dl_purchase_fired` post-meta
 *   is set after the first emit; refreshing /order-received/ won't re-fire.
 */
require_once plugin_dir_path( __FILE__ ) . 'incl/analytics/lafka-wc-events.php';

/**
 * v9.25.0 (Phase 1C — Analytics + SEO + Conversion plan):
 *
 *   - incl/analytics/lafka-custom-events.php enqueues lafka-custom-events.js
 *     (gated on at least one configured analytics ID, identical pattern to
 *     Phase 1B). The bundle binds eight selector-driven interaction events
 *     to window.dataLayer:
 *
 *       phone_click             a[href^="tel:"]
 *       email_click             a[href^="mailto:"]
 *       get_directions_click    maps URLs + "directions" text
 *       faq_open                details.lafka-contact__faq-item open toggle
 *       filter_apply            .lafka-menu__chip / .lafka-menu__category-chip
 *       scroll_milestone        25 / 50 / 75 / 100 % (once per page view)
 *       outbound_link           absolute a[href] to a foreign host
 *       sticky_cart_open        .lafka-sticky-cart enters viewport or .is-open
 *
 *   The bundle is theme-agnostic — it binds via stable class names / hrefs
 *   that already exist in the front-end markup. No theme changes ship with
 *   this version.
 */
require_once plugin_dir_path( __FILE__ ) . 'incl/analytics/lafka-custom-events.php';

/**
 * v9.31.0 (Tracking foundation — Growth program):
 *
 *   - incl/analytics/lafka-page-context.php   emits one server-rendered
 *     `page_context` dataLayer push on every page (page_type, fulfilment_method,
 *     store_open, customer_logged_in, customer_is_repeat, cart_items_count,
 *     cart_value_band, top_category) so GA4/Clarity segment without per-page wiring.
 *   - incl/analytics/lafka-store-events.php   enqueues lafka-store-events.js
 *     (order_channel_click [direct vs UberEats/Skip/DoorDash/phone],
 *     select_fulfilment, select_addon, store_closed_view).
 *   - incl/analytics/lafka-cf-analytics.php   emits the cookieless Cloudflare
 *     Web Analytics beacon when a token is configured (independent of GTM/consent).
 *
 *   All gated on an analytics destination being configured (lafka_analytics_is_active()).
 *   Event dictionary + data-attr contracts: incl/../docs/TRACKING.md.
 */
require_once plugin_dir_path( __FILE__ ) . 'incl/analytics/lafka-page-context.php';
require_once plugin_dir_path( __FILE__ ) . 'incl/analytics/lafka-store-events.php';
require_once plugin_dir_path( __FILE__ ) . 'incl/analytics/lafka-cf-analytics.php';
require_once plugin_dir_path( __FILE__ ) . 'incl/analytics/lafka-clarity-tags.php';

/**
 * v9.27.0 (Phase 3B — Analytics + SEO + Conversion plan):
 *
 *   - incl/conversion/lafka-abandoned-cart-db.php   creates / migrates the
 *     `wp_lafka_abandoned_carts` table via dbDelta. Self-heals on plugins_loaded
 *     if the schema version drifted. Exposes save_cart / mark_recovered /
 *     mark_recovery_sent / get_pending / get_row_by_token / cleanup /
 *     delete_by_email helpers.
 *   - incl/conversion/lafka-abandoned-cart-capture.php  hooks
 *     `woocommerce_checkout_update_order_review` (saves email + cart whenever a
 *     visitor edits the order-review block) and `woocommerce_checkout_order_processed`
 *     (marks the row recovered so cron never sends to a completed customer).
 *     Also cascades WC account deletions into the abandoned-cart table.
 *   - incl/conversion/lafka-abandoned-cart-cron.php  registers
 *     `every_fifteen_minutes` via cron_schedules, schedules the
 *     `lafka_check_abandoned_carts` event + the daily
 *     `lafka_cleanup_abandoned_carts` event. Both events self-heal on
 *     plugins_loaded so a missing schedule re-registers automatically.
 *   - incl/conversion/lafka-abandoned-cart-email.php  registers the
 *     `LAFKA_Abandoned_Cart_Email` WC_Email subclass through the
 *     `woocommerce_email_classes` filter so the recovery email inherits WC's
 *     own header/footer/styling. The class body lives in the sibling
 *     class-lafka-abandoned-cart-email-class.php file (lazy-loaded — WC_Email
 *     isn't defined until WC has booted).
 *   - incl/conversion/lafka-abandoned-cart-resume.php  hooks `init` priority 5
 *     to inspect `$_GET['lafka_resume_cart']`, restore the visitor's cart, and
 *     redirect to /cart/.
 *   - incl/customizer/class-lafka-customizer-abandoned-cart.php  registers the
 *     `lafka_abandoned_cart` Customizer panel — enable toggle (default OFF),
 *     delay minutes, subject + heading + body + CTA label overrides, global
 *     opt-out blocklist.
 *
 * The DB module loads first so its helpers are available to the capture / cron
 * layers (which call lafka_ac_save_cart / lafka_ac_get_pending). All six files
 * are safe to load on every request — each one self-gates on the
 * `lafka_ac_enabled` Customizer toggle and no-ops when disabled.
 */
require_once plugin_dir_path( __FILE__ ) . 'incl/conversion/lafka-abandoned-cart-db.php';
require_once plugin_dir_path( __FILE__ ) . 'incl/conversion/lafka-abandoned-cart-capture.php';
require_once plugin_dir_path( __FILE__ ) . 'incl/conversion/lafka-abandoned-cart-cron.php';
require_once plugin_dir_path( __FILE__ ) . 'incl/conversion/lafka-abandoned-cart-email.php';
require_once plugin_dir_path( __FILE__ ) . 'incl/conversion/lafka-abandoned-cart-resume.php';
require_once plugin_dir_path( __FILE__ ) . 'incl/customizer/class-lafka-customizer-abandoned-cart.php';

/**
 * v9.27.0: activation + deactivation hooks for the abandoned-cart module.
 *
 *   on activate   →  install the table + register the WP-Cron events
 *   on deactivate →  unschedule the cron events (table is intentionally kept
 *                    so flipping off/on doesn't lose pending recovery rows)
 *
 * The table itself is dropped only on plugin uninstall (uninstall.php).
 */
if ( function_exists( 'register_activation_hook' ) ) {
	register_activation_hook(
		__FILE__,
		static function () {
			if ( function_exists( 'lafka_ac_install_table' ) ) {
				lafka_ac_install_table();
			}
			if ( function_exists( 'lafka_ac_schedule_events' ) ) {
				lafka_ac_schedule_events();
			}
		}
	);
}
if ( function_exists( 'register_deactivation_hook' ) ) {
	register_deactivation_hook(
		__FILE__,
		static function () {
			if ( function_exists( 'lafka_ac_unschedule_events' ) ) {
				lafka_ac_unschedule_events();
			}
		}
	);
}

/**
 * v9.28.0 (Phase 3D — Analytics + SEO + Conversion plan):
 *
 *   - incl/conversion/lafka-review-prompt-email.php  registers a WC_Email
 *     subclass (`LAFKA_Review_Prompt_Email`) via woocommerce_email_classes,
 *     schedules a one-shot WP-Cron event `lafka_send_review_email` N hours
 *     after an order transitions to status `completed` (operator-configurable,
 *     default 24h), and handles the unsubscribe link
 *     (`?lafka_unsubscribe_reviews=…&u=…`) that flips a user-meta opt-out flag.
 *   - incl/conversion/lafka-review-prompt-banner.php  server-side detects whether
 *     the current logged-in user has a completed order within the configured
 *     window (default 7 days) and sets the `lafka_review_prompt_show` cookie
 *     accordingly. Registers two REST endpoints:
 *         POST /wp-json/lafka/v1/review-banner-dismiss  (logged-in, `read` cap)
 *         POST /wp-json/lafka/v1/review-banner-shown    (public, rate-limited)
 *   - incl/customizer/class-lafka-customizer-reviews.php  registers the
 *     `lafka_reviews` Customizer panel with three sections: email channel
 *     toggle + delay + copy, shared review-target URL + label, banner channel
 *     toggle + window-days + copy + CTA label.
 *
 * Email + banner are both default OFF — operator opt-in. Either can be enabled
 * independently. The Phase 3D class supersedes the original P6-UX-8 simple
 * review-prompt email; the legacy file in incl/emails/ is kept but is now a
 * no-op shim that defers to the Phase 3D scheduler.
 */
require_once plugin_dir_path( __FILE__ ) . 'incl/conversion/lafka-review-prompt-email.php';
require_once plugin_dir_path( __FILE__ ) . 'incl/conversion/lafka-review-prompt-banner.php';
require_once plugin_dir_path( __FILE__ ) . 'incl/customizer/class-lafka-customizer-reviews.php';

/**
 * v9.29.0 (Phase 3E — Web Push notifications):
 *
 *   - incl/conversion/lafka-push-db.php  owns wp_lafka_push_subscriptions
 *     (endpoint UNIQUE, p256dh, auth, user_agent, locale, timestamps,
 *     unsubscribed_at). dbDelta migration self-heals on plugins_loaded.
 *   - incl/conversion/lafka-push-rest.php  registers 3 REST routes under
 *     lafka/v1:
 *         POST /push/subscribe   (nonce — saves a subscription row)
 *         POST /push/unsubscribe (nonce — soft-deletes a row)
 *         GET  /push/vapid-key   (public — returns the operator's public key)
 *   - incl/conversion/lafka-push-sender.php  raw-cURL Web Push protocol
 *     (RFC 8030 + 8291 + 8292). Builds VAPID JWT (ES256), HKDF-derives the
 *     content-encryption key, AES-128-GCM-encrypts the payload, POSTs to
 *     the endpoint. Pure PHP — zero composer deps. Handles 410 Gone +
 *     404 endpoint cleanup automatically.
 *   - incl/conversion/lafka-push-reorder-cron.php  daily cron that finds
 *     users whose last completed order was N days ago (default 14) and
 *     sends "Your usual?" pushes. Per-user opt-out via user_meta.
 *   - incl/admin/class-lafka-push-admin.php  adds a "Push notifications"
 *     submenu under WooCommerce — audience picker (All / Recent customers /
 *     Specific user IDs) + title/body/url/icon composer + Preview / Send +
 *     activity log (last 20 sends in a single option).
 *   - incl/customizer/class-lafka-customizer-push.php  registers the
 *     `lafka_push` Customizer panel with three sections: VAPID + master
 *     toggle, subscribe prompt (toggle + threshold + copy), reorder reminder
 *     (toggle + days).
 *
 * Default OFF — operator opts in by pasting VAPID keys + flipping the
 * master toggle. The REST routes refuse all writes when disabled. The
 * theme's subscribe-prompt JS also gates on the master toggle (via
 * wp_localize_script).
 */
require_once plugin_dir_path( __FILE__ ) . 'incl/conversion/lafka-push-db.php';
require_once plugin_dir_path( __FILE__ ) . 'incl/conversion/lafka-push-rest.php';
require_once plugin_dir_path( __FILE__ ) . 'incl/conversion/lafka-push-sender.php';
require_once plugin_dir_path( __FILE__ ) . 'incl/conversion/lafka-push-reorder-cron.php';
require_once plugin_dir_path( __FILE__ ) . 'incl/customizer/class-lafka-customizer-push.php';
if ( is_admin() ) {
	require_once plugin_dir_path( __FILE__ ) . 'incl/admin/class-lafka-push-admin.php';
}

/**
 * NX1-06: WP privacy exporter + eraser for the conversion tables that hold
 * personal data — push subscriptions (endpoint / user-agent / locale, matched
 * to the requesting email's WP user) and abandoned carts (email + cart
 * snapshot, matched on the email column). Registered on the core privacy
 * filters so GDPR export/erase requests reach the Lafka data stores.
 */
require_once plugin_dir_path( __FILE__ ) . 'incl/conversion/class-lafka-conversion-privacy.php';
( new Lafka_Conversion_Privacy() )->register();

/**
 * v9.29.0: activation + deactivation hooks for the Web Push module.
 *
 *   on activate   → install the subscriptions table + schedule daily crons
 *   on deactivate → unschedule cron events (table is kept so flip-off/on
 *                   doesn't lose subscribers; uninstall.php drops the table)
 */
if ( function_exists( 'register_activation_hook' ) ) {
	register_activation_hook(
		__FILE__,
		static function () {
			if ( function_exists( 'lafka_push_install_table' ) ) {
				lafka_push_install_table();
			}
			if ( function_exists( 'lafka_push_reorder_schedule_event' ) ) {
				lafka_push_reorder_schedule_event();
			}
		}
	);
}
if ( function_exists( 'register_deactivation_hook' ) ) {
	register_deactivation_hook(
		__FILE__,
		static function () {
			if ( function_exists( 'lafka_push_reorder_unschedule_event' ) ) {
				lafka_push_reorder_unschedule_event();
			}
		}
	);
}

/**
 * Seed the framework's default option values on activation.
 *
 * The include-time feature-loader gates below (is_lafka_product_addons(), etc.)
 * run at plugins_loaded — before `init`, and therefore before the theme's
 * options framework registers its lazily-loaded defaults. On a fresh install
 * whose 'lafka' option has not yet been persisted, that gate would read no value
 * and switch default-ON features (e.g. product_addons, std='enabled') OFF until
 * the theme's own admin_init seeder runs. Persisting the defaults here, at
 * activation, closes that window so the gate reads the 'enabled' value directly
 * and stays consistent with lafka_get_option() for the same key.
 *
 * Mirrors the theme's own create-only seeder (lafka_optionsframework_setdefaults):
 * it only writes when 'lafka' is absent, so it never clobbers saved values nor
 * defeats the theme's "seed when missing" sentinel. No-ops when the active theme
 * does not expose lafka_get_default_values() (plugin-only / third-party theme),
 * in which case the theme owns those defaults and both access paths agree on
 * false. Activation runs in an admin request after `init` has fired, so calling
 * lafka_get_default_values() here is safe (no "_load_textdomain_just_in_time").
 */
if ( function_exists( 'register_activation_hook' ) ) {
	register_activation_hook(
		__FILE__,
		static function () {
			if ( get_option( 'lafka' ) || ! function_exists( 'lafka_get_default_values' ) ) {
				return;
			}
			$defaults = lafka_get_default_values();
			if ( is_array( $defaults ) && ! empty( $defaults ) ) {
				add_option( 'lafka', $defaults );
			}
		}
	);
}

/**
 * P6-A11Y-9 (W2-T7): WP-CLI command to backfill missing/garbage image alt text.
 * Self-gates: the file returns early when WP_CLI is not defined, so it is safe
 * to require unconditionally here — it only attaches behaviour during WP-CLI runs.
 */
require_once plugin_dir_path( __FILE__ ) . 'incl/cli/lafka-image-alt-backfill.php';

/**
 * P6-UX-8 (W3-T6): WP-CLI helpers for WooCommerce product review configuration.
 * Self-gates: the file returns early when WP_CLI is not defined.
 *
 *   wp lafka reviews status
 *   wp lafka reviews enable
 *   wp lafka reviews disable
 */
require_once plugin_dir_path( __FILE__ ) . 'incl/cli/lafka-reviews-cli.php';

/**
 * WP-CLI: bulk-generate WebP siblings for every PNG/JPG in wp-content/uploads.
 * Pairs with incl/perf/webp-swap.php which auto-swaps src/srcset to .webp
 * when the sibling exists. Self-gates: returns when WP_CLI is not defined.
 *
 *   wp lafka images convert-webp
 *   wp lafka images convert-webp --quality=85 --force
 *   wp lafka images convert-webp --path=2026/01 --dry-run
 */
require_once plugin_dir_path( __FILE__ ) . 'incl/cli/lafka-webp-convert.php';

/**
 * WP-CLI: export/import a Lafka configuration bundle (NX1-05).
 * Self-gates: returns when WP_CLI is not defined.
 *
 *   wp lafka config export --file=lafka-config.json
 *   wp lafka config import --file=lafka-config.json --dry-run
 */
require_once plugin_dir_path( __FILE__ ) . 'incl/cli/lafka-config-cli.php';

/**
 * WP-CLI: provision a deterministic demo restaurant for e2e/CI + preset QA (NX1-09a).
 * The class is always defined (pure helpers are unit-tested); only the command
 * registration self-gates on WP_CLI.
 *
 *   wp lafka seed-demo
 *   wp lafka seed-demo --reset
 */
require_once plugin_dir_path( __FILE__ ) . 'incl/cli/class-lafka-cli-seed-demo.php';

/**
 * P6-UX-8 (W3-T6) — deprecated as of v9.28.0 (Phase 3D).
 *
 * The original simple review-prompt email lived at incl/emails/lafka-review-prompt-email.php.
 * It has been superseded by the richer Phase 3D pipeline registered above
 * (incl/conversion/lafka-review-prompt-email.php + WC_Email subclass +
 * Customizer panel). The legacy file is now a no-op shim retained only so
 * any third-party that grep'd the include path doesn't fatal on a missing
 * file. New installations only hook the Phase 3D scheduler.
 */
require_once plugin_dir_path( __FILE__ ) . 'incl/emails/lafka-review-prompt-email.php';

/**
 * P6-PERF-4 (W3-T2, 2026-04-28): Asset pruning — dequeue heavy third-party assets
 * on pages that don't use them. Currently handles Revolution Slider (~150 KB CSS+JS).
 * Self-gates via is_admin() inside the module; safe to load unconditionally.
 */
require_once plugin_dir_path( __FILE__ ) . 'incl/perf/lafka-asset-pruning.php';

// Perf modules (CLS image-dim + LCP preload) — migrated from lafka-child
// v5.10.6 in lafka-plugin v9.7.25.
require_once __DIR__ . '/incl/perf/image-dimensions.php';
require_once __DIR__ . '/incl/perf/lcp-preload.php';
// WebP auto-swap (v9.10.0). No-op until .webp siblings exist on disk;
// generates them via `wp lafka images convert-webp`.
require_once __DIR__ . '/incl/perf/webp-swap.php';

add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
			// NX1-04b: the block Cart/Checkout path now holds every ordering gate
			// (NX1-04a), addon line items + totals (NX1-04c) and Lafka's order_type/
			// branch/timeslot fields, so declare full compatibility. Declared
			// unconditionally: an operator on classic mode is still compatible with
			// blocks — the declaration only removes WC's incompatibility warning.
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, true );
		}
	}
);

if ( LAFKA_PLUGIN_IS_WOOCOMMERCE ) {

	/**
	 * P6-UX-7 (W3-T5, 2026-04-28): Normalise price HTML — strip <sup> cents,
	 * unify variable-range separator to en-dash, remove "Price range:" prefix.
	 */
	require_once plugin_dir_path( __FILE__ ) . 'incl/woocommerce/lafka-price-presentation.php';

	/* Load nutrition and allergens */
	require_once plugin_dir_path( __FILE__ ) . '/incl/nutrition/lafka-nutrition.php';

	/*
	 * Store API (block cart/checkout, headless) server-truth parity (NX1-04a).
	 * Loaded unconditionally when WooCommerce is active; every adapter guards on
	 * class_exists() so a disabled feature module simply no-ops. Registers its
	 * hooks/schema on woocommerce_init once the Store API container is ready.
	 */
	require_once plugin_dir_path( __FILE__ ) . 'incl/store-api/class-lafka-store-api.php';
	Lafka_Store_Api::init();

	/*
	 * Block Cart/Checkout UI (NX1-04b). Builds on the NX1-04a Store API contract:
	 *   · Lafka_Checkout_Fields — order_type + branch selects via WooCommerce's
	 *     Additional Checkout Fields API, wired back into the classic session/order
	 *     meta so KDS/branch-routing/analytics see identical order meta.
	 *   · Lafka_Blocks_Integration — a build-free IntegrationInterface enqueuing the
	 *     timeslot picker (block checkout) + free-delivery progress (block cart).
	 * Both self-gate to blocks checkout mode; the classic path is untouched.
	 */
	require_once plugin_dir_path( __FILE__ ) . 'incl/checkout/class-lafka-checkout-fields.php';
	Lafka_Checkout_Fields::init();
	// The block integration `implements IntegrationInterface`, which WooCommerce
	// Blocks defines only once it loads (after this plugin file is included). Defer
	// requiring it to `woocommerce_blocks_loaded` so the interface exists at class
	// declaration time; the block-registration hooks it wires fire later still.
	add_action(
		'woocommerce_blocks_loaded',
		static function () {
			require_once plugin_dir_path( LAFKA_PLUGIN_FILE ) . 'incl/checkout/class-lafka-blocks-integration.php';
			if ( class_exists( 'Lafka_Blocks_Integration' ) ) {
				Lafka_Blocks_Integration::init();
			}
		}
	);

	if ( is_lafka_product_addons( get_option( 'lafka' ) ) ) {
		/* Load addons */
		require_once plugin_dir_path( __FILE__ ) . '/incl/addons/lafka-product-addons.php';
	}

	if ( is_lafka_shipping_areas( get_option( 'lafka' ) ) ) {
		require_once plugin_dir_path( __FILE__ ) . '/incl/shipping-areas/class-lafka-shipping-areas.php';
	}

	if ( is_lafka_order_hours( get_option( 'lafka' ) ) ) {
		/* Load order_hours */
		require_once plugin_dir_path( __FILE__ ) . '/incl/order-hours/Lafka_Order_Hours.php';
	}

	if ( is_lafka_kitchen_display( get_option( 'lafka' ) ) ) {
		/* Load kitchen display */
		require_once plugin_dir_path( __FILE__ ) . '/incl/kitchen-display/class-lafka-kitchen-display.php';
	}

	if ( is_lafka_promotions() ) {
		/* Load BOGO + delivery-min + banner (P2-01). Child code self-gates off. */
		require_once plugin_dir_path( __FILE__ ) . '/incl/promotions/class-lafka-promotions.php';
		if ( is_admin() ) {
			/* Admin UI for the 4 promo knobs (P2-01a). */
			require_once plugin_dir_path( __FILE__ ) . '/incl/promotions/class-lafka-promotions-admin.php';
		}
	}
}

add_action( 'plugins_loaded', 'lafka_plugin_after_plugins_loaded' );
// The variation-swatches constructor is hooked to plugins_loaded by the
// swatches file itself (incl/swatches/variation-swatches.php), which is
// required from lafka_plugin_after_plugins_loaded() during this same
// plugins_loaded dispatch; the add_action there registers it for the current
// priority-10 pass, so no duplicate registration is needed here.

if ( ! function_exists( 'lafka_load_wc_dependent_widgets' ) ) {
	/**
	 * Load WooCommerce-dependent widgets on widgets_init.
	 *
	 * These extend WC_Widget; requiring them before WooCommerce has loaded that
	 * abstract (e.g. at plugins_loaded, or during CLI `wp plugin activate`) is a
	 * fatal. By widgets_init WooCommerce has initialised, so WC_Widget exists;
	 * the class_exists guard keeps a misconfigured stack from fataling anyway.
	 * The widget file self-registers on widgets_init (priority 10), which still
	 * runs in the same pass since this loader is priority 5.
	 */
	function lafka_load_wc_dependent_widgets() {
		if ( ! class_exists( 'WC_Widget' ) ) {
			return;
		}
		foreach ( array( 'LafkaProductFilterWidget' ) as $file ) {
			require_once plugin_dir_path( __FILE__ ) . 'widgets/wc_widgets/' . $file . '.php';
		}
	}
}

function lafka_plugin_after_plugins_loaded() {
	// Load Nutrition Config - it may be needed also for menu entries
	require_once plugin_dir_path( __FILE__ ) . '/incl/nutrition/includes/class-lafka-nutrition-config.php';

	/* independent widgets */
	foreach ( array(
		'LafkaAboutWidget',
		'LafkaContactsWidget',
		'LafkaPaymentOptionsWidget',
		'LafkaPopularPostsWidget',
		'LafkaLatestMenuEntriesWidget',
	) as $file ) {
		require_once plugin_dir_path( __FILE__ ) . 'widgets/' . $file . '.php';
	}

	if ( LAFKA_PLUGIN_IS_WOOCOMMERCE ) {
		/*
		 * WooCommerce-dependent widgets extend WC_Widget, which only exists once
		 * WooCommerce has loaded its abstracts — not guaranteed at plugins_loaded,
		 * and absent entirely during CLI `wp plugin activate`. Load them on
		 * widgets_init (priority 5, before the widget self-registers at 10),
		 * guarded by class_exists, so requiring the class can never fatal.
		 */
		add_action( 'widgets_init', 'lafka_load_wc_dependent_widgets', 5 );

		// PERF-H09: woocommerce-metaboxes.php is admin-only (term edit fields + save hooks)
		if ( is_admin() ) {
			require_once plugin_dir_path( __FILE__ ) . '/incl/woocommerce-metaboxes.php';
		}
		require_once plugin_dir_path( __FILE__ ) . '/incl/woocommerce-functions.php';

		// subcategories after 3.3.1 - will need refactoring in future
		remove_filter( 'woocommerce_product_loop_start', 'woocommerce_maybe_show_product_subcategories' );

		// Check if WPML and WooCommerce Multilingual are active
		if ( class_exists( 'SitePress' ) && class_exists( 'woocommerce_wpml' ) ) {
			define( 'LAFKA_PLUGIN_IS_WPML_WCML', true );
		} else {
			define( 'LAFKA_PLUGIN_IS_WPML_WCML', false );
		}

		global $sitepress;
		global $woocommerce_wpml;
		if ( LAFKA_PLUGIN_IS_WPML_WCML && is_lafka_product_addons( get_option( 'lafka' ) ) && ! empty( $sitepress ) && ! empty( $woocommerce_wpml ) ) {
			require_once plugin_dir_path( __FILE__ ) . '/incl/wpml/addons/class-wcml-lafka-product-addons.php';
			$lafka_product_addons = new WCML_Lafka_Product_Addons( $sitepress, $woocommerce_wpml );
			$lafka_product_addons->add_hooks();
		}

		// Suspend account notices on the cart page, because cart notices got taken by the account form in header
		add_action( 'wp', 'lafka_suspend_account_notice' );
		if ( ! function_exists( 'lafka_suspend_account_notice' ) ) {
			function lafka_suspend_account_notice() {
				if ( is_cart() ) {
					remove_action( 'woocommerce_before_customer_login_form', 'woocommerce_output_all_notices', 10 );
				}
			}
		}
	}

	/* shortcodes */
	require_once plugin_dir_path( __FILE__ ) . 'shortcodes/shortcodes.php';

	/*
	 * Map all Lafka shortcodes to WPBakery's Visual Composer.
	 *
	 * Must run on the frontend too, not just admin: `vc_map()` registers the
	 * editor metadata AND triggers WPBakery's `Vc_Mapper` to instantiate the
	 * matching `WPBakeryShortCode_*` class for class-based custom shortcodes
	 * (`as_parent`/`as_child` elements like `lafka_content_slider`). The
	 * class's parent constructor calls `add_shortcode()`, which is what makes
	 * the shortcode actually render content on the frontend.
	 *
	 * Was previously gated to `is_admin()` under PERF-H09 (Session 2). That
	 * skipped the admin metadata too — but it ALSO skipped the frontend
	 * shortcode registration, which broke `[lafka_content_slider]` and any
	 * other class-based VC element on every public page that used them.
	 */
	add_action( 'vc_before_init', 'lafka_integrateWithVC' );
	require_once plugin_dir_path( __FILE__ ) . 'shortcodes/shortcodes_to_vc_mapping.php';

	/* Load variation product swatches */
	require_once plugin_dir_path( __FILE__ ) . 'incl/swatches/variation-swatches.php';

	/* include metaboxes.php — PERF-H09: admin-only (add_meta_boxes + save_post with nonce) */
	if ( is_admin() ) {
		require_once plugin_dir_path( __FILE__ ) . '/incl/metaboxes.php';
	}

	/* Load foodmenu_category ordering in admin */
	require_once plugin_dir_path( __FILE__ ) . '/incl/foodmenu-category-ordering.php';

	/* P6-UX-6 W3-T10: mobile menu IA grouping walker (opt-in via Customizer) */
	require_once plugin_dir_path( __FILE__ ) . 'incl/menu/class-lafka-mobile-grouped-walker.php';

	/* include customizer class — PERF-H09: admin-only (customize_register hook) */
	if ( is_admin() ) {
		require_once plugin_dir_path( __FILE__ ) . '/incl/customizer/class-lafka-customizer.php';
	}

	/*
	 * v9.18.0: Restaurant Info as WooCommerce Settings tab. Replaces the
	 * legacy custom Customizer panel.
	 *
	 * v9.20.0: load at plugin init (not woocommerce_init). The class file
	 * registers the woocommerce_get_settings_pages filter at load time —
	 * the filter callback lazy-loads the class body when it fires, which
	 * is on Settings page render when WC_Settings_Page is guaranteed
	 * loaded. Loading on woocommerce_init was too early — WC admin
	 * classes weren't available yet and the file returned without
	 * registering the filter.
	 */
	if ( is_admin() ) {
		require_once plugin_dir_path( __FILE__ ) . 'incl/admin/class-lafka-wc-settings-restaurant.php';
	}

	/**
	 * P6-PDP (W4-T1, 2026-04-29): PDP Redesign Customizer panel + feature flag.
	 */
	require_once plugin_dir_path( __FILE__ ) . 'incl/customizer/class-lafka-customizer-pdp.php';

	/**
	 * P6-PDP (W4-T2, 2026-04-29): Best-seller eyebrow data + render.
	 */
	require_once plugin_dir_path( __FILE__ ) . 'incl/woocommerce/lafka-bestseller.php';

	/**
	 * P6-PDP (W4-T3, 2026-04-29): Prep-time trust signal.
	 */
	require_once plugin_dir_path( __FILE__ ) . 'incl/woocommerce/lafka-prep-time.php';

	/**
	 * P6-PDP (W4-T4, 2026-04-29): Last-order card cookie + reader + render + reorder AJAX.
	 */
	require_once plugin_dir_path( __FILE__ ) . 'incl/woocommerce/lafka-last-order-card.php';

	/**
	 * P6-PDP (W4-T5, 2026-04-29): Upsell row Customizer panel.
	 */
	require_once plugin_dir_path( __FILE__ ) . 'incl/customizer/class-lafka-customizer-upsell.php';

	/**
	 * P6-PDP (W4-T6, 2026-04-29): Upsell row renderer.
	 */
	require_once plugin_dir_path( __FILE__ ) . 'incl/woocommerce/lafka-upsell-row.php';

	/**
	 * P6-PDP (W4-T7, 2026-04-29): Cart drawer fragments.
	 */
	require_once plugin_dir_path( __FILE__ ) . 'incl/woocommerce/lafka-cart-drawer-fragments.php';
	require_once plugin_dir_path( __FILE__ ) . 'incl/woocommerce/lafka-cart-drawer-upsell.php';
	// Free delivery over $X — standalone (NOT behind the promotions/BOGO gate).
	require_once plugin_dir_path( __FILE__ ) . 'incl/woocommerce/lafka-free-delivery.php';
	// First-order discount — standalone (logged-in first-timers; abuse-resistant).
	require_once plugin_dir_path( __FILE__ ) . 'incl/woocommerce/lafka-first-order.php';
	// Slow-day discount — standalone (operator-chosen weekdays, site timezone).
	require_once plugin_dir_path( __FILE__ ) . 'incl/woocommerce/lafka-slow-day.php';
	// Combo deal — standalone (category-pair, e.g. pizza + poutine → save $X).
	require_once plugin_dir_path( __FILE__ ) . 'incl/woocommerce/lafka-combo-deal.php';

	/**
	 * P6-PDP (W4-T8, 2026-04-29): Checkout email-capture field.
	 */
	require_once plugin_dir_path( __FILE__ ) . 'incl/woocommerce/lafka-checkout-email-capture.php';

	/**
	 * v9.13.0 (2026-05-15): Dietary tag seeder — ensures the four filter
	 * chip terms (popular/vegetarian/vegan/spicy) exist so the menu
	 * archive dietary filter actually has terms to match against.
	 */
	require_once plugin_dir_path( __FILE__ ) . 'incl/woocommerce/lafka-dietary-tags.php';

	/**
	 * v9.22.2 (2026-05-18): Runtime product-image alt-text backfill. Fills in
	 * `alt=""` from the parent product name when an operator forgot to set
	 * the attachment alt during upload. Visual QA found 104/108 product
	 * images missing alt on /menu/.
	 */
	require_once plugin_dir_path( __FILE__ ) . 'incl/woocommerce/lafka-product-image-alt.php';

	// Removed because causes categories to appear twice in shop and category view.
	// Functionality not lost, because "woocommerce_maybe_show_product_subcategories" is called
	remove_filter( 'woocommerce_product_loop_start', 'woocommerce_maybe_show_product_subcategories' );
}

// C-10: hook on `plugins_loaded` (priority 10) so the text domain is available
// before `init` fires. CPT/taxonomy labels are registered at `init` priority 5
// in this plugin, so loading the text domain at `init` priority 10 was too late
// — non-default-locale labels rendered untranslated. `plugins_loaded` runs
// strictly before `init`, fixing the ordering.
add_action( 'plugins_loaded', 'lafka_load_plugin_text_domain' );
if ( ! function_exists( 'lafka_load_plugin_text_domain' ) ) {
	function lafka_load_plugin_text_domain() {
		load_plugin_textdomain( 'lafka-plugin', false, dirname( plugin_basename( __FILE__ ) ) . '/languages/' );
	}
}

// Fix bbpress  Notice: bp_setup_current_user was called incorrectly
if ( class_exists( 'bbPress' ) ) {
	remove_action( 'set_current_user', 'bbp_setup_current_user', 10 );
	add_action( 'set_current_user', 'lafka_bbp_setup_current_user', 10 );
}

if ( ! function_exists( 'lafka_bbp_setup_current_user' ) ) {

	function lafka_bbp_setup_current_user() {
		do_action( 'bbp_setup_current_user' );
	}

}

// PERF-H10: Only load wp-admin/includes/plugin.php in admin context.
// get_plugin_data() is only used by admin screens; loading this 500-line file
// on every frontend request is unnecessary.
if ( is_admin() && ! function_exists( 'get_plugin_data' ) ) {
	include_once ABSPATH . 'wp-admin/includes/plugin.php';
}

if ( ! defined( 'LAFKA_PLUGIN_IMAGES_PATH' ) ) {
	define( 'LAFKA_PLUGIN_IMAGES_PATH', plugins_url( '/assets/image/', plugin_basename( __FILE__ ) ) );
}

/**
 * Generate excerpt by post Id
 *
 * @param type $post_id
 * @param type $excerpt_length
 * @param type $dots_to_link
 * @return string
 */
if ( ! function_exists( 'lafka_get_excerpt_by_id' ) ) {

	function lafka_get_excerpt_by_id( $post_id, $excerpt_length = 35, $dots_to_link = false ) {

		$the_post = get_post( $post_id );
		if ( ! $the_post ) {
			return '';
		}
		$the_excerpt = wp_strip_all_tags( $the_post->post_excerpt );
		$the_excerpt = '<p>' . esc_html( $the_excerpt ) . '</p>';

		return $the_excerpt;
	}

}

/**
 * Define Foodmenu custom post type
 * 'lafka-foodmenu'
 */
if ( ! function_exists( 'lafka_register_cpt_lafka_foodmenu' ) ) {
	add_action( 'init', 'lafka_register_cpt_lafka_foodmenu', 5 );

	function lafka_register_cpt_lafka_foodmenu() {

		$labels = array(
			'name'               => esc_html__( 'Restaurant Menu', 'lafka-plugin' ),
			'singular_name'      => esc_html__( 'Menu Entry', 'lafka-plugin' ),
			'add_new'            => esc_html__( 'Add New Menu Entry', 'lafka-plugin' ),
			'add_new_item'       => esc_html__( 'Add New Menu Entry', 'lafka-plugin' ),
			'edit_item'          => esc_html__( 'Edit Restaurant Menu Entry', 'lafka-plugin' ),
			'new_item'           => esc_html__( 'New Menu Entry', 'lafka-plugin' ),
			'view_item'          => esc_html__( 'View Menu Entry', 'lafka-plugin' ),
			'search_items'       => esc_html__( 'Search Menu Entries', 'lafka-plugin' ),
			'not_found'          => esc_html__( 'No Menu Entries Found', 'lafka-plugin' ),
			'not_found_in_trash' => esc_html__( 'No Menu Entries found in Trash', 'lafka-plugin' ),
			'parent_item_colon'  => esc_html__( 'Parent Menu Entry:', 'lafka-plugin' ),
			'menu_name'          => esc_html__( 'Restaurant Menu', 'lafka-plugin' ),
		);

		$args = array(
			'labels'                => $labels,
			'hierarchical'          => false,
			'description'           => esc_html__( 'Lafka Restaurant Menu Post Type', 'lafka-plugin' ),
			'supports'              => array( 'title', 'editor', 'excerpt', 'author', 'thumbnail', 'revisions', 'custom-fields' ),
			'taxonomies'            => array( 'lafka_foodmenu_category' ),
			'public'                => true,
			'show_ui'               => true,
			'show_in_menu'          => true,
			'show_in_nav_menus'     => true,
			'show_in_rest'          => true,
			// `menu-items` collides with WP 5.9+ core nav-menu items endpoint
			// (`/wp/v2/menu-items`) — core registers it later, wins, and
			// returns 401 unauth. Lafka's CPT is therefore shadowed for
			// public consumers. Use a Lafka-specific rest_base so the
			// food-menu posts have a stable, unambiguous URL.
			'rest_base'             => 'lafka-foodmenu',
			'rest_controller_class' => 'WP_REST_Posts_Controller',
			'publicly_queryable'    => true,
			'exclude_from_search'   => false,
			'has_archive'           => true,
			'query_var'             => true,
			'can_export'            => true,
			'capability_type'       => 'page',
			'menu_icon'             => 'dashicons-list-view',
			'rewrite'               => array(
				'slug' => esc_html__( 'restaurant-menu', 'lafka-plugin' ),
			),
		);

		register_post_type( 'lafka-foodmenu', $args );
	}

}

/**
 * Define lafka_foodmenu_category taxonomy
 * used by lafka-foodmenu post type
 */
if ( ! function_exists( 'lafka_register_taxonomy_lafka_foodmenu_category' ) ) {
	add_action( 'init', 'lafka_register_taxonomy_lafka_foodmenu_category', 5 );

	function lafka_register_taxonomy_lafka_foodmenu_category() {

		$labels = array(
			'name'                       => esc_html__( 'Menu Categories', 'lafka-plugin' ),
			'singular_name'              => esc_html__( 'Menu Category', 'lafka-plugin' ),
			'search_items'               => esc_html__( 'Search Menu Categories', 'lafka-plugin' ),
			'popular_items'              => esc_html__( 'Popular Menu Categories', 'lafka-plugin' ),
			'all_items'                  => esc_html__( 'All Menu Categories', 'lafka-plugin' ),
			'parent_item'                => esc_html__( 'Parent Menu Category', 'lafka-plugin' ),
			'parent_item_colon'          => esc_html__( 'Parent Menu Category:', 'lafka-plugin' ),
			'edit_item'                  => esc_html__( 'Edit Menu Category', 'lafka-plugin' ),
			'update_item'                => esc_html__( 'Update Menu Category', 'lafka-plugin' ),
			'add_new_item'               => esc_html__( 'Add New', 'lafka-plugin' ),
			'new_item_name'              => esc_html__( 'New Menu Category', 'lafka-plugin' ),
			'separate_items_with_commas' => esc_html__( 'Separate Menu Categories with commas', 'lafka-plugin' ),
			'add_or_remove_items'        => esc_html__( 'Add or remove Menu Category', 'lafka-plugin' ),
			'choose_from_most_used'      => esc_html__( 'Choose from the most used Menu Categories', 'lafka-plugin' ),
			'menu_name'                  => esc_html__( 'Menu Categories', 'lafka-plugin' ),
		);

		$args = array(
			'labels'                => $labels,
			'public'                => true,
			'show_in_nav_menus'     => true,
			'show_ui'               => true,
			'show_in_rest'          => true,
			// Same collision: WP 5.9+ core registers `/wp/v2/menu-categories`
			// (in fact only `/wp/v2/menus` ships in core, but matching the
			// CPT's namespacing for consistency). Use the Lafka-prefixed
			// path so the taxonomy doesn't shadow or get shadowed by any
			// future core endpoint at the same name.
			'rest_base'             => 'lafka-foodmenu-categories',
			'rest_controller_class' => 'WP_REST_Terms_Controller',
			'show_tagcloud'         => true,
			'show_admin_column'     => false,
			'hierarchical'          => true,
			'query_var'             => true,
			'rewrite'               => array(
				'slug' => 'restaurant-menu-category',
			),
		);

		register_taxonomy( 'lafka_foodmenu_category', array( 'lafka-foodmenu' ), $args );
	}

}

add_action( 'init', 'lafka_theme_options_link' );
if ( ! function_exists( 'lafka_theme_options_link' ) ) {
	function lafka_theme_options_link() {
		if ( wp_get_theme()->get_template() === 'lafka' && current_user_can( 'edit_theme_options' ) ) {
			add_action( 'wp_before_admin_bar_render', 'lafka_optionsframework_adminbar' );
		}
	}
}

/**
 * Add a Theme Settings shortcut to the Admin Bar.
 *
 * NX1-02 (theme 7.0) retired the legacy "Appearance -> Theme Options" panel
 * (and the redirect that used to catch its URL); theme settings now live in the
 * Customizer's "Lafka" panel, so this shortcut points there instead of the
 * removed themes.php?page=lafka-optionsframework page.
 */
if ( ! function_exists( 'lafka_optionsframework_adminbar' ) ) {
	function lafka_optionsframework_adminbar() {

		global $wp_admin_bar;

		if ( ! $wp_admin_bar ) {
			return;
		}

		$wp_admin_bar->add_menu(
			array(
				'parent' => false,
				'id'     => 'lafka_of_theme_options',
				'title'  => esc_html__( 'Theme Options', 'lafka-plugin' ),
				'href'   => esc_url( admin_url( 'customize.php?autofocus[panel]=lafka_settings' ) ),
				'meta'   => array( 'class' => 'althemist-admin-opitons' ),
			)
		);
	}
}

// Register scripts
add_action( 'wp_enqueue_scripts', 'lafka_register_plugin_scripts' );
if ( ! function_exists( 'lafka_register_plugin_scripts' ) ) {

	function lafka_register_plugin_scripts() {

		// PERF-C02 / f105: The Lafka theme is the only supported runtime for the
		// theme-owned vendor handles below — it already registers/enqueues them
		// (see incl/system/core-functions.php) with matching src/version. The
		// theme-active guard further down returns early when the Lafka theme is
		// NOT the active stylesheet, so nothing here can act as a "plugin runs
		// without the Lafka theme" fallback (the URLs point at the theme directory
		// regardless). The pure flexslider/owl-carousel/cloud-zoom/countdown
		// duplicates were dead code and have been removed. `magnific` is kept
		// because the theme deliberately does NOT register it and relies on the
		// plugin to (the branch-locations ordering modal depends on the handle);
		// the remaining lafka-dialog/typed/nice-select/isotope registrations are
		// still theme-owned duplicates kept only for the active-theme case.
		// Do NOT wp_enqueue here — that would override the theme's conditional
		// enqueue guards (magnific is pulled in only via branch-locations deps).
		//
		// `lafka_asset_version()` is defined by the Lafka theme; keep a thin shim
		// onto the plugin's own helper so the guarded registrations below never
		// fatal on a missing function.
		if ( ! function_exists( 'lafka_asset_version' ) ) {
			function lafka_asset_version( $relative_path = '' ) {
				return lafka_plugin_asset_version( ltrim( $relative_path, '/' ) );
			}
		}

		/**
		 * Plugin-OWNED frontend assets are registered ABOVE the Lafka-theme
		 * guard below. Their URLs come from `plugins_url()` / maps.googleapis —
		 * they live in this plugin, not in any theme directory, so they never
		 * 404 regardless of the active theme. Registering them unconditionally
		 * honours the documented standalone-fallback contract so the checkout
		 * delivery date/time picker (flatpickr / flatpickr-local, a submit-path
		 * control) and the [lafka_map] shortcode (lafka-google-maps) keep
		 * working even when a non-Lafka theme is active.
		 */

		// Flatpickr (plugin-only asset — not in theme)
		wp_register_script( 'flatpickr', plugins_url( 'assets/js/flatpickr/flatpickr.min.js', __FILE__ ), array( 'jquery' ), lafka_plugin_asset_version( 'assets/js/flatpickr/flatpickr.min.js' ), true );

		// P6-PERF-6: enqueue ONLY the current site locale's flatpickr l10n file.
		// Try candidate filenames in priority order:
		//   1. Full locale lowercased with hyphen   (e.g. en-ca.js)
		//   2. Full locale lowercased with underscore (e.g. en_ca.js)
		//   3. Short-code only                       (e.g. en.js, fr.js)
		// English (en_US, en_CA, en_GB) is flatpickr's built-in default — no l10n file needed.
		$fp_locale       = get_locale(); // e.g. en_CA, fr_CA
		$fp_short        = strtolower( substr( $fp_locale, 0, 2 ) );
		$fp_candidates   = array(
			str_replace( '_', '-', strtolower( $fp_locale ) ) . '.js',
			strtolower( $fp_locale ) . '.js',
			$fp_short . '.js',
		);
		$fp_l10n_dir     = plugin_dir_path( LAFKA_PLUGIN_FILE ) . 'assets/js/flatpickr/l10n/';
		$fp_l10n_url_base = plugins_url( 'assets/js/flatpickr/l10n/', __FILE__ );
		$fp_picked       = null;
		foreach ( $fp_candidates as $fp_candidate ) {
			if ( file_exists( $fp_l10n_dir . $fp_candidate ) ) {
				$fp_picked = $fp_candidate;
				break;
			}
		}
		if ( null === $fp_picked ) {
			// Check the theme's custom l10n override directory using the same priority list.
			$fp_theme_dir     = get_stylesheet_directory() . '/lafka_plugin_templates/flatpickr_l10n/';
			$fp_theme_url_base = get_stylesheet_directory_uri() . '/lafka_plugin_templates/flatpickr_l10n/';
			foreach ( $fp_candidates as $fp_candidate ) {
				if ( file_exists( $fp_theme_dir . $fp_candidate ) ) {
					wp_register_script( 'flatpickr-l10n', $fp_theme_url_base . $fp_candidate, array( 'flatpickr' ), lafka_plugin_asset_version( 'assets/js/flatpickr/flatpickr.min.js' ), true );
					break;
				}
			}
		} else {
			wp_register_script( 'flatpickr-l10n', $fp_l10n_url_base . $fp_picked, array( 'flatpickr' ), lafka_plugin_asset_version( 'assets/js/flatpickr/l10n/' . $fp_picked ), true );
		}
		// Back-compat alias: 'flatpickr-local' is still referenced in shipping-areas.
		// If it is not already registered, alias it to the new handle (or skip when
		// no l10n file was found, as before).
		if ( wp_script_is( 'flatpickr-l10n', 'registered' ) && ! wp_script_is( 'flatpickr-local', 'registered' ) ) {
			$fp_l10n_obj = wp_scripts()->query( 'flatpickr-l10n', 'registered' );
			wp_register_script( 'flatpickr-local', $fp_l10n_obj->src, $fp_l10n_obj->deps, $fp_l10n_obj->ver, true );
		}

		wp_register_style( 'flatpickr', plugins_url( 'assets/js/flatpickr/flatpickr.min.css', __FILE__ ), array(), lafka_plugin_asset_version( 'assets/js/flatpickr/flatpickr.min.css' ) );

		// google maps — only when an API key is configured. Without a key the
		// loader returns 401 + a console error on every Geocoding/Places call.
		// Skip the registration so dependent enqueues fail-closed via the
		// `wp_script_is('lafka-google-maps','registered')` gate at each call
		// site (`lafka_map` shortcode, shipping-areas shortcode, branch
		// locations admin, etc.).
		if ( function_exists( 'lafka_get_option' ) ) {
			$lafka_maps_api_key = lafka_get_option( 'google_maps_api_key' );
			if ( ! empty( $lafka_maps_api_key ) ) {
				wp_register_script(
					'lafka-google-maps',
					'https://maps.googleapis.com/maps/api/js?key=' . rawurlencode( $lafka_maps_api_key ) . '&libraries=geometry,places&v=weekly&language=' . get_locale() . '&callback=Function.prototype',
					array( 'jquery' ),
					false,
					true
				);
			}
		}

		/**
		 * v9.12.0: Theme-URL fallback registrations are guarded so they only
		 * fire when the Lafka theme (parent or child) is the active stylesheet.
		 *
		 * Why: previously these `wp_register_*` calls used
		 * `get_template_directory_uri()` unconditionally — when this plugin
		 * is active alongside a NON-Lafka theme (operator switched themes,
		 * plugin used standalone, etc.), the registered URLs pointed to
		 * non-existent assets in the other theme's directory. If any
		 * plugin/theme then tried to enqueue these handles, the browser
		 * would 404 on magnific, isotope, etc.
		 *
		 * Guard: only register when the active theme template is 'lafka'.
		 */
		$lafka_theme_active = function_exists( 'wp_get_theme' ) && 'lafka' === (string) wp_get_theme()->get_template();
		if ( ! $lafka_theme_active ) {
			return;
		}

		// f105: flexslider, owl-carousel (+ theme-default/animate), cloud-zoom,
		// jquery-plugin and countdown were dead duplicate "fallback"
		// re-registrations — the Lafka theme already owns those handles (see
		// incl/system/core-functions.php). Removed.

		// P3-04: lafka-dialog (native <dialog> wrapper) — replaces magnific
		// for everything except the branch-locations modal (which still uses
		// magnific because its minified vendor file calls $.magnificPopup.open
		// and we don't have a source to migrate it from).
		$lafka_dialog_suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';
		wp_register_script( 'lafka-dialog', get_template_directory_uri() . '/js/lafka-dialog' . $lafka_dialog_suffix . '.js', array(), lafka_asset_version( '/js/lafka-dialog' . $lafka_dialog_suffix . '.js' ), true );
		wp_register_style( 'lafka-dialog', get_template_directory_uri() . '/styles/lafka-dialog.css', array(), lafka_asset_version( '/styles/lafka-dialog.css' ) );

		// Magnific stays registered — branch-locations.min.js (ordering critical
		// path) depends on it. It is no longer globally enqueued; only loads
		// when the branch-locations feature pulls it in via its deps array.
		wp_register_script( 'magnific', get_template_directory_uri() . '/js/magnific/jquery.magnific-popup.min.js', array( 'jquery' ), lafka_asset_version( '/js/magnific/jquery.magnific-popup.min.js' ), true );
		wp_register_style( 'magnific', get_template_directory_uri() . '/styles/magnific/magnific-popup.css', array(), lafka_asset_version( '/styles/magnific/magnific-popup.css' ) );

		// `appear` + `is-in-viewport` removed in P3-05 (theme migrated to native
		// IntersectionObserver via lafkaOnVisible). The vendor JS files no longer
		// ship with the theme, so registering them here would 404. Leaving the
		// handles unregistered: any caller that depends on them will get a
		// "doing it wrong" notice rather than a broken script tag.

		wp_register_script( 'typed', get_template_directory_uri() . '/js/typed.min.js', array(), lafka_asset_version( '/js/typed.min.js' ), true );

		wp_register_script( 'nice-select', get_template_directory_uri() . '/js/jquery.nice-select.min.js', array( 'jquery' ), lafka_asset_version( '/js/jquery.nice-select.min.js' ), true );

		// Isotope
		wp_register_script( 'isotope', get_template_directory_uri() . '/js/isotope/dist/isotope.pkgd.min.js', array( 'jquery', 'imagesloaded' ), lafka_asset_version( '/js/isotope/dist/isotope.pkgd.min.js' ), true );
	}

}

// Register scripts
add_action( 'admin_enqueue_scripts', 'lafka_register_admin_plugin_scripts' );
if ( ! function_exists( 'lafka_register_admin_plugin_scripts' ) ) {
	function lafka_register_admin_plugin_scripts() {
		// Flatpickr
		wp_register_script( 'flatpickr', plugins_url( 'assets/js/flatpickr/flatpickr.min.js', __FILE__ ), array( 'jquery' ), lafka_plugin_asset_version( 'assets/js/flatpickr/flatpickr.min.js' ), true );
		wp_register_style( 'flatpickr', plugins_url( 'assets/js/flatpickr/flatpickr.min.css', __FILE__ ), array(), lafka_plugin_asset_version( 'assets/js/flatpickr/flatpickr.min.css' ) );

		// Schedule
		wp_register_script(
			'lafka-schedule',
			plugins_url( 'assets/js/schedule/jquery.schedule.min.js', __FILE__ ),
			array(
				'jquery-ui-core',
				'jquery-ui-draggable',
				'jquery-ui-resizable',
			),
			lafka_plugin_asset_version( 'assets/js/schedule/jquery.schedule.min.js' ),
			true
		);
		wp_register_style( 'lafka-schedule', plugins_url( 'assets/css/schedule/jquery.schedule.min.css', __FILE__ ), array(), lafka_plugin_asset_version( 'assets/css/schedule/jquery.schedule.min.css' ) );

		// ajax upload files
		wp_enqueue_script( 'plupload' );
		$suffix = defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ? '' : '.min';
		wp_enqueue_script( 'lafka-plugin-admin', plugins_url( 'assets/js/lafka-plugin-admin' . $suffix . '.js', __FILE__ ), array( 'plupload' ), lafka_plugin_asset_version( 'assets/js/lafka-plugin-admin' . $suffix . '.js' ), true );
		wp_localize_script(
			'lafka-plugin-admin',
			'localise',
			array(
				'confirm_import_1'     => esc_html__( 'Confirm importing settings from', 'lafka-plugin' ),
				'confirm_import_2'     => esc_html__( '. Current Theme Options will be overwritten. Continue?', 'lafka-plugin' ),
				'import_success'       => esc_html__( 'Options successfully imported. Reloading.', 'lafka-plugin' ),
				'upload_error'         => esc_html__( 'There was a problem with the upload. Error', 'lafka-plugin' ),
				'export_url'           => esc_url( wp_nonce_url( add_query_arg( 'action', 'lafka_options_export', admin_url( 'admin-post.php' ) ), 'lafka_options_export' ) ),
				'options_upload_nonce' => wp_create_nonce( 'lafka_options_upload_nonce' ),
			)
		);

		$screen    = get_current_screen();
		$screen_id = $screen ? $screen->id : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- admin taxonomy screen detection from $_GET['taxonomy']; read-only display gating, no state mutation.
		if ( strstr( $screen_id, 'lafka_foodmenu_category' ) && ! empty( $_GET['taxonomy'] ) && in_array( wp_unslash( $_GET['taxonomy'] ), array( 'lafka_foodmenu_category' ), true ) ) {
			wp_register_script( 'lafka-plugin-term-ordering', plugins_url( 'assets/js/lafka-plugin-foodmenu-cat-ordering.js', __FILE__ ), array( 'jquery-ui-sortable' ), lafka_plugin_asset_version( 'assets/js/lafka-plugin-foodmenu-cat-ordering.js' ) );
			wp_enqueue_script( 'lafka-plugin-term-ordering' );
			wp_localize_script(
				'lafka-plugin-term-ordering',
				'lafka_cat_ordering',
				array(
					'nonce' => wp_create_nonce( 'lafka-foodmenu-cat-ordering' ),
				)
			);
			wp_enqueue_style( 'lafka-plugin-term-ordering-style', plugins_url( 'assets/css/lafka-plugin-term-ordering.css', __FILE__ ), array(), lafka_plugin_asset_version( 'assets/css/lafka-plugin-term-ordering.css' ) );
		}
		// google maps
		if ( function_exists( 'lafka_get_option' ) ) {
			wp_register_script( 'lafka-google-maps', 'https://maps.googleapis.com/maps/api/js?' . ( lafka_get_option( 'google_maps_api_key' ) ? 'key=' . lafka_get_option( 'google_maps_api_key' ) . '&' : '' ) . 'libraries=geometry&v=weekly&language=' . get_locale() . '&callback=Function.prototype', array( 'jquery' ), false, true );
		}
	}
}

// Enqueue the script for proper positioning the custom added font in vc edit form
add_filter( 'vc_edit_form_enqueue_script', 'lafka_enqueue_edit_form_scripts' );
if ( ! function_exists( 'lafka_enqueue_edit_form_scripts' ) ) {

	function lafka_enqueue_edit_form_scripts( $scripts ) {
		$scripts[] = plugin_dir_url( __FILE__ ) . 'assets/js/lafka-vc-edit-form.js';
		return $scripts;
	}

}

add_filter( 'vc_iconpicker-type-etline', 'lafka_vc_iconpicker_type_etline' );

/**
 * Elegant Icons Font icons
 *
 * @param $icons - taken from filter - vc_map param field settings['source'] provided icons (default empty array).
 * If array categorized it will auto-enable category dropdown
 *
 * @since 4.4
 * @return array - of icons for iconpicker, can be categorized, or not.
 */
if ( ! function_exists( 'lafka_vc_iconpicker_type_etline' ) ) {

	function lafka_vc_iconpicker_type_etline( $icons ) {
		// Categorized icons ( you can also output simple array ( key=> value ), where key = icon class, value = icon readable name ).
		$etline_icons = array(
			array( 'icon-mobile' => 'Mobile' ),
			array( 'icon-laptop' => 'Laptop' ),
			array( 'icon-desktop' => 'Desktop' ),
			array( 'icon-tablet' => 'Tablet' ),
			array( 'icon-phone' => 'Phone' ),
			array( 'icon-document' => 'Document' ),
			array( 'icon-documents' => 'Documents' ),
			array( 'icon-search' => 'Search' ),
			array( 'icon-clipboard' => 'Clipboard' ),
			array( 'icon-newspaper' => 'Newspaper' ),
			array( 'icon-notebook' => 'Notebook' ),
			array( 'icon-book-open' => 'Open' ),
			array( 'icon-browser' => 'Browser' ),
			array( 'icon-calendar' => 'Calendar' ),
			array( 'icon-presentation' => 'Presentation' ),
			array( 'icon-picture' => 'Picture' ),
			array( 'icon-pictures' => 'Pictures' ),
			array( 'icon-video' => 'Video' ),
			array( 'icon-camera' => 'Camera' ),
			array( 'icon-printer' => 'Printer' ),
			array( 'icon-toolbox' => 'Toolbox' ),
			array( 'icon-briefcase' => 'Briefcase' ),
			array( 'icon-wallet' => 'Wallet' ),
			array( 'icon-gift' => 'Gift' ),
			array( 'icon-bargraph' => 'Bargraph' ),
			array( 'icon-grid' => 'Grid' ),
			array( 'icon-expand' => 'Expand' ),
			array( 'icon-focus' => 'Focus' ),
			array( 'icon-edit' => 'Edit' ),
			array( 'icon-adjustments' => 'Adjustments' ),
			array( 'icon-ribbon' => 'Ribbon' ),
			array( 'icon-hourglass' => 'Hourglass' ),
			array( 'icon-lock' => 'Lock' ),
			array( 'icon-megaphone' => 'Megaphone' ),
			array( 'icon-shield' => 'Shield' ),
			array( 'icon-trophy' => 'Trophy' ),
			array( 'icon-flag' => 'Flag' ),
			array( 'icon-map' => 'Map' ),
			array( 'icon-puzzle' => 'Puzzle' ),
			array( 'icon-basket' => 'Basket' ),
			array( 'icon-envelope' => 'Envelope' ),
			array( 'icon-streetsign' => 'Streetsign' ),
			array( 'icon-telescope' => 'Telescope' ),
			array( 'icon-gears' => 'Gears' ),
			array( 'icon-key' => 'Key' ),
			array( 'icon-paperclip' => 'Paperclip' ),
			array( 'icon-attachment' => 'Attachment' ),
			array( 'icon-pricetags' => 'Pricetags' ),
			array( 'icon-lightbulb' => 'Lightbulb' ),
			array( 'icon-layers' => 'Layers' ),
			array( 'icon-pencil' => 'Pencil' ),
			array( 'icon-tools' => 'Tools' ),
			array( 'icon-tools-2' => '2' ),
			array( 'icon-scissors' => 'Scissors' ),
			array( 'icon-paintbrush' => 'Paintbrush' ),
			array( 'icon-magnifying-glass' => 'Glass' ),
			array( 'icon-circle-compass' => 'Compass' ),
			array( 'icon-linegraph' => 'Linegraph' ),
			array( 'icon-mic' => 'Mic' ),
			array( 'icon-strategy' => 'Strategy' ),
			array( 'icon-beaker' => 'Beaker' ),
			array( 'icon-caution' => 'Caution' ),
			array( 'icon-recycle' => 'Recycle' ),
			array( 'icon-anchor' => 'Anchor' ),
			array( 'icon-profile-male' => 'Male' ),
			array( 'icon-profile-female' => 'Female' ),
			array( 'icon-bike' => 'Bike' ),
			array( 'icon-wine' => 'Wine' ),
			array( 'icon-hotairballoon' => 'Hotairballoon' ),
			array( 'icon-globe' => 'Globe' ),
			array( 'icon-genius' => 'Genius' ),
			array( 'icon-map-pin' => 'Pin' ),
			array( 'icon-dial' => 'Dial' ),
			array( 'icon-chat' => 'Chat' ),
			array( 'icon-heart' => 'Heart' ),
			array( 'icon-cloud' => 'Cloud' ),
			array( 'icon-upload' => 'Upload' ),
			array( 'icon-download' => 'Download' ),
			array( 'icon-target' => 'Target' ),
			array( 'icon-hazardous' => 'Hazardous' ),
			array( 'icon-piechart' => 'Piechart' ),
			array( 'icon-speedometer' => 'Speedometer' ),
			array( 'icon-global' => 'Global' ),
			array( 'icon-compass' => 'Compass' ),
			array( 'icon-lifesaver' => 'Lifesaver' ),
			array( 'icon-clock' => 'Clock' ),
			array( 'icon-aperture' => 'Aperture' ),
			array( 'icon-quote' => 'Quote' ),
			array( 'icon-scope' => 'Scope' ),
			array( 'icon-alarmclock' => 'Alarmclock' ),
			array( 'icon-refresh' => 'Refresh' ),
			array( 'icon-happy' => 'Happy' ),
			array( 'icon-sad' => 'Sad' ),
			array( 'icon-facebook' => 'Facebook' ),
			array( 'icon-twitter' => 'Twitter' ),
			array( 'icon-googleplus' => 'Googleplus' ),
			array( 'icon-rss' => 'Rss' ),
			array( 'icon-tumblr' => 'Tumblr' ),
			array( 'icon-linkedin' => 'Linkedin' ),
			array( 'icon-dribbble' => 'Dribbble' ),
		);

		return array_merge( $icons, $etline_icons );
	}

}

add_filter( 'vc_iconpicker-type-flaticon', 'lafka_vc_iconpicker_type_flaticon' );

/**
 * Flaticon Icons Font icons
 *
 * @param $icons - taken from filter - vc_map param field settings['source'] provided icons (default empty array).
 * If array categorized it will auto-enable category dropdown
 *
 * @since 4.4
 * @return array - of icons for iconpicker, can be categorized, or not.
 */
if ( ! function_exists( 'lafka_vc_iconpicker_type_flaticon' ) ) {

	function lafka_vc_iconpicker_type_flaticon( $icons ) {
		// Categorized icons ( you can also output simple array ( key=> value ), where key = icon class, value = icon readable name ).
		$flaticon_icons = array(
			array( 'flaticon-001-popcorn' => 'popcorn' ),
			array( 'flaticon-002-tea' => 'tea' ),
			array( 'flaticon-003-chinese-food' => 'chinese food' ),
			array( 'flaticon-004-tomato-sauce' => 'tomato sauce' ),
			array( 'flaticon-005-cola-1' => 'cola 1' ),
			array( 'flaticon-006-burger-2' => 'burger 2' ),
			array( 'flaticon-007-burger-1' => 'burger 1' ),
			array( 'flaticon-008-fried-potatoes' => 'fried potatoes' ),
			array( 'flaticon-009-coffee' => 'coffee' ),
			array( 'flaticon-010-burger' => 'burger' ),
			array( 'flaticon-011-ice-cream-1' => 'ice cream 1' ),
			array( 'flaticon-012-cola' => 'cola' ),
			array( 'flaticon-013-milkshake' => 'milkshake' ),
			array( 'flaticon-014-sauces' => 'sauces' ),
			array( 'flaticon-015-hot-dog-1' => 'hotdog 1' ),
			array( 'flaticon-016-chicken-leg-1' => 'chicken leg 1' ),
			array( 'flaticon-017-croissant' => 'croissant' ),
			array( 'flaticon-018-cheese' => 'cheese' ),
			array( 'flaticon-019-sausage' => 'sausage' ),
			array( 'flaticon-020-fried-egg' => 'fried egg' ),
			array( 'flaticon-021-fried-chicken' => 'fried-chicken' ),
			array( 'flaticon-022-serving-dish' => 'serving dish' ),
			array( 'flaticon-023-pizza-slice' => 'pizza slice' ),
			array( 'flaticon-024-chef-hat' => 'chef hat' ),
			array( 'flaticon-025-meat' => 'meat' ),
			array( 'flaticon-026-ice-cream' => 'ice cream' ),
			array( 'flaticon-027-donut' => 'donut' ),
			array( 'flaticon-028-rice' => 'rice' ),
			array( 'flaticon-029-package' => 'package' ),
			array( 'flaticon-030-kebab' => 'kebab' ),
			array( 'flaticon-031-delivery' => 'delivery' ),
			array( 'flaticon-032-food-truck' => 'food truck' ),
			array( 'flaticon-033-waiter-1' => 'waiter 1' ),
			array( 'flaticon-034-waiter' => 'waiter' ),
			array( 'flaticon-035-taco' => 'taco' ),
			array( 'flaticon-036-chips' => 'chips' ),
			array( 'flaticon-037-soda' => 'soda' ),
			array( 'flaticon-038-take-away' => 'take away' ),
			array( 'flaticon-039-fork' => 'fork' ),
			array( 'flaticon-040-coffee-cup' => 'coffee cup' ),
			array( 'flaticon-041-waffle' => 'waffle' ),
			array( 'flaticon-042-beer' => 'beer' ),
			array( 'flaticon-043-chicken-leg' => 'chicken leg' ),
			array( 'flaticon-044-pitcher' => 'pitcher' ),
			array( 'flaticon-045-coffee-machine' => 'coffee machine' ),
			array( 'flaticon-046-noodles' => 'noodles' ),
			array( 'flaticon-047-menu' => 'menu' ),
			array( 'flaticon-048-hot-dog' => 'hot-dog' ),
			array( 'flaticon-049-breakfast' => 'breakfast' ),
			array( 'flaticon-050-french-fries' => 'french fries' ),
		);

		return array_merge( $icons, $flaticon_icons );
	}

}

if ( ! function_exists( 'lafka_foodmenu_category_field_search' ) ) {

	function lafka_foodmenu_category_field_search( $search_string ) {
		$data = array();

		$vc_taxonomies_types = array( 'lafka_foodmenu_category' );
		$vc_taxonomies       = get_terms(
			$vc_taxonomies_types,
			array(
				'hide_empty' => false,
				'search'     => $search_string,
			)
		);
		if ( is_array( $vc_taxonomies ) && ! empty( $vc_taxonomies ) ) {
			foreach ( $vc_taxonomies as $t ) {
				if ( is_object( $t ) ) {
					$data[] = vc_get_term_object( $t );
				}
			}
		}

		return $data;
	}

}

if ( ! function_exists( 'lafka_latest_posts_category_field_search' ) ) {

	function lafka_latest_posts_category_field_search( $search_string ) {
		$data = array();

		$vc_taxonomies_types = array( 'category' );
		$vc_taxonomies       = get_terms(
			$vc_taxonomies_types,
			array(
				'hide_empty' => false,
				'search'     => $search_string,
			)
		);
		if ( is_array( $vc_taxonomies ) && ! empty( $vc_taxonomies ) ) {
			foreach ( $vc_taxonomies as $t ) {
				if ( is_object( $t ) ) {
					$data[] = vc_get_term_object( $t );
				}
			}
		}

		return $data;
	}

}

// Contact form ajax actions
if ( ! function_exists( 'lafka_submit_contact' ) ) {

	function lafka_submit_contact() {

		check_ajax_referer( 'lafka_contactform', false, true );

		$unique_id = array_key_exists( 'unique_id', $_POST ) ? sanitize_text_field( $_POST['unique_id'] ) : '';
		$nonce     = array_key_exists( '_ajax_nonce', $_POST ) ? sanitize_text_field( $_POST['_ajax_nonce'] ) : '';

		ob_start();
		?>
		<script>
			//<![CDATA[
			"use strict";
			jQuery(document).ready(function () {
				var submitButton = jQuery('#holder_<?php echo esc_js( $unique_id ); ?> input:submit');
				var loader = jQuery('<img id="<?php echo esc_js( $unique_id ); ?>_loading_gif" class="lafka-contacts-loading" src="<?php echo esc_url( plugin_dir_url( __FILE__ ) ); ?>assets/image/contacts_ajax_loading.png" />').prependTo('#holder_<?php echo esc_attr( $unique_id ); ?> div.buttons div.left').hide();

				jQuery('#holder_<?php echo esc_js( $unique_id ); ?> form').ajaxForm({
					target: '#holder_<?php echo esc_js( $unique_id ); ?>',
					data: {
						// additional data to be included along with the form fields
						unique_id: '<?php echo esc_js( $unique_id ); ?>',
						action: 'lafka_submit_contact',
						_ajax_nonce: '<?php echo esc_js( $nonce ); ?>'
					},
					beforeSubmit: function (formData, jqForm, options) {
						// optionally process data before submitting the form via AJAX
						submitButton.hide();
						loader.show();
					},
					success: function (responseText, statusText, xhr, $form) {
						// code that's executed when the request is processed successfully
						loader.remove();
						submitButton.show();
					}
				});
			});
			//]]>
		</script>
		<?php
		require plugin_dir_path( __FILE__ ) . 'shortcodes/partials/contact-form.php';

		$output = ob_get_contents();
		ob_end_clean();

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $output is captured contact-form template render; all dynamic values escaped at construction in the partial.
		echo $output;
		wp_die();
	}

}

add_action( 'wp_ajax_lafka_submit_contact', 'lafka_submit_contact' );
add_action( 'wp_ajax_nopriv_lafka_submit_contact', 'lafka_submit_contact' );

//function to generate response
if ( ! function_exists( 'lafka_contact_form_generate_response' ) ) {

	function lafka_contact_form_generate_response( $type, $message ) {

		$lafka_contactform_response = '';

		if ( $type == 'success' ) {
			$lafka_contactform_response = "<div class='success-message'>" . esc_html( $message ) . '</div>';
		} else {
			$lafka_contactform_response .= "<div class='error-message'>" . esc_html( $message ) . '</div>';
		}

		return $lafka_contactform_response;
	}

}

if ( ! function_exists( 'lafka_share_links' ) ) {

	/**
	 * Displays social networks share links
	 *
	 * @param $title
	 * @param $link
	 */
	function lafka_share_links( $title, $link ) {

		$has_to_show_share = lafka_has_to_show_share();

		if ( $has_to_show_share ) {
			global $post;

			$media         = get_the_post_thumbnail_url( $post->ID, 'large' );
			$decoded_title = html_entity_decode( $title );

			// v9.7.24: filterable network list. Pre-fix the 5 hardcoded
			// networks (Facebook / Twitter / Pinterest / LinkedIn / VK)
			// were frozen circa 2015 — operators couldn't add WhatsApp,
			// Telegram, Mastodon, BlueSky, or even an email-this-page link
			// without forking. Now defaults include the modern essentials
			// and child plugins / themes hook the filter to extend.
			//
			// rawurlencode (not urlencode) for URL query params per RFC 3986;
			// esc_url() on the full href as defense-in-depth even though
			// hosts are hardcoded; HTTPS on every endpoint.
			//
			// Filter signature:
			//   apply_filters( 'lafka_share_networks',
			//     array $defaults, string $title, string $link, string $media )
			//   → array<string, array{ label:string, url:string }>
			$networks = (array) apply_filters(
				'lafka_share_networks',
				array(
					'facebook'  => array(
						'label' => esc_attr__( 'Share on Facebook', 'lafka-plugin' ),
						'url'   => 'https://www.facebook.com/sharer.php?u=' . rawurlencode( $link ) . '&t=' . rawurlencode( $decoded_title ),
					),
					'twitter'   => array(
						'label' => esc_attr__( 'Share on X (Twitter)', 'lafka-plugin' ),
						'url'   => 'https://twitter.com/share?text=' . rawurlencode( $decoded_title ) . '&url=' . rawurlencode( $link ),
					),
					'pinterest' => array(
						'label' => esc_attr__( 'Share on Pinterest', 'lafka-plugin' ),
						'url'   => 'https://pinterest.com/pin/create/button?media=' . rawurlencode( (string) $media ) . '&url=' . rawurlencode( $link ) . '&description=' . rawurlencode( $decoded_title ),
					),
					'linkedin'  => array(
						'label' => esc_attr__( 'Share on LinkedIn', 'lafka-plugin' ),
						'url'   => 'https://www.linkedin.com/shareArticle?url=' . rawurlencode( $link ) . '&title=' . rawurlencode( $decoded_title ),
					),
					'whatsapp'  => array(
						'label' => esc_attr__( 'Share on WhatsApp', 'lafka-plugin' ),
						// `wa.me` redirects to native app on mobile, web.whatsapp.com on desktop.
						'url'   => 'https://wa.me/?text=' . rawurlencode( $decoded_title . ' ' . $link ),
					),
					'telegram'  => array(
						'label' => esc_attr__( 'Share on Telegram', 'lafka-plugin' ),
						'url'   => 'https://t.me/share/url?url=' . rawurlencode( $link ) . '&text=' . rawurlencode( $decoded_title ),
					),
					'email'     => array(
						'label' => esc_attr__( 'Share by email', 'lafka-plugin' ),
						'url'   => 'mailto:?subject=' . rawurlencode( $decoded_title ) . '&body=' . rawurlencode( $link ),
					),
					'vkontakte' => array(
						// Legacy network kept for back-compat with existing CSS overrides.
						'label' => esc_attr__( 'Share on VK', 'lafka-plugin' ),
						'url'   => 'https://vk.com/share.php?url=' . rawurlencode( $link ) . '&title=' . rawurlencode( $decoded_title ) . '&image=' . rawurlencode( (string) $media ),
					),
				),
				$decoded_title,
				$link,
				(string) $media
			);

			$share_links_html = '<span>' . esc_html__( 'Share', 'lafka-plugin' ) . ':</span>';
			foreach ( $networks as $key => $net ) {
				if ( ! is_array( $net ) || empty( $net['url'] ) || empty( $net['label'] ) ) {
					continue;
				}
				$share_links_html .= sprintf(
					'<a class="lafka-share-%s" title="%s" href="%s" target="_blank" rel="noopener noreferrer"><span class="screen-reader-text">%s</span></a>',
					esc_attr( $key ),
					esc_attr( $net['label'] ),
					esc_url( $net['url'] ),
					esc_html( $net['label'] )
				);
			}

			// Each <a> built above is fully escaped; wp_kses_post on the
			// container is defense-in-depth for any future addition that
			// might forget per-piece escaping.
			echo '<div class="lafka-share-links">' . wp_kses_post( $share_links_html ) . '<div class="clear"></div></div>';
		}
	}
}

add_action( 'wp_head', 'lafka_insert_og_tags' );
if ( ! function_exists( 'lafka_insert_og_tags' ) ) {
	/**
	 * Emit OpenGraph + Twitter Card tags on every public page.
	 * P6-SEO-5: full coverage (was og:image only).
	 *
	 * v9.22.2 image fallback chain (first non-empty wins):
	 *   1. Per-post `_lafka_og_image` post meta (manual override on any page).
	 *   2. Featured image of the singular post/product.
	 *   3. Customizer `lafka_og_image_default` (operator-pinned hero photo).
	 *   4. Site icon (last-resort fallback).
	 *
	 * Without the Customizer default, archive pages like /menu/ and
	 * /contact-us/ emitted no `og:image` at all — bad social-share previews.
	 *
	 * v9.22.2 locale: emit goes through `lafka_og_locale` filter; operator
	 * can pin a non-WP-Settings locale (e.g. en_CA when Site Language is
	 * still en_US) via Customizer `lafka_default_locale`. Same value drives
	 * `<html lang>` via the language_attributes filter below.
	 */
	function lafka_insert_og_tags() {
		if ( is_admin() || is_feed() || is_404() ) {
			return;
		}

		/*
		 * Defer to a dedicated SEO plugin (Yoast / Rank Math / SEOPress /
		 * AIOSEO) when one is active — it already emits a full set of
		 * og:* / twitter:* tags. Emitting ours alongside theirs duplicates
		 * the OpenGraph/Twitter Card metadata on every public page and
		 * confuses social scrapers. Mirrors the JSON-LD @graph deferral in
		 * incl/schema/class-lafka-json-ld.php so a single "an SEO plugin
		 * owns head metadata" decision (lafka_seo_plugin_active()) governs
		 * all head emitters.
		 *
		 * Operators who want Lafka's tags regardless can override via the
		 * `lafka_head_meta_force_emit` filter (return true) — the head-meta
		 * sibling of `lafka_schema_force_emit`.
		 */
		if ( lafka_seo_plugin_active() && ! (bool) apply_filters( 'lafka_head_meta_force_emit', false ) ) {
			return;
		}

		global $post;

		// ===== Resolve title / description / URL / image / type per context =====
		// Front-page check MUST come before is_singular() — when the homepage is a
		// static page (Settings → Reading), both are true. We want the front-page
		// branch to win so the homepage carries og:type=restaurant.restaurant and
		// og:title=site-name (not the page's literal title like "Home New").
		// We still pass $post to the description resolver so any per-page
		// _lafka_meta_description override is honored on the static front page.
		// Resolve image URL + actual width/height. Pre-v9.7.24 the dimensions
		// were always WP's `large_size_w`/`large_size_h` option (default
		// 1024×1024) regardless of the actual image — so a portrait 800×1200
		// thumbnail emitted og:image:width=1024, og:image:height=1024,
		// causing Facebook/LinkedIn/Slack to crop badly or compute wrong
		// aspect ratios in cached previews.
		//
		// Now we look up the actual image src array via
		// wp_get_attachment_image_src(), which returns [url, width, height,
		// is_intermediate]. For site-icon fallback we know the requested
		// size (1200×1200 — site icons are square).
		$image        = '';
		$image_width  = 0;
		$image_height = 0;

		$resolve_post_image = static function ( $post_id ) use ( &$image, &$image_width, &$image_height ) {
			// Tier 1: per-post override via `_lafka_og_image` post meta.
			// Stored as either a numeric attachment ID or a raw URL.
			$override = get_post_meta( (int) $post_id, '_lafka_og_image', true );
			if ( $override ) {
				if ( is_numeric( $override ) ) {
					$src = wp_get_attachment_image_src( (int) $override, 'large' );
					if ( is_array( $src ) && ! empty( $src[0] ) ) {
						$image        = (string) $src[0];
						$image_width  = (int) ( $src[1] ?? 0 );
						$image_height = (int) ( $src[2] ?? 0 );
						return;
					}
				} else {
					$image = (string) $override;
					// Unknown dimensions — emitter will skip width/height tags.
					return;
				}
			}
			// Tier 2: featured image of the singular post/product.
			$thumb_id = (int) get_post_thumbnail_id( $post_id );
			if ( ! $thumb_id ) {
				return;
			}
			$src = wp_get_attachment_image_src( $thumb_id, 'large' );
			if ( ! is_array( $src ) || empty( $src[0] ) ) {
				return;
			}
			$image        = (string) $src[0];
			$image_width  = (int) ( $src[1] ?? 0 );
			$image_height = (int) ( $src[2] ?? 0 );
		};

		if ( is_front_page() || is_home() ) {
			$title       = get_bloginfo( 'name' );
			$description = lafka_resolve_meta_description( ( is_singular() && $post ) ? $post : null );
			$url         = home_url( '/' );
			if ( is_singular() && $post ) {
				$resolve_post_image( $post->ID );
			}
			$og_type     = 'restaurant.restaurant';
		} elseif ( is_singular() && $post ) {
			$title       = get_the_title( $post );
			$description = lafka_resolve_meta_description( $post );
			$url         = get_permalink( $post );
			$resolve_post_image( $post->ID );
			$og_type     = ( function_exists( 'is_product' ) && is_product() ) ? 'product' : 'article';
		} elseif ( is_tax() || is_category() || is_tag() ) {
			$term        = get_queried_object();
			$title       = $term ? $term->name : get_bloginfo( 'name' );
			$description = $term && ! empty( $term->description ) ? wp_strip_all_tags( $term->description ) : lafka_resolve_meta_description( null );
			$url         = $term ? get_term_link( $term ) : home_url( '/' );
			$og_type     = 'website';
		} else {
			$title       = wp_get_document_title();
			$description = lafka_resolve_meta_description( null );
			$url         = home_url( add_query_arg( null, null ) );
			$og_type     = 'website';
		}

		// Tier 3: Customizer-pinned default OG image. Applies on any page
		// that fell through tiers 1+2 (archives, /menu/, /contact-us/,
		// homepage without featured image, etc).
		if ( '' === $image ) {
			$og_default = get_theme_mod( 'lafka_og_image_default', '' );
			if ( '' !== $og_default && null !== $og_default ) {
				if ( is_numeric( $og_default ) ) {
					$src = wp_get_attachment_image_src( (int) $og_default, 'large' );
					if ( is_array( $src ) && ! empty( $src[0] ) ) {
						$image        = (string) $src[0];
						$image_width  = (int) ( $src[1] ?? 0 );
						$image_height = (int) ( $src[2] ?? 0 );
					}
				} else {
					$image = (string) $og_default;
					// String URL — dimensions unknown, width/height tags skipped.
				}
			}
		}

		// Tier 4 (last resort): site icon. Square, low resolution — still
		// better than no preview at all.
		if ( '' === $image && function_exists( 'get_site_icon_url' ) ) {
			$icon = get_site_icon_url( 1200 );
			if ( $icon ) {
				$image        = $icon;
				$image_width  = 1200; // site icons are always square at the requested size.
				$image_height = 1200;
			}
		}

		$site_name = get_bloginfo( 'name' );

		// Locale: Customizer default (operator-pinned) takes precedence over
		// WP Settings → General → Site Language. Output normalized to "xx_YY"
		// (underscore, not hyphen). The `lafka_og_locale` filter lets a
		// theme/plugin override per-request without touching settings.
		$customizer_locale = (string) get_theme_mod( 'lafka_default_locale', '' );
		$locale            = '' !== $customizer_locale
			? str_replace( '-', '_', $customizer_locale )
			: str_replace( '-', '_', get_locale() );
		$locale            = (string) apply_filters( 'lafka_og_locale', $locale );

		// ===== Emit =====
		printf( '<meta property="og:title" content="%s">' . "\n", esc_attr( $title ) );
		printf( '<meta property="og:description" content="%s">' . "\n", esc_attr( $description ) );
		printf( '<meta property="og:url" content="%s">' . "\n", esc_url( $url ) );
		printf( '<meta property="og:type" content="%s">' . "\n", esc_attr( $og_type ) );
		printf( '<meta property="og:site_name" content="%s">' . "\n", esc_attr( $site_name ) );
		printf( '<meta property="og:locale" content="%s">' . "\n", esc_attr( $locale ) );

		if ( $image ) {
			printf( '<meta property="og:image" content="%s">' . "\n", esc_url( $image ) );
			// Emit dimensions only when we actually know them — emitting wrong
			// dimensions is worse than omitting them (crawlers fall back to
			// fetching+measuring vs trusting bad metadata).
			if ( $image_width > 0 && $image_height > 0 ) {
				printf( '<meta property="og:image:width" content="%d">' . "\n", (int) $image_width );
				printf( '<meta property="og:image:height" content="%d">' . "\n", (int) $image_height );
			}
		}

		printf( '<meta name="twitter:card" content="%s">' . "\n", $image ? 'summary_large_image' : 'summary' );
		printf( '<meta name="twitter:title" content="%s">' . "\n", esc_attr( $title ) );
		printf( '<meta name="twitter:description" content="%s">' . "\n", esc_attr( $description ) );
		if ( $image ) {
			printf( '<meta name="twitter:image" content="%s">' . "\n", esc_url( $image ) );
		}
	}
}

/**
 * Drive <html lang="…"> from the Customizer `lafka_default_locale` setting
 * (v9.22.2). Without this filter the WP core uses Settings → General →
 * Site Language. Operators on `en_US` WP installs that serve a Canadian
 * audience can pin `en_CA` (or any other locale) via the Customizer
 * "Social Sharing" section without wrangling WP Settings.
 *
 * Frontend only — admin keeps the WP core locale for back-office i18n.
 */
add_filter( 'language_attributes', 'lafka_filter_language_attributes', 10, 2 );
if ( ! function_exists( 'lafka_filter_language_attributes' ) ) {
	/**
	 * @param string $output Existing attribute string e.g. `lang="en-US"`.
	 * @param string $doctype Either 'html' or 'xhtml'.
	 */
	function lafka_filter_language_attributes( $output, $doctype = 'html' ) {
		if ( is_admin() ) {
			return $output;
		}
		if ( ! function_exists( 'get_theme_mod' ) ) {
			return $output;
		}
		$override = (string) get_theme_mod( 'lafka_default_locale', '' );
		if ( '' === $override ) {
			return $output;
		}
		// Allow plugins/themes to short-circuit. Mirror the OG filter name
		// for discoverability — same setting drives both surfaces.
		$override = (string) apply_filters( 'lafka_og_locale', str_replace( '-', '_', $override ) );
		// `<html lang>` uses hyphen form per BCP-47 (e.g. en-CA), so flip
		// the underscore the filter normalised on.
		$lang_attr = str_replace( '_', '-', $override );
		$replacement = sprintf( 'lang="%s"', esc_attr( $lang_attr ) );
		// Replace any existing lang="…" attribute; append when absent.
		if ( preg_match( '/\blang="[^"]*"/', $output ) ) {
			$output = preg_replace( '/\blang="[^"]*"/', $replacement, $output, 1 );
		} else {
			$output = trim( $output . ' ' . $replacement );
		}
		return $output;
	}
}

add_action( 'wp_head', 'lafka_render_meta_description', 1 );
if ( ! function_exists( 'lafka_render_meta_description' ) ) {
	/**
	 * Emit <meta name="description"> from per-post override or context-specific default.
	 * P6-SEO-4 + P6-SEO-5: replaces silent absence of meta description.
	 */
	function lafka_render_meta_description() {
		if ( is_admin() || is_feed() || is_404() ) {
			return;
		}

		/*
		 * Defer to a dedicated SEO plugin when active — it emits its own
		 * <meta name="description">. See lafka_insert_og_tags() for the full
		 * rationale; the same `lafka_head_meta_force_emit` override applies so
		 * the deferral decision stays consistent across all head emitters.
		 */
		if ( lafka_seo_plugin_active() && ! (bool) apply_filters( 'lafka_head_meta_force_emit', false ) ) {
			return;
		}

		global $post;
		$desc = lafka_resolve_meta_description( is_singular() && $post ? $post : null );
		if ( $desc ) {
			printf( '<meta name="description" content="%s">' . "\n", esc_attr( $desc ) );
		}
	}
}

if ( ! function_exists( 'lafka_resolve_meta_description' ) ) {
	/**
	 * Resolution order (first non-empty wins):
	 *   1. Per-post `_lafka_meta_description` post meta (manual override).
	 *   2. WC product short description (single product).
	 *   3. Post excerpt (any singular).
	 *   4. WC term description (taxonomy archive).
	 *   5. Site tagline (Settings → General → Tagline).
	 *   6. Restaurant Information description (Customizer panel) — final fallback
	 *      so the homepage gets a meaningful <meta name="description"> even when
	 *      the operator hasn't set a tagline yet. Without this, fresh installs
	 *      ship with no meta description at all, capping Lighthouse SEO ≤92.
	 *   7. Constructed local-business pitch from name + servedCuisine + locality.
	 */
	function lafka_resolve_meta_description( $post_or_null ) {
		if ( $post_or_null ) {
			$override = get_post_meta( $post_or_null->ID, '_lafka_meta_description', true );
			if ( $override ) {
				return $override;
			}
			if ( function_exists( 'is_product' ) && is_product() ) {
				$product = wc_get_product( $post_or_null->ID );
				if ( $product && $product->get_short_description() ) {
					return wp_strip_all_tags( $product->get_short_description() );
				}
			}
			if ( ! empty( $post_or_null->post_excerpt ) ) {
				return wp_strip_all_tags( $post_or_null->post_excerpt );
			}
		}
		if ( is_tax() || is_category() || is_tag() ) {
			$term = get_queried_object();
			if ( $term && ! empty( $term->description ) ) {
				return wp_strip_all_tags( $term->description );
			}
		}
		$tagline = get_bloginfo( 'description' );
		if ( $tagline ) {
			// WP defaults the tagline to either "Just another WordPress site"
			// or the site name on some installs. Either case produces a
			// useless meta description that competes with — and loses to —
			// the Restaurant Information description the operator can
			// configure. Skip the tagline when it's the default WP boilerplate
			// or a verbatim duplicate of the site name, so the next
			// fallback gets a chance.
			$site_name      = function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'name' ) : '';
			$is_wp_default  = 'Just another WordPress site' === $tagline;
			$is_dupe_of_nam = '' !== $site_name && 0 === strcasecmp( trim( $tagline ), trim( $site_name ) );
			if ( ! $is_wp_default && ! $is_dupe_of_nam ) {
				return $tagline;
			}
		}
		if ( function_exists( 'lafka_get_restaurant_info' ) ) {
			$info = lafka_get_restaurant_info();
			if ( ! empty( $info['description'] ) ) {
				return wp_strip_all_tags( $info['description'] );
			}
			// Construct a sensible auto-pitch when the operator has set NAP but
			// not a description: "{name} — fresh {cuisine} in {locality}".
			$bits = array();
			if ( ! empty( $info['name'] ) ) {
				$bits[] = $info['name'];
			}
			// Read the flat keys that lafka_get_restaurant_info() actually
			// returns (`cuisines`, `city`). The legacy code looked for
			// `servedCuisine` and `address.addressLocality`, which never
			// existed in the array — making the pitch always collapse to
			// just `name`. Fixed in v9.22.1.
			$cuisine = '';
			if ( ! empty( $info['cuisines'] ) ) {
				$cuisine = is_array( $info['cuisines'] )
					? implode( ', ', array_map( 'strval', $info['cuisines'] ) )
					: (string) $info['cuisines'];
			}
			$locality = ! empty( $info['city'] ) ? (string) $info['city'] : '';
			if ( $cuisine && $locality ) {
				$bits[] = sprintf(
					/* translators: 1: cuisine list, 2: city/locality */
					__( 'Fresh %1$s in %2$s — order online or call.', 'lafka-plugin' ),
					$cuisine,
					$locality
				);
			} elseif ( $locality ) {
				$bits[] = sprintf(
					/* translators: %s: city/locality */
					__( 'Serving %s — order online or call.', 'lafka-plugin' ),
					$locality
				);
			}
			if ( ! empty( $bits ) ) {
				return implode( ' — ', $bits );
			}
		}
		return '';
	}
}

if ( ! function_exists( 'lafka_has_to_show_share' ) ) {
	function lafka_has_to_show_share() {

		if ( function_exists( 'lafka_get_option' ) ) {
			$general_option         = get_option( 'lafka_share_on_posts' ) === 'yes';
			$general_option_product = get_option( 'lafka_share_on_products' ) === 'yes';
			$single_meta            = get_post_meta( get_the_ID(), 'lafka_show_share', true );

			$target = 'single';
			if ( function_exists( 'is_product' ) && is_product() ) {
				$target = 'product';
			}

			$has_to_show_share = false;

			if ( $target === 'single' && $single_meta === 'yes' ) {
				$has_to_show_share = true;
			} elseif ( $target === 'single' && $general_option && $single_meta !== 'no' ) {
				$has_to_show_share = true;
			} elseif ( $target === 'product' && $general_option_product ) {
				$has_to_show_share = true;
			}

			return $has_to_show_share;
		}

		return false;
	}
}

add_action( 'woocommerce_single_product_summary', 'lafka_show_custom_product_popup_link', 12 );
if ( ! function_exists( 'lafka_show_custom_product_popup_link' ) ) {
	function lafka_show_custom_product_popup_link() {
		if ( function_exists( 'lafka_get_option' ) && trim( lafka_get_option( 'custom_product_popup_link' ) ) !== '' && trim( lafka_get_option( 'custom_product_popup_content' ) ) !== '' ) {
			global $product;

			$link_text     = lafka_get_option( 'custom_product_popup_link' );
			$popup_content = lafka_get_option( 'custom_product_popup_content' );

			echo '<div class="lafka-product-popup-link"><a href="#lafka-product-' . esc_attr( $product->get_id() ) . '-popup-content" title="' . esc_attr( $link_text ) . '" >' . esc_html( $link_text ) . '</a></div>';

			echo '<div id="lafka-product-' . esc_attr( $product->get_id() ) . '-popup-content" class="mfp-hide">';
			echo wp_kses_post( do_shortcode( $popup_content ) );
			echo '</div>';

			// P3-04: product-popup-link migrated from magnificPopup to lafkaDialog.
			// Pure vanilla — no jQuery dependency. Click handler matches the same
			// `.lafka-product-popup-link a` selector and opens the linked anchor
			// element's content inside a native <dialog>.
			$inline_script_data = "(function () {
                document.addEventListener('click', function (e) {
                    var link = e.target.closest('.lafka-product-popup-link a');
                    if (!link || !window.lafkaDialog) { return; }
                    var href = link.getAttribute('href') || '';
                    if (href.charAt(0) !== '#') { return; }
                    var src = document.querySelector(href);
                    if (!src) { return; }
                    e.preventDefault();
                    window.lafkaDialog.inline(src.innerHTML, { className: 'lafka-product-popup-content' });
                });
            })();";

			wp_add_inline_script( 'lafka-dialog', $inline_script_data );

		}
	}
}

// Promo info tooltips
add_action(
	'woocommerce_single_product_summary',
	function () {
		lafka_output_info_tooltips( 'above-price' );
	},
	9
);
add_action(
	'woocommerce_single_product_summary',
	function () {
		lafka_output_info_tooltips( 'below-price' );
	},
	11
);
add_action(
	'woocommerce_single_product_summary',
	function () {
		lafka_output_info_tooltips( 'below-add-to-cart' );
	},
	39
);
add_action(
	'woocommerce_after_shop_loop_item_title',
	function () {
		lafka_output_info_tooltips( '', true );
	},
	11
);

if ( ! function_exists( 'lafka_output_info_tooltips' ) ) {
	function lafka_output_info_tooltips( $position, $show_in_listing = false ) {
		for ( $i = 1; $i <= 3; $i++ ) {
			if ( function_exists( 'lafka_get_option' ) && lafka_get_option( 'promo_tooltip_' . $i . '_trigger_text' ) && ( $position === lafka_get_option( 'promo_tooltip_' . $i . '_position' ) || $show_in_listing && lafka_get_option( 'promo_tooltip_' . $i . '_show_in_listing' ) ) ) {
				?>
				<div class="lafka-promo-wrapper
				<?php
				if ( $position ) {
					echo ' lafka-promo-' . esc_attr( $position );}
				?>
				">
					<div class="lafka-promo-text">
						<?php echo wp_kses_post( lafka_get_option( 'promo_tooltip_' . $i . '_text' ) ); ?>
						<span class="lafka-promo-trigger">
							<?php echo wp_kses_post( lafka_get_option( 'promo_tooltip_' . $i . '_trigger_text' ) ); ?>
							<span class="lafka-promo-content">
								<?php echo wp_kses_post( lafka_get_option( 'promo_tooltip_' . $i . '_content' ) ); ?>
							</span>
						</span>
					</div>
				</div>
				<?php
			}
		}
	}
}

// Import theme options
add_action( 'wp_ajax_lafka_options_upload', 'lafka_options_upload' );
if ( ! function_exists( 'lafka_options_upload' ) ) {
	function lafka_options_upload() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Unauthorized', 'lafka-plugin' ) ), 403 );
		}

		check_ajax_referer( 'lafka_options_upload_nonce', 'security' );

		if ( ! isset( $_FILES['file'] ) || ! is_array( $_FILES['file'] ) || empty( $_FILES['file']['tmp_name'] ) ) {
			wp_send_json_error( array( 'message' => __( 'No file provided', 'lafka-plugin' ) ), 400 );
		}

		$file = $_FILES['file'];

		if ( ! empty( $file['error'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Upload failed', 'lafka-plugin' ) ), 400 );
		}

		// Size cap — settings exports are small text/JSON files; 5MB is far more than enough.
		$max_size = 5 * MB_IN_BYTES;
		if ( ! empty( $file['size'] ) && (int) $file['size'] > $max_size ) {
			wp_send_json_error( array( 'message' => __( 'File too large (5 MB max)', 'lafka-plugin' ) ), 400 );
		}

		// Server-side MIME sniff (do not trust client-supplied $_FILES['file']['type']).
		$allowed_types = array( 'application/json', 'text/plain', 'application/xml', 'text/xml' );
		$detected_type = '';
		if ( function_exists( 'finfo_open' ) ) {
			$finfo = finfo_open( FILEINFO_MIME_TYPE );
			if ( $finfo ) {
				$detected_type = (string) finfo_file( $finfo, $file['tmp_name'] );
				finfo_close( $finfo );
			}
		}
		if ( $detected_type && ! in_array( $detected_type, $allowed_types, true ) ) {
			wp_send_json_error(
				array( 'message' => sprintf( /* translators: %s: detected MIME type */ __( 'Invalid file type (%s). Only JSON/XML/plain text are allowed.', 'lafka-plugin' ), $detected_type ) ),
				400
			);
		}

		// Confirm the uploaded file is in fact an uploaded file (not a path-traversal attempt).
		if ( ! is_uploaded_file( $file['tmp_name'] ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid upload', 'lafka-plugin' ) ), 400 );
		}

		$lafka_transfer_content = Lafka_Transfer_Content::getInstance();
		$result                 = $lafka_transfer_content->importSettings( $file['tmp_name'], false, false, false, true );
		wp_send_json_success( $result );
	}
}

// Export theme options
add_action( 'admin_post_lafka_options_export', 'lafka_options_export' );
if ( ! function_exists( 'lafka_options_export' ) ) {
	function lafka_options_export() {
		// Capability + CSRF check before doing any work.
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to export theme options.', 'lafka-plugin' ), 403 );
		}
		check_admin_referer( 'lafka_options_export' );

		$lafka_transfer_content = Lafka_Transfer_Content::getInstance();
		$export_file_path       = $lafka_transfer_content->exportThemeOptions();

		// Defense in depth: require export to live inside the expected directory so a
		// compromised exportThemeOptions() can't be used to readfile() arbitrary paths.
		$export_dir   = realpath( get_template_directory() . '/store/settings' );
		$resolved     = $export_file_path ? realpath( $export_file_path ) : false;
		$path_is_safe = $resolved && $export_dir && str_starts_with( $resolved, $export_dir . DIRECTORY_SEPARATOR );

		if ( $path_is_safe && file_exists( $resolved ) ) {
			nocache_headers();
			header( 'Content-Description: File Transfer' );
			header( 'Content-Type: application/octet-stream' );
			header( 'Content-Disposition: attachment; filename="' . basename( $resolved ) . '"' );
			header( 'Content-Length: ' . filesize( $resolved ) );
			readfile( $resolved );
			// Best-effort cleanup so /store/settings/ doesn't fill up.
			@unlink( $resolved );
			exit;
		}

		wp_safe_redirect( admin_url( 'admin.php?page=lafka-optionsframework' ) );
		exit;
	}
}

// Allow safe HTML descriptions in WordPress Menu (related to Mega menu)
remove_filter( 'nav_menu_description', 'strip_tags' );
add_filter( 'nav_menu_description', 'wp_kses_post' );

// Allow Shortcodes in the Excerpt field (only when shortcode brackets are present)
add_filter(
	'the_excerpt',
	function ( $excerpt ) {
		if ( false !== strpos( $excerpt, '[' ) ) {
			return do_shortcode( $excerpt );
		}
		return $excerpt;
	}
);

add_action( 'after_setup_theme', 'lafka_after_setup_theme' );
if ( ! function_exists( 'lafka_after_setup_theme' ) ) {
	/**
	 * Doing stuff which require theme to be loaded so we have 'lafka_get_option' function available etc.
	 */
	function lafka_after_setup_theme() {
		// Move product taxonomy description below products if 'category_description_position' = 'bottom'
		if ( function_exists( 'lafka_get_option' ) && lafka_get_option( 'category_description_position' ) === 'lafka-bottom-description' ) {
			remove_action( 'woocommerce_archive_description', 'woocommerce_taxonomy_archive_description', 10 );
			add_action( 'woocommerce_after_main_content', 'woocommerce_taxonomy_archive_description', 1 );
		}
	}
}

add_filter( 'script_loader_tag', 'lafka_defer_script_loader_tags', 10, 3 );
if ( ! function_exists( 'lafka_defer_script_loader_tags' ) ) {
	/**
	 * Add async to script tags with defined handles.
	 *
	 * @param string $tag HTML for the script tag.
	 * @param string $handle Handle of script.
	 * @param string $src Src of script.
	 *
	 * @return string
	 */
	function lafka_defer_script_loader_tags( $tag, $handle, $src ) {
		if ( ! in_array( $handle, array( 'lafka-google-maps' ), true ) ) {
			return $tag;
		}

		return str_replace( ' src', ' defer src', $tag );
	}
}

add_filter( 'sgo_js_async_exclude', 'lafka_js_async_exclude' );
if ( ! function_exists( 'lafka_js_async_exclude' ) ) {
	function lafka_js_async_exclude( $exclude_list ) {
		$exclude_list[] = 'lafka-google-maps';

		return $exclude_list;
	}
}

add_shortcode( 'lafka_nap', 'lafka_nap_shortcode' );
if ( ! function_exists( 'lafka_nap_shortcode' ) ) {
	/**
	 * P6-UX-5 + W2-T1: canonical NAP block. Reads from lafka_get_restaurant_info()
	 * via lafka_schema_get_nap() — the single source-of-truth shared with the
	 * JSON-LD module and the editorial templates. Operator content flows from
	 * the Customizer panel "Lafka — Restaurant Information".
	 *
	 * Usage:
	 *   [lafka_nap]                       // full address block
	 *   [lafka_nap part="address"]        // address line only
	 *   [lafka_nap part="phone"]          // tap-to-call phone link
	 *   [lafka_nap part="name"]           // restaurant name
	 *   [lafka_nap part="street"]         // street address
	 *   [lafka_nap part="city"]           // city
	 *   [lafka_nap part="region"]         // region/state
	 *   [lafka_nap part="postal"]         // postal/ZIP code
	 */
	function lafka_nap_shortcode( $atts ) {
		$atts = shortcode_atts( array( 'part' => 'all' ), $atts, 'lafka_nap' );

		// Delegate to the canonical helper — Customizer-driven.
		$nap = lafka_schema_get_nap();

		$name   = $nap['name'];
		$street = $nap['street'];
		$city   = $nap['city'];
		$region = $nap['region'];
		$postal = $nap['postal'];
		$phone  = $nap['telephone_display'];
		$tel    = $nap['telephone'];

		$address_parts = array_filter( array( $street, trim( $city . ', ' . $region . ' ' . $postal, ' ,' ) ) );
		$address       = implode( ', ', $address_parts );

		switch ( $atts['part'] ) {
			case 'name':
				return esc_html( $name );
			case 'address':
				return esc_html( $address );
			case 'street':
				return esc_html( $street );
			case 'city':
				return esc_html( $city );
			case 'region':
				return esc_html( $region );
			case 'postal':
				return esc_html( $postal );
			case 'phone':
				if ( '' === $tel ) {
					return '';
				}
				return sprintf(
					'<a href="tel:%s">%s</a>',
					esc_attr( $tel ),
					esc_html( $phone )
				);
			case 'all':
			default:
				$phone_html = '';
				if ( '' !== $tel ) {
					$phone_html = sprintf(
						'<br><a href="tel:%s">%s</a>',
						esc_attr( $tel ),
						esc_html( $phone )
					);
				}
				return sprintf(
					'<address class="lafka-nap"><strong>%s</strong><br>%s%s</address>',
					esc_html( $name ),
					esc_html( $address ),
					$phone_html
				);
		}
	}
}
