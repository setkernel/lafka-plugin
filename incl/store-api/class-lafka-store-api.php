<?php
/**
 * Lafka_Store_Api — Store API (block cart/checkout, headless) server-truth parity.
 *
 * NX1-04a. The classic-checkout ordering gates (store-closed / order-hours,
 * delivery geo-fence, timeslot validity + capacity, branch order-type
 * capability) were hardened in the 2026-06 audit remediation on the CLASSIC
 * request path (woocommerce_checkout_process / woocommerce_add_to_cart_validation).
 * WooCommerce's Store API — which powers the Cart & Checkout Blocks and any
 * headless client — does NOT run those classic hooks, so without this module a
 * block-checkout order could bypass every gate a classic checkout enforces.
 *
 * This module is the single place that wires the Store API surface. It does NOT
 * re-implement any gate rule: every decision routes back through the SAME gate
 * methods the classic path uses (Lafka_Order_Hours::is_shop_open(),
 * Lafka_Shipping_Areas::is_point_in_delivery_zone(),
 * Lafka_Timeslots::evaluate_datetime_selection(),
 * Lafka_Branch_Locations::is_order_type_allowed_for_branch()). The adapters here
 * are thin: they read server truth (WC session / the Store API request), call the
 * shared gate, and translate the result into the Store API error contract.
 *
 * Three responsibilities:
 *   1. VALIDATION PARITY — reject invalid Store API checkouts:
 *        · woocommerce_store_api_cart_errors                       (order-hours,
 *          branch order-type capability, timeslot validity + capacity)
 *        · woocommerce_store_api_checkout_update_order_from_request (delivery
 *          geo-fence + branch/timeslot order-meta persistence)
 *      (store-closed add-to-cart stays on Lafka_Order_Hours'
 *      woocommerce_store_api_validate_add_to_cart gate, which already fires.)
 *   2. SCHEMA — expose read-only cart data (namespace `lafka`) the block UI needs.
 *   3. UPDATE CALLBACK — set order_type / branch / timeslot into the WC session
 *      from a block UI, reusing the classic select_branch validation predicates.
 *
 * Loaded unconditionally whenever WooCommerce is active; every adapter guards on
 * class_exists()/function_exists() so a disabled feature module simply no-ops.
 * NX1-04b (block UI) builds on this contract; this item deliberately does NOT
 * declare cart_checkout_blocks compatibility.
 *
 * @package Lafka\Plugin\StoreApi
 * @since   9.36.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Lafka_Store_Api' ) ) {

	/**
	 * Store API server-truth parity wiring.
	 */
	final class Lafka_Store_Api {

		/**
		 * Extension namespace used for schema, update callback and error codes.
		 */
		const LAFKA_NAMESPACE = 'lafka';

		/**
		 * WC session key holding the block-selected date/timeslot pair.
		 */
		const DATETIME_SESSION_KEY = 'lafka_checkout_datetime';

		/**
		 * WC session key holding a block-selected delivery pinpoint (04b sets it;
		 * read here so the geo-fence can hold the moment a pinpoint exists).
		 */
		const DELIVERY_POINT_SESSION_KEY = 'lafka_delivery_geocoded';

		/**
		 * Register Store API hooks + schema once WooCommerce (and thus the Store
		 * API container + helper functions) is available.
		 *
		 * @return void
		 */
		public static function init() {
			add_action( 'woocommerce_init', array( __CLASS__, 'register' ) );
		}

		/**
		 * Wire validation hooks, the cart schema extension, and the update
		 * callback. Guarded so a WC build without the Store API is a no-op.
		 *
		 * @return void
		 */
		public static function register() {
			if ( ! function_exists( 'woocommerce_store_api_register_endpoint_data' )
				|| ! function_exists( 'woocommerce_store_api_register_update_callback' ) ) {
				return;
			}

			// 1. Validation parity.
			add_action( 'woocommerce_store_api_cart_errors', array( __CLASS__, 'add_cart_errors' ), 10, 2 );
			add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( __CLASS__, 'on_checkout_update_order_from_request' ), 10, 2 );

			// 2. Schema extension — read data the block UI needs.
			woocommerce_store_api_register_endpoint_data(
				array(
					'endpoint'        => 'cart',
					'namespace'       => self::LAFKA_NAMESPACE,
					'data_callback'   => array( __CLASS__, 'extend_cart_data' ),
					'schema_callback' => array( __CLASS__, 'extend_cart_schema' ),
					'schema_type'     => ARRAY_A,
				)
			);

			// 3. Update callback — set order_type / branch / timeslot from a block UI.
			woocommerce_store_api_register_update_callback(
				array(
					'namespace' => self::LAFKA_NAMESPACE,
					'callback'  => array( __CLASS__, 'handle_update_callback' ),
				)
			);
		}

		/* --------------------------------------------------------------------- *
		 *  1. Validation parity
		 * --------------------------------------------------------------------- */

		/**
		 * Aggregate every session-derived ordering gate onto the Store API cart
		 * error bag. Fired from CartController::validate_cart() — i.e. only at the
		 * Store API checkout (block place-order) and calc-totals passes, never on a
		 * plain cart read — so adding errors here blocks the block-checkout order
		 * exactly as the classic woocommerce_checkout_process gate blocks the
		 * classic one, without breaking cart viewing.
		 *
		 * @param \WP_Error $errors Store API cart error bag.
		 * @param mixed     $cart   WC cart instance (unused; gates read session/cart via helpers).
		 * @return void
		 */
		public static function add_cart_errors( $errors, $cart = null ) {
			unset( $cart );
			if ( ! is_object( $errors ) || ! method_exists( $errors, 'add' ) ) {
				return;
			}

			// order-hours: store closed → block checkout (always, like the classic gate).
			if ( class_exists( 'Lafka_Order_Hours' ) ) {
				$closed = self::evaluate_order_hours(
					Lafka_Order_Hours::is_shop_open(),
					Lafka_Order_Hours::get_closed_notice_message()
				);
				if ( null !== $closed ) {
					$errors->add( 'lafka_store_closed', $closed );
				}
			}

			$branch = self::get_branch_session();

			// branch order-type capability: re-validate the session selection.
			if ( class_exists( 'Lafka_Branch_Locations' )
				&& ! empty( $branch['branch_id'] )
				&& ! empty( $branch['order_type'] ) ) {
				$allowed = Lafka_Branch_Locations::is_order_type_allowed_for_branch(
					(string) $branch['order_type'],
					(int) $branch['branch_id']
				);
				if ( ! $allowed ) {
					$errors->add(
						'lafka_invalid_order_type',
						__( 'The selected order type is not available for this branch.', 'lafka-plugin' )
					);
				}
			}

			// timeslot validity + capacity: route through the shared classic gate.
			$timeslot_error = self::timeslot_error( self::get_datetime_session() );
			if ( null !== $timeslot_error ) {
				$errors->add( 'lafka_invalid_timeslot', $timeslot_error );
			}
		}

		/**
		 * Pure store-closed decision. Returns the customer-facing closed message
		 * when the store is closed, else null. (is_shop_open() + the message are
		 * resolved by the caller so this stays unit-testable in isolation.)
		 *
		 * @param bool   $is_shop_open   Whether the store is currently open.
		 * @param string $closed_message Operator/default "store closed" text.
		 * @return string|null
		 */
		public static function evaluate_order_hours( bool $is_shop_open, string $closed_message ): ?string {
			return $is_shop_open ? null : $closed_message;
		}

		/**
		 * Run the shared timeslot gate against the block-supplied (session) date +
		 * slot. Delegates to Lafka_Timeslots::evaluate_datetime_selection() — the
		 * SAME re-derivation + capacity logic the classic
		 * woocommerce_checkout_process gate uses — so validity and capacity can
		 * never disagree between the two checkout paths.
		 *
		 * @param array $datetime_session { date, timeslot } from the WC session.
		 * @return string|null Error message when the selection is invalid/full.
		 */
		private static function timeslot_error( array $datetime_session ): ?string {
			if ( ! class_exists( 'Lafka_Timeslots' ) ) {
				return null;
			}
			$timeslots = Lafka_Timeslots::instance();
			if ( ! $timeslots instanceof Lafka_Timeslots ) {
				return null;
			}
			$date = isset( $datetime_session['date'] ) ? (string) $datetime_session['date'] : '';
			$slot = isset( $datetime_session['timeslot'] ) ? (string) $datetime_session['timeslot'] : '';

			return $timeslots->evaluate_datetime_selection( $date, $slot, $timeslots->is_mandatory() );
		}

		/**
		 * Store API place-order hook: enforce the delivery geo-fence against the
		 * checkout request, then persist branch/timeslot meta onto the order so a
		 * block order carries the same server truth a classic order does.
		 *
		 * Fires from CheckoutTrait::update_order_from_request() during the POST
		 * place-order flow, after the draft order exists but before payment, so a
		 * thrown RouteException aborts the order cleanly (nothing charged).
		 *
		 * @param mixed $order   WC_Order being built for this checkout.
		 * @param mixed $request WP_REST_Request for the checkout call.
		 * @return void
		 */
		public static function on_checkout_update_order_from_request( $order, $request ) {
			self::apply_implicit_branch_defaults();
			self::validate_geo_fence( $request );
			self::persist_order_meta( $order );
		}

		/**
		 * Resolve hidden-field implicit selections (single branch / single order
		 * type) into the session BEFORE the geo-fence and the order-meta writer
		 * read it. A block field only renders when there is a real choice, so on
		 * a single-branch and/or single-order-type site the session slot arrives
		 * empty at checkout: without this fill the delivery geo-fence would
		 * silently skip (it keys on order_type === 'delivery') and
		 * lafka_order_type would never persist onto the order.
		 *
		 * @return void
		 */
		private static function apply_implicit_branch_defaults(): void {
			if ( ! class_exists( 'Lafka_Checkout_Fields' )
				|| ! Lafka_Checkout_Fields::is_branch_selection_active() ) {
				return;
			}
			$before = self::get_branch_session();
			$after  = Lafka_Checkout_Fields::fill_implicit_selections( $before );
			if ( $after !== $before ) {
				self::set_session( 'lafka_branch_location', $after );
			}
		}

		/**
		 * Delivery geo-fence for the Store API path. Delivery orders only; only
		 * when the operator turned pinpoint delivery on AND mandatory (identical
		 * preconditions to the classic Lafka_Shipping_Areas::validate_checkout_field_process()).
		 * The polygon membership test itself is the shared
		 * Lafka_Shipping_Areas::is_point_in_delivery_zone() used by both paths.
		 *
		 * The pinpoint rides the checkout request (block field, wired by NX1-04b)
		 * or the WC session. When no pinpoint is present yet, the polygon test is
		 * skipped — the presence requirement is a UI concern that lands with the
		 * block map field in NX1-04b; this item guarantees the polygon test HOLDS
		 * whenever a pinpoint exists so an out-of-zone address cannot slip through.
		 *
		 * @param mixed $request WP_REST_Request for the checkout call.
		 * @return void
		 */
		private static function validate_geo_fence( $request ) {
			if ( ! class_exists( 'Lafka_Shipping_Areas' ) ) {
				return;
			}

			$branch = self::get_branch_session();
			if ( 'delivery' !== ( $branch['order_type'] ?? '' ) ) {
				return; // Pickup / dine-in never carry a delivery pinpoint.
			}

			$options = get_option( 'lafka_shipping_areas_general' );
			if ( empty( $options['pick_delivery_address'] ) || empty( $options['mandatory_pickup_delivery'] ) ) {
				return;
			}

			$point = self::read_delivery_point( $request );
			if ( null === $point ) {
				return; // No pinpoint source yet (NX1-04b wires the block map field).
			}

			$shipping_areas = Lafka_Shipping_Areas::instance();
			if ( ! $shipping_areas instanceof Lafka_Shipping_Areas ) {
				return;
			}

			if ( ! $shipping_areas->is_point_in_delivery_zone( $point['lat'], $point['lng'] ) ) {
				self::throw_store_api_error(
					'lafka_outside_delivery_area',
					__( 'The selected location is outside our delivery area. Please pinpoint an address inside the delivery zone.', 'lafka-plugin' )
				);
			}
		}

		/**
		 * Persist branch + timeslot server truth onto the block order, mirroring
		 * the classic woocommerce_checkout_create_order writers (which do not run
		 * on the Store API path). Branch meta reuses the classic writer verbatim.
		 *
		 * @param mixed $order WC_Order being built for this checkout.
		 * @return void
		 */
		private static function persist_order_meta( $order ) {
			if ( ! $order instanceof WC_Order ) {
				return;
			}

			// Branch id + order type — reuse the exact classic session→meta writer.
			if ( class_exists( 'Lafka_Branch_Locations' )
				&& method_exists( 'Lafka_Branch_Locations', 'checkout_field_update_order_meta_fields' ) ) {
				Lafka_Branch_Locations::checkout_field_update_order_meta_fields( $order );
			}

			// Date + timeslot — same meta keys the classic writer uses.
			$datetime = self::get_datetime_session();
			if ( ! empty( $datetime['date'] ) ) {
				$order->update_meta_data( 'lafka_checkout_date', sanitize_text_field( (string) $datetime['date'] ) );
			}
			if ( ! empty( $datetime['timeslot'] ) ) {
				$order->update_meta_data( 'lafka_checkout_timeslot', sanitize_text_field( (string) $datetime['timeslot'] ) );
			}
		}

		/* --------------------------------------------------------------------- *
		 *  2. Schema — read data for the block UI
		 * --------------------------------------------------------------------- */

		/**
		 * Cart schema properties registered under the `lafka` namespace. Describes
		 * the read-only payload extend_cart_data() returns.
		 *
		 * @return array
		 */
		public static function extend_cart_schema(): array {
			$string_prop = static function ( $description ) {
				return array(
					'description' => $description,
					'type'        => array( 'string', 'null' ),
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				);
			};
			$number_prop = static function ( $description ) {
				return array(
					'description' => $description,
					'type'        => 'number',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				);
			};

			return array(
				'order_type'                 => $string_prop( __( 'Selected order type (delivery or pickup).', 'lafka-plugin' ) ),
				'branch_id'                  => array(
					'description' => __( 'Selected branch term id (0 when none).', 'lafka-plugin' ),
					'type'        => 'integer',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'branch_name'                => $string_prop( __( 'Selected branch name.', 'lafka-plugin' ) ),
				'checkout_date'              => $string_prop( __( 'Selected delivery/pickup date (Y-m-d).', 'lafka-plugin' ) ),
				'checkout_timeslot'          => $string_prop( __( 'Selected delivery/pickup timeslot id.', 'lafka-plugin' ) ),
				'store_open_now'             => array(
					'description' => __( 'Whether the store is currently accepting orders.', 'lafka-plugin' ),
					'type'        => 'boolean',
					'context'     => array( 'view', 'edit' ),
					'readonly'    => true,
				),
				'next_open'                  => $string_prop( __( 'Human-readable next opening time when closed (null when open).', 'lafka-plugin' ) ),
				'free_delivery_threshold'    => $number_prop( __( 'Cart total at which delivery becomes free (0 = off).', 'lafka-plugin' ) ),
				'free_delivery_remaining'    => $number_prop( __( 'Amount remaining to reach the free-delivery threshold.', 'lafka-plugin' ) ),
				'delivery_minimum'           => $number_prop( __( 'Minimum cart total required for delivery (0 = off).', 'lafka-plugin' ) ),
				'delivery_minimum_remaining' => $number_prop( __( 'Amount remaining to reach the delivery minimum.', 'lafka-plugin' ) ),
			);
		}

		/**
		 * Read data exposed on the cart under the `lafka` namespace. Every value is
		 * resolved from server truth (WC session, order-hours, the free-delivery /
		 * delivery-minimum SSOT helpers) so a block UI never has to recompute a gate.
		 *
		 * @return array
		 */
		public static function extend_cart_data(): array {
			$branch   = self::get_branch_session();
			$datetime = self::get_datetime_session();

			$open      = class_exists( 'Lafka_Order_Hours' ) ? (bool) Lafka_Order_Hours::is_shop_open() : true;
			$next_open = null;
			if ( ! $open && class_exists( 'Lafka_Order_Hours' ) ) {
				$next_dt = Lafka_Order_Hours::get_next_opening_time();
				if ( $next_dt ) {
					$next_open = Lafka_Order_Hours::format_next_open_time_human( $next_dt );
				}
			}

			$branch_id   = isset( $branch['branch_id'] ) ? (int) $branch['branch_id'] : 0;
			$branch_name = self::resolve_branch_name( $branch_id );

			$contents = self::get_cart_contents_total();

			$free_threshold = function_exists( 'lafka_get_free_delivery_threshold' )
				? (float) lafka_get_free_delivery_threshold()
				: 0.0;
			$free_remaining = $free_threshold > 0 ? max( 0.0, $free_threshold - $contents ) : 0.0;

			$delivery_minimum = class_exists( 'Lafka_Promotions' )
				? (float) Lafka_Promotions::knob( 'delivery_min' )
				: 0.0;
			$delivery_remaining = $delivery_minimum > 0 ? max( 0.0, $delivery_minimum - $contents ) : 0.0;

			return array(
				'order_type'                 => isset( $branch['order_type'] ) ? (string) $branch['order_type'] : '',
				'branch_id'                  => $branch_id,
				'branch_name'                => $branch_name,
				'checkout_date'              => isset( $datetime['date'] ) ? (string) $datetime['date'] : '',
				'checkout_timeslot'          => isset( $datetime['timeslot'] ) ? (string) $datetime['timeslot'] : '',
				'store_open_now'             => $open,
				'next_open'                  => $next_open,
				'free_delivery_threshold'    => $free_threshold,
				'free_delivery_remaining'    => $free_remaining,
				'delivery_minimum'           => $delivery_minimum,
				'delivery_minimum_remaining' => $delivery_remaining,
			);
		}

		/* --------------------------------------------------------------------- *
		 *  3. Update callback — set session state from a block UI
		 * --------------------------------------------------------------------- */

		/**
		 * Handle POST /cart/extensions { namespace: lafka, data: {...} }. Accepts
		 * any of { order_type, branch_id, checkout_date, checkout_timeslot } and
		 * writes them to the WC session exactly like the classic AJAX endpoints,
		 * reusing the select_branch validation predicates (taxonomy constraint,
		 * legit-branch allow-list, order-type capability). Invalid input throws a
		 * RouteException, which the Store API surfaces as a proper error response.
		 *
		 * @param mixed $data Extension data payload from the request.
		 * @return void
		 */
		public static function handle_update_callback( $data ) {
			$data = is_array( $data ) ? $data : array();

			if ( array_key_exists( 'branch_id', $data ) || array_key_exists( 'order_type', $data ) ) {
				self::apply_branch_update( $data );
			}

			if ( array_key_exists( 'checkout_date', $data ) || array_key_exists( 'checkout_timeslot', $data ) ) {
				self::apply_timeslot_update( $data );
			}
		}

		/**
		 * Validate + persist the branch / order-type selection to the session.
		 * The effective branch id and order type fall back to any existing session
		 * value so a partial update (e.g. only order_type) still validates the pair.
		 *
		 * @param array $data Update-callback payload.
		 * @return void
		 */
		private static function apply_branch_update( array $data ) {
			$session = self::get_branch_session();

			$branch_id = array_key_exists( 'branch_id', $data )
				? (int) $data['branch_id']
				: (int) ( $session['branch_id'] ?? 0 );
			$order_type = array_key_exists( 'order_type', $data )
				? sanitize_text_field( (string) $data['order_type'] )
				: (string) ( $session['order_type'] ?? '' );

			$term = $branch_id > 0 ? get_term( $branch_id, 'lafka_branch_location' ) : null;

			$legit_branches = class_exists( 'Lafka_Shipping_Areas' )
				? Lafka_Shipping_Areas::get_all_legit_branch_locations()
				: array();
			$is_legit = is_array( $legit_branches ) && array_key_exists( $branch_id, $legit_branches );

			$order_type_allowed = class_exists( 'Lafka_Branch_Locations' )
				&& Lafka_Branch_Locations::is_order_type_allowed_for_branch( $order_type, $branch_id );

			$error = self::branch_update_error( $term, $is_legit, $order_type_allowed );
			if ( null !== $error ) {
				self::throw_store_api_error( 'lafka_invalid_branch', $error );
			}

			$session['branch_id']  = $branch_id;
			$session['order_type'] = $order_type;
			self::set_session( 'lafka_branch_location', $session );
		}

		/**
		 * Pure branch-selection decision, mirroring the classic select_branch()
		 * guard order: the id must resolve to a lafka_branch_location term, be an
		 * actually-orderable (legit) branch, and permit the requested order type.
		 *
		 * @param mixed $term               Resolved term for the branch id (or null).
		 * @param bool  $is_legit           Whether the id is in the legit-branch allow-list.
		 * @param bool  $order_type_allowed Whether the order type is permitted by branch + site caps.
		 * @return string|null Error message when the selection is invalid.
		 */
		public static function branch_update_error( $term, bool $is_legit, bool $order_type_allowed ): ?string {
			if ( ! is_object( $term ) || ( function_exists( 'is_wp_error' ) && is_wp_error( $term ) )
				|| ! isset( $term->taxonomy ) || 'lafka_branch_location' !== $term->taxonomy ) {
				return __( 'Something is wrong. No such branch location.', 'lafka-plugin' );
			}
			if ( ! $is_legit ) {
				return __( 'Something is wrong. No such branch location.', 'lafka-plugin' );
			}
			if ( ! $order_type_allowed ) {
				return __( 'The selected order type is not available for this branch.', 'lafka-plugin' );
			}

			return null;
		}

		/**
		 * Validate the shape of a block-supplied date/slot pair, then persist it to
		 * the session. This is the boundary (shape) check the classic path performs
		 * implicitly by only rendering well-formed values; the authoritative
		 * validity + capacity gate runs at checkout in add_cart_errors().
		 *
		 * @param array $data Update-callback payload.
		 * @return void
		 */
		private static function apply_timeslot_update( array $data ) {
			$date = array_key_exists( 'checkout_date', $data ) ? sanitize_text_field( (string) $data['checkout_date'] ) : '';
			$slot = array_key_exists( 'checkout_timeslot', $data ) ? sanitize_text_field( (string) $data['checkout_timeslot'] ) : '';

			$error = self::timeslot_update_error( $date, $slot );
			if ( null !== $error ) {
				self::throw_store_api_error( 'lafka_invalid_timeslot', $error );
			}

			self::set_session(
				self::DATETIME_SESSION_KEY,
				array(
					'date'     => $date,
					'timeslot' => $slot,
				)
			);
		}

		/**
		 * Pure shape validation for a date/slot pair. A slot without its anchoring
		 * date, or a mis-formatted date, is malformed and rejected. (Deeper
		 * validity/capacity is enforced by the shared timeslot gate at checkout.)
		 *
		 * @param string $date Submitted date.
		 * @param string $slot Submitted timeslot id.
		 * @return string|null
		 */
		public static function timeslot_update_error( string $date, string $slot ): ?string {
			if ( '' !== $slot && '' === $date ) {
				return __( 'Please select a Delivery/Pickup date.', 'lafka-plugin' );
			}
			if ( '' !== $date && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
				return __( 'The selected Delivery/Pickup date is invalid. Please choose another.', 'lafka-plugin' );
			}

			return null;
		}

		/* --------------------------------------------------------------------- *
		 *  Helpers
		 * --------------------------------------------------------------------- */

		/**
		 * Read the branch selection array from the WC session.
		 *
		 * @return array
		 */
		private static function get_branch_session(): array {
			$branch = self::get_session( 'lafka_branch_location' );

			return is_array( $branch ) ? $branch : array();
		}

		/**
		 * Read the date/timeslot selection array from the WC session.
		 *
		 * @return array
		 */
		private static function get_datetime_session(): array {
			$datetime = self::get_session( self::DATETIME_SESSION_KEY );

			return is_array( $datetime ) ? $datetime : array();
		}

		/**
		 * Resolve a branch term name for display, guarding taxonomy + errors.
		 *
		 * @param int $branch_id Branch term id.
		 * @return string
		 */
		private static function resolve_branch_name( int $branch_id ): string {
			if ( $branch_id <= 0 || ! function_exists( 'get_term' ) ) {
				return '';
			}
			$term = get_term( $branch_id, 'lafka_branch_location' );
			if ( ! is_object( $term ) || ( function_exists( 'is_wp_error' ) && is_wp_error( $term ) ) || ! isset( $term->name ) ) {
				return '';
			}

			return (string) $term->name;
		}

		/**
		 * The post-coupon, ex-tax cart contents total — the same base the
		 * delivery-minimum and free-delivery rate rules key off, so progress copy
		 * can never disagree with the actual shipping decision.
		 *
		 * @return float
		 */
		private static function get_cart_contents_total(): float {
			if ( ! function_exists( 'WC' ) ) {
				return 0.0;
			}
			$wc = WC();
			if ( ! is_object( $wc ) || ! isset( $wc->cart ) || ! is_object( $wc->cart ) ) {
				return 0.0;
			}

			return (float) $wc->cart->get_cart_contents_total();
		}

		/**
		 * Pull a delivery pinpoint from the checkout request's `lafka` extension
		 * data, falling back to the session. Returns [ lat, lng ] floats within
		 * planet bounds, or null when no valid pinpoint is available.
		 *
		 * @param mixed $request WP_REST_Request for the checkout call.
		 * @return array{lat:float,lng:float}|null
		 */
		private static function read_delivery_point( $request ): ?array {
			$raw = null;

			if ( is_object( $request ) && $request instanceof ArrayAccess ) {
				$extensions = $request['extensions'] ?? null;
				if ( is_array( $extensions ) && isset( $extensions[ self::LAFKA_NAMESPACE ]['delivery_geocoded'] ) ) {
					$raw = $extensions[ self::LAFKA_NAMESPACE ]['delivery_geocoded'];
				}
			}

			if ( null === $raw ) {
				$raw = self::get_session( self::DELIVERY_POINT_SESSION_KEY );
			}

			return self::normalize_delivery_point( $raw );
		}

		/**
		 * Decode a raw pinpoint (JSON string, object, or array) into bounded
		 * [ lat, lng ] floats. Mirrors the classic geocoded-field decode.
		 *
		 * @param mixed $raw Raw pinpoint value.
		 * @return array{lat:float,lng:float}|null
		 */
		private static function normalize_delivery_point( $raw ): ?array {
			if ( is_string( $raw ) && '' !== $raw ) {
				$raw = json_decode( $raw );
			}
			if ( is_object( $raw ) ) {
				$raw = (array) $raw;
			}
			if ( ! is_array( $raw ) || ! isset( $raw['lat'], $raw['lng'] ) || ! is_numeric( $raw['lat'] ) || ! is_numeric( $raw['lng'] ) ) {
				return null;
			}

			$lat = (float) $raw['lat'];
			$lng = (float) $raw['lng'];
			if ( $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180 ) {
				return null;
			}

			return array(
				'lat' => $lat,
				'lng' => $lng,
			);
		}

		/**
		 * Safe WC session read.
		 *
		 * @param string $key Session key.
		 * @return mixed
		 */
		private static function get_session( string $key ) {
			if ( ! function_exists( 'WC' ) ) {
				return null;
			}
			$wc = WC();
			if ( ! is_object( $wc ) || ! isset( $wc->session ) || ! is_object( $wc->session ) ) {
				return null;
			}

			return $wc->session->get( $key );
		}

		/**
		 * Safe WC session write.
		 *
		 * @param string $key   Session key.
		 * @param mixed  $value Session value.
		 * @return void
		 */
		private static function set_session( string $key, $value ) {
			if ( ! function_exists( 'WC' ) ) {
				return;
			}
			$wc = WC();
			if ( ! is_object( $wc ) || ! isset( $wc->session ) || ! is_object( $wc->session ) ) {
				return;
			}
			$wc->session->set( $key, $value );
		}

		/**
		 * Throw a Store API RouteException (falling back to a generic exception in
		 * the unlikely event the Store API exception class is unavailable) so an
		 * invalid update or checkout surfaces as a proper Store API error.
		 *
		 * @param string $code    Machine error code.
		 * @param string $message Customer-facing message.
		 * @param int    $status  HTTP status.
		 * @return void
		 *
		 * @throws \Automattic\WooCommerce\StoreApi\Exceptions\RouteException Store API error.
		 * @throws \RuntimeException Fallback when the Store API is unavailable.
		 */
		private static function throw_store_api_error( string $code, string $message, int $status = 400 ): void {
			if ( class_exists( '\Automattic\WooCommerce\StoreApi\Exceptions\RouteException' ) ) {
				throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException( esc_html( $code ), esc_html( $message ), $status );
			}

			throw new \RuntimeException( esc_html( $message ) );
		}
	}
}
