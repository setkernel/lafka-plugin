<?php
/**
 * Phase 1A: Analytics tag emit layer.
 *
 * Reads Customizer settings registered by
 * incl/customizer/class-lafka-customizer-analytics.php and emits:
 *   - Google Consent Mode v2 default state (priority 1 on wp_head — MUST run before any tag)
 *   - dataLayer init (priority 1)
 *   - GSC verification meta (priority 1)
 *   - GTM container head snippet (priority 2) OR direct platform snippets
 *   - GTM noscript iframe via wp_body_open (with wp_footer fallback)
 *   - Consent banner HTML + JS when enabled AND a tracking destination is
 *     configured (lafka_analytics_is_active())
 *
 * Override-not-additive: when `lafka_gtm_container_id` is set, the direct
 * GA4 / Clarity / Meta Pixel emitters no-op so a single page never double-
 * fires (operator wires those platforms inside GTM instead).
 *
 * All emitted IDs pass through esc_attr / esc_js / esc_html as appropriate;
 * banner copy goes through wp_kses_post; GSC token through esc_attr.
 *
 * @package Lafka\Plugin\Analytics
 * @since   9.23.0
 */

defined( 'ABSPATH' ) || exit;

// ============================================================================
// Customizer accessors — single source of truth for option reads.
// ============================================================================

if ( ! function_exists( 'lafka_analytics_get_setting' ) ) {
	/**
	 * Read a Customizer theme_mod with a default fallback.
	 *
	 * Wraps get_theme_mod() so tests can stub a single function and emit
	 * code can stay readable.
	 *
	 * @param string $key
	 * @param string $default
	 * @return string
	 */
	function lafka_analytics_get_setting( string $key, string $default = '' ): string {
		$value = function_exists( 'get_theme_mod' ) ? get_theme_mod( $key, $default ) : $default;
		return is_scalar( $value ) ? (string) $value : $default;
	}
}

if ( ! function_exists( 'lafka_analytics_gtm_id' ) ) {
	function lafka_analytics_gtm_id(): string {
		$id = lafka_analytics_get_setting( 'lafka_gtm_container_id', '' );
		// Re-validate at emit time — defensive against direct option writes
		// that bypassed the Customizer sanitizer.
		return preg_match( '/^GTM-[A-Z0-9]+$/', $id ) ? $id : '';
	}
}

if ( ! function_exists( 'lafka_analytics_ga4_id' ) ) {
	function lafka_analytics_ga4_id(): string {
		$id = lafka_analytics_get_setting( 'lafka_ga4_measurement_id', '' );
		return preg_match( '/^G-[A-Z0-9]+$/', $id ) ? $id : '';
	}
}

if ( ! function_exists( 'lafka_analytics_clarity_id' ) ) {
	function lafka_analytics_clarity_id(): string {
		$id = lafka_analytics_get_setting( 'lafka_clarity_project_id', '' );
		return preg_match( '/^[a-zA-Z0-9]+$/', $id ) ? $id : '';
	}
}

if ( ! function_exists( 'lafka_analytics_meta_pixel_id' ) ) {
	function lafka_analytics_meta_pixel_id(): string {
		$id = lafka_analytics_get_setting( 'lafka_meta_pixel_id', '' );
		return preg_match( '/^\d{15,16}$/', $id ) ? $id : '';
	}
}

if ( ! function_exists( 'lafka_analytics_gsc_token' ) ) {
	function lafka_analytics_gsc_token(): string {
		return lafka_analytics_get_setting( 'lafka_gsc_verification', '' );
	}
}

if ( ! function_exists( 'lafka_analytics_consent_defaults' ) ) {
	/**
	 * Resolve the four Consent Mode v2 default states.
	 *
	 * @return array<string, string>
	 */
	function lafka_analytics_consent_defaults(): array {
		$normalize = static function ( string $value ): string {
			return 'granted' === $value ? 'granted' : 'denied';
		};
		return array(
			'analytics_storage'    => $normalize( lafka_analytics_get_setting( 'lafka_consent_default_analytics', 'denied' ) ),
			'ad_storage'           => $normalize( lafka_analytics_get_setting( 'lafka_consent_default_ad_storage', 'denied' ) ),
			'ad_user_data'         => $normalize( lafka_analytics_get_setting( 'lafka_consent_default_ad_user_data', 'denied' ) ),
			'ad_personalization'   => $normalize( lafka_analytics_get_setting( 'lafka_consent_default_ad_personalization', 'denied' ) ),
		);
	}
}

