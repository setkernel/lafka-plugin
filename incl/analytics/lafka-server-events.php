<?php
/**
 * Server-side conversions: Meta Conversions API and the GA4 Measurement
 * Protocol, sent once per paid order from an Action Scheduler job.
 *
 * Why: ad blockers, Safari's tracking limits and payment redirects that never
 * return to the thank-you page drop a share of browser-side purchases. The
 * server knows every paid order.
 *
 *   - Meta: one Purchase per order with event_id `purchase-<order id>`, the
 *     same id the Pixel sends, so Meta deduplicates the pair. Email and phone
 *     are normalised and SHA-256 hashed as Meta requires.
 *   - GA4: a purchase only when the thank-you page never emitted one (GA4 has
 *     no browser/server dedupe), keyed to the visitor's GA client id.
 *
 * Consent: a snapshot taken at checkout through lafka_has_consent() (the
 * mirrored banner cookies, a WP Consent API plugin, or the configured defaults). Nothing is sent without the matching consent.
 *
 * Settings live in the `lafka_tracking` option (Customizer → Lafka —
 * Analytics → direct IDs): `lafka_meta_capi_token`, `lafka_ga4_api_secret`.
 * When the official "Meta for WooCommerce" plugin is active it owns the
 * Conversions API and this module stands aside.
 *
 * @package Lafka\Plugin\Analytics
 * @since   10.4.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'lafka_server_events_meta_ready' ) ) {
	/**
	 * Meta Conversions API is configured: a Pixel ID and an access token, and
	 * the official Meta plugin is not handling it.
	 *
	 * @return bool
	 */
	function lafka_server_events_meta_ready(): bool {
		$token = lafka_analytics_get_setting( 'lafka_meta_capi_token', '' );
		return '' !== lafka_analytics_meta_pixel_id()
			&& 1 === preg_match( '/^[A-Za-z0-9_-]{20,400}$/', $token )
			&& ! class_exists( 'WC_Facebookcommerce' );
	}
}

if ( ! function_exists( 'lafka_server_events_ga4_ready' ) ) {
	/**
	 * GA4 Measurement Protocol is configured: a measurement ID and an API
	 * secret.
	 *
	 * @return bool
	 */
	function lafka_server_events_ga4_ready(): bool {
		$secret = lafka_analytics_get_setting( 'lafka_ga4_api_secret', '' );
		return '' !== lafka_analytics_ga4_id() && 1 === preg_match( '/^[A-Za-z0-9_-]{10,100}$/', $secret );
	}
}

if ( ! function_exists( 'lafka_server_events_any_ready' ) ) {
	/**
	 * Whether any server-side destination is configured.
	 *
	 * @return bool
	 */
	function lafka_server_events_any_ready(): bool {
		return lafka_server_events_meta_ready() || lafka_server_events_ga4_ready();
	}
}

if ( ! function_exists( 'lafka_server_events_capture' ) ) {
	/**
	 * At checkout, store what a later server-side send needs and cannot read
	 * from the order: the consent snapshot, the GA client id, and Meta's
	 * browser ids. Classic and block checkout both land here.
	 *
	 * @param int|WC_Order $order Order or order id.
	 * @return void
	 */
	function lafka_server_events_capture( $order ): void {
		if ( ! lafka_server_events_any_ready() ) {
			return;
		}
		$order = is_object( $order ) ? $order : wc_get_order( (int) $order );
		if ( ! $order ) {
			return;
		}
		$cookie = static function ( string $name ): string {
			return isset( $_COOKIE[ $name ] ) ? sanitize_text_field( wp_unslash( $_COOKIE[ $name ] ) ) : '';
		};

		// _ga is "GA1.1.<random>.<timestamp>"; the client id is the last two parts.
		$client_id = '';
		if ( preg_match( '/^GA\d\.\d\.(\d+\.\d+)$/', $cookie( '_ga' ), $m ) ) {
			$client_id = $m[1];
		}

		$order->update_meta_data(
			'_lafka_server_events',
			array(
				'analytics' => lafka_has_consent( 'analytics' ),
				'ads'       => lafka_has_consent( 'ads' ),
				'ga_cid'    => $client_id,
				'fbp'       => $cookie( '_fbp' ),
				'fbc'       => $cookie( '_fbc' ),
				'url'       => function_exists( 'wc_get_checkout_url' ) ? wc_get_checkout_url() : home_url( '/' ),
			)
		);
		$order->save();
	}
}

