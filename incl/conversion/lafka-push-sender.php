<?php
/**
 * Phase 3E (v9.29.0): Web Push notifications - sender.
 *
 * Pure-PHP implementation of the Web Push protocol (RFC 8030 + RFC 8291 + RFC
 * 8292):
 *
 *   1. Build a VAPID JWT signed with the operator's private key (ES256).
 *   2. Derive a payload encryption key + nonce via HKDF (RFC 5869) from the
 *      subscription's p256dh + auth values and our ephemeral keypair.
 *   3. AES-128-GCM-encrypt the payload (RFC 8291 - aes128gcm content encoding).
 *   4. POST the ciphertext to the subscription's endpoint with the VAPID
 *      Authorization header.
 *
 * We use raw cURL via the curl_* family (wp_remote_post would re-encode the
 * body and strip headers we need). Tested against FCM (Chrome / Android),
 * Apple Push Service (Safari 16.4+), and Mozilla autopush (Firefox).
 *
 * Why not the `minishlink/web-push` Composer library? The plugin's composer.json
 * intentionally has zero runtime deps so the .zip ships clean. Implementing the
 * spec directly (~250 lines) keeps that property and removes the operator's
 * "run composer install in production" step.
 *
 * Caveats:
 *   - Requires OpenSSL with EC support (PHP 8.1+ on any sane host).
 *   - The VAPID private key MUST be a base64url-encoded raw P-256 private key
 *     (32 bytes). This is the format `web-push generate-vapid-keys` and
 *     vapidkeys.com both emit.
 *   - 410 Gone response - permanent unsubscribe, the row is deleted.
 *   - 404, 429, 5xx - soft failure, row kept; the operator can retry later.
 *
 * @package Lafka\Plugin\Conversion
 * @since   9.29.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'lafka_push_b64url_encode' ) ) {
	/**
	 * base64url-encode raw bytes (RFC 4648 section 5, no trailing padding).
	 */
	function lafka_push_b64url_encode( string $bytes ): string {
		return rtrim( strtr( base64_encode( $bytes ), '+/', '-_' ), '=' );
	}
}

if ( ! function_exists( 'lafka_push_b64url_decode' ) ) {
	/**
	 * base64url-decode (RFC 4648 section 5). Pads back to a multiple of 4.
	 */
	function lafka_push_b64url_decode( string $str ): string {
		$str  = strtr( $str, '-_', '+/' );
		$mod4 = strlen( $str ) % 4;
		if ( $mod4 ) {
			$str .= str_repeat( '=', 4 - $mod4 );
		}
		$decoded = base64_decode( $str, true );
		return false === $decoded ? '' : $decoded;
	}
}

if ( ! function_exists( 'lafka_push_get_vapid_config' ) ) {
	/**
	 * Read the operator's VAPID config.
	 *
	 * Precedence for the private key (v9.29.1 hardening):
	 *   1. `LAFKA_PUSH_VAPID_PRIVATE_KEY` constant defined in `wp-config.php`.
	 *      This is the recommended path for multi-admin sites — the key never
	 *      enters the database and is invisible to anyone with the
	 *      `edit_theme_options` capability.
	 *   2. `LAFKA_PUSH_VAPID_PUBLIC_KEY` + `LAFKA_PUSH_VAPID_SUBJECT` constants
	 *      (same logic).
	 *   3. `get_theme_mod()` fallback for sites where wp-config edits aren't
	 *      practical. Documented in the Customizer description.
	 */
	function lafka_push_get_vapid_config(): array {
		$enabled = function_exists( 'get_theme_mod' )
			? '1' === (string) get_theme_mod( 'lafka_push_enabled', '0' )
			: false;

		$public  = defined( 'LAFKA_PUSH_VAPID_PUBLIC_KEY' ) && '' !== (string) LAFKA_PUSH_VAPID_PUBLIC_KEY
			? (string) LAFKA_PUSH_VAPID_PUBLIC_KEY
			: ( function_exists( 'get_theme_mod' ) ? (string) get_theme_mod( 'lafka_push_vapid_public_key', '' ) : '' );

		$private = defined( 'LAFKA_PUSH_VAPID_PRIVATE_KEY' ) && '' !== (string) LAFKA_PUSH_VAPID_PRIVATE_KEY
			? (string) LAFKA_PUSH_VAPID_PRIVATE_KEY
			: ( function_exists( 'get_theme_mod' ) ? (string) get_theme_mod( 'lafka_push_vapid_private_key', '' ) : '' );

		$subject = defined( 'LAFKA_PUSH_VAPID_SUBJECT' ) && '' !== (string) LAFKA_PUSH_VAPID_SUBJECT
			? (string) LAFKA_PUSH_VAPID_SUBJECT
			: ( function_exists( 'get_theme_mod' ) ? (string) get_theme_mod( 'lafka_push_vapid_subject', '' ) : '' );

		if ( '' === $subject ) {
			$site    = function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'admin_email' ) : 'operator@example.com';
			$subject = 'mailto:' . $site;
		}
		return array(
			'enabled' => $enabled,
			'public'  => $public,
			'private' => $private,
			'subject' => $subject,
		);
	}
}

