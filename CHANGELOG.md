# Changelog

All notable changes to lafka-plugin are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/); versions follow the repo's
semver (`npm version` is the single source of truth — see the Releases section
of CONTRIBUTING.md). Older history lives in git tags + GitHub Releases.

## [Unreleased]

### Added
- **WebP for new uploads**: new JPEG/PNG uploads are saved as WebP (every
  generated size) when the server's image editor can write WebP. Toggle on
  Lafka → Modules → "WebP images for new uploads" (option `lafka_webp_uploads`)
  or the `lafka_webp_uploads_enabled` filter. Existing images:
  `wp lafka images convert-webp` / `wp media regenerate` (docs/PERFORMANCE.md).
- **Add-on group disclosure**: a theme that declares
  `add_theme_support( 'lafka-addon-group-toggle' )` gets add-on group headings
  as `<h3><button aria-expanded aria-controls>` around a `.lafka-addon-body`
  region; addons.js keeps `aria-expanded` and the group's `data-collapsed` in
  sync. Other themes keep the plain heading. Filter `lafka_addon_group_toggle`.
- Search & AI: "Menu page description" and "Page description fallback"
  templates (`lafka_seo_desc_menu`, `lafka_seo_desc_page`) and the `{short}`
  token (product short description).

### Changed
- **Contact Form 7** CSS/JS load only on content that embeds a form (a form
  rendered from a widget or template still loads them late). Filter
  `lafka_cf7_assets_needed`.
- **Payment-gateway assets** (SkyVerge framework, Authorize.Net CIM) load only on
  cart, checkout, account, order-pay and add-payment-method; express-pay handles
  are never touched. Filters `lafka_gateway_asset_patterns`,
  `lafka_gateway_assets_needed`.
- **Descriptions**: a product short description under 70 characters is wrapped
  by the product template (name — short description, from $X, place); inner
  pages get the menu template, their own text or the page template instead of
  the shared site pitch (the front page keeps it).
- **Titles**: home adds the place and "Order Online" even without cuisines;
  categories read "{term} Menu[ in {city}] – {name}", products
  "{product} – {name}"; a title over 65 characters drops its optional parts
  (`lafka_seo_title_max_length`). Saved operator templates still win.
- **Social previews**: og:image falls back to the menu-category thumbnail, the
  default share image, then the homepage hero before the site icon;
  `twitter:card` is `summary_large_image` only for a landscape image (never the
  square logo); pages are `og:type` website (article only for posts);
  og:title/twitter:title carry the site name. Filter `lafka_twitter_card`.
- **robots.txt**: Lafka's rules join the `User-agent: *` group (they used to land
  after the Sitemap line, outside any group) as `/*?*orderby=`,
  `/*?*add-to-cart=`, `/*?*filter_` …; the Sitemap line closes the file.
  `/my-account/` is noindexed instead of blocked.
- Legacy `lafka-foodmenu` singles, archive and categories 301 to the menu page
  when WooCommerce is the menu (`lafka_legacy_foodmenu_redirect`,
  `lafka_legacy_foodmenu_redirect_target`).
- WordPress' 404 "guess the permalink" redirect is off
  (`lafka_disable_404_guess_redirect`).
- Anonymous `?author=N` probes answer 404 and anonymous `/wp/v2/users` requests
  401; logged-in users (block editor) are unaffected
  (`lafka_restrict_user_enumeration`, `lafka_author_enumeration_response`).

### Fixed
- Menu-category canonicals are the clean term link (+ `/page/N/`) with every
  query arg dropped (utm_*, fbclid, sort/filter).
- `menu.md` and `llms-full.txt` are served `X-Robots-Tag: noindex` like
  `menu.json` (`lafka_llms_noindex_types`).
- A category page's Menu JSON-LD has its own `@id` (term URL + `#menu`) instead
  of re-declaring `/menu/#menu`.

## [10.2.1] — 2026-09-25

### Fixed
- Promotions: the BOGO banner renders in the page flow at the top of `<body>` (`wp_body_open`) instead of a fixed overlay, so it no longer covers the theme header or sticky bars; the fixed overlay remains only as the fallback for themes without `wp_body_open`. The banner is a labelled `region` (not a second `banner` landmark).

## [10.2.0] — 2026-09-25