if ( ! function_exists( 'lafka_server_events_schedule' ) ) {
	/**
	 * Queue the send once the order is paid (processing or completed; cash
	 * orders go straight to processing). The Pixel and GA4 browser events get
	 * ten minutes to land first, which the GA4 send checks.
	 *
	 * @param int $order_id Order id.
	 * @return void
	 */
	function lafka_server_events_schedule( $order_id ): void {
		$order_id = (int) $order_id;
		if ( $order_id <= 0 || ! lafka_server_events_any_ready() || ! function_exists( 'as_schedule_single_action' ) ) {
			return;
		}
		$args = array( $order_id );
		if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( 'lafka_server_events_send', $args, 'lafka' ) ) {
			return;
		}
		as_schedule_single_action( time() + 10 * MINUTE_IN_SECONDS, 'lafka_server_events_send', $args, 'lafka' );
	}
}

if ( ! function_exists( 'lafka_server_events_send' ) ) {
	/**
	 * Send the paid order to each configured destination its consent allows,
	 * once per destination.
	 *
	 * @param int $order_id Order id.
	 * @return void
	 */
	function lafka_server_events_send( $order_id ): void {
		$order = wc_get_order( (int) $order_id );
		if ( ! $order ) {
			return;
		}
		// get_meta() returns '' when unset; only an array is a real record.
		$ctx  = $order->get_meta( '_lafka_server_events', true );
		$ctx  = is_array( $ctx ) ? $ctx : array();
		$sent = $order->get_meta( '_lafka_server_events_sent', true );
		$sent = is_array( $sent ) ? $sent : array();

		if ( empty( $sent['meta'] ) && ! empty( $ctx['ads'] ) && lafka_server_events_meta_ready() ) {
			$sent['meta'] = lafka_server_events_send_meta( $order, $ctx ) ? time() : 0;
		}
		$browser_sent = '1' === (string) $order->get_meta( '_lafka_dl_purchase_fired', true );
		if ( empty( $sent['ga4'] ) && ! $browser_sent && ! empty( $ctx['analytics'] ) && ! empty( $ctx['ga_cid'] ) && lafka_server_events_ga4_ready() ) {
			$sent['ga4'] = lafka_server_events_send_ga4( $order, $ctx ) ? time() : 0;
		}

		$order->update_meta_data( '_lafka_server_events_sent', $sent );
		$order->save();
	}
}

if ( ! function_exists( 'lafka_server_events_hash' ) ) {
	/**
	 * SHA-256 of a normalised identifier, as the Conversions API requires.
	 *
	 * @param string $value Already normalised value.
	 * @return string[] One hash, or none for an empty value.
	 */
	function lafka_server_events_hash( string $value ): array {
		return '' === $value ? array() : array( hash( 'sha256', $value ) );
	}
}

if ( ! function_exists( 'lafka_server_events_send_meta' ) ) {
	/**
	 * Meta Conversions API Purchase.
	 *
	 * @param WC_Order             $order Order.
	 * @param array<string, mixed> $ctx   Checkout snapshot.
	 * @return bool Whether Meta accepted it.
	 */
	function lafka_server_events_send_meta( $order, array $ctx ): bool {
		$items = lafka_dl_order_items( $order );
		$phone = preg_replace( '/\D/', '', (string) $order->get_billing_phone() );
		$code  = function_exists( 'WC' ) && WC()->countries ? ltrim( (string) WC()->countries->get_country_calling_code( (string) $order->get_billing_country() ), '+' ) : '';
		if ( '' !== $phone && '' !== $code && 0 !== strpos( $phone, $code ) ) {
			$phone = $code . ltrim( $phone, '0' );
		}

		$user = array_filter(
			array(
				'em'                => lafka_server_events_hash( strtolower( trim( (string) $order->get_billing_email() ) ) ),
				'ph'                => lafka_server_events_hash( (string) $phone ),
				'fn'                => lafka_server_events_hash( strtolower( trim( (string) $order->get_billing_first_name() ) ) ),
				'ln'                => lafka_server_events_hash( strtolower( trim( (string) $order->get_billing_last_name() ) ) ),
				'client_ip_address' => (string) $order->get_customer_ip_address(),
				'client_user_agent' => (string) $order->get_customer_user_agent(),
				'fbp'               => (string) ( $ctx['fbp'] ?? '' ),
				'fbc'               => (string) ( $ctx['fbc'] ?? '' ),
			)
		);

		$event = array(
			'event_name'       => 'Purchase',
			'event_time'       => $order->get_date_created() ? $order->get_date_created()->getTimestamp() : time(),
			'event_id'         => 'purchase-' . $order->get_id(),
			'action_source'    => 'website',
			'event_source_url' => (string) ( $ctx['url'] ?? home_url( '/' ) ),
			'user_data'        => $user,
			'custom_data'      => array(
				'currency'     => $order->get_currency(),
				'value'        => round( (float) $order->get_total(), 2 ),
				'order_id'     => (string) $order->get_id(),
				'content_type' => 'product',
				'content_ids'  => array_map(
					static function ( $item ) {
						return (string) $item['item_id'];
					},
					$items
				),
				'contents'     => array_map(
					static function ( $item ) {
						return array(
							'id'         => (string) $item['item_id'],
							'quantity'   => (int) $item['quantity'],
							'item_price' => (float) $item['price'],
						);
					},
					$items
				),
			),
		);

		/**
		 * Filter the Graph API version used for the Conversions API.
		 *
		 * @since 10.4.0
		 * @param string $version e.g. 'v24.0'.
		 */
		$version = (string) apply_filters( 'lafka_meta_capi_api_version', 'v24.0' );
		$url     = 'https://graph.facebook.com/' . rawurlencode( $version ) . '/' . rawurlencode( lafka_analytics_meta_pixel_id() ) . '/events';
		$body    = array(
			'data'         => array( $event ),
			'access_token' => lafka_analytics_get_setting( 'lafka_meta_capi_token', '' ),
		);

		return lafka_server_events_post( 'meta', $url, $body, $order->get_id() );
	}
}

