# Lafka Addons Engine v2 — Design Spec

**Status:** approved 2026-04-30 via conversation
**Target:** lafka-plugin v8.13.0 (Phase 1) → v8.13.1 (Phase 2) → v8.13.2 (Phase 3) → v8.14.0 (Phase 4)

---

## Why this exists

Lafka's current addon system (`incl/addons/`) is a fork of WooCommerce's official Product Add-ons that has drifted significantly. Recent v8.12.x patches fixed cart correctness, save preservation, security hardening — but the architecture was never restructured. Each new feature has been bolted on, and the operator UX requires manually typing every option label and price even when the data could be sourced from existing WC product attributes.

The user has decided this is the moment to rebuild the addon system as a foundation for ongoing Lafka development — modular, hookable, well-tested, and aligned with WordPress + WooCommerce extension patterns. The new engine should be:

- **Schema-versioned** so future shape changes are safe
- **Composable** — pricing modes, source providers, field types are independent strategies
- **Hookable** — every meaningful operation goes through a filter or action
- **Backward compatible** — existing addon groups must keep working without operator action
- **Forward extensible** — adding a new pricing mode = drop a class + register via filter

## Goals

1. **Operator UX**: load addon options from a WC product attribute (e.g., `pa_premium_toppings`) instead of typing them. Pick which terms to include. Pick a pricing mode that matches the actual pricing strategy. Save once.
2. **4 pricing modes** instead of the current single ad-hoc mode:
   - `flat_group` — one price for the whole group regardless of option or size
   - `flat_per_option` — different price per option, same across sizes (current default)
   - `flat_per_size` (NEW) — different price per size, same across options
   - `matrix` — full per-option × per-size grid (current premium mode)
3. **Per-size include picker**: operator can opt out of specific size terms; the addon group is hidden when the customer picks a deselected size.
4. **Architectural integrity**: separate data, business logic, presentation, API. Every layer has interfaces. Every behavior is testable in isolation.
5. **Foundation for the future**: REST API, WP-CLI, additional field types, multilingual, caching compatibility — all become drop-in additions, not refactors.

## Non-goals (this spec)

- Migrating existing addon groups to use the new attribute-source mode (operator does that manually after Phase 2 ships)
- Replacing the current cart/order processing logic — Phase 1 changes only the data layer + admin save handler
- Migrating products from `lafka_combo` to WC's `bundle` type — separate decision, not part of this rewrite
- Building a new front-end picker component — current `pdp-pickers.js` is fine, the rewrite is admin-side first

## Architecture

```
lafka-plugin/incl/addons/
├── lafka-addons.php                       # bootstrap / autoloader
├── interfaces/
│   ├── interface-pricing-strategy.php     # 4 implementations
│   ├── interface-options-source.php       # manual or attribute-sourced
│   ├── interface-field-renderer.php       # checkbox, radio, textarea, select
│   └── interface-validator.php            # required, limit, etc.
├── data/
│   ├── class-addon-group.php              # value object (immutable-ish)
│   ├── class-addon-option.php             # value object
│   ├── class-addon-repository.php         # CRUD against _product_addons meta
│   └── schema/
│       └── schema-v2.php                  # canonical shape definition + version constants
├── pricing/
│   ├── abstract-pricing-strategy.php
│   ├── class-flat-group-pricing.php
│   ├── class-flat-per-option-pricing.php
│   ├── class-flat-per-size-pricing.php
│   ├── class-matrix-pricing.php
│   └── class-pricing-resolver.php         # picks strategy + matrix-expansion
├── sources/
│   ├── abstract-options-source.php
│   ├── class-manual-source.php
│   └── class-attribute-source.php
├── migrations/
│   ├── abstract-migration.php
│   ├── class-migration-v8-13-0.php
│   └── class-upgrader.php
└── tests/
    ├── pricing/
    ├── sources/
    └── data/
```

The existing `incl/addons/admin/`, `incl/addons/includes/`, `incl/addons/templates/` directories stay untouched in Phase 1 — they continue to read/write the same `_product_addons` post meta. The migration class adds two new fields to the meta shape (`pricing_mode`, `options_source`) with sensible defaults, so existing addon groups behave identically.

In Phase 2, the admin views will be rewritten to use the new engine. In Phase 3, the cart/display layer migrates. In Phase 4, the old code is deleted.

## Data schema (v2)

The `_product_addons` post meta stays an array of group dicts. Each group adds three fields:

