# Local SEO playbook — Your Restaurant

Goal: rank in the Google **Map Pack** + local organic for "[your cuisine] near me",
"[your cuisine] in [your city]", etc., and turn that visibility into **website**
orders (not third-party app orders). The code side (schema, performance, honest
review surfacing) is handled by the plugin/theme; this file is the **operator
checklist** for the parts only you can do — they move the needle more than any
code change.

Priority order is deliberate: do 1 → 2 → 3 first.

## 1. Google Business Profile (GBP) — the #1 local ranking factor
Manage at <https://business.google.com>.
- [ ] **Claim & verify** the listing for the exact business.
- [ ] **Primary category**: choose the single category that best matches your
  cuisine (e.g. `Pizza restaurant`, `Sushi restaurant`, `Burger restaurant`).
  Add a few relevant secondaries (e.g. `Restaurant`, `Takeout restaurant`,
  `Delivery restaurant`).
- [ ] **NAP**: name, address, phone EXACTLY as on the website (see §3).
- [ ] **Hours** — match the site (WooCommerce → Settings → Restaurant → Hours). Set holiday hours.
- [ ] **Attributes**: "Has online ordering" → point the **Order link to the website** (`/menu/`), not a third-party aggregator (UberEats, DoorDash, etc.). Set Delivery + Takeout = yes, Dine-in as applicable.
- [ ] **Photos**: 10+ high-quality — storefront, interior, hero dishes, team. Add a few weekly.
- [ ] **Products/Menu**: add your best-selling dishes with photos + prices.
- [ ] **Posts**: post weekly — promos (first-order discount, slow-day, combos), new items, events. Posts also surface your direct-order link.
- [ ] **Q&A**: seed 5–10 common questions (delivery area, gluten-free, parking) and answer them.
- [ ] **Reviews**: this is huge — see §4.

## 2. Fill the schema settings (powers the JSON-LD rich results)
Set these in **WooCommerce → Settings → Restaurant**, or in the Customizer under
**Lafka — Restaurant Information** (both write the same options). Empty fields are
omitted from schema (honest), so completing them strengthens your knowledge-panel
+ rich results:
- [ ] **Cuisine & Payment** → your cuisines (e.g. Pizza, Sushi, Burgers, Vegan) and payment methods (e.g. Visa, Mastercard, Amex, Debit, Cash, Apple Pay).
- [ ] **Schema & Geo** → business type (`Restaurant`), price range (`$$`), phone (E.164 + display), email, **geo lat/lng** (exact rooftop pin).
- [ ] **Social Profiles** → every profile URL (Facebook, Instagram, etc.). These become schema `sameAs` — a strong entity-disambiguation signal.
- [ ] **Hours** → per-day, used for `openingHoursSpecification`.

Verify after saving: open the homepage, View Source, search `application/ld+json`,
and confirm `servesCuisine`, `sameAs`, `paymentAccepted`, `geo` now appear. Or run
the URL through <https://search.google.com/test/rich-results>.

What the code already emits automatically: `Restaurant`/`LocalBusiness`/`FoodEstablishment`,
`WebSite` (+ sitelinks SearchAction), `areaServed` (your city), `BreadcrumbList`,
per-product `Product`/`Offer`, and `Menu`. Review stars (`aggregateRating`) appear
**only when you have real reviews** wired to the social-proof setting — never faked.

## 3. NAP consistency (Name · Address · Phone)
Pick ONE canonical format and use it **identically** everywhere — site, GBP,
Facebook, Instagram, Yelp, Apple Maps, directories. Inconsistent NAP is a top
cause of weak local ranking.
- Canonical source on the site: WooCommerce → Settings → General (address) + Restaurant tab (phone), also editable in Customizer → Lafka — Restaurant Information. The footer, schema, and announce bar all read from there.

## 4. Reviews (ranking + conversion)
- [ ] After each order, ask for a Google review — add a short link (GBP gives a
  "review link") on the receipt/thank-you page and on packaging inserts.
- [ ] Respond to every review (good and bad) within a day.
- [ ] Aim for a steady trickle (e.g. 4–8/month) rather than bursts.
- On-site product reviews now display **honestly** (real WooCommerce reviews only;
  no fabricated quotes/ratings). Encourage logged-in customers to leave them.

## 5. Citations & directories
Create/claim consistent listings: Apple Business Connect, Bing Places, Yelp,
TripAdvisor, your national/regional business directories, and any local
city/neighbourhood directories. Same NAP everywhere.

## 6. On-site (handled in code, verify it stays true)
- Fast Core Web Vitals (perf phase): LCP hero preload, deferred CSS/JS.
- Clean local title/meta per page; `/menu/` is the conversion hub.
- Internal links from home → categories → products.

## 7. Measure
- **Google Search Console** — verify the domain; watch local query impressions/clicks, fix coverage issues, submit the sitemap.
- **GA4 + Clarity** (already wired) — watch `order_channel_click[direct]`, `select_item`, `begin_checkout`, `purchase`; Clarity heatmaps on `/menu/` + PDP.
- Monthly: Map Pack rank for your top 5 queries, GBP calls/direction-requests/website-clicks.

---
The single highest-leverage move: **make GBP's order button go to the website**
and **run a website-only promo** (first-order discount / slow-day) advertised in
GBP Posts — that's how Map-Pack visibility converts into commission-free orders.
