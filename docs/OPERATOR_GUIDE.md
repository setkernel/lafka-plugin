# Operator guide

How to run a store on the Lafka plugin: find what went wrong, set up local
search, keep pages fast, and understand what the store tracks. Requirements and
compatibility are in the [README](../README.md#requirements--compatibility).

Contents: [Diagnostics](#diagnostics) · [Local SEO](#local-seo) ·
[Performance](#performance) · [Tracking](#tracking)

## Diagnostics

Lafka writes problems to **WooCommerce → Status → Logs**, keeps a short list of
distinct incidents, and records every reason a customer could not add to cart,
check out or pay. Code: `incl/observability/`.

### Store owners

- **Lafka → Diagnostics** (module `diagnostics`, on by default):
  - *Incidents*: each distinct problem once, with a count and last-seen time.
    Mute (still counted, left out of emails), resolve (re-opens if it happens
    again), or open its lines in the WooCommerce log viewer.
  - *Checkout failures*: refusals by reason for the last 30 days, recent failed
    payments (declined, address check (AVS), security code (CVV), gateway
    error), and checkout attempts that stopped part-way or failed (WooCommerce
    place-order traces). Traces that reached a success step are treated as
    finished: hidden behind "Show finished attempts", never indexed, never in the
    digest. Filter: `lafka_place_order_trace_outcome`.
  - *Health*: versions, checkout mode, modules, WooCommerce logging settings,
    daily job status.
  - *Settings*: minimum log level, incident retention (default 90 days after
    last seen), "Send test error", "Run the daily check now".
- **Daily error digest**: WooCommerce → Settings → Emails → *Lafka error digest*.
  On by default, sent to the site admin email once a day, only when something new
  happened.
- **Site Health** (Tools → Site Health): a Lafka fatal error in the last 24 hours
  (critical), three or more payment failures in 7 days, background jobs not
  running.

Turning the module off (Lafka → Modules) hides the screen, the Site Health checks
and the digest. Logging itself stays on.

Personal data is removed before anything is written: emails, phone numbers, card
numbers, postal codes, IP addresses, tokens, and address, billing and shipping
fields by name. Order and product ids are kept.

### Developers

**Logging.**

```php
Lafka_Log::error( 'payment', 'Gateway timed out', array( 'code' => 'gateway_timeout', 'order_id' => $order_id ) );
lafka_log( 'warning', 'shipping', 'Zone polygon is empty', array( 'code' => 'empty_zone' ) );
```

- Levels: `debug info notice warning error critical alert emergency`.
- Documented channels (`Lafka_Log::CHANNELS`): `core checkout payment store-api
  order-hours shipping timeslots addons kds conversion analytics js theme child
  cron rest php`. Each is the WooCommerce log source `lafka-{channel}`.
- Minimum level: `warning`, or everything under `WP_DEBUG`. Option
  `lafka_log_settings[min_level]`; filter `lafka_log_min_level( $level, $channel )`.
- Every record gets `request_id`, `channel`, `code`, `url_path` (no query string)
  and `lafka_version`.
- Records at `warning` and above are indexed in `{prefix}lafka_incidents` and fire
  `do_action( 'lafka_log_record', $record )`. Forward to Sentry or similar there.
- Scrubber filters: `lafka_log_scrub_keys` (glob key patterns),
  `lafka_log_scrub_patterns` (regex to replacement).
- `Lafka_Log::guard( $callable, $channel, 'rethrow'|'wp_error'|'swallow' )` wraps
  an entry point so an uncaught Throwable is logged with context. Lafka's REST
  callbacks run guarded, as do its cron hooks (`lafka_guarded()`).
- The theme and child theme log without the plugin:
  `do_action( 'lafka_log', $level, $channel, $message, $context )`.
- Correlation: `X-Lafka-Request-Id` response header on Lafka REST, Store API and
  classic checkout; `_lafka_request_id` order meta on orders created at checkout.
- Fatal errors: WooCommerce's `woocommerce_shutdown_error` is indexed as a `php`
  critical incident only when the file is in the Lafka plugin, theme or child
  (filter `lafka_fatal_capture_roots`).

**`lafka_checkout_blocked`: the "why no order" contract.**

```php
do_action( 'lafka_checkout_blocked', string $reason, array $context );
```

Fired once per reason per request at every refusal point. The signature does not
change. Reasons are the constants of `Lafka_Checkout_Block_Reasons`; labels are
filterable through `lafka_checkout_block_reasons`.

| Reason | Fired by |
|---|---|
| `store_closed` | Order hours: classic checkout, add-to-cart, Store API checkout |
| `outside_delivery_zone` | Shipping areas geo-fence (classic and Store API) |
| `address_unpinned` | Delivery order without a valid map pin |
| `timeslot_invalid` | Missing, invalid, past or full date or slot |
| `branch_invalid` | Store API branch selection not orderable |
| `order_type_unavailable` | Order type not offered by the branch |
| `addon_invalid` | Add-on validation rejected an add-to-cart |
| `below_delivery_minimum` | Promotions withheld delivery rates (once per session per day) |
| `no_shipping_method` | WooCommerce found no delivery or pickup method |
| `field_validation` | WooCommerce checkout validation; `context.fields` holds field names, never values |
| `store_api_error` | Any other Store API checkout rejection |
| `payment_declined` `payment_avs` `payment_cvv` `payment_gateway_error` `payment_other` | Order moved to *failed*, classified from the gateway note (`lafka_payment_failure_keywords`) |

Context keys: `path` (`classic` / `store_api` / `order`), `stage` (`add_to_cart` /
`cart` / `checkout` / `payment`), `code`, and where relevant `fields`, `order_id`,
`gateway`, `class`. Never customer-entered values.

Lafka's own listener logs each reason (`checkout` channel at notice; payment
reasons on `payment` at warning, which makes them incidents) and keeps 35 days of
per-day counters in `lafka_log_checkout_stats`.

**Storage and jobs.**

- Table `{prefix}lafka_incidents` (version option `lafka_incidents_db_version`,
  self-heals on `init`), dropped on uninstall.
- Options: `lafka_log_settings`, `lafka_log_checkout_stats`,
  `lafka_log_last_daily_run`, `lafka_log_seen_traces`.
- Action Scheduler action `lafka_diagnostics_daily` (group `lafka`, 07:00 site
  time; filter `lafka_diagnostics_daily_hour`): prunes incidents and counters,
  indexes stale unfinished place-order traces, sends the digest.
- JavaScript errors: a small inline `<head>` handler reports uncaught errors and
  unhandled promise rejections from same-origin scripts (at most 3 per page and
  10 per tab session) to `POST /wp-json/lafka/v1/diag`, logged on channel `js`.
  Sample rate `lafka_log_settings[js_sample]` (default 1). Filter
  `lafka_diag_js_beacon_enabled` forces it on or off. It runs with the
  `diagnostics` or `insights` module.

## Ordering features

### Deals: "any 2 pizzas for $20"

Create a product and choose the type **Deal (customer picks the items)**. Its
Regular / Sale price is the deal price. The **Deal slots** tab lists what the
customer chooses, one slot per item:

- **Categories** and/or **Also these items**: the slot's choices; **Never these
  items** takes some out.
- **Lock options**: for example *Size: Medium*, so every pizza in the slot is a
  medium.
- **Required**: untick for an optional add-on item ("Add a side"), which costs its
  own price on top.
- **Premium items pay the difference**: a dearer choice adds the difference over the
  slot's cheapest choice (shown as "+$1.00").
- **Runs on** / **First day** / **Last day** (top of the tab): "Two for Tuesday" runs
  only on the ticked weekdays (site timezone) and between the dates. On other days
  the deal page says when it runs, and a deal still in a cart is removed with a
  notice. Leave all of it empty to run the deal every day.

On the deal's page the customer picks each item and sets its own options (crust,
toppings, half and half); a sticky bar shows the total and what is still missing.
Each chosen item becomes its own cart line, so stock, tax, kitchen tickets and
refunds work per item; the deal price is split across the lines, extras on top.
Deal lines cannot change quantity, removing one removes the deal, and BOGO and the
combo discount never apply to them. Lists and menu cards link to the deal's page.
Lafka → Modules → **Deals** (on by default).

### Half and half

In a product add-on group (Products → Add-ons), tick **Half and half** (checkbox
groups). Each ticked option then offers *Left / Whole / Right*; a half costs half the
option price (filter `lafka_addon_half_price_factor`). The cart, order, emails and
kitchen screen read "Olives (left half)".

### Tips

**WooCommerce → Settings → Restaurant → Tips** (also Lafka → Modules): suggested
percentages of the order's items, an optional custom amount, every order or delivery
only, and the label. The tip is a separate, non-taxable fee line on the order, on
the classic and the block checkout.

### Delivery maps (no API key needed)

Every map works out of the box with **OpenStreetMap**: the store location, the
delivery-zone editor, branch addresses, the location popup, the checkout pin and the
`[lafka_shipping_areas]` zone map. A **Google Maps API key** is optional (Lafka
Shipping Settings → General, or the Lafka theme's Customizer; it is one setting): with
it the maps use Google and the location popup suggests addresses as customers type.
Enable the Maps JavaScript, Places and Geocoding APIs and restrict the key to your
site. Empty the field to go back to OpenStreetMap.

- **Store location** (Lafka Shipping Settings → Advanced): click the map, drag the
  pin, search an address or use the WooCommerce store address, then save. It is the
  same point as the coordinates under WooCommerce → Settings → Restaurant (and in the
  search-engine schema). Every map opens there; with no point yet they open on your
  WooCommerce province or state, else your country, else Canada.
- **Delivery zones** (WooCommerce → Lafka Shipping Areas): click the map to add a
  corner, drag a corner to move it, click a corner to remove it, drag the small dot
  between two corners to add one. Checkout refuses a pinned address outside every
  zone.
- **Checkout pin** (Lafka Shipping Settings → General → Pick Precise Delivery
  Address from Map, classic checkout): the typed address is looked up once; a precise
  match becomes the pin, otherwise the customer clicks the map, drags the pin or uses
  **Use my location**. "Mandatory" only applies where this pin map exists: the block
  checkout has none yet, so it never asks for a pin.
- **Location popup**: customers type their address and press **Find** (or use their
  location); a pickup-only store loads no map at all.

Without a key, addresses are looked up through OpenStreetMap's Nominatim service from
your server: one lookup per address, cached for 30 days, at most one request a second,
sent with your site address and admin email so the service can contact you, and
limited to 20 lookups an hour per visitor. Nothing is looked up while customers type.
For more than light use point the `lafka_geocoder_endpoint` filter at your own
Nominatim (or a compatible service). Map tiles come from OpenStreetMap
(`lafka_map_tile_url`, `lafka_map_tile_attribution` to use another tile server); the
browser caches them, the site does not. Filters: `lafka_map_default_view`,
`lafka_store_point`, `lafka_maps_provider` (return `osm` to ignore a saved key, or
`none` to switch maps off), `lafka_geocoder_contact_email`,
`lafka_geocoder_user_agent`, `lafka_delivery_pinpoint_ui_available`.

### Delivery by distance

A WooCommerce shipping method that prices delivery by how far the customer is from the
restaurant. No other plugin and no Google account are needed.

**Setup.** Make sure the store location is set (Lafka Shipping Settings → Advanced; it is
the origin). Then **WooCommerce → Settings → Shipping → your zone → Add shipping method →
Delivery by distance**, and open it:

- **Distance bands**: "Up to" a distance, the **Fee**, and an optional **Plus per km**
  (fee + rate × distance). The first band the distance fits in sets the price. A band with
  no "Up to" covers everything further. To add a band, fill a blank row and save; empty a
  row to remove it.
- **Maximum distance**: beyond it there is no delivery rate. The customer is told, and
  can choose Pickup. Leave it empty for no limit.
- **Distance unit** (km or miles), **tax status**, **title**, and whether to show the
  distance ("Delivery · 4.2 km" under the rate, on the order, in emails and on the
  kitchen display).
- **Free over the store's free-delivery threshold**: on by default. The threshold itself
  is set in one place (WooCommerce → Settings → Restaurant → Promotions); this method only
  reads it. A delivery minimum is the Promotions **delivery minimum**, also set once.

With branch selection on, the distance is measured from the chosen branch; otherwise from
the store location.

**Where the customer's point comes from.** The pin on the classic checkout map when there
is one (and it was placed for the address now typed); else the address is looked up once
(Nominatim, cached 30 days). If the address is not found to street level, the lookup fails
or the country differs, no delivery rate is offered and a line is written to WooCommerce →
Status → Logs (`lafka-shipping`) so you can see why. It never guesses a price.

**Keyless or driving distance?**

| | Straight line × road factor (default) | Driving distance |
| --- | --- | --- |
| Needs | nothing | the Google Maps key (Google Routes API, billed by Google) or your own OSRM server (`lafka_distance_osrm_endpoint` filter) |
| Accuracy | an estimate: roads are about 1.3× the straight line in most towns (change the factor); rivers, highways and one-way grids make some addresses over- or under-priced | the real road distance |
| Failure | none beyond the address lookup | if Google or OSRM is unreachable or the key is removed, there is no delivery rate until it works |

Start with straight line, compare a few real addresses against what you charged before,
and adjust the factor. Enable the **Routes API** on the key if you choose driving.
Filters: `lafka_distance_osrm_endpoint`, `lafka_distance_driving_meters` (any other
router), `lafka_distance_google_endpoint`, `lafka_distance_origin`,
`lafka_distance_default_bands`, `lafka_distance_unavailable_message`.

**Switching from WooCommerce Distance Rate Shipping.** Run
`wp lafka shipping migrate-drs` to see every Distance Rate Shipping instance, its rules and
how they map to bands (the Google key is never printed), plus what cannot be mapped
(travel-time, weight or quantity rules, order-total conditions, shipping classes, per-item
costs, percentage fees, "avoid tolls", walking mode). Then
`wp lafka shipping migrate-drs --apply` adds a **disabled** copy to the same zone. Compare
the fees for a few real addresses, then disable the old method and enable the new one in
the zone (and deactivate the old plugin when you are happy). Check the DRS origin address
is the same place as your store location.

## Local SEO

Goal: rank in the Google Map Pack and local results for "[cuisine] near me" and
turn that into website orders, not third-party app orders. The plugin handles
schema, performance and honest review display. The rest is yours, and it moves
the result more than any code change. Do 1, 2 and 3 first.

### 1. Google Business Profile

Manage at <https://business.google.com>.

- [ ] Claim and verify the listing.
- [ ] Primary category that matches your cuisine (for example `Pizza restaurant`),
  plus secondaries such as `Restaurant`, `Takeout restaurant`, `Delivery restaurant`.
- [ ] Name, address and phone exactly as on the website (see 3).
- [ ] Hours match **WooCommerce → Settings → Restaurant → Hours**. Set holiday hours.
- [ ] "Has online ordering": point the order link at your website, not an
  aggregator. **WooCommerce → Settings → Restaurant → Search & AI** lists the exact
  links to paste (Google order and menu links, Apple Business Connect, Bing Places),
  each tagged so Insights counts the orders they bring. Set delivery and takeout to
  yes. (Ordering inside Google Search ended in 2024; the listing's direct link is
  what remains.)
- [ ] 10 or more photos (storefront, interior, hero dishes, team); add a few weekly.
- [ ] Best sellers with photos and prices under Products or Menu.
- [ ] A weekly post (promo, new item, event).
- [ ] Seed 5 to 10 Q&A entries (delivery area, gluten-free, parking) and answer them.

### 2. Schema settings

Set these in **WooCommerce → Settings → Restaurant**. The Customizer panel
**Lafka — Restaurant Information** holds the same fields as a fallback, used only
where the WooCommerce tab is empty. Empty fields are left out of the schema.

- [ ] Cuisine and payment methods.
- [ ] Schema and geo: business type, price range, phone (E.164 and display), email,
  exact latitude and longitude.
- [ ] Social profiles: every profile URL (they become schema `sameAs`).
- [ ] Hours per day (they become `openingHoursSpecification`).

Check: view the homepage source, search `application/ld+json`, and confirm
`servesCuisine`, `sameAs`, `paymentAccepted` and `geo` appear, or run the URL
through <https://search.google.com/test/rich-results>.

The plugin emits `Restaurant` / `LocalBusiness` / `FoodEstablishment` (with an
`OrderAction` pointing at the menu for each way you hand food over), `WebSite`,
`BreadcrumbList`, `Menu`, per-product `Product` / `Offer`, and `areaServed`. With an
SEO plugin active (Yoast, Rank Math, SEOPress, All in One SEO) that plugin owns the
`WebSite`, breadcrumb and product nodes; Lafka still emits the restaurant, menu and
FAQ data those plugins do not have.
`aggregateRating` appears only on `Product` nodes, from real WooCommerce product
reviews. It is never put on the `Restaurant` node (Google's structured-data
policy) and never comes from a decorative setting.

For AI and search crawlers the plugin also serves `/llms.txt`, `/llms-full.txt`,
`/menu.md` and `/menu.json` (the home page links to `/llms.txt`), and can ping
IndexNow. None of them is served while Settings → Reading → "Discourage search
engines" is ticked, so a staging copy publishes nothing.

### 3. Name, address, phone

Pick one format and use it identically everywhere: site, Google, Facebook,
Instagram, Yelp, Apple Maps, directories. On the site the sources are
WooCommerce → Settings → General (address) and the Restaurant tab (phone). The
footer, schema and announce bar all read through `lafka_get_restaurant_info()`.
Place `[lafka_nap]` where you want the visible block.

### 4. Reviews

- [ ] Ask every customer for a Google review (the review link on the thank-you page
  and packaging).
- [ ] Reply to every review within a day.
- [ ] Aim for a steady few per month, not bursts.
- On-site product reviews show real WooCommerce reviews only.

### 5. Citations

Claim consistent listings: Apple Business Connect, Bing Places, Yelp, TripAdvisor,
regional business directories. Same name, address and phone everywhere.

### 6. Measure

- Google Search Console: verify the domain, watch local queries, submit the sitemap.
- Lafka Insights and GA4 (see Tracking): `order_channel_click` with channel
  `direct`, `select_item`, `begin_checkout`, `purchase`.
- Monthly: Map Pack rank for your top five queries; Business Profile calls,
  direction requests and website clicks.

The highest-leverage move: make the Business Profile order button go to the
website, and run a website-only promotion advertised in Business Profile posts.

## Performance

Fast pages mean better Core Web Vitals, better local ranking and more orders.

### What the plugin does

- **LCP:** the homepage hero image is preloaded (set it under WooCommerce →
  Settings → Restaurant → Homepage Hero). With the Lafka theme, the active
  preset's display font is preloaded too.
- **CSS and JS:** page-specific files load only where needed; non-critical CSS is
  deferred; block-library CSS, Contact Form 7 and gateway assets are
  dequeued where a page does not use them.
- **Markup:** one JSON-LD `@graph`, with no duplicate SEO-plugin output.

### Page cache and CDN

Never full-page-cache cart, checkout, account or logged-in responses. Promotions
compute per cart at request time, so cached HTML shows stale totals.

- Exclude from page caches: cart, checkout, my-account, and any request with
  WooCommerce session cookies (`woocommerce_items_in_cart`).
- Plugin assets are versioned with `filemtime()`, so long cache lifetimes are
  safe. The `Cache-Control` / `Expires` headers come from your web server or CDN.

Cloudflare, if you use it:

- [ ] Caching → Configuration: Browser Cache TTL = Respect existing headers.
- [ ] Speed → Optimization: Brotli on, Early Hints on, Auto Minify off (assets are
  already minified), Rocket Loader **off** (it reorders JS and can break the cart).
- [ ] Tiered Cache on.
- [ ] Cache static assets (`css js woff2 png jpg webp svg`) with a long edge TTL.
  Bypass the cache for `/cart*`, `/checkout*`, `/my-account*`, `/?add-to-cart=*`,
  `wp-admin`, `wp-login.php` and `/wp-json/*`.
- [ ] WAF: make sure `admin-ajax.php` and `/wp-json/` are not blocked (the cart
  drawer and tracking use them).

### Images

- [ ] Upload product photos about 1200 px wide at most; WordPress makes the
  responsive set.
- [ ] **WebP for new uploads** is on by default when the server's image editor
  (Imagick or GD) can write WebP. Turn it off under **Lafka → Modules → WebP
  images for new uploads** (option `lafka_webp_uploads` = `no`) or with the
  `lafka_webp_uploads_enabled` filter.
- [ ] **Existing images:** run once over SSH, after backing up `wp-content/uploads`:

  ```bash
  wp lafka images convert-webp --dry-run   # preview
  wp lafka images convert-webp             # writes foo.webp beside each foo.png / foo.jpg (quality 80)
  wp media regenerate --yes                # optional: rebuild every thumbnail size
  ```

  `convert-webp` is idempotent (`--force` re-converts, `--dir=2026/01` limits to a
  folder, `--quality=85` raises quality). Pages then use the `.webp` sibling
  automatically; opt out with the `lafka_disable_webp_swap` filter.
- [ ] Give every product an image; missing photos hurt conversion and the
  merchant feed. Top sellers first.
- [ ] Cloudflare Polish (lossy) is a zero-code way to serve modern formats.

### Measure

Use PageSpeed Insights and the Search Console Core Web Vitals report for `/`,
`/menu/` and a product page. Targets on mobile: LCP under 2.5 s, INP under 200 ms,
CLS under 0.1. Re-test after any theme or asset change.

## Tracking

All analytics signals go through one layer: pushes to `window.dataLayer`. How they
reach your tools depends on the setup.

- **GTM mode** (a container ID is set): GTM routes everything. Wire GA4, Clarity
  and Meta Pixel inside GTM; the plugin emits no direct tags.
- **Direct-tag mode** (no GTM): the plugin emits the Google tag (GA4 and Google
  Ads), Clarity and the Meta Pixel itself. Every dataLayer event reaches them through
  one subscription point (`window.lafkaDL`): GA4 receives all events, ecommerce or
  not; the Meta Pixel gets ViewContent, AddToCart, InitiateCheckout, AddPaymentInfo,
  Purchase and Search; Google Ads gets one purchase conversion per order.
- Cloudflare Web Analytics is cookieless and always emits directly.
- **Consent.** Google tags follow Consent Mode v2. Clarity and the Meta Pixel ignore
  it, so they load from the *effective* consent: the visitor's banner decision when
  there is one, otherwise your defaults (so with the banner off and defaults granted,
  they load). Clarity also receives its own consent signal.
- **Consent plugins.** With a plugin that speaks the WP Consent API (Complianz,
  CookieYes, Cookiebot and others) active, Lafka's own banner stands down and every
  Lafka tag, and the server-side conversions, follow that plugin: *statistics* for
  analytics, *marketing* for ads. The WP Consent API plugin on its own (no consent
  manager registering a consent type) changes nothing: it would report every
  category as granted, so Lafka keeps its banner and defaults.

### Configuration

Customizer → **Lafka — Analytics**. Everything is stored in one plugin option,
`lafka_tracking`, so it survives a theme switch (older sites are migrated from
theme_mods once, automatically).

| Field | Purpose |
|---|---|
| `lafka_gtm_container_id` | GTM container (`GTM-...`). If set, only GTM emits. |
| `lafka_ga4_measurement_id` | GA4 (`G-...`), used when GTM is empty. |
| `lafka_ga4_api_secret` | GA4 Measurement Protocol secret: a paid order whose thank-you page never loaded is still recorded (server-side, with analytics consent). |
| `lafka_google_ads_id`, `lafka_google_ads_purchase_label` | Google Ads purchase conversion (`AW-...` and the label). With `ad_user_data` consent the order's email and phone go along as enhanced-conversion data (hashed by Google's tag); GTM users can read them as `window.lafkaUserData` on the order-received page. |
| `lafka_clarity_project_id` | Microsoft Clarity, used when GTM is empty. |
| `lafka_cf_beacon_token` | Cloudflare Web Analytics token. |
| `lafka_meta_pixel_id` | Meta Pixel, only for paid Facebook or Instagram ads. |
| `lafka_tiktok_pixel_id` | TikTok Pixel, only for TikTok ads: the same standard events as Meta (CompletePayment for orders, with `event_id` for Events API dedupe), held until ad consent. |
| `lafka_meta_capi_token` | Meta Conversions API token: every paid order is also sent from the server, deduplicated with the Pixel (`purchase-<order id>`), with ad consent. Left alone when the official Meta for WooCommerce plugin is active. |
| `lafka_gsc_*`, `lafka_consent_*` | Search Console verification and Consent Mode v2 defaults (default denied). |

Server-side sends run ten minutes after an order is paid (Action Scheduler,
`lafka_server_events_send`); failures are logged to the `analytics` channel.

Nothing emits until a destination is configured (`lafka_analytics_is_active()`).
Lafka Insights counts as a destination while it collects.

### Lafka Insights: first-party funnel analytics

Module `insights`, off by default. Enable under **Lafka → Modules**, read under
**Lafka → Insights**. No third party and no Google account.

- **Collection.** `assets/js/lafka-insights.js` (never on admin, the kitchen
  display or for shop staff) reads the dataLayer events and sends one
  `navigator.sendBeacon` per page on `pagehide` to `POST /wp-json/lafka/v1/i`:
  page type, referrer host, `utm_source` / `utm_medium` / `utm_campaign`, device
  class. The money path is recorded **server-side** from WooCommerce hooks (classic
  and block checkout alike): add and remove cart, cart and checkout views, payment
  attempt, order placed (once per order), payment failed (classified), and every
  `lafka_checkout_blocked` reason.
- **Visits without cookies.** `sid = sha256( daily secret | IP | browser family | site )`.
  The secret (option `lafka_insights_secret`) is replaced every local day, so old
  visits cannot be re-identified. The IP is never stored. Behind Cloudflare, tick
  *This site is behind Cloudflare*; for other proxies use the filter
  `lafka_insights_client_ip`.
- **Storage.** `{prefix}lafka_insights_sessions` (one row per visit per day, kept 35
  days) and `{prefix}lafka_insights_daily` (aggregate counters, 25 months). A
  nightly Action Scheduler job (03:10 site time) rolls up, prunes and rotates the
  secret.
- **Honest comparisons.** Only measured visits count. An order is counted in the
  funnel and per source only when its payment attempt came from a measured visit.
  Numbers that combine visits and orders start at the later of the range start and
  the day collection started (option `lafka_insights_collecting_since`); the page
  says "Collecting since ..." and shows no trend against an uncovered period. A
  share whose numerator could exceed its denominator shows "—". All placed orders
  by WooCommerce Order Attribution are listed separately and never divided by
  visits.
- **Consent** (Customizer → Lafka — Analytics → Insights, theme_mod
  `lafka_insights_consent_mode`):

  | Mode | Behaviour |
  |---|---|
  | `aggregate` (default) | Cookieless; skips browsers sending Global Privacy Control or Do Not Track; needs no cookie banner. |
  | `consent_required` | Nothing is measured until the visitor allows analytics in the consent banner, which mirrors the choice into the first-party `lafka_consent` cookie (`assets/js/lafka-consent-mirror.js`) and turns on WooCommerce Order Attribution. |
  | `off` | Collects nothing; reports stay readable. |

  A paragraph is added to Settings → Privacy → Policy guide.
- **Weekly email.** WooCommerce → Settings → Emails → *Weekly Insights*, Monday
  08:00 site time, last week in plain English. Zero visits means tracking may be
  broken.
- **Guards on `/lafka/v1/i`** (anonymous beacons carry no nonce, because pages are
  full-page cached): same-origin `Origin` or `Referer`, strict schema up to 2 KB,
  bot filter, staff excluded, a per-visit daily cap (`lafka_insights_visit_cap`,
  300) and a site-wide hourly cap (`lafka_insights_global_cap`, 5000).
- **Filters:** `lafka_insights_consent_mode`, `lafka_insights_behind_cloudflare`,
  `lafka_insights_client_ip`, `lafka_insights_exclude_user`,
  `lafka_insights_store_is_open`, `lafka_insights_search_engine_pattern`,
  `lafka_insights_block_reason_bits`, `lafka_insights_placed_statuses`,
  `lafka_payment_failure_keywords`, `lafka_checkout_block_reasons`,
  `lafka_beacon_bot_pattern`.

### Event dictionary

**Page context** (`incl/analytics/lafka-page-context.php`, every page):
`page_context` with `page_type`, `fulfilment_method`, `store_open`,
`customer_logged_in`, `customer_is_repeat`, `cart_items_count`, `cart_value_band`
(`empty`, `under_25`, `25_40`, `40_55`, `55_plus`), `top_category`.

**Ecommerce, GA4 shape** (`incl/analytics/lafka-wc-events.php`,
`assets/js/lafka-dl-client.js`): `view_item`, `view_item_list`, `select_item`,
`add_to_cart`, `remove_from_cart`, `view_cart`, `begin_checkout`,
`add_shipping_info`, `add_payment_info`, `purchase`, `search`. Items are built by
`lafka_dl_item_payload()`. `purchase` fires once per order.

Client-side events from `lafka-dl-client.js`:

| Event | Trigger | Params |
|---|---|---|
| `select_item` | click on `a[data-lafka-item-id]` | item from the `data-lafka-item-*` attributes and `data-lafka-list-name` |
| `search` | typing in `[data-lafka-menu-search-input]` (debounced 350 ms, 2 or more characters) | `search_term`, `results_count` |
| `add_shipping_info` | change of a `shipping_method*` radio | `shipping_tier`, `currency`, `value`, `items` |
| `add_payment_info` | change of the `payment_method` radio | `payment_type`, `currency`, `value`, `items` |

`items` come from `[data-lafka-checkout-item]` rows, else from the cart items
localized as `lafkaDlCheckout`. With neither, `items` is empty (GA4 accepts it).

**Custom interactions** (`incl/analytics/lafka-custom-events.php`): `phone_click`,
`email_click`, `get_directions_click`, `faq_open`, `filter_apply`,
`scroll_milestone`, `outbound_link`, `sticky_cart_open`.

**Store events** (`assets/js/lafka-store-events.js`):

| Event | Trigger | Params |
|---|---|---|
| `order_channel_click` | click on `[data-lafka-order-channel]` | `order_channel` (`direct`, `ubereats`, `skipthedishes`, `doordash`, `phone`), `order_source` |
| `select_fulfilment` | click on `[data-lafka-fulfilment]` | `fulfilment_method` (`delivery`, `pickup`), `fulfilment_source` |
| `select_addon` | change inside `.product-addon` | `product_id`, `addon_name`, `addon_value`, `price_delta` |
| `store_closed_view` | `.lafka-store-closed-card` enters the viewport (once) | `closed_context` |

`order_channel_click` is the core growth signal: put
`data-lafka-order-channel="direct"` on your own order button and
`ubereats` / `skipthedishes` / `doordash` on aggregator links to compare direct
and commission-channel intent.

### Markup contract between theme and tracking

The theme (or your own templates) must emit these hooks; the tracking JS binds to
them.

```
[data-lafka-order-channel="direct|ubereats|skipthedishes|doordash|phone"]
[data-lafka-order-source="home_hero|menu|cart|footer|..."]
[data-lafka-fulfilment="delivery|pickup"]  [data-lafka-fulfilment-source="..."]
.lafka-store-closed-card[data-lafka-closed-context="pdp|cart|checkout"]
.product-addon[data-product-id][data-addon-name]
a[data-lafka-item-id][data-lafka-item-name][data-lafka-item-category][data-lafka-item-price][data-lafka-list-name]
[data-lafka-menu-search-input] + [data-lafka-menu-results]
[data-lafka-checkout-item][data-lafka-item-id][data-lafka-item-name][data-lafka-item-category][data-lafka-item-price][data-lafka-item-quantity]
```

### Setup

1. Create a GTM container and paste its ID in the Customizer.
2. In GTM, add a GA4 Configuration tag (your `G-...` ID), Consent Mode v2, and GA4
   Event tags triggered on the event names above.
3. Enable Clarity and Cloudflare Web Analytics and paste their IDs.
