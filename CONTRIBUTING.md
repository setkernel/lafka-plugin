# Contributing to lafka-plugin

This is the companion plugin for the Lafka theme. It owns business logic (CPTs, shop modules, addons engine, KDS, shipping areas, promotions, analytics, conversion) so it survives theme switches. Deterministic demo content also lives here: `wp lafka seed-demo` (`incl/cli/class-lafka-cli-seed-demo.php`).

## Local development

```bash
npm ci
composer install

# Boot a full WP + WC + this plugin
npx @wordpress/env start
# WP runs at http://localhost:8883
# Tests-WP runs at http://localhost:8884

# Seed a deterministic demo restaurant (products, addons, branch, zone, hours)
npx @wordpress/env run cli wp lafka seed-demo          # add --reset to rebuild
```

## Before opening a PR

```bash
npm run lint           # ESLint + Stylelint
npm test               # front-end JS behaviour tests (node:test)
npm run build          # regenerate .min.js from sources — commit both
npm run check-version  # version SSOT drift guard
composer phpcs         # (composer phpcbf auto-fixes what it can)
composer test          # PHPUnit (Brain Monkey)
```

The `.githooks/pre-push` hook runs the affected gates in parallel (`git config core.hooksPath .githooks`).

Tests assert behaviour: execute the code (Brain Monkey stubs for WordPress,
`require_once` the module inside `setUp()`, never define WP functions as plain
globals) and check what it returns, renders or writes. Don't grep source for
implementation strings, comments or "function exists"; a source scan is only
for a genuine repo-wide invariant (text domain, operator literals, versioned
asset paths, uninstall inventory, release packaging).

## Architecture (short version)

- `lafka-plugin.php` — bootstrap, CPT/taxonomy registration, AJAX endpoints, asset enqueues, HPOS + Cart-Checkout-Blocks compat declaration.
- `incl/` — feature modules. Gating is declared in `Lafka_Module_Registry` (`incl/class-lafka-module-registry.php`), which the Lafka → Modules page and Site Health read. The five legacy flags (product add-ons, shipping areas, order hours, KDS, promotions) are also exposed as `is_lafka_<feature>()` helpers over `Lafka_Options`; the conversion modules read their own theme_mod toggles.
  - `addons/` — WooCommerce product addons; the v2 **engine** lives in `addons/engine/` (resolver, pricing strategies, `cart/`, `display/`, `admin/`, REST `api/`, `cli/`, `compat/` WC Product Bundles bridge, `data/`, `sources/`, `migrations/`). Bundles/combos are now the official WC Product Bundles plugin bridged here — the old `combos/` fork was removed in v9.0.0.
  - `nutrition/` — nutrition labels for food-menu items
  - `order-hours/` — store-hours and holiday closures
  - `shipping-areas/` — delivery-zone coordinator; branches (`branches/`), the date/time picker (`timeslots/`) and `[lafka_map]` (`map-shortcode/`) are split out into sibling modules
  - `swatches/` — variation swatches
  - `kitchen-display/` — KDS for staff
  - `promotions/` — BOGO + delivery-minimum (migrated from lafka-child; `class-lafka-promotions.php` `@since` 8.7.0)
  - `analytics/` — GA4/GTM, Consent Mode v2, WC `dataLayer` + custom events
  - `conversion/` — abandoned-cart capture/cron/email/resume, web-push, review prompts
  - `schema/` — JSON-LD + `lafka_get_restaurant_info()` resolver
  - `wpml/` — WPML/WCML translation glue
- `shortcodes/` — shortcode definitions + WPBakery/VC mappings (`[lafka_nap]` and `[lafka_shipping_areas]` are registered elsewhere).
- `widgets/` — 6 widgets (5 standalone + 1 WC-dependent).

## Where new code goes

| If you're adding... | Put it in... |
|---------------------|--------------|
| A new CPT or taxonomy | `lafka-plugin.php` (registration) + a new `incl/<feature>/` module if it has logic |
| A new shortcode | `shortcodes/` + add VC mapping in `shortcodes_to_vc_mapping.php` |
| A WC product behavior | `incl/addons/` (engine in `addons/engine/`) if related; otherwise a new module |
| A new module entirely | New folder under `incl/`; register a descriptor in `Lafka_Module_Registry` (built-ins in `register_builtin_modules()`, third parties via the `lafka_register_modules` action) so it appears on Lafka → Modules; gate on that state and load conditionally from `lafka-plugin.php` |
| Site-specific business logic | NOT here — put it in `lafka-child` |

## Coding standards

- WordPress-Extra (PHPCS) with short arrays.
- Min PHP 8.1, min WP 6.6, min WC 9.5.
- Text domain: `lafka-plugin`.
- All public-by-default AJAX (`_nopriv_`) handlers MUST: verify nonce, sanitize input, escape output, gate by capability where appropriate.
- All `$wpdb` queries MUST use `prepare()` or be string-literal.
- All `_FILES` uploads MUST validate MIME server-side and check `is_uploaded_file()`.

## HPOS / Blocks

The plugin declares both HPOS and `cart_checkout_blocks` compatibility in `lafka-plugin.php`. Block Cart/Checkout shipped in 10.0.0: Store API parity lives in `incl/store-api/`, checkout-mode migration + additional checkout fields + blocks integration in `incl/checkout/`. New order/cart code must work on BOTH the classic (shortcode) and block paths — parity is asserted, not assumed.

## Releases

`package.json` is the single source of truth for the version.

0. When translatable strings changed, regenerate the POT:
   `npx @wordpress/env run cli wp i18n make-pot /var/www/html/wp-content/plugins/lafka-plugin /var/www/html/wp-content/plugins/lafka-plugin/languages/lafka-plugin.pot --domain=lafka-plugin --exclude=tests,scripts,node_modules,vendor,assets/vendor,.wp-env-uploads --skip-audit`

1. `npm version <major|minor|patch>` — bumps `package.json`, rewrites the derived copies (`lafka-plugin.php` header, `readme.txt` Stable tag, `languages/lafka-plugin.pot`) via `scripts/sync-version.mjs`, commits and creates the `vX.Y.Z` tag. `npm run check-version` (CI + `VersionConsistencyTest`) catches drift.
2. `git push --follow-tags` — pushing the tag triggers `.github/workflows/release.yml`.
3. `release.yml` runs `npm run build`, then builds the zip (dev-only files — `.git`, `node_modules`, `vendor`, `tests`, `scripts`, lint configs and caches, `README.md`, `CONTRIBUTING.md` — are excluded; `ReleasePackagingTest` dry-runs the same rsync), then creates/updates the GitHub Release with the zip + SHA256.

## Security

Email security issues to security@setkernel.com (or the equivalent maintained channel). Never use public issues.
