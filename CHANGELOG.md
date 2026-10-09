# Changelog

All notable changes to lafka-plugin are documented here. The format follows
[Keep a Changelog](https://keepachangelog.com/); versions follow the repo's
semver (`npm version` is the single source of truth — see the Releases section
of CONTRIBUTING.md). Older history lives in git tags + GitHub Releases.

## [Unreleased]

### Fixed
- `wp lafka seed-demo` left the demo without a Contact page and a site icon, so the contact surfaces and the
  installable app could not be tried out of the box. It now also ensures `/contact/` (on the theme's contact
  template when the active theme has it) and, only when the site has no icon, a generated 512px site icon with
  WordPress's own icon sizes. Both are idempotent and removed by `--reset`.
- The block cart and checkout showed the loyalty redemption as its raw coupon code ("loyalty-xxxx"); it now reads
  "Loyalty points" like the classic checkout, through WooCommerce's `coupons` checkout filter (the `lafka-loyalty`
  cart extension now carries the coupon code). The points panel stays on the checkout only.
- The block checkout said "Ship", "Shipping address" and "Shipping options" where the rest of the site says
  Delivery. Its blocks now default to "Pickup or delivery" / "Delivery" / "Delivery address" / "Delivery options"
  through their own label attributes (render_block), unless the merchant set those labels in the editor. New filter:
  `lafka_blocks_checkout_labels`.
- The block checkout's own Ship / Pickup toggle and the site's Pickup / Delivery preference (header, drawer,
  `lafka_order_method`) disagreed: choosing Ship at checkout left the header on Pickup, and the header did not move
  the toggle. The customer's toggle now goes through `window.lafka.fulfilment.set()` (the preference's one writer),
  and a header or drawer choice sets the toggle the way WooCommerce's own toggle does; WooCommerce settling the
  toggle on page load writes nothing.
- Distance delivery on the block checkout looked up every half-typed street ("15", "1500 Bar", …) because
  WooCommerce saves the address on each pause in typing. While WooCommerce saves the address
  (`cart/update-customer`) an address that was never looked up is now left unpriced ("Checking the delivery price
  for your address…"), and the block checkout asks for the price once the customer leaves the address fields
  (cart-extensions update `lafka` → `quote_delivery`); a cached address is priced at once, and the classic checkout
  is unchanged. The method also only looks up a destination the quote guard calls complete (street + postcode, or
  what the country needs) even when the guard is switched off. New: cart extension field `delivery_quote_pending`.
- Lafka → Modules said the Cart & Checkout pages "already match this choice (or have been edited)" when the Checkout
  page showed the classic checkout inside a page builder while Blocks was chosen; it now says the page is edited and
  shows the other checkout, and what to do.
- A first-time customer whose card was declined lost the first-order discount on the retry: the failed order counted
  as a prior order, so the retry was priced without the discount and placed as a second order. Failed, cancelled and
  draft orders no longer count, nor does the order the checkout is retrying (`order_awaiting_payment` / the Store API
  draft), so the retry keeps the discount and WooCommerce pays the same order. Pending, on-hold, processing, completed
  and refunded orders of the same account still count; orders found by the same billing email or billing phone
  (compared in E.164) across accounts and guest orders count once they went through (processing, completed, refunded),
  so a new account does not get it again and nobody can block a stranger's discount by typing their details on an
  unpaid order. One order holds the discount: it is checked again, under a database lock, once the order exists and
  before payment (classic `woocommerce_checkout_order_created`, block `woocommerce_store_api_checkout_order_processed`)
  and before a Pay for order payment (`before_woocommerce_pay_form`, `woocommerce_before_pay_action`). If the person
  already has another settled order that counts, the discount comes off this order; otherwise this order holds it and
  the same account's other unpaid (failed or pending) orders lose it; another account's or a guest's order is never
  changed. Each change re-prices the order and adds an order note. Of two orders placed at the same moment (classic or
  block), the one checked first keeps it; an order that cannot get the lock does not keep it. New: `lafka_first_order_counted_statuses()`, `lafka_first_order_retry_ids()`,
  `lafka_first_order_paid_statuses()`, `lafka_first_order_identity_order_ids()`, `lafka_first_order_check()`,
  `lafka_first_order_share()` (the first-order part is recorded on the discount fee item, `_lafka_first_order`, from the
  computation that built the cart fee, via `woocommerce_checkout_create_order_fee_item` on both checkouts; a promo fee
  item without a recorded split counts as carrying the configured percentage),
  `lafka_first_order_on_paid()` (an order paid with the discount after another order of the same person was gets an
  order note and a dashboard warning with "mark reviewed"; the paid amount is never changed), `lafka_order_discount_fee()`.
- The deal builder showed a missing required add-on as `Pizza 1: &quot;Crust&quot; is a required field.`; the message
  is plain text now (`Pizza 1: "Crust" is a required field.`).
- A half-and-half topping showed its whole price in the product page's "Options total" when the topping was ticked
  before its half was chosen (Mushrooms left half: $2.00 shown, $1.00 charged): the totals read a price jQuery had
  cached. The cart always charged the right amount; the product page now shows it too.
- Buy 1, get 1 charged a cent more than it said: two $22.99 items showed "You save $11.50" next to a $34.49 line
  (the half-cent saving was rounded one way for the label and the other way in the line). The saving is now rounded
  once and the line is the original total less it ($34.48), on the cart, the checkout and the order. New:
  `Lafka_Promotions::savings()`.
- WooCommerce's Completed order email told a pickup customer "Your order from … is on its way!" / "Good things are
  heading your way!". For a pickup order the plugin now supplies "Your order from {site_title} is complete" /
  "Thank you for your order. Enjoy your meal!" through WooCommerce's email filters, only while the subject and
  heading are WooCommerce's defaults (a text typed in WooCommerce → Settings → Emails always wins); delivery orders
  keep WooCommerce's wording. New: `Lafka_Fulfilment_Emails`, filter `lafka_pickup_completed_email_text`.
- Pickup orders paid by cash said "Pay with cash upon delivery." on the order-received page and in the customer's
  emails (WooCommerce prints the gateway's instructions there, which the contextual payment labels did not cover).
  The instructions now follow the order's fulfilment ("Pay when you collect your order." / "Pay when your order
  arrives.", the same Customizer texts as the checkout description), on the classic page, the Order Confirmation
  block and every customer email, including those sent from the admin. New filter `lafka_cod_instructions`.
- Switching Loyalty on and writing to its points ledger in the same request failed (the table was only created on
  the next page load). A module's table is now created the moment its switch is turned on (`lafka_loyalty_enabled`,
  `lafka_ac_enabled`, `lafka_push_enabled`), and the ledger makes sure its table exists before its first write.
  New: `Lafka_Schema::ensure()`, `Lafka_Schema::watch_switches()`.
- Address suggestions answered 403 for a signed-in customer (the routes read the request as a stranger's, so the
  shopping session did not match the page token); the script now sends the REST nonce for a signed-in customer.

### Installable app
- New gated module (Lafka → Modules → Installable app, default on; WooCommerce → Settings → Restaurant → App).
  Serves a web app manifest (`/?lafka_manifest=1`: restaurant name, WordPress site icon at 192/512 and an optional
  maskable icon, colours from the active design through `lafka_pwa_colors`, standalone, start page the menu with
  `?source=pwa`) and an offline page (`/?lafka_offline=1`: name, tap-to-call phone, hours, open-now line while it is
  still true). Hands the theme's single service worker its settings through `lafka_service_worker_config` (cache
  version = plugin version, offline page, never-cached paths), registers it from `lafka-pwa.js` and tells it which
  files the menu uses. Pages served to a visitor with a session carry `X-Lafka-Session: 1` and are never kept.
  "Add to home screen" card (own switch, default off, visit threshold, 30-day snooze): `beforeinstallprompt` on
  Chrome, a one-time Share hint on iOS, never together with another prompt. Insights counts `?source=pwa` as source
  `pwa`, medium `app`. New: `Lafka_Pwa`, `lafka_pwa_json()`, filters `lafka_pwa_manifest`, `lafka_pwa_colors`,
  `lafka_pwa_maskable_icon_url`, `lafka_pwa_install_copy`, `lafka_pwa_snooze_days`, `lafka_pwa_service_worker_url`.

