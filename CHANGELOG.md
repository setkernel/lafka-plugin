# Changelog

All notable changes to lafka-plugin are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/); versions follow the repo's
semver (see `npm version` SSOT in CONTRIBUTING.md). Older history lives in
git tags + GitHub Releases.

## [10.0.0] — 2026-07-07

Phase NX1 ("Platform & Configurability Foundation") release. See
`ROADMAP_2026-07-05.md` at the umbrella repo for the full program.

### Added
- **Feature Modules dashboard** (Lafka → Modules): every gated module —
  addons, shipping areas, order hours, KDS, promotions, abandoned cart, web
  push, review prompts, analytics — visible and toggleable from one screen,
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
