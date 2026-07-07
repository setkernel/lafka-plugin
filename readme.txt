=== Lafka Plugin — Commission-Free Restaurant Ordering for WooCommerce ===
Contributors: setkernel
Tags: restaurant, food ordering, delivery, woocommerce, online ordering
Requires at least: 6.6
Tested up to: 7.0
Requires PHP: 8.1
Requires Plugins: woocommerce
Stable tag: 9.35.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Turn WooCommerce into a commission-free restaurant ordering system: menus, add-ons, delivery zones, order hours, a kitchen display and more.

== Description ==

**Lafka Plugin** is the companion plugin for the Lafka restaurant theme — but it
works with any WooCommerce store. It turns WooCommerce into a full online
ordering platform that you own end to end, with **no per-order commission** and
no third-party middleman between you and your customers.

Every operator-facing detail (name, address, phone, opening hours, geo, social
profiles, accepted payments) is configured from the Customizer or WP-CLI — the
plugin ships with **zero hardcoded restaurant data**, so it is safe to
distribute and clone. Functionality is split into gated modules you switch on
from **WooCommerce → Feature Modules**, each with sensible defaults, Customizer
controls, filter hooks, and `--lafka-*` CSS custom properties for the theme to
style.

The plugin is deliberately **theme-agnostic**: it emits default markup and its
own feature CSS only. Appearance decisions live in the theme, so Lafka Plugin
drops into any WooCommerce site without fighting your design.

= Feature modules =

* **Product add-ons** — let customers customise items with extra options, sizes
  and toppings, priced per selection.
* **Delivery areas & branches** — draw delivery zones on a map, validate
  customer addresses, and route orders to the right branch.
* **Order hours** — control when the store accepts online orders with a weekly
  schedule, holidays and instant open/close.
* **Kitchen display (KDS)** — a full-screen kitchen screen with a live order
  state machine and customer-facing status tracking.
* **Promotions** — a BOGO, delivery-minimum and promo-banner engine that
  coordinates order discounts.
* **Abandoned cart recovery** — email a one-click resume link when a customer
  enters their address at checkout but does not finish.
* **Web push notifications** — browser-native alerts for order updates and
  reorder reminders, sent even when the site is closed.
* **Review requests** — ask happy customers for a review after a completed order
  via a scheduled email.
* **Analytics & tracking** — GA4 / GTM / Microsoft Clarity / Meta Pixel with
  Consent Mode v2, active whenever a destination is configured.

= Also included =

* A **Restaurant Menu** presentation layer (categories, prices, dietary tags,
  nutrition facts, variation swatches).
* **Local SEO** — Restaurant / Menu / Breadcrumb JSON-LD schema wired to your
  operator configuration.
* **Timeslots** for scheduled pickup and delivery.
* **20+ shortcodes** (also exposed as page-builder elements) for menus, contact
  blocks, maps, teasers and more.
* **HPOS-ready** WooCommerce integration and a **Site Health** panel that
  surfaces misconfiguration before customers hit it.

= Privacy =

Lafka Plugin does not phone home. Analytics destinations, push subscriptions and
marketing emails are only active when you configure and enable the relevant
module. Personal-data export and erasure hooks are provided for the data the
plugin stores.

== Installation ==

1. Install and activate **WooCommerce** first (Lafka Plugin lists it as a
   required plugin).
2. Upload the `lafka-plugin` folder to `/wp-content/plugins/`, or install the
   ZIP from **Plugins → Add New → Upload Plugin**.
3. Activate the plugin through the **Plugins** menu in WordPress.
4. Configure your restaurant details under **Appearance → Customize → Lafka —
   Restaurant Information** (or seed them in bulk with the bundled WP-CLI
   script, `scripts/migrate-restaurant-info.php`).
5. Enable the modules you need under **WooCommerce → Feature Modules**. Every
   module is off by default until you opt in.

For a design that matches, pair the plugin with the free **Lafka theme**, though
any WooCommerce-compatible theme will work.

== Frequently Asked Questions ==

= Does Lafka Plugin charge a commission on orders? =

No. Lafka Plugin adds ordering features to your own WooCommerce store. You keep
100% of every order minus your own payment-gateway fees — there is no per-order
commission paid to us or anyone else.

= Do I need WooCommerce? =