### Text messages
- New gated module (Lafka → Modules → Text messages, default off; WooCommerce → Settings → Restaurant → Text
  messages). Customers who tick an unticked opt-in at checkout (classic `woocommerce_checkout_fields`; block
  Additional Checkout Fields API) get SMS (Twilio) or WhatsApp Cloud API template messages when their order is
  received (optional), accepted, ready for pickup, out for delivery, completed (optional) or cancelled/rejected,
  each with its own on/off and text with `{name}`, `{order}`, `{restaurant}`, `{eta}`, `{track_url}`. Consent
  (yes, time, the wording shown, the E.164 number) is stored on the order; sending is an Action Scheduler job,
  idempotent per order and event, retried with a growing delay and logged to the `lafka-notify` source without the
  text and with only the last two digits of the number. Credentials are stored without autoload and the access
  keys are never printed (type REMOVE to clear one). "Save and send test message" button. Free "Message us on
  WhatsApp" wa.me link under the order tracker and via `do_action( 'lafka_whatsapp_link' )` /
  `[lafka_whatsapp]`. New: `Lafka_Notify`, `Lafka_Notify_Adapter` (+ Twilio, WhatsApp), `Lafka_Notify_Checkout`,
  `Lafka_Notify_Links`, `lafka_phone_to_e164()`, filters `lafka_notify_adapters`, `lafka_notify_message`,
  `lafka_notify_retry_delay`, `lafka_notify_whatsapp_api_version`, `lafka_whatsapp_link_hooks`,
  `lafka_whatsapp_link_phone`, `lafka_whatsapp_link_html`, `lafka_phone_to_e164`.

### Loyalty points
- New gated module (Lafka → Modules → Loyalty points, default off; WooCommerce → Settings → Restaurant →
  Loyalty). Points are earned on Completed orders (items only, once per order), spent at checkout on the classic
  and block checkout through a single-use, customer-bound WooCommerce coupon, and taken back in proportion on
  refunds and on cancellation. Ledger table `lafka_loyalty_ledger` in `Lafka_Schema` (HPOS-safe order ids,
  write-once refs, per-customer database lock so two tabs cannot spend the same points, negative balances never
  created, shortfalls recorded); balance cached in user meta and rebuilt by `wp lafka loyalty recalc`. My
  Account → Points, a line in the completed email, an admin adjustment on the user profile, optional expiry, hourly
  Action Scheduler upkeep, privacy export and erase. New: `Lafka_Loyalty`, `Lafka_Loyalty_Ledger`,
  `Lafka_Loyalty_Redeem`, `Lafka_Loyalty_Account`, Store API cart extension `lafka-loyalty`, filters
  `lafka_loyalty_earn_points` and `lafka_loyalty_reservation_ttl`.

### Deals
- Deal extras: pricing modes (percent off, amount off, cheapest item free, besides the fixed price),
  conditions (pickup / delivery only, an hours window, most uses per customer and in total counted on
  paid orders, allow or block coupons) and a cart nudge ("Add 1 more item to get ...") in the cart
  drawer, the classic cart and the block cart, linking to the deal with the cart's items chosen; adding
  the deal replaces them. Every condition shows its reason on the deal page, in the builder and on the
  cart / checkout (classic and Store API). New: `Lafka_Deals_Conditions`, `Lafka_Deals_Nudge`, filter
  `lafka_deal_nudge`, action `lafka_cart_drawer_upsell_start`, Store API cart extension `lafka_deals`.

### Design system
- Front-end CSS reads the theme's tokens with a neutral fallback: the branch popup and its Google
  suggestion list use `--lafka-z-popup` / `--lafka-z-maps-suggest` (the list stays above the popup), colours
  and sizes use `--lafka-color-*` and `--lafka-font-size-*`, the promotions banner uses `--lafka-z-drawer`, the
  maps define `--lafka-map-pin` / `--lafka-map-editor`, the order tracker uses `--lafka-color-surface-raised`.
- The consent banner's z-index, font and 44 px controls read the same tokens.
- The cart-drawer and PDP upsell buttons and tiles carry the theme's `lafka-btn` / `lafka-card` classes; the
  deal builder's focus ring uses `--lafka-shadow-focus`; the Peppery-neutral aliases (`--lafka-disabled-*`,
  `--lafka-font-size-sm`, ...) moved to the canonical token names.

### One source of truth: hours, money, fulfilment, the shared script
- **Opening hours have one home.** `Lafka_Order_Hours::status( $now )` returns `is_open`, `closes_at`,
  `next_open`, `source` (schedule, display, forced, holiday) and `gated`, from one engine
  (`Lafka_Order_Hours_Engine`) that handles overnight spans, windows that meet at midnight, holidays
  and the force override. The order gate, the next-opening lookup, schema `openingHoursSpecification`
  (one row per window), `/llms.txt`, the PDP "Ready in" line, Insights and the theme's badges all read
  it; the PDP hours helpers (`lafka_pdp_hours_to_minutes()`, `lafka_pdp_hours_window_is_open()`) and
  `get_schedule_display_hours_map()` are gone. The class is always loaded; `is_lafka_order_hours()` is
  replaced by `Lafka_Order_Hours::module_enabled()`. The per-day display-hours options apply only
  without a schedule; Site Health and `wp lafka hours check|status|sync` report disagreements.
  New: `GET /lafka/v1/open-status`, filter `lafka_order_hours_now`. A day missing from a saved
  schedule is closed (it used to count as open).
- **`lafka_price_plain()` moves to the plugin** (WooCommerce symbol, position, separators and decimals);
  the deal builder and promotions use it.
- **`lafka-core`**, one small script (`window.lafka`: `track`, `cookie`, `money`, `debounce`, `api`),
  registered once and loaded only with a script that depends on it. `lafkaCore` carries the REST root,
  nonce and the WooCommerce currency. The deal total, block-checkout free-delivery message, add-on
  totals debounce, tips, and the dataLayer search event use it.
- **Fulfilment**: `Lafka_Fulfilment::pickup_method_ids()` (the one list, filter
  `lafka_pickup_shipping_method_ids`) and `::current_mode()`; the page-context event reports the mode
  in force.
- **Free delivery / delivery minimum**: the deprecated filter `lafka_pdp_free_delivery_threshold` is
  applied once, inside `lafka_get_free_delivery_threshold()`; the zone's Free Shipping minimum is read
  from its stored settings (no method objects, so no recursion with the distance method).
  `lafka_delivery_minimum()` (and its filter) now governs what is enforced, not only what is shown.

### Settings, data and wiring
- **Plugin settings are plugin options, not theme_mods.** Abandoned-cart, web-push, review-request,
  product-page, checkout (delivery-quote guard, short pickup checkout, cash-on-delivery wording),
  upsell-pick and SEO (default locale, share image) settings keep their Customizer controls but are
  stored as options (`incl/settings/lafka-settings.php`: `lafka_settings_keys()`, the one reader
  `lafka_setting()`), so a theme or child-theme switch no longer loses them and uninstall finds them.
  A one-time migration (`lafka_settings_maybe_migrate()`, version `lafka_settings_version`) copies the
  old theme_mods of the active theme and its parent across and removes them; an option that already
  exists wins. The module registry reads the options. The dual option-then-theme_mod reads of the
  promotion knobs are gone. A deprecated `theme_mod_<key>` filter keeps child code that still calls
  `get_theme_mod()` on a moved setting working until the next major version.
- **VAPID keys**: created on the server the first time push is on, stored as options (the private key
  is never autoloaded and is no longer in the Customizer; wp-config constants still take precedence).
  WooCommerce → Push notifications shows the public key and a "Create new keys" button (existing
  subscribers must subscribe again). `GET /push/vapid-key` now also honours the constants.
- **Consent**: one `lafka_has_consent( 'analytics'|'ads' )` (WP Consent API, else the banner cookie,
  else the configured default) for Insights and the server-side conversions, and one cookie-name
  source (`lafka_consent_cookie_name()`, passed to the mirror script as `window.lafkaConsentCookies`).
  Insights in `consent_required` mode now measures with a WP Consent API plugin or default-granted
  consent too.
- **One schema registry** (`Lafka_Schema`, `incl/tools/class-lafka-schema.php`) declares the five custom
  tables (SQL, version, version option, module gate); one upgrader serves activation, the self-heal
  and uninstall. Abandoned carts gain indexes on `order_id`, `created_at` and
  `(recovery_sent_at, order_id, last_seen_at)` (table version 1.1.0); push audience queries use
  `user_id IN (...)` instead of `FIND_IN_SET`.
- `lafka_tracking` (holds the GA4 api secret and the Meta token) is no longer autoloaded.
- **Uninstall** also removes the moved settings, the tip options, the Lafka widget instances, every
  Lafka wp-cron event (review email, push batch, IndexNow and Insights fallbacks) and every Action
  Scheduler action of the `lafka` groups; the dead food-menu entries are gone.
- The contact-page FAQ is business data: WooCommerce → Settings → Restaurant → Contact FAQ
  (`lafka_contact_faq_items()`, read by the theme, the FAQPage schema and /llms.txt). The deals category
  is `lafka_get_deals_category_id()` (WooCommerce → Settings → Restaurant → Promotions, else a category
  named deals, combos or specials). The free-delivery threshold has one setting (the Customizer
  duplicate migrates into it). Default locale and default share image are in Restaurant → Search & AI;
  the per-page share image field is in the SEO box.
- `lafka_get_restaurant_info()` gains `phone_tel` (digits and "+", for `tel:` links).
- Real reviews only: `lafka_get_store_reviews()` / `lafka_get_store_review_summary()` read approved
  WooCommerce product reviews for the theme's reviews band.
- Kitchen display customer emails go out when an order ENTERS accepted, preparing, ready or rejected,
  from any status, once per status per order (not per transition pair).
