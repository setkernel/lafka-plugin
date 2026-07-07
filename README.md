# Lafka Plugin

Companion plugin for the [Lafka WordPress Theme](https://github.com/setkernel/lafka-theme). Adds restaurant menu management, product addons, combos, delivery zones, store hours, and 20+ shortcodes.

Originally developed by [theAlThemist](https://www.althemist.com). Continued as open-source under GPL v2+.

## Requirements

- WordPress 6.6+
- WooCommerce 9.5+
- PHP 8.1+
- [Lafka Theme](https://github.com/setkernel/lafka-theme)

These match the floor declared in `lafka-plugin.php` (`Requires at least:` / `Requires PHP:` / `WC requires at least:`). The plugin will fatal-error or behave unexpectedly on older versions. WC tested up to: 10.9.

## Installation

1. Download or clone this repository into `wp-content/plugins/lafka-plugin`
2. Activate in WordPress Admin → Plugins
3. Or: Install automatically via TGM prompt when activating the Lafka theme

## First-time setup

The plugin ships with **zero hardcoded restaurant data** — every public NAP / hours / geo / social value is operator-configurable. After activation:

1. **Seed your restaurant info via WP-CLI** (preferred — idempotent, ship-ready):

   ```bash
   # Copy the sample, fill in your business details
   cp wp-content/plugins/lafka-plugin/scripts/sample-restaurant-info.json my-restaurant.json
   $EDITOR my-restaurant.json

   # Dry-run first
   LAFKA_RESTAURANT_INFO_DRY_RUN=1 wp eval-file wp-content/plugins/lafka-plugin/scripts/migrate-restaurant-info.php --path=/path/to/wp my-restaurant.json

   # Then for real
   wp eval-file wp-content/plugins/lafka-plugin/scripts/migrate-restaurant-info.php --path=/path/to/wp my-restaurant.json
   ```

   The 24 `lafka_business_*` theme_mods cover: name, address, phone, email, lat/lng, opening hours (7-day array), URL, accepted payments, served cuisine, accepts reservations, sameAs (5 social URLs), price-range, takeaway/delivery booleans.

2. **Or configure via Customizer** — Appearance → Customize → "Lafka — Restaurant Information" panel (7 sections, 24 settings). The same `theme_mod` keys.

3. **Verify** — every Lafka-emitted JSON-LD schema, the `[lafka_nap]` shortcode, the contacts widget, and the editorial templates now pull from your operator config. No literals.

4. **Place `[lafka_nap]`** anywhere you want the canonical NAP block (Restaurant Schema + visible HTML).

`lafka_get_restaurant_info()` is the canonical resolver — defined in `incl/schema/lafka-schema-helpers.php`. Reads `theme_mod` → WP core fallback → empty. Filterable via `lafka_restaurant_info`.

## Features

### Custom Post Types
- **Restaurant Menu** (`lafka-foodmenu`) — Menu items with categories, prices, images
- **Shipping Areas** (`lafka_shipping_areas`) — Delivery zone management
- **Product Addons** (`lafka_glb_addon`) — Global addon groups

For bundled / composite products, install the official **[WooCommerce Product Bundles](https://woocommerce.com/products/product-bundles/)** plugin. Lafka's addons engine bridges into it via `incl/addons/engine/compat/class-bundles-addons-compatibility.php` (since v9.6.0). The legacy `wc_combined_product` fork was removed in v9.0.0.

### Shortcodes (20+)
All shortcodes are also available as WPBakery/Visual Composer elements.

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
| `[lafka_woo_*_carousel]` | 8 WooCommerce product carousels |
| `[lafka_cloudzoom_gallery]` | Product image gallery |
| `[lafka_content_slider]` | Tabbed content slider |

### Widgets
- About, Contacts, Latest Menu Entries, Payment Options, Popular Posts, Product Filter

### Modules (conditionally loaded via theme options)
- **Product Addons** (engine v2 since v8.13.0) — Text, textarea, checkbox, radio fields per product; 4 pricing strategies; WPML-aware; bridges into WC Product Bundles
- **Order Hours** — Store open/close scheduling, holidays, branch-specific with timezone overrides
- **Shipping Areas** (decomposed v9.2-9.4) — Delivery zones + dedicated `branches/`, `timeslots/`, `map-shortcode/` sub-modules
- **Promotions** (migrated from child v6.0.0) — BOGO 50% math + delivery-minimum gate
- **Kitchen Display System (KDS)** — Order state machine, rate-limited AJAX, customer-view, email triggers
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
│   ├── admin/           # Meta-description box, WC Settings → Restaurant tab, push admin
│   ├── analytics/       # GA4/GTM, Consent Mode v2, WC dataLayer + custom events
│   ├── branches/        # Branch selection AJAX (split from shipping-areas v9.2.0)
│   ├── checkout/        # Block checkout: mode migration, additional fields, blocks integration (v10.0.0)
│   ├── cli/             # WP-CLI commands (image-alt backfill, reviews)
│   ├── customizer/      # Restaurant Info / PDP / Upsell / Abandoned-Cart / Analytics / Push / Reviews panels
│   ├── compat/          # Block-cart shim, WP Importer ↔ WC attrs bridge
│   ├── conversion/      # Abandoned-cart + web-push + review prompts
│   ├── emails/          # Review prompt email
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
├── scripts/             # Operator onboarding (migrate-restaurant-info.php + sample JSON)
├── assets/              # JS, CSS, images
├── languages/           # Translation files
└── lafka-plugin.php     # Main plugin file
```

## Development

Standard local checks:

```bash
composer install        # PHPCS + WPCS + PHPUnit + Brain Monkey
npm ci                  # ESLint + Stylelint

composer phpcs          # full WordPress-Extra ruleset (security sniffs enforced)
composer test           # PHPUnit (Brain Monkey)
npm run lint            # ESLint + Stylelint
```

A pre-push git hook is shipped under `.githooks/` that runs all four gates before any push — install once per clone:

```bash
git config core.hooksPath .githooks
```

To bypass for a single push: `git push --no-verify`.

## License

GPL v2 or later. See [LICENSE](LICENSE).
