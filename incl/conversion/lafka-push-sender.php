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
		// SEC1 ECPrivateKey (RFC 5915). The optional [0] parameters MUST name the
		// SAME curve as the enclosing PKCS#8 AlgorithmIdentifier below — prime256v1
		// (1.2.840.10045.3.1.7), encoded as 06 08 2a 86 48 ce 3d 03 01 07. A stale
		// secp384r1 OID here made OpenSSL 3.x materialise a P-384 key from the
		// 32-byte scalar, producing a 96-byte ES384 signature that the VAPID JOSE
		// conversion (R||S, 32 bytes each) rejected — every send failed jwt_failed.
		// SEQUENCE length is 0x31 (49): 3 (version) + 34 (privateKey octet string)
		// + 12 ([0] + curve OID).
		$sec1 = "\x30\x31\x02\x01\x01\x04\x20" . $raw . "\xa0\x0a\x06\x08\x2a\x86\x48\xce\x3d\x03\x01\x07";

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
		// Belt-and-suspenders SSRF guard for rows that predate the subscribe-time
		// host allowlist (or that were written by an out-of-band path): never
		// send to a host that isn't a known push provider.
		if ( function_exists( 'lafka_push_endpoint_host_allowed' ) && ! lafka_push_endpoint_host_allowed( (string) $row->endpoint ) ) {
			$result['response'] = 'host_not_allowed';
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
			// Refresh the deliverability heartbeat: a 2xx proves the subscription
			// is still live, so bump `last_seen_at` to keep the daily cleanup from
			// ever treating an actively-delivered row as stale. This is the "or
			// send" half of the heartbeat documented on the schema in
			// lafka-push-db.php (previously last_seen_at was only written at
			// subscribe time). One UPDATE per successful send hits the unique
			// `endpoint` index; acceptable even for a 5000-row broadcast.
			global $wpdb;
			if ( isset( $wpdb ) && is_object( $wpdb ) && method_exists( $wpdb, 'update' ) && function_exists( 'lafka_push_table_name' ) ) {
				$now = function_exists( 'current_time' ) ? (string) current_time( 'mysql' ) : gmdate( 'Y-m-d H:i:s' );
				$wpdb->update(
					lafka_push_table_name(),
					array( 'last_seen_at' => $now ),
					array( 'endpoint' => (string) $row->endpoint ),
					array( '%s' ),
					array( '%s' )
				);
			}
		}
		if ( 410 === $result['http_code'] ) {
			lafka_push_delete_subscription( (string) $row->endpoint );
		} elseif ( 404 === $result['http_code'] ) {
			lafka_push_mark_unsubscribed( (string) $row->endpoint );
		}
		return $result;
	}
}

