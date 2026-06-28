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
 *     Body: { endpoint, keys: { auth } }
 *     Marks the matching row as unsubscribed via lafka_push_mark_unsubscribed().
 *     Returns { ok: true, removed: int }. Same nonce model as subscribe, plus
 *     proof of ownership: the caller must echo back the subscription's `auth`
 *     secret (the per-subscription secret the push service minted at subscribe
 *     time) so a third party who merely learns the opaque endpoint URL cannot
 *     unsubscribe someone else (IDOR). Logged-in callers are additionally bound
 *     to rows owned by their own user_id.
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
	 * IMPORTANT: we must verify the nonce ourselves and cannot rely on WP core's
	 * rest_cookie_check_errors. That handler only validates X-WP-Nonce when an
	 * auth cookie is present; for a cookie-less request it sets the current user
	 * to 0 and returns no error — so a guest (which this surface explicitly
	 * allows) could POST with the header simply omitted and pass the route
	 * layer. We therefore call wp_verify_nonce() unconditionally below. The
	 * theme already issues a guest `wp_rest` nonce, so the legitimate guest
	 * subscribe/unsubscribe flow is preserved.
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

		// Resolve the nonce: prefer the WP_REST_Request header accessor, then the
		// raw HTTP header, then a _wpnonce body/query param. Any one is fine — all
		// must carry the same wp_create_nonce('wp_rest') token.
		$nonce = '';
		if ( is_object( $request ) && method_exists( $request, 'get_header' ) ) {
			$nonce = (string) $request->get_header( 'X-WP-Nonce' );
		}
		if ( '' === $nonce && isset( $_SERVER['HTTP_X_WP_NONCE'] ) ) {
			$nonce = function_exists( 'wp_unslash' )
				? (string) wp_unslash( $_SERVER['HTTP_X_WP_NONCE'] ) // phpcs:ignore WordPressVIPMinimum.Variables.ServerVariables.UserControlledHeaders
				: (string) $_SERVER['HTTP_X_WP_NONCE']; // phpcs:ignore WordPressVIPMinimum.Variables.ServerVariables.UserControlledHeaders
		}
		if ( '' === $nonce && isset( $_REQUEST['_wpnonce'] ) ) {
			$nonce = function_exists( 'wp_unslash' )
				? (string) wp_unslash( $_REQUEST['_wpnonce'] ) // phpcs:ignore WordPress.Security.NonceVerification.Recommended
				: (string) $_REQUEST['_wpnonce']; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
		if ( function_exists( 'sanitize_text_field' ) ) {
			$nonce = sanitize_text_field( $nonce );
		}

		// Enforce the nonce regardless of auth state (see method doc above).
		$valid = ( '' !== $nonce ) && function_exists( 'wp_verify_nonce' ) && wp_verify_nonce( $nonce, 'wp_rest' );
		if ( ! $valid ) {
			if ( class_exists( 'WP_Error' ) ) {
				return new \WP_Error(
					'rest_forbidden',
					function_exists( '__' ) ? __( 'Invalid or missing nonce.', 'lafka-plugin' ) : 'Invalid or missing nonce.',
					array( 'status' => 403 )
				);
			}
			return false;
		}

		return true;
	}
}