- The config bundle has a `settings` section (non-secret plugin settings); old bundles that carried
  them in `theme_mods` import into the options.
- Site Health lists default-on modules (add-ons, Deals, Order tracking) as Enabled.
- Hooks (since 10.4.0): `lafka_home_hero_image_id`, `lafka_deals_category_slugs`,
  `lafka_deals_category_id`.

### Removed
- The product promo tooltips, the custom product pop-up and the category-description position
  (read from `lafka` keys nothing could set; unused on the live store), with their `wpml-config.xml`
  entries; the win-back email field at checkout (it saved addresses nothing used) and its setting;
  the per-post `lafka_show_share` read; `lafka_input_get_text_list()`; the legacy
  `lafka['enable_security_headers']` read (migrated to `lafka_security_options`); the stale seeded
  `top_bar_message_phone`; `lafka_server_consent()` (use `lafka_has_consent()`); the homepage-hero
  Customizer field of the legacy Restaurant Info panel (the theme's hero image is read through the
  `lafka_home_hero_image_id` filter).
- The appearance meta boxes (page layout, header style, page subtitle, top menu, sidebars, product
  video, gallery type) moved to the theme; the meta keys are unchanged.

### Changed
- **Built on WooCommerce, not beside it** (extensions work out of the box):
  - The cart drawer fires WooCommerce's mini-cart actions
    (`woocommerce_before_mini_cart`, `woocommerce_before_mini_cart_contents`,
    `woocommerce_mini_cart_contents`, the three `woocommerce_widget_shopping_cart_*_buttons`
    actions, `woocommerce_after_mini_cart`); its rows use the cart-item filters core's
    mini-cart uses (`woocommerce_widget_cart_item_visible`, `_cart_item_thumbnail`,
    `_cart_item_subtotal`; the struck-through deal price is a priority-5 callback on the
    subtotal filter); one `lafka_cart_drawer_render_items()` renders the list for the page
    and the AJAX fragment.
  - Free delivery over the threshold no longer zeroes other plugins' delivery rates. The
    threshold follows the zone's WooCommerce Free Shipping minimum amount when the zone has
    one; `lafka_free_delivery_method_ids` opts methods in (none by default). Sites still on
    Distance Rate Shipping: opt `distance_rate` in, or move to Delivery by distance.
  - The Cart and Checkout pages are never edited automatically: switching between blocks and
    the classic shortcodes is a button on Lafka → Modules, once, with undo (the old
    `admin_init` rewrite and its done-flag are gone).
  - A closed store no longer makes every product non-purchasable (`woocommerce_is_purchasable`)
    or removes WooCommerce's checkout buttons: a closed card is added next to them and the
    existing gates (add-to-cart validation, classic checkout, Store API) refuse. Lists and
    the drawer upsell read `lafka_add_to_cart_blocked()`/`Lafka_Order_Hours::is_add_to_cart_blocked()`.
  - The shop-archive subcategory filter removal moved to the theme (archive only); the cart
    page no longer removes `woocommerce_output_all_notices` from the login form.
  - Declares `product_block_editor` compatibility as false (Deals and the add-on tabs are
    classic-editor only).

### Added
- **Checkout address suggestions through WooCommerce's own address-autocomplete system.** A "Lafka address
  search" provider (`incl/address-autocomplete/`) lists itself in WooCommerce → Settings → General →
  Address autocomplete and works on the classic and the block checkout (WooCommerce draws the list, with
  its keyboard and screen-reader support). It searches through server routes (`lafka/v1/address/suggest`
  and `/place`: same-site, rate limited, cached): Google Places (New) when the one Maps key is set, else
  Photon (OpenStreetMap data; Nominatim forbids search-as-you-type), biased to the store point and cut
  to the selling countries; 4 characters and a 400 ms pause at least; at most 5 suggestions. A chosen
  suggestion fills address, city, province/state, postcode and country in WooCommerce's formats and hands
  its point to the delivery price like the checkout pin (classic: the pin field; block: a Store API update,
  namespace `lafka-address-autocomplete`), so the distance resolver's pin tolerance still applies. Module
  switch Lafka → Modules → Address suggestions (on by default; customers see nothing until the
  WooCommerce setting is on). Filters `lafka_address_autocomplete_countries`, `lafka_address_photon_endpoint`,
  `lafka_address_photon_params`, `lafka_address_google_endpoint`, `lafka_address_autocomplete_bias_radius`,
  `lafka_address_autocomplete_debounce`, `lafka_address_number_after_street`. Service disclosure added to readme.txt.
  The routes need a page token printed only on the checkout and tied to the shopper's session
  (cart not empty), count Google searches as billing sessions against a daily budget
  (`lafka_address_google_daily_sessions`, default 500; Photon takes over when it is used up), cap
  searches per session and Google searches per minute, and keep the keyless path rate limited. A search
  answered from the cache still lets the customer choose the place; a session is counted against the daily
  budget at its first paid Google call (refused once the budget is used up), and the place lookup shares the
  per-minute Google cap.
- Block checkout: when delivery was chosen and no delivery rate is on offer, the `lafka` cart
  extension carries `delivery_unavailable_message` (the distance method's reason, else the
  plain "we can't deliver") that the block cart/checkout shows under the shipping options,
  and placing the order is refused through the Store API
  (`Lafka_Fulfilment::validate_store_api_checkout`), like the classic checkout. One shared
  `lafka_shipping_has_delivery_rate()`.
- Hooks: `lafka_free_delivery_method_ids`. Cart/Checkout page switch handlers
  `lafka_checkout_pages_apply` / `lafka_checkout_pages_undo`.

### Removed
- The kitchen display's customer progress bar and its `lafka_kds_customer_status` AJAX
  action: Order tracking replaces them (the Customer Poll Interval setting still sets the poll).

### Added
- **Delivery by distance** (`lafka_distance`): a native WooCommerce shipping method for
  zones, replacing the paid Distance Rate Shipping extension. Distance bands (up to N km
  or miles gives a fee, plus an optional amount per unit), a maximum distance, straight
  line x road factor (keyless) or driving distance (Google Routes with the one Maps key,
  or an OSRM server through `lafka_distance_osrm_endpoint`), tax status, and free over
  the store's free-delivery threshold. It measures from the chosen branch or the store
  point to the checkout pin, else to a cached server-side geocode of the address; a
  geocode or route failure, a street-less match or an address beyond the maximum offers
  no rate (with a plain explanation and a `shipping` log line), never a guessed price.
  The distance shows under the rate ("Delivery · 4.2 km") and on the order, emails and
  the kitchen display. Classic checkout: a new pin reprices the order.
- `wp lafka shipping migrate-drs [--apply]`: prints every Distance Rate Shipping
  instance (zone, rules, settings; never the API key), the equivalent bands and what
  cannot be mapped; `--apply` adds a disabled copy to the same zone to compare.
- **Order tracking** module (Lafka → Modules, on by default): a status stepper on the
  order confirmation (classic, and the order-confirmation block of block themes) and
  in My Account → view order, from the kitchen statuses when the kitchen display is on
  or the WooCommerce status otherwise; ETA from the kitchen estimate or the chosen
  timeslot; restaurant phone and pickup or delivery address; live updates through a
  read-only, order-key-authorised, rate-limited endpoint
  (`/wp-json/lafka/v1/order-status/{id}`); a Track your order button in WooCommerce's
  processing, on-hold and completed emails; "Order this again" and "Track" in My
  Account → Orders and a guest reorder on the confirmation. Order-again no longer
  re-adds Deal lines: it leaves them out and links to the deal to choose again.
- **Deals** (`lafka_deal` product type): "any 2 pizzas for $20" where the customer
  picks each item and its options. Slots by category or hand-picked items, locked
  options (e.g. size), optional add-on items, premiums that pay the difference;
  server-priced builder on the product page; each item a quantity-locked cart line,
  the deal price split across them; BOGO and the combo discount skip deal lines.
  Lafka → Modules → Deals (on by default).
- **Half and half**: add-on groups can offer Left / Whole / Right per option; a half
  costs half (`lafka_addon_half_price_factor`).
- **Tips** at checkout (classic and block): suggested percentages, a custom amount,
  every order or delivery only; a non-taxable fee line.
- **Analytics**: Google Ads purchase conversions with enhanced-conversion data; Meta
  Pixel standard events; server-side Meta Conversions API and GA4 Measurement
  Protocol (Action Scheduler, consent-gated, deduplicated); the full GA4 funnel on
  the block cart and checkout; `view_item_list` on the menu page; purchase items at
  the price paid.
- **TikTok Pixel** (direct mode) with standard events and consent hold/grant.
- **WP Consent API**: with a compatible consent plugin active, Lafka's banner stands
  down and all tags (and server-side conversions) follow its statistics / marketing
  decisions; Lafka declares compatibility.
