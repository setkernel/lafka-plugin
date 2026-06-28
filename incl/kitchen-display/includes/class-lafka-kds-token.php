<?php
/**
 * KDS access-token verification (P2-02a hash-at-rest).
 *
 * Pure, dependency-light helpers (only wp_salt) so they are unit-testable in
 * isolation and reusable by the frontend page + every AJAX endpoint. The single
 * source of truth for "does this candidate token authenticate?".
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lafka_KDS_Token {

	/**
	 * HMAC of a raw token, keyed on the site auth salt. The key lives in wp-config
	 * (never the DB), so a leaked stored digest is non-usable. NOTE: rotating the WP
	 * auth salt invalidates hashed tokens — regenerate the KDS URL afterwards.
	 */
	public static function hash( $raw ) {
		return hash_hmac( 'sha256', (string) $raw, wp_salt( 'auth' ) );
	}

	/**
	 * Whether a stored value is a hash-at-rest digest (64 lowercase hex) rather than
	 * a legacy 32-char plaintext token from wp_generate_password( 32, false ).
	 */
	public static function is_hashed( $value ) {
		return (bool) preg_match( '/^[a-f0-9]{64}$/', (string) $value );
	}

	/**
	 * Constant-time check of a candidate raw token against the stored value,
	 * supporting BOTH legacy plaintext and hash-at-rest. Backward-compatible:
	 * a legacy token validates exactly as before; a digest is matched via HMAC.
	 * The stored digest itself never authenticates (you must present the raw token).
	 */
	public static function matches( $stored, $candidate ) {
		$stored    = (string) $stored;
		$candidate = (string) $candidate;
		if ( '' === $stored || '' === $candidate ) {
			return false;
		}

		if ( self::is_hashed( $stored ) ) {
			return hash_equals( $stored, self::hash( $candidate ) );
		}

		return hash_equals( $stored, $candidate );
	}
}