if ( ! function_exists( 'lafka_push_is_safe_remote_host' ) ) {
	/**
	 * SSRF guard for the cURL sender: resolve $host and confirm EVERY address it
	 * points at is a publicly-routable, non-reserved IP.
	 *
	 * We deliberately bypass wp_safe_remote_post() for body/header fidelity (see
	 * the file header), which means we lose WP's built-in reject_unsafe_urls
	 * protection — so we re-implement the IP-range check here. Any address in the
	 * private (10/8, 172.16/12, 192.168/16, fc00::/7) or reserved (127/8,
	 * 169.254/16 link-local incl. the 169.254.169.254 cloud-metadata host, ::1,
	 * 0.0.0.0/8, …) ranges is rejected via FILTER_FLAG_NO_PRIV_RANGE |
	 * FILTER_FLAG_NO_RES_RANGE.
	 *
	 * Fails closed: if the host cannot be resolved to any address we refuse to
	 * connect rather than hand an unvetted name to cURL.
	 *
	 * @param string $host Hostname (or IP literal) from the endpoint URL.
	 * @return bool True only if the host resolves exclusively to safe addresses.
	 */
	function lafka_push_is_safe_remote_host( string $host ): bool {
		$host = strtolower( trim( $host ) );
		$host = trim( $host, '[].' );
		if ( '' === $host ) {
			return false;
		}

		$safe_flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;

		// Host is itself an IP literal — validate it directly.
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			return (bool) filter_var( $host, FILTER_VALIDATE_IP, $safe_flags );
		}

		// Resolve A (IPv4) records.
		$ips = array();
		if ( function_exists( 'gethostbynamel' ) ) {
			$v4 = gethostbynamel( $host );
			if ( is_array( $v4 ) ) {
				$ips = array_merge( $ips, $v4 );
			}
		} elseif ( function_exists( 'gethostbyname' ) ) {
			$v4 = gethostbyname( $host );
			if ( is_string( $v4 ) && '' !== $v4 && $v4 !== $host ) {
				$ips[] = $v4;
			}
		}

		// Resolve AAAA (IPv6) records when DNS is available.
		if ( function_exists( 'dns_get_record' ) && defined( 'DNS_AAAA' ) ) {
			$aaaa = @dns_get_record( $host, DNS_AAAA );
			if ( is_array( $aaaa ) ) {
				foreach ( $aaaa as $rec ) {
					if ( ! empty( $rec['ipv6'] ) ) {
						$ips[] = (string) $rec['ipv6'];
					}
				}
			}
		}

		if ( empty( $ips ) ) {
			// Could not resolve — fail closed.
			return false;
		}

		foreach ( $ips as $ip ) {
			if ( ! filter_var( $ip, FILTER_VALIDATE_IP, $safe_flags ) ) {
				// Any single private/reserved address poisons the whole host.
				return false;
			}
		}
		return true;
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
		// SSRF guard: only ever speak HTTPS to a publicly-routable provider host.
		$parsed = parse_url( $url );
		if ( ! is_array( $parsed ) || empty( $parsed['scheme'] ) || 'https' !== strtolower( (string) $parsed['scheme'] ) || empty( $parsed['host'] ) ) {
			return array(
				'http_code' => 0,
				'body'      => 'blocked_url',
			);
		}
		if ( ! lafka_push_is_safe_remote_host( (string) $parsed['host'] ) ) {
			return array(
				'http_code' => 0,
				'body'      => 'blocked_host',
			);
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
		// Confine the transfer to HTTPS and never follow redirects — an open
		// redirect on a provider must not be able to bounce us to http:// or to
		// an internal host we just refused above.
		if ( defined( 'CURLPROTO_HTTPS' ) ) {
			curl_setopt( $ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTPS );
			curl_setopt( $ch, CURLOPT_REDIR_PROTOCOLS, CURLPROTO_HTTPS );
		}
		curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, false );
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

/*
 * ─────────────────────────────────────────────────────────────────────────────
 * Async (off-request-thread) broadcast queue.
 *
 * The admin "Send now" button used to call lafka_push_broadcast() inline during
 * the page render, which looped a blocking per-row curl_exec over up to 5000
 * rows — any non-trivial audience blew past max_execution_time, killed the
 * request mid-loop, sent partially, and offered no resume.
 *
 * Instead we resolve the audience once, persist a small job record, and hand the
 * actual sending to WP-Cron (matching the reorder/review-email modules). Each
 * cron tick drains a capped batch with a wall-clock budget, persists a row-id
 * cursor after every sub-batch (so a crashed/timed-out tick resumes from where
 * it stopped rather than re-sending), updates the activity-log entry in place so
 * the admin page shows live progress on refresh, and re-schedules itself while
 * rows remain.
 * ─────────────────────────────────────────────────────────────────────────────
 */

if ( ! function_exists( 'lafka_push_count_active_subscriptions' ) ) {
	/**
	 * COUNT active (not-unsubscribed) subscriptions, optionally filtered to a set
	 * of WP user IDs. Used to show the operator the queued audience size without
	 * pulling every row into memory.
	 *
	 * @param array<int,int>|null $user_ids Filter; null = all subscribers.
	 * @return int
	 */
	function lafka_push_count_active_subscriptions( $user_ids = null ): int {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) ) {
			return 0;
		}
		if ( ! function_exists( 'lafka_push_table_name' ) ) {
			return 0;
		}
		$table = lafka_push_table_name();

		if ( is_array( $user_ids ) ) {
			$ids = array_values(
				array_filter(
					array_map( 'intval', $user_ids ),
					static function ( $v ) {
						return $v > 0;
					}
				)
			);
			if ( empty( $ids ) || ! method_exists( $wpdb, 'prepare' ) ) {
				return 0;
			}
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$count        = (int) $wpdb->get_var(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT COUNT(*) FROM {$table} WHERE unsubscribed_at IS NULL AND user_id IN ({$placeholders})",
					$ids
				)
			);
			return max( 0, $count );
		}

		$count = (int) $wpdb->get_var(
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			"SELECT COUNT(*) FROM {$table} WHERE unsubscribed_at IS NULL"
		);
		return max( 0, $count );
	}
}