- **Maps without an API key**: every map (store location, delivery-zone editor, branch
  addresses, location popup, checkout pin, `[lafka_shipping_areas]`) works with
  OpenStreetMap (Leaflet 1.9.4, bundled) when no Google Maps key is set; a key switches
  them to Google. Addresses are looked up server-side through Nominatim, cached and
  rate limited (`lafka/v1/geocode`, `lafka/v1/admin/geocode`;
  `lafka_geocoder_endpoint` for your own geocoder). The checkout pin map gains "Use my
  location" and a draggable pin.
- **Search**: `OrderAction` on the restaurant schema; ready-to-paste order links for
  Google Business Profile, Apple Business Connect and Bing Places under Search & AI.
- Deals can run on chosen weekdays and between dates ("Two for Tuesday"); outside
  them the deal page says when it runs and a deal left in a cart is removed.
- Demo seed: a delivery zone and block-checkout pickup, a Deal, half-and-half
  toppings and tips.

### Changed
- **Maps, one source.** Every map opens on `lafka_get_map_default_view()`: the store
  point, else the WooCommerce province / state, else the country, else Canada (never
  Brussels, Sydney or 0,0). The store point is the business geo: the Shipping Settings
  map now saves it there (a pre-10.4 pin is still read until the page is saved), and
  the "Set Store Location" mode is gone. The Google Maps key lives only in
  `lafka[google_maps_api_key]`: a copy in the Shipping Settings option is moved there
  once and the second and secondary key fields and their sync hooks are removed (an
  emptied field now removes the key). The Delivery areas module counts as configured
  once the store has a location.
- The checkout pin requirement applies wherever the classic pin map can show, which is
  now always on the classic checkout (no key needed).
- The location popup's delivery and pickup icons come from the theme's icon set (with
  a built-in fallback); the Flaticon glyphs had been blank since the font was removed.
- The four shipping-areas scripts are readable, documented sources again; the
  client-side rate filtering for the retired Lafka shipping method, the unused debug
  output and their styles are removed.
- Demo seed: the demo restaurant, its zone, branch and WooCommerce store address and
  base region are in Halifax, Nova Scotia (CAD), matching its coordinates.
- Tracking settings moved from theme_mods to one plugin option, `lafka_tracking`
  (migrated once), so they survive a theme switch. Clarity and the Meta Pixel load from
  the effective consent (banner decision, else your defaults); direct GA4 receives
  every event, not only ecommerce ones.
- With an SEO plugin active, Lafka keeps emitting its restaurant, menu and FAQ
  schema instead of dropping everything.
- **No lint rule is switched off any more.** ESLint now enforces `no-var`, `prefer-const`,
  `no-prototype-builtins`, `no-redeclare`, `no-unused-vars`, `no-empty`, `no-useless-escape`,
  `no-useless-assignment` and `no-shadow-restricted-names` everywhere, including the
  shipping-areas and branch scripts that used to be exempt; the only ignored files are
  the vendored flatpickr and jquery.schedule libraries and minified output. Stylelint
  has no `null` overrides left: named colours became hex, icon-font declarations gained a
  generic fallback family, duplicate declarations were removed, and the few
  `no-descending-specificity` hits were resolved by moving rules only where the cascade
  result is identical. `selector-class-pattern` / `selector-id-pattern` are configured to
  the real naming convention (lowercase kebab/snake, BEM, third-party prefixes) instead of
  being disabled; see CONTRIBUTING.md. Rebuilt the `.min.js` files from the updated sources.
- **Strict lint, no suppressions.** PHPCS now runs plain `WordPress-Extra` with no
  excluded sniffs and warnings fail the run. Every inline lint-suppression comment is gone;
  ESLint is at zero warnings. Class files are `class-lafka-*.php`, the widgets are
  `Lafka_*_Widget` classes registered from `widgets/lafka-widget-registration.php`
  (`LafkaMobileGroupedWalker` stays as a `class_alias`), `Lafka_WCVS()` is now
  `lafka_wcvs()` (PHP function names are case-insensitive, so old calls still work).
- Plugin-owned hooks without a `lafka_` prefix were renamed (`get_product_addons`,
  `product_addons_field_prefix`, the `wc_product_addon` start/end/options actions,
  the `lafka-product-addons` and `lafka-wcs` hooks, ...). The old names still fire
  through `apply_filters_deprecated()` / `do_action_deprecated()`.
- Security fixes: nonce and capability checks on the product add-on, nutrition,
  serves, swatch, WCML price and contact-form save paths; classic-checkout hooks
  read the posted time slot, delivery pin and gateway only after the
  `woocommerce-process_checkout` nonce verifies; read-only request parameters go
  through `filter_input()`; SQL identifiers use `%i`; pre-built HTML is escaped or
  passed through `wp_kses()` allowlists; the web-push sender uses the WordPress
  HTTP API and sodium base64.
- New floors: WordPress 7.0, PHP 8.3, WooCommerce 11.0 (tested up to WordPress 7.1
  / WooCommerce 11.2), declared in the plugin header, `readme.txt`, `composer.json`
  and `.phpcs.xml.dist` (`testVersion 8.3-`, `minimum_wp_version 7.0`).
  The plugin header is the single source for the floors; the README has a
  "Requirements & compatibility" section.
- Compat code for older WordPress / WooCommerce removed: `function_exists()` /
  `method_exists()` guards and fallbacks for `wp_date()`, `wp_timezone()`,
  `wp_timezone_string()`, `wp_parse_url()`, `wp_print_inline_script_tag()`,
  `wp_get_environment_type()`, `wp_doing_ajax()`, `wp_generate_uuid4()`,
  `wp_using_ext_object_cache()`, `get_site_icon_url()`, `get_term_meta()`,
  `wp_get_attachment_image_url()`, `register_rest_route()`, `wp_strip_all_tags()`,
  `WP_Sitemaps_Renderer`, and the WooCommerce `LoggingUtil` / `WC_AJAX` /
  `WC_Install` methods. HPOS and legacy order storage are both still supported.