Phases GX0 ("stop losing orders"), GX1 (diagnostics) and GX2 (Insights).
All new checkout behaviour works on the classic and the block (Store API)
checkout; settings live in Customizer → **Lafka — Checkout** unless noted.

### Added
- **Menu items that skip pickup and delivery**: a WooCommerce order made only
  of Virtual items needs no shipping, so it never asks pickup or delivery,
  keeps the billing address required and charges no delivery fee. While the
  store offers pickup or delivery: a Site Health check (count, up to ten
  linked titles, guidance), a one-line warning on a flagged product's edit
  screen with a per-product "This item is meant to be virtual" link (post
  meta `_lafka_virtual_ok`), and `wp lafka products unvirtual [--ids=…]`
  (dry run unless `--yes`). Downloadable items are exempt; filters
  `lafka_virtual_ok_product_ids`, `lafka_virtual_ok_terms` (taxonomy ⇒
  terms), `lafka_virtual_items_check_enabled`.
- **The counter (GX4, plugin part)** — behaviour the theme's counter layouts
  read; inert until a theme calls it or opts in:
  - **Serves N**: Product data → General → "Serves (people)" (`_lafka_serves`,
    0–50, 0 = not set). `lafka_get_product_serves()` + filter
    `lafka_product_serves` (a variation reads its parent); REST field
    `lafka_serves`; Store API `extensions.lafka.serves` on products. Never
    inferred.
  - `lafka_product_has_required_addons( $product_id )` (filter of the same
    name; Store API `extensions.lafka.has_required_addons`): a product with a
    required add-on group goes to its page instead of a listing quick add.
  - **Pickup or delivery, once**: `lafka_fulfilment_modes()`,
    `lafka_fulfilment_preference()` (branch-session order type, else cookie
    `lafka_order_method`). WooCommerce's default shipping rate follows the
    preference on the classic and block checkout; a rate the customer picked
    is never overridden, and with delivery preferred pickup is only the
    stop-gap until delivery rates exist. Filters `lafka_fulfilment_modes`,
    `lafka_fulfilment_preference`, `lafka_fulfilment_preselect_enabled`.
  - **Drawer quantity stepper** for themes that declare
    `add_theme_support( 'lafka-drawer-stepper' )` (or filter
    `lafka_cart_drawer_stepper_enabled`): options line, labelled − / + and a
    worded Remove per row; `wc-ajax=lafka_cart_set_qty` (nonce
    `lafka-cart-qty`, WooCommerce quantity rules) answers with refreshed
    fragments; page-cache safe. Filter `lafka_cart_drawer_item_details`.
  - **Category tagline**: Products → Categories → "Tagline (optional)" (term
    meta `lafka_tagline`, ≤ 140 chars, REST); `lafka_get_category_tagline()`,
    filter `lafka_category_tagline_meta`.
  - Drawer extras wording: `lafka_cart_drawer_upsell_heading`,
    `lafka_cart_drawer_upsell_row_note`, `lafka_cart_drawer_upsell_add_label`.
  - `wp lafka seed-demo`: a Deals category first in category order (three
    combos: one serves 2, one featured).
- **Diagnostics (GX1)**: `Lafka_Log` / `lafka_log()` logging facade on
  WooCommerce's logger — one WC log source per channel (`lafka-{channel}`),
  personal data scrubbed, a request id on Lafka REST / Store API / checkout
  responses (`X-Lafka-Request-Id`) and on checkout orders (`_lafka_request_id`).
  Theme/child log through the `lafka_log` action.
- **Diagnostics**: deduplicated incident table (warnings and errors, 90-day
  retention), Lafka-scoped PHP fatal capture, and guarded Lafka REST + cron
  entry points.
- **Checkout**: `lafka_checkout_blocked( $reason, $context )` fired at every
  refusal point — store closed, outside the delivery area, address not
  pinpointed, time slot, branch/order type, add-ons, delivery minimum, no
  shipping method, checkout field errors (codes only), other Store API errors
  and payment failures classified declined / AVS / CVV / gateway error.
  Vocabulary: `Lafka_Checkout_Block_Reasons`. See `docs/DIAGNOSTICS.md`.
- **Lafka → Diagnostics** screen (incidents, checkout failures incl.
  WooCommerce place-order traces, health, settings), three Site Health checks
  and a daily error digest email — module `diagnostics`, on by default.