if ( ! function_exists( 'lafka_push_build_vapid_jwt' ) ) {
	/**
	 * Build + sign a VAPID JWT (ES256) for the audience origin.
	 */
	function lafka_push_build_vapid_jwt( string $audience, string $subject, string $private_b64 ): string {
		if ( '' === $private_b64 ) {
			return '';
		}
		$private_raw = lafka_push_b64url_decode( $private_b64 );
		if ( strlen( $private_raw ) !== 32 ) {
			return '';
		}
		if ( ! function_exists( 'openssl_pkey_get_private' ) ) {
			return '';
		}

		$header  = array(
			'typ' => 'JWT',
			'alg' => 'ES256',
		);
		$payload = array(
			'aud' => $audience,
			'exp' => time() + ( 12 * HOUR_IN_SECONDS ),
			'sub' => $subject,
		);
		$h_enc = lafka_push_b64url_encode( function_exists( 'wp_json_encode' ) ? (string) wp_json_encode( $header ) : (string) json_encode( $header ) );
		$p_enc = lafka_push_b64url_encode( function_exists( 'wp_json_encode' ) ? (string) wp_json_encode( $payload ) : (string) json_encode( $payload ) );
		$body  = $h_enc . '.' . $p_enc;

		$pem = lafka_push_p256_pem_from_raw_private( $private_raw );
		if ( '' === $pem ) {
			return '';
		}
		$pkey = @openssl_pkey_get_private( $pem );
		if ( false === $pkey ) {
			return '';
		}
		$der_signature = '';
		$signed        = @openssl_sign( $body, $der_signature, $pkey, OPENSSL_ALGO_SHA256 );
		if ( ! $signed ) {
			return '';
		}
		$raw_signature = lafka_push_der_to_jose_es256( $der_signature );
		if ( '' === $raw_signature ) {
			return '';
		}
		return $body . '.' . lafka_push_b64url_encode( $raw_signature );
	}
}

if ( ! function_exists( 'lafka_push_p256_pem_from_raw_private' ) ) {
	/**
	 * Wrap a 32-byte raw P-256 private key in PKCS#8 DER + PEM envelope.
	 */
	function lafka_push_p256_pem_from_raw_private( string $raw ): string {
		if ( strlen( $raw ) !== 32 ) {
			return '';
		}
		$sec1 = "\x30\x2e\x02\x01\x01\x04\x20" . $raw . "\xa0\x07\x06\x05\x2b\x81\x04\x00\x22";

		$pkcs8_inner = "\x02\x01\x00"
			. "\x30\x13"
				. "\x06\x07\x2a\x86\x48\xce\x3d\x02\x01"
				. "\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07"
			. "\x04" . chr( strlen( $sec1 ) ) . $sec1;
		$pkcs8 = "\x30" . chr( strlen( $pkcs8_inner ) ) . $pkcs8_inner;

		$pem = "-----BEGIN PRIVATE KEY-----\n"
			. chunk_split( base64_encode( $pkcs8 ), 64, "\n" )
			. "-----END PRIVATE KEY-----\n";
		return $pem;
	}
}

