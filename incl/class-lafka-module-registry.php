<?php
/**
 * Lafka_Module_Registry — typed registry of gated Lafka feature modules (NX1-01).
 *
 * Before this, the feature flags lived in three unrelated places: the five
 * Lafka_Options flags inside the opaque 'lafka' option array
 * (product_addons / shipping_areas / order_hours / kitchen_display /
 * promotions), the conversion modules self-gating on scattered Customizer
 * settings (abandoned cart / web push / review prompts), and analytics
 * deriving its own "is a destination configured?" answer. A buyer could not
 * see or flip what they owned from one place.
 *
 * This registry is that single place. Each module registers a small typed
 * descriptor — id, i18n label + description, category, default state, and the
 * callbacks that read/write the module's REAL existing storage. It invents no
 * new storage: the five flags still read/write the same 'lafka' array the
 * is_lafka_*() gates read (via Lafka_Options), and the conversion modules
 * still read/write the same plugin options their Customizer panels persist. So
 * toggling a module here changes exactly the option the current code already
 * reads — zero behaviour change when untouched.
 *
 * Consumers: the Feature Modules dashboard (incl/admin/class-lafka-modules-page.php),
 * Site Health (incl/site-health/class-lafka-site-health.php), and — later —
 * the setup wizard (NX3-01), uninstall cleanup (NX1-06) and the Pro/licensing
 * layer (NX5-03) all read this one list.
 *
 * @package Lafka
 * @since   10.0.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Lafka_Module_Registry' ) ) {

	/**
	 * Static registry of Lafka_Module descriptors, lazily populated with the
	 * built-in modules on first access.
	 */
	final class Lafka_Module_Registry {

		/** @var array<string,Lafka_Module> */
		private static $modules = array();

		/** @var bool */
		private static $bootstrapped = false;

		/**
		 * Register (or replace) a module descriptor.
		 */
		public static function register( Lafka_Module $module ): void {
			self::$modules[ $module->get_id() ] = $module;
		}

		/**
		 * Fetch a module by id, or null when unknown.
		 */
		public static function get( string $id ): ?Lafka_Module {
			self::bootstrap();
			return self::$modules[ $id ] ?? null;
		}

		/**
		 * All registered modules, keyed by id, in registration order.
		 *
		 * @return array<string,Lafka_Module>
		 */
		public static function all(): array {
			self::bootstrap();
			return self::$modules;
		}

		/**
		 * Modules whose enable flag lives in a given storage backend.
		 *
		 * Used by Site Health to enumerate exactly the five 'lafka'-option
		 * flags without re-hardcoding them.
		 *
		 * @param string $storage 'lafka_option' | 'option' | 'derived'.
		 * @return array<string,Lafka_Module>
		 */
		public static function modules_by_storage( string $storage ): array {
			$out = array();
			foreach ( self::all() as $id => $module ) {
				if ( $module->get_storage() === $storage ) {
					$out[ $id ] = $module;
				}
			}
			return $out;
		}

		/**
		 * Reset registry state (test isolation).
		 */
		public static function reset(): void {
			self::$modules      = array();
			self::$bootstrapped = false;
		}

		/**
		 * Populate the built-in modules once, then let third parties register
		 * their own via the 'lafka_register_modules' action.
		 */
		public static function bootstrap(): void {
			if ( self::$bootstrapped ) {
				return;
			}
			self::$bootstrapped = true;
			self::register_builtin_modules();
			if ( function_exists( 'do_action' ) ) {
				do_action( 'lafka_register_modules' );
			}
		}

		/**
		 * Human-readable label for a category slug.
		 */
		public static function category_label( string $slug ): string {
			$labels = array(
				'ordering'   => esc_html__( 'Ordering', 'lafka-plugin' ),
				'fulfilment' => esc_html__( 'Fulfilment', 'lafka-plugin' ),
				'operations' => esc_html__( 'Operations', 'lafka-plugin' ),
				'conversion' => esc_html__( 'Conversion', 'lafka-plugin' ),
				'analytics'  => esc_html__( 'Analytics', 'lafka-plugin' ),
				'seo'        => esc_html__( 'Search & AI visibility', 'lafka-plugin' ),
			);
			return $labels[ $slug ] ?? ucfirst( $slug );
		}

		/**
		 * Resolve a module's docs slug to a documentation URL.
		 *
		 * Returns '' by default: per-module docs pages do not exist yet, and a
		 * guessed URL would render a 404 "Docs" link on every Modules card (the
		 * page hides the link when this is empty). The `lafka_module_docs_url`
		 * filter lets a docs site (NX5) supply real URLs per slug.
		 */
		public static function docs_url( Lafka_Module $module ): string {
			$slug = $module->get_docs_slug();
			if ( '' === $slug ) {
				return '';
			}
			$url = '';
			if ( function_exists( 'apply_filters' ) ) {
				$url = apply_filters( 'lafka_module_docs_url', $url, $module->get_id(), $slug );
			}
			return (string) $url;
		}

		// ─── Built-in module descriptors ────────────────────────────────────

		private static function register_builtin_modules(): void {
			// ---- The five Lafka_Options flags (the 'lafka' option array) ----
			self::register(
				new Lafka_Module(
					array(
						'id'              => 'product_addons',
						'label'           => esc_html__( 'Product add-ons', 'lafka-plugin' ),
						'description'     => esc_html__( 'Let customers customise items with extra options, sizes and toppings, priced per selection.', 'lafka-plugin' ),
						'category'        => 'ordering',
						'storage'         => 'lafka_option',
						'default_enabled' => Lafka_Options::flag_default_on( 'product_addons' ),
						'get_enabled'     => self::flag_getter( 'product_addons' ),
						'set_enabled'     => self::flag_setter( 'product_addons' ),
						'settings_path'   => 'edit.php?post_type=product&page=lafka_addons',
						'docs_slug'       => 'product-addons',
					)
				)
			);
			self::register(
				new Lafka_Module(
					array(
						'id'              => 'shipping_areas',
						'label'           => esc_html__( 'Delivery areas & branches', 'lafka-plugin' ),
						'description'     => esc_html__( 'Draw delivery zones on a map, validate customer addresses, and route orders to the right branch.', 'lafka-plugin' ),
						'category'        => 'fulfilment',
						'storage'         => 'lafka_option',
						'default_enabled' => false,
						'get_enabled'     => self::flag_getter( 'shipping_areas' ),
						'set_enabled'     => self::flag_setter( 'shipping_areas' ),
						// Maps work without a key (OpenStreetMap); what the module
						// needs is the store's location to start maps and measure
						// delivery distances from.
						'is_configured'   => static function () {
							return function_exists( 'lafka_get_store_point' ) && null !== lafka_get_store_point();
						},
						'settings_path'   => 'admin.php?page=lafka_shipping_areas_admin',
						'docs_slug'       => 'delivery-areas',
					)
				)
			);
			self::register(
				new Lafka_Module(
					array(
						'id'              => 'order_hours',
						'label'           => esc_html__( 'Order hours', 'lafka-plugin' ),
						'description'     => esc_html__( 'Control when the store accepts online orders with a weekly schedule, holidays and instant open/close.', 'lafka-plugin' ),
						'category'        => 'fulfilment',
						'storage'         => 'lafka_option',
						'default_enabled' => false,
						'get_enabled'     => self::flag_getter( 'order_hours' ),
						'set_enabled'     => self::flag_setter( 'order_hours' ),
						'is_configured'   => static function () {
							$opts = get_option( 'lafka_order_hours_options' );
							return is_array( $opts ) && ! empty( $opts );
						},
						'settings_path'   => 'admin.php?page=lafka_order_hours',
						'docs_slug'       => 'order-hours',
					)
				)
			);
			self::register(
				new Lafka_Module(
					array(
						'id'              => 'kitchen_display',
						'label'           => esc_html__( 'Kitchen display (KDS)', 'lafka-plugin' ),
						'description'     => esc_html__( 'Full-screen kitchen screen with a live order state machine; customers follow it in Order tracking.', 'lafka-plugin' ),
						'category'        => 'operations',
						'storage'         => 'lafka_option',
						'default_enabled' => false,
						'get_enabled'     => self::flag_getter( 'kitchen_display' ),
						'set_enabled'     => self::flag_setter( 'kitchen_display' ),
						'settings_path'   => 'admin.php?page=lafka_kitchen_display',
						'docs_slug'       => 'kitchen-display',
					)
				)
			);
			self::register(
				new Lafka_Module(
					array(
						'id'              => 'promotions',
						'label'           => esc_html__( 'Promotions', 'lafka-plugin' ),
						'description'     => esc_html__( 'Buy-one-get-one discount, delivery minimum and the promo banner. First-order, slow-day, combo and free-delivery offers have their own switches in WooCommerce → Settings → Restaurant.', 'lafka-plugin' ),
						'category'        => 'conversion',
						'storage'         => 'lafka_option',
						'default_enabled' => false,
						'get_enabled'     => self::flag_getter( 'promotions' ),
						'set_enabled'     => self::flag_setter( 'promotions' ),
						'settings_path'   => 'admin.php?page=lafka-promotions',
						'docs_slug'       => 'promotions',
					)
				)
			);

			// ---- Deals (default ON: a deal only exists once an operator creates one) ----
			self::register(
				new Lafka_Module(
					array(
						'id'              => 'deals',
						'label'           => esc_html__( 'Deals', 'lafka-plugin' ),
						'description'     => esc_html__( 'Deal products where the customer picks each item, e.g. any 2 pizzas for $20, each with its own options.', 'lafka-plugin' ),
						'category'        => 'ordering',
						'storage'         => 'lafka_option',
						'default_enabled' => Lafka_Options::flag_default_on( 'deals' ),
						'get_enabled'     => self::flag_getter( 'deals' ),
						'set_enabled'     => self::flag_setter( 'deals' ),
						'settings_path'   => 'post-new.php?post_type=product',
						'docs_slug'       => 'deals',
					)
				)
			);

			// ---- Order tracking (default ON: static without the kitchen display) ----
			self::register(
				new Lafka_Module(
					array(
						'id'              => 'order_tracking',
						'label'           => esc_html__( 'Order tracking', 'lafka-plugin' ),
						'description'     => esc_html__( 'A status stepper on the order confirmation and in My Account, updated live while the kitchen works, a Track link in the order emails, and one-tap reorder. With the kitchen display off it shows the WooCommerce status and does not poll.', 'lafka-plugin' ),
						'category'        => 'ordering',
						'storage'         => 'lafka_option',
						'default_enabled' => Lafka_Options::flag_default_on( 'order_tracking' ),
						'get_enabled'     => self::flag_getter( 'order_tracking' ),
						'set_enabled'     => self::flag_setter( 'order_tracking' ),
						'docs_slug'       => 'order-tracking',
					)
				)
			);

			// ---- Address suggestions (Lafka's provider in WooCommerce's autocomplete system) ----
			self::register(
				new Lafka_Module(
					array(
						'id'              => 'address_autocomplete',
						'label'           => esc_html__( 'Address suggestions', 'lafka-plugin' ),
						'description'     => esc_html__( 'Suggest full addresses as customers type at checkout (classic and block) through WooCommerce\'s own address autocomplete, using Google Places with your Maps key or the free Photon service. Turn it on for customers in WooCommerce → Settings → General.', 'lafka-plugin' ),
						'category'        => 'ordering',
						'storage'         => 'option',
						'default_enabled' => true,
						'get_enabled'     => static function () {
							return '1' === (string) lafka_setting( 'lafka_address_autocomplete_enabled', '1' );
						},
						'set_enabled'     => self::setting_setter( 'lafka_address_autocomplete_enabled' ),
						// Configured once the customer-facing switch in WooCommerce is on.
						'is_configured'   => static function () {
							return class_exists( 'Lafka_Address_Search' ) && Lafka_Address_Search::woocommerce_enabled();
						},
						'settings_path'   => 'admin.php?page=wc-settings&tab=general',
						'docs_slug'       => 'address-suggestions',
					)
				)
			);

			// ---- Tips (a WooCommerce yes/no option; the settings live with it) ----
			self::register(
				new Lafka_Module(
					array(
						'id'              => 'tips',
						'label'           => esc_html__( 'Tips', 'lafka-plugin' ),
						'description'     => esc_html__( 'Suggested tips and a custom amount at checkout, added as a separate non-taxable line.', 'lafka-plugin' ),
						'category'        => 'ordering',
						'storage'         => 'option',
						'default_enabled' => false,
						'get_enabled'     => static function () {
							return 'yes' === get_option( 'lafka_tips_enabled', 'no' );
						},
						'set_enabled'     => static function ( bool $enabled ) {
							update_option( 'lafka_tips_enabled', $enabled ? 'yes' : 'no' );
						},
						'settings_path'   => 'admin.php?page=wc-settings&tab=lafka_restaurant&section=tips',
						'docs_slug'       => 'tips',
					)
				)
			);

			// ---- Loyalty points (a WooCommerce yes/no option; the settings live with it) ----
			self::register(
				new Lafka_Module(
					array(
						'id'              => 'loyalty',
						'label'           => esc_html__( 'Loyalty points', 'lafka-plugin' ),
						'description'     => esc_html__( 'Customers with an account earn points on completed orders and spend them at checkout, on the classic and block checkout, as a single-use WooCommerce coupon. Points show in My Account and in the completed email; refunds and cancellations take them back.', 'lafka-plugin' ),
						'category'        => 'conversion',
						'storage'         => 'option',
						'default_enabled' => false,
						'get_enabled'     => static function () {
							return 'yes' === lafka_setting( 'lafka_loyalty_enabled', 'no' );
						},
						'set_enabled'     => static function ( bool $enabled ) {
							update_option( 'lafka_loyalty_enabled', $enabled ? 'yes' : 'no' );
						},
						'settings_path'   => 'admin.php?page=wc-settings&tab=lafka_restaurant&section=loyalty',
						'docs_slug'       => 'loyalty',
					)
				)
			);

			// ---- Installable app (a WooCommerce yes/no option; default on, the home-screen card has its own switch) ----
			self::register(
				new Lafka_Module(
					array(
						'id'              => 'pwa',
						'label'           => esc_html__( 'Installable app', 'lafka-plugin' ),
						'description'     => esc_html__( 'Customers can add the site to their home screen. Adds a web app manifest from your site icon and colours, and an offline page and menu snapshot (never the cart, checkout or account). The "Add to home screen" card is a separate switch.', 'lafka-plugin' ),
						'category'        => 'conversion',
						'storage'         => 'option',
						'default_enabled' => true,
						'get_enabled'     => static function () {
							return 'yes' === get_option( 'lafka_pwa_enabled', 'yes' );
						},
						'set_enabled'     => static function ( bool $enabled ) {
							update_option( 'lafka_pwa_enabled', $enabled ? 'yes' : 'no' );
						},
						// Installing needs a site icon.
						'is_configured'   => static function () {
							return has_site_icon();
						},
						'settings_path'   => 'admin.php?page=wc-settings&tab=lafka_restaurant&section=app',
						'docs_slug'       => 'installable-app',
					)
				)
			);

			// ---- Text messages (a WooCommerce yes/no option; the settings live with it) ----
			self::register(
				new Lafka_Module(
					array(
						'id'              => 'notify',
						'label'           => esc_html__( 'Text messages', 'lafka-plugin' ),
						'description'     => esc_html__( 'Tell customers who opt in at checkout when their order is accepted, ready or out for delivery, by SMS (Twilio) or WhatsApp, with the tracker link. Adds a free "Message us on WhatsApp" link to the order confirmation and the Contact page.', 'lafka-plugin' ),
						'category'        => 'operations',
						'storage'         => 'option',
						'default_enabled' => false,
						'get_enabled'     => static function () {
							return 'yes' === lafka_setting( 'lafka_notify_enabled', 'no' );
						},
						'set_enabled'     => static function ( bool $enabled ) {
							update_option( 'lafka_notify_enabled', $enabled ? 'yes' : 'no' );
						},
						'is_configured'   => static function () {
							return class_exists( 'Lafka_Notify' ) && null !== Lafka_Notify::adapter();
						},
						'settings_path'   => 'admin.php?page=wc-settings&tab=lafka_restaurant&section=notify',
						'docs_slug'       => 'text-messages',
					)
				)
			);

			// ---- New-order alerts (a checkbox flag in the 'lafka' option array) ----
			// Stored as a '1'/'0' checkbox (NOT the 'enabled'/'disabled' sentinel the
			// five flags above use), so it gets bespoke truthy getter/setter rather
			// than the flag_getter/flag_setter factories. Business logic lives in
			// incl/admin/class-lafka-order-notifications.php (moved from the theme,
			// NX1-08b); the runtime gate additionally honours the
			// `lafka_order_notifications_enabled` filter.
			self::register(
				new Lafka_Module(
					array(
						'id'              => 'order_notifications',
						'label'           => esc_html__( 'New-order alerts', 'lafka-plugin' ),
						'description'     => esc_html__( 'Browser notification + sound for shop managers each time a new order is ready to process, routed to the assigned branch operator.', 'lafka-plugin' ),
						'category'        => 'operations',
						'storage'         => 'lafka_option',
						'default_enabled' => false,
						'get_enabled'     => static function () {
							return (bool) Lafka_Options::get( 'order_notifications' );
						},
						'set_enabled'     => static function ( bool $enabled ) {
							$opts = get_option( 'lafka', array() );
							if ( ! is_array( $opts ) ) {
								$opts = array();
							}
							$opts['order_notifications'] = $enabled ? '1' : '0';
							update_option( 'lafka', $opts );
							if ( class_exists( 'Lafka_Options' ) ) {
								Lafka_Options::flush();
							}
						},
						'docs_slug'       => 'order-notifications',
					)
				)
			);

			// ---- Conversion modules (plugin options, set in the Customizer) ----
			self::register(
				new Lafka_Module(
					array(
						'id'              => 'abandoned_cart',
						'label'           => esc_html__( 'Abandoned cart recovery', 'lafka-plugin' ),
						'description'     => esc_html__( 'Email a one-click resume link when a customer enters their email at checkout but does not finish.', 'lafka-plugin' ),
						'category'        => 'conversion',
						'storage'         => 'option',
						'default_enabled' => false,
						'get_enabled'     => self::setting_getter( 'lafka_ac_enabled' ),
						'set_enabled'     => self::setting_setter( 'lafka_ac_enabled' ),
						'settings_path'   => 'customize.php?autofocus[panel]=lafka_abandoned_cart',
						'docs_slug'       => 'abandoned-cart',
					)
				)
			);
			self::register(
				new Lafka_Module(
					array(
						'id'              => 'push',
						'label'           => esc_html__( 'Web push notifications', 'lafka-plugin' ),
						'description'     => esc_html__( 'Browser-native alerts for order updates and reorder reminders, sent even when the site is closed.', 'lafka-plugin' ),
						'category'        => 'conversion',
						'storage'         => 'option',
						'default_enabled' => false,
						'get_enabled'     => self::setting_getter( 'lafka_push_enabled' ),
						'set_enabled'     => self::setting_setter( 'lafka_push_enabled' ),
						'is_configured'   => static function () {
							if ( ! function_exists( 'lafka_push_get_vapid_config' ) ) {
								return false;
							}
							$vapid = lafka_push_get_vapid_config();
							return '' !== $vapid['public'] && '' !== $vapid['private'];
						},
						'settings_path'   => 'customize.php?autofocus[panel]=lafka_push',
						'docs_slug'       => 'web-push',
					)
				)
			);
			self::register(
				new Lafka_Module(
					array(
						'id'              => 'review_prompt',
						'label'           => esc_html__( 'Review requests', 'lafka-plugin' ),
						'description'     => esc_html__( 'Ask happy customers for a review after a completed order via a scheduled email.', 'lafka-plugin' ),
						'category'        => 'conversion',
						'storage'         => 'option',
						'default_enabled' => false,
						'get_enabled'     => self::setting_getter( 'lafka_review_email_enabled' ),
						'set_enabled'     => self::setting_setter( 'lafka_review_email_enabled' ),
						'is_configured'   => static function () {
							return '' !== (string) lafka_setting( 'lafka_review_target_url', '' );
						},
						'settings_path'   => 'customize.php?autofocus[panel]=lafka_reviews',
						'docs_slug'       => 'review-requests',
					)
				)
			);

			// ---- Diagnostics (GX1) — own option, default ON ----
			// Core logging (WooCommerce logs + incident index) is always on;
			// this flag gates the operator surfaces: Lafka → Diagnostics, the
			// Site Health tests and the daily error digest email. Stored in
			// `lafka_log_settings[diagnostics]` ('enabled' / 'disabled'; absent
			// = enabled), read directly so the registry never depends on the
			// observability classes being loaded.
			self::register(
				new Lafka_Module(
					array(
						'id'              => 'diagnostics',
						'label'           => esc_html__( 'Diagnostics', 'lafka-plugin' ),
						'description'     => esc_html__( 'Incident list, "why no order" checkout-failure reasons, Site Health checks and a daily error digest email. Logging to WooCommerce → Status → Logs stays on either way.', 'lafka-plugin' ),
						'category'        => 'operations',
						'storage'         => 'option',
						'default_enabled' => true,
						'get_enabled'     => static function () {
							$settings = get_option( 'lafka_log_settings', array() );
							return ! ( is_array( $settings ) && isset( $settings['diagnostics'] ) && 'disabled' === $settings['diagnostics'] );
						},
						'set_enabled'     => static function ( bool $enabled ) {
							$settings                = get_option( 'lafka_log_settings', array() );
							$settings                = is_array( $settings ) ? $settings : array();
							$settings['diagnostics'] = $enabled ? 'enabled' : 'disabled';
							update_option( 'lafka_log_settings', $settings );
						},
						'settings_path'   => 'admin.php?page=lafka-diagnostics',
						'docs_slug'       => 'diagnostics',
					)
				)
			);

			// ---- Insights (GX2) — first-party funnel analytics, a 'lafka' flag ----
			self::register(
				new Lafka_Module(
					array(
						'id'              => 'insights',
						'label'           => esc_html__( 'Insights', 'lafka-plugin' ),
						'description'     => esc_html__( 'First-party, cookieless funnel analytics: where visitors drop out and why no order was placed, plus a weekly plain-English email. No Google account needed.', 'lafka-plugin' ),
						'category'        => 'analytics',
						'storage'         => 'lafka_option',
						'default_enabled' => false,
						'get_enabled'     => self::flag_getter( 'insights' ),
						'set_enabled'     => self::flag_setter( 'insights' ),
						'settings_path'   => 'admin.php?page=lafka-insights',
						'docs_slug'       => 'insights',
					)
				)
			);

			// ---- Analytics (read-only — derived from configured destinations) ----
			self::register(
				new Lafka_Module(
					array(
						'id'              => 'analytics',
						'label'           => esc_html__( 'Analytics & tracking', 'lafka-plugin' ),
						'description'     => esc_html__( 'GA4 / GTM / Clarity / Meta Pixel with Consent Mode v2. Active whenever a destination is configured.', 'lafka-plugin' ),
						'category'        => 'analytics',
						'storage'         => 'derived',
						'default_enabled' => false,
						'get_enabled'     => static function () {
							return function_exists( 'lafka_analytics_is_active' ) && lafka_analytics_is_active();
						},
						// No set callback: analytics turns on when a destination
						// is configured, so it is read-only in the dashboard.
						'is_configured'   => static function () {
							return function_exists( 'lafka_analytics_is_active' ) && lafka_analytics_is_active();
						},
						'settings_path'   => 'customize.php?autofocus[panel]=lafka_analytics',
						'docs_slug'       => 'analytics',
					)
				)
			);
		}

		// ─── Storage-backend callback factories ─────────────────────────────

		/**
		 * Reader for a feature flag stored in the 'lafka' option array — the
		 * exact source the is_lafka_*() gates in lafka-plugin.php read.
		 *
		 * @return callable():bool
		 */
		private static function flag_getter( string $key ): callable {
			return static function () use ( $key ) {
				return Lafka_Options::is_enabled( $key );
			};
		}

		/**
		 * Writer for a feature flag in the 'lafka' option array. Writes the
		 * same 'enabled'/'disabled' sentinel Lafka_Options::is_enabled() reads,
		 * then busts the request cache so a subsequent read sees the new value.
		 *
		 * @return callable(bool):void
		 */
		private static function flag_setter( string $key ): callable {
			return static function ( bool $enabled ) use ( $key ) {
				$opts = get_option( 'lafka', array() );
				if ( ! is_array( $opts ) ) {
					$opts = array();
				}
				$opts[ $key ] = $enabled ? 'enabled' : 'disabled';
				update_option( 'lafka', $opts );
				if ( class_exists( 'Lafka_Options' ) ) {
					Lafka_Options::flush();
				}
			};
		}

		/**
		 * Reader for a boolean plugin option stored as the '1'/'0' string the
		 * Lafka Customizer panels persist.
		 *
		 * @return callable():bool
		 */
		private static function setting_getter( string $key ): callable {
			return static function () use ( $key ) {
				return '1' === (string) lafka_setting( $key, '0' );
			};
		}

		/**
		 * Writer for a boolean plugin option, matching the Customizer
		 * sanitiser's '1'/'0' contract.
		 *
		 * @return callable(bool):void
		 */
		private static function setting_setter( string $key ): callable {
			return static function ( bool $enabled ) use ( $key ) {
				update_option( $key, $enabled ? '1' : '0' );
			};
		}
	}
}