- **Checkout**: delivery prices are withheld until the destination has a
  street address and a postcode (postcode skipped where the country has
  none); pickup stays visible and the customer sees "Enter your street
  address to see the delivery cost." (classic cart/checkout row, empty-rates
  copy, block cart/checkout notice). Complements WooCommerce's
  requires-address setting, which is bypassed whenever blocks Local Pickup is
  enabled and never requires the street. Toggle + message in the Customizer;
  filters `lafka_delivery_quote_guard_enabled`,
  `lafka_delivery_rate_needs_address`, `lafka_delivery_quote_required_fields`,
  `lafka_delivery_quote_guard_message`.
- **Checkout**: pickup orders paid with an offline method (cash, cheque, bank
  transfer) ask only for name, phone and email; card gateways keep the
  billing address they verify (AVS). Empty billing country/state default to
  the store base. Classic: fields hide/show live with the shipping/payment
  choice, with "Want delivery? Add your address". Block: address fields are
  shown as optional and the rule is enforced on place-order
  (`lafka_pickup_checkout_relax_block_address` turns that off). Filters
  `lafka_pickup_checkout_slim_enabled`, `lafka_pickup_checkout_hidden_fields`,
  `lafka_pickup_address_optional_gateways`,
  `lafka_pickup_gateway_needs_billing_address`.
- **Checkout**: cash on delivery reads "Pay at pickup" / "Pay on delivery"
  (title + description, Customizer overrides; filters `lafka_cod_title`,
  `lafka_cod_description`, `lafka_contextual_payment_gateways`).
- **Variations**: options without an explicit order (custom term order, or
  a name/id sort) are listed cheapest first — WooCommerce dropdowns, Lafka
  swatches, and the theme's PDP chips / menu rows (Customizer → Lafka — PDP
  Redesign → "Order size options by price"; filter
  `lafka_sort_variation_options_by_price`).
- **NAP**: `lafka_format_phone_display()` — phone text in national format
  ("(902) 555-0100"; grouped international elsewhere); `phone_display` uses
  it when unset or stored as a bare E.164 number. tel: links keep E.164.
- **Order hours**: `Lafka_Order_Hours::can_order_ahead()`,
  `is_add_to_cart_blocked()`, `get_closed_notice_with_next_open()`; body
  class `lafka-order-ahead`.
- **Insights (GX2)**: first-party, cookieless funnel analytics — module
  `insights`, off by default (Lafka → Modules). One `sendBeacon` per page to
  `POST /wp-json/lafka/v1/i` (page type, referrer host, UTM, device class)
  plus server-side money events for classic and block checkout (add/remove
  cart, cart/checkout views, payment attempt, order placed once, payment
  failures, every `lafka_checkout_blocked` refusal). Visits are told apart by
  a daily-rotating hash; no IP, cookie or identifier is stored. Tables
  `wp_lafka_insights_sessions` (35 days) and `wp_lafka_insights_daily`, a
  nightly Action Scheduler rollup, **Lafka → Insights** (funnel + biggest
  leak, why no order, visits while closed, items viewed but not bought,
  search incl. zero results, device, visits vs orders by source, hour ×
  weekday, payment health) and a Monday-morning plain-English email
  (WooCommerce → Settings → Emails → Weekly Insights). Consent modes in
  Customizer → Lafka — Analytics → Insights: aggregate (default, no banner
  needed, honours GPC/DNT), consent required, off. See `docs/TRACKING.md`.
- **Analytics**: Insights counts as a dataLayer destination, so the event
  layer runs without GA4/GTM.
- **Diagnostics**: a ≤ 700-byte inline handler reports same-origin JavaScript
  errors to `POST /wp-json/lafka/v1/diag`, logged on the `js` channel (with
  the diagnostics or insights module).

### Changed
- `lafka_write_log()` is deprecated and now writes through `Lafka_Log`; the
  order-hours clock error logs to WooCommerce logs instead of `error_log`.

### Fixed
- **Insights**: numbers that combine visits with orders no longer mix
  populations. Orders count only when a measured visit paid (not orders from
  before Insights collected, or from staff / bots / opted-out browsers), so an
  item can no longer be "ordered" more often than "added"; visits vs orders by
  source both come from Insights, with every WooCommerce-attributed order in a
  separate, labelled table; figures start at the day collection started
  ("Collecting since …"), no trend against an uncovered period, and a ratio
  whose part exceeds its whole shows "—". Retained days are re-rolled once.