if ( ! function_exists( 'lafka_push_get_active_subscriptions_after' ) ) {
	/**
	 * Cursor-based fetch of active subscriptions: returns the next `$limit` rows
	 * with `id` strictly greater than `$after_id`, ordered by id ascending.
	 *
	 * A monotonic row-id cursor (rather than a numeric OFFSET) is what makes the
	 * batch loop safe to resume: 410-Gone rows are hard-deleted mid-broadcast, so
	 * an OFFSET window would silently skip rows as the table shrinks, whereas
	 * "id > cursor" never re-sends and never skips.
	 *
	 * @param array<int,int>|null $user_ids Filter; null = all subscribers.
	 * @param int                 $after_id Return rows with id greater than this.
	 * @param int                 $limit    Max rows to return.
	 * @return array<int,object>
	 */
	function lafka_push_get_active_subscriptions_after( $user_ids, int $after_id, int $limit ): array {
		global $wpdb;
		if ( ! isset( $wpdb ) || ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_results' ) || ! method_exists( $wpdb, 'prepare' ) ) {
			return array();
		}
		if ( ! function_exists( 'lafka_push_table_name' ) ) {
			return array();
		}
		$after_id = max( 0, $after_id );
		$limit    = max( 1, min( 500, $limit ) );
		$table    = lafka_push_table_name();

		if ( is_array( $user_ids ) ) {
			$ids = array_values(
				array_filter(
					array_map( 'intval', $user_ids ),
					static function ( $v ) {
						return $v > 0;
					}
				)
			);
			if ( empty( $ids ) ) {
				return array();
			}
			$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
			$args         = array_merge( $ids, array( $after_id, $limit ) );
			$rows         = $wpdb->get_results(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT * FROM {$table} WHERE unsubscribed_at IS NULL AND user_id IN ({$placeholders}) AND id > %d ORDER BY id ASC LIMIT %d",
					$args
				)
			);
			return is_array( $rows ) ? $rows : array();
		}

		$rows = $wpdb->get_results(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT * FROM {$table} WHERE unsubscribed_at IS NULL AND id > %d ORDER BY id ASC LIMIT %d",
				$after_id,
				$limit
			)
		);
		return is_array( $rows ) ? $rows : array();
	}
}

if ( ! function_exists( 'lafka_push_job_option_key' ) ) {
	/**
	 * Option name that holds a single broadcast job's state.
	 */
	function lafka_push_job_option_key( string $job_id ): string {
		return 'lafka_push_job_' . $job_id;
	}
}

if ( ! function_exists( 'lafka_push_generate_job_id' ) ) {
	/**
	 * Generate an opaque, option-name-safe broadcast job id.
	 */
	function lafka_push_generate_job_id(): string {
		if ( function_exists( 'wp_generate_uuid4' ) ) {
			return (string) wp_generate_uuid4();
		}
		if ( function_exists( 'random_bytes' ) ) {
			return bin2hex( random_bytes( 8 ) );
		}
		return (string) uniqid( '', true );
	}
}

if ( ! function_exists( 'lafka_push_save_job' ) ) {
	/**
	 * Persist a broadcast job's state (autoload off — transient-ish working data).
	 *
	 * @param array<string,mixed> $state
	 */
	function lafka_push_save_job( string $job_id, array $state ): void {
		if ( '' === $job_id || ! function_exists( 'update_option' ) ) {
			return;
		}
		update_option( lafka_push_job_option_key( $job_id ), $state, false );
	}
}

if ( ! function_exists( 'lafka_push_get_job' ) ) {
	/**
	 * Read a broadcast job's state, or null if it's gone (completed/cleaned up).
	 *
	 * @return array<string,mixed>|null
	 */
	function lafka_push_get_job( string $job_id ): ?array {
		if ( '' === $job_id || ! function_exists( 'get_option' ) ) {
			return null;
		}
		$state = get_option( lafka_push_job_option_key( $job_id ), null );
		return is_array( $state ) ? $state : null;
	}
}

if ( ! function_exists( 'lafka_push_delete_job' ) ) {
	/**
	 * Drop a finished broadcast job's state option.
	 */
	function lafka_push_delete_job( string $job_id ): void {
		if ( '' === $job_id || ! function_exists( 'delete_option' ) ) {
			return;
		}
		delete_option( lafka_push_job_option_key( $job_id ) );
	}
}