if ( ! function_exists( 'lafka_analytics_banner_enabled' ) ) {
	function lafka_analytics_banner_enabled(): bool {
		return '1' === lafka_analytics_get_setting( 'lafka_consent_banner_enabled', '1' );
	}
}

// ============================================================================
// Emit functions.
// ============================================================================

if ( ! function_exists( 'lafka_emit_consent_mode_defaults' ) ) {
	/**
	 * Emit the Google Consent Mode v2 default state.
	 *
	 * MUST run before any analytics tag. Hooked on wp_head priority 1.
	 * Also emits the dataLayer init in the same script tag so the order is
	 * guaranteed and there's only one inline <script> for the consent
	 * scaffold.
	 */
	function lafka_emit_consent_mode_defaults(): void {
		$defaults = lafka_analytics_consent_defaults();
		// Functionality (essentials) is always granted — required for the
		// site to operate (e.g. session cookie). Security is granted by
		// default; operator who wants per-tag security throttling can
		// override via the lafka_consent_defaults filter.
		$payload = array(
			'analytics_storage'    => $defaults['analytics_storage'],
			'ad_storage'           => $defaults['ad_storage'],
			'ad_user_data'         => $defaults['ad_user_data'],
			'ad_personalization'   => $defaults['ad_personalization'],
			'functionality_storage' => 'granted',
			'security_storage'      => 'granted',
			'wait_for_update'       => 500,
		);
		/**
		 * Filter the Consent Mode v2 default payload.
		 *
		 * Theme/site overrides can flip categories or extend the payload
		 * (e.g. region-scoped defaults via gtag 'region' parameter).
		 *
		 * @param array<string, mixed> $payload
		 */
		if ( function_exists( 'apply_filters' ) ) {
			$payload = (array) apply_filters( 'lafka_consent_defaults', $payload );
		}

		echo "<script>\n";
		echo "window.dataLayer = window.dataLayer || [];\n";
		echo "function gtag(){dataLayer.push(arguments);}\n";
		echo "gtag('consent','default'," . wp_json_encode( $payload ) . ");\n";
		echo "</script>\n";
	}
}

if ( ! function_exists( 'lafka_emit_consent_replay' ) ) {
	/**
	 * Replay a returning visitor's stored consent decision in <head>.
	 *
	 * Hooked on wp_head priority 1, immediately AFTER
	 * lafka_emit_consent_mode_defaults so `gtag` and `dataLayer` already
	 * exist when this runs.
	 *
	 * Why this is in the head and not only in the footer banner JS: the
	 * defaults emit `wait_for_update: 500` (ms), and the footer banner JS
	 * only renders on wp_footer:100 — typically well after that 500ms window
	 * has closed. By then GA4/Ads may have already sent a cookieless ping in
	 * the page-load 'denied' default. A returning visitor who previously
	 * accepted would therefore be silently downgraded to denied on every
	 * subsequent page. Replaying inside the head — before the window closes —
	 * restores their persisted grants so the very first tag boot honours the
	 * stored decision.
	 *
	 * Reads the same localStorage key the banner persists
	 * (`lafka_consent_v1`). The footer banner JS keeps ownership of the
	 * show/click UX (and re-applies defensively on load).
	 *
	 * The emitted script is entirely static (no PHP-interpolated values), so
	 * there is nothing to escape; the localStorage payload is consumed
	 * client-side and only handed to gtag()/dataLayer, never echoed to HTML.
	 */
	function lafka_emit_consent_replay(): void {
		if ( ! lafka_analytics_banner_enabled() ) {
			return;
		}
		echo "<script id=\"lafka-consent-replay\">\n";
		echo "(function(){\n";
		echo "\ttry {\n";
		echo "\t\tvar raw = window.localStorage.getItem('lafka_consent_v1');\n";
		echo "\t\tif (!raw) { return; }\n";
		echo "\t\tvar state = JSON.parse(raw);\n";
		echo "\t\tif (!state) { return; }\n";
		echo "\t\twindow.dataLayer = window.dataLayer || [];\n";
		echo "\t\tfunction gtag(){ window.dataLayer.push(arguments); }\n";
		echo "\t\tgtag('consent','update', {\n";
		echo "\t\t\tanalytics_storage:   state.analytics_storage   ? 'granted' : 'denied',\n";
		echo "\t\t\tad_storage:          state.ad_storage          ? 'granted' : 'denied',\n";
		echo "\t\t\tad_user_data:        state.ad_user_data        ? 'granted' : 'denied',\n";
		echo "\t\t\tad_personalization:  state.ad_personalization  ? 'granted' : 'denied'\n";
		echo "\t\t});\n";
		echo "\t\twindow.dataLayer.push({ event: 'consent_update', consent_state: state });\n";
		echo "\t} catch(e){ /* private mode / parse error — leave defaults in place */ }\n";
		echo "})();\n";
		echo "</script>\n";
	}
}

