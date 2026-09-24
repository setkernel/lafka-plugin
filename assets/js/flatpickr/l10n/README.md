# Flatpickr locale files

Only the most-used locales ship with the plugin: `ar`, `de`, `es`, `fr`,
`he`, `it`, `ja`, `ko`, `nl`, `pl`, `pt`, `ru`, `sv`, `tr`, `zh`. Each file is a
standalone UMD bundle — no `index.js` / `default.js` is needed, and English is
flatpickr's built-in default.

The PHP enqueue at `lafka-plugin.php` resolves the right file from
`get_locale()`, trying `<ll-cc>.js`, then `<ll_cc>.js`, then the 2-letter
`<ll>.js`, with a `file_exists()` check. If the user's locale isn't in this
directory (or a child-theme override), no localized calendar JS loads — the
date picker falls back to its built-in English text. Functional, just not
localized.

## Add another locale

Two ways:

**1. Drop into the active child theme** (no plugin re-deploy needed):

    /wp-content/themes/<your-child>/lafka_plugin_templates/flatpickr_l10n/<locale>.js

The plugin's enqueue logic falls back to child-theme overrides when the
plugin's own copy is missing.

**2. Pull the file from upstream** and commit to the plugin:

    curl -O https://raw.githubusercontent.com/flatpickr/flatpickr/master/dist/l10n/<locale>.js

Place it in this directory. The PHP enqueue picks it up automatically on
next request.

## Why prune the rest

Every release zip used to ship 64 locale files (~356 KB) when only one is
ever loaded per request. The trimmed set covers ~80% of WP installs while
shrinking the release zip by ~188 KB.