if ( ! function_exists( 'lafka_push_record_job_activity' ) ) {
	/**
	 * Upsert a broadcast job's activity-log entry.
	 *
	 * Unlike lafka_push_record_activity() (which always prepends a fresh row),
	 * this finds the existing entry tagged with `$job_id` and merges the new
	 * counts/status into it in place — so a single broadcast shows as one row
	 * whose Sent/Failed/Status update as each batch drains. If no entry exists
	 * yet (first call) it prepends one and caps the log at 20.
	 *
	 * @param array<string,mixed> $entry
	 */
	function lafka_push_record_job_activity( string $job_id, array $entry ): void {
		if ( '' === $job_id || ! function_exists( 'get_option' ) || ! function_exists( 'update_option' ) ) {
			return;
		}
		$entry['job'] = $job_id;
		$log          = get_option( 'lafka_push_activity_log', array() );
		if ( ! is_array( $log ) ) {
			$log = array();
		}
		$found = false;
		foreach ( $log as $i => $existing ) {
			if ( is_array( $existing ) && isset( $existing['job'] ) && $existing['job'] === $job_id ) {
				$log[ $i ] = array_merge( $existing, $entry );
				$found     = true;
				break;
			}
		}
		if ( ! $found ) {
			array_unshift( $log, $entry );
			$log = array_slice( $log, 0, 20 );
		}
		update_option( 'lafka_push_activity_log', $log, false );
	}
}

if ( ! function_exists( 'lafka_push_enqueue_broadcast' ) ) {
	/**
	 * Queue a broadcast instead of sending it inline.
	 *
	 * Resolves the audience once, persists a job record + a 'queued' activity-log
	 * entry, and schedules the first WP-Cron batch to run immediately. Returns at
	 * once so the admin request never blocks on the send loop.
	 *
	 * @param string|array<int,int> $audience Audience selector.
	 * @param array<string,mixed>   $payload  Notification payload.
	 * @return array<string,mixed> Queue descriptor (queued/job_id/audience_size).
	 */
	function lafka_push_enqueue_broadcast( $audience, array $payload ): array {
		$user_ids       = lafka_push_resolve_audience( $audience );
		$audience_label = is_array( $audience ) ? 'user_ids:' . count( $audience ) : (string) $audience;
		$size           = lafka_push_count_active_subscriptions( $user_ids );
		$job_id         = lafka_push_generate_job_id();
		$now            = time();

		$state = array(
			'id'        => $job_id,
			'status'    => 'queued',
			'audience'  => $audience_label,
			'user_ids'  => is_array( $user_ids ) ? array_values( array_map( 'intval', $user_ids ) ) : null,
			'payload'   => $payload,
			'cursor'    => 0,
			'sent'      => 0,
			'failed'    => 0,
			'processed' => 0,
			'size'      => $size,
			'created'   => $now,
			'updated'   => $now,
		);
		lafka_push_save_job( $job_id, $state );

		lafka_push_record_job_activity(
			$job_id,
			array(
				'timestamp' => $now,
				'audience'  => $audience_label,
				'title'     => isset( $payload['title'] ) ? (string) $payload['title'] : '',
				'sent'      => 0,
				'failed'    => 0,
				'size'      => $size,
				'status'    => 'queued',
			)
		);

		if ( function_exists( 'wp_schedule_single_event' ) ) {
			wp_schedule_single_event( $now, 'lafka_push_broadcast_batch', array( $job_id ) );
		}

		return array(
			'queued'        => true,
			'job_id'        => $job_id,
			'audience_size' => $size,
			'sent'          => 0,
			'failed'        => 0,
		);
	}
}

