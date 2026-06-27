# Contributing to lafka-plugin

This is the companion plugin for the Lafka theme. It owns business logic (CPTs, shop modules, addons engine, KDS, shipping areas, promotions, analytics, conversion) so it survives theme switches. (Demo-content import lives in the theme — `incl/LafkaTransferContent.class.php` — not here.)

## Local development

```bash
npm ci
composer install

# Boot a full WP + WC + this plugin
npx @wordpress/env start
# WP runs at http://localhost:8883
# Tests-WP runs at http://localhost:8884
```

## Before opening a PR

```bash
npm run lint        # ESLint + Stylelint
composer phpcs
composer phpcbf
```

## Architecture (short version)

- `lafka-plugin.php` — bootstrap, CPT/taxonomy registration, AJAX endpoints, asset enqueues, HPOS + Cart-Checkout-Blocks compat declaration.
- `incl/` — feature modules. Each gated behind `is_lafka_<feature>()` reading from `Lafka_Options`:
  - `addons/` — WooCommerce product addons; the v2 **engine** lives in `addons/engine/` (resolver, pricing strategies, `cart/`, `display/`, `admin/`, REST `api/`, `cli/`, `compat/` WC Product Bundles bridge, `data/`, `sources/`, `migrations/`). Bundles/combos are now the official WC Product Bundles plugin bridged here — the old `combos/` fork was removed in v9.0.0.
  - `nutrition/` — nutrition labels for food-menu items
  - `order-hours/` — store-hours and holiday closures
  - `shipping-areas/` — delivery zones + branch picker
  - `swatches/` — variation swatches
  - `kitchen-display/` — KDS for staff
  - `promotions/` — BOGO + delivery-minimum (migrated from lafka-child; `class-lafka-promotions.php` `@since` 8.7.0)
  - `analytics/` — GA4/GTM, Consent Mode v2, WC `dataLayer` + custom events
  - `conversion/` — abandoned-cart capture/cron/email/resume, web-push, review prompts
  - `schema/` — JSON-LD + `lafka_get_restaurant_info()` resolver
  - `wpml/` — WPML/WCML translation glue
- `shortcodes/` — 23 shortcodes + WPBakery/VC mappings.
- `widgets/` — 6 widgets (5 standalone + 1 WC-dependent).

## Where new code goes

| If you're adding... | Put it in... |
|---------------------|--------------|
| A new CPT or taxonomy | `lafka-plugin.php` (registration) + a new `incl/<feature>/` module if it has logic |
| A new shortcode | `shortcodes/` + add VC mapping in `shortcodes_to_vc_mapping.php` |
| A WC product behavior | `incl/addons/` (engine in `addons/engine/`) if related; otherwise a new module |
| A new module entirely | New folder under `incl/`; gate with `is_lafka_<thing>()`; load conditionally from `lafka-plugin.php` |
| Site-specific business logic | NOT here — put it in `lafka-child` |

## Coding standards

- WordPress-Extra (PHPCS) with short arrays.
- Min PHP 8.1, min WP 6.6, min WC 9.5.
- Text domain: `lafka-plugin`.
- All public-by-default AJAX (`_nopriv_`) handlers MUST: verify nonce, sanitize input, escape output, gate by capability where appropriate.
- All `$wpdb` queries MUST use `prepare()` or be string-literal.
- All `_FILES` uploads MUST validate MIME server-side and check `is_uploaded_file()`.

## HPOS / Blocks

The plugin declares HPOS compat in `lafka-plugin.php`. Cart/Checkout Blocks compatibility is a roadmap item (see `LAFKA_AUDIT.md` §4 M-HIGH-1) — don't declare compat until the integration ships.

## Releases

Tagging `vX.Y.Z` triggers `.github/workflows/release.yml`. Zip excludes dev files (`.git`, `node_modules`, `vendor`, lint configs).

## Security

Email security issues to security@setkernel.com (or the equivalent maintained channel). Never use public issues.
