<?php
/**
 * Phase 3D (v9.28.0): Post-purchase review prompt — on-site banner channel.
 *
 * Server-side detection: on every front-end request, if all of the following
 * hold the plugin sets the `lafka_review_prompt_show` cookie (SameSite=Lax;
 * Secure when HTTPS) so the theme JS can read it and render the banner:
 *
 *   1. Customizer toggle `lafka_review_banner_enabled` is ON.
 *   2. Current user is logged in.
 *   3. User has at least one WooCommerce order with status `completed` whose
 *      completion timestamp is within the configured window
 *      (`lafka_review_banner_window_days`, default 7).
 *   4. User has not dismissed the banner — user meta
 *      `_lafka_review_banner_dismissed` is empty.
 *
 * Two REST endpoints support the banner UI:
 *
 *   POST /wp-json/lafka/v1/review-banner-dismiss
 *     - Requires `current_user_can('read')` — logged-in only.
 *     - Sets `_lafka_review_banner_dismissed` user meta to the current
 *       timestamp.
 *     - Returns 200 { "dismissed": true }.
 *
 *   POST /wp-json/lafka/v1/review-banner-shown
 *     - Public endpoint for analytics tracking — no nonce, no login.
 *     - Rate-limited to 1 request per minute per IP via a transient.
 *     - Returns 200 { "ok": true } or 429 { "code": "rate_limited" }.
 *
 * Cookie lifecycle:
 *   - Set: max-age = banner_window_days * 86400.
 *   - Cleared (max-age 0) on dismiss success.
 *
 * Self-gates on the master toggle so an operator who hasn't opted in pays zero
 * overhead at request time.
 *
 * @package Lafka\Plugin\Conversion
 * @since   9.28.0
 */

defined( 'ABSPATH' ) || exit;

// ─────────────────────────────────────────────────────────────────────────────
// Customizer reads.
// ─────────────────────────────────────────────────────────────────────────────

if ( ! function_exists( 'lafka_review_banner_is_enabled' ) ) {
	/**
	 * Master enable toggle.
	 *
	 * @return bool
	 */
	function lafka_review_banner_is_enabled(): bool {
		if ( ! function_exists( 'get_theme_mod' ) ) {
			return false;
		}
		return '1' === (string) get_theme_mod( 'lafka_review_banner_enabled', '0' );
	}
}

if ( ! function_exists( 'lafka_review_banner_window_days' ) ) {
	/**
	 * Days after a completed order during which the banner remains visible
	 * on the customer's next visit.
	 *
	 * @return int Clamped to [1, 30].
	 */
	function lafka_review_banner_window_days(): int {
		$raw = 7;
		if ( function_exists( 'get_theme_mod' ) ) {
			$raw = (int) get_theme_mod( 'lafka_review_banner_window_days', 7 );
		}
		return max( 1, min( 30, $raw ) );
	}
}

if ( ! function_exists( 'lafka_review_banner_copy' ) ) {
	function lafka_review_banner_copy(): string {
		$value = function_exists( 'get_theme_mod' )
			? (string) get_theme_mod( 'lafka_review_banner_copy', '' )
			: '';
		return '' === trim( $value ) ? 'Loved your order? Tap to rate us' : $value;
	}
}

if ( ! function_exists( 'lafka_review_banner_cta_label' ) ) {
	function lafka_review_banner_cta_label(): string {
		$value = function_exists( 'get_theme_mod' )
			? (string) get_theme_mod( 'lafka_review_banner_cta_label', '' )
			: '';
		return '' === trim( $value ) ? 'Leave a review →' : $value;
	}
}

// ─────────────────────────────────────────────────────────────────────────────
// Server-side detection.
// ─────────────────────────────────────────────────────────────────────────────