if ( ! function_exists( 'lafka_push_der_to_jose_es256' ) ) {
	/**
	 * Convert DER ECDSA signature to raw R||S pair for ES256.
	 */
	function lafka_push_der_to_jose_es256( string $der ): string {
		$len = strlen( $der );
		if ( $len < 8 || "\x30" !== $der[0] ) {
			return '';
		}
		$i = 2;
		if ( ord( $der[1] ) & 0x80 ) {
			$i = 2 + ( ord( $der[1] ) & 0x7f );
		}
		if ( "\x02" !== $der[ $i ] ) {
			return '';
		}
		$r_len = ord( $der[ $i + 1 ] );
		$r     = substr( $der, $i + 2, $r_len );
		$i     = $i + 2 + $r_len;
		if ( $i >= $len || "\x02" !== $der[ $i ] ) {
			return '';
		}
		$s_len = ord( $der[ $i + 1 ] );
		$s     = substr( $der, $i + 2, $s_len );

		$r = ltrim( $r, "\x00" );
		$s = ltrim( $s, "\x00" );
		if ( strlen( $r ) > 32 || strlen( $s ) > 32 ) {
			return '';
		}
		$r = str_pad( $r, 32, "\x00", STR_PAD_LEFT );
		$s = str_pad( $s, 32, "\x00", STR_PAD_LEFT );
		return $r . $s;
	}
}

if ( ! function_exists( 'lafka_push_hkdf' ) ) {
	/**
	 * HKDF (RFC 5869).
	 */
	function lafka_push_hkdf( string $ikm, string $salt, string $info, int $length ): string {
		if ( function_exists( 'hash_hkdf' ) ) {
			return (string) hash_hkdf( 'sha256', $ikm, $length, $info, $salt );
		}
		$prk = hash_hmac( 'sha256', $ikm, $salt, true );
		$t   = '';
		$out = '';
		$i   = 1;
		while ( strlen( $out ) < $length ) {
			$t    = hash_hmac( 'sha256', $t . $info . chr( $i ), $prk, true );
			$out .= $t;
			++$i;
		}
		return substr( $out, 0, $length );
	}
}

if ( ! function_exists( 'lafka_push_encrypt_payload' ) ) {
	/**
	 * Encrypt $payload using aes128gcm content encoding (RFC 8291).
	 */
	function lafka_push_encrypt_payload( string $payload, string $p256dh_b64, string $auth_b64 ): ?array {
		if ( ! function_exists( 'openssl_pkey_new' ) || ! function_exists( 'openssl_pkey_derive' ) ) {
			return null;
		}
		$ua_public = lafka_push_b64url_decode( $p256dh_b64 );
		$ua_auth   = lafka_push_b64url_decode( $auth_b64 );
		if ( strlen( $ua_public ) !== 65 || strlen( $ua_auth ) === 0 ) {
			return null;
		}

		$ec = @openssl_pkey_new(
			array(
				'private_key_type' => OPENSSL_KEYTYPE_EC,
				'curve_name'       => 'prime256v1',
			)
		);
		if ( false === $ec ) {
			return null;
		}
		$details = @openssl_pkey_get_details( $ec );
		if ( ! is_array( $details ) || empty( $details['ec']['x'] ) || empty( $details['ec']['y'] ) ) {
			return null;
		}
		$server_public = "\x04" . str_pad( $details['ec']['x'], 32, "\x00", STR_PAD_LEFT ) . str_pad( $details['ec']['y'], 32, "\x00", STR_PAD_LEFT );

		$ua_spki = lafka_push_p256_spki_from_raw_public( $ua_public );
		if ( '' === $ua_spki ) {
			return null;
		}
		$ua_pem = "-----BEGIN PUBLIC KEY-----\n"
			. chunk_split( base64_encode( $ua_spki ), 64, "\n" )
			. "-----END PUBLIC KEY-----\n";
		$ua_key = @openssl_pkey_get_public( $ua_pem );
		if ( false === $ua_key ) {
			return null;
		}
		$shared = @openssl_pkey_derive( $ua_key, $ec, 32 );
		if ( false === $shared || '' === $shared ) {
			return null;
		}

		$key_info = "WebPush: info\x00" . $ua_public . $server_public;
		$prk_key  = lafka_push_hkdf( $shared, $ua_auth, $key_info, 32 );

		$salt = random_bytes( 16 );

		$cek   = lafka_push_hkdf( $prk_key, $salt, "Content-Encoding: aes128gcm\x00", 16 );
		$nonce = lafka_push_hkdf( $prk_key, $salt, "Content-Encoding: nonce\x00", 12 );

		$padded = $payload . "\x02";

		$tag       = '';
		$encrypted = @openssl_encrypt( $padded, 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag );
		if ( false === $encrypted ) {
			return null;
		}

		$rs         = 4096;
		$header     = $salt
			. pack( 'N', $rs )
			. chr( strlen( $server_public ) )
			. $server_public;
		$ciphertext = $header . $encrypted . $tag;

		return array(
			'ciphertext'            => $ciphertext,
			'server_public_key_b64' => lafka_push_b64url_encode( $server_public ),
		);
	}
}

