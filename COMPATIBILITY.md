# Lafka Compatibility Matrix

Tested combinations of Lafka theme + plugin + child + their dependencies.
Each row is **known-good** — runs the test suite green and the smoke
checklist passes in staging.

> The "minimum" floor is what `composer.json` / plugin headers enforce.
> The "recommended" column is what the maintainers run in CI today.

## Stack versions

| Component   | Minimum | Recommended | Latest tested |
|-------------|---------|-------------|---------------|
| **PHP**     | 8.1     | 8.3         | 8.3           |
| **WordPress** | 6.6   | 6.9         | 6.9.4         |
| **WooCommerce** | 9.5 | 10.7        | 10.7          |
| **Node.js** (build only) | 20 | 20 | 20         |

## Package versions

| Package         | Current release | Minimum sibling versions |
|-----------------|-----------------|--------------------------|
| lafka-plugin    | **8.7.0**       | theme ≥ 5.8.0, child ≥ 5.5.0 |
| lafka-theme     | **5.8.0**       | plugin ≥ 8.7.0 (optional but expected) |
| lafka-child     | **5.5.0**       | theme = 5.8.0 (parent) |

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

## Known incompatibilities

- **stylelint ^17** — incompatible with `@wordpress/stylelint-config@23.x`
  (peer dep requires ^16.8.2). Pinned to ^16.26.1 in all 3 repos. Revisit
  when @wordpress/stylelint-config@24+ ships.
- **WP < 6.6** — uses `wp_body_open()` (since 5.2) but several other APIs
  the codebase depends on (CPT REST, modern HPOS hooks) are 6.6+.
- **WC < 9.5** — combos + addons rely on hook signatures changed in 9.5.
- **PHP < 8.1** — uses `static fn()` short closures (since 7.4 actually,
  but 8.1 is the floor for declared types in pricing helpers).

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

## Updating this document

This file is hand-maintained. After bumping any of the headers in
`composer.json`, `style.css`, or `lafka-plugin.php`, update the matrix
above to match. The CI workflow doesn't auto-generate it.

For per-release verification, see the operator checklist at the end of
`LAFKA_PROGRESS.md`.