if ( ! function_exists( 'lafka_review_banner_user_in_window' ) ) {
	/**
	 * Does the current user have a `completed` WC order whose completion date
	 * is within the configured window? Returns the order ID of the most recent
	 * eligible order, or 0.
	 *
	 * Uses `wc_get_orders()` which is cached by WC for the request lifetime, so
	 * the per-request cost is one DB query at worst.
	 *
	 * @param int $user_id
	 * @return int Most recent eligible order ID, or 0.
	 */
	function lafka_review_banner_user_in_window( int $user_id ): int {
		if ( $user_id <= 0 ) {
			return 0;
		}
		if ( ! function_exists( 'wc_get_orders' ) ) {
			return 0;
		}
		$window_days = lafka_review_banner_window_days();
		$cutoff      = time() - ( $window_days * DAY_IN_SECONDS );

		$orders = wc_get_orders(
			array(
				'customer_id' => $user_id,
				'status'      => array( 'completed' ),
				'limit'       => 1,
				'orderby'     => 'date',
				'order'       => 'DESC',
			)
		);
		if ( ! is_array( $orders ) || empty( $orders ) ) {
			return 0;
		}
		$order = $orders[0];
		if ( ! is_object( $order ) ) {
			return 0;
		}

		// Use date_completed when WC has stamped it; fall back to date_modified.
		$completed_ts = 0;
		if ( method_exists( $order, 'get_date_completed' ) ) {
			$dt = $order->get_date_completed();
			if ( is_object( $dt ) && method_exists( $dt, 'getTimestamp' ) ) {
				$completed_ts = (int) $dt->getTimestamp();
			}
		}
		if ( 0 === $completed_ts && method_exists( $order, 'get_date_modified' ) ) {
			$dt = $order->get_date_modified();
			if ( is_object( $dt ) && method_exists( $dt, 'getTimestamp' ) ) {
				$completed_ts = (int) $dt->getTimestamp();
			}
		}
		if ( $completed_ts < $cutoff ) {
			return 0;
		}
		if ( method_exists( $order, 'get_id' ) ) {
			return (int) $order->get_id();
		}
		return 0;
	}
}

if ( ! function_exists( 'lafka_review_banner_user_has_dismissed' ) ) {
	/**
	 * Has the current user already dismissed the banner?
	 *
	 * @param int $user_id
	 * @return bool
	 */
	function lafka_review_banner_user_has_dismissed( int $user_id ): bool {
		if ( $user_id <= 0 ) {
			return false;
		}
		if ( ! function_exists( 'get_user_meta' ) ) {
			return false;
		}
		$value = get_user_meta( $user_id, '_lafka_review_banner_dismissed', true );
		return '' !== (string) $value && '0' !== (string) $value;
	}
}

if ( ! function_exists( 'lafka_review_banner_should_set_cookie' ) ) {
	/**
	 * Pure-decision helper — returns true iff the cookie should be (re)set
	 * for the current request. Externalised from the cookie-set hook so unit
	 * tests can exercise the gating logic without simulating PHP headers.
	 *
	 * @param int $user_id 0 = guest.
	 * @return bool
	 */
	function lafka_review_banner_should_set_cookie( int $user_id ): bool {
		if ( ! lafka_review_banner_is_enabled() ) {
			return false;
		}
		if ( $user_id <= 0 ) {
			return false;
		}
		if ( lafka_review_banner_user_has_dismissed( $user_id ) ) {
			return false;
		}
		return lafka_review_banner_user_in_window( $user_id ) > 0;
	}
}

if ( ! function_exists( 'lafka_review_banner_should_clear_cookie' ) ) {
	/**
	 * Pure-decision helper — true iff the cookie should be force-cleared.
	 *
	 * @param int $user_id 0 = guest.
	 * @return bool
	 */
	function lafka_review_banner_should_clear_cookie( int $user_id ): bool {
		if ( ! lafka_review_banner_is_enabled() ) {
			return false;
		}
		if ( $user_id <= 0 ) {
			return false;
		}
		if ( lafka_review_banner_user_has_dismissed( $user_id ) ) {
			return true;
		}
		// User no longer in window (their orders moved out of recency).
		return lafka_review_banner_user_in_window( $user_id ) === 0;
	}
}