if ( ! function_exists( 'lafka_emit_datalayer_init' ) ) {
	/**
	 * Ensure the dataLayer global exists before any tag pushes to it.
	 *
	 * The consent-mode emit above already declares it, but keep this as a
	 * standalone helper so tests can call it in isolation and so a future
	 * refactor that moves consent-mode behind a feature flag does not lose
	 * dataLayer bootstrap.
	 */
	function lafka_emit_datalayer_init(): void {
		echo "<script>window.dataLayer = window.dataLayer || [];</script>\n";
	}
}

if ( ! function_exists( 'lafka_emit_gsc_verification' ) ) {
	/**
	 * Emit the Google Search Console verification meta tag.
	 */
	function lafka_emit_gsc_verification(): void {
		$token = lafka_analytics_gsc_token();
		if ( '' === $token ) {
			return;
		}
		echo '<meta name="google-site-verification" content="' . esc_attr( $token ) . '" />' . "\n";
	}
}

if ( ! function_exists( 'lafka_emit_gtm_head' ) ) {
	/**
	 * Emit Google's canonical GTM head snippet.
	 *
	 * Reference: https://developers.google.com/tag-platform/tag-manager/web
	 * Snippet is identical to what tagmanager.google.com → Install Google
	 * Tag Manager → head section produces, with the container ID swapped
	 * in. The container ID passes through esc_js() inside the JS string
	 * literal and esc_attr() inside the dl=... query param fallback.
	 */
	function lafka_emit_gtm_head(): void {
		$gtm_id = lafka_analytics_gtm_id();
		if ( '' === $gtm_id ) {
			return;
		}
		echo "<!-- Google Tag Manager -->\n";
		echo "<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':\n";
		echo "new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],\n";
		echo "j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=\n";
		echo "'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);\n";
		echo "})(window,document,'script','dataLayer','" . esc_js( $gtm_id ) . "');</script>\n";
		echo "<!-- End Google Tag Manager -->\n";
	}
}

if ( ! function_exists( 'lafka_emit_gtm_body_noscript' ) ) {
	/**
	 * Emit GTM's noscript iframe immediately after <body>.
	 *
	 * Requires the theme to call wp_body_open(). Most modern themes do —
	 * the Lafka theme does. A late wp_footer fallback is registered
	 * separately so themes that forgot still get a (visually-hidden but
	 * functionally-present) noscript hook.
	 */
	function lafka_emit_gtm_body_noscript(): void {
		$gtm_id = lafka_analytics_gtm_id();
		if ( '' === $gtm_id ) {
			return;
		}
		echo "<!-- Google Tag Manager (noscript) -->\n";
		echo '<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=' . esc_attr( $gtm_id ) . '" ';
		echo 'height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>' . "\n";
		echo "<!-- End Google Tag Manager (noscript) -->\n";
	}
}

if ( ! function_exists( 'lafka_emit_gtm_body_noscript_fallback' ) ) {
	/**
	 * Late fallback for themes that don't call wp_body_open().
	 *
	 * Emits the noscript on wp_footer with a tiny JS shim that hoists the
	 * iframe to the top of <body> on DOMContentLoaded. We track via a
	 * module-scoped flag whether wp_body_open already fired so we don't
	 * double-emit.
	 *
	 * NOTE: GTM's tracking primarily uses the head snippet; the noscript
	 * iframe is only consulted when JS is disabled, so a late fallback is
	 * acceptable (no JS = no DOM hoist either; the iframe wherever it lands
	 * still loads).
	 */
	function lafka_emit_gtm_body_noscript_fallback(): void {
		// If wp_body_open fired, the canonical emit above already ran.
		if ( did_action( 'wp_body_open' ) ) {
			return;
		}
		lafka_emit_gtm_body_noscript();
	}
}