Yes. Lafka Plugin extends WooCommerce for products, cart, checkout and orders,
and declares WooCommerce as a required plugin. Install and activate WooCommerce
before activating Lafka Plugin.

= Do I have to use the Lafka theme? =

No. The plugin is theme-agnostic and emits only default markup plus its own
feature CSS. It looks best with the Lafka theme because the theme ships the
matching `--lafka-*` styling, but it functions on any WooCommerce-ready theme.

= Where do the restaurant name, address and hours come from? =

From your site configuration — the Customizer "Restaurant Information" panel or
the WP-CLI seeding script — never from hardcoded values. Every NAP, geo, hours
and social value is resolved through `lafka_get_restaurant_info()` and is
filterable, so nothing about your business is baked into the code.

= How do I turn features on or off? =

Open **WooCommerce → Feature Modules**. Each module (add-ons, delivery areas,
order hours, kitchen display, promotions, abandoned cart, push, reviews,
analytics) is a toggle with its own Customizer settings and filter hooks.
Modules are off by default so you only run what you use.

= Block Cart/Checkout or classic — which does Lafka use? =

Both are fully supported. Choose under **Lafka → Modules → Checkout experience**:

* **Block Cart & Checkout** — the modern WooCommerce block checkout. New stores
  start here (it is what WooCommerce gives new stores by default). Lafka's order
  type / branch selects, time-slot picker, add-on line items, free-delivery
  progress and every ordering rule all work on it.
* **Classic Cart & Checkout** — the classic shortcode checkout. Stores that were
  already running Lafka keep this automatically when they update, so nothing
  about their checkout changes.

Switching is one click, but it changes the pages your customers use, so place a
test order afterwards. Developers can force classic everywhere with the
`lafka_force_classic_checkout` filter.

= What personal data does Lafka store, and how long? =

Two optional modules store customer personal data in their own tables:

* **Abandoned cart recovery** keeps the customer's email and a snapshot of their
  cart when they enter an email at checkout but do not finish. Rows are deleted
  automatically 30 days after they are created (or once the customer completes an
  order), whichever comes first.
* **Web push notifications** keep the browser push endpoint, user-agent and
  locale for each subscriber. When a customer unsubscribes the row is
  soft-deleted and then removed 60 days later; invalid endpoints are removed
  immediately.

Both stores are wired into WordPress's built-in privacy tools: **Tools →
Export/Erase Personal Data** returns and removes a customer's push subscriptions
(matched to their account) and abandoned carts (matched by email). Everything
else the plugin stores is your own business configuration, not personal data.

= What happens to my data when I uninstall the plugin? =

By default, uninstalling removes only the two conversion tables above and reverts
custom attribute types — all your menu configuration, branches, zones, hours and
settings are kept, so re-installing resumes where you left off.

If you want a clean slate, enable **Remove all data on uninstall** under **Lafka →
Modules**. With that on, deleting the plugin also erases every Lafka option, the
menu / delivery-zone / add-on-group posts, delivery branches and their per-branch
settings, lafka-prefixed transients, and Lafka product/customer meta. Your
WooCommerce **orders and their history are always retained** — a plugin uninstall
never rewrites your sales records.

= Is it ready for High-Performance Order Storage (HPOS)? =

Yes. The WooCommerce integration is HPOS-compatible and does not rely on legacy
post-based order storage.

= Where is the full changelog? =

Development happens in the open on GitHub. See the Changelog section below for
where to find per-release notes.

== Screenshots ==

1. Restaurant menu with categories, prices and dietary tags.
2. Product add-ons — sizes, toppings and extras priced per selection.
3. Delivery areas drawn on a map with per-branch routing.
4. Order-hours schedule with instant open/close control.
5. Kitchen display (KDS) showing the live order state machine.
6. Feature Modules admin screen with per-module toggles.

== Changelog ==

This plugin is developed in the open and released per-version on GitHub. For the
complete, authoritative changelog and downloadable releases, see:

https://github.com/setkernel/lafka-plugin/releases

= 9.35.0 =
* Current stable release. See the GitHub releases page for the full history of
  changes across all versions.

== Upgrade Notice ==

= 9.35.0 =
Requires WordPress 6.6+, WooCommerce 9.5+ and PHP 8.1+. Review the GitHub
release notes before upgrading a production store.
