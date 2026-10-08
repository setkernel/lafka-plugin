# Contributing to lafka-plugin

This is the companion plugin for the Lafka theme. It owns business logic (CPTs, shop modules, addons engine, KDS, shipping areas, promotions, analytics, conversion) so it survives theme switches. Deterministic demo content also lives here: `wp lafka seed-demo` (`incl/cli/class-lafka-cli-seed-demo.php`).

## Local development

```bash
npm ci
composer install

# Boot a full WP + WC + Lafka stack: the Docker environment in ../local-env
# (this repo is bind-mounted live; see its README). Then seed a deterministic
# demo restaurant (products, addons, branch, zone, hours):
git config core.hooksPath .githooks   # pre-push runs every gate
../local-env/lafka wp lafka seed-demo        # add --reset to rebuild
```

## Before opening a PR

```bash
npm run check-version  # version single source of truth
npm run lint           # ESLint + Stylelint, zero warnings
npm run build          # regenerate .min.js from sources — commit both
composer lint:php      # PHP syntax at the 8.3 floor
composer phpcs         # full WordPress-Extra, warnings fail (composer phpcbf auto-fixes)
```

The `.githooks/pre-push` hook and CI run exactly these gates, every time. No
lint rule is excluded and no inline suppression comment of any linter is
allowed: fix the code.

The plugin currently ships no automated test suite.

## Architecture (short version)

- `lafka-plugin.php` — bootstrap, CPT/taxonomy registration, AJAX endpoints, asset enqueues, HPOS + Cart-Checkout-Blocks compat declaration.
- `incl/` — feature modules. Gating is declared in `Lafka_Module_Registry` (`incl/class-lafka-module-registry.php`), which the Lafka → Modules page and Site Health read. The five legacy flags (product add-ons, shipping areas, order hours, KDS, promotions) are also exposed as `is_lafka_<feature>()` helpers over `Lafka_Options`; the conversion modules read their own theme_mod toggles.
  - `addons/` — WooCommerce product addons; the v2 **engine** lives in `addons/engine/` (resolver, pricing strategies, `cart/`, `display/`, `admin/`, REST `api/`, `cli/`, `compat/` WC Product Bundles bridge, `data/`, `sources/`, `migrations/`). Bundles are the official WC Product Bundles plugin, bridged here.
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
- `shortcodes/` — shortcode definitions (`[lafka_nap]` and `[lafka_shipping_areas]` are registered elsewhere).
- `widgets/` — 6 widgets (5 standalone + 1 WC-dependent).

## Where new code goes

| If you're adding... | Put it in... |
|---------------------|--------------|
| A new CPT or taxonomy | `lafka-plugin.php` (registration) + a new `incl/<feature>/` module if it has logic |
| A new shortcode | `shortcodes/shortcodes.php` |
| A WC product behavior | `incl/addons/` (engine in `addons/engine/`) if related; otherwise a new module |
| A new module entirely | New folder under `incl/`; register a descriptor in `Lafka_Module_Registry` (built-ins in `register_builtin_modules()`, third parties via the `lafka_register_modules` action) so it appears on Lafka → Modules; gate on that state and load conditionally from `lafka-plugin.php` |
| Site-specific business logic | NOT here — put it in `lafka-child` |

## Coding standards

- WordPress-Extra (PHPCS) with short arrays.
- Version floors (PHP / WordPress / WooCommerce): see [COMPATIBILITY.md](COMPATIBILITY.md), the single source. Write against the floors — no `version_compare()` branches or polyfills for older versions.
- Text domain: `lafka-plugin`.
- All public-by-default AJAX (`_nopriv_`) handlers MUST: verify nonce, sanitize input, escape output, gate by capability where appropriate.
- All `$wpdb` queries MUST use `prepare()` or be string-literal.
- All `_FILES` uploads MUST validate MIME server-side and check `is_uploaded_file()`.

### CSS class and id naming (Stylelint `selector-class-pattern` / `selector-id-pattern`)

Class names and ids are contracts with templates, JS, the theme and
WooCommerce/WordPress, so the lint pattern describes the names that exist
instead of forcing renames. One regex serves both rules, and it is identical in
lafka-plugin, lafka-child and lafka-theme:

- Our own names are lowercase words joined by `-` or `_`: `lafka-branch-order-type`,
  `lafka_select_branch_modal` (ids that mirror PHP field names keep their `_`).
- BEM is allowed: `lafka-engine-group__header`, `lafka-bogo-banner--fixed`.
- Mixed case is accepted only for names owned by someone else, recognised by
  prefix: `woocommerce-` (for example `woocommerce-Price-amount`), `wc-`, `wp-`,
  jQuery UI `ui-`, `select2-`, `flatpickr-`, `dashicons-` and `fa-`.
- camelCase or a capital in a `lafka-` / `lafka_` name is rejected.

The regex lives in `.stylelintrc.json`. Do not rename an existing class or id
to satisfy it, and do not widen it for new code: new names follow the rules above.

### JavaScript conventions (ESLint)

`no-var`, `prefer-const`, `no-prototype-builtins` and the recommended set
(including `no-redeclare`, `no-unused-vars`, `no-empty`, `no-useless-escape`,
`no-useless-assignment` and `no-shadow-restricted-names`) are all on. A script
that other scripts read through `window` assigns it explicitly (`window.x = ...`);
never rely on a top-level `var`.

## HPOS / Blocks

The plugin declares both HPOS and `cart_checkout_blocks` compatibility in `lafka-plugin.php`. Block Cart/Checkout shipped in 10.0.0: Store API parity lives in `incl/store-api/`, checkout-mode migration + additional checkout fields + blocks integration in `incl/checkout/`. New order/cart code must work on BOTH the classic (shortcode) and block paths — parity is asserted, not assumed.

## Releases

`package.json` is the single source of truth for the version.

0. When translatable strings changed, regenerate the POT with the local stack
   running: `npm run i18n:pot`.

1. `npm version <major|minor|patch>` — bumps `package.json` and rewrites the derived copies (`lafka-plugin.php` header, `readme.txt` Stable tag, `languages/lafka-plugin.pot`) via `scripts/sync-version.mjs`, then commits and creates the `vX.Y.Z` tag. `npm run check-version` catches drift.
2. Push the branch, then the tag on its own (`git push origin main`, then `git push origin vX.Y.Z`).
3. The tag runs `.github/workflows/release.yml`: it calls the CI workflow and, only if every gate passes, zips the tree minus the paths in `.distignore` and creates or updates the GitHub Release with the zip and its SHA-256.

## Security

Email security issues to security@setkernel.com (or the equivalent maintained channel). Never use public issues.
