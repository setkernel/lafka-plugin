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
(`lafka_analytics_is_active()`).

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
| `search` | typing in the menu search (debounced 350 ms, ≥ 2 chars) | `search_term`, `results_count` (count of `[data-lafka-item-id]` inside `[data-lafka-menu-results]`) |
| `add_shipping_info` | change of a `shipping_method*` radio | `shipping_tier`, `items` from `[data-lafka-checkout-item]` |
| `add_payment_info` | change of the `payment_method` radio | `payment_type`, `items` from `[data-lafka-checkout-item]` |

> **Known issue — `search` never fires.** The client binds
> `[data-lafka-menu-search]` and reads its `.value`, but the theme puts that
> attribute on the search `<form>`; the text field is
> `[data-lafka-menu-search-input]`. The intended contract is the input
> attribute below; the JS fix is tracked separately.

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
