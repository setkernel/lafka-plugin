# Lafka Plugin

Restaurant-ordering plugin for WooCommerce, built as the companion to the [Lafka WordPress Theme](https://github.com/setkernel/lafka-theme). Adds restaurant menu management, product add-ons, delivery zones, store hours, a kitchen display, local-SEO schema and 25+ shortcodes. The plugin is theme-agnostic: it emits default markup and works on any WooCommerce-ready theme.

Originally developed by [theAlThemist](https://www.althemist.com). Continued as open-source under GPL v2+.

## Requirements & compatibility

| Component | Minimum | Tested up to |
|-----------|---------|--------------|
| WordPress | 7.0 | 7.1 |
| WooCommerce | 11.0 | 11.2 |
| PHP | 8.3 | latest (syntax-linted) |

The plugin header in `lafka-plugin.php` is the source for these numbers
(`Requires at least`, `Requires PHP`, `WC requires at least`, `WC tested up to`).
They are mirrored in `readme.txt`, `composer.json` (`php`) and `.phpcs.xml.dist`
(`testVersion`, `minimum_wp_version`); change the header first, then those. The
code is written against the floors, with no `version_compare()` branches or
polyfills, and older versions are unsupported: the header blocks activation on
older PHP and WordPress.

- **Theme:** optional. The plugin works on any WooCommerce-ready theme; the
  [Lafka Theme](https://github.com/setkernel/lafka-theme) ships the matching
  styling. Releases of the three repos are independent.
- **Browsers:** current evergreen browsers (Safari 14+, Chrome, Firefox and Edge
  100+). IE11 is not supported.
- **HPOS:** declared compatible (`custom_order_tables`). Order code supports both
  the custom-orders-table and legacy post storage: checkout meta is written on
  `woocommerce_checkout_create_order`, and custom filters use `wc_get_orders()`.
- **Block Cart and Checkout:** declared compatible (`cart_checkout_blocks`). Both the
  block and the classic shortcode checkout are supported, chosen under **Lafka →
  Modules → Checkout experience** (option `lafka_checkout_mode`). Fresh installs
  default to blocks; installs that existed before 10.0.0 were migrated to classic so
  nothing changed on update. The `lafka_force_classic_checkout` filter pins classic.
  On the block path every gate the classic checkout enforces holds (order hours,
  delivery geo-fence, time-slot validity and capacity, branch and order-type rules)
  through the Store API (`incl/store-api/`). Add-on selections reach cart lines,
  totals and order-item meta the same way as on classic. Order type and branch are
  block checkout additional fields, and a time-slot picker and free-delivery
  progress bar are build-free scripts in `incl/checkout/`. In classic mode,
  `incl/compat/class-lafka-block-cart-shim.php` rewrites unedited default block
  Cart and Checkout pages to the shortcodes and keeps the original markup so the
  switch is reversible. It never touches pages an operator edited.
- **Google Maps:** the loader (`lafka-google-maps`) is registered only when a key is
  set (Customizer, Google Maps API key, stored as `lafka[google_maps_api_key]`).
  Without one, `[lafka_map]` and `[lafka_shipping_areas]` show an admin-only notice,
  map pickers fall back to dropdowns and text inputs, and server-side geo-fence
  validation still gates orders.
- **Server hardening:** the security-headers toggle (Tools, Lafka Security) strips
  `X-Powered-By`. The `Server:` header comes from the web server: use
  `ServerTokens Prod` and `ServerSignature Off` on Apache, or `server_tokens off;`
  on Nginx.

### Checks

`composer lint:php`, `composer phpcs`, `npm run lint`, `npm run check-version` and
build freshness (`npm run build`) run in `.githooks/pre-push` and in CI
(`.github/workflows/ci.yml`). There is no test suite. PHPCS runs full
WordPress-Extra with no excluded sniffs and warnings failing, plus
PHPCompatibilityWP at the PHP floor (a static check; CI uses the runner's single
PHP). The WordPress.org Plugin Check runs in `plugin-check.yml` against the same
tree the release zips.

Third-party libraries and their licences are listed in
`readme.txt` under "Third-party libraries".

## Installation

1. Download or clone this repository into `wp-content/plugins/lafka-plugin`
2. Activate in WordPress Admin → Plugins
3. Or: Install automatically via TGM prompt when activating the Lafka theme

## First-time setup

The plugin ships with **zero hardcoded restaurant data** — every public NAP / hours / geo / social value is operator-configurable. After activation:

1. **Enter your restaurant info** in either place:
   - **WooCommerce → Settings → Restaurant** (canonical) — hours, cuisine, payment methods, schema type, price range, phone, email, geo and sameAs profiles. These write the canonical `lafka_business_*` options. The street address comes from **WooCommerce → Settings → General** (store address) unless you override it.
   - **Appearance → Customize → "Lafka — Restaurant Information"** (8 sections, 26 settings) — the same `lafka_business_*` keys stored as `theme_mod`s, plus the homepage-hero image, default OG image and default locale. Customizer values are a **fallback**: a non-empty option always wins.

2. **Or seed / copy it via WP-CLI** (round-trippable config bundle):

   ```bash
   wp lafka config export --file=lafka-config.json   # dump the current config
   $EDITOR lafka-config.json                          # edit sections.business
   wp lafka config import --file=lafka-config.json --dry-run   # review the diff
   wp lafka config import --file=lafka-config.json             # apply
   ```

   The `business` section writes the 23 canonical `lafka_business_*` options: `name`, `business_type`, `price_range`, `street`, `city`, `region`, `postal`, `country`, `geo_lat`, `geo_lng`, `phone_e164`, `phone_display`, `email`, `cuisines`, `payment_methods`, `same_as`, and `hours_mon` … `hours_sun`. The same export/import is available in the admin under **Lafka → Tools**.

3. **Verify** — every Lafka-emitted JSON-LD schema, the `[lafka_nap]` shortcode, the contacts widget, and the editorial templates now pull from your operator config. No literals.

4. **Place `[lafka_nap]`** anywhere you want the canonical NAP block (Restaurant Schema + visible HTML).

`lafka_get_restaurant_info()` is the canonical resolver — defined in `incl/schema/lafka-schema-helpers.php`. Per field it reads the `lafka_business_*` option → the `lafka_business_*` theme_mod → the WooCommerce store address / phone → a default (site name / admin email / empty). Filterable via `lafka_restaurant_info`.

## Features

### Custom Post Types
- **Shipping Areas** (`lafka_shipping_areas`) — Delivery zone management
- **Product Addons** (`lafka_glb_addon`) — Global addon groups

For bundled / composite products, install the official **[WooCommerce Product Bundles](https://woocommerce.com/products/product-bundles/)** plugin. Lafka's addons engine bridges into it via `incl/addons/engine/compat/class-lafka-bundles-addons-compatibility.php`.

### Shortcodes
| Shortcode | Description |
|---|---|
| `[lafka_nap]` | Canonical name / address / phone block with Restaurant schema |
| `[lafka_shipping_areas]` | Delivery-area map (Delivery areas module) |

The page-builder-era shortcodes (`[lafka_banner]`, `[lafka_counter]`, `[lafka_map]`,
`[lafka_contact_form]`, `[lafka_woo_*]` carousels and the rest) are retired.
`shortcodes/shortcodes.php` keeps their tags registered so old pages never print them as
raw text: each renders only the content it encloses. Old pages built with WPBakery or
Slider Revolution still render cleanly: `incl/compat/lafka-wpbakery-fallback.php` strips
their orphaned `[vc_*]` / `[rev_slider]` tags from stored content. Neither plugin is
supported or required.

### Widgets
- About, Contacts, Payment Options, Popular Posts, Product Filter

### Modules (toggled at Lafka → Modules)
Gated features are declared in `Lafka_Module_Registry` (`incl/class-lafka-module-registry.php`) and switched on or off from the **Lafka → Modules** admin page. Everything is off by default except Product add-ons.
- **Product add-ons** (default ON; engine v2 since v8.13.0) — Text, textarea, checkbox, radio fields per product; 4 pricing strategies; WPML-aware; bridges into WC Product Bundles
- **Delivery areas & branches** (decomposed v9.2-9.4) — Delivery zones + dedicated `branches/`, `timeslots/`, `map-shortcode/` sub-modules
- **Order hours** — Store open/close scheduling, holidays, branch-specific with timezone overrides
- **Kitchen display (KDS)** — Order state machine, rate-limited AJAX, customer-view, email triggers
- **Promotions** (default OFF; moved from lafka-child in child 6.0.0) — BOGO 50% math + delivery-minimum gate. **Upgrading from lafka-child ≤ 5.x:** the child no longer ships promotions, so enable Lafka → Modules → Promotions and click-test BOGO + the delivery minimum.
- **New-order alerts** (order notifications) — Browser notification + sound for shop managers on each new order, routed to the branch operator
- **Abandoned cart recovery**, **Web push**, **Review requests** — see Conversion below
- **Analytics & tracking** — read-only in the Modules page; active whenever a destination is configured

### Always-on subsystems
- **Nutrition & Allergens** — Per-product nutrition facts, filterable daily-intake refs
- **Variation Swatches** — Color and image swatches per attribute term
- **Schema / JSON-LD** — Restaurant / LocalBusiness / Menu / MenuItem / Product / BreadcrumbList graph
- **Security Headers** — X-Content-Type-Options, X-Frame-Options, Referrer-Policy, Permissions-Policy + REST user-enum blocking
- **Performance** — Image-dimension auto-injection (CLS), LCP preload, unused-asset pruning
- **Analytics** (`incl/analytics/`) — GA4 / GTM with Consent Mode v2 defaults, `dataLayer` WooCommerce ecommerce events, and custom event hooks
- **Conversion** (`incl/conversion/`) — Abandoned-cart capture / cron / DB / email / resume, web-push (db / REST / sender / re-order cron), and review-prompt banner + email

## Structure

```
lafka-plugin/
├── incl/
│   ├── addons/          # Engine v2 under addons/engine/ (resolver, pricing strategies, REST api/, cli/, compat WC Bundles bridge)
│   ├── admin/           # Lafka → Modules + Tools pages, WC Settings → Restaurant tab, new-order alerts, push admin, meta-description box
│   ├── analytics/       # GA4/GTM, Consent Mode v2, WC dataLayer + custom events
│   ├── branches/        # Branch selection AJAX (split from shipping-areas v9.2.0)
│   ├── checkout/        # Block checkout: mode migration, additional fields, blocks integration (v10.0.0)
│   ├── cli/             # WP-CLI: `wp lafka config`, `seed-demo`, image-alt backfill, WebP convert, reviews
│   ├── customizer/      # Restaurant Info / PDP / Upsell / Abandoned-Cart / Analytics / Push / Reviews panels
│   ├── compat/          # Block-cart shim, orphaned-shortcode fallback, address-autocomplete compat
│   ├── conversion/      # Abandoned-cart + web-push + review prompts
│   ├── insights/        # First-party funnel sessions, nightly rollups, weekly insights email
│   ├── kitchen-display/ # KDS state machine + AJAX + emails
│   ├── map-shortcode/   # [lafka_map] (split from shipping-areas v9.3.0)
│   ├── menu/            # Mobile grouped walker
│   ├── nutrition/       # Per-product nutrition facts
│   ├── observability/   # Lafka_Log, fatal capture, incidents table, diagnostics + error digest
│   ├── order-hours/     # Open/close scheduling
│   ├── perf/            # Image dimensions, LCP preload, asset pruning
│   ├── promotions/      # BOGO + delivery minimum (migrated from child v6.0.0)
│   ├── schema/          # JSON-LD + lafka_get_restaurant_info() resolver
│   ├── security/        # Headers + REST user-enum block
│   ├── seo/             # Titles, head meta, canonical, sitemap + robots, llms.txt, IndexNow, term FAQs
│   ├── shipping-areas/  # Coordinator (delivery zones + CPT)
│   ├── site-health/     # WP Site Health integration
│   ├── store-api/       # Store API parity: cart validation + order-meta persistence (v10.0.0)
│   ├── swatches/        # Variation swatches
│   ├── timeslots/       # Date-picker + capacity (split from shipping-areas v9.4.0)
│   ├── tools/           # Config export/import bundle + uninstall cleanup
│   ├── woocommerce/     # W4 PDP redesign modules (bestseller, prep-time, last-order, upsell, drawer, etc.)
│   └── wpml/            # WPML/WCML addon compat
├── shortcodes/          # All shortcode definitions
├── widgets/             # Widget classes
├── scripts/             # Dev tooling (version sync) — not shipped in the release zip
├── assets/              # JS, CSS, images
├── docs/                # OPERATOR_GUIDE.md: diagnostics, local SEO, performance, tracking
├── languages/           # Translation files
└── lafka-plugin.php     # Main plugin file
```

## Operator guide

Diagnostics, local SEO, performance and tracking: [docs/OPERATOR_GUIDE.md](docs/OPERATOR_GUIDE.md).

## Development

See [CONTRIBUTING.md](CONTRIBUTING.md). The local stacks are in the umbrella repo's `local-env/`. Standard checks:

```bash
composer install        # PHPCS + WPCS + PHPCompatibility
npm ci                  # ESLint + Stylelint

composer lint:php       # PHP syntax at the floor
composer phpcs          # WordPress-Extra + PHPCompatibility at the PHP floor; parallel + cached
npm run lint            # ESLint + Stylelint (cached)
npm run build           # regenerate every .min.js from its readable source (esbuild)
npm run check-version   # version SSOT drift guard
```

Minified scripts are build output: edit the `.js` source next to each `.min.js`,
run `npm run build`, and commit both (CI fails on a stale build). With
`SCRIPT_DEBUG` on, WordPress loads the sources.

A pre-push git hook is shipped under `.githooks/` that runs the gates the pushed commits can affect (check-version, then PHPCS and/or ESLint, Stylelint and the build check, in parallel; it fails with the install command if `node_modules` or `vendor` is missing) — install once per clone:

```bash
git config core.hooksPath .githooks
```

## License

GPL v2 or later. See [LICENSE](LICENSE).