if ( ! function_exists( 'lafka_push_p256_spki_from_raw_public' ) ) {
	/**
	 * Wrap a 65-byte uncompressed P-256 public point in X.509 SPKI DER.
	 */
	function lafka_push_p256_spki_from_raw_public( string $raw ): string {
		if ( strlen( $raw ) !== 65 || "\x04" !== $raw[0] ) {
			return '';
		}
		$algo = "\x30\x13"
			. "\x06\x07\x2a\x86\x48\xce\x3d\x02\x01"
			. "\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07";

		$bit_string_body = "\x00" . $raw;
		$bit_string      = "\x03" . chr( strlen( $bit_string_body ) ) . $bit_string_body;

		$inner = $algo . $bit_string;
		$spki  = "\x30" . chr( strlen( $inner ) ) . $inner;
		return $spki;
	}
}

if ( ! function_exists( 'lafka_push_send' ) ) {
	/**
	 * Send a single push to one subscription row.
	 *
	 * On 410 Gone the subscription is deleted (permanent unsubscribe).
	 * On 404 the subscription is marked unsubscribed.
	 */
	function lafka_push_send( $row, array $payload ): array {
		$result = array(
			'ok'        => false,
			'http_code' => 0,
			'response'  => '',
		);
		if ( ! is_object( $row ) ) {
			$result['response'] = 'not_a_row';
			return $result;
		}
		if ( empty( $row->endpoint ) || empty( $row->p256dh ) || empty( $row->auth ) ) {
			$result['response'] = 'missing_keys';
			return $result;
		}
		$vapid = lafka_push_get_vapid_config();
		if ( ! $vapid['enabled'] || '' === $vapid['public'] || '' === $vapid['private'] ) {
			$result['response'] = 'vapid_unconfigured';
			return $result;
		}

		$parsed = parse_url( (string) $row->endpoint );
		if ( ! is_array( $parsed ) || empty( $parsed['scheme'] ) || empty( $parsed['host'] ) ) {
			$result['response'] = 'invalid_endpoint';
			return $result;
		}
		$audience = $parsed['scheme'] . '://' . $parsed['host'];
		if ( ! empty( $parsed['port'] ) ) {
			$audience .= ':' . $parsed['port'];
		}

		$jwt = lafka_push_build_vapid_jwt( $audience, $vapid['subject'], $vapid['private'] );
		if ( '' === $jwt ) {
			$result['response'] = 'jwt_failed';
			return $result;
		}

		$payload_json = function_exists( 'wp_json_encode' ) ? (string) wp_json_encode( $payload ) : (string) json_encode( $payload );
		if ( '' === $payload_json ) {
			$result['response'] = 'empty_payload';
			return $result;
		}

		$enc = lafka_push_encrypt_payload( $payload_json, (string) $row->p256dh, (string) $row->auth );
		if ( null === $enc ) {
			$result['response'] = 'encrypt_failed';
			return $result;
		}

		$headers = array(
			'Authorization: vapid t=' . $jwt . ',k=' . $vapid['public'],
			'Content-Encoding: aes128gcm',
			'Content-Type: application/octet-stream',
			'Content-Length: ' . strlen( $enc['ciphertext'] ),
			'TTL: 86400',
			'Urgency: normal',
		);

		$response = lafka_push_http_post( (string) $row->endpoint, $headers, $enc['ciphertext'] );

		$result['http_code'] = (int) $response['http_code'];
		$result['response']  = (string) $response['body'];

		if ( $result['http_code'] >= 200 && $result['http_code'] < 300 ) {
			$result['ok'] = true;
		}
		if ( 410 === $result['http_code'] ) {
			lafka_push_delete_subscription( (string) $row->endpoint );
		} elseif ( 404 === $result['http_code'] ) {
			lafka_push_mark_unsubscribed( (string) $row->endpoint );
		}
		return $result;
	}
}