if ( ! function_exists( 'lafka_emit_direct_ga4' ) ) {
	/**
	 * Emit GA4 gtag.js — only when GTM is empty AND GA4 ID is set.
	 *
	 * Override-not-additive: if operator pasted a GTM container, they wire
	 * GA4 inside GTM, so emitting both here would double-count every event.
	 *
	 * Direct-GA4 mode has NO GTM container to translate the GTM-format
	 * `dataLayer.push({event, ecommerce})` messages (emitted by the WC events
	 * module — both the server-rendered inline pushes from lafka_dl_emit_push()
	 * AND the client pushes in lafka-dl-client.js) into GA4 events. gtag.js
	 * ignores those raw dataLayer objects entirely — that translation is a
	 * GTM-only feature — so without a forwarder an operator on the simplest
	 * "paste a GA4 ID, no GTM" setup gets pageviews but ZERO funnel/ecommerce/
	 * purchase tracking. We therefore monkeypatch dataLayer.push (once, in the
	 * head, before any event fires) to mirror every {event, ecommerce} push
	 * into gtag('event', name, params). The ecommerce object is SPREAD as the
	 * event params (gtag ignores a nested `ecommerce` key); the
	 * `{ecommerce:null}` clear pushes carry no `event` and are skipped.
	 */
	function lafka_emit_direct_ga4(): void {
		if ( '' !== lafka_analytics_gtm_id() ) {
			return; // GTM owns this path.
		}
		$ga4_id = lafka_analytics_ga4_id();
		if ( '' === $ga4_id ) {
			return;
		}
		echo "<!-- Lafka — direct GA4 -->\n";
		echo '<script async src="https://www.googletagmanager.com/gtag/js?id=' . esc_attr( $ga4_id ) . '"></script>' . "\n";
		echo "<script>\n";
		echo "window.dataLayer = window.dataLayer || [];\n";
		echo "function gtag(){dataLayer.push(arguments);}\n";
		echo "gtag('js', new Date());\n";
		echo "gtag('config','" . esc_js( $ga4_id ) . "');\n";
		// dataLayer -> gtag forwarder for GTM-format ecommerce pushes. gtag()
		// itself pushes an array-like `arguments` object (no `.event` own key),
		// so mirrored events never re-enter this branch — no recursion.
		echo "(function(){\n";
		echo "\tvar dl = window.dataLayer;\n";
		echo "\tvar op = dl.push.bind(dl);\n";
		echo "\tdl.push = function(o){\n";
		echo "\t\tvar r = op(o);\n";
		echo "\t\tif (o && typeof o === 'object' && o.event && o.ecommerce && typeof gtag === 'function') {\n";
		echo "\t\t\tgtag('event', o.event, Object.assign({ send_to: '" . esc_js( $ga4_id ) . "' }, o.ecommerce));\n";
		echo "\t\t}\n";
		echo "\t\treturn r;\n";
		echo "\t};\n";
		echo "})();\n";
		echo "</script>\n";
	}
}

if ( ! function_exists( 'lafka_emit_direct_clarity' ) ) {
	/**
	 * Emit Microsoft Clarity — only when GTM is empty AND Clarity ID is set.
	 */
	function lafka_emit_direct_clarity(): void {
		if ( '' !== lafka_analytics_gtm_id() ) {
			return;
		}
		$clarity_id = lafka_analytics_clarity_id();
		if ( '' === $clarity_id ) {
			return;
		}
		// Microsoft Clarity does NOT honour Google Consent Mode, so it must be
		// driven from the same lafka_consent_v1 decision the bundled banner
		// persists. Injecting the external tag unconditionally drops cookies and
		// sends a beacon before the visitor decides anything, so instead expose a
		// one-shot lazy loader and only invoke it when a stored
		// analytics_storage===true decision already exists at load. The banner JS
		// calls window.lafkaLoadClarity() when analytics is granted later.
		echo "<!-- Lafka — direct Microsoft Clarity -->\n";
		echo "<script>\n";
		echo "(function(){\n";
		echo "\tvar loaded = false;\n";
		echo "\twindow.lafkaLoadClarity = function(){\n";
		echo "\t\tif (loaded) { return; }\n";
		echo "\t\tloaded = true;\n";
		echo "\t\t(function(c,l,a,r,i,t,y){\n";
		echo "\t\t\tc[a]=c[a]||function(){(c[a].q=c[a].q||[]).push(arguments)};\n";
		echo "\t\t\tt=l.createElement(r);t.async=1;t.src=\"https://www.clarity.ms/tag/\"+i;\n";
		echo "\t\t\ty=l.getElementsByTagName(r)[0];y.parentNode.insertBefore(t,y);\n";
		echo "\t\t})(window, document, \"clarity\", \"script\", \"" . esc_js( $clarity_id ) . "\");\n";
		echo "\t};\n";
		echo "\ttry {\n";
		echo "\t\tvar d = JSON.parse(window.localStorage.getItem('lafka_consent_v1') || 'null');\n";
		echo "\t\tif (d && d.analytics_storage) { window.lafkaLoadClarity(); }\n";
		echo "\t} catch(e){ /* private mode / parse error — stay unloaded until consent */ }\n";
		echo "})();\n";
		echo "</script>\n";
	}
}