```php
array(
    // existing fields (preserved)
    'name'        => 'Premium Toppings',
    'limit'       => 0,
    'description' => '',
    'type'        => 'checkbox',
    'position'    => 1,
    'required'    => 0,
    'variations'  => 1,                       // 1 = use per-size pricing
    'attribute'   => 1,                       // WC attribute taxonomy ID for size axis
    'options'     => array( ...option dicts... ),

    // NEW in v8.13.0 (defaults preserve current behavior)
    'pricing_mode'           => 'flat_per_option',  // flat_group | flat_per_option | flat_per_size | matrix | legacy
    'options_source'         => 'manual',           // manual | attribute
    'options_source_attribute' => '',               // taxonomy slug when options_source = attribute
    'included_size_slugs'    => array(),            // empty = all; non-empty = subset of size attribute terms
    'group_flat_price'       => '',                 // used when pricing_mode = flat_group
    'group_size_prices'      => array(),            // [size_slug => price] when pricing_mode = flat_per_size
    'schema_version'         => 2,
)
```

**Backward compat**: any group missing `pricing_mode` is treated as `legacy` — the existing render/save path applies. Migration class sets `pricing_mode = legacy` on every existing group, then operators can opt into new modes via the editor.

**Per-option price field**: stays as it is (scalar OR nested matrix). The pricing strategies expand or extract from this shape as needed:
- `flat_group`: every option's price = `group_flat_price`
- `flat_per_option`: each option's price = its own scalar (current model)
- `flat_per_size`: every option's price = `{ tax_slug => group_size_prices }` matrix
- `matrix`: each option's price = its own nested matrix (current premium model)
- `legacy`: read whatever's there

## Pricing strategy interface

```php
interface Lafka_Pricing_Strategy {
    public function get_id(): string;                          // 'flat_group' etc.
    public function get_label(): string;                       // operator-facing
    public function get_admin_template(): string;              // path to the form partial
    public function expand_options( array $options, array $context ): array;
    // ^ takes raw options + group context, returns options with price fields populated
    public function extract_from_post( array $post_data, array $context ): array;
    // ^ reads $_POST, returns normalized data ready for storage
    public function validate( array $data, array $context ): array;
    // ^ returns array of WP_Error objects (empty = valid)
}
```

The resolver iterates registered strategies (via `lafka_addons_register_pricing_strategy` filter), picks the one matching `pricing_mode`, calls its methods. New strategy = new file + register. Zero changes elsewhere.

## Options source interface

```php
interface Lafka_Options_Source {
    public function get_id(): string;                          // 'manual' or 'attribute'
    public function get_label(): string;
    public function get_admin_template(): string;
    public function get_options( array $context ): array;
    // ^ returns canonical options list for this source
    public function sync( array $context ): array;
    // ^ for attribute source: refresh against current taxonomy terms; returns updated options preserving existing prices/inclusion
}
```

Attribute source reads the configured taxonomy + applies the operator's per-term include/exclude list.

## Phasing

| Phase | Version | Scope | Ship date target |
|---|---|---|---|
| **1 — Foundation** | v8.13.0 | Interfaces, data layer, pricing strategies, sources, migration. **No admin UI changes.** Existing behavior unchanged because new fields default to `legacy` mode. | this session |
| **2 — Admin form rewrite** | v8.13.1 | New editor with source selector, pricing mode picker, size include picker. Operator can use new modes. Existing groups still in legacy mode until operator opts in. | next session |
| **3 — Cart/display migration + REST + CLI** | v8.13.2 | Cart and display layers read from new engine. WP_List_Table for admin list. REST controllers + WP-CLI commands. | session after that |
| **4 — Polish** | v8.14.0 | Privacy class, import/export, additional field types, developer docs. Old `incl/addons/admin/` and `incl/addons/includes/` deletion if all groups migrated. | when ready |

## Acceptance criteria — Phase 1

After Phase 1 ships:

1. ✅ Existing addon groups work identically — no operator-visible change
2. ✅ Migration runs on plugin update; every group gets `pricing_mode = legacy`, `options_source = manual`, `schema_version = 2`
3. ✅ All four pricing strategy classes pass behavioral tests with the canonical price-extraction contract
4. ✅ Attribute source provider correctly reads terms + applies include/exclude
5. ✅ Repository round-trips an Addon_Group through save → load → save without data drift
6. ✅ The `incl/addons/admin/` and `incl/addons/includes/` directories untouched (still operate the legacy code path)
7. ✅ Test suite green: 256 (existing) + ~50 new tests

## Risks

- **Migration safety**: writing to `_product_addons` of every `lafka_glb_addon` post during plugin update. Mitigation: idempotent (checks `schema_version`), runs in batches of 50, dry-run mode available.
- **Schema drift**: if Phase 2 admin form needs a field we didn't anticipate in Phase 1's schema, we add another migration. Cheap.
- **Existing patches**: v8.12.x added `preserve_nested_prices_on_save`, the array-walker, etc. The new repository must call into or reproduce those guards. Mitigation: repository delegates to the existing helper functions where they exist; tests verify the same behavior.

## Open questions (resolved)

- **Size deselect → addon group hidden** for that size. ✅
- **Attribute source = manual sync** (not auto). ✅
- **One source attribute per group** (no multi-attribute groups in Phase 1). ✅
- **All four pricing modes available**, operator picks. ✅
- **Existing groups stay in legacy mode**, operator opts in to new modes. ✅