if ( ! function_exists( 'lafka_push_endpoint_host_allowed' ) ) {
	/**
	 * SSRF guard: is this endpoint's host one of the real Web Push providers?
	 *
	 * The Web Push protocol only ever hands the browser a provider-served
	 * endpoint (FCM / Apple / Mozilla / Windows). Anything else — a private IP,
	 * a link-local metadata host (169.254.169.254), `localhost`, an arbitrary
	 * attacker domain — is a forgery whose only purpose is to make our sender
	 * issue an attacker-directed POST. We therefore reject any host that is not
	 * on a filterable allowlist of known push services.
	 *
	 * Operators behind a regional / self-hosted push gateway can extend the list
	 * via the `lafka_push_endpoint_host_allowlist` filter. Entries are matched
	 * case-insensitively and may be either an exact host
	 * (`updates.push.services.mozilla.com`) or a leading wildcard
	 * (`*.push.apple.com`, matching any single- or multi-label subdomain).
	 *
	 * @param string $endpoint The full subscription endpoint URL.
	 * @return bool True if the host is allowed.
	 */
	function lafka_push_endpoint_host_allowed( string $endpoint ): bool {
		$parsed = function_exists( 'wp_parse_url' ) ? wp_parse_url( $endpoint ) : parse_url( $endpoint );
		if ( ! is_array( $parsed ) || empty( $parsed['host'] ) ) {
			return false;
		}
		// Normalise: lowercase, drop a trailing FQDN root dot, strip IPv6 brackets.
		$host = strtolower( trim( (string) $parsed['host'] ) );
		$host = trim( $host, '[].' );
		if ( '' === $host ) {
			return false;
		}

		$allow = array(
			'fcm.googleapis.com',
			'web.push.apple.com',
			'*.push.apple.com',
			'*.notify.windows.com',
			'updates.push.services.mozilla.com',
			'*.push.services.mozilla.com',
		);
		if ( function_exists( 'apply_filters' ) ) {
			$allow = apply_filters( 'lafka_push_endpoint_host_allowlist', $allow, $host, $endpoint );
		}
		if ( ! is_array( $allow ) ) {
			return false;
		}

		foreach ( $allow as $pattern ) {
			$pattern = strtolower( trim( (string) $pattern ) );
			if ( '' === $pattern ) {
				continue;
			}
			if ( 0 === strpos( $pattern, '*.' ) ) {
				// Wildcard: match the dotted suffix, requiring at least one label
				// in front (so `*.push.apple.com` matches `web.push.apple.com`
				// but never the bare `push.apple.com`).
				$suffix = substr( $pattern, 1 );
				$slen   = strlen( $suffix );
				if ( strlen( $host ) > $slen && substr( $host, -$slen ) === $suffix ) {
					return true;
				}
			} elseif ( $host === $pattern ) {
				return true;
			}
		}
		return false;
	}
}

if ( ! function_exists( 'lafka_push_rest_request_ip' ) ) {
	/**
	 * Best-effort client IP for rate-limit keying only — returns REMOTE_ADDR or
	 * '' when unavailable. We deliberately ignore X-Forwarded-For: behind a proxy
	 * it is client-spoofable, which would make the rate-limit key trivial to
	 * rotate. Never stored, only hashed into a transient key.
	 *
	 * @return string
	 */
	function lafka_push_rest_request_ip(): string {
		if ( ! isset( $_SERVER['REMOTE_ADDR'] ) ) {
			return '';
		}
		$ip = function_exists( 'wp_unslash' )
			? (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) // phpcs:ignore WordPressVIPMinimum.Variables.ServerVariables.UserControlledHeaders
			: (string) $_SERVER['REMOTE_ADDR']; // phpcs:ignore WordPressVIPMinimum.Variables.ServerVariables.UserControlledHeaders
		$ip = trim( $ip );
		if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return $ip;
		}
		return '';
	}
}

