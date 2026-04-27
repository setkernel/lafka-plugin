# Lafka Compatibility Matrix

Tested combinations of Lafka theme + plugin + child + their dependencies.
Each row is **known-good** — runs the test suite green and the smoke
checklist passes in staging.

> The "minimum" floor is what `composer.json` / plugin headers enforce.
> The "recommended" column is what the maintainers run in CI today.

## Stack versions

| Component   | Minimum | Recommended | Latest tested |
|-------------|---------|-------------|---------------|
| **PHP**     | 8.1     | 8.3         | 8.3.30        |
| **WordPress** | 6.6   | 6.9         | 6.9.4         |
| **WooCommerce** | 9.5 | 10.7        | 10.7.0        |
| **Node.js** (build only) | 20 | 24 | 24         |
| **Apache** (recommended for security headers) | 2.4 | 2.4.66+ | 2.4.66 |

The "Latest tested" column was verified end-to-end in Session 5
(2026-04-27): fresh Docker stack (`wordpress:latest` 6.9.4 + WC 10.7.0
+ MariaDB LTS + PHP 8.3.30) driven via Playwright, 18 fixes shipped,
0 lines in `debug.log` and 0 console errors after. See
`LAFKA_PROGRESS.md` Session 5 ledger entry.

## Package versions

| Package         | Current release | Minimum sibling versions |
|-----------------|-----------------|--------------------------|
| lafka-plugin    | **8.7.1** (next: 8.7.2) | theme ≥ 5.8.1, child ≥ 5.5.0 |
| lafka-theme     | **5.8.1** (next: 5.8.2) | plugin ≥ 8.7.1 (optional but expected) |
| lafka-child     | **5.5.0**       | theme ≥ 5.8.0 (parent) |

Session 5 work is unreleased post v8.7.1 / v5.8.1 — nine commits each
on `lafka-plugin` and `lafka-theme` ahead of those tags.

## CI matrix (per-repo CI runs the full grid)

| | PHP 8.1 | PHP 8.2 | PHP 8.3 |
|----|----|----|----|
| **lafka-plugin** | ✅ | ✅ | ✅ |
| **lafka-theme**  | ✅ | ✅ | ✅ |
| **lafka-child**  | ✅ | ✅ | ✅ |

CI checks per matrix cell: PHPCS (WordPress-Extra ruleset, ~50 sniff
exclusions documented in `.phpcs.xml.dist`) + PHPUnit (Brain Monkey).
JS/CSS linted separately on Node 20 (ESLint + Stylelint).

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
Theme Options → General. With no key, the script is **not registered**
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

## Block-based Cart/Checkout (WC 10.6+ default)

The plugin does **not** yet declare `cart_checkout_blocks` compatibility
(intentional — full Store API extensions for BOGO / branch selector /
order-hours / delivery-min are tracked as **P3-01**, blocked).

Until P3-01 ships, the plugin includes a **transitional shim**
(`incl/compat/class-lafka-block-cart-shim.php`, added Session 5):
on first `admin_init` after activation, if the Cart and/or Checkout
pages contain unedited WC default Block content
(`<!-- wp:woocommerce/cart -->` / `<!-- wp:woocommerce/checkout -->`),
the shim rewrites them to `[woocommerce_cart]` / `[woocommerce_checkout]`
classic shortcodes — the path Lafka's classic-cart hooks (BOGO label,
delivery-min notice, branch selector, order-hours notice) actually fire
on. Self-disabling: gated by `lafka_block_cart_shim_done` option, only
swaps unedited Block content (any merchant customization is left alone),
shows a one-time admin notice on swap.

Operators who prefer the Block-based flow can re-enable it after
restoring the page content + deleting the option; the shim won't undo
that decision.

## Browser support (frontend)

Modern evergreen browsers; IE11 is not tested. WP itself dropped IE
support in core 5.8. Mobile Safari ≥ 14, Chrome/Firefox/Edge ≥ 100.

## What's tested where

- **Unit tests** (this repo's `tests/Unit/` per package) — pure-helper
  math, options precedence, feature-flag wiring, style.css headers. No WP
  runtime; Brain Monkey mocks WP/WC functions.
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

This file is hand-maintained. After bumping any of the headers in
`composer.json`, `style.css`, or `lafka-plugin.php`, update the matrix
above to match. The CI workflow doesn't auto-generate it.

For per-release verification, see the operator checklist at the end of
`LAFKA_PROGRESS.md`.