if ( ! function_exists( 'lafka_push_http_post' ) ) {
	/**
	 * Tiny cURL wrapper used by lafka_push_send().
	 */
	function lafka_push_http_post( string $url, array $headers, string $body ): array {
		if ( function_exists( 'apply_filters' ) ) {
			$override = apply_filters( 'lafka_push_http_post', null, $url, $headers, $body );
			if ( is_array( $override ) && isset( $override['http_code'] ) ) {
				return array(
					'http_code' => (int) $override['http_code'],
					'body'      => isset( $override['body'] ) ? (string) $override['body'] : '',
				);
			}
		}
		if ( ! function_exists( 'curl_init' ) ) {
			return array(
				'http_code' => 0,
				'body'      => 'curl_missing',
			);
		}
		$ch = curl_init( $url );
		curl_setopt( $ch, CURLOPT_POST, true );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, $body );
		curl_setopt( $ch, CURLOPT_HTTPHEADER, $headers );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_TIMEOUT, 15 );
		curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT, 5 );
		$resp_body = (string) curl_exec( $ch );
		$http_code = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		curl_close( $ch );
		return array(
			'http_code' => $http_code,
			'body'      => $resp_body,
		);
	}
}

if ( ! function_exists( 'lafka_push_resolve_audience' ) ) {
	/**
	 * Translate an audience selector into a user-IDs filter.
	 *
	 *   'all'              - null (every active row)
	 *   'recent_customers' - user IDs who placed an order in last 60 days
	 *   array of ints      - that list, sanitised
	 *
	 * @return array<int,int>|null
	 */
	function lafka_push_resolve_audience( $audience ) {
		if ( 'all' === $audience ) {
			return null;
		}
		if ( 'recent_customers' === $audience ) {
			$ids = array();
			if ( function_exists( 'wc_get_orders' ) ) {
				$orders = wc_get_orders(
					array(
						'limit'        => 500,
						'date_created' => '>' . ( time() - ( 60 * DAY_IN_SECONDS ) ),
						'status'       => array( 'completed', 'processing' ),
						'return'       => 'ids',
					)
				);
				if ( is_array( $orders ) ) {
					foreach ( $orders as $order_id ) {
						if ( ! function_exists( 'wc_get_order' ) ) {
							continue;
						}
						$order = wc_get_order( (int) $order_id );
						if ( ! is_object( $order ) || ! method_exists( $order, 'get_customer_id' ) ) {
							continue;
						}
						$cid = (int) $order->get_customer_id();
						if ( $cid > 0 ) {
							$ids[ $cid ] = $cid;
						}
					}
				}
			}
			return array_values( $ids );
		}
		if ( is_array( $audience ) ) {
			$ids = array();
			foreach ( $audience as $id ) {
				$id = (int) $id;
				if ( $id > 0 ) {
					$ids[ $id ] = $id;
				}
			}
			return array_values( $ids );
		}
		return array();
	}
}

if ( ! function_exists( 'lafka_push_broadcast' ) ) {
	/**
	 * Send the same payload to many subscribers.
	 */
	function lafka_push_broadcast( $audience, array $payload ): array {
		$user_ids = lafka_push_resolve_audience( $audience );
		$rows     = lafka_push_get_active_subscriptions( $user_ids, 5000 );
		$sent     = 0;
		$failed   = 0;
		foreach ( $rows as $row ) {
			$res = lafka_push_send( $row, $payload );
			if ( ! empty( $res['ok'] ) ) {
				++$sent;
			} else {
				++$failed;
			}
		}
		$summary = array(
			'sent'          => $sent,
			'failed'        => $failed,
			'audience_size' => count( $rows ),
		);
		lafka_push_record_activity(
			array(
				'timestamp' => time(),
				'audience'  => is_array( $audience ) ? 'user_ids:' . count( $audience ) : (string) $audience,
				'title'     => isset( $payload['title'] ) ? (string) $payload['title'] : '',
				'sent'      => $sent,
				'failed'    => $failed,
				'size'      => $summary['audience_size'],
			)
		);
		return $summary;
	}
}

if ( ! function_exists( 'lafka_push_record_activity' ) ) {
	/**
	 * Append an activity log entry (last 20 entries in a single option).
	 */
	function lafka_push_record_activity( array $entry ): void {
		if ( ! function_exists( 'get_option' ) || ! function_exists( 'update_option' ) ) {
			return;
		}
		$log = get_option( 'lafka_push_activity_log', array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}
		array_unshift( $log, $entry );
		$log = array_slice( $log, 0, 20 );
		update_option( 'lafka_push_activity_log', $log, false );
	}
}

if ( ! function_exists( 'lafka_push_get_activity_log' ) ) {
	/**
	 * Read the activity log for the admin page.
	 */
	function lafka_push_get_activity_log(): array {
		if ( ! function_exists( 'get_option' ) ) {
			return array();
		}
		$log = get_option( 'lafka_push_activity_log', array() );
		return is_array( $log ) ? $log : array();
	}
}
