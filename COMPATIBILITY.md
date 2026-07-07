# Lafka Compatibility Matrix

Tested combinations of Lafka theme + plugin + child + their dependencies.
Each row is **known-good** — runs the test suite green and the smoke
checklist passes in staging.

> The "minimum" floor is what `composer.json` / plugin headers enforce.
> The "recommended" column is the maintainer-recommended production target;
> what CI actually runs is described in the *CI* section below.

## Stack versions

| Component   | Minimum | Recommended | Latest tested |
|-------------|---------|-------------|---------------|
| **PHP**     | 8.1     | 8.4         | 8.4           |
| **WordPress** | 6.6   | 7.0         | 7.0           |
| **WooCommerce** | 9.5 | 10.9        | 10.9.1        |
| **Node.js** (build only) | 20 | 24 | 24         |
| **Apache** (recommended for security headers) | 2.4 | 2.4.66+ | 2.4.66 |

`.wp-env.json` pins the local integration stack at WP 6.9.4 / WC 10.7.0 /
PHP 8.2; CI's PHP job runs PHPUnit + PHPCS on the runner's single
pre-installed PHP (currently 8.3, matching prod), not on wp-env. The PHPUnit
suites additionally run on PHP 8.5 locally. The full Playwright end-to-end
pass was last run on the WP 6.9.4 / WC 10.7.0 / PHP 8.3.30 stack (Session 5,
2026-04-27); re-run it against the bumped stack before the next release.

## Package versions

Each repo's **current** version is its git tag and its WordPress header
(`lafka-plugin.php` / `style.css`) — the single source of truth, kept in lockstep
with `package.json` + `package-lock.json` by `npm version` (see *Updating this
document*). To avoid restating a number that drifts, this matrix records only the
**compatibility floors**, which change rarely:

| Package         | Minimum sibling versions |
|-----------------|--------------------------|
| lafka-plugin    | theme ≥ 6.13.0, child ≥ 6.0.6 |
| lafka-theme     | plugin ≥ 9.30.0 (optional but expected) |
| lafka-child     | theme ≥ 6.13.0 (parent) |

Each repo is tagged and released independently on its own cadence — the plugin
and theme advance faster than the thin child, so their versions are not expected
to move in lock-step.

## CI (single-runner PHPUnit; PHP floor enforced statically)

CI does **not** run a multi-PHP test matrix. Under the first-party-actions-only
policy (see the header comment in `.github/workflows/ci.yml`), each repo's PHP
job runs PHPUnit + PHPCS on the runner's **single** pre-installed PHP (currently
8.3, matching prod); the multi-PHP matrix was deliberately traded away for that
constraint. The **PHP 8.1 floor is enforced statically, not by running PHPUnit
on 8.1**: PHPCompatibility sniffs (`phpcompatibility/phpcompatibility-wp`, wired
as `testVersion 8.1-` + `PHPCompatibilityWP` in `.phpcs.xml.dist`) flag any
8.2+-only construct during PHPCS, and the `Requires PHP: 8.1` plugin header
gates activation at runtime.

| Repo | PHPUnit + PHPCS | PHP-floor check |
|----|----|----|
| **lafka-plugin** | runner PHP (8.3) | PHPCompatibility `8.1-` |
| **lafka-theme**  | runner PHP (8.3) | PHPCompatibility `8.1-` |
| **lafka-child**  | runner PHP (8.3) | PHPCompatibility `8.1-` |

CI checks: PHPCS (WordPress-Extra ruleset, ~60 sniff exclusions documented in
`.phpcs.xml.dist`) + PHPUnit (Brain Monkey), both on the runner PHP. JS/CSS
linted separately on Node 24 (ESLint + Stylelint).

The security sniff families — `WordPress.Security.EscapeOutput.*`,
`WordPress.Security.NonceVerification.*`, `WordPress.DB.PreparedSQL.*` —
are **enforced as errors** (re-enabled in the 2026-05-14 P5-Sec pass). Only
the narrow `WordPress.Security.EscapeOutput.ExceptionNotEscaped` is excluded.

A container-based PHP 8.1 / 8.3 test matrix (first-party `container:` images,
no community actions) is a tracked follow-up — roadmap item **NX1-08c** in
`ROADMAP_2026-07-05.md`. Until it lands, cross-version coverage is
static-analysis-only via PHPCompatibility.

