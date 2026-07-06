<?php
/**
 * Lafka_Module_Registry — typed registry of gated Lafka feature modules (NX1-01).
 *
 * Before this, the feature flags lived in three unrelated places: the five
 * Lafka_Options flags inside the opaque 'lafka' option array
 * (product_addons / shipping_areas / order_hours / kitchen_display /
 * promotions), the conversion modules self-gating on scattered Customizer
 * theme_mods (abandoned cart / web push / review prompts), and analytics
 * deriving its own "is a destination configured?" answer. A buyer could not
 * see or flip what they owned from one place.
 *
 * This registry is that single place. Each module registers a small typed
 * descriptor — id, i18n label + description, category, default state, and the
 * callbacks that read/write the module's REAL existing storage. It invents no
 * new storage: the five flags still read/write the same 'lafka' array the
 * is_lafka_*() gates read (via Lafka_Options), and the conversion modules
 * still read/write the same theme_mods their Customizer panels persist. So
 * toggling a module here changes exactly the option the current code already
 * reads — zero behaviour change when untouched.
 *
 * Consumers: the Feature Modules dashboard (incl/admin/class-lafka-modules-page.php),
 * Site Health (incl/site-health/class-lafka-site-health.php), and — later —
 * the setup wizard (NX3-01), uninstall cleanup (NX1-06) and the Pro/licensing
 * layer (NX5-03) all read this one list.
 *
 * @package Lafka
 * @since   9.36.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Lafka_Module' ) ) {

	/**
	 * Value object describing a single gated Lafka module.
	 *
	 * Enable/disable, configuration and settings-URL resolution all flow
	 * through callbacks supplied at registration, so the registry never has to
	 * know HOW a given module stores its state — only that it can ask.
	 */
	final class Lafka_Module {

		/** @var string */
		private $id;

		/** @var string i18n label. */
		private $label;

		/** @var string i18n one-line description. */
		private $description;

		/** @var string Grouping slug (ordering / fulfilment / operations / conversion / analytics). */
		private $category;

		/** @var string Where the enable flag lives — 'lafka_option' | 'theme_mod' | 'derived'. */
		private $storage;

		/** @var bool Product default state (documentation / wizard seed). */
		private $default_enabled;

		/** @var callable():bool */
		private $get_enabled_cb;

		/** @var callable(bool):void|null Null == read-only (state is derived, not toggleable). */
		private $set_enabled_cb;

		/** @var callable():bool|null Null == always considered configured. */
		private $is_configured_cb;

		/** @var string Relative admin path to the module's deeper settings (wrapped with admin_url()). */
		private $settings_path;

		/** @var string Docs slug (resolved to a URL via Lafka_Module_Registry::docs_url()). */
		private $docs_slug;

		/**
		 * @param array<string,mixed> $args Descriptor. See register_builtin_modules() for the shape.
		 */
		public function __construct( array $args ) {
			$this->id               = (string) ( $args['id'] ?? '' );
			$this->label            = (string) ( $args['label'] ?? $this->id );
			$this->description      = (string) ( $args['description'] ?? '' );
			$this->category         = (string) ( $args['category'] ?? 'general' );
			$this->storage          = (string) ( $args['storage'] ?? 'derived' );
			$this->default_enabled  = (bool) ( $args['default_enabled'] ?? false );
			$this->get_enabled_cb   = $args['get_enabled'] ?? null;
			$this->set_enabled_cb   = $args['set_enabled'] ?? null;
			$this->is_configured_cb = $args['is_configured'] ?? null;
			$this->settings_path    = (string) ( $args['settings_path'] ?? '' );
			$this->docs_slug        = (string) ( $args['docs_slug'] ?? '' );
		}

		public function get_id(): string {
			return $this->id;
		}

		public function get_label(): string {
			return $this->label;
		}

		public function get_description(): string {
			return $this->description;
		}

		public function get_category(): string {
			return $this->category;
		}

		public function get_storage(): string {
			return $this->storage;
		}

		public function default_enabled(): bool {
			return $this->default_enabled;
		}

		public function get_docs_slug(): string {
			return $this->docs_slug;
		}

		/**
		 * A module is read-only when it has no set callback — its enabled state
		 * is derived from other configuration (e.g. analytics is "on" whenever a
		 * tracking destination is configured), so there is nothing to toggle.
		 */
		public function is_read_only(): bool {
			return ! is_callable( $this->set_enabled_cb );
		}

		/**
		 * Live enabled state, read from the module's real storage.
		 */
		public function is_enabled(): bool {
			return is_callable( $this->get_enabled_cb )
				? (bool) call_user_func( $this->get_enabled_cb )
				: false;
		}

		/**
		 * Write the module's enabled state to its real storage.
		 *
		 * @param bool $enabled Desired state.
		 * @return bool True if the write happened; false for read-only modules.
		 */
		public function set_enabled( bool $enabled ): bool {
			if ( $this->is_read_only() ) {
				return false;
			}
			call_user_func( $this->set_enabled_cb, $enabled );
			return true;
		}

		/**
		 * Whether the module has the extra configuration it needs to actually
		 * function (e.g. push needs VAPID keys). Modules with no extra
		 * requirement are always considered configured.
		 */
		public function is_configured(): bool {
			return is_callable( $this->is_configured_cb )
				? (bool) call_user_func( $this->is_configured_cb )
				: true;
		}

		/**
		 * Absolute admin URL to the module's deeper settings, or '' when the
		 * module has no settings screen beyond the toggle.
		 */
		public function get_settings_url(): string {
			if ( '' === $this->settings_path || ! function_exists( 'admin_url' ) ) {
				return '';
			}
			return admin_url( $this->settings_path );
		}
	}
}

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
		 * @param string $storage 'lafka_option' | 'theme_mod' | 'derived'.
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
			);
			return $labels[ $slug ] ?? ucfirst( $slug );
		}

		/**
		 * Resolve a module's docs slug to a documentation URL.
		 *
		 * Points at the plugin's own public repository by default (not operator
		 * content — safe for the OSS build) and is filterable so the NX5 docs
		 * site can repoint it.
		 */
		public static function docs_url( Lafka_Module $module ): string {
			$slug = $module->get_docs_slug();
			if ( '' === $slug ) {
				return '';
			}
			$url = 'https://github.com/setkernel/lafka-plugin/blob/main/docs/modules/' . $slug . '.md';
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
						'default_enabled' => true,
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
						'is_configured'   => static function () {
							$general = get_option( 'lafka_shipping_areas_general' );
							if ( is_array( $general ) && ! empty( $general['google_maps_api_key'] ) ) {
								return true;
							}
							$key = Lafka_Options::get( 'google_maps_api_key', '' );
							return is_string( $key ) && '' !== $key;
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
						'description'     => esc_html__( 'Full-screen kitchen screen with a live order state machine and customer-facing status tracking.', 'lafka-plugin' ),
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
						'description'     => esc_html__( 'BOGO, delivery-minimum and promo-banner engine that coordinates order discounts.', 'lafka-plugin' ),
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

			// ---- Conversion modules (Customizer theme_mods) ----
			self::register(
				new Lafka_Module(
					array(
						'id'              => 'abandoned_cart',
						'label'           => esc_html__( 'Abandoned cart recovery', 'lafka-plugin' ),
						'description'     => esc_html__( 'Email a one-click resume link when a customer enters their address at checkout but does not finish.', 'lafka-plugin' ),
						'category'        => 'conversion',
						'storage'         => 'theme_mod',
						'default_enabled' => false,
						'get_enabled'     => self::theme_mod_getter( 'lafka_ac_enabled' ),
						'set_enabled'     => self::theme_mod_setter( 'lafka_ac_enabled' ),
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
						'storage'         => 'theme_mod',
						'default_enabled' => false,
						'get_enabled'     => self::theme_mod_getter( 'lafka_push_enabled' ),
						'set_enabled'     => self::theme_mod_setter( 'lafka_push_enabled' ),
						'is_configured'   => static function () {
							$public  = ( defined( 'LAFKA_PUSH_VAPID_PUBLIC_KEY' ) && LAFKA_PUSH_VAPID_PUBLIC_KEY )
								? LAFKA_PUSH_VAPID_PUBLIC_KEY
								: get_theme_mod( 'lafka_push_vapid_public_key', '' );
							$private = ( defined( 'LAFKA_PUSH_VAPID_PRIVATE_KEY' ) && LAFKA_PUSH_VAPID_PRIVATE_KEY )
								? LAFKA_PUSH_VAPID_PRIVATE_KEY
								: get_theme_mod( 'lafka_push_vapid_private_key', '' );
							return '' !== (string) $public && '' !== (string) $private;
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
						'storage'         => 'theme_mod',
						'default_enabled' => false,
						'get_enabled'     => self::theme_mod_getter( 'lafka_review_email_enabled' ),
						'set_enabled'     => self::theme_mod_setter( 'lafka_review_email_enabled' ),
						'is_configured'   => static function () {
							return '' !== (string) get_theme_mod( 'lafka_review_target_url', '' );
						},
						'settings_path'   => 'customize.php?autofocus[panel]=lafka_reviews',
						'docs_slug'       => 'review-requests',
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
		 * Reader for a boolean theme_mod stored as the '1'/'0' string the Lafka
		 * Customizer panels persist.
		 *
		 * @return callable():bool
		 */
		private static function theme_mod_getter( string $key ): callable {
			return static function () use ( $key ) {
				return '1' === (string) get_theme_mod( $key, '0' );
			};
		}

		/**
		 * Writer for a boolean theme_mod, matching the Customizer sanitiser's
		 * '1'/'0' contract.
		 *
		 * @return callable(bool):void
		 */
		private static function theme_mod_setter( string $key ): callable {
			return static function ( bool $enabled ) use ( $key ) {
				set_theme_mod( $key, $enabled ? '1' : '0' );
			};
		}
	}
}
