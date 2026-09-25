# Lafka Plugin

Restaurant-ordering plugin for WooCommerce, built as the companion to the [Lafka WordPress Theme](https://github.com/setkernel/lafka-theme). Adds restaurant menu management, product add-ons, delivery zones, store hours, a kitchen display, local-SEO schema and 25+ shortcodes. The plugin is theme-agnostic: it emits default markup and works on any WooCommerce-ready theme.

Originally developed by [theAlThemist](https://www.althemist.com). Continued as open-source under GPL v2+.

## Requirements

- WordPress 6.6+
- WooCommerce 9.5+
- PHP 8.1+
- Recommended (not required): the [Lafka Theme](https://github.com/setkernel/lafka-theme), which ships the matching styling

These match the floor declared in `lafka-plugin.php` (`Requires at least:` / `Requires PHP:` / `WC requires at least:`). The plugin will fatal-error or behave unexpectedly on older versions. WC tested up to: 11.1.

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
- **Restaurant Menu** (`lafka-foodmenu`) — Menu items with categories, prices, images
- **Shipping Areas** (`lafka_shipping_areas`) — Delivery zone management
- **Product Addons** (`lafka_glb_addon`) — Global addon groups

For bundled / composite products, install the official **[WooCommerce Product Bundles](https://woocommerce.com/products/product-bundles/)** plugin. Lafka's addons engine bridges into it via `incl/addons/engine/compat/class-bundles-addons-compatibility.php` (since v9.6.0). The legacy `wc_combined_product` fork was removed in v9.0.0.

### Shortcodes (26)
Most shortcodes are also mapped as WPBakery elements; WPBakery itself is optional (see `incl/compat/lafka-wpbakery-fallback.php`).

| Shortcode | Description |
|---|---|
| `[lafka_foodmenu]` | Restaurant menu display with filtering |
| `[lafka_banner]` | Banner with image, text, button |
| `[lafka_counter]` | Animated number counter |
| `[lafka_typed]` | Typed.js text animation |
| `[lafka_icon_box]` / `[lafka_icon_teaser]` | Icon blocks |
| `[lafka_pricing_table]` | Pricing tables |
| `[lafka_countdown]` | Countdown timer |
| `[lafka_map]` | Google Maps with directions |
| `[lafka_contact_form]` | Ajax contact form |
| `[lafka_latest_posts]` / `[lafkablogposts]` | Blog grids/carousels |
| `[lafka_woo_*]` | 9 WooCommerce product carousels / sliders (top-rated, recent, featured, sale, best-selling, category, categories, recently viewed, products slider) |
| `[lafka_cloudzoom_gallery]` | Product image gallery |
| `[lafka_content_slider]` | Tabbed content slider — WPBakery only (registered by WPBakery's Tabs class) |
| `[lafka_nap]` | Canonical name / address / phone block with Restaurant schema |
| `[lafka_shipping_areas]` | Delivery-area map (Delivery areas module) |
| `[lafka_wcmp_vendorslist]` | Vendor list — only when WC Marketplace (WCMp) is active |

### Widgets
- About, Contacts, Latest Menu Entries, Payment Options, Popular Posts, Product Filter

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
- **Performance** — Image-dimension auto-injection (CLS), LCP preload, Revslider auto-dequeue
- **Analytics** (`incl/analytics/`) — GA4 / GTM with Consent Mode v2 defaults, `dataLayer` WooCommerce ecommerce events, and custom event hooks
- **Conversion** (`incl/conversion/`) — Abandoned-cart capture / cron / DB / email / resume, web-push (db / REST / sender / re-order cron), and review-prompt banner + email

### Icon Packs
- Elegant Icons (etline) — 100+ icons
- Flaticon Food Icons — 50+ food-specific icons

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
│   ├── compat/          # Block-cart shim, WPBakery/Revslider fallbacks, address-autocomplete compat, WP Importer ↔ WC attrs bridge
│   ├── conversion/      # Abandoned-cart + web-push + review prompts
│   ├── kitchen-display/ # KDS state machine + AJAX + emails
│   ├── map-shortcode/   # [lafka_map] (split from shipping-areas v9.3.0)
│   ├── menu/            # Mobile grouped walker
│   ├── nutrition/       # Per-product nutrition facts
│   ├── order-hours/     # Open/close scheduling
│   ├── perf/            # Image dimensions, LCP preload, asset pruning
│   ├── promotions/      # BOGO + delivery minimum (migrated from child v6.0.0)
│   ├── schema/          # JSON-LD + lafka_get_restaurant_info() resolver
│   ├── security/        # Headers + REST user-enum block
│   ├── seo/             # Shop archive canonical
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
├── languages/           # Translation files
└── lafka-plugin.php     # Main plugin file
```

## Development

Standard local checks:

```bash
composer install        # PHPCS + WPCS + PHPUnit + Brain Monkey
npm ci                  # ESLint + Stylelint

composer phpcs          # WordPress-Extra + PHPCompatibility (8.1 floor); parallel + cached
composer test           # PHPUnit (Brain Monkey)
npm run lint            # ESLint + Stylelint (cached)
npm test                # front-end JS behaviour tests (node:test)
npm run build           # regenerate every .min.js from its readable source (esbuild)
npm run check-version   # version SSOT drift guard
```

Minified scripts are build output: edit the `.js` source next to each `.min.js`,
run `npm run build`, and commit both (CI fails on a stale build). With
`SCRIPT_DEBUG` on, WordPress loads the sources.

A pre-push git hook is shipped under `.githooks/` that runs the gates the pushed commits can affect (check-version, then PHPCS + PHPUnit and/or ESLint, Stylelint, JS tests and the build check, in parallel) — install once per clone:

```bash
git config core.hooksPath .githooks
```

To bypass for a single push: `git push --no-verify`.

## License

GPL v2 or later. See [LICENSE](LICENSE).