- **Documentation consolidated**: `COMPATIBILITY.md` moved into the README
  ("Requirements & compatibility"), `CREDITS.md` into `readme.txt` ("Third-party
  libraries"), the flatpickr locale notes into CONTRIBUTING.md, and
  `docs/DIAGNOSTICS.md`, `LOCAL_SEO.md`, `PERFORMANCE.md` and `TRACKING.md` into one
  `docs/OPERATOR_GUIDE.md`.

### Removed
- The Magnific Popup dependency (loaded from the theme's directory, so the location
  popup failed under any other theme): the popup is a native `<dialog>`. Also the
  unused jQuery UI dialog stylesheet it enqueued.
- **All automated tests and test tooling**: `tests/` (PHPUnit + node:test suites),
  `phpunit.xml.dist`, `composer test` / `npm test`, the `phpunit/phpunit`,
  `brain/monkey` and `linkedom` dev dependencies, and the test-only seams
  (`LAFKA_TESTING`, no-WP fallbacks). A fresh suite will be added later.
- **wp-env**: `.wp-env.json` is retired in favour of the `../local-env` Docker stack.
- The pre-push hook no longer runs or skips tests; it fails with the install command
  when `node_modules` / `vendor` is missing, and no longer advertises `--no-verify`.
- **Legacy third-party integrations** (breaking for sites that still ran them):
  - WPBakery / Visual Composer: all 24 `vc_map` element mappings
    (`shortcodes/shortcodes_to_vc_mapping.php`), the shipping-areas VC map and its
    autocomplete callbacks, the VC icon-picker lists and category-search helpers,
    `lafka-vc-edit-form.js`, the VC logo, the `WPBakeryShortCode` content-slider
    class, the `css` design-options attribute on `[lafka_typed]`, `[lafka_foodmenu]`,
    `[lafka_latest_posts]`, `[lafka_banner]` and `[lafka_shipping_areas]`, the
    VC-only icon types (openiconic, typicons, linecons, entypo, monosocial,
    material) and the inert `wpb_content_element` / `wpb_wrapper` markup classes.
    `[lafka_content_slider]` (a WPBakery-only element) is gone; its stray tags are
    stripped from stored content. `lafka_perf_dequeue_unused_vc` and the
    `lafka_keep_vc_css` / `lafka_vc_native_template_page` filters are removed.
  - Slider Revolution: `LAFKA_PLUGIN_IS_REVOLUTION`, the "Revolution Slider" meta box
    (`lafka_rev_slider`, `lafka_rev_slider_before_header`), the revslider asset
    pruning and the WPML config keys.
  - bbPress: `LAFKA_PLUGIN_IS_BBPRESS` / `LAFKA_IS_BBPRESS`, the
    `bbp_setup_current_user` glue, and `forum` / `topic` meta boxes.
  - The Events Calendar (`tribe_events`) meta boxes.
  - WC Marketplace (WCMp): `[lafka_wcmp_vendorslist]`, `LafkaShortcodeVendorList`,
    `LAFKA_PLUGIN_IS_WC_MARKETPLACE`.
  - The WordPress Importer ↔ WC attributes bridge (demo content is `wp lafka seed-demo`).
  - `incl/compat/lafka-wpbakery-fallback.php` stays: it is content safety (orphaned
    `[vc_*]` / `[rev_slider]` tags in old pages), not an integration.

- **The "Restaurant Menu" post type** (`lafka-foodmenu`) and its `lafka_foodmenu_category`
  taxonomy: the registration, the "Menu Entry Fields" and "Menu Entry Options" meta boxes,
  menu-category drag ordering (`incl/foodmenu-category-ordering.php`, its script and
  stylesheet — its `terms_clauses` filter added a second column to `fields=ids` term
  queries, the source of thousands of PHP warnings in production logs), the "Latest Menu
  Entries" widget and the WPML keys. WooCommerce products are the menu. Old
  `/restaurant-menu/…` and `/restaurant-menu-category/…` URLs that would 404 now 301 to the
  menu (`lafka_get_menu_url()`); the prefixes are filterable
  (`lafka_legacy_foodmenu_path_prefixes`), replacing the `lafka_legacy_foodmenu_redirect` /
  `lafka_legacy_foodmenu_redirect_target` filters. `lafka_seo_legacy_post_types()` now
  defaults to an empty list. `LAFKA_SEO_REWRITE_VERSION` is 2, so the old rewrite rules are
  flushed once after the update. Uninstall still deletes any leftover entries and terms.

- **The page-builder-era shortcode library** (`shortcodes/shortcodes.php`, ~2,000 lines, and
  `shortcodes/partials/contact-form.php`): `[lafka_counter]`, `[lafka_typed]`,
  `[lafkablogposts]`, `[lafka_foodmenu]`, `[lafka_latest_posts]`, `[lafka_banner]`,
  `[lafka_cloudzoom_gallery]`, `[lafka_icon_teaser]`, `[lafka_icon_box]`,
  `[lafka_countdown]`, `[lafka_map]`, `[lafka_pricing_table]`, `[lafka_contact_form]` and the
  nine `[lafka_woo_*]` carousels, with the Ajax contact-form handler
  (`lafka_submit_contact`, `lafka_contact_form_generate_response()`), the map script
  (`assets/js/lafka-plugin-map-config.js`), their images, `LAFKA_PLUGIN_IMAGES_PATH` and the
  WPML shortcode keys. The file is now a stub that keeps every retired tag registered and
  renders only the content it encloses, so old pages never print a raw tag (on Peppery the
  tags sat only on pages whose content is not rendered, plus one `[lafka_icon_box]` on My
  account). `[lafka_nap]` and `[lafka_shipping_areas]` are unchanged.

- The `typed`, `nice-select` and `isotope` fallback script handles that pointed into the
  theme folder (`lafka_register_theme_script_fallbacks()`); the theme no longer ships those
  libraries.

- **The bundled Font Awesome copy** (`assets/vendor/font-awesome/`), its fallback handles
  and `lafka_perf_dequeue_unused_font_awesome()` with its content and
  `_lafka-menu-item-icon` meta scans (filters `lafka_keep_font_awesome_css` and
  `lafka_header_renders_fa_icons`). The branch modal's "use my location" icon is an inline
  SVG, and the shipping-areas pseudo-element icons (change branch, delivery time, clear
  date, estimated time; admin order type and delivery date) are CSS masks with their own
  SVG data URIs, so no stylesheet needs an icon font. `fa-` is no longer an allowed
  class prefix in `.stylelintrc.json`.

- **Meta boxes whose values nothing reads**: "Video Background" (it only added an unstyled
  body class), "Featured Image 2–6" (shown only by a blog-list branch single posts never
  reach), and from "Page Layout Options" the title background image and title alignment,
  from "Page Structure Options" Show Title, Show Breadcrumb and Featured Image in Single
  Post View (read only by the removed food-menu templates). The WPML keys go with them.
  Kept because they are read: layout, footer and header style, page subtitle, top menu,
  sidebars, product video URL, product gallery type
  (`lafka_single_product_gallery_type`). Stored values are left in the database.
- Dead `combo` product-type branches in `addons.js` (no such type is registered) and the
  `add_to_cart_text` / `woocommerce_add_to_cart_url` filters, which WooCommerce 11 never
  applies (`woocommerce_product_add_to_cart_text` / `_url` stay).

### Fixed
- **A delivery order no longer silently becomes a pickup.** On the classic
  checkout, a customer who chose Delivery and typed an address that brought no
  delivery rate (out of range, or the distance lookup failed) got the only rate
  left, Pickup, and the order went through as a pickup. The checkout now refuses
  it and says to check the address or choose Pickup ("Collect it myself instead"
  on the form). Choosing Delivery on the checkout itself opens the address.
- Address lookups (`lafka/v1/geocode`) were rate-limited by values the visitor
  controls (session cookie, browser string, forwarded-for IP), so the per-visitor
  limit could be bypassed and the site-wide cap used up; they are now limited per
  account, else per connection IP.
- The cart drawer could suggest a Deal as a one-tap extra (then refuse the add).
- The location popup's Start Order read accent-on-accent; it is now a button.
- The order-hours schedule editor used jQuery APIs removed in jQuery 4
  (`$.isArray`, `$.isFunction`); patched in the vendored jquery.schedule.
- A branch whose map location was cleared kept its old location.
- "Pick the delivery map only when the address cannot be found" with "Mandatory"
  refused every order whose address was found.
- The location popup measured branch delivery radii in km even when the branch is set
  to miles.
- Abandoned carts were never captured on the block checkout; they are now captured
  as the email is typed and marked recovered when the order is placed.
- The WP Consent API alone (no consent manager) failed open: every tag and
  server-side send ran as consented. It is trusted only once a consent type is
  registered.
- `/llms.txt` and the menu documents were served on sites that discourage search
  engines; the home page now links to `/llms.txt`.
- `lafka_get_option()` read keys that moved to the Customizer from the stale
  `lafka` option whenever the plugin was active (`lafka_pre_get_option` filter).
- The branch info box kept the branch picker's address after the customer changed
  it on the block checkout.
- Default-on modules (Product add-ons, Deals) now load on a fresh install; before,
  add-ons never loaded until the Modules page was saved.
- A mandatory delivery pin refused every block-checkout delivery order (and every
  classic one without a map); a pin is required only where the checkout can show one.
- The block checkout announced the Promotions delivery minimum even with Promotions
  off; BOGO at 0% still showed its labels and banner; BOGO prices were restored from
  the session after a price change.
- Branch selection: posted fields were garbled by sanitisation, branch geocodes never
  saved, the modal printed as an inert block without its script, and a jQuery 3
  `.load()` call threw.
- `LAFKA_PLUGIN_VERSION` was never defined (config-bundle exports had no version).
- Translations and the cron self-heal ran before `init` (WordPress 6.7+ notice).
- A Customizer save warned and did not refresh the cached home hero.
- Backslashes were stripped from category FAQs, branch hours, the seeded polygon and
  the same-request last-order cookie.
- Promo tooltips never matched their default zone: the theme default
  `promo_tooltip_N_position` was `above_price` while the plugin compared against
  `above-price`, so an unsaved tooltip never rendered on the product page. The zone
  slugs now live in one place (`lafka_promo_tooltip_zones()`), and
  `lafka_promo_tooltip_position()` accepts the underscore form and falls back to
  `above-price`. The hook priorities (9 / 11 / 39) come from the same map.
- The foodmenu metabox no longer reads the retired `foodmenu_currency` option; it
  always shows the WooCommerce currency (WooCommerce is a required plugin).
- `[lafka_counter]` no longer fatals on `add_icon="true"` when WPBakery is not
  installed (it called a WPBakery function); icon fonts are enqueued by
  `lafka_icon_element_fonts_enqueue()`, now defined in `shortcodes/shortcodes.php`.

## [10.3.0] — 2026-09-25

Live-site QA sharpening (2026-09-25): 153 findings from four anonymous QA
passes on the production store; every code finding fixed here or in the theme.

### Fixed
- **Checkout mode follows the Checkout page**: classic (`[woocommerce_checkout]`) vs
  checkout block is read from the page itself, not only the stored option, so the
  classic form never has street/city/postcode relaxed for delivery or card orders,
  and the short pickup form loads. Site Health warns when option and page disagree.
- **Delivery orders keep the typed street** when a map plugin (address-field-
  autocomplete) blanks `address_1` on the saved order.
- **Pickup → Delivery**: a "Delivery" choice stays selectable while its price waits
  for an address; the header/drawer/cart preference drives the shipping rate; the
  COD label follows the rate WooCommerce settled on; "Want delivery?" is hidden when
  the cart is under the delivery minimum.
- **"Closed — order ahead" all Friday/Saturday** on product pages (midnight close
  compared as a string); ready-time now follows the order gate and the store clock.
- **Cart drawer**: line prices from the calculated line totals (BOGO/coupon with the
  original struck through); rapid stepper taps queued; focus kept after Remove;
  upsell suggests varied little extras (no combos, nothing already in the cart).
- **BOGO banner**: in the page flow (never over the header), closes on ×, no layout
  shift (pre-paint dismissal check), plain wording, 44px close, links to the menu.
- Delivery-minimum note is one readable paragraph; phone numbers under 7 digits are
  refused with an inline error; card security code gets `cc-csc`; speculative
  loading never prefetches cart/checkout/remove links; shipping row heading reads
  "Pickup or delivery".
- JSON-LD WebSite name/description no longer show `&amp;`; category-page Menu nodes
  get their own `@id`; add-on multi-choices read as one line.

### Added
- **WebP for new uploads**: new JPEG/PNG uploads are saved as WebP (every
  generated size) when the server's image editor can write WebP. Toggle on
  Lafka → Modules → "WebP images for new uploads" (option `lafka_webp_uploads`)
  or the `lafka_webp_uploads_enabled` filter. Existing images:
  `wp lafka images convert-webp` / `wp media regenerate` (docs/PERFORMANCE.md).
- **Add-on group disclosure**: a theme that declares
  `add_theme_support( 'lafka-addon-group-toggle' )` gets add-on group headings
  as `<h3><button aria-expanded aria-controls>` around a `.lafka-addon-body`
  region; addons.js keeps `aria-expanded` and the group's `data-collapsed` in
  sync. Other themes keep the plain heading. Filter `lafka_addon_group_toggle`.
- Search & AI: "Menu page description" and "Page description fallback"
  templates (`lafka_seo_desc_menu`, `lafka_seo_desc_page`) and the `{short}`
  token (product short description).

### Changed
- **Contact Form 7** CSS/JS load only on content that embeds a form (a form
  rendered from a widget or template still loads them late). Filter
  `lafka_cf7_assets_needed`.
- **Payment-gateway assets** (SkyVerge framework, Authorize.Net CIM) load only on
  cart, checkout, account, order-pay and add-payment-method; express-pay handles
  are never touched. Filters `lafka_gateway_asset_patterns`,
  `lafka_gateway_assets_needed`.
- **Descriptions**: a product short description under 70 characters is wrapped
  by the product template (name — short description, from $X, place); inner
  pages get the menu template, their own text or the page template instead of
  the shared site pitch (the front page keeps it).
- **Titles**: home adds the place and "Order Online" even without cuisines;
  categories read "{term} Menu[ in {city}] – {name}", products
  "{product} – {name}"; a title over 65 characters drops its optional parts
  (`lafka_seo_title_max_length`). Saved operator templates still win.
- **Social previews**: og:image falls back to the menu-category thumbnail, the
  default share image, then the homepage hero before the site icon;
  `twitter:card` is `summary_large_image` only for a landscape image (never the
  square logo); pages are `og:type` website (article only for posts);
  og:title/twitter:title carry the site name. Filter `lafka_twitter_card`.
- **robots.txt**: Lafka's rules join the `User-agent: *` group (they used to land
  after the Sitemap line, outside any group) as `/*?*orderby=`,
  `/*?*add-to-cart=`, `/*?*filter_` …; the Sitemap line closes the file.
  `/my-account/` is noindexed instead of blocked.