if ( ! function_exists( 'lafka_review_banner_set_cookie' ) ) {
	/**
	 * `template_redirect` hook handler — runs after WP knows whether the user
	 * is logged in and (for WC) has access to wc_get_orders(). Sets or clears
	 * the cookie based on the helpers above.
	 *
	 * @return void
	 */
	function lafka_review_banner_set_cookie(): void {
		if ( function_exists( 'headers_sent' ) && headers_sent() ) {
			return;
		}
		if ( ! lafka_review_banner_is_enabled() ) {
			return;
		}
		if ( ! function_exists( 'get_current_user_id' ) ) {
			return;
		}
		$user_id = (int) get_current_user_id();
		$secure  = function_exists( 'is_ssl' ) && is_ssl();

		if ( lafka_review_banner_should_set_cookie( $user_id ) ) {
			$window_days = lafka_review_banner_window_days();
			$expires     = time() + ( $window_days * DAY_IN_SECONDS );
			lafka_review_banner_emit_setcookie(
				'lafka_review_prompt_show',
				'1',
				$expires,
				$secure
			);
			return;
		}

		if ( lafka_review_banner_should_clear_cookie( $user_id )
			&& isset( $_COOKIE['lafka_review_prompt_show'] ) ) {
			lafka_review_banner_emit_setcookie(
				'lafka_review_prompt_show',
				'',
				time() - DAY_IN_SECONDS,
				$secure
			);
		}
	}
}

if ( ! function_exists( 'lafka_review_banner_emit_setcookie' ) ) {
	/**
	 * Tiny wrapper around setcookie() so the cookie's options dict is uniform
	 * across set + clear paths and unit tests can stub the whole thing.
	 *
	 * @param string $name
	 * @param string $value
	 * @param int    $expires
	 * @param bool   $secure
	 * @return void
	 */
	function lafka_review_banner_emit_setcookie( string $name, string $value, int $expires, bool $secure ): void {
		// PHP 7.3+ associative form — supports SameSite. Function is stubbable
		// by tests via Brain Monkey.
		if ( function_exists( 'setcookie' ) ) {
			$opts = array(
				'expires'  => $expires,
				'path'     => '/',
				'secure'   => $secure,
				'httponly' => false, // JS must read it.
				'samesite' => 'Lax',
			);
			// phpcs:ignore WordPressVIPMinimum.Functions.RestrictedFunctions.cookies_setcookie -- standard cookie write, no PII.
			@setcookie( $name, $value, $opts );
		}
	}
}

if ( function_exists( 'add_action' ) ) {
	add_action( 'template_redirect', 'lafka_review_banner_set_cookie', 5 );
}

// ─────────────────────────────────────────────────────────────────────────────
// REST endpoints.
// ─────────────────────────────────────────────────────────────────────────────

if ( ! function_exists( 'lafka_review_banner_register_rest_routes' ) ) {
	/**
	 * Register two endpoints under the `lafka/v1` namespace.
	 *
	 * @return void
	 */
	function lafka_review_banner_register_rest_routes(): void {
		if ( ! function_exists( 'register_rest_route' ) ) {
			return;
		}

		register_rest_route(
			'lafka/v1',
			'/review-banner-dismiss',
			array(
				'methods'             => 'POST',
				'callback'            => 'lafka_review_banner_rest_dismiss',
				'permission_callback' => 'lafka_review_banner_rest_dismiss_permission',
			)
		);

		register_rest_route(
			'lafka/v1',
			'/review-banner-shown',
			array(
				'methods'             => 'POST',
				'callback'            => 'lafka_review_banner_rest_shown',
				// Public endpoint — analytics tracking only.
				'permission_callback' => '__return_true',
			)
		);
	}
}

if ( function_exists( 'add_action' ) ) {
	add_action( 'rest_api_init', 'lafka_review_banner_register_rest_routes' );
}

if ( ! function_exists( 'lafka_review_banner_rest_dismiss_permission' ) ) {
	/**
	 * Permission callback for POST /review-banner-dismiss. Logged-in users
	 * only — guests have no user meta to write, and the master toggle is the
	 * operator's own opt-in.
	 *
	 * @return bool|WP_Error
	 */
	function lafka_review_banner_rest_dismiss_permission() {
		if ( ! function_exists( 'is_user_logged_in' ) || ! is_user_logged_in() ) {
			if ( class_exists( 'WP_Error' ) ) {
				return new \WP_Error(
					'rest_forbidden',
					function_exists( '__' ) ? __( 'You must be logged in to dismiss this prompt.', 'lafka-plugin' ) : 'You must be logged in to dismiss this prompt.',
					array( 'status' => 401 )
				);
			}
			return false;
		}
		// Spec requires current_user_can('read') — the 'read' capability is
		// granted to every standard customer/subscriber role.
		if ( function_exists( 'current_user_can' ) && ! current_user_can( 'read' ) ) {
			if ( class_exists( 'WP_Error' ) ) {
				return new \WP_Error(
					'rest_forbidden',
					function_exists( '__' ) ? __( 'You do not have permission to dismiss this prompt.', 'lafka-plugin' ) : 'You do not have permission to dismiss this prompt.',
					array( 'status' => 403 )
				);
			}
			return false;
		}
		return true;
	}
}

