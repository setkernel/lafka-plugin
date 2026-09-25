# Lafka Compatibility Matrix

Supported versions of the Lafka plugin's dependencies, and how each is checked.

> The "minimum" floor is what the plugin header declares.
> The "recommended" column is the maintainer-recommended production target;
> what CI actually runs is described in the *CI* section below.

## Stack versions

| Component   | Minimum | Recommended | Latest tested |
|-------------|---------|-------------|---------------|
| **PHP**     | 8.1     | 8.4         | 8.4           |
| **WordPress** | 6.6   | 7.1         | 7.1.2         |
| **WooCommerce** | 9.5 | 11.1        | 11.1.2        |
| **Node.js** (build only) | 20 | 24 | 24         |
| **Apache** (recommended for security headers) | 2.4 | 2.4.66+ | 2.4.66 |

`.wp-env.json` pins the local integration stack at WP 7.1.2 / WC 11.1.2 /
PHP 8.4; CI's PHP job runs PHPUnit + PHPCS on the runner's single
pre-installed PHP (currently 8.3, matching prod), not on wp-env. End-to-end
coverage is the theme repo's Playwright smoke suite (`e2e.yml` in
lafka-theme), which boots wp-env with the theme + this plugin + WooCommerce and
seeds it with `wp lafka seed-demo`.

## Package versions

Each repo's **current** version is its git tag and its WordPress header
(`lafka-plugin.php` / `style.css`) — the single source of truth, kept in lockstep
with `package.json` + `package-lock.json` by `npm version` (see *Updating this
document*). To avoid restating a number that drifts, this matrix records only the
**compatibility floors**, which change rarely:

Floors are **advisory** — documented, not enforced by any version check.

| Package         | Minimum sibling versions (advisory) |
|-----------------|-------------------------------------|
| lafka-plugin    | theme ≥ 6.13.0, child ≥ 6.0.6 |
| lafka-theme     | plugin ≥ 9.30.0 (optional but expected) |
| lafka-child     | theme ≥ 6.13.0 (parent) |

Each repo is tagged and released independently on its own cadence — the plugin
and theme advance faster than the thin child, so their versions are not expected
to move in lock-step.

## CI (single-runner PHPUnit; PHP 8.1 floor checked statically)

CI does **not** run a multi-PHP test matrix. Under the first-party-actions-only
policy (see the header comment in `.github/workflows/ci.yml`), the PHP job runs
PHPUnit + PHPCS on the runner's **single** pre-installed PHP (currently 8.3,
matching prod).

The **PHP 8.1 floor is checked by PHPCS**: `.phpcs.xml.dist` runs
`PHPCompatibilityWP` with `testVersion 8.1-` on PHPCompatibility **10**
(`10.0.0-alpha2`, via `phpcompatibility-wp 3.0.0-alpha2` — the 9.x line has
no PHP 8.x sniffs). A PHP 8.2+-only construct (readonly classes, DNF types,
`json_validate()`, …) fails CI. The check is static: it catches syntax and
known new functions/constants, not every behavioural difference between PHP
versions, and the unit tests themselves run on the runner PHP only. The
`Requires PHP: 8.1` plugin header still blocks activation on older PHP.

CI checks: PHPCS (WordPress-Extra ruleset + PHPCompatibility; exclusions
documented in `.phpcs.xml.dist`) + PHPUnit (Brain Monkey), both on the runner
PHP, plus `npm run check-version`. JS is linted (ESLint), CSS linted
(Stylelint), front-end JS behaviour-tested (`npm test`, node:test) and the
committed `.min.js` builds are checked against their sources (`npm run build`)
on Node 24.

The official WordPress.org **Plugin Check** runs in `.github/workflows/plugin-check.yml`
(non-blocking for now, outside `ci-passed`) against exactly the tree release.yml
zips, on a wp-env stack (WP 7.1.2 + WC 11.1.2) started from npm — no community
actions; errors fail that job, the CSV report is uploaded as an artifact.

The security sniff families — `WordPress.Security.EscapeOutput.*`,
`WordPress.Security.NonceVerification.*`, `WordPress.DB.PreparedSQL.*` —
are **enforced as errors** (re-enabled in the 2026-05-14 P5-Sec pass). Only
the narrow `WordPress.Security.EscapeOutput.ExceptionNotEscaped` is excluded.

There is no WP × WC integration-test matrix yet.

## Server-level configuration recommendations

Lafka's security-headers module strips `X-Powered-By` (the version-leaking
PHP header) when the toggle is enabled. The `Server:` header (e.g.
`Server: Apache/2.4.66 (Debian)`) is set by the web server itself before
PHP runs, so PHP can't remove it from a hook. Strip it server-side:

**Apache** — add to `httpd.conf` or a vhost:

```apache
ServerTokens Prod
ServerSignature Off
```

`ServerTokens Prod` reduces the header to just `Server: Apache`;
`ServerSignature Off` suppresses the version footer on default error
pages.

**Nginx** — add to the `http {}` block or a server block:

```nginx
server_tokens off;
```

Pair this with the plugin-level security-headers toggle (Tools → Lafka
Security) for a complete fingerprint-reduction posture: `X-Powered-By`,
`Server:`, and the four positive headers (`X-Content-Type-Options`,
`X-Frame-Options`, `Referrer-Policy`, `Permissions-Policy`) all
correctly set.

## Google Maps integration

The plugin and theme conditionally register the Google Maps loader
script under handle `lafka-google-maps` only when an API key is set in
the Customizer (`lafka[google_maps_api_key]`, Lafka settings bridge). With no key, the script is **not registered**
and dependent enqueues across the codebase fail-closed via
`wp_script_is( 'lafka-google-maps', 'registered' )` guards:

- `[lafka_map]` shortcode — renders an admin-only configuration notice.
- `[lafka_shipping_areas]` shortcode — renders an admin-only notice.
- Front-end branch-locations selector — falls back to dropdown UX.
- Branch-locations admin (map-pick) — disabled (dropdown still works).
- Shipping-areas admin (define-area, store-map) — disabled (text-input
  fallback for coordinates still works).
- Front-end shipping handler (geo-fence validation) — server-side
  validation in `validate_checkout_field_process` still gates orders.

Symptom on misconfigured sites prior to this gate: console error
`Geocoding Service: You must use an API key to authenticate each
request to Google Maps Platform APIs` on every page that loaded the
maps loader without a key. Closed in plugin v8.7.4 + theme v5.8.3.

## Known incompatibilities

- **stylelint ^17** — incompatible with `@wordpress/stylelint-config@23.x`
  (peer dep requires ^16.8.2). Pinned to ^16.26.1 in all 3 repos. Revisit
  when @wordpress/stylelint-config@24+ ships.
- **WP < 6.6** — uses `wp_body_open()` (since 5.2) but several other APIs
  the codebase depends on (CPT REST, modern HPOS hooks) are 6.6+.
- **WC < 9.5** — addons rely on hook signatures changed in 9.5. Checkout
  meta is written on `woocommerce_checkout_create_order` (not
  `woocommerce_checkout_update_order_meta`) so it receives the `WC_Order`
  before save.
- **PHP < 8.1** — uses `static fn()` short closures (since 7.4 actually,
  but 8.1 is the floor for declared types in pricing helpers).

## HPOS (custom_order_tables) status

The plugin **declares HPOS compatibility** in its `before_woocommerce_init`
callback in `lafka-plugin.php`:
`FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true)`.

HPOS-safe patterns in the codebase:
- All checkout meta saves go through `woocommerce_checkout_create_order`
  with `$order->update_meta_data()` — works in both HPOS and CPT stores
  without branching.
- `wc_get_orders(['meta_query' => ...])` is used everywhere a custom
  meta filter is needed (HPOS supports it natively in WC 8.x+).
- KDS dashboard meta priming is HPOS-aware: skips
  `update_meta_cache('post', $ids)` when HPOS is on (order meta lives
  in `wc_orders_meta`, not `wp_postmeta`); per-order meta is already
  loaded into the `WC_Order` in-memory cache by `wc_get_orders()`.
- Branch-scoped admin order count uses `meta_query` form so HPOS and
  CPT both honor the filter.

## Block-based Cart/Checkout (WooCommerce default since 8.3) — SUPPORTED

**The plugin declares `cart_checkout_blocks` compatibility** in the same
`before_woocommerce_init` callback as HPOS. Both the modern block Cart/Checkout and the classic
shortcode Cart/Checkout are fully supported; the operator picks which one
customers see via **Lafka → Modules → Checkout experience**.

### Checkout mode (`lafka_checkout_mode`)

A single option governs the experience, with a production-preserving
migration (`Lafka_Checkout_Mode`):

- **`blocks`** — the modern WooCommerce block Cart/Checkout. **Fresh
  activations default to this** (it is the default WooCommerce gives new
  stores).
- **`classic`** — the classic shortcode Cart/Checkout. **Existing installs
  are migrated to this on update** so their behaviour is byte-identical to
  before — nothing changes on a plugin update for a live store.