if ( ! function_exists( 'lafka_emit_direct_meta_pixel' ) ) {
	/**
	 * Emit Meta (Facebook) Pixel — only when GTM is empty AND Pixel ID is set.
	 */
	function lafka_emit_direct_meta_pixel(): void {
		if ( '' !== lafka_analytics_gtm_id() ) {
			return;
		}
		$pixel_id = lafka_analytics_meta_pixel_id();
		if ( '' === $pixel_id ) {
			return;
		}
		echo "<!-- Lafka — direct Meta Pixel -->\n";
		echo "<script>\n";
		echo "!function(f,b,e,v,n,t,s){if(f.fbq)return;n=f.fbq=function(){n.callMethod?\n";
		echo "n.callMethod.apply(n,arguments):n.queue.push(arguments)};if(!f._fbq)f._fbq=n;\n";
		echo "n.push=n;n.loaded=!0;n.version='2.0';n.queue=[];t=b.createElement(e);t.async=!0;\n";
		echo "t.src=v;s=b.getElementsByTagName(e)[0];s.parentNode.insertBefore(t,s)}(window,\n";
		echo "document,'script','https://connect.facebook.net/en_US/fbevents.js');\n";
		// Meta Pixel ignores Google Consent Mode, so the bundled banner must drive
		// it explicitly. Revoke BEFORE init so init drops no _fbp/_fbc cookie and
		// sends no beacon; then only replay a PageView when a stored decision
		// granting ad_storage already exists at load. The banner JS grants and
		// fires the deferred PageView on an explicit accept (deduped via the flag).
		echo "fbq('consent', 'revoke');\n";
		echo "fbq('init', '" . esc_js( $pixel_id ) . "');\n";
		echo "(function(){\n";
		echo "\ttry {\n";
		echo "\t\tvar d = JSON.parse(window.localStorage.getItem('lafka_consent_v1') || 'null');\n";
		echo "\t\tif (d && d.ad_storage) {\n";
		echo "\t\t\tfbq('consent', 'grant');\n";
		echo "\t\t\tfbq('track', 'PageView');\n";
		echo "\t\t\twindow._lafkaFbPageView = true;\n";
		echo "\t\t}\n";
		echo "\t} catch(e){ /* private mode / parse error — stay revoked until consent */ }\n";
		echo "})();\n";
		echo "</script>\n";
		echo '<noscript><img height="1" width="1" style="display:none" alt="" ';
		echo 'src="https://www.facebook.com/tr?id=' . esc_attr( $pixel_id ) . '&ev=PageView&noscript=1" /></noscript>' . "\n";
	}
}