- Legacy `lafka-foodmenu` singles, archive and categories 301 to the menu page
  when WooCommerce is the menu (`lafka_legacy_foodmenu_redirect`,
  `lafka_legacy_foodmenu_redirect_target`).
- WordPress' 404 "guess the permalink" redirect is off
  (`lafka_disable_404_guess_redirect`).
- Anonymous `?author=N` probes answer 404 and anonymous `/wp/v2/users` requests
  401; logged-in users (block editor) are unaffected
  (`lafka_restrict_user_enumeration`, `lafka_author_enumeration_response`).

### Fixed
- Menu-category canonicals are the clean term link (+ `/page/N/`) with every
  query arg dropped (utm_*, fbclid, sort/filter).
- `menu.md` and `llms-full.txt` are served `X-Robots-Tag: noindex` like
  `menu.json` (`lafka_llms_noindex_types`).
- A category page's Menu JSON-LD has its own `@id` (term URL + `#menu`) instead
  of re-declaring `/menu/#menu`.

## [10.2.1] — 2026-09-25

### Fixed
- Promotions: the BOGO banner renders in the page flow at the top of `<body>` (`wp_body_open`) instead of a fixed overlay, so it no longer covers the theme header or sticky bars; the fixed overlay remains only as the fallback for themes without `wp_body_open`. The banner is a labelled `region` (not a second `banner` landmark).

## [10.2.0] — 2026-09-25

Phases GX0 ("stop losing orders"), GX1 (diagnostics) and GX2 (Insights).
All new checkout behaviour works on the classic and the block (Store API)
checkout; settings live in Customizer → **Lafka — Checkout** unless noted.

### Added
- **Menu items that skip pickup and delivery**: a WooCommerce order made only
  of Virtual items needs no shipping, so it never asks pickup or delivery,
  keeps the billing address required and charges no delivery fee. While the
  store offers pickup or delivery: a Site Health check (count, up to ten
  linked titles, guidance), a one-line warning on a flagged product's edit
  screen with a per-product "This item is meant to be virtual" link (post
  meta `_lafka_virtual_ok`), and `wp lafka products unvirtual [--ids=…]`
  (dry run unless `--yes`). Downloadable items are exempt; filters
  `lafka_virtual_ok_product_ids`, `lafka_virtual_ok_terms` (taxonomy ⇒
  terms), `lafka_virtual_items_check_enabled`.
- **The counter (GX4, plugin part)** — behaviour the theme's counter layouts
  read; inert until a theme calls it or opts in:
  - **Serves N**: Product data → General → "Serves (people)" (`_lafka_serves`,
    0–50, 0 = not set). `lafka_get_product_serves()` + filter
    `lafka_product_serves` (a variation reads its parent); REST field
    `lafka_serves`; Store API `extensions.lafka.serves` on products. Never
    inferred.
  - `lafka_product_has_required_addons( $product_id )` (filter of the same
    name; Store API `extensions.lafka.has_required_addons`): a product with a
    required add-on group goes to its page instead of a listing quick add.
  - **Pickup or delivery, once**: `lafka_fulfilment_modes()`,
    `lafka_fulfilment_preference()` (branch-session order type, else cookie
    `lafka_order_method`). WooCommerce's default shipping rate follows the
    preference on the classic and block checkout; a rate the customer picked
    is never overridden, and with delivery preferred pickup is only the
    stop-gap until delivery rates exist. Filters `lafka_fulfilment_modes`,
    `lafka_fulfilment_preference`, `lafka_fulfilment_preselect_enabled`.
  - **Drawer quantity stepper** for themes that declare
    `add_theme_support( 'lafka-drawer-stepper' )` (or filter
    `lafka_cart_drawer_stepper_enabled`): options line, labelled − / + and a
    worded Remove per row; `wc-ajax=lafka_cart_set_qty` (nonce
    `lafka-cart-qty`, WooCommerce quantity rules) answers with refreshed
    fragments; page-cache safe. Filter `lafka_cart_drawer_item_details`.
  - **Category tagline**: Products → Categories → "Tagline (optional)" (term
    meta `lafka_tagline`, ≤ 140 chars, REST); `lafka_get_category_tagline()`,
    filter `lafka_category_tagline_meta`.
  - Drawer extras wording: `lafka_cart_drawer_upsell_heading`,
    `lafka_cart_drawer_upsell_row_note`, `lafka_cart_drawer_upsell_add_label`.
  - `wp lafka seed-demo`: a Deals category first in category order (three
    combos: one serves 2, one featured).
- **Diagnostics (GX1)**: `Lafka_Log` / `lafka_log()` logging facade on
  WooCommerce's logger — one WC log source per channel (`lafka-{channel}`),
  personal data scrubbed, a request id on Lafka REST / Store API / checkout
  responses (`X-Lafka-Request-Id`) and on checkout orders (`_lafka_request_id`).
  Theme/child log through the `lafka_log` action.
- **Diagnostics**: deduplicated incident table (warnings and errors, 90-day
  retention), Lafka-scoped PHP fatal capture, and guarded Lafka REST + cron
  entry points.
- **Checkout**: `lafka_checkout_blocked( $reason, $context )` fired at every
  refusal point — store closed, outside the delivery area, address not
  pinpointed, time slot, branch/order type, add-ons, delivery minimum, no
  shipping method, checkout field errors (codes only), other Store API errors
  and payment failures classified declined / AVS / CVV / gateway error.
  Vocabulary: `Lafka_Checkout_Block_Reasons`. See `docs/DIAGNOSTICS.md`.
- **Lafka → Diagnostics** screen (incidents, checkout failures incl.
  WooCommerce place-order traces, health, settings), three Site Health checks
  and a daily error digest email — module `diagnostics`, on by default.
- **Checkout**: delivery prices are withheld until the destination has a
  street address and a postcode (postcode skipped where the country has
  none); pickup stays visible and the customer sees "Enter your street
  address to see the delivery cost." (classic cart/checkout row, empty-rates
  copy, block cart/checkout notice). Complements WooCommerce's
  requires-address setting, which is bypassed whenever blocks Local Pickup is
  enabled and never requires the street. Toggle + message in the Customizer;
  filters `lafka_delivery_quote_guard_enabled`,
  `lafka_delivery_rate_needs_address`, `lafka_delivery_quote_required_fields`,
  `lafka_delivery_quote_guard_message`.
