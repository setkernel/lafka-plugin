<?php
/**
 * Per-recipient email unsubscribe (CAN-SPAM / GDPR) shared by the
 * abandoned-cart recovery email and the review-prompt email.
 *
 * @package Lafka\Plugin\Conversion
 * @since   10.1.0 (extracted from the two email modules)
 */

defined( 'ABSPATH' ) || exit;

// ─────────────────────────────────────────────────────────────────────────────
// Generic per-recipient unsubscribe (CAN-SPAM / GDPR).
//
// Shared by the abandoned-cart recovery email and the review-prompt email so a
// guest-checkout customer (no WP user, no user meta) still gets a working
// opt-out. The recipient EMAIL is tokenized (not a user_id), and the opt-out is
// persisted in a dedicated option keyed by a salt-independent hash of the email
// so the preference survives wp_salt() rotation; the URL token itself is an HMAC
// (wp_salt) so it cannot be forged.
// ─────────────────────────────────────────────────────────────────────────────

if ( ! function_exists( 'lafka_unsub_option_name' ) ) {
	/**
	 * Option that stores the per-recipient opt-out list (assoc: hash => ts).
	 *
	 * @return string
	 */
	function lafka_unsub_option_name(): string {
		return 'lafka_email_unsub_list';
	}
}

if ( ! function_exists( 'lafka_unsub_token' ) ) {
	/**
	 * Forgery-resistant token for a recipient email.
	 *
	 *   token = HMAC-SHA256( "lafka-unsub:{lower(email)}", wp_salt() )
	 *
	 * @param string $email
	 * @return string Empty string when $email is empty.
	 */
	function lafka_unsub_token( string $email ): string {
		$email = strtolower( trim( $email ) );
		if ( '' === $email ) {
			return '';
		}
		$salt = function_exists( 'wp_salt' ) ? (string) wp_salt() : 'lafka-default-salt';
		return hash_hmac( 'sha256', 'lafka-unsub:' . $email, $salt );
	}
}

if ( ! function_exists( 'lafka_unsub_store_key' ) ) {
	/**
	 * Salt-independent storage key for a recipient email. Decoupled from
	 * wp_salt() so the opt-out preference is permanent across salt rotation.
	 *
	 * @param string $email
	 * @return string Empty string when $email is empty.
	 */
	function lafka_unsub_store_key( string $email ): string {
		$email = strtolower( trim( $email ) );
		if ( '' === $email ) {
			return '';
		}
		return hash( 'sha256', 'lafka-unsub-store:' . $email );
	}
}

if ( ! function_exists( 'lafka_unsub_url' ) ) {
	/**
	 * Build the unsubscribe URL: home_url() + ?lafka_unsubscribe={TOKEN}&e={email}
	 *
	 * @param string $email
	 * @return string Empty string when $email is empty.
	 */
	function lafka_unsub_url( string $email ): string {
		$email = strtolower( trim( $email ) );
		if ( '' === $email ) {
			return '';
		}
		$token = lafka_unsub_token( $email );
		if ( '' === $token ) {
			return '';
		}
		$base = function_exists( 'home_url' ) ? (string) home_url( '/' ) : '/';
		if ( function_exists( 'add_query_arg' ) ) {
			return (string) add_query_arg(
				array(
					'lafka_unsubscribe' => $token,
					'e'                 => $email,
				),
				$base
			);
		}
		$sep = ( false === strpos( $base, '?' ) ) ? '?' : '&';
		return $base . $sep . 'lafka_unsubscribe=' . rawurlencode( $token ) . '&e=' . rawurlencode( $email );
	}
}

if ( ! function_exists( 'lafka_unsub_is_opted_out' ) ) {
	/**
	 * Is the recipient email on the persisted per-recipient opt-out list?
	 *
	 * @param string $email
	 * @return bool
	 */
	function lafka_unsub_is_opted_out( string $email ): bool {
		$key = lafka_unsub_store_key( $email );
		if ( '' === $key || ! function_exists( 'get_option' ) ) {
			return false;
		}
		// Reading the opt-out store must never fatal an email-eligibility decision.
		try {
			$list = get_option( lafka_unsub_option_name(), array() );
		} catch ( \Throwable $e ) {
			return false;
		}
		return is_array( $list ) && isset( $list[ $key ] );
	}
}

if ( ! function_exists( 'lafka_unsub_record_opt_out' ) ) {
	/**
	 * Persist a per-recipient opt-out. Idempotent.
	 *
	 * @param string $email
	 * @return void
	 */
	function lafka_unsub_record_opt_out( string $email ): void {
		$key = lafka_unsub_store_key( $email );
		if ( '' === $key || ! function_exists( 'get_option' ) || ! function_exists( 'update_option' ) ) {
			return;
		}
		// A storage failure must not 500 the unsubscribe request.
		try {
			$list = get_option( lafka_unsub_option_name(), array() );
			if ( ! is_array( $list ) ) {
				$list = array();
			}
			if ( isset( $list[ $key ] ) ) {
				return;
			}
			$list[ $key ] = time();
			update_option( lafka_unsub_option_name(), $list, false );
		} catch ( \Throwable $e ) {
			return;
		}
	}
}

