<?php
/**
 * Lafka_Site_Health — surface Lafka diagnostics in WP Admin → Tools → Site Health.
 *
 * Two integrations:
 *
 *   1. `debug_information` filter — adds a "Lafka" section to the Info tab
 *      with feature-flag state, version numbers, dependency presence, and
 *      cache state. This is the read-only diagnostic surface ops people
 *      copy-paste when reporting bugs.
 *
 *   2. `site_status_tests` filter — registers a couple of conditional
 *      health checks (security headers enabled? promotions module owns BOGO?).
 *      Each check shows up in the Status tab as a recommendation when not met.
 *
 * @package Lafka
 * @since   8.7.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Lafka_Site_Health' ) ) {

	final class Lafka_Site_Health {

		/** @var Lafka_Site_Health|null */
		private static $instance = null;

		public static function instance() {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		private function __construct() {
			add_filter( 'debug_information', array( $this, 'add_debug_information' ) );
			add_filter( 'site_status_tests', array( $this, 'add_status_tests' ) );
		}

		/**
		 * Add a "Lafka" section to the Site Health Info tab.
		 */
		public function add_debug_information( $info ) {
			$plugin_data = function_exists( 'get_plugin_data' )
				? get_plugin_data( LAFKA_PLUGIN_FILE, false, false )
				: array( 'Version' => 'unknown' );

			$theme    = function_exists( 'wp_get_theme' ) ? wp_get_theme() : null;
			$child    = function_exists( 'wp_get_theme' ) && is_child_theme() ? wp_get_theme() : null;
			$parent   = $theme && $theme->parent() ? $theme->parent() : $theme;

			$lafka_options = get_option( 'lafka', array() );

			$fields = array(
				'plugin_version' => array(
					'label' => esc_html__( 'Plugin version', 'lafka-plugin' ),
					'value' => isset( $plugin_data['Version'] ) ? $plugin_data['Version'] : 'unknown',
				),
				'theme_version'  => array(
					'label' => esc_html__( 'Parent theme', 'lafka-plugin' ),
					'value' => $parent
						? sprintf( '%s %s', $parent->get( 'Name' ), $parent->get( 'Version' ) )
						: esc_html__( 'Not active', 'lafka-plugin' ),
				),
				'child_version'  => array(
					'label' => esc_html__( 'Child theme', 'lafka-plugin' ),
					'value' => $child
						? sprintf( '%s %s', $child->get( 'Name' ), $child->get( 'Version' ) )
						: esc_html__( 'Not active', 'lafka-plugin' ),
				),
			);

			// Feature-flag rows are driven by the module registry (NX1-01) so
			// this diagnostic panel and the Lafka → Modules dashboard can never
			// drift. Only the 'lafka'-option flags are listed here (the same
			// five flags shown before); each keeps its exact value formatting
			// (Enabled / Disabled (explicit) / Disabled (default)).
			foreach ( $this->registry_flag_fields() as $key => $field ) {
				$fields[ $key ] = $field;
			}

			$fields['security_headers'] = array(
				'label' => esc_html__( 'Security headers', 'lafka-plugin' ),
				'value' => $this->flag_label( 'enable_security_headers' ),
			);
			$fields['wc_active']        = array(
				'label' => esc_html__( 'WooCommerce', 'lafka-plugin' ),
				'value' => defined( 'LAFKA_PLUGIN_IS_WOOCOMMERCE' ) && LAFKA_PLUGIN_IS_WOOCOMMERCE
					? esc_html__( 'Active', 'lafka-plugin' )
					: esc_html__( 'Inactive', 'lafka-plugin' ),
			);
			$fields['options_count']    = array(
				'label' => esc_html__( 'Stored options', 'lafka-plugin' ),
				'value' => is_array( $lafka_options ) ? (string) count( $lafka_options ) : '0',
			);
			$fields['object_cache']     = array(
				'label' => esc_html__( 'Persistent object cache', 'lafka-plugin' ),
				'value' => wp_using_ext_object_cache()
					? esc_html__( 'Yes (transients used for short-TTL caches)', 'lafka-plugin' )
					: esc_html__( 'No (using DB transients only)', 'lafka-plugin' ),
			);

			$info['lafka'] = array(
				'label'  => esc_html__( 'Lafka', 'lafka-plugin' ),
				'fields' => $fields,
			);

			return $info;
		}

		/**
		 * Add Lafka-specific health checks to the Status tab.
		 */
		public function add_status_tests( $tests ) {
			$tests['direct']['lafka_security_headers'] = array(
				'label' => esc_html__( 'Lafka security headers', 'lafka-plugin' ),
				'test'  => array( $this, 'test_security_headers' ),
			);
			return $tests;
		}

		/**
		 * Recommendation: enable the security-headers module if not already.
		 *
		 * Pre-v9.7.16 this checked only the legacy `Lafka_Options::get('enable_security_headers')`
		 * storage. After v8.7.0 the canonical storage moved to the dedicated
		 * `lafka_security_options` array (see Lafka_Security_Headers::OPTION_KEY)
		 * because the theme's options-framework register_setting('lafka', ...)
		 * sanitize callback drops unregistered keys. So sites that flipped the
		 * toggle via the admin UI (the canonical write path) showed "not
		 * enabled" in Site Health — false negative.
		 *
		 * Now delegates to is_active() which is the single source of truth for
		 * the resolution chain (dedicated → legacy → default).
		 */
		public function test_security_headers() {
			$enabled = class_exists( 'Lafka_Security_Headers' )
				&& Lafka_Security_Headers::instance()->is_active();

			if ( $enabled ) {
				return array(
					'label'       => esc_html__( 'Lafka security headers are enabled', 'lafka-plugin' ),
					'status'      => 'good',
					'badge'       => array(
						'label' => esc_html__( 'Security', 'lafka-plugin' ),
						'color' => 'green',
					),
					'description' => '<p>' . esc_html__(
						'X-Content-Type-Options, X-Frame-Options, Referrer-Policy and Permissions-Policy are being sent on frontend requests, plus the wp/v2/users REST endpoint is hidden.',
						'lafka-plugin'
					) . '</p>',
					'test'        => 'lafka_security_headers',
				);
			}

			return array(
				'label'       => esc_html__( 'Lafka security headers are not enabled', 'lafka-plugin' ),
				'status'      => 'recommended',
				'badge'       => array(
					'label' => esc_html__( 'Security', 'lafka-plugin' ),
					'color' => 'orange',
				),
				'description' => '<p>' . esc_html__(
					'The Lafka security-headers module ships dormant by default to avoid breaking iframe embeds (Stripe / payment gateway returns) on existing sites. To enable it: WP-CLI `wp option patch update lafka enable_security_headers enabled`. Disabling later: same command with `disabled`.',
					'lafka-plugin'
				) . '</p>',
				'actions'     => '<p><a href="https://github.com/setkernel/lafka-plugin/blob/main/incl/security/class-lafka-security-headers.php" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Read more about the module', 'lafka-plugin' ) . '</a></p>',
				'test'        => 'lafka_security_headers',
			);
		}

		/**
		 * Format a feature-flag value as a human-readable label.
		 *
		 * The `enable_security_headers` flag lives in the dedicated
		 * `lafka_security_options` array (see Lafka_Security_Headers::OPTION_KEY)
		 * — falls back to the main `lafka` option for back-compat with
		 * pre-P2-05a-fix installs.
		 */
		private function flag_label( $key ) {
			if ( 'enable_security_headers' === $key && class_exists( '\Lafka_Security_Headers' ) ) {
				$opts  = get_option( \Lafka_Security_Headers::OPTION_KEY, array() );
				$value = is_array( $opts ) && isset( $opts[ \Lafka_Security_Headers::TOGGLE_OPTION_KEY ] )
					? $opts[ \Lafka_Security_Headers::TOGGLE_OPTION_KEY ]
					: \Lafka_Options::get( $key, '' );
			} else {
				$value = \Lafka_Options::get( $key, '' );
			}
			if ( 'enabled' === $value ) {
				return esc_html__( 'Enabled', 'lafka-plugin' );
			}
			if ( 'disabled' === $value ) {
				return esc_html__( 'Disabled (explicit)', 'lafka-plugin' );
			}
			return esc_html__( 'Disabled (default)', 'lafka-plugin' );
		}

		/**
		 * Build the feature-flag debug rows from the module registry (NX1-01).
		 *
		 * Enumerates exactly the modules whose enable flag lives in the 'lafka'
		 * option array — the same five gates this panel used to hand-list —
		 * keeping each row's value formatting identical via flag_label().
		 *
		 * @return array<string,array{label:string,value:string}>
		 */
		private function registry_flag_fields() {
			if ( ! class_exists( 'Lafka_Module_Registry' ) ) {
				return array();
			}
			$fields = array();
			foreach ( \Lafka_Module_Registry::modules_by_storage( 'lafka_option' ) as $id => $module ) {
				$fields[ $id ] = array(
					'label' => $module->get_label(),
					'value' => $this->flag_label( $id ),
				);
			}
			return $fields;
		}
	}

	if ( function_exists( 'is_admin' ) && is_admin() ) {
		Lafka_Site_Health::instance();
	}
}