- **Checkout**: pickup orders paid with an offline method (cash, cheque, bank
  transfer) ask only for name, phone and email; card gateways keep the
  billing address they verify (AVS). Empty billing country/state default to
  the store base. Classic: fields hide/show live with the shipping/payment
  choice, with "Want delivery? Add your address". Block: address fields are
  shown as optional and the rule is enforced on place-order
  (`lafka_pickup_checkout_relax_block_address` turns that off). Filters
  `lafka_pickup_checkout_slim_enabled`, `lafka_pickup_checkout_hidden_fields`,
  `lafka_pickup_address_optional_gateways`,
  `lafka_pickup_gateway_needs_billing_address`.
- **Checkout**: cash on delivery reads "Pay at pickup" / "Pay on delivery"
  (title + description, Customizer overrides; filters `lafka_cod_title`,
  `lafka_cod_description`, `lafka_contextual_payment_gateways`).
- **Variations**: options without an explicit order (custom term order, or
  a name/id sort) are listed cheapest first — WooCommerce dropdowns, Lafka
  swatches, and the theme's PDP chips / menu rows (Customizer → Lafka — PDP
  Redesign → "Order size options by price"; filter
  `lafka_sort_variation_options_by_price`).
- **NAP**: `lafka_format_phone_display()` — phone text in national format
  ("(902) 555-0100"; grouped international elsewhere); `phone_display` uses
  it when unset or stored as a bare E.164 number. tel: links keep E.164.
- **Order hours**: `Lafka_Order_Hours::can_order_ahead()`,
  `is_add_to_cart_blocked()`, `get_closed_notice_with_next_open()`; body
  class `lafka-order-ahead`.
- **Insights (GX2)**: first-party, cookieless funnel analytics — module
  `insights`, off by default (Lafka → Modules). One `sendBeacon` per page to
  `POST /wp-json/lafka/v1/i` (page type, referrer host, UTM, device class)
  plus server-side money events for classic and block checkout (add/remove
  cart, cart/checkout views, payment attempt, order placed once, payment
  failures, every `lafka_checkout_blocked` refusal). Visits are told apart by
  a daily-rotating hash; no IP, cookie or identifier is stored. Tables
  `wp_lafka_insights_sessions` (35 days) and `wp_lafka_insights_daily`, a
  nightly Action Scheduler rollup, **Lafka → Insights** (funnel + biggest
  leak, why no order, visits while closed, items viewed but not bought,
  search incl. zero results, device, visits vs orders by source, hour ×
  weekday, payment health) and a Monday-morning plain-English email
  (WooCommerce → Settings → Emails → Weekly Insights). Consent modes in
  Customizer → Lafka — Analytics → Insights: aggregate (default, no banner
  needed, honours GPC/DNT), consent required, off. See `docs/TRACKING.md`.
- **Analytics**: Insights counts as a dataLayer destination, so the event
  layer runs without GA4/GTM.
- **Diagnostics**: a ≤ 700-byte inline handler reports same-origin JavaScript
  errors to `POST /wp-json/lafka/v1/diag`, logged on the `js` channel (with
  the diagnostics or insights module).

### Changed
- `lafka_write_log()` is deprecated and now writes through `Lafka_Log`; the
  order-hours clock error logs to WooCommerce logs instead of `error_log`.

### Fixed
- **Insights**: numbers that combine visits with orders no longer mix
  populations. Orders count only when a measured visit paid (not orders from
  before Insights collected, or from staff / bots / opted-out browsers), so an
  item can no longer be "ordered" more often than "added"; visits vs orders by
  source both come from Insights, with every WooCommerce-attributed order in a
  separate, labelled table; figures start at the day collection started
  ("Collecting since …"), no trend against an uncovered period, and a ratio
  whose part exceeds its whole shows "—". Retained days are re-rolled once.
