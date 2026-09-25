<?php
/**
 * Lafka_Checkout_Block_Reasons — the "why no order" vocabulary (GX1 / A7).
 *
 * Every point where Lafka (or WooCommerce, observed by Lafka) refuses a
 * customer's add-to-cart, checkout or payment fires ONE action:
 *
 *     do_action( 'lafka_checkout_blocked', string $reason, array $context );
 *
 * The signature is a public contract (Insights, third-party dashboards and
 * alerting all consume it) and never changes. `$reason` is always one of the
 * constants below; `$context` carries only machine codes and record ids —
 * never customer-entered values (no names, emails, phones, addresses, notes).
 *
 * Context keys (all optional except `path`):
 *   path     'classic' | 'store_api' | 'order' — which surface refused.
 *   stage    'add_to_cart' | 'cart' | 'checkout' | 'payment' — funnel stage.
 *   code     machine error code behind the refusal (e.g. 'lafka_store_closed',
 *            'billing_phone_required', 'woocommerce_rest_invalid_address').
 *   fields   string[] of WooCommerce validation error CODES (field_validation).
 *   order_id int, only for payment_* reasons (merchant record id).
 *   gateway  payment method id, only for payment_* reasons (e.g. 'cod').
 *   class    payment failure class: declined | avs | cvv | gateway_error | other.
 *
 * emit() fires the action at most once per (reason, path) per request, so a
 * gate evaluated twice in one request (Store API validates the cart on every
 * pass) never double-counts an attempt.
 *
 * @package Lafka\Plugin\Observability
 * @since   10.2.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Lafka_Checkout_Block_Reasons' ) ) {

	/**
	 * Reason constants + labels + the single emit helper.
	 */
	final class Lafka_Checkout_Block_Reasons {

		/** The action every refusal point fires. Signature: ( string $reason, array $context ). */
		const ACTION = 'lafka_checkout_blocked';

		// ─── Lafka-owned gates ──────────────────────────────────────────────

		/** Order hours: the store (or the selected branch) is closed. */
		const STORE_CLOSED = 'store_closed';

		/** Shipping areas: the pinpointed delivery location is outside every zone. */
		const OUTSIDE_DELIVERY_ZONE = 'outside_delivery_zone';

		/** Shipping areas: a delivery order carries no (valid) map pinpoint — address not geocoded. */
		const ADDRESS_UNPINNED = 'address_unpinned';

		/** Timeslots: the chosen date/slot is missing, invalid, past or full. */
		const TIMESLOT_INVALID = 'timeslot_invalid';

		/** Branches: the chosen branch does not exist or is not orderable. */
		const BRANCH_INVALID = 'branch_invalid';

		/** Branches: the chosen order type (delivery/pickup) is not offered by the branch. */
		const ORDER_TYPE_UNAVAILABLE = 'order_type_unavailable';

		/** Add-ons: a required/invalid add-on selection rejected the add-to-cart. */
		const ADDON_INVALID = 'addon_invalid';

		/** Promotions: the cart is below the delivery minimum, so delivery rates were withheld. */
		const BELOW_DELIVERY_MINIMUM = 'below_delivery_minimum';

		// ─── WooCommerce-owned refusals (observed) ──────────────────────────

		/** No shipping method could be chosen / was chosen for a shipped cart. */
		const NO_SHIPPING_METHOD = 'no_shipping_method';

		/** Checkout form validation failed (required/invalid fields). Codes only, in context.fields. */
		const FIELD_VALIDATION = 'field_validation';

		/** Any other Store API (block checkout) rejection not covered above. */
		const STORE_API_ERROR = 'store_api_error';

		// ─── Payment failures (classified from the gateway's failure note) ──

		/** The card issuer declined the charge. */
		const PAYMENT_DECLINED = 'payment_declined';

		/** Declined because the billing address did not match (AVS). */
		const PAYMENT_AVS = 'payment_avs';

		/** Declined because the card security code did not match (CVV/CVC). */
		const PAYMENT_CVV = 'payment_cvv';

		/** The gateway itself errored (timeout, connection, configuration). */
		const PAYMENT_GATEWAY_ERROR = 'payment_gateway_error';

		/** The payment failed for a reason the classifier could not identify. */
		const PAYMENT_OTHER = 'payment_other';

		/**
		 * Lafka Store API error codes → reason. Lets the Store API response
		 * listener and the Lafka gates agree on one vocabulary.
		 */
		const CODE_MAP = array(
			'lafka_store_closed'               => self::STORE_CLOSED,
			'lafka_outside_delivery_area'      => self::OUTSIDE_DELIVERY_ZONE,
			'lafka_delivery_location_required' => self::ADDRESS_UNPINNED,
			'lafka_invalid_timeslot'           => self::TIMESLOT_INVALID,
			'lafka_invalid_branch'             => self::BRANCH_INVALID,
			'lafka_invalid_order_type'         => self::ORDER_TYPE_UNAVAILABLE,
			'lafka_invalid_addon'              => self::ADDON_INVALID,
		);

		/** Payment failure class → reason. */
		const PAYMENT_CLASS_MAP = array(
			'declined'      => self::PAYMENT_DECLINED,
			'avs'           => self::PAYMENT_AVS,
			'cvv'           => self::PAYMENT_CVV,
			'gateway_error' => self::PAYMENT_GATEWAY_ERROR,
			'other'         => self::PAYMENT_OTHER,
		);

		/** @var array<string,bool> "reason|path" keys already emitted this request. */
		private static $emitted = array();

		/**
		 * Every reason, in display order.
		 *
		 * @return array<int,string>
		 */
		public static function all(): array {
			return array(
				self::STORE_CLOSED,
				self::OUTSIDE_DELIVERY_ZONE,
				self::ADDRESS_UNPINNED,
				self::TIMESLOT_INVALID,
				self::BRANCH_INVALID,
				self::ORDER_TYPE_UNAVAILABLE,
				self::ADDON_INVALID,
				self::BELOW_DELIVERY_MINIMUM,
				self::NO_SHIPPING_METHOD,
				self::FIELD_VALIDATION,
				self::STORE_API_ERROR,
				self::PAYMENT_DECLINED,
				self::PAYMENT_AVS,
				self::PAYMENT_CVV,
				self::PAYMENT_GATEWAY_ERROR,
				self::PAYMENT_OTHER,
			);
		}

		/**
		 * Whether a string is one of the reasons.
		 *
		 * @param string $reason Candidate.
		 * @return bool
		 */
		public static function is_known( string $reason ): bool {
			return in_array( $reason, self::all(), true );
		}

		/**
		 * Whether a reason is a payment failure (logged on the `payment` channel).
		 *
		 * @param string $reason Reason.
		 * @return bool
		 */
		public static function is_payment( string $reason ): bool {
			return in_array( $reason, self::PAYMENT_CLASS_MAP, true );
		}

		/**
		 * Map a machine error code to a reason, or null when it is not a Lafka code.
		 *
		 * @param string $code Error code.
		 * @return string|null
		 */
		public static function from_code( string $code ): ?string {
			return self::CODE_MAP[ $code ] ?? null;
		}

		/**
		 * Map a payment failure class to its reason (unknown → payment_other).
		 *
		 * @param string $payment_class declined | avs | cvv | gateway_error | other.
		 * @return string
		 */
		public static function from_payment_class( string $payment_class ): string {
			return self::PAYMENT_CLASS_MAP[ $payment_class ] ?? self::PAYMENT_OTHER;
		}

		/**
		 * Operator-facing labels, keyed by reason. Filterable for wording / i18n
		 * overrides via `lafka_checkout_block_reasons`.
		 *
		 * @return array<string,string>
		 */
		public static function labels(): array {
			$labels = array(
				self::STORE_CLOSED           => __( 'Store closed', 'lafka-plugin' ),
				self::OUTSIDE_DELIVERY_ZONE  => __( 'Outside the delivery area', 'lafka-plugin' ),
				self::ADDRESS_UNPINNED       => __( 'Delivery address not pinpointed', 'lafka-plugin' ),
				self::TIMESLOT_INVALID       => __( 'Time slot unavailable', 'lafka-plugin' ),
				self::BRANCH_INVALID         => __( 'Branch not available', 'lafka-plugin' ),
				self::ORDER_TYPE_UNAVAILABLE => __( 'Order type not offered by the branch', 'lafka-plugin' ),
				self::ADDON_INVALID          => __( 'Invalid add-on selection', 'lafka-plugin' ),
				self::BELOW_DELIVERY_MINIMUM => __( 'Below the delivery minimum', 'lafka-plugin' ),
				self::NO_SHIPPING_METHOD     => __( 'No delivery/pickup method', 'lafka-plugin' ),
				self::FIELD_VALIDATION       => __( 'Checkout form errors', 'lafka-plugin' ),
				self::STORE_API_ERROR        => __( 'Other checkout error', 'lafka-plugin' ),
				self::PAYMENT_DECLINED       => __( 'Card declined', 'lafka-plugin' ),
				self::PAYMENT_AVS            => __( 'Card declined: address mismatch (AVS)', 'lafka-plugin' ),
				self::PAYMENT_CVV            => __( 'Card declined: security code (CVV)', 'lafka-plugin' ),
				self::PAYMENT_GATEWAY_ERROR  => __( 'Payment gateway error', 'lafka-plugin' ),
				self::PAYMENT_OTHER          => __( 'Payment failed (other)', 'lafka-plugin' ),
			);

			if ( function_exists( 'apply_filters' ) ) {
				$filtered = apply_filters( 'lafka_checkout_block_reasons', $labels );
				if ( is_array( $filtered ) ) {
					$labels = array_merge( $labels, $filtered );
				}
			}
			return $labels;
		}

		/**
		 * Label for one reason (falls back to the raw reason).
		 *
		 * @param string $reason Reason.
		 * @return string
		 */
		public static function label( string $reason ): string {
			$labels = self::labels();
			return isset( $labels[ $reason ] ) ? (string) $labels[ $reason ] : $reason;
		}

		/**
		 * Fire `lafka_checkout_blocked` once per (reason, path) per request.
		 *
		 * @param string $reason  One of the reason constants.
		 * @param array  $context See the class docblock. `path` defaults to 'classic'.
		 * @return bool True when the action fired, false when deduped / unknown.
		 */
		public static function emit( string $reason, array $context = array() ): bool {
			if ( ! self::is_known( $reason ) ) {
				return false;
			}
			$context['path'] = isset( $context['path'] ) && is_string( $context['path'] ) && '' !== $context['path']
				? $context['path']
				: 'classic';

			$key = $reason . '|' . $context['path'];
			if ( isset( self::$emitted[ $key ] ) ) {
				return false;
			}
			self::$emitted[ $key ] = true;

			if ( function_exists( 'do_action' ) ) {
				do_action( self::ACTION, $reason, $context );
			}
			return true;
		}

		/**
		 * emit() for a Lafka error code (see CODE_MAP); unknown codes are ignored.
		 *
		 * @param string $code    Lafka error code, e.g. 'lafka_invalid_timeslot'.
		 * @param array  $context Context; `code` is set to $code.
		 * @return bool True when the action fired.
		 */
		public static function emit_code( string $code, array $context = array() ): bool {
			$reason = self::from_code( $code );
			if ( null === $reason ) {
				return false;
			}
			$context['code'] = $code;
			return self::emit( $reason, $context );
		}

		/**
		 * Whether a reason was already emitted in this request (any path).
		 *
		 * @param string $reason Reason.
		 * @return bool
		 */
		public static function was_emitted( string $reason ): bool {
			foreach ( array_keys( self::$emitted ) as $key ) {
				if ( 0 === strpos( $key, $reason . '|' ) ) {
					return true;
				}
			}
			return false;
		}

		/**
		 * Forget the per-request dedupe state (tests; long-running workers).
		 *
		 * @return void
		 */
		public static function reset(): void {
			self::$emitted = array();
		}
	}
}
