# Lafka tracking — single source of truth

Every analytics signal the storefront emits is defined here. There is **one**
tracking layer: server/JS push to `window.dataLayer` (no direct `gtag()` calls in
the event layer). How those pushes reach your tools depends on the setup:

- **GTM mode** (a container ID is set) — GTM is the router. GA4 / Microsoft
  Clarity / Meta Pixel are wired *inside GTM*; the plugin emits no direct tags.
- **Direct-tag mode** (no GTM) — the plugin emits GA4 / Clarity / Meta Pixel
  tags itself. For GA4 it installs a small `dataLayer.push` → `gtag('event', …)`
  forwarder, because gtag.js ignores GTM-format `{event, ecommerce}` pushes;
  without it a GA4-only site would get pageviews but no ecommerce funnel.

Cloudflare Web Analytics is the one exception in both modes — it's a cookieless
first-party beacon emitted directly.

## Configuration (Customizer → "Lafka — Analytics")

| Field (theme_mod) | Purpose |
|---|---|
| `lafka_gtm_container_id` | GTM container (`GTM-…`). If set, **only** GTM emits (override-not-additive). |
| `lafka_ga4_measurement_id` | GA4 (`G-…`) — used when GTM empty. |
| `lafka_clarity_project_id` | Microsoft Clarity — used when GTM empty. |
| `lafka_cf_beacon_token` | Cloudflare Web Analytics token (32 hex). Cookieless → always emits, independent of GTM/consent. |
| `lafka_meta_pixel_id` | Meta Pixel — only if running paid FB/IG ads. |
| `lafka_gsc_*` / `lafka_consent_*` | Search Console verification + Consent Mode v2 defaults (default: denied). |

Nothing emits until at least one destination is configured
(`lafka_analytics_is_active()`). **Lafka Insights counts as a destination**
while it collects, so the event layer runs on a site with no GA4/GTM at all.

## Lafka Insights — first-party funnel analytics (module `insights`)

Off by default; turn it on under **Lafka → Modules**, read it under
**Lafka → Insights**. No third party, no Google account.