WP × WC integration matrix is pending integration tests — tracked as P2-04a.

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
- **WC < 9.5** — combos + addons rely on hook signatures changed in 9.5.
  `woocommerce_checkout_update_order_meta` (deprecated WC 9.0) is no
  longer used as of Session 5 — all three call sites moved to
  `woocommerce_checkout_create_order`.
- **PHP < 8.1** — uses `static fn()` short closures (since 7.4 actually,
  but 8.1 is the floor for declared types in pricing helpers).

## HPOS (custom_order_tables) status

The plugin **declares HPOS compatibility** at boot:
`FeaturesUtil::declare_compatibility('custom_order_tables', __FILE__, true)`
(`lafka-plugin.php:181-183`).

Verified clean under HPOS in Session 5:
- All checkout meta saves go through `woocommerce_checkout_create_order`
  with `$order->update_meta_data()` — works in both HPOS and CPT stores
  without branching.
- `wc_get_orders(['meta_query' => ...])` is used everywhere a custom
  meta filter is needed (HPOS supports it natively in WC 8.x+).
  Previously broken `value=>null` clauses (silent no-op under SQL =
  semantics → slot overbooking) were fixed in Session 5.
- KDS dashboard meta priming is HPOS-aware: skips
  `update_meta_cache('post', $ids)` when HPOS is on (order meta lives
  in `wc_orders_meta`, not `wp_postmeta`); per-order meta is already
  loaded into the `WC_Order` in-memory cache by `wc_get_orders()`.
- Branch-scoped admin order count uses `meta_query` form so HPOS and
  CPT both honor the filter.

## Block-based Cart/Checkout (WC 10.6+ default) — SUPPORTED

**The plugin declares `cart_checkout_blocks` compatibility** (NX1-04b —
closes **P3-01**). Both the modern block Cart/Checkout and the classic
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

- **Unit tests** (this repo's `tests/Unit/` per package) — pure-helper
  math, options precedence, feature-flag wiring, style.css headers. No WP
  runtime; Brain Monkey mocks WP/WC functions.
- **Analytics / conversion / web-push** (`tests/Unit/`) — GA4 `dataLayer`
  emitter + Consent Mode v2 defaults (`AnalyticsEmitterTest`,
  `AnalyticsWcEventsTest`, `AnalyticsCustomEventsTest`); abandoned-cart
  capture/cron/email (`AbandonedCartTest`); review-prompt scheduling
  (`ReviewPromptTest`); web-push subscribe/send (`PushNotificationsTest`).
  Browser push *delivery* (VAPID round-trip to a live service worker) is
  smoke-checked manually, not yet in the e2e grid.
- **Integration tests** — not yet present. Tracked as P2-04a.
- **Manual smoke** — `wp option patch update lafka promotions enabled`
  cutover for the BOGO module; KDS standalone-page rendering; cart with
  mixed-price items; delivery-min boundary at $30.
- **End-to-end (Session 5)** — full Docker stack on
  `wordpress:latest` (6.9.4) + WC 10.7.0 + PHP 8.3.30, driven via
  Playwright with a brand-new Chrome user-data-dir. Storefront
  (home/shop/product/cart/checkout/order-received), KDS dashboard with
  state-machine transitions, all 5 admin pages (Promotions / KDS /
  Order Hours / Shipping Areas / Security), Site Health debug section.
  Real order placed end-to-end with COD payment + BOGO discounts.

## Updating this document

Versions are single-source-of-truth. Bump with **one command** in the repo:

```bash
npm version <patch|minor|major>   # or an explicit x.y.z
```

`npm version` bumps `package.json`, then the `version` lifecycle hook
(`scripts/sync-version.mjs`) writes the WordPress header (`lafka-plugin.php` /
`style.css`) and any versioned docs, npm syncs `package-lock.json`, and a
`vX.Y.Z` git tag + release commit are created — then `git push --follow-tags`.
**Never hand-edit a version.** `npm run check-version` is a CI gate (and a
PHPUnit guard, `VersionConsistencyTest`) that fails the build on any drift.

Only the compatibility **floors** in the matrix above are hand-maintained —
update them when a real minimum-version requirement changes.