if ( ! function_exists( 'lafka_push_rest_subscribe_rate_limited' ) ) {
	/**
	 * Transient-based per-IP cap on subscribe attempts. Returns true once the IP
	 * has exceeded the allowed number of attempts inside the rolling window.
	 *
	 * Defaults: 10 attempts per hour. Both values are filterable
	 * (`lafka_push_subscribe_rate_limit`, `lafka_push_subscribe_rate_window`) so
	 * operators on shared egress IPs can tune them. A limit of 0 disables the cap.
	 *
	 * @return bool True when the request should be rejected as rate-limited.
	 */
	function lafka_push_rest_subscribe_rate_limited(): bool {
		$limit = 10;
		if ( function_exists( 'apply_filters' ) ) {
			$limit = (int) apply_filters( 'lafka_push_subscribe_rate_limit', $limit );
		}
		if ( $limit <= 0 ) {
			return false;
		}
		if ( ! function_exists( 'get_transient' ) || ! function_exists( 'set_transient' ) ) {
			return false;
		}

		$window = defined( 'HOUR_IN_SECONDS' ) ? HOUR_IN_SECONDS : 3600;
		if ( function_exists( 'apply_filters' ) ) {
			$window = (int) apply_filters( 'lafka_push_subscribe_rate_window', $window );
		}
		if ( $window <= 0 ) {
			$window = 3600;
		}

		// IP is empty in CLI / test contexts — fall back to a fixed key so the
		// limit still applies to the whole process rather than vanishing.
		$ip = lafka_push_rest_request_ip();
		if ( '' === $ip ) {
			$ip = 'cli';
		}
		$key   = 'lafka_push_sub_rl_' . md5( $ip );
		$count = (int) get_transient( $key );

		if ( $count >= $limit ) {
			return true;
		}

		set_transient( $key, $count + 1, $window );
		return false;
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
		// Per-IP cap: a real browser subscribes a handful of times at most, but a
		// script forging unique fake endpoints (the UNIQUE key only dedupes
		// identical endpoints) would blow past this immediately — blunting the
		// mass junk-row injection that would later be blocking-cURL'd per
		// broadcast. The cap/window are filterable for operators behind shared
		// NAT / corporate egress IPs.
		if ( lafka_push_rest_subscribe_rate_limited() ) {
			return lafka_push_rest_error( 'rate_limited', 'Too many subscription attempts. Please try again later.', 429 );
		}

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
		// SSRF guard: the host must be a known push provider. Without this any
		// `https://10.0.0.5/…` or `https://169.254.169.254/…` would be stored and
		// later POSTed to by the sender from our trusted network position.
		if ( ! lafka_push_endpoint_host_allowed( $endpoint ) ) {
			return lafka_push_rest_error( 'invalid_endpoint_host', 'Endpoint host is not an allowed push provider.', 400 );
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
	 *   { endpoint: "...", keys: { auth: "..." } }
	 *
	 * Returns:
	 *   200 { ok: true, removed: int }   // 1 if a row matched, 0 if none
	 *   400 { ok: false, code: 'invalid_payload' }
	 *   403 { ok: false, code: 'forbidden' }   // ownership proof failed
	 *
	 * Ownership binding (IDOR fix): the nonce alone is shared across all visitors
	 * (guests possess it), so it proves nothing about which subscription the
	 * caller owns. The browser PushSubscription carries a per-subscription `auth`
	 * secret (sent on subscribe as keys.auth); we require the caller to echo it
	 * back and constant-time compare it against the stored value before touching
	 * the row. A missing row and a mismatched secret return the same 403 so the
	 * endpoint can't be probed for existence. Logged-in callers are additionally
	 * bound to rows carrying their own user_id.
	 *
	 * @param mixed $request WP_REST_Request
	 * @return array|WP_REST_Response
	 */
	function lafka_push_rest_unsubscribe( $request = null ) {
		$body     = lafka_push_rest_extract_body( $request );
		$endpoint = isset( $body['endpoint'] ) ? (string) $body['endpoint'] : '';

		// Proof of ownership: the per-subscription `auth` secret. Accept it under
		// keys.auth (the native PushSubscription shape, matching subscribe) and
		// also at the top level for callers that flatten the payload.
		$keys = isset( $body['keys'] ) && is_array( $body['keys'] ) ? $body['keys'] : array();
		$auth = isset( $keys['auth'] ) ? (string) $keys['auth'] : '';
		if ( '' === $auth && isset( $body['auth'] ) ) {
			$auth = (string) $body['auth'];
		}

		if ( '' === $endpoint ) {
			return lafka_push_rest_error( 'invalid_payload', 'endpoint is required.', 400 );
		}
		if ( '' === $auth ) {
			return lafka_push_rest_error( 'invalid_payload', 'keys.auth is required to prove ownership of the subscription.', 400 );
		}

		// Load the stored row and verify the caller actually owns it. We do NOT
		// distinguish "no such endpoint" from "wrong secret" in the response —
		// both yield a uniform 403 so the route can't be used as an existence
		// oracle for other people's opaque endpoint URLs.
		$row = function_exists( 'lafka_push_get_subscription_by_endpoint' )
			? lafka_push_get_subscription_by_endpoint( $endpoint )
			: null;

		$stored_auth = ( is_object( $row ) && isset( $row->auth ) ) ? (string) $row->auth : '';
		$owns        = ( '' !== $stored_auth ) && hash_equals( $stored_auth, $auth );

		// For an authenticated caller, the row must also belong to them — this
		// blocks one member from disabling another member's subscription even if
		// the secret somehow leaked. Guest-owned rows (user_id 0/null) stay
		// unsubscribable by whoever holds the auth secret, including a now
		// logged-in visitor who first subscribed as a guest.
		if ( $owns && function_exists( 'get_current_user_id' ) ) {
			$current_user = (int) get_current_user_id();
			if ( $current_user > 0 ) {
				$row_user = ( is_object( $row ) && isset( $row->user_id ) ) ? (int) $row->user_id : 0;
				if ( $row_user > 0 && $row_user !== $current_user ) {
					$owns = false;
				}
			}
		}

		if ( ! $owns ) {
			return lafka_push_rest_error( 'forbidden', 'Subscription not found or ownership check failed.', 403 );
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