- The `lafka_force_classic_checkout` filter forces classic at runtime,
  overriding the option (for hosts/child plugins that need to pin it).
- An unset option resolves to `classic` at runtime (the safe,
  production-preserving default).

### What is supported on the block path

Everything the classic path enforces holds on the Store API / block path:

- **Server gates (NX1-04a)** — order-hours (store closed), delivery
  geo-fence, timeslot validity + capacity, and branch/order-type
  capability are all re-validated on `woocommerce_store_api_cart_errors`
  / `…_checkout_update_order_from_request`. A block order can never
  violate a gate the classic checkout enforces.
- **Add-ons (NX1-04c)** — addon selections ride the Store API into cart
  line items, totals and order-item meta identically to the classic path.
- **Order type + branch fields (NX1-04b)** — registered on the block
  checkout via WooCommerce's Additional Checkout Fields API (shown
  conditionally: order-type when the site offers more than one type;
  branch when more than one branch exists). Their values round into the
  same `lafka_branch_location` session and the same `lafka_order_type` /
  `lafka_selected_branch_id` order meta the classic path writes, so KDS,
  branch routing and analytics see identical order meta.
- **Time-slot picker + free-delivery progress (NX1-04b)** — build-free JS
  components (`incl/checkout/assets/js/lafka-blocks-checkout.js`, no build
  step): a date/time-slot picker on the block checkout (driven by the
  existing `time_slots_for_date` AJAX endpoint, pushed through the `lafka`
  cart/extensions update callback) and a free-delivery progress bar on the
  block cart (reading the `lafka` cart extension). Both degrade safely — if
  the script fails to load, checkout still submits and the server gates
  remain the authority.

### The shim's new role

`incl/compat/class-lafka-block-cart-shim.php` no longer forces classic. It
now **honours the mode**:

- In **classic** mode it rewrites unedited default block Cart/Checkout
  pages to `[woocommerce_cart]` / `[woocommerce_checkout]` (as before),
  and **saves the original block markup** so the switch is reversible.
- In **blocks** mode it leaves native block pages alone, and restores the
  original block markup on any page it previously rewrote.

It only ever touches unedited default block markup or its own shortcode
output — operator-customised Cart/Checkout pages are always left alone.
Switching modes on the Modules screen re-reconciles the pages on the next
admin request.

## Browser support (frontend)

Modern evergreen browsers; IE11 is not tested. WP itself dropped IE
support in core 5.8. Mobile Safari ≥ 14, Chrome/Firefox/Edge ≥ 100.

## What's tested where

- **Unit tests** (`tests/Unit/`) — pure-helper math, options precedence,
  feature-flag wiring, plugin header / version SSOT. No WP runtime; Brain
  Monkey mocks WP/WC functions.
- **Analytics / conversion / web-push** (`tests/Unit/`) — GA4 `dataLayer`
  emitter + Consent Mode v2 defaults (`AnalyticsEmitterTest`,
  `AnalyticsWcEventsTest`, `AnalyticsCustomEventsTest`); abandoned-cart
  capture/cron/email (`AbandonedCartTest`); review-prompt scheduling
  (`ReviewPromptTest`); web-push subscribe/send (`PushNotificationsTest`).
  Browser push *delivery* (VAPID round-trip to a live service worker) is
  smoke-checked manually, not yet in the e2e suite.
- **Integration tests** — not yet present.
- **Manual smoke** — enabling Lafka → Modules → Promotions for the BOGO
  module; KDS standalone-page rendering; cart with mixed-price items;
  delivery-minimum boundary at the configured minimum.
- **End-to-end** — the theme repo's Playwright smoke suite (`e2e.yml` in
  lafka-theme) boots wp-env with theme + plugin + WooCommerce, seeds it with
  `wp lafka seed-demo`, and walks the ordering funnel. It is non-blocking
  for now.

## Updating this document

Versions are single-source-of-truth. Bump with **one command** in the repo:

```bash
npm version <patch|minor|major>   # or an explicit x.y.z
```

`npm version` bumps `package.json`, then the `version` lifecycle hook
(`scripts/sync-version.mjs`) writes the plugin header (`lafka-plugin.php`) and
the `readme.txt` Stable tag, npm syncs `package-lock.json`, and a
`vX.Y.Z` git tag + release commit are created — then `git push --follow-tags`.
**Never hand-edit a version.** `npm run check-version` is a CI gate (and a
PHPUnit guard, `VersionConsistencyTest`) that fails the build on any drift.

Only the compatibility **floors** in the matrix above are hand-maintained —
update them when a real minimum-version requirement changes.
