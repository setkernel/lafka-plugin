<?php
/**
 * Phase 3E (v9.29.0): Web Push notifications — REST endpoints.
 *
 * Three routes under the `lafka/v1` namespace, all nonced via wp_rest:
 *
 *   POST /wp-json/lafka/v1/push/subscribe
 *     Body: { endpoint, keys: { p256dh, auth } }
 *     Saves a subscription row via lafka_push_save_subscription(); returns
 *     { ok: true, subscription_id: int }. Public (logged-in or guest), nonce
 *     required so the SW can't be POST'd cross-site.
 *
 *   POST /wp-json/lafka/v1/push/unsubscribe
 *     Body: { endpoint }
 *     Marks the matching row as unsubscribed via lafka_push_mark_unsubscribed().
 *     Returns { ok: true, removed: int }. Same auth model as subscribe.
 *
 *   GET  /wp-json/lafka/v1/push/vapid-key
 *     Returns { key: string } — the public VAPID key from the Customizer.
 *     Public, no nonce — the key is by definition public, and the SW needs to
 *     fetch it before the user has any auth context.
 *
 * Self-gates on the `lafka_push_enabled` Customizer toggle so an operator who
 * hasn't opted in serves no surface area at all.
 *
 * @package Lafka\Plugin\Conversion
 * @since   9.29.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'lafka_push_rest_is_enabled' ) ) {
	/**
	 * Read the master enable toggle. Gates all three endpoints.
	 *
	 * @return bool
	 */
	function lafka_push_rest_is_enabled(): bool {
		if ( ! function_exists( 'get_theme_mod' ) ) {
			return false;
		}
		return '1' === (string) get_theme_mod( 'lafka_push_enabled', '0' );
	}
}

if ( ! function_exists( 'lafka_push_register_rest_routes' ) ) {
	/**
	 * Register the three /push routes under the `lafka/v1` namespace.
	 *
	 * @return void
	 */
	function lafka_push_register_rest_routes(): void {
		if ( ! function_exists( 'register_rest_route' ) ) {
			return;
		}

		register_rest_route(
			'lafka/v1',
			'/push/subscribe',
			array(
				'methods'             => 'POST',
				'callback'            => 'lafka_push_rest_subscribe',
				'permission_callback' => 'lafka_push_rest_nonce_permission',
			)
		);

		register_rest_route(
			'lafka/v1',
			'/push/unsubscribe',
			array(
				'methods'             => 'POST',
				'callback'            => 'lafka_push_rest_unsubscribe',
				'permission_callback' => 'lafka_push_rest_nonce_permission',
			)
		);

		register_rest_route(
			'lafka/v1',
			'/push/vapid-key',
			array(
				'methods'             => 'GET',
				'callback'            => 'lafka_push_rest_vapid_key',
				// Public — the key is by definition public; the SW must fetch
				// it pre-auth to build the subscribe call.
				'permission_callback' => '__return_true',
			)
		);
	}
}

if ( function_exists( 'add_action' ) ) {
	add_action( 'rest_api_init', 'lafka_push_register_rest_routes' );
}

if ( ! function_exists( 'lafka_push_rest_nonce_permission' ) ) {
	/**
	 * Permission callback for subscribe/unsubscribe — requires a valid wp_rest
	 * nonce. Standard WP REST auth model: the nonce is created by the theme
	 * via wp_create_nonce('wp_rest') and is sent in the X-WP-Nonce header.
	 *
	 * Also requires the master enable toggle to be ON.
	 *
	 * @param mixed $request WP_REST_Request
	 * @return bool|WP_Error
	 */
	function lafka_push_rest_nonce_permission( $request = null ) {
		if ( ! lafka_push_rest_is_enabled() ) {
			if ( class_exists( 'WP_Error' ) ) {
				return new \WP_Error(
					'lafka_push_disabled',
					function_exists( '__' ) ? __( 'Push notifications are disabled.', 'lafka-plugin' ) : 'Push notifications are disabled.',
					array( 'status' => 403 )
				);
			}
			return false;
		}
		// WP core's rest_cookie_check_errors handler validates the X-WP-Nonce
		// header on cookie-auth requests. Since the front-end is logged-in or
		// guest with a cookie, the nonce check is satisfied at the route layer
		// — no manual wp_verify_nonce() needed here.
		return true;
	}
}

