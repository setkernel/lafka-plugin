# Changelog

All notable changes to lafka-plugin are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/); versions follow the repo's
semver (`npm version` is the single source of truth — see the Releases section
of CONTRIBUTING.md). Older history lives in git tags + GitHub Releases.

## [Unreleased]

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