- **Order hours**: closed-store notices name the next opening ("STORE
  CLOSED. Opens Saturday at 11:00 AM."); adding to a cart while closed says
  so immediately; with date/time slots on, a closed store takes orders for a
  later slot (a chosen slot is required at checkout) instead of blocking.
- **Shipping areas**: the admin store-location map no longer falls back to
  (and saves) a hard-coded Sydney, Australia location. A missing or
  placeholder location is "not configured": delivery maths geocodes the
  WooCommerce store address instead, and an admin notice + Site Health check
  ask for the store location.
- **Consent**: the banner publishes `--lafka-consent-banner-h` /
  `html.lafka-consent-open` so fixed-bottom bars sit above it; 44px buttons
  and a compact mobile layout.
- **Diagnostics**: completed checkouts whose WooCommerce place-order trace was
  left behind (final step `[Shortcode #6A/#6B]` / `[Store API #9]`, or past
  the payment step on a processing / completed / on-hold order) are no longer
  listed as "never finished", indexed as incidents or emailed in the digest;
  incidents already recorded for them are resolved by the daily check. A
  "Show finished attempts" link reveals them; each row shows its outcome.

### Platform (GX5)
- Tested up to WordPress 7.1 / WooCommerce 11.1 (wp-env pins WP 7.1.2 + WC 11.1.2); `Requires Plugins: woocommerce` header.
- Google Maps loader deferred via the script strategy API (no tag rewriting); add-ons depend on `wc-accounting`; checkout date/time uses `selectWoo`; the contact-form inline script attaches to `jquery-form`.
- stylelint 17 + @wordpress/stylelint-config 26; non-blocking official Plugin Check workflow on the release tree.

### Tests & CI
- The PHPUnit suite is independent of test order and wall clock (300/300 random seeds): `LeftoverStubsExtension` pre-defines Brain-Monkey-stubbed functions, `StableClock` guards clock-tick races, static state resets between classes. CI also runs the suite in reverse and in seeded random order.

## [10.1.0] — 2026-09-24

### Fixed
- **Checkout (block)**: a saved "mandatory" date/time no longer blocks block
  checkout while the date/time feature is off (global and per-branch).
- **Checkout (block)**: with pinpoint delivery mandatory, a delivery order
  without a valid in-zone pinpoint is now rejected on the Store API path as
  on classic checkout (it used to skip the geo-fence entirely).
- **Checkout**: pickup orders — Lafka order type *pickup*, WC Local Pickup
  (`local_pickup`) or the blocks pickup method (`pickup_location`) — are never
  asked to pinpoint a delivery location; carts that ship nothing neither.
- **Checkout (block)**: the branch field only offers and accepts orderable
  branches; a crafted checkout naming any other branch is rejected.
- **Branches**: WooCommerce pages no longer fatal once a branch has a geocoded
  address (`update_term_meta_cache()` → `update_termmeta_cache()`).
- **Branches**: the branch-selector and branch-admin scripts load again —
  their URLs had 404'd since the module moved to `incl/branches/`.
- **Branches**: branch product filters (shop, widgets, related products) match
  by the branch term id instead of misusing it as a term_taxonomy_id.
- **Branches**: branch managers get their order emails without WC Analytics;
  the Orders badge counts only orders awaiting the branch; the orders-list
  branch filter renders only when there are branches.
- **Add-ons**: the per-option "Include" flag is honoured everywhere — excluded
  options are not rendered, not accepted when posted (classic or Store API) and
  never priced; a group with nothing left is not shown.
- **Add-ons**: stopped defining `WC_PRODUCT_ADDONS_VERSION` (another vendor's
  constant; clashed with WooCommerce Product Add-Ons).
- **Timeslots**: orders without a branch count against slot capacity (no empty
  `lafka_selected_branch_id` is written, and legacy empty values are counted).
- **Timeslots / Order hours**: the offered dates and the closed-store
  "Opens …" time use the store timezone, not UTC.
- **Promotions / free delivery / KDS**: the blocks `pickup_location` method is
  treated as pickup (delivery minimum, free delivery, KDS order type).
- **Privacy**: add-on selections are found by the personal-data exporter and
  eraser (they are stored under display-name keys, now indexed in
  `_lafka_addon_keys`); older orders are matched by the product's add-on names.
- **Uninstall**: the full-cleanup inventory covers every option the plugin
  writes (`lafka_checkout_mode`, `lafka_email_unsub_list`,
  `lafka_security_options`, combo-deal settings, …); theme options are kept.
- **Analytics**: the menu `search` event fires (it bound the search `<form>`),
  and `add_shipping_info` / `add_payment_info` carry the cart items.
- **SEO**: no second shop-archive canonical when an SEO plugin is active; WC's
  Product schema is kept when Lafka yields structured data to an SEO plugin.
- **Assets**: the plugin no longer overrides theme-registered script handles
  (the theme's `defer` strategy wins); wp-admin no longer loads Google Maps
  without an API key; swatch assets are cache-busted by their real paths.
- **Shortcodes**: `[lafka_shipping_areas]` works without WPBakery and loads its
  map script from the right URL.
- **Admin**: Lafka fields entered on the "Add New" term form (categories, tags,
  branches) are saved; "Show in Catalog" can be unticked on the last variation;
  the delivery-area polygon input is a complete element; stale "Theme Options"
  pointers name the real Google Maps key settings; Site Health names the right
  security option and WP-CLI command.
- **Last order card**: the signed cookie is set before output
  (`template_redirect` on the order-received page, key-checked).
- **Menu**: removed the mobile nav-walker sort filter that fataled (it called
  protected methods) on themes rendering a `mobile` menu location.
- **i18n**: remaining hard-coded English (abandoned-cart email table headers,
  best-seller badge, metabox Yes/No/Show/Hide) is translatable; the POT is
  regenerated (1661 strings) and versioned with the SSOT.
- **Copy**: the checkout win-back field no longer promises an email that is
  never sent.
- **Order hours**: an empty or invalid branch timezone falls back to the site
  timezone instead of fataling the closed-store card and branch status.
- **Promotions**: the BOGO cart label and banner state the configured discount
  (e.g. "25% Off", "Free") instead of always "50% Off".
- **SEO**: WooCommerce's BreadcrumbList is kept when Lafka yields structured
  data to an SEO plugin.
- **Performance**: the LCP preload and fetchpriority hints resolve the same
  homepage hero (they read different legacy keys).
- **Admin**: the food-menu metabox save no longer warns about, or blanks,
  weight / nutrition fields a request didn't send; swatch colour terms without
  a colour no longer render invalid CSS.
- **CLI**: `wp lafka image-alts … --post-type=X` skips unattached images.
- **Admin**: the SEO meta-description character counter no longer throws a JS
  error on every post/page/product edit screen (its textarea shared the
  metabox's id).
- **i18n**: the product-popup and promo-tooltip copy (`lafka` option) is
  registered in the plugin's `wpml-config.xml`.
- **Assets**: the `lafka-dialog` fallback uses the theme's `.min` build only
  when it exists.
- **Checkout**: implicit (hidden) branch / order-type values are filled into
  the session before the Store API gates run, so the delivery geo-fence and
  order-type meta work on single-branch / single-order-type block checkouts.
- **Analytics**: the once-per-order purchase gate uses the order CRUD API, so
  it is correct on HPOS-only stores instead of touching unrelated posts.
- **Uninstall**: only Lafka's own swatch attribute types (color, image, label)
  are reverted; other plugins' types are left alone.
- **New-order alerts**: notified-order state is per shop manager, so one
  manager's poll no longer consumes the alert for everyone else.
- **Conversion**: default-OFF abandoned-cart and web-push modules no longer
  create tables or schedule cron events until enabled.
- **Promotions**: the settings page is reachable while the module is OFF, and
  a one-time notice tells lafka-child upgraders to enable it.
- **Admin / KDS**: no inline storefront styling on the KDS rejected state;
  nutrition admin CSS is scoped to the product-edit screen.
- **Release**: readme.txt `Stable tag` joins the version SSOT; the GPL
  `LICENSE` now ships in the release zip.
- **Menu**: `group_terms()` exposes the grouped-mobile-menu contract as a
  term-level API (the walker hooks were dead).
- **Modules**: Lafka → Modules no longer links every card to a 404 docs page.
- Docs fact-check: shipped-state claims corrected; planning docs retired.
- **Abandoned cart**: the recovery email's resume link restores the cart for
  guests again. The handler ran on `init`, before WooCommerce sets its
  session and cart cookies (and before it loads the session cart on
  `wp_loaded`), so the restored items were never saved and the visitor
  landed on an empty cart; it now runs on `wp_loaded` priority 20.
- **Push**: the VAPID contact defaults to `mailto:` plus the site admin email
  (filter `lafka_push_default_vapid_subject`) instead of a placeholder
  address; sites still on the old placeholder get the new default. If cURL
  rejects any transfer-hardening option, the send now fails closed.
- **Widgets**: newly added About, Payment Options and Popular Posts widgets
  no longer log undefined-index warnings; Popular Posts saves unslashed input
  with a post count of at least 1; About omits "Read more" until a page is
  chosen; Contacts reads the restaurant info once per render.
- **Security headers**: a filtered header name or value containing a line
  break is dropped instead of reaching `header()`.

### Removed
- The per-page "Header size" / "Footer size" layout options (the theme no longer
  reads them since its pre-handoff header/footer CSS was removed); stored meta is kept.
- `lafka_mobile_menu_sort_by_group()`, `lafka_mobile_menu_grouped_walker_filter()`
  and the `LafkaMobileGroupedWalker` nav-walker methods (the class keeps
  `group_terms()`); `lafka_combo_cart_has_pair()`; `Lafka_Options::get_all()`;
  `Lafka_Engine_Display::prevent_purchase_at_grouped_level()`.
- The WC settings "Homepage Hero" section (its option was never read — the
  Customizer's Homepage Hero is the setting), the "Secondary Google Maps API
  Key" setting, and the page/post metabox inputs nothing read (Top Menu Bar,
  Social Share, Footer Sidebar, video-background timing/loop/mute; stored
  values are kept). Matching `wpml-config.xml` entries are dropped.
- Dead WCML add-on compat for the WooCommerce Product Add-Ons v1 panels, the
  handler-less `wc_product_addons_calculate_tax` request in `addons.js`, and
  unreachable branches in abandoned-cart email capture.
- The `WC_PRODUCT_ADDONS_VERSION` constant (see Fixed).

### Removed (lean pass)
- The retired Options-Framework import/export (`lafka_options_upload` /
  `lafka_options_export` and the `lafka-plugin-admin.js` + plupload enqueue
  on every admin page). Use `wp lafka config` or Lafka → Tools.
- `scripts/migrate-restaurant-info.php` (superseded by `wp lafka config`).
- Dead assets, the inert `incl/emails/` review-prompt shim, and uncalled
  helpers; obsolete per-feature version-floor tests.

### Changed
- Every first-party minified script ships next to a readable source (the eight
  shipping-areas / branch scripts' sources are new); `npm run build`
  regenerates the `.min.js` files (esbuild, minify only), release.yml runs it
  and CI fails if a committed build is stale. `SCRIPT_DEBUG` loads the sources.
- A bundled Font Awesome Free 6.7.2 backs `font_awesome_6` when the active
  theme does not register it; `CREDITS.md` lists the bundled libraries.
- PHPCS checks the PHP 8.1 floor with PHPCompatibility 10 (alpha); PHP 8.5
  deprecations fixed (`imagedestroy()`, `curl_close()`, `openssl_pkey_derive()`
  key length).
- Script/style handle registration moved to `incl/lafka-asset-registration.php`.
- The email unsubscribe helpers live once in
  `incl/conversion/lafka-email-unsubscribe.php`.
- New filters: `lafka_pickup_shipping_method_ids`,
  `lafka_branch_order_count_statuses`, `lafka_push_default_vapid_subject`,
  `lafka_ac_resume_redirect_exit` (whether the cart-resume redirect exits;
  default true).
- The OpenGraph/Twitter tags, meta description and `<html lang>` filter
  (`incl/seo/lafka-head-meta.php`), share links (`incl/lafka-share-links.php`),
  `[lafka_nap]` (`incl/schema/lafka-nap-shortcode.php`) and
  `lafka_seo_plugin_active()` (`incl/seo/lafka-seo-plugin-detect.php`) moved
  out of `lafka-plugin.php`; function names, hooks and priorities are
  unchanged. `lafka_push_curl_options()` and
  `Lafka_Security_Headers::build_headers()` split the pure parts out of the
  push sender and the header sender.
- Test suite rationalised: tests execute the code and assert behaviour instead
  of grepping source for implementation strings, comments or existence (1518
  tests / 4319 assertions → 1160 / 2839, 154 → 146 files, plus 15 node:test
  JS tests); the operator-literal guard stores only hashes; the bootstrap
  records hook registrations so wiring is asserted by running registration
  code.

### Performance
- Shipping-area front CSS loads only on cart/checkout (or sitewide while branch
  selection is on); shipping-area and branch admin assets only on their
  screens.
- Development: PHPCS runs in parallel with a result cache (51.6 s cold → 9.9 s,
  0.8 s unchanged), ESLint/Stylelint cache, PHPUnit result cache, and the
  pre-push hook runs only the affected gates in parallel.

## [10.0.0] — 2026-07-07

Phase NX1 ("Platform & Configurability Foundation") release.

### Added
- **Feature Modules dashboard** (Lafka → Modules): every gated module —
  addons, shipping areas, order hours, KDS, promotions, order notifications,
  abandoned cart, web push, review prompts, analytics — visible and toggleable
  from one screen,
  backed by a typed module registry that Site Health also reads.
- **Store API parity for every ordering gate**: store-closed, branch
  order-type capability, timeslot validity + capacity, and delivery geo-fence
  are enforced on block cart/checkout and headless clients exactly as on
  classic checkout; a `lafka` cart schema extension exposes order type,
  branch, timeslot, open-now/next-open, and free-delivery / delivery-minimum
  progress; a cart update callback writes branch/order-type/timeslot to the
  session with full validation.
- **Block Cart/Checkout support** (closes long-blocked P3-01): order-type and
  branch checkout fields (Additional Checkout Fields API), a build-free
  timeslot picker, free-delivery progress on block cart, and the
  `cart_checkout_blocks` compatibility declaration alongside HPOS.
- **Addons engine over the Store API**: addon selections ride
  `extensions.lafka.addons` through the engine's own sanitization/validation
  pipeline with per-pricing-strategy price parity and identical order-item
  meta between classic and block paths.
- **Settings export/import** (`wp lafka config export|import [--dry-run]` +
  Lafka → Tools with a dry-run diff): 9 config sections; secrets are never
  exported.
- **Demo seeder** (`wp lafka seed-demo`): deterministic 12-product restaurant
  with addons, branch, delivery polygon, hours; idempotent with `--reset`.
- **Privacy**: GDPR personal-data exporters/erasers for push subscriptions
  and abandoned carts; documented retention windows.
- **Opt-in full-data uninstall** with an inventory-driven cleanup class.
- Order-notification admin poller (moved from the theme; wp.org theme-review
  blocker resolved), HPOS-safe.
- Canonical menu-URL resolver `lafka_get_menu_url()` (audit #97 closed).
- wp.org-format `readme.txt`.

### Changed
- **Checkout mode SSOT** (`lafka_checkout_mode`): fresh installs default to
  block checkout; **existing installs are migrated to explicit `classic`** so
  their live checkout never changes on update. `lafka_force_classic_checkout`
  filter overrides.
- COMPATIBILITY.md now states CI reality (single-PHP runner + static
  PHPCompatibility floor) and the block-checkout support matrix.
- Operator docs (LOCAL_SEO, PERFORMANCE) genericized — no operator literals.
- Release zips exclude dev-only files (449 → 280 files).

### Fixed
- Store-closed gate on Store API checkout was hooked to
  `woocommerce_store_api_validate_cart`, which WooCommerce never fires — a
  closed store could accept block-checkout orders. Now enforced on the real
  cart-errors/checkout path (verified live with a 409).
- `flat_group` addon pricing under-charge for seeded combo options.
- First-run demo seeding failed to create the branch when the shipping-areas
  module was still gated off (taxonomy self-registration).
- i18n: repo-wide gettext-domain guard hardened; catalog regenerated.

### Security
- Config bundles strip all secret-shaped keys (API keys, VAPID, tokens,
  pixel/measurement/container IDs) on export and import.

### Compatibility
- Requires WP 6.6+ / PHP 8.1+ / WooCommerce 9.5+ (tested to WP 7.0 / WC 10.9).
- Best experienced with lafka-theme ≥ 7.0.0 (block-checkout skin); the plugin
  remains theme-agnostic.

### Upgrade notes
- **Promotions** (BOGO + delivery minimum) is a default-OFF plugin module; the
  lafka-child 6.x implementation is gone. Sites upgrading from lafka-child
  ≤ 5.x must enable **Lafka → Modules → Promotions** and click-test BOGO and
  the delivery minimum on the cart.