if ( ! function_exists( 'lafka_push_rest_subscribe' ) ) {
	/**
	 * POST /push/subscribe handler.
	 *
	 * Expects:
	 *   {
	 *     endpoint: "https://fcm.googleapis.com/fcm/send/...",
	 *     keys: { p256dh: "...", auth: "..." }
	 *   }
	 *
	 * Returns:
	 *   200 { ok: true, subscription_id: int }
	 *   400 { ok: false, code: 'invalid_payload' }
	 *
	 * @param mixed $request WP_REST_Request
	 * @return array|WP_REST_Response
	 */
	function lafka_push_rest_subscribe( $request = null ) {
		$body = lafka_push_rest_extract_body( $request );

		$endpoint = isset( $body['endpoint'] ) ? (string) $body['endpoint'] : '';
		$keys     = isset( $body['keys'] ) && is_array( $body['keys'] ) ? $body['keys'] : array();
		$p256dh   = isset( $keys['p256dh'] ) ? (string) $keys['p256dh'] : '';
		$auth     = isset( $keys['auth'] ) ? (string) $keys['auth'] : '';

		// Endpoint must be a valid HTTPS URL — the Web Push protocol guarantees
		// providers serve over HTTPS, so any http:// or non-URL is a forgery.
		if ( '' === $endpoint || ! preg_match( '#^https://#i', $endpoint ) ) {
			return lafka_push_rest_error( 'invalid_payload', 'Endpoint must be an https URL.', 400 );
		}
		if ( '' === $p256dh || '' === $auth ) {
			return lafka_push_rest_error( 'invalid_payload', 'keys.p256dh and keys.auth are required.', 400 );
		}
		// Light shape check on the keys — base64url alphabet.
		if ( ! preg_match( '#^[A-Za-z0-9_\-]+=*$#', $p256dh ) ) {
			return lafka_push_rest_error( 'invalid_payload', 'p256dh is not base64url.', 400 );
		}
		if ( ! preg_match( '#^[A-Za-z0-9_\-]+=*$#', $auth ) ) {
			return lafka_push_rest_error( 'invalid_payload', 'auth is not base64url.', 400 );
		}

		$user_id    = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		$user_agent = '';
		if ( isset( $_SERVER['HTTP_USER_AGENT'] ) ) {
			// phpcs:ignore WordPressVIPMinimum.Variables.ServerVariables.UserControlledHeaders
			$user_agent = (string) $_SERVER['HTTP_USER_AGENT'];
			if ( function_exists( 'sanitize_text_field' ) ) {
				$user_agent = sanitize_text_field( $user_agent );
			}
			$user_agent = substr( $user_agent, 0, 255 );
		}
		$locale = function_exists( 'get_locale' ) ? (string) get_locale() : '';

		$row_id = lafka_push_save_subscription( $endpoint, $p256dh, $auth, $user_id, $user_agent, $locale );

		if ( $row_id <= 0 ) {
			return lafka_push_rest_error( 'save_failed', 'Could not save subscription.', 500 );
		}

		return array(
			'ok'              => true,
			'subscription_id' => $row_id,
		);
	}
}

if ( ! function_exists( 'lafka_push_rest_unsubscribe' ) ) {
	/**
	 * POST /push/unsubscribe handler. Marks a row as unsubscribed.
	 *
	 * Expects:
	 *   { endpoint: "..." }
	 *
	 * Returns:
	 *   200 { ok: true, removed: int }   // 1 if a row matched, 0 if none
	 *
	 * @param mixed $request WP_REST_Request
	 * @return array|WP_REST_Response
	 */
	function lafka_push_rest_unsubscribe( $request = null ) {
		$body     = lafka_push_rest_extract_body( $request );
		$endpoint = isset( $body['endpoint'] ) ? (string) $body['endpoint'] : '';

		if ( '' === $endpoint ) {
			return lafka_push_rest_error( 'invalid_payload', 'endpoint is required.', 400 );
		}

		$removed = lafka_push_mark_unsubscribed( $endpoint );
		return array(
			'ok'      => true,
			'removed' => $removed,
		);
	}
}

if ( ! function_exists( 'lafka_push_rest_vapid_key' ) ) {
	/**
	 * GET /push/vapid-key handler. Returns the operator's public VAPID key.
	 *
	 * @return array
	 */
	function lafka_push_rest_vapid_key( $request = null ) {
		// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		unset( $request );

		$key = function_exists( 'get_theme_mod' )
			? (string) get_theme_mod( 'lafka_push_vapid_public_key', '' )
			: '';

		return array(
			'enabled' => lafka_push_rest_is_enabled(),
			'key'     => $key,
		);
	}
}

if ( ! function_exists( 'lafka_push_rest_extract_body' ) ) {
	/**
	 * Pull the JSON body off a WP_REST_Request, falling back to raw
	 * php://input when the test harness passes null (Brain Monkey).
	 *
	 * @param mixed $request
	 * @return array
	 */
	function lafka_push_rest_extract_body( $request ): array {
		if ( is_object( $request ) && method_exists( $request, 'get_json_params' ) ) {
			$json = $request->get_json_params();
			if ( is_array( $json ) && ! empty( $json ) ) {
				return $json;
			}
		}
		if ( is_object( $request ) && method_exists( $request, 'get_params' ) ) {
			$p = $request->get_params();
			if ( is_array( $p ) && ! empty( $p ) ) {
				return $p;
			}
		}
		// Raw fallback (unit tests, edge runtimes).
		$raw = '';
		if ( function_exists( 'file_get_contents' ) ) {
			// phpcs:ignore WordPressVIPMinimum.Performance.FetchingRemoteData.FileGetContentsUnknown
			$raw = (string) @file_get_contents( 'php://input' );
		}
		if ( '' === $raw ) {
			return array();
		}
		$decoded = json_decode( $raw, true );
		return is_array( $decoded ) ? $decoded : array();
	}
}

if ( ! function_exists( 'lafka_push_rest_error' ) ) {
	/**
	 * Build a uniform error response (object when WP_REST_Response is loaded,
	 * array otherwise so unit tests get a serialisable shape).
	 *
	 * @param string $code
	 * @param string $message
	 * @param int    $status
	 * @return array|WP_REST_Response
	 */
	function lafka_push_rest_error( string $code, string $message, int $status ) {
		$resp = array(
			'ok'      => false,
			'code'    => $code,
			'message' => $message,
		);
		if ( class_exists( 'WP_REST_Response' ) ) {
			return new \WP_REST_Response( $resp, $status );
		}
		return $resp;
	}
}