if ( ! function_exists( 'lafka_emit_consent_banner' ) ) {
	/**
	 * Emit the consent banner HTML + inline JS.
	 *
	 * Banner appears bottom of viewport. Accept / Reject / Settings buttons.
	 * Settings opens a per-category toggle modal. Decision is persisted in
	 * localStorage key `lafka_consent_v1` so the banner doesn't reappear
	 * on every page.
	 *
	 * Inline-style CSS so the banner works even when the theme stylesheet
	 * fails to load (which is exactly when consent matters most — slow /
	 * bot / privacy-tool requests).
	 */
	function lafka_emit_consent_banner(): void {
		if ( ! lafka_analytics_banner_enabled() ) {
			return;
		}
		// Destination gate — mirror the other analytics emitters (page-context,
		// custom-events, dl-client, store-events all gate on
		// lafka_analytics_is_active()). Without a configured tracking destination
		// (GTM / GA4 / Clarity / Meta Pixel / CF beacon) no cookies are ever set,
		// so rendering a "we use cookies" banner is pointless and arguably
		// misleading — and a needless conversion drag on a default install. The
		// function_exists guard is defensive: the gate lives in
		// lafka-page-context.php, required at bootstrap well before this fires on
		// wp_footer:100, so on a real request the function is always present.
		if ( ! function_exists( 'lafka_analytics_is_active' ) || ! lafka_analytics_is_active() ) {
			return;
		}

		$body_text      = lafka_analytics_get_setting( 'lafka_consent_banner_text', 'We use cookies to analyze site traffic and personalize content. By accepting, you consent to our use of cookies.' );
		$accept_label   = lafka_analytics_get_setting( 'lafka_consent_banner_accept_label', 'Accept all' );
		$reject_label   = lafka_analytics_get_setting( 'lafka_consent_banner_reject_label', 'Reject' );
		$settings_label = lafka_analytics_get_setting( 'lafka_consent_banner_settings_label', 'Settings' );

		// Palette is expressed as --lafka-consent-* CSS custom properties with
		// the current brand values as fallbacks. This keeps the plugin's
		// "markup only / theme owns appearance" contract: a theme can recolor
		// the banner from its own stylesheet (just by setting the variables, no
		// !important overrides) while the banner still renders correctly when
		// the theme CSS fails to load — which is exactly when consent matters
		// most (slow / bot / privacy-tool requests).
		$styles = <<<'CSS'
.lafka-consent-banner{position:fixed;left:0;right:0;bottom:0;z-index:99998;background:var(--lafka-consent-bg,#1f2937);color:var(--lafka-consent-fg,#fff);font:14px/1.5 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;padding:16px 20px;box-shadow:0 -4px 16px rgba(0,0,0,.2);display:none}
.lafka-consent-banner.is-visible{display:flex;flex-wrap:wrap;gap:16px;align-items:center;justify-content:space-between}
.lafka-consent-banner__text{flex:1 1 320px;margin:0}
.lafka-consent-banner__actions{display:flex;flex-wrap:wrap;gap:8px}
.lafka-consent-banner__btn{appearance:none;border:0;border-radius:6px;padding:10px 18px;font:inherit;font-weight:600;cursor:pointer;line-height:1}
.lafka-consent-banner__btn--accept{background:var(--lafka-consent-accept,#10b981);color:var(--lafka-consent-accept-fg,#fff)}
.lafka-consent-banner__btn--reject{background:var(--lafka-consent-reject,#374151);color:var(--lafka-consent-reject-fg,#fff)}
.lafka-consent-banner__btn--settings{background:transparent;color:var(--lafka-consent-fg,#fff);text-decoration:underline}
.lafka-consent-modal{position:fixed;inset:0;z-index:99999;background:var(--lafka-consent-overlay,rgba(0,0,0,.55));display:none;align-items:center;justify-content:center;padding:20px}
.lafka-consent-modal.is-visible{display:flex}
.lafka-consent-modal__panel{background:var(--lafka-consent-panel-bg,#fff);color:var(--lafka-consent-panel-fg,#1f2937);border-radius:10px;max-width:520px;width:100%;padding:24px;box-shadow:0 20px 50px rgba(0,0,0,.35)}
.lafka-consent-modal__title{margin:0 0 12px;font-size:18px}
.lafka-consent-modal__row{display:flex;justify-content:space-between;align-items:center;padding:10px 0;border-top:1px solid var(--lafka-consent-border,#e5e7eb);font-size:14px}
.lafka-consent-modal__row:first-of-type{border-top:0}
.lafka-consent-modal__actions{display:flex;justify-content:flex-end;gap:8px;margin-top:16px}
.lafka-consent-modal__btn{appearance:none;border:0;border-radius:6px;padding:10px 18px;font:inherit;font-weight:600;cursor:pointer}
.lafka-consent-modal__btn--save{background:var(--lafka-consent-accept,#10b981);color:var(--lafka-consent-accept-fg,#fff)}
.lafka-consent-modal__btn--close{background:var(--lafka-consent-close-bg,#e5e7eb);color:var(--lafka-consent-close-fg,#1f2937)}
@media (prefers-reduced-motion:no-preference){.lafka-consent-banner.is-visible{animation:lafka-slide-up .25s ease-out}}
@keyframes lafka-slide-up{from{transform:translateY(100%)}to{transform:translateY(0)}}
CSS;

		/**
		 * Filter the consent banner's inline CSS.
		 *
		 * Return an empty string to suppress the inline <style> block entirely
		 * — e.g. when the theme enqueues its own consent styling and does not
		 * want the bundled defaults. The default block exposes its palette via
		 * --lafka-consent-* custom properties (current brand values kept as
		 * fallbacks), so most themes can recolor the banner by setting those
		 * variables without needing to replace the whole block here.
		 *
		 * @param string $styles Inline CSS, without the wrapping <style> tag.
		 */
		if ( function_exists( 'apply_filters' ) ) {
			$styles = (string) apply_filters( 'lafka_consent_banner_styles', $styles );
		}

		if ( '' !== trim( $styles ) ) {
			echo '<style id="lafka-consent-banner-style">' . "\n" . $styles . "\n</style>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static (or theme-filtered) CSS; escaping would corrupt the stylesheet.
		}

		?>
<div class="lafka-consent-banner" id="lafka-consent-banner" role="region" aria-label="<?php echo esc_attr__( 'Cookie consent', 'lafka-plugin' ); ?>" hidden>
	<p class="lafka-consent-banner__text"><?php echo wp_kses_post( $body_text ); ?></p>
	<div class="lafka-consent-banner__actions">
		<button type="button" class="lafka-consent-banner__btn lafka-consent-banner__btn--settings" data-lafka-consent="settings" aria-label="<?php echo esc_attr( $settings_label ); ?>"><?php echo esc_html( $settings_label ); ?></button>
		<button type="button" class="lafka-consent-banner__btn lafka-consent-banner__btn--reject" data-lafka-consent="reject" aria-label="<?php echo esc_attr( $reject_label ); ?>"><?php echo esc_html( $reject_label ); ?></button>
		<button type="button" class="lafka-consent-banner__btn lafka-consent-banner__btn--accept" data-lafka-consent="accept" aria-label="<?php echo esc_attr( $accept_label ); ?>"><?php echo esc_html( $accept_label ); ?></button>
	</div>
</div>
<div class="lafka-consent-modal" id="lafka-consent-modal" role="dialog" aria-modal="true" aria-labelledby="lafka-consent-modal-title" hidden>
	<div class="lafka-consent-modal__panel">
		<h2 class="lafka-consent-modal__title" id="lafka-consent-modal-title"><?php echo esc_html__( 'Cookie preferences', 'lafka-plugin' ); ?></h2>
		<label class="lafka-consent-modal__row"><span><?php echo esc_html__( 'Analytics (site usage)', 'lafka-plugin' ); ?></span><input type="checkbox" data-lafka-consent-cat="analytics_storage"></label>
		<label class="lafka-consent-modal__row"><span><?php echo esc_html__( 'Advertising cookies', 'lafka-plugin' ); ?></span><input type="checkbox" data-lafka-consent-cat="ad_storage"></label>
		<label class="lafka-consent-modal__row"><span><?php echo esc_html__( 'Send data to ad partners', 'lafka-plugin' ); ?></span><input type="checkbox" data-lafka-consent-cat="ad_user_data"></label>
		<label class="lafka-consent-modal__row"><span><?php echo esc_html__( 'Personalized ads', 'lafka-plugin' ); ?></span><input type="checkbox" data-lafka-consent-cat="ad_personalization"></label>
		<div class="lafka-consent-modal__actions">
			<button type="button" class="lafka-consent-modal__btn lafka-consent-modal__btn--close" data-lafka-consent="close"><?php echo esc_html__( 'Close', 'lafka-plugin' ); ?></button>
			<button type="button" class="lafka-consent-modal__btn lafka-consent-modal__btn--save" data-lafka-consent="save"><?php echo esc_html__( 'Save preferences', 'lafka-plugin' ); ?></button>
		</div>
	</div>
</div>
<script id="lafka-consent-banner-js">
(function(){
	var STORAGE_KEY = 'lafka_consent_v1';
	var banner = document.getElementById('lafka-consent-banner');
	var modal  = document.getElementById('lafka-consent-modal');
	if (!banner || !modal) return;

	function gtagSafe(){
		window.dataLayer = window.dataLayer || [];
		function gtag(){ window.dataLayer.push(arguments); }
		return gtag;
	}

	function readStored(){
		try { return JSON.parse(window.localStorage.getItem(STORAGE_KEY) || 'null'); }
		catch(e){ return null; }
	}

	function persist(state){
		try { window.localStorage.setItem(STORAGE_KEY, JSON.stringify(state)); }
		catch(e){ /* private mode / quota — silently ignore */ }
	}

	function applyConsent(state){
		var gtag = gtagSafe();
		gtag('consent','update', {
			analytics_storage:   state.analytics_storage   ? 'granted' : 'denied',
			ad_storage:          state.ad_storage          ? 'granted' : 'denied',
			ad_user_data:        state.ad_user_data        ? 'granted' : 'denied',
			ad_personalization:  state.ad_personalization  ? 'granted' : 'denied'
		});
		window.dataLayer.push({ event: 'consent_update', consent_state: state });
		// Non-Google platforms ignore Consent Mode, so drive them from the same
		// decision. Meta Pixel: grant/revoke, plus a one-time PageView once
		// ad_storage is granted (deduped against the head emit via the flag).
		// Clarity: lazy-load the external tag only after analytics_storage grants.
		if (window.fbq){
			window.fbq('consent', state.ad_storage ? 'grant' : 'revoke');
			if (state.ad_storage && !window._lafkaFbPageView){
				window.fbq('track','PageView');
				window._lafkaFbPageView = true;
			}
		}
		if (state.analytics_storage && typeof window.lafkaLoadClarity === 'function'){
			window.lafkaLoadClarity();
		}
	}

	function showBanner(){
		banner.hidden = false;
		banner.classList.add('is-visible');
	}
	function hideBanner(){
		banner.classList.remove('is-visible');
		banner.hidden = true;
	}
	function showModal(prefill){
		modal.querySelectorAll('[data-lafka-consent-cat]').forEach(function(input){
			var cat = input.getAttribute('data-lafka-consent-cat');
			input.checked = !!(prefill && prefill[cat]);
		});
		modal.hidden = false;
		modal.classList.add('is-visible');
	}
	function hideModal(){
		modal.classList.remove('is-visible');
		modal.hidden = true;
	}

	function modalReadState(){
		var out = {};
		modal.querySelectorAll('[data-lafka-consent-cat]').forEach(function(input){
			out[input.getAttribute('data-lafka-consent-cat')] = !!input.checked;
		});
		return out;
	}

	// Replay the persisted decision on load: a returning visitor who already
	// accepted/rejected must have their grants re-applied to gtag, otherwise
	// the page stays at the wp_head 'denied' default forever. The head replay
	// (lafka_emit_consent_replay) handles the timing-critical update inside the
	// wait_for_update window; this is the defensive re-apply + show/click UX.
	var existing = readStored();
	if (existing){
		applyConsent(existing);
	} else {
		showBanner();
	}

	banner.addEventListener('click', function(ev){
		var btn = ev.target.closest('[data-lafka-consent]');
		if (!btn) return;
		var action = btn.getAttribute('data-lafka-consent');
		if (action === 'accept'){
			var stateAll = { analytics_storage:true, ad_storage:true, ad_user_data:true, ad_personalization:true, decided_at: Date.now() };
			persist(stateAll);
			applyConsent(stateAll);
			hideBanner();
		} else if (action === 'reject'){
			var stateNone = { analytics_storage:false, ad_storage:false, ad_user_data:false, ad_personalization:false, decided_at: Date.now() };
			persist(stateNone);
			applyConsent(stateNone);
			hideBanner();
		} else if (action === 'settings'){
			showModal(readStored());
		}
	});

	modal.addEventListener('click', function(ev){
		var btn = ev.target.closest('[data-lafka-consent]');
		if (!btn) return;
		var action = btn.getAttribute('data-lafka-consent');
		if (action === 'save'){
			var state = modalReadState();
			state.decided_at = Date.now();
			persist(state);
			applyConsent(state);
			hideModal();
			hideBanner();
		} else if (action === 'close'){
			hideModal();
		}
	});
})();
</script>
		<?php
	}
}

// ============================================================================
// Hook registration.
// ============================================================================

if ( function_exists( 'add_action' ) ) {
	// Priority 1: must run before any tag. Consent default must be FIRST,
	// then immediately replay any stored decision so returning visitors are
	// restored to their granted/denied state inside the wait_for_update window
	// (registered right after defaults so it fires after gtag is defined).
	add_action( 'wp_head', 'lafka_emit_consent_mode_defaults', 1 );
	add_action( 'wp_head', 'lafka_emit_consent_replay', 1 );
	add_action( 'wp_head', 'lafka_emit_gsc_verification', 1 );

	// Priority 2: tag emitters.
	add_action( 'wp_head', 'lafka_emit_gtm_head', 2 );
	add_action( 'wp_head', 'lafka_emit_direct_ga4', 2 );
	add_action( 'wp_head', 'lafka_emit_direct_clarity', 2 );
	add_action( 'wp_head', 'lafka_emit_direct_meta_pixel', 2 );

	// GTM noscript: canonical position is immediately after <body>. Falls
	// back to wp_footer when the theme doesn't call wp_body_open().
	add_action( 'wp_body_open', 'lafka_emit_gtm_body_noscript', 1 );
	add_action( 'wp_footer', 'lafka_emit_gtm_body_noscript_fallback', 5 );

	// Consent banner renders late on wp_footer so it overlays everything
	// without fighting other late-injected components for z-index.
	add_action( 'wp_footer', 'lafka_emit_consent_banner', 100 );
}
