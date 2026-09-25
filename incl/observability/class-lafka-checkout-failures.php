<?php
/**
 * Lafka_Checkout_Failures — the "why no order" feed (GX1 / A7).
 *
 * Two jobs:
 *
 *   1. Observe the refusals WooCommerce owns and translate them into
 *      `lafka_checkout_blocked` (Lafka's own gates emit directly):
 *        · classic checkout validation   woocommerce_after_checkout_validation
 *          (error CODES only, never the submitted values)
 *        · block checkout rejections      rest_request_after_callbacks on the
 *          Store API /checkout route (WP_Error / HTTP ≥ 400 responses)
 *        · payment failures               woocommerce_order_status_failed +
 *          woocommerce_order_note_added (retries on an already-failed order),
 *          classified declined / avs / cvv / gateway_error / other from the
 *          gateway's failure note (filterable keyword map).
 *
 *   2. Listen to `lafka_checkout_blocked` (priority 10) and record it:
 *        · Lafka_Log — `payment` channel at warning (→ incident + digest) for
 *          payment failures, `checkout` channel at notice for everything else;
 *        · a 35-day per-day counter (option `lafka_log_checkout_stats`, not
 *          autoloaded) behind the Diagnostics "Checkout failures" tab and the
 *          Site Health payment-failure test.
 *
 * @package Lafka\Plugin\Observability
 * @since   10.2.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Lafka_Checkout_Failures' ) ) {

	/**
	 * Checkout / payment refusal observer + recorder.
	 */
	final class Lafka_Checkout_Failures {

		/** Per-day counters: [ 'Y-m-d' => [ reason => count ] ]. */
		const STATS_OPTION = 'lafka_log_checkout_stats';

		/** Days of counters kept. */
		const STATS_DAYS = 35;

		/** Store API checkout route (versioned or not). */
		const CHECKOUT_ROUTE = '#^/wc/store(?:/v\d+)?/checkout/?$#';

		/** @var bool Whether the current request is a Store API place-order POST. */
		private static $store_api_checkout = false;

		/** @var array<int,bool> Orders whose payment failure was emitted this request. */
		private static $payment_orders = array();

		/**
		 * Wire the observers + the recorder.
		 *
		 * @return void
		 */
		public static function register(): void {
			add_action( Lafka_Checkout_Block_Reasons::ACTION, array( __CLASS__, 'on_blocked' ), 10, 2 );
			add_action( 'woocommerce_after_checkout_validation', array( __CLASS__, 'on_classic_validation' ), PHP_INT_MAX, 2 );
			add_filter( 'rest_request_before_callbacks', array( __CLASS__, 'on_rest_before' ), 10, 3 );
			add_filter( 'rest_request_after_callbacks', array( __CLASS__, 'on_rest_after' ), 10, 3 );
			add_action( 'woocommerce_order_status_failed', array( __CLASS__, 'on_order_failed' ), 10, 3 );
			add_action( 'woocommerce_order_note_added', array( __CLASS__, 'on_order_note_added' ), 10, 2 );
		}

		// ─── Recorder ───────────────────────────────────────────────────────

		/**
		 * `lafka_checkout_blocked` listener: log + count.
		 *
		 * @param mixed $reason  Reason constant.
		 * @param mixed $context Context.
		 * @return void
		 */
		public static function on_blocked( $reason, $context = array() ): void {
			$reason  = is_string( $reason ) ? $reason : '';
			$context = is_array( $context ) ? $context : array();
			if ( ! Lafka_Checkout_Block_Reasons::is_known( $reason ) ) {
				return;
			}

			self::record_stat( $reason );

			if ( ! class_exists( 'Lafka_Log' ) ) {
				return;
			}
			if ( Lafka_Checkout_Block_Reasons::is_payment( $reason ) ) {
				$class           = isset( $context['class'] ) ? (string) $context['class'] : 'other';
				$context['code'] = $reason;
				Lafka_Log::warning( 'payment', sprintf( 'Payment failed: %s', $class ), $context );
				return;
			}
			$context['code'] = isset( $context['code'] ) && '' !== $context['code'] ? $context['code'] : $reason;
			$context['reason'] = $reason;
			Lafka_Log::notice( 'checkout', sprintf( 'Checkout blocked: %s', $reason ), $context );
		}

		/**
		 * Bump today's counter for a reason and drop days past the window.
		 *
		 * @param string $reason Reason.
		 * @return void
		 */
		public static function record_stat( string $reason ): void {
			if ( ! function_exists( 'get_option' ) || ! function_exists( 'update_option' ) ) {
				return;
			}
			$stats = get_option( self::STATS_OPTION, array() );
			$stats = is_array( $stats ) ? $stats : array();
			$today = self::today();

			$stats[ $today ][ $reason ] = (int) ( $stats[ $today ][ $reason ] ?? 0 ) + 1;
			$stats                      = self::prune( $stats );

			update_option( self::STATS_OPTION, $stats, false );
		}

		/**
		 * Totals per reason over the last $days days (today included).
		 *
		 * @param int                     $days  Window.
		 * @param array<string,array>|null $stats Counters (default: the option).
		 * @return array<string,int> reason => count, highest first.
		 */
		public static function totals( int $days = 30, ?array $stats = null ): array {
			if ( null === $stats ) {
				$stats = function_exists( 'get_option' ) ? get_option( self::STATS_OPTION, array() ) : array();
				$stats = is_array( $stats ) ? $stats : array();
			}
			$cutoff = self::day_offset( - ( max( 1, $days ) - 1 ) );
			$out    = array();
			foreach ( $stats as $day => $reasons ) {
				if ( (string) $day < $cutoff || ! is_array( $reasons ) ) {
					continue;
				}
				foreach ( $reasons as $reason => $n ) {
					$out[ (string) $reason ] = ( $out[ (string) $reason ] ?? 0 ) + (int) $n;
				}
			}
			arsort( $out );
			return $out;
		}

		/**
		 * Sum of payment-failure counts over the last $days days.
		 *
		 * @param int $days Window.
		 * @return int
		 */
		public static function payment_failures( int $days = 7 ): int {
			$total = 0;
			foreach ( self::totals( $days ) as $reason => $n ) {
				if ( Lafka_Checkout_Block_Reasons::is_payment( (string) $reason ) ) {
					$total += $n;
				}
			}
			return $total;
		}

		/**
		 * @param array<string,array> $stats Counters.
		 * @return array<string,array>
		 */
		private static function prune( array $stats ): array {
			$cutoff = self::day_offset( - ( self::STATS_DAYS - 1 ) );
			foreach ( array_keys( $stats ) as $day ) {
				if ( (string) $day < $cutoff ) {
					unset( $stats[ $day ] );
				}
			}
			ksort( $stats );
			return $stats;
		}

		/**
		 * Drop expired counter days (daily job).
		 *
		 * @return void
		 */
		public static function prune_stats(): void {
			if ( ! function_exists( 'get_option' ) || ! function_exists( 'update_option' ) ) {
				return;
			}
			$stats = get_option( self::STATS_OPTION, array() );
			if ( is_array( $stats ) && ! empty( $stats ) ) {
				update_option( self::STATS_OPTION, self::prune( $stats ), false );
			}
		}

		/** @return string Today in the site timezone (Y-m-d). */
		private static function today(): string {
			return self::day_offset( 0 );
		}

		/**
		 * Site-local date $offset days from today.
		 *
		 * @param int $offset Days (negative = past).
		 * @return string
		 */
		private static function day_offset( int $offset ): string {
			$tz = function_exists( 'wp_timezone' ) ? wp_timezone() : new \DateTimeZone( 'UTC' );
			$dt = new \DateTimeImmutable( 'now', $tz );
			return $dt->modify( sprintf( '%+d days', $offset ) )->format( 'Y-m-d' );
		}

		// ─── Classic checkout validation ────────────────────────────────────

		/**
		 * `woocommerce_after_checkout_validation` (last): turn WC's validation
		 * error codes into reasons. Only codes are read — never the values.
		 *
		 * @param mixed $data   Posted data (unused — values are never logged).
		 * @param mixed $errors WP_Error.
		 * @return void
		 */
		public static function on_classic_validation( $data, $errors = null ): void {
			unset( $data );
			if ( ! is_object( $errors ) || ! method_exists( $errors, 'get_error_codes' ) ) {
				return;
			}
			$codes = self::clean_codes( (array) $errors->get_error_codes() );
			if ( empty( $codes ) ) {
				return;
			}
			self::emit_for_codes( $codes, 'classic' );
		}

		/**
		 * Emit reasons for a list of WooCommerce error codes.
		 *
		 * @param array<int,string> $codes Codes.
		 * @param string            $path  classic | store_api.
		 * @return void
		 */
		private static function emit_for_codes( array $codes, string $path ): void {
			$fields = array();
			foreach ( $codes as $code ) {
				$lafka = Lafka_Checkout_Block_Reasons::from_code( $code );
				if ( null !== $lafka ) {
					Lafka_Checkout_Block_Reasons::emit(
						$lafka,
						array(
							'path'  => $path,
							'stage' => 'checkout',
							'code'  => $code,
						)
					);
					continue;
				}
				if ( self::is_shipping_method_code( $code ) ) {
					Lafka_Checkout_Block_Reasons::emit(
						Lafka_Checkout_Block_Reasons::NO_SHIPPING_METHOD,
						array(
							'path'  => $path,
							'stage' => 'checkout',
							'code'  => $code,
						)
					);
					continue;
				}
				$fields[] = $code;
			}
			if ( ! empty( $fields ) ) {
				Lafka_Checkout_Block_Reasons::emit(
					Lafka_Checkout_Block_Reasons::FIELD_VALIDATION,
					array(
						'path'   => $path,
						'stage'  => 'checkout',
						'code'   => $fields[0],
						'fields' => array_slice( $fields, 0, 20 ),
					)
				);
			}
		}

		// ─── Store API (block checkout) ─────────────────────────────────────

		/**
		 * Whether a REST route is the Store API checkout route.
		 *
		 * @param string $route Route.
		 * @return bool
		 */
		public static function is_checkout_route( string $route ): bool {
			return 1 === preg_match( self::CHECKOUT_ROUTE, $route );
		}

		/**
		 * Flag Store API place-order requests so gates evaluated on every cart
		 * read (add_cart_errors) only emit when an order is actually attempted.
		 *
		 * @param mixed $response Response so far (passed through).
		 * @param mixed $handler  Handler (unused).
		 * @param mixed $request  WP_REST_Request.
		 * @return mixed
		 */
		public static function on_rest_before( $response, $handler = null, $request = null ) {
			unset( $handler );
			if ( self::is_checkout_post( $request ) ) {
				self::$store_api_checkout = true;
			}
			return $response;
		}

		/**
		 * Whether the current request is a Store API place-order POST.
		 *
		 * @return bool
		 */
		public static function in_store_api_checkout(): bool {
			return self::$store_api_checkout;
		}

		/**
		 * Translate a failed Store API checkout response into reasons.
		 *
		 * @param mixed $response WP_REST_Response | WP_Error.
		 * @param mixed $handler  Handler (unused).
		 * @param mixed $request  WP_REST_Request.
		 * @return mixed The response, unchanged.
		 */
		public static function on_rest_after( $response, $handler = null, $request = null ) {
			unset( $handler );
			if ( ! self::is_checkout_post( $request ) ) {
				return $response;
			}
			$errors = self::extract_errors( $response );
			if ( empty( $errors['codes'] ) ) {
				return $response;
			}

			$codes = array();
			foreach ( $errors['codes'] as $code ) {
				if ( 'woocommerce_rest_checkout_process_payment_error' === $code || false !== strpos( $code, 'payment_error' ) ) {
					if ( ! self::payment_emitted_this_request() ) {
						$class = self::classify_payment_failure( (string) $errors['message'] );
						Lafka_Checkout_Block_Reasons::emit(
							Lafka_Checkout_Block_Reasons::from_payment_class( $class ),
							array(
								'path'  => 'store_api',
								'stage' => 'payment',
								'code'  => $code,
								'class' => $class,
							)
						);
					}
					continue;
				}
				$codes[] = $code;
			}

			$fields = array_merge( $errors['fields'], array() );
			$known  = array();
			$other  = array();
			foreach ( $codes as $code ) {
				if ( 'rest_invalid_param' === $code || 'rest_missing_callback_param' === $code ) {
					continue; // Represented by the offending field names below.
				}
				if ( null !== Lafka_Checkout_Block_Reasons::from_code( $code ) || self::is_shipping_method_code( $code ) ) {
					$known[] = $code;
				} elseif ( preg_match( '/invalid_email|address|missing|required|invalid_param|_validation/', $code ) ) {
					$fields[] = $code;
				} else {
					$other[] = $code;
				}
			}
			self::emit_for_codes( array_values( array_unique( array_merge( $known, $fields ) ) ), 'store_api' );

			foreach ( $other as $code ) {
				Lafka_Checkout_Block_Reasons::emit(
					Lafka_Checkout_Block_Reasons::STORE_API_ERROR,
					array(
						'path'  => 'store_api',
						'stage' => 'checkout',
						'code'  => $code,
					)
				);
				break; // One generic reason per attempt.
			}

			if ( $errors['status'] >= 500 && class_exists( 'Lafka_Log' ) ) {
				Lafka_Log::error(
					'store-api',
					sprintf( 'Store API checkout failed with HTTP %d', $errors['status'] ),
					array( 'code' => $errors['codes'][0] )
				);
			}
			return $response;
		}

		/**
		 * Error codes, offending field names, first message and HTTP status of
		 * a REST response. Messages are only used for payment classification.
		 *
		 * @param mixed $response WP_REST_Response | WP_Error.
		 * @return array{codes:array<int,string>,fields:array<int,string>,message:string,status:int}
		 */
		public static function extract_errors( $response ): array {
			$out = array(
				'codes'   => array(),
				'fields'  => array(),
				'message' => '',
				'status'  => 0,
			);

			$entries = array();
			if ( is_object( $response ) && method_exists( $response, 'get_error_codes' ) ) {
				foreach ( (array) $response->get_error_codes() as $code ) {
					$entries[] = array(
						'code'    => (string) $code,
						'message' => method_exists( $response, 'get_error_message' ) ? (string) $response->get_error_message( $code ) : '',
						'data'    => method_exists( $response, 'get_error_data' ) ? $response->get_error_data( $code ) : null,
					);
				}
				foreach ( $entries as $entry ) {
					if ( is_array( $entry['data'] ) && isset( $entry['data']['status'] ) ) {
						$out['status'] = (int) $entry['data']['status'];
						break;
					}
				}
				if ( 0 === $out['status'] && ! empty( $entries ) ) {
					$out['status'] = 500;
				}
			} elseif ( is_object( $response ) && method_exists( $response, 'get_status' ) && method_exists( $response, 'get_data' ) ) {
				$out['status'] = (int) $response->get_status();
				$data          = $response->get_data();
				if ( $out['status'] < 400 || ! is_array( $data ) || empty( $data['code'] ) ) {
					return $out;
				}
				$entries[] = array(
					'code'    => (string) $data['code'],
					'message' => (string) ( $data['message'] ?? '' ),
					'data'    => $data['data'] ?? null,
				);
				foreach ( (array) ( $data['additional_errors'] ?? array() ) as $extra ) {
					if ( is_array( $extra ) && ! empty( $extra['code'] ) ) {
						$entries[] = array(
							'code'    => (string) $extra['code'],
							'message' => (string) ( $extra['message'] ?? '' ),
							'data'    => $extra['data'] ?? null,
						);
					}
				}
			} else {
				return $out;
			}

			foreach ( $entries as $entry ) {
				$out['codes'][] = $entry['code'];
				if ( '' === $out['message'] ) {
					$out['message'] = $entry['message'];
				}
				$data = is_array( $entry['data'] ) ? $entry['data'] : array();
				// rest_invalid_param: data.params = { field => message }, data.details = { field => { code } }.
				foreach ( array_keys( (array) ( $data['params'] ?? array() ) ) as $field ) {
					$out['fields'][] = (string) $field;
				}
				foreach ( (array) ( $data['details'] ?? array() ) as $field => $detail ) {
					$out['fields'][] = is_array( $detail ) && ! empty( $detail['code'] ) ? (string) $detail['code'] : (string) $field;
				}
			}
			$out['codes']  = self::clean_codes( $out['codes'] );
			$out['fields'] = self::clean_codes( $out['fields'] );
			return $out;
		}

		/**
		 * @param mixed $request WP_REST_Request.
		 * @return bool
		 */
		private static function is_checkout_post( $request ): bool {
			return is_object( $request )
				&& method_exists( $request, 'get_route' )
				&& method_exists( $request, 'get_method' )
				&& 'POST' === strtoupper( (string) $request->get_method() )
				&& self::is_checkout_route( (string) $request->get_route() );
		}

		// ─── Payment failures ───────────────────────────────────────────────

		/**
		 * `woocommerce_order_status_failed` — the first failure of an order.
		 *
		 * @param mixed $order_id   Order id.
		 * @param mixed $order      WC_Order.
		 * @param mixed $transition Status transition (note, manual…).
		 * @return void
		 */
		public static function on_order_failed( $order_id, $order = null, $transition = array() ): void {
			if ( is_array( $transition ) && ! empty( $transition['manual'] ) ) {
				return; // An admin changed the status by hand — not a customer payment failure.
			}
			if ( ! is_object( $order ) && function_exists( 'wc_get_order' ) ) {
				$order = wc_get_order( $order_id );
			}
			if ( ! is_object( $order ) || ! method_exists( $order, 'get_id' ) ) {
				return;
			}
			$note = is_array( $transition ) && isset( $transition['note'] ) ? (string) $transition['note'] : '';
			if ( 'other' === self::classify_payment_failure( $note ) ) {
				$note .= ' ' . self::recent_note_text( (int) $order->get_id() );
			}
			self::emit_payment_failure( $order, $note );
		}

		/**
		 * `woocommerce_order_note_added` — catches retries on an order that is
		 * already failed (gateways add a note instead of re-transitioning).
		 *
		 * @param mixed $comment_id Note id.
		 * @param mixed $order      WC_Order.
		 * @return void
		 */
		public static function on_order_note_added( $comment_id, $order = null ): void {
			if ( ! is_object( $order ) || ! method_exists( $order, 'get_status' ) || 'failed' !== $order->get_status() ) {
				return;
			}
			if ( function_exists( 'is_admin' ) && is_admin() && ! ( function_exists( 'wp_doing_ajax' ) && wp_doing_ajax() ) ) {
				return; // Notes typed on the order screen are not payment attempts.
			}
			if ( isset( self::$payment_orders[ (int) $order->get_id() ] ) ) {
				return;
			}
			$comment = function_exists( 'get_comment' ) ? get_comment( $comment_id ) : null;
			$text    = is_object( $comment ) && isset( $comment->comment_content ) ? (string) $comment->comment_content : '';
			if ( ! preg_match( '/fail|declin|denied|reject|error/i', $text ) ) {
				return;
			}
			self::emit_payment_failure( $order, $text );
		}

		/**
		 * Classify + emit one payment failure per order per request.
		 *
		 * @param object $order WC_Order.
		 * @param string $text  Gateway note text (classified, never stored).
		 * @return void
		 */
		private static function emit_payment_failure( $order, string $text ): void {
			$order_id = (int) $order->get_id();
			if ( isset( self::$payment_orders[ $order_id ] ) ) {
				return;
			}
			self::$payment_orders[ $order_id ] = true;

			$class = self::classify_payment_failure( $text );
			Lafka_Checkout_Block_Reasons::emit(
				Lafka_Checkout_Block_Reasons::from_payment_class( $class ),
				array(
					'path'     => 'order',
					'stage'    => 'payment',
					'code'     => 'payment_' . $class,
					'class'    => $class,
					'order_id' => $order_id,
					'gateway'  => method_exists( $order, 'get_payment_method' ) ? (string) preg_replace( '/[^a-z0-9_\-]/', '', strtolower( (string) $order->get_payment_method() ) ) : '',
				)
			);
		}

		/**
		 * Text of the order's notes from the last few minutes (the gateway may
		 * have written its failure note before the status transition).
		 *
		 * @param int $order_id Order id.
		 * @return string
		 */
		private static function recent_note_text( int $order_id ): string {
			if ( ! function_exists( 'wc_get_order_notes' ) ) {
				return '';
			}
			$text  = '';
			$notes = wc_get_order_notes(
				array(
					'order_id' => $order_id,
					'limit'    => 3,
				)
			);
			foreach ( is_array( $notes ) ? $notes : array() as $note ) {
				$created = isset( $note->date_created ) && is_object( $note->date_created ) && method_exists( $note->date_created, 'getTimestamp' )
					? (int) $note->date_created->getTimestamp()
					: 0;
				if ( $created && ( time() - $created ) > 300 ) {
					continue;
				}
				$text .= ' ' . ( isset( $note->content ) ? (string) $note->content : '' );
			}
			return $text;
		}

		/**
		 * Whether a payment reason was already emitted in this request.
		 *
		 * @return bool
		 */
		private static function payment_emitted_this_request(): bool {
			if ( ! empty( self::$payment_orders ) ) {
				return true;
			}
			foreach ( Lafka_Checkout_Block_Reasons::PAYMENT_CLASS_MAP as $reason ) {
				if ( Lafka_Checkout_Block_Reasons::was_emitted( $reason ) ) {
					return true;
				}
			}
			return false;
		}

		/**
		 * Keyword map per failure class, checked in order (AVS and CVV before
		 * the generic "declined", which their messages also contain).
		 * Filter: `lafka_payment_failure_keywords`.
		 *
		 * @return array<string,array<int,string>>
		 */
		public static function payment_keywords(): array {
			$map = array(
				'avs'           => array( 'avs', 'address verification', 'address mismatch', 'address provided does not match', 'address does not match', 'postal code', 'zip code', 'incorrect_zip' ),
				'cvv'           => array( 'cvv', 'cvc', 'cvv2', 'card code', 'security code', 'card verification', 'incorrect_cvc', 'invalid_cvc' ),
				'declined'      => array( 'declin', 'do not honor', 'do_not_honor', 'insufficient funds', 'insufficient_funds', 'expired card', 'expired_card', 'lost card', 'stolen card', 'pickup card', 'not permitted', 'card was refused', 'refused' ),
				'gateway_error' => array( 'timeout', 'timed out', 'time-out', 'connection', 'could not connect', 'unable to connect', 'gateway error', 'api error', 'service unavailable', 'temporarily unavailable', 'internal error', 'server error', 'http 5', 'authentication failed', 'invalid api', 'invalid credentials', 'configuration', 'misconfigured' ),
			);
			if ( function_exists( 'apply_filters' ) ) {
				$filtered = apply_filters( 'lafka_payment_failure_keywords', $map );
				if ( is_array( $filtered ) ) {
					$map = $filtered;
				}
			}
			return $map;
		}

		/**
		 * Classify a gateway failure message.
		 *
		 * @param string $text Note / message text.
		 * @return string declined | avs | cvv | gateway_error | other.
		 */
		public static function classify_payment_failure( string $text ): string {
			$text = strtolower( $text );
			if ( '' === trim( $text ) ) {
				return 'other';
			}
			foreach ( self::payment_keywords() as $class => $needles ) {
				foreach ( (array) $needles as $needle ) {
					$needle = strtolower( (string) $needle );
					if ( '' === $needle ) {
						continue;
					}
					// Short tokens (avs, cvv, cvc) must be whole words.
					$hit = strlen( $needle ) <= 4
						? 1 === preg_match( '/\b' . preg_quote( $needle, '/' ) . '\b/', $text )
						: false !== strpos( $text, $needle );
					if ( $hit ) {
						return in_array( $class, array( 'avs', 'cvv', 'declined', 'gateway_error' ), true ) ? (string) $class : 'other';
					}
				}
			}
			return 'other';
		}

		// ─── Helpers ────────────────────────────────────────────────────────

		/**
		 * Whether an error code means "no delivery/pickup method" (classic
		 * `shipping`, Store API `…_invalid_shipping_option` and friends) rather
		 * than a shipping_* address field.
		 *
		 * @param string $code Error code.
		 * @return bool
		 */
		public static function is_shipping_method_code( string $code ): bool {
			return 'shipping' === $code || 1 === preg_match( '/shipping_(method|option|rate)|no_shipping/', $code );
		}

		/**
		 * @param array<int,mixed> $codes Raw codes.
		 * @return array<int,string> Sanitized, unique, non-empty, ≤ 64 chars.
		 */
		private static function clean_codes( array $codes ): array {
			$out = array();
			foreach ( $codes as $code ) {
				if ( ! is_scalar( $code ) ) {
					continue;
				}
				$code = substr( (string) preg_replace( '/[^A-Za-z0-9_.\-]/', '', (string) $code ), 0, 64 );
				if ( '' !== $code ) {
					$out[] = $code;
				}
			}
			return array_values( array_unique( $out ) );
		}

		/**
		 * Reset per-request state (tests).
		 *
		 * @return void
		 */
		public static function reset(): void {
			self::$store_api_checkout = false;
			self::$payment_orders     = array();
		}
	}
}