if ( ! function_exists( 'lafka_review_banner_rest_dismiss' ) ) {
	/**
	 * POST /review-banner-dismiss handler — flips `_lafka_review_banner_dismissed`
	 * user meta + clears the cookie.
	 *
	 * @param mixed $request WP_REST_Request (or null in unit tests).
	 * @return array|WP_REST_Response
	 */
	function lafka_review_banner_rest_dismiss( $request = null ) {
		// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		unset( $request );

		$user_id = function_exists( 'get_current_user_id' ) ? (int) get_current_user_id() : 0;
		if ( $user_id <= 0 ) {
			return array(
				'dismissed' => false,
				'reason'    => 'no_user',
			);
		}
		if ( function_exists( 'update_user_meta' ) ) {
			update_user_meta( $user_id, '_lafka_review_banner_dismissed', (string) time() );
		}
		// Best-effort cookie clear — REST runs after headers may have started,
		// so we don't crash if it can't be cleared (the dismiss user meta still
		// gates the next page load).
		if ( function_exists( 'headers_sent' ) && ! headers_sent() ) {
			$secure = function_exists( 'is_ssl' ) && is_ssl();
			lafka_review_banner_emit_setcookie(
				'lafka_review_prompt_show',
				'',
				time() - DAY_IN_SECONDS,
				$secure
			);
		}
		return array(
			'dismissed' => true,
			'user_id'   => $user_id,
		);
	}
}

if ( ! function_exists( 'lafka_review_banner_rest_shown' ) ) {
	/**
	 * POST /review-banner-shown handler — public, rate-limited to 1 req/min/IP.
	 *
	 * The endpoint exists purely so the theme JS can POST a fire-and-forget
	 * impression beacon — server-side measurement of how often the banner
	 * actually rendered, regardless of whether GTM / GA fired.
	 *
	 * @param mixed $request
	 * @return array|WP_REST_Response
	 */
	function lafka_review_banner_rest_shown( $request = null ) {
		// phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter
		unset( $request );

		$ip = lafka_review_banner_request_ip();
		// IP is empty in CLI / test contexts — fall back to a fixed key so
		// the limit applies to the whole process.
		if ( '' === $ip ) {
			$ip = 'cli';
		}
		$transient_key = 'lafka_rvb_shown_' . md5( $ip );

		if ( function_exists( 'get_transient' ) ) {
			$existing = get_transient( $transient_key );
			if ( false !== $existing ) {
				$resp = array(
					'ok'   => false,
					'code' => 'rate_limited',
				);
				if ( class_exists( 'WP_REST_Response' ) ) {
					return new \WP_REST_Response( $resp, 429 );
				}
				return $resp;
			}
		}

		if ( function_exists( 'set_transient' ) ) {
			set_transient( $transient_key, 1, MINUTE_IN_SECONDS );
		}

		return array(
			'ok'        => true,
			'logged_at' => time(),
		);
	}
}

if ( ! function_exists( 'lafka_review_banner_request_ip' ) ) {
	/**
	 * Best-effort client IP — returns REMOTE_ADDR or '' when not available.
	 *
	 * NOT used for storage, only for in-memory rate-limit keying. We avoid
	 * X-Forwarded-For specifically because behind a proxy that header is
	 * spoofable + the rate-limit key would be too easy to bypass.
	 *
	 * @return string
	 */
	function lafka_review_banner_request_ip(): string {
		if ( ! isset( $_SERVER['REMOTE_ADDR'] ) ) {
			return '';
		}
		$ip = (string) $_SERVER['REMOTE_ADDR']; // phpcs:ignore WordPressVIPMinimum.Variables.ServerVariables.UserControlledHeaders
		$ip = trim( $ip );
		// Validate — drop anything that doesn't look like an IP, never letting
		// caller-controlled bytes into the transient key uncleansed.
		if ( filter_var( $ip, FILTER_VALIDATE_IP ) ) {
			return $ip;
		}
		return '';
	}
}