- **Order hours**: closed-store notices name the next opening ("STORE
  CLOSED. Opens Saturday at 11:00 AM."); adding to a cart while closed says
  so immediately; with date/time slots on, a closed store takes orders for a
  later slot (a chosen slot is required at checkout) instead of blocking.
- **Shipping areas**: the admin store-location map no longer falls back to
  (and saves) a hard-coded Sydney, Australia location. A missing or
  placeholder location is "not configured": delivery maths geocodes the
  WooCommerce store address instead, and an admin notice + Site Health check
  ask for the store location.
- **Consent**: the banner publishes `--lafka-consent-banner-h` /
  `html.lafka-consent-open` so fixed-bottom bars sit above it; 44px buttons
  and a compact mobile layout.
- **Diagnostics**: completed checkouts whose WooCommerce place-order trace was
  left behind (final step `[Shortcode #6A/#6B]` / `[Store API #9]`, or past
  the payment step on a processing / completed / on-hold order) are no longer
  listed as "never finished", indexed as incidents or emailed in the digest;
  incidents already recorded for them are resolved by the daily check. A
  "Show finished attempts" link reveals them; each row shows its outcome.

### Platform (GX5)
- Tested up to WordPress 7.1 / WooCommerce 11.1 (wp-env pins WP 7.1.2 + WC 11.1.2); `Requires Plugins: woocommerce` header.
- Google Maps loader deferred via the script strategy API (no tag rewriting); add-ons depend on `wc-accounting`; checkout date/time uses `selectWoo`; the contact-form inline script attaches to `jquery-form`.
- stylelint 17 + @wordpress/stylelint-config 26; non-blocking official Plugin Check workflow on the release tree.

### Tests & CI
- The PHPUnit suite is independent of test order and wall clock (300/300 random seeds): `LeftoverStubsExtension` pre-defines Brain-Monkey-stubbed functions, `StableClock` guards clock-tick races, static state resets between classes. CI also runs the suite in reverse and in seeded random order.

## [10.1.0] — 2026-09-24

### Fixed
- **Checkout (block)**: a saved "mandatory" date/time no longer blocks block
  checkout while the date/time feature is off (global and per-branch).
- **Checkout (block)**: with pinpoint delivery mandatory, a delivery order
  without a valid in-zone pinpoint is now rejected on the Store API path as
  on classic checkout (it used to skip the geo-fence entirely).
- **Checkout**: pickup orders — Lafka order type *pickup*, WC Local Pickup
  (`local_pickup`) or the blocks pickup method (`pickup_location`) — are never
  asked to pinpoint a delivery location; carts that ship nothing neither.
- **Checkout (block)**: the branch field only offers and accepts orderable
  branches; a crafted checkout naming any other branch is rejected.
- **Branches**: WooCommerce pages no longer fatal once a branch has a geocoded
  address (`update_term_meta_cache()` → `update_termmeta_cache()`).
- **Branches**: the branch-selector and branch-admin scripts load again —
  their URLs had 404'd since the module moved to `incl/branches/`.
- **Branches**: branch product filters (shop, widgets, related products) match
  by the branch term id instead of misusing it as a term_taxonomy_id.
- **Branches**: branch managers get their order emails without WC Analytics;
  the Orders badge counts only orders awaiting the branch; the orders-list
  branch filter renders only when there are branches.
- **Add-ons**: the per-option "Include" flag is honoured everywhere — excluded
  options are not rendered, not accepted when posted (classic or Store API) and
  never priced; a group with nothing left is not shown.
- **Add-ons**: stopped defining `WC_PRODUCT_ADDONS_VERSION` (another vendor's
  constant; clashed with WooCommerce Product Add-Ons).
- **Timeslots**: orders without a branch count against slot capacity (no empty
  `lafka_selected_branch_id` is written, and legacy empty values are counted).
- **Timeslots / Order hours**: the offered dates and the closed-store
  "Opens …" time use the store timezone, not UTC.
- **Promotions / free delivery / KDS**: the blocks `pickup_location` method is
  treated as pickup (delivery minimum, free delivery, KDS order type).
- **Privacy**: add-on selections are found by the personal-data exporter and
  eraser (they are stored under display-name keys, now indexed in
  `_lafka_addon_keys`); older orders are matched by the product's add-on names.
- **Uninstall**: the full-cleanup inventory covers every option the plugin
  writes (`lafka_checkout_mode`, `lafka_email_unsub_list`,
  `lafka_security_options`, combo-deal settings, …); theme options are kept.
- **Analytics**: the menu `search` event fires (it bound the search `<form>`),
  and `add_shipping_info` / `add_payment_info` carry the cart items.
- **SEO**: no second shop-archive canonical when an SEO plugin is active; WC's
  Product schema is kept when Lafka yields structured data to an SEO plugin.
- **Assets**: the plugin no longer overrides theme-registered script handles
  (the theme's `defer` strategy wins); wp-admin no longer loads Google Maps
  without an API key; swatch assets are cache-busted by their real paths.
- **Shortcodes**: `[lafka_shipping_areas]` works without WPBakery and loads its
  map script from the right URL.
- **Admin**: Lafka fields entered on the "Add New" term form (categories, tags,
  branches) are saved; "Show in Catalog" can be unticked on the last variation;
  the delivery-area polygon input is a complete element; stale "Theme Options"
  pointers name the real Google Maps key settings; Site Health names the right
  security option and WP-CLI command.
- **Last order card**: the signed cookie is set before output
  (`template_redirect` on the order-received page, key-checked).
- **Menu**: removed the mobile nav-walker sort filter that fataled (it called
  protected methods) on themes rendering a `mobile` menu location.
- **i18n**: remaining hard-coded English (abandoned-cart email table headers,
  best-seller badge, metabox Yes/No/Show/Hide) is translatable; the POT is
  regenerated (1661 strings) and versioned with the SSOT.
- **Copy**: the checkout win-back field no longer promises an email that is
  never sent.
- **Order hours**: an empty or invalid branch timezone falls back to the site
  timezone instead of fataling the closed-store card and branch status.
- **Promotions**: the BOGO cart label and banner state the configured discount
  (e.g. "25% Off", "Free") instead of always "50% Off".
- **SEO**: WooCommerce's BreadcrumbList is kept when Lafka yields structured
  data to an SEO plugin.
- **Performance**: the LCP preload and fetchpriority hints resolve the same
  homepage hero (they read different legacy keys).
- **Admin**: the food-menu metabox save no longer warns about, or blanks,
  weight / nutrition fields a request didn't send; swatch colour terms without
  a colour no longer render invalid CSS.
- **CLI**: `wp lafka image-alts … --post-type=X` skips unattached images.
- **Admin**: the SEO meta-description character counter no longer throws a JS
  error on every post/page/product edit screen (its textarea shared the
  metabox's id).
- **i18n**: the product-popup and promo-tooltip copy (`lafka` option) is
  registered in the plugin's `wpml-config.xml`.
- **Assets**: the `lafka-dialog` fallback uses the theme's `.min` build only
  when it exists.
- **Checkout**: implicit (hidden) branch / order-type values are filled into
  the session before the Store API gates run, so the delivery geo-fence and
  order-type meta work on single-branch / single-order-type block checkouts.
- **Analytics**: the once-per-order purchase gate uses the order CRUD API, so
  it is correct on HPOS-only stores instead of touching unrelated posts.
- **Uninstall**: only Lafka's own swatch attribute types (color, image, label)
  are reverted; other plugins' types are left alone.
- **New-order alerts**: notified-order state is per shop manager, so one
  manager's poll no longer consumes the alert for everyone else.
- **Conversion**: default-OFF abandoned-cart and web-push modules no longer
  create tables or schedule cron events until enabled.
- **Promotions**: the settings page is reachable while the module is OFF, and
  a one-time notice tells lafka-child upgraders to enable it.
- **Admin / KDS**: no inline storefront styling on the KDS rejected state;
  nutrition admin CSS is scoped to the product-edit screen.
- **Release**: readme.txt `Stable tag` joins the version SSOT; the GPL
  `LICENSE` now ships in the release zip.
- **Menu**: `group_terms()` exposes the grouped-mobile-menu contract as a
  term-level API (the walker hooks were dead).
- **Modules**: Lafka → Modules no longer links every card to a 404 docs page.
- Docs fact-check: shipped-state claims corrected; planning docs retired.
- **Abandoned cart**: the recovery email's resume link restores the cart for
  guests again. The handler ran on `init`, before WooCommerce sets its
  session and cart cookies (and before it loads the session cart on
  `wp_loaded`), so the restored items were never saved and the visitor
  landed on an empty cart; it now runs on `wp_loaded` priority 20.
- **Push**: the VAPID contact defaults to `mailto:` plus the site admin email
  (filter `lafka_push_default_vapid_subject`) instead of a placeholder
  address; sites still on the old placeholder get the new default. If cURL
  rejects any transfer-hardening option, the send now fails closed.
- **Widgets**: newly added About, Payment Options and Popular Posts widgets
  no longer log undefined-index warnings; Popular Posts saves unslashed input
  with a post count of at least 1; About omits "Read more" until a page is
  chosen; Contacts reads the restaurant info once per render.
- **Security headers**: a filtered header name or value containing a line
  break is dropped instead of reaching `header()`.

### Removed
- The per-page "Header size" / "Footer size" layout options (the theme no longer
  reads them since its pre-handoff header/footer CSS was removed); stored meta is kept.
- `lafka_mobile_menu_sort_by_group()`, `lafka_mobile_menu_grouped_walker_filter()`
  and the `LafkaMobileGroupedWalker` nav-walker methods (the class keeps
  `group_terms()`); `lafka_combo_cart_has_pair()`; `Lafka_Options::get_all()`;
  `Lafka_Engine_Display::prevent_purchase_at_grouped_level()`.
- The WC settings "Homepage Hero" section (its option was never read — the
  Customizer's Homepage Hero is the setting), the "Secondary Google Maps API
  Key" setting, and the page/post metabox inputs nothing read (Top Menu Bar,
  Social Share, Footer Sidebar, video-background timing/loop/mute; stored
  values are kept). Matching `wpml-config.xml` entries are dropped.
- Dead WCML add-on compat for the WooCommerce Product Add-Ons v1 panels, the
  handler-less `wc_product_addons_calculate_tax` request in `addons.js`, and
  unreachable branches in abandoned-cart email capture.
- The `WC_PRODUCT_ADDONS_VERSION` constant (see Fixed).

### Removed (lean pass)
- The retired Options-Framework import/export (`lafka_options_upload` /
  `lafka_options_export` and the `lafka-plugin-admin.js` + plupload enqueue
  on every admin page). Use `wp lafka config` or Lafka → Tools.
- `scripts/migrate-restaurant-info.php` (superseded by `wp lafka config`).
- Dead assets, the inert `incl/emails/` review-prompt shim, and uncalled
  helpers; obsolete per-feature version-floor tests.

### Changed
- Every first-party minified script ships next to a readable source (the eight
  shipping-areas / branch scripts' sources are new); `npm run build`
  regenerates the `.min.js` files (esbuild, minify only), release.yml runs it
  and CI fails if a committed build is stale. `SCRIPT_DEBUG` loads the sources.
- A bundled Font Awesome Free 6.7.2 backs `font_awesome_6` when the active
  theme does not register it; `CREDITS.md` lists the bundled libraries.
- PHPCS checks the PHP 8.1 floor with PHPCompatibility 10 (alpha); PHP 8.5
  deprecations fixed (`imagedestroy()`, `curl_close()`, `openssl_pkey_derive()`
  key length).
- Script/style handle registration moved to `incl/lafka-asset-registration.php`.
- The email unsubscribe helpers live once in
  `incl/conversion/lafka-email-unsubscribe.php`.
- New filters: `lafka_pickup_shipping_method_ids`,
  `lafka_branch_order_count_statuses`, `lafka_push_default_vapid_subject`,
  `lafka_ac_resume_redirect_exit` (whether the cart-resume redirect exits;
  default true).
- The OpenGraph/Twitter tags, meta description and `<html lang>` filter
  (`incl/seo/lafka-head-meta.php`), share links (`incl/lafka-share-links.php`),
  `[lafka_nap]` (`incl/schema/lafka-nap-shortcode.php`) and
  `lafka_seo_plugin_active()` (`incl/seo/lafka-seo-plugin-detect.php`) moved
  out of `lafka-plugin.php`; function names, hooks and priorities are
  unchanged. `lafka_push_curl_options()` and
  `Lafka_Security_Headers::build_headers()` split the pure parts out of the
  push sender and the header sender.
- Test suite rationalised: tests execute the code and assert behaviour instead
  of grepping source for implementation strings, comments or existence (1518
  tests / 4319 assertions → 1160 / 2839, 154 → 146 files, plus 15 node:test
  JS tests); the operator-literal guard stores only hashes; the bootstrap
  records hook registrations so wiring is asserted by running registration
  code.

### Performance
- Shipping-area front CSS loads only on cart/checkout (or sitewide while branch
  selection is on); shipping-area and branch admin assets only on their
  screens.
- Development: PHPCS runs in parallel with a result cache (51.6 s cold → 9.9 s,
  0.8 s unchanged), ESLint/Stylelint cache, PHPUnit result cache, and the
  pre-push hook runs only the affected gates in parallel.

## [10.0.0] — 2026-07-07

Phase NX1 ("Platform & Configurability Foundation") release.

### Added
- **Feature Modules dashboard** (Lafka → Modules): every gated module —
  addons, shipping areas, order hours, KDS, promotions, order notifications,
  abandoned cart, web push, review prompts, analytics — visible and toggleable
  from one screen,
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

### Upgrade notes
- **Promotions** (BOGO + delivery minimum) is a default-OFF plugin module; the
  lafka-child 6.x implementation is gone. Sites upgrading from lafka-child
  ≤ 5.x must enable **Lafka → Modules → Promotions** and click-test BOGO and
  the delivery minimum on the cart.
