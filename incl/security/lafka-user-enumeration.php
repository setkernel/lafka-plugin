<?php
/**
 * GX (T-27): close user enumeration for anonymous visitors, on by default.
 *
 * Two public doors list the site's user logins, which are half of every
 * brute-force login attempt:
 *
 *  - `/?author=N` — core redirects it to /author/<login>/. For a visitor who
 *    is not logged in it now answers a plain 404 (the redirect never runs:
 *    this hooks template_redirect at priority 0, before core's canonical
 *    redirect at 10). Filter `lafka_author_enumeration_response` → 'home'
 *    sends the probe to the home page instead.
 *  - `/wp-json/wp/v2/users` (list and single) — answers 401 to anonymous
 *    requests. Logged-in users (the block editor's author picker) keep full
 *    access; no other route is touched.
 *
 * Independent of the opt-in security-headers module (which removes the users
 * routes for everyone). Filter `lafka_restrict_user_enumeration` (bool,
 * default true) turns both off.
 *
 * @package Lafka
 * @since   10.3.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'lafka_restrict_user_enumeration' ) ) {
	/**
	 * Whether anonymous user enumeration is blocked.
	 *
	 * @return bool
	 */
	function lafka_restrict_user_enumeration(): bool {
		/**
		 * Filter whether anonymous visitors are kept from enumerating users.
		 *
		 * @since 10.3.0
		 * @param bool $restrict Default true.
		 */
		return (bool) apply_filters( 'lafka_restrict_user_enumeration', true );
	}
}

if ( ! function_exists( 'lafka_block_author_enumeration' ) ) {
	/**
	 * `template_redirect` (priority 0): answer an anonymous `?author=N` probe
	 * with a 404 (or a redirect home) before core reveals the login.
	 *
	 * @return void
	 */
	function lafka_block_author_enumeration() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- presence check of a public query arg; no state change.
		if ( ! isset( $_GET['author'] ) || is_admin() || is_user_logged_in() || ! lafka_restrict_user_enumeration() ) {
			return;
		}

		/**
		 * Filter how an anonymous `?author=N` probe is answered.
		 *
		 * @since 10.3.0
		 * @param string $response '404' (default) or 'home' (301 to the home page).
		 */
		if ( 'home' === (string) apply_filters( 'lafka_author_enumeration_response', '404' ) ) {
			wp_safe_redirect( home_url( '/' ), 301, 'Lafka' );
			exit;
		}

		global $wp_query;
		if ( is_object( $wp_query ) && method_exists( $wp_query, 'set_404' ) ) {
			$wp_query->set_404();
		}
		status_header( 404 );
		nocache_headers();
	}
}

if ( ! function_exists( 'lafka_restrict_users_endpoint' ) ) {
	/**
	 * `rest_pre_dispatch`: 401 for anonymous requests to /wp/v2/users[/…].
	 *
	 * @param mixed  $result  Earlier short-circuit result (null = none).
	 * @param mixed  $server  WP_REST_Server.
	 * @param object $request WP_REST_Request.
	 * @return mixed
	 */
	function lafka_restrict_users_endpoint( $result, $server, $request ) {
		if ( null !== $result || ! is_object( $request ) || ! method_exists( $request, 'get_route' ) ) {
			return $result;
		}
		// Case-insensitive like the REST server's own route matching.
		if ( ! preg_match( '#^/wp/v2/users(?:/|$)#i', (string) $request->get_route() ) ) {
			return $result;
		}
		if ( is_user_logged_in() || ! lafka_restrict_user_enumeration() ) {
			return $result;
		}
		return new WP_Error(
			'rest_user_cannot_view',
			__( 'Sorry, you are not allowed to list users.', 'lafka-plugin' ),
			array( 'status' => 401 )
		);
	}
}

add_action( 'template_redirect', 'lafka_block_author_enumeration', 0 );
add_filter( 'rest_pre_dispatch', 'lafka_restrict_users_endpoint', 10, 3 );
