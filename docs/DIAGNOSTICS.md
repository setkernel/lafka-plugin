# Diagnostics (logging, incidents, "why no order")

Lafka writes what goes wrong on the store to **WooCommerce → Status → Logs**,
keeps a short deduplicated list of problems, and records every reason a customer
could not add to cart, check out or pay. Code: `incl/observability/`.

## For store owners

- **Lafka → Diagnostics** (module `diagnostics`, on by default):
  - *Incidents*: each distinct problem once, with how often and when it was
    last seen. Mute (still counted, left out of emails), resolve (re-opens if it
    happens again), or open its lines in the WooCommerce log viewer.
  - *Checkout failures*: refusals by reason for the last 30 days, recent failed
    payments (declined / address check (AVS) / security code (CVV) / gateway
    error), and checkout attempts that stopped part-way or failed (WooCommerce
    9.9+ place-order traces). WooCommerce does not always delete the trace of
    a completed checkout, so traces whose final step is a success step
    (`[Shortcode #6A/#6B]`, `[Store API #9]`), or that got past the payment
    step on an order now processing / completed / on hold, count as finished:
    hidden (a "Show finished attempts" link reveals them), never indexed as
    incidents and never in the digest. Filter: `lafka_place_order_trace_outcome`.
  - *Health*: versions, checkout mode, modules, WooCommerce logging settings,
    daily job status.
  - *Settings*: minimum log level, how long incidents are kept (default 90
    days after last seen), "Send test error", "Run the daily check now".
- **Daily error digest**: WooCommerce → Settings → Emails → *Lafka error
  digest*. On by default, sent to the site admin email, once a day, only when
  something new happened (payment/checkout/PHP/JS warnings, or any error).
- **Site Health** (Tools → Site Health): Lafka fatal error in the last 24 h
  (critical), 3+ payment failures in 7 days (recommended), background jobs not
  running (recommended).

Turning the module off (Lafka → Modules) hides the screen, the Site Health
checks and the digest. Logging itself stays on.

Personal data is removed before anything is written (emails, phone numbers,
card numbers, postal codes, IP addresses, tokens; address/billing/shipping
fields by name). Order and product ids are kept.

## For developers

### Logging

```php
Lafka_Log::error( 'payment', 'Gateway timed out', array( 'code' => 'gateway_timeout', 'order_id' => $order_id ) );
lafka_log( 'warning', 'shipping', 'Zone polygon is empty', array( 'code' => 'empty_zone' ) );
```

- Levels: `debug info notice warning error critical` (+ `alert emergency`).
- Channels: `core checkout payment store-api order-hours shipping timeslots
  addons kds conversion analytics js theme child cron rest php`. Each is the WC
  log source `lafka-{channel}`.
- Minimum level: `warning` (everything when `WP_DEBUG`); option
  `lafka_log_settings[min_level]`; filter `lafka_log_min_level( $level, $channel )`.
- Context envelope added to every record: `request_id`, `channel`, `code`,
  `url_path` (no query string), `lafka_version`.
- Records at `warning`+ are indexed in `{prefix}lafka_incidents` and fire
  `do_action( 'lafka_log_record', $record )` — forward to Sentry etc. there.
- Scrubber filters: `lafka_log_scrub_keys` (glob key patterns),
  `lafka_log_scrub_patterns` (regex => replacement).
- `Lafka_Log::guard( $callable, $channel, 'rethrow'|'wp_error'|'swallow' )`
  wraps an entry point: an uncaught Throwable is logged with context. All
  `lafka/*` and `wc-lafka/*` REST callbacks run guarded (500 `WP_Error`), as do
  Lafka's cron hooks (`lafka_guarded()`, logged and swallowed).
- The theme and child theme log without depending on the plugin:
  `do_action( 'lafka_log', $level, $channel, $message, $context )` (theme
  helper: `lafka_theme_log()`).
- Correlation: `X-Lafka-Request-Id` header on Lafka REST, Store API and classic
  checkout responses; `_lafka_request_id` meta on orders created at checkout.
- Fatal errors: WooCommerce's `woocommerce_shutdown_error` is indexed as a
  `php` critical incident only when the file is inside the Lafka plugin, the
  Lafka theme or its child (`lafka_fatal_capture_roots` filter).

### `lafka_checkout_blocked` — the "why no order" contract

```php
do_action( 'lafka_checkout_blocked', string $reason, array $context );
```

Fired once per reason per request at every refusal point. The signature never
changes. Reasons are the constants of `Lafka_Checkout_Block_Reasons`
(`incl/observability/class-lafka-checkout-block-reasons.php`); labels are
filterable through `lafka_checkout_block_reasons`.

| Reason | Fired by |
|---|---|
| `store_closed` | Order hours: classic checkout, classic + Store API add-to-cart, Store API checkout |
| `outside_delivery_zone` | Shipping areas geo-fence (classic + Store API) |
| `address_unpinned` | Delivery order without a valid map pinpoint (classic + Store API) |
| `timeslot_invalid` | Missing / invalid / past / full date or slot (classic, Store API update + checkout) |
| `branch_invalid` | Store API branch selection not orderable |
| `order_type_unavailable` | Order type not offered by the branch (Store API checkout) |
| `addon_invalid` | Add-on validation rejected an add-to-cart (classic + Store API) |
| `below_delivery_minimum` | Promotions withheld delivery rates (once per session per day) |
| `no_shipping_method` | WooCommerce: no delivery/pickup method (classic `shipping`, Store API shipping-option errors) |
| `field_validation` | WooCommerce checkout validation; `context.fields` holds error codes / field names, never values |
| `store_api_error` | Any other Store API checkout rejection |
| `payment_declined` / `payment_avs` / `payment_cvv` / `payment_gateway_error` / `payment_other` | Order moved to *failed*, or a failure note on an already-failed order (retry); classified from the gateway note (`lafka_payment_failure_keywords`) |

Context keys: `path` (`classic`/`store_api`/`order`), `stage`
(`add_to_cart`/`cart`/`checkout`/`payment`), `code`, and where relevant
`fields`, `order_id`, `gateway`, `class`. No customer-entered
values ever.

Lafka's own listener logs each reason (`checkout` channel at notice; payment
reasons on `payment` at warning, which makes them incidents) and keeps 35 days
of per-day counters in `lafka_log_checkout_stats`.

### Storage and jobs

- Table `{prefix}lafka_incidents` (dbDelta, version `lafka_incidents_db_version`,
  self-heals on `init`), dropped on uninstall.
- Options: `lafka_log_settings` (min_level, diagnostics, retention_days),
  `lafka_log_checkout_stats`, `lafka_log_last_daily_run`, `lafka_log_seen_traces`.
- Action Scheduler recurring action `lafka_diagnostics_daily` (group `lafka`,
  07:00 site time; `lafka_diagnostics_daily_hour` filter): prune incidents,
  index stale unfinished place-order traces (and resolve incidents that were
  recorded for finished ones), prune counters, send the digest.