if ( ! function_exists( 'lafka_server_events_send_ga4' ) ) {
	/**
	 * GA4 Measurement Protocol purchase.
	 *
	 * @param WC_Order             $order Order.
	 * @param array<string, mixed> $ctx   Checkout snapshot.
	 * @return bool Whether GA4 accepted it.
	 */
	function lafka_server_events_send_ga4( $order, array $ctx ): bool {
		$params  = array(
			'transaction_id' => (string) $order->get_id(),
			'currency'       => $order->get_currency(),
			'value'          => round( (float) $order->get_total(), 2 ),
			'tax'            => round( (float) $order->get_total_tax(), 2 ),
			'shipping'       => round( (float) $order->get_shipping_total(), 2 ),
			'items'          => lafka_dl_order_items( $order ),
		);
		$coupons = (array) $order->get_coupon_codes();
		if ( array() !== $coupons ) {
			$params['coupon'] = implode( ',', $coupons );
		}
		$url  = add_query_arg(
			array(
				'measurement_id' => rawurlencode( lafka_analytics_ga4_id() ),
				'api_secret'     => rawurlencode( lafka_analytics_get_setting( 'lafka_ga4_api_secret', '' ) ),
			),
			'https://www.google-analytics.com/mp/collect'
		);
		$body = array(
			'client_id' => (string) $ctx['ga_cid'],
			'events'    => array(
				array(
					'name'   => 'purchase',
					'params' => $params,
				),
			),
		);

		return lafka_server_events_post( 'ga4', $url, $body, $order->get_id() );
	}
}

if ( ! function_exists( 'lafka_server_events_post' ) ) {
	/**
	 * POST JSON and log a failure to the analytics channel.
	 *
	 * @param string               $destination 'meta' or 'ga4' (for the log).
	 * @param string               $url         Endpoint.
	 * @param array<string, mixed> $body        JSON body.
	 * @param int                  $order_id    Order id (for the log).
	 * @return bool Whether the endpoint answered 2xx.
	 */
	function lafka_server_events_post( string $destination, string $url, array $body, int $order_id ): bool {
		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 10,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $body ),
			)
		);
		$code     = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 200 && $code < 300 ) {
			return true;
		}
		if ( class_exists( 'Lafka_Log' ) ) {
			Lafka_Log::warning(
				'analytics',
				'Server-side conversion not accepted',
				array(
					'code'        => 'server_conversion_failed',
					'destination' => $destination,
					'order_id'    => $order_id,
					'http_status' => $code,
					'error'       => is_wp_error( $response ) ? $response->get_error_message() : '',
				)
			);
		}
		return false;
	}
}

if ( function_exists( 'add_action' ) ) {
	add_action( 'woocommerce_checkout_order_processed', 'lafka_server_events_capture', 20 );
	add_action( 'woocommerce_store_api_checkout_order_processed', 'lafka_server_events_capture', 20 );
	add_action( 'woocommerce_order_status_processing', 'lafka_server_events_schedule' );
	add_action( 'woocommerce_order_status_completed', 'lafka_server_events_schedule' );
	add_action( 'lafka_server_events_send', function_exists( 'lafka_guarded' ) ? lafka_guarded( 'lafka_server_events_send' ) : 'lafka_server_events_send' );
}
