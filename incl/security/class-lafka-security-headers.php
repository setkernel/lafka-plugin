<?php
/**
 * Lafka_Security_Headers — frontend security-header middleware + user-enum hardening.
 *
 * Ships four response headers on frontend requests, removes the default
 * `wp/v2/users` REST endpoints, and redirects unauthenticated `?author=N`
 * probes back to the home page.
 *
 * Gating: opt-in only — set the `enable_security_headers` Lafka option to
 * `'enabled'` to activate. Headers stay off by default to avoid breaking
 * iframe embeds (X-Frame-Options) or REST consumers on existing sites.
 *
 * @package Lafka
 * @since   8.7.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Lafka_Security_Headers' ) ) {

	final class Lafka_Security_Headers {

		const TOGGLE_OPTION_KEY = 'enable_security_headers';

		/**
		 * Dedicated option array for the security-headers toggle. Stored separately
		 * from the main `lafka` option because the theme's options-framework
		 * `register_setting('lafka', ...)` sanitize callback would otherwise drop
		 * unregistered keys on save (caught during P2-05a admin-UI smoke test).
		 *
		 * Backwards compat: also reads `lafka['enable_security_headers']` if the
		 * dedicated option is absent — so existing WP-CLI users who set the flag
		 * via `wp option patch update lafka enable_security_headers enabled`
		 * still get the right behavior until they re-save through the admin UI.
		 */
		const OPTION_KEY = 'lafka_security_options';

		/** @var Lafka_Security_Headers|null */
		private static $instance = null;

		public static function instance() {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		private function __construct() {
			if ( ! $this->is_active() ) {
				return;
			}
			add_action( 'send_headers', array( $this, 'send_security_headers' ) );
			add_filter( 'rest_endpoints', array( $this, 'disable_user_enum_rest' ) );
			add_action( 'template_redirect', array( $this, 'block_author_enum' ) );
		}

		/**
		 * Resolve whether the module is currently enabled.
		 *
		 * Order of precedence:
		 *   1. Dedicated option `lafka_security_options['enable_security_headers']`
		 *      — what the admin UI writes to.
		 *   2. Back-compat: `lafka['enable_security_headers']` — what early
		 *      WP-CLI adopters may have set before P2-05a moved storage out of
		 *      the main option array.
		 *   3. Otherwise the install-time default returned by {@see should_default_on()}.
		 */
		public function is_active() {
			$opts     = get_option( self::OPTION_KEY, array() );
			$override = is_array( $opts ) && isset( $opts[ self::TOGGLE_OPTION_KEY ] ) ? $opts[ self::TOGGLE_OPTION_KEY ] : '';
			if ( '' === $override ) {
				// Fall back to the legacy storage location.
				$override = Lafka_Options::get( self::TOGGLE_OPTION_KEY, '' );
			}
			if ( 'enabled' === $override ) {
				return true;
			}
			if ( 'disabled' === $override ) {
				return false;
			}
			return self::should_default_on();
		}

		/**
		 * Resolve the install-time default for a site that has never set the
		 * `enable_security_headers` toggle.
		 *
		 * Decision (P2-05): opt-in only — return false. Production sites must
		 * flip the toggle explicitly. Rationale: `X-Frame-Options: SAMEORIGIN`
		 * can break legitimate iframe embeds (Stripe, payment-gateway returns,
		 * embedded previews); silently enabling on upgrade is a worse failure
		 * mode than leaving headers off until an admin acknowledges them.
		 *
		 * Operator: enable on a site with WP-CLI:
		 *   `wp option patch update lafka enable_security_headers enabled`
		 */
		public static function should_default_on() {
			return false;
		}

		public function send_security_headers() {
			if ( is_admin() ) {
				return;
			}
			if ( headers_sent() ) {
				return;
			}
			// Strip the version-disclosing `X-Powered-By: PHP/...` header that PHP
			// or `expose_php = On` adds. Apache's `ServerTokens Prod` setting is
			// the right place to suppress the `Server:` header — we can't reach
			// that from PHP — so this is a partial fix; document the Apache side
			// in COMPATIBILITY.md.
			header_remove( 'X-Powered-By' );
			header( 'X-Content-Type-Options: nosniff' );
			header( 'X-Frame-Options: SAMEORIGIN' );
			header( 'Referrer-Policy: strict-origin-when-cross-origin' );
			header( 'Permissions-Policy: interest-cohort=()' );
		}

		/**
		 * Remove the user-enumeration REST endpoints. Logged-in users with
		 * `list_users` keep access via the admin-only `users.php` screen.
		 */
		public function disable_user_enum_rest( $endpoints ) {
			if ( isset( $endpoints['/wp/v2/users'] ) ) {
				unset( $endpoints['/wp/v2/users'] );
			}
			if ( isset( $endpoints['/wp/v2/users/(?P<id>[\d]+)'] ) ) {
				unset( $endpoints['/wp/v2/users/(?P<id>[\d]+)'] );
			}
			return $endpoints;
		}

		/**
		 * Block `/?author=N` enumeration on the front-end. Logged-in users keep
		 * access (so the author-screen edit-link still works in admin previews).
		 */
		public function block_author_enum() {
			if ( is_user_logged_in() ) {
				return;
			}
			if ( ! is_author() && ! isset( $_GET['author'] ) ) {
				return;
			}
			wp_safe_redirect( home_url( '/' ), 301 );
			exit;
		}
	}

	Lafka_Security_Headers::instance();
}
