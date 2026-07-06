# Performance & caching playbook

Fast pages = better Core Web Vitals = better local ranking + higher conversion.
The code side ships LCP hero preload, deferred non-critical CSS, asset pruning,
and conditional script loading. This file is the **infra/operator checklist** —
mostly Cloudflare (already in front of the site) + WordPress cache headers.

## What the code already does
- **LCP**: homepage hero image is `<link rel=preload>`'d (set it in WooCommerce →
  Settings → Restaurant → Homepage Hero). Fonts (Fraunces) are preloaded.
- **CSS**: page-specific stylesheets load only where needed (e.g. PDP CSS only on
  products); non-critical CSS is deferred (`media=print` → `onload`).
- **JS**: WPBakery front JS is dequeued off the front page; WooCommerce
  add-to-cart script loads where the drawer/upsell can appear.
- **Schema/markup**: single `@graph` block, no duplicate SEO-plugin output.

## Cloudflare (do these in the dashboard)
- [ ] **Caching → Configuration**: Browser Cache TTL = "Respect existing headers".
- [ ] **Speed → Optimization**:
  - Auto Minify: leave OFF (assets are already built/minified; double-minify risk).
  - **Brotli**: ON.
  - **Early Hints**: ON (works with the preload links).
  - Rocket Loader: **OFF** (it reorders JS and can break the cart/menu scripts).
- [ ] **Tiered Cache**: ON (Smart Tiered Caching).
- [ ] **Cache Rules** — cache static assets aggressively, never cache the funnel:
  - Cache `*.css *.js *.woff2 *.png *.jpg *.webp *.svg` → Eligible for cache, Edge TTL 1 year.
  - **Bypass cache** for `/cart*`, `/checkout*`, `/my-account*`, `/?add-to-cart=*`, and any URL with WooCommerce session cookies. (Caching these breaks live carts.)
  - Bypass cache for `wp-admin`, `wp-login.php`, `/wp-json/*` (or short TTL).
- [ ] **WAF**: keep the existing rules; ensure `admin-ajax.php` + `/wp-json/` aren't blocked (the cart drawer + tracking use them).

> ⚠️ Never full-page-cache logged-in or cart/checkout responses. The promos
> (first-order, slow-day, combo, free-delivery) compute per-cart at request time;
> caching the cart/checkout HTML would show stale totals.

## WordPress / origin cache headers
If a page-cache plugin is used, exclude: cart, checkout, my-account, and any page
with `woocommerce_items_in_cart` / session cookies. Static assets already carry
far-future cache headers via the build; confirm the host isn't stripping them.

## Images (operator)
- [ ] Upload product photos sized ~1200px max; let WP generate the responsive set.
- [ ] Any product with **no image** hurts both conversion and the merchant feed —
  audit your catalogue and add photos. Priorities: top sellers first.
- [ ] Consider Cloudflare **Polish** (WebP/AVIF auto-conversion) = ON, lossy — a
  zero-code way to serve modern formats without a WP WebP pipeline.

## Measure (target: green CWV)
- **PageSpeed Insights** / Search Console "Core Web Vitals" report — watch the
  field data for `/`, `/menu/`, and a product page.
- Targets: **LCP < 2.5s**, **INP < 200ms**, **CLS < 0.1** (mobile first).
- Re-test after any theme/asset change. The home hero image is the usual LCP
  element — keep it preloaded + appropriately sized.

## Still on the code roadmap (needs visual QA before shipping)
- Regenerate critical/above-the-fold CSS after the conversion-phase markup changes.
- Purge dead rules from the legacy `style.css` monolith (large; high-risk → must
  be verified at 375/768/1280 before release).
- WPBakery → native template migration for the remaining builder pages (removes
  the heavy js_composer asset load site-wide).