if ( ! function_exists( 'lafka_push_run_broadcast_batch' ) ) {
	/**
	 * WP-Cron handler for `lafka_push_broadcast_batch`.
	 *
	 * Drains the job in capped sub-batches under a wall-clock budget, persisting
	 * the row-id cursor + running totals after each sub-batch so a timeout/crash
	 * resumes rather than re-sends. Re-schedules itself while rows remain and
	 * finalises the activity-log entry when the audience is exhausted.
	 *
	 * @param string $job_id Broadcast job id.
	 */
	function lafka_push_run_broadcast_batch( $job_id ): void {
		$job_id = (string) $job_id;
		$state  = lafka_push_get_job( $job_id );
		if ( null === $state ) {
			return;
		}
		if ( isset( $state['status'] ) && 'complete' === $state['status'] ) {
			return;
		}

		// This runs in cron context; never let a slow provider kill the worker
		// mid-batch and leave the job un-resumable.
		if ( function_exists( 'set_time_limit' ) ) {
			@set_time_limit( 0 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		if ( function_exists( 'ignore_user_abort' ) ) {
			ignore_user_abort( true );
		}

		$payload   = ( isset( $state['payload'] ) && is_array( $state['payload'] ) ) ? $state['payload'] : array();
		$user_ids  = ( isset( $state['user_ids'] ) && is_array( $state['user_ids'] ) ) ? $state['user_ids'] : null;
		$cursor    = isset( $state['cursor'] ) ? (int) $state['cursor'] : 0;
		$sent      = isset( $state['sent'] ) ? (int) $state['sent'] : 0;
		$failed    = isset( $state['failed'] ) ? (int) $state['failed'] : 0;
		$processed = isset( $state['processed'] ) ? (int) $state['processed'] : 0;
		$created   = isset( $state['created'] ) ? (int) $state['created'] : time();
		$audience  = isset( $state['audience'] ) ? (string) $state['audience'] : '';
		$title     = isset( $payload['title'] ) ? (string) $payload['title'] : '';

		$batch_size = 100;
		if ( function_exists( 'apply_filters' ) ) {
			$batch_size = (int) apply_filters( 'lafka_push_broadcast_batch_size', $batch_size );
		}
		$batch_size = max( 1, min( 500, $batch_size ) );

		$budget = 20;
		if ( function_exists( 'apply_filters' ) ) {
			$budget = (int) apply_filters( 'lafka_push_broadcast_time_budget', $budget );
		}
		$budget = max( 5, min( 50, $budget ) );

		// Hard cap on sub-batches per cron tick, in addition to the wall-clock
		// budget — bounds the work even on a host where time() barely advances.
		$max_batches = 100;
		if ( function_exists( 'apply_filters' ) ) {
			$max_batches = (int) apply_filters( 'lafka_push_broadcast_max_batches_per_tick', $max_batches );
		}
		$max_batches = max( 1, min( 1000, $max_batches ) );

		$state['status'] = 'sending';
		$start           = time();
		$batches_done    = 0;
		$complete        = false;

		do {
			$rows = lafka_push_get_active_subscriptions_after( $user_ids, $cursor, $batch_size );
			$n    = is_array( $rows ) ? count( $rows ) : 0;
			if ( 0 === $n ) {
				$complete = true;
				break;
			}
			foreach ( $rows as $row ) {
				$res = lafka_push_send( $row, $payload );
				if ( ! empty( $res['ok'] ) ) {
					++$sent;
				} else {
					++$failed;
				}
				if ( is_object( $row ) && isset( $row->id ) ) {
					$cursor = (int) $row->id;
				}
				++$processed;
			}

			// Persist the cursor + totals after every sub-batch so a crash here
			// resumes from $cursor rather than starting over.
			$state['cursor']    = $cursor;
			$state['sent']      = $sent;
			$state['failed']    = $failed;
			$state['processed'] = $processed;
			$state['updated']   = time();
			lafka_push_save_job( $job_id, $state );

			lafka_push_record_job_activity(
				$job_id,
				array(
					'timestamp' => $created,
					'audience'  => $audience,
					'title'     => $title,
					'sent'      => $sent,
					'failed'    => $failed,
					'size'      => isset( $state['size'] ) ? (int) $state['size'] : $processed,
					'status'    => 'sending',
				)
			);

			// A short page means we've reached the end of the audience.
			if ( $n < $batch_size ) {
				$complete = true;
				break;
			}

			++$batches_done;
			if ( $batches_done >= $max_batches ) {
				break;
			}
		} while ( ( time() - $start ) < $budget );

		if ( $complete ) {
			lafka_push_record_job_activity(
				$job_id,
				array(
					'timestamp' => $created,
					'audience'  => $audience,
					'title'     => $title,
					'sent'      => $sent,
					'failed'    => $failed,
					'size'      => max( isset( $state['size'] ) ? (int) $state['size'] : 0, $processed ),
					'status'    => 'done',
				)
			);
			lafka_push_delete_job( $job_id );
			return;
		}

		// Budget exhausted but rows remain — checkpoint and hand off to the next
		// cron tick, which resumes from the persisted cursor.
		$state['updated'] = time();
		lafka_push_save_job( $job_id, $state );
		if ( function_exists( 'wp_schedule_single_event' ) ) {
			wp_schedule_single_event( time(), 'lafka_push_broadcast_batch', array( $job_id ) );
		}
	}
}

if ( function_exists( 'add_action' ) ) {
	add_action( 'lafka_push_broadcast_batch', 'lafka_push_run_broadcast_batch', 10, 1 );
}