if ( ! function_exists( 'lafka_unsub_postal_address' ) ) {
	/**
	 * Operator's physical postal address for the CAN-SPAM footer. Sourced from
	 * the single NAP source of truth (Customizer-driven), with a fallback to the
	 * WooCommerce store-address options. Never hardcoded.
	 *
	 * @return string Comma-separated address, or '' when unconfigured.
	 */
	function lafka_unsub_postal_address(): string {
		$parts = array();
		if ( function_exists( 'lafka_schema_get_nap' ) ) {
			$nap   = lafka_schema_get_nap();
			$parts = array(
				isset( $nap['name'] ) ? (string) $nap['name'] : '',
				isset( $nap['street'] ) ? (string) $nap['street'] : '',
				trim(
					( isset( $nap['city'] ) ? (string) $nap['city'] : '' ) . ', '
					. ( isset( $nap['region'] ) ? (string) $nap['region'] : '' ) . ' '
					. ( isset( $nap['postal'] ) ? (string) $nap['postal'] : '' ),
					' ,'
				),
				isset( $nap['country'] ) ? (string) $nap['country'] : '',
			);
		} elseif ( function_exists( 'get_option' ) ) {
			$parts = array(
				(string) get_option( 'woocommerce_store_address', '' ),
				(string) get_option( 'woocommerce_store_address_2', '' ),
				(string) get_option( 'woocommerce_store_city', '' ),
				(string) get_option( 'woocommerce_store_postcode', '' ),
			);
		}
		$parts = array_filter(
			array_map(
				static function ( $part ) {
					return trim( (string) $part );
				},
				$parts
			)
		);
		return implode( ', ', $parts );
	}
}

if ( ! function_exists( 'lafka_unsub_handle_request' ) ) {
	/**
	 * `init` handler — opt the recipient out when ?lafka_unsubscribe + ?e are
	 * present and the HMAC token matches. Works for both a GET click from the
	 * email body and an RFC 8058 one-click POST (the query string populates $_GET
	 * regardless of method). No confirmation page — matches the existing
	 * review-prompt unsubscribe behaviour.
	 *
	 * @return void
	 */
	function lafka_unsub_handle_request(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- HMAC token gates the action; opt-out is not a credentialed state change.
		if ( ! isset( $_GET['lafka_unsubscribe'] ) || ! isset( $_GET['e'] ) ) {
			return;
		}
		$token = ( is_string( $_GET['lafka_unsubscribe'] ) && function_exists( 'sanitize_text_field' ) && function_exists( 'wp_unslash' ) )
			? sanitize_text_field( wp_unslash( $_GET['lafka_unsubscribe'] ) )
			: '';
		$email = '';
		if ( is_string( $_GET['e'] ) ) {
			$raw   = function_exists( 'wp_unslash' ) ? wp_unslash( $_GET['e'] ) : $_GET['e'];
			$email = function_exists( 'sanitize_email' ) ? sanitize_email( $raw ) : (string) $raw;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( '' === $token || '' === $email ) {
			return;
		}
		$expected = lafka_unsub_token( $email );
		if ( '' === $expected || ! hash_equals( $expected, $token ) ) {
			return;
		}

		lafka_unsub_record_opt_out( $email );

		if ( function_exists( 'wp_safe_redirect' ) && function_exists( 'home_url' ) ) {
			wp_safe_redirect( (string) home_url( '/?lafka_email_unsubscribed=1' ) );
			if ( ! ( defined( 'LAFKA_TESTING' ) && LAFKA_TESTING ) ) {
				exit;
			}
		}
	}
}

if ( ! function_exists( 'lafka_unsub_email_headers' ) ) {
	/**
	 * `woocommerce_email_headers` filter — append a List-Unsubscribe (+ RFC 8058
	 * one-click) header to the Lafka marketing-class emails. Scoped by email id;
	 * the per-recipient URL is built from the live WC_Email instance recipient.
	 *
	 * @param string $headers
	 * @param string $email_id
	 * @param mixed  $object
	 * @param mixed  $email
	 * @return string
	 */
	function lafka_unsub_email_headers( $headers, $email_id = '', $object = null, $email = null ): string {
		$headers = (string) $headers;
		$ids     = array( 'lafka_abandoned_cart', 'lafka_review_prompt' );
		if ( ! in_array( (string) $email_id, $ids, true ) ) {
			return $headers;
		}
		$recipient = '';
		if ( is_object( $email ) && method_exists( $email, 'get_recipient' ) ) {
			$recipient = (string) $email->get_recipient();
		} elseif ( is_object( $email ) && isset( $email->recipient ) ) {
			$recipient = (string) $email->recipient;
		}
		// Fallback to the order object (review email; older 3-arg WC signature).
		if ( '' === $recipient && is_object( $object ) && method_exists( $object, 'get_billing_email' ) ) {
			$recipient = (string) $object->get_billing_email();
		}
		// Defend against a comma-joined recipient list — token the first address.
		if ( false !== strpos( $recipient, ',' ) ) {
			$first     = explode( ',', $recipient );
			$recipient = (string) reset( $first );
		}
		$recipient = trim( $recipient );
		if ( '' === $recipient ) {
			return $headers;
		}
		$url = lafka_unsub_url( $recipient );
		if ( '' === $url ) {
			return $headers;
		}
		if ( '' !== $headers && "\n" !== substr( $headers, -1 ) ) {
			$headers .= "\r\n";
		}
		$headers .= 'List-Unsubscribe: <' . $url . ">\r\n";
		$headers .= "List-Unsubscribe-Post: List-Unsubscribe=One-Click\r\n";
		return $headers;
	}
}

if ( function_exists( 'add_action' ) ) {
	add_action( 'init', 'lafka_unsub_handle_request', 5 );
}
if ( function_exists( 'add_filter' ) ) {
	add_filter( 'woocommerce_email_headers', 'lafka_unsub_email_headers', 10, 4 );
}