**Collection.** `assets/js/lafka-insights.js` (≈2 KB, deferred, never on admin,
the kitchen display or for shop staff) reads the dataLayer events below —
`page_context`, `view_item_list`, `view_item`, `select_item`, `search`,
`store_closed_view`, `select_fulfilment`, `order_channel_click`,
`add_shipping_info` — and sends **one** `navigator.sendBeacon` per page on
`pagehide` to `POST /wp-json/lafka/v1/i` with the page type, the referrer
*host*, `utm_source/medium/campaign` and a viewport device class. The money
path is recorded **server-side** from WooCommerce hooks (so ad-blockers and page
caches can't hide it), for classic and block checkout alike: add/remove cart,
cart and checkout views, payment attempt, order placed (once per order),
payment failed (classified avs / cvv / declined / gateway_error / other from the
gateway note), plus every `do_action( 'lafka_checkout_blocked', $reason, $context )`.

**Visits without cookies.** `sid = sha256( daily secret | IP | browser family | site )`.
The secret (option `lafka_insights_secret`) is replaced every local day and the
old one deleted, so yesterday's visits can't be re-identified; the IP is never
stored. Behind Cloudflare, tick *This site is behind Cloudflare* so the real
visitor IP (`CF-Connecting-IP`) is used; other proxies: filter
`lafka_insights_client_ip`.

**Storage.** `{prefix}lafka_insights_sessions` (one row per visit per day:
furthest funnel stage, refusal reasons, device, source, landing page type,
hour/weekday — kept 35 days) and `{prefix}lafka_insights_daily` (aggregate
counters, 25 months). A nightly Action Scheduler job (03:10 site time) rolls up,
prunes and rotates the secret. Orders by source come straight from
WooCommerce Order Attribution.

**Consent** (Customizer → Lafka — Analytics → *Insights (first-party)*,
theme_mod `lafka_insights_consent_mode`):

| Mode | Behaviour |
|---|---|
| `aggregate` (default) | Cookieless; skips browsers sending Global Privacy Control / Do Not Track; needs no cookie banner. |
| `consent_required` | Nothing is measured until the visitor allows analytics in the consent banner, which then mirrors the choice into the first-party `lafka_consent` cookie (read by the server-side events; `assets/js/lafka-consent-mirror.js`, inlined first in `<head>`) and switches WooCommerce Order Attribution on. |
| `off` | Collects nothing; reports stay readable. |

A privacy-policy paragraph is added to *Settings → Privacy → Policy guide*.

**Weekly email.** WooCommerce → Settings → Emails → *Weekly Insights* (on by
default once the module is on; recipient defaults to the admin email):
Monday 08:00 site time, last week in plain English. Zero visits → it says
tracking may be broken.

**Guards on `/lafka/v1/i`** (anonymous beacons carry no nonce because pages are
full-page cached): same-origin `Origin`/`Referer`, ≤ 2 KB strict schema, bot
filter, staff excluded, per-visit daily cap (`lafka_insights_visit_cap`, 300)
and site-wide hourly cap (`lafka_insights_global_cap`, 5000). One read + at most
two writes per beacon.

**Filters:** `lafka_insights_consent_mode`, `lafka_insights_behind_cloudflare`,
`lafka_insights_client_ip`, `lafka_insights_exclude_user`,
`lafka_insights_store_is_open`, `lafka_insights_search_engine_pattern`,
`lafka_insights_block_reason_bits`, `lafka_payment_failure_keywords` (the GX1
classifier Insights shares; `lafka_insights_payment_failure_keywords` only
without it),
`lafka_insights_placed_statuses`, `lafka_checkout_block_reasons` (labels),
`lafka_beacon_bot_pattern`.

## JavaScript error beacon (with the `diagnostics` module — on by default — or `insights`)

A ≤ 700-byte inline `<head>` handler reports uncaught errors and unhandled
promise rejections from **same-origin** scripts (≤ 3 per page, ≤ 10 per tab
session) to `POST /wp-json/lafka/v1/diag`, which logs them via `lafka_log()`
(channel `js`) or, without the logging facade, WooCommerce → Status → Logs
(source `lafka-js`). Sample rate: `lafka_log_settings[js_sample]` (default 1).
Filter `lafka_diag_js_beacon_enabled` to force it on/off.

## Event dictionary

### Page context — `incl/analytics/lafka-page-context.php` (wp_head pri 3, every page)
`page_context` → `page_type` · `fulfilment_method` · `store_open` ·
`customer_logged_in` · `customer_is_repeat` · `cart_items_count` ·
`cart_value_band` (`empty`/`under_25`/`25_40`/`40_55`/`55_plus`) · `top_category`.

### Ecommerce (GA4 shape) — `incl/analytics/lafka-wc-events.php` + `assets/js/lafka-dl-client.js`
`view_item` · `view_item_list` · `select_item` · `add_to_cart` ·
`remove_from_cart` · `view_cart` · `begin_checkout` · `add_shipping_info` ·
`add_payment_info` · `purchase` · `search`. Item shape from the SSOT helper
`lafka_dl_item_payload()`. `purchase` fires once per order (meta-gated).

Client-side (`lafka-dl-client.js`):

| Event | Trigger | Params |
|---|---|---|
| `select_item` | click `a[data-lafka-item-id]` | item from the `data-lafka-item-*` attrs + `data-lafka-list-name` |
| `search` | typing in the menu search field `[data-lafka-menu-search-input]` (debounced 350 ms, ≥ 2 chars) | `search_term`, `results_count` (the `[data-lafka-item-id]` inside `[data-lafka-menu-results]` when the theme marks one, else every one not inside a `[hidden]` element) |
| `add_shipping_info` | change of a `shipping_method*` radio | `shipping_tier`, `currency`, `value`, `items` (from `[data-lafka-checkout-item]` rows, else the cart items localized as `lafkaDlCheckout`) |
| `add_payment_info` | change of the `payment_method` radio | `payment_type`, `currency`, `value`, `items` (same source) |

### Custom interactions — `incl/analytics/lafka-custom-events.php`
`phone_click` · `email_click` · `get_directions_click` · `faq_open` ·
`filter_apply` · `scroll_milestone` · `outbound_link` · `sticky_cart_open`.

### Store-specific — `assets/js/lafka-store-events.js`
| Event | Trigger | Params |
|---|---|---|
| `order_channel_click` | click `[data-lafka-order-channel]` | `order_channel` (direct/ubereats/skipthedishes/doordash/phone), `order_source` |
| `select_fulfilment` | click `[data-lafka-fulfilment]` | `fulfilment_method` (delivery/pickup), `fulfilment_source` |
| `select_addon` | change inside `.product-addon` | `product_id`, `addon_name`, `addon_value`, `price_delta` |
| `store_closed_view` | `.lafka-store-closed-card` enters viewport (one-shot) | `closed_context` |

## Data-attribute contracts (theme ↔ tracking — keep in sync)

The theme must emit these stable hooks; the tracking JS binds to them:

```
[data-lafka-order-channel="direct|ubereats|skipthedishes|doordash|phone"]
[data-lafka-order-source="home_hero|menu|cart|footer|..."]
[data-lafka-fulfilment="delivery|pickup"]  [data-lafka-fulfilment-source="..."]
.lafka-store-closed-card[data-lafka-closed-context="pdp|cart|checkout"]
.product-addon[data-product-id][data-addon-name]   (addons engine)
a[data-lafka-item-id][data-lafka-item-name][data-lafka-item-category][data-lafka-item-price][data-lafka-list-name]  (product cards → select_item)
[data-lafka-menu-search-input] + [data-lafka-menu-results]  (menu search → search)
[data-lafka-checkout-item][data-lafka-item-id][data-lafka-item-name][data-lafka-item-category][data-lafka-item-price][data-lafka-item-quantity]  (checkout summary rows → add_shipping_info / add_payment_info items)
```

When no `[data-lafka-checkout-item]` rows are present, `add_shipping_info` /
`add_payment_info` still fire with an empty `items` array (GA4 accepts it).

> **`order_channel_click` is the core growth signal.** The conversion workstream
> places `[data-lafka-order-channel="direct"]` on the "Order direct — skip the
> 30% app fees" CTA and `=ubereats|skipthedishes|doordash` on aggregator links,
> so we can measure first-party vs commission-channel intent.

## Operator setup

1. Create a GTM container → paste its ID in the Customizer (one field wires all).
2. In GTM: add a GA4 Configuration tag (paste your `G-…`), Consent Mode v2, and
   GA4 Event tags triggered on the custom event names above. (An importable
   container template is the planned `gtm-container-template.json`.)
3. Enable Clarity + Cloudflare Web Analytics, paste their IDs/token.
