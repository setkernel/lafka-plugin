<?php
/**
 * Lafka_Insights — first-party funnel analytics (GX2), module gate + bootstrap.
 *
 * Module id `insights` in Lafka_Module_Registry, default OFF, stored in the
 * 'lafka' option array like Promotions (Lafka → Modules flips it). When on:
 *
 *   - Collection (B1): assets/js/lafka-insights.js consumes the existing
 *     dataLayer events and sends ONE sendBeacon per page to POST
 *     /wp-json/lafka/v1/i (Lafka_Insights_Collector). Money events (cart,
 *     checkout, payment, order, payment failure, checkout refusals) are
 *     recorded server-side from Woo hooks (Lafka_Insights_Server_Events), so
 *     ad-blockers and page caches cannot hide them. Visits are stitched with
 *     a cookieless, daily-rotating pseudonym (Lafka_Insights_Session).
 *   - Storage (B2): two tables (Lafka_Insights_DB), a nightly Action Scheduler
 *     rollup + prune + secret rotation (Lafka_Insights_Rollup).
 *   - Admin (B3): Lafka → Insights (Lafka_Insights_Page).
 *   - Weekly owner email (B4): Lafka_Email_Weekly_Insights.
 *   - Consent (B5): three modes in Customizer → Lafka — Analytics → Insights.
 *
 * Insights counts as a dataLayer destination
 * (lafka_analytics_has_datalayer_destination()), so the existing dataLayer
 * emitters run on sites that configured no GA4 / GTM.
 *
 * @package Lafka\Plugin\Insights
 * @since   10.2.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Lafka_Insights' ) ) {

	final class Lafka_Insights {

		/** Registry id + key in the 'lafka' option array. */
		const MODULE = 'insights';

		/** Consent modes (theme_mod `lafka_insights_consent_mode`). */
		const MODE_AGGREGATE = 'aggregate';
		const MODE_CONSENT   = 'consent_required';
		const MODE_OFF       = 'off';

		/** First-party cookie the consent banner mirrors analytics consent into. */
		const CONSENT_COOKIE = 'lafka_consent';

		/** First local day (Y-m-d) of the current collection period. */
		const SINCE_OPTION = 'lafka_insights_collecting_since';

		/** REST namespace shared with the other lafka/v1 routes. */
		const REST_NS = 'lafka/v1';

		/** Action Scheduler group + hooks. */
		const AS_GROUP     = 'lafka-insights';
		const NIGHTLY_HOOK = 'lafka_insights_nightly';
		const WEEKLY_HOOK  = 'lafka_insights_weekly_email';

		/** @var bool */
		private static $booted = false;

		/** @var bool True once boot() found the module enabled (this request). */
		private static $active = false;

		/**
		 * Whether the operator turned the module on (Lafka → Modules).
		 *
		 * @return bool
		 */
		public static function is_enabled(): bool {
			return class_exists( 'Lafka_Options' ) && Lafka_Options::is_enabled( self::MODULE );
		}

		/**
		 * Whether the module booted enabled in this request. The procedural
		 * gates below check this first, so code paths that never booted the
		 * module (a disabled install, isolated callers) cost nothing.
		 *
		 * @return bool
		 */
		public static function is_active(): bool {
			return self::$active;
		}

		/**
		 * The consent mode: aggregate (default) | consent_required | off.
		 * Filterable via `lafka_insights_consent_mode`.
		 *
		 * @return string
		 */
		public static function consent_mode(): string {
			$mode = function_exists( 'get_theme_mod' ) ? get_theme_mod( 'lafka_insights_consent_mode', self::MODE_AGGREGATE ) : self::MODE_AGGREGATE;
			if ( function_exists( 'apply_filters' ) ) {
				$mode = apply_filters( 'lafka_insights_consent_mode', $mode );
			}
			return self::sanitize_consent_mode( $mode );
		}

		/**
		 * Normalise a consent-mode value; unknown input falls back to aggregate.
		 *
		 * @param mixed $value Raw value.
		 * @return string
		 */
		public static function sanitize_consent_mode( $value ): string {
			$value = is_scalar( $value ) ? strtolower( trim( (string) $value ) ) : '';
			return in_array( $value, array( self::MODE_AGGREGATE, self::MODE_CONSENT, self::MODE_OFF ), true ) ? $value : self::MODE_AGGREGATE;
		}

		/**
		 * True when the module is on AND its consent mode collects anything.
		 * This is what makes Insights a dataLayer destination.
		 *
		 * @return bool
		 */
		public static function is_collecting(): bool {
			return self::is_enabled() && self::MODE_OFF !== self::consent_mode();
		}

		/**
		 * Wire the module. Called once from lafka-plugin.php. Loads the heavy
		 * files only when the module is on, so a default install pays nothing.
		 *
		 * @return void
		 */
		public static function boot(): void {
			if ( self::$booted ) {
				return;
			}
			self::$booted = true;

			// Module flips on/off from Lafka → Modules: (un)schedule the jobs then.
			add_action( 'update_option_lafka', array( __CLASS__, 'on_flags_changed' ), 10, 2 );
			add_action( 'add_option_lafka', array( __CLASS__, 'on_flags_added' ), 10, 2 );

			// Lafka → Insights is registered even while the module is off, so the
			// Modules card's "Settings" link explains how to switch it on.
			if ( function_exists( 'is_admin' ) && is_admin() ) {
				require_once dirname( __DIR__ ) . '/admin/class-lafka-insights-page.php';
				Lafka_Insights_Page::instance();
			}

			if ( ! self::is_enabled() ) {
				return;
			}
			self::$active = true;
			self::load();

			add_action( 'plugins_loaded', array( 'Lafka_Insights_DB', 'maybe_install' ), 20 );
			add_action( self::NIGHTLY_HOOK, array( 'Lafka_Insights_Rollup', 'run_nightly' ) );
			add_action( self::WEEKLY_HOOK, array( __CLASS__, 'send_weekly_email' ) );
			add_action( 'admin_init', array( 'Lafka_Insights_Scheduler', 'ensure_scheduled' ) );
			add_action( 'admin_init', array( __CLASS__, 'add_privacy_policy_content' ) );
			add_filter( 'woocommerce_email_classes', array( __CLASS__, 'register_email_class' ) );
			add_filter( 'wc_order_attribution_allow_tracking', array( __CLASS__, 'filter_order_attribution_tracking' ) );

			if ( ! self::is_collecting() ) {
				return;
			}
			add_action( 'rest_api_init', array( 'Lafka_Insights_Collector', 'register_routes' ) );
			add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_script' ), 20 );
			Lafka_Insights_Server_Events::register();
		}

		/**
		 * Require the module's files.
		 *
		 * @return void
		 */
		public static function load(): void {
			$dir = __DIR__;
			require_once dirname( $dir ) . '/class-lafka-beacon-guard.php';
			require_once $dir . '/class-lafka-insights-db.php';
			require_once $dir . '/class-lafka-insights-session.php';
			require_once $dir . '/class-lafka-insights-collector.php';
			require_once $dir . '/class-lafka-insights-server-events.php';
			require_once $dir . '/class-lafka-insights-rollup.php';
			require_once $dir . '/class-lafka-insights-scheduler.php';
			require_once $dir . '/class-lafka-insights-queries.php';
			require_once $dir . '/class-lafka-insights-narrative.php';
		}

		/**
		 * `update_option_lafka`: schedule the jobs when the module was just
		 * enabled, drop them when it was just disabled.
		 *
		 * @param mixed $old Previous 'lafka' array.
		 * @param mixed $new New 'lafka' array.
		 * @return void
		 */
		public static function on_flags_changed( $old, $new ): void {
			$was = is_array( $old ) && 'enabled' === ( $old[ self::MODULE ] ?? '' );
			$now = is_array( $new ) && 'enabled' === ( $new[ self::MODULE ] ?? '' );
			if ( $was === $now ) {
				return;
			}
			self::load();
			if ( $now ) {
				// A new collection period starts today: numbers that combine visits
				// with orders never reach back into the time Insights was off.
				update_option( self::SINCE_OPTION, Lafka_Insights_Session::today(), false );
				Lafka_Insights_DB::install();
				Lafka_Insights_Scheduler::ensure_scheduled();
			} else {
				Lafka_Insights_Scheduler::unschedule_all();
			}
		}

		/**
		 * First local day (Y-m-d) of the current collection period. Installs that
		 * switched Insights on before this was recorded fall back to the first
		 * day with data (then persisted), else today.
		 *
		 * @return string
		 */
		public static function collecting_since(): string {
			$since = (string) get_option( self::SINCE_OPTION, '' );
			if ( 1 === preg_match( '/^\d{4}-\d{2}-\d{2}$/', $since ) ) {
				return $since;
			}
			$since = class_exists( 'Lafka_Insights_DB' ) ? Lafka_Insights_DB::first_day() : '';
			if ( '' === $since ) {
				return Lafka_Insights_Session::today();
			}
			update_option( self::SINCE_OPTION, $since, false );
			return $since;
		}

		/**
		 * `add_option_lafka` (first save on a fresh site).
		 *
		 * @param string $option Option name.
		 * @param mixed  $value  Value.
		 * @return void
		 */
		public static function on_flags_added( $option, $value ): void {
			self::on_flags_changed( array(), $value );
		}

		/**
		 * Enqueue the collector script (front end only; never admin, KDS, the
		 * Customizer preview, or staff who can manage the store).
		 *
		 * @return void
		 */
		public static function enqueue_script(): void {
			if ( ! self::should_load_front_script() ) {
				return;
			}
			$rel     = function_exists( 'lafka_plugin_script_path' ) ? lafka_plugin_script_path( 'assets/js/lafka-insights.min.js' ) : 'assets/js/lafka-insights.min.js';
			$version = function_exists( 'lafka_plugin_asset_version' ) ? lafka_plugin_asset_version( $rel ) : '10.2.0';
			wp_enqueue_script(
				'lafka-insights',
				plugins_url( $rel, LAFKA_PLUGIN_FILE ),
				array(),
				$version,
				array(
					'in_footer' => true,
					'strategy'  => 'defer',
				)
			);
			wp_add_inline_script( 'lafka-insights', 'window.lafkaInsightsCfg=' . wp_json_encode( self::script_config() ) . ';', 'before' );
		}

		/**
		 * Whether this front-end request should carry the collector script.
		 *
		 * @return bool
		 */
		public static function should_load_front_script(): bool {
			if ( ( function_exists( 'is_admin' ) && is_admin() ) || ( function_exists( 'is_customize_preview' ) && is_customize_preview() ) ) {
				return false;
			}
			if ( function_exists( 'get_query_var' ) && '' !== (string) get_query_var( 'lafka_kds_token', '' ) ) {
				return false;
			}
			if ( class_exists( 'Lafka_Insights_Session' ) && Lafka_Insights_Session::is_excluded_user() ) {
				return false;
			}
			return self::is_collecting();
		}

		/**
		 * The config handed to lafka-insights.js. Identical for every anonymous
		 * visitor (page-cache safe); logged-in visitors get a wp_rest nonce so
		 * the endpoint can see who they are (and drop staff).
		 *
		 * @return array<string,string>
		 */
		public static function script_config(): array {
			$url   = function_exists( 'rest_url' ) ? (string) rest_url( self::REST_NS . '/i' ) : '';
			$nonce = ( function_exists( 'is_user_logged_in' ) && is_user_logged_in() && function_exists( 'wp_create_nonce' ) )
				? (string) wp_create_nonce( 'wp_rest' )
				: '';
			return array(
				'u' => $url,
				'm' => self::MODE_CONSENT === self::consent_mode() ? 'c' : 'a',
				'n' => $nonce,
			);
		}

		/**
		 * `wc_order_attribution_allow_tracking`: in consent_required mode Woo's
		 * sourcebuster cookies wait for consent too — tracking starts disabled
		 * (page-cache safe) and the consent banner switches it on through
		 * `wc_order_attribution.setOrderTracking(true)` once analytics is granted.
		 *
		 * @param mixed $allow Current value.
		 * @return bool
		 */
		public static function filter_order_attribution_tracking( $allow ): bool {
			if ( self::MODE_CONSENT === self::consent_mode() ) {
				return false;
			}
			return (bool) $allow;
		}

		/**
		 * `woocommerce_email_classes`: register the weekly owner email (lazy —
		 * WC_Email only exists once WooCommerce has booted).
		 *
		 * @param mixed $classes Registered email classes.
		 * @return array
		 */
		public static function register_email_class( $classes ): array {
			$classes = is_array( $classes ) ? $classes : array();
			if ( ! class_exists( 'WC_Email' ) ) {
				return $classes;
			}
			require_once __DIR__ . '/class-lafka-email-weekly-insights.php';
			if ( class_exists( 'Lafka_Email_Weekly_Insights' ) ) {
				$classes['Lafka_Email_Weekly_Insights'] = new Lafka_Email_Weekly_Insights();
			}
			return $classes;
		}

		/**
		 * Weekly job: build last week's report and hand it to the WC email.
		 * Always re-schedules the next Monday first, so a failure never ends
		 * the chain.
		 *
		 * @return void
		 */
		public static function send_weekly_email(): void {
			if ( ! self::is_enabled() ) {
				return;
			}
			Lafka_Insights_Scheduler::schedule_weekly();
			if ( ! self::is_collecting() ) {
				return; // Consent mode "off": an empty week would falsely read as broken tracking.
			}
			if ( function_exists( 'WC' ) && WC() && method_exists( WC(), 'mailer' ) ) {
				WC()->mailer(); // Instantiates the email classes (binds the trigger).
			}
			$report = Lafka_Insights_Queries::report( 7, true );
			do_action( 'lafka_insights_weekly_email_trigger', $report );
		}

		/**
		 * Privacy-policy guide text (Settings → Privacy → Policy guide).
		 *
		 * @return void
		 */
		public static function add_privacy_policy_content(): void {
			if ( ! function_exists( 'wp_add_privacy_policy_content' ) ) {
				return;
			}
			wp_add_privacy_policy_content( __( 'Lafka Insights', 'lafka-plugin' ), wp_kses_post( wpautop( self::privacy_policy_text(), false ) ) );
		}

		/**
		 * Suggested privacy-policy wording for the active consent mode.
		 *
		 * @return string
		 */
		public static function privacy_policy_text(): string {
			$text = __( 'This site measures how visitors use the online menu and checkout (pages viewed, items added to the cart, checkout steps reached, and why an order could not be placed) with a first-party tool built into the site. No data is sent to third parties.', 'lafka-plugin' ) . "\n\n"
				. __( 'Visits are counted without cookies. To tell one visit from another on the same day, the site combines your IP address and browser type with a secret key that changes every day and is then deleted; your IP address is never stored and the result cannot be linked back to you or to your visits on other days. Only the page type, device class (phone, tablet or desktop), the referring website and campaign tags, and the steps reached are kept, for at most 35 days; daily totals without any identifier are kept for up to 25 months.', 'lafka-plugin' ) . "\n\n"
				. __( 'Because no personal data is stored, there is nothing to export or erase for a data request. Browsers that send a Global Privacy Control or Do Not Track signal are not measured.', 'lafka-plugin' );
			if ( self::MODE_CONSENT === self::consent_mode() ) {
				$text .= "\n\n" . __( 'Measurement only starts after you allow analytics in the cookie banner; your choice is remembered in a first-party cookie named lafka_consent.', 'lafka-plugin' );
			}
			return $text;
		}
	}
}

if ( ! function_exists( 'lafka_insights_is_collecting' ) ) {
	/**
	 * Procedural gate for the analytics destination check.
	 *
	 * @return bool
	 */
	function lafka_insights_is_collecting(): bool {
		return Lafka_Insights::is_active() && Lafka_Insights::is_collecting();
	}
}

if ( ! function_exists( 'lafka_insights_needs_consent_banner' ) ) {
	/**
	 * True when Insights itself requires the consent banner (consent_required mode).
	 *
	 * @return bool
	 */
	function lafka_insights_needs_consent_banner(): bool {
		return lafka_insights_is_collecting() && Lafka_Insights::MODE_CONSENT === Lafka_Insights::consent_mode();
	}
}
