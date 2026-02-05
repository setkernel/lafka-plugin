# Lafka Plugin

Companion plugin for the [Lafka WordPress Theme](https://github.com/setkernel/lafka-theme). Adds restaurant menu management, product addons, combos, delivery zones, store hours, and 20+ shortcodes.

Originally developed by [theAlThemist](https://www.althemist.com). Continued as open-source under GPL v2+.

## Requirements

- WordPress 5.6+
- WooCommerce 7.0+
- PHP 7.4+
- [Lafka Theme](https://github.com/setkernel/lafka-theme)

## Installation

1. Download or clone this repository into `wp-content/plugins/lafka-plugin`
2. Activate in WordPress Admin → Plugins
3. Or: Install automatically via TGM prompt when activating the Lafka theme

## Features

### Custom Post Types
- **Restaurant Menu** (`lafka-foodmenu`) — Menu items with categories, prices, images
- **Product Combos** (`wc_combined_product`) — Bundle/composite products
- **Shipping Areas** (`lafka_shipping_areas`) — Delivery zone management
- **Product Addons** (`lafka_glb_addon`) — Global addon groups

### Shortcodes (20+)
All shortcodes are also available as WPBakery/Visual Composer elements.

| Shortcode | Description |
|---|---|
| `[lafka_foodmenu]` | Restaurant menu display with filtering |
| `[lafka_banner]` | Banner with image, text, button |
| `[lafka_counter]` | Animated number counter |
| `[lafka_typed]` | Typed.js text animation |
| `[lafka_icon_box]` / `[lafka_icon_teaser]` | Icon blocks |
| `[lafka_pricing_table]` | Pricing tables |
| `[lafka_countdown]` | Countdown timer |
| `[lafka_map]` | Google Maps with directions |
| `[lafka_contact_form]` | Ajax contact form |
| `[lafka_latest_posts]` / `[lafkablogposts]` | Blog grids/carousels |
| `[lafka_woo_*_carousel]` | 8 WooCommerce product carousels |
| `[lafka_cloudzoom_gallery]` | Product image gallery |
| `[lafka_content_slider]` | Tabbed content slider |

### Widgets
- About, Contacts, Latest Menu Entries, Payment Options, Popular Posts, Product Filter

### Modules (conditionally loaded via theme options)
- **Product Addons** — Text, textarea, checkbox, radio fields per product
- **Product Combos** — Composite/bundle products with stock management
- **Order Hours** — Store open/close scheduling, holidays, branch-specific
- **Shipping Areas** — Delivery zones, date/time slot selection, branch locations
- **Nutrition & Allergens** — Per-product nutrition facts
- **Variation Swatches** — Color and image swatches

### Icon Packs
- Elegant Icons (etline) — 100+ icons
- Flaticon Food Icons — 50+ food-specific icons

## Structure

```
lafka-plugin/
├── incl/
│   ├── addons/          # Product addons system
│   ├── combos/          # Product combos/bundles
│   ├── nutrition/       # Nutrition information
│   ├── order-hours/     # Store hours management
│   ├── shipping-areas/  # Delivery zones
│   └── swatches/        # Variation swatches
├── shortcodes/          # All shortcode definitions
├── widgets/             # Widget classes
├── assets/              # JS, CSS, images
├── importer/            # Demo content importer
├── languages/           # Translation files
└── lafka-plugin.php     # Main plugin file (v7.1.0)
```

## License

GPL v2 or later. See [LICENSE](LICENSE).
