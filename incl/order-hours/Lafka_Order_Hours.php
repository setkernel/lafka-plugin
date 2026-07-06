<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lafka_Order_Hours {
	public static $lafka_order_hours_options;
	public static $timezone;
	public static $lafka_order_hours_schedule;
	public static $lafka_order_hours_force_override_check;
	public static $lafka_order_hours_force_override_status;
	public static $lafka_order_hours_holidays_calendar;

	public function __construct() {
		self::$lafka_order_hours_options = get_option( 'lafka_order_hours_options' );
		add_action( 'init', array( $this, 'init' ), 99 );
	}

	private function init_order_hours_options(): void {
		self::$timezone                                = '';
		self::$lafka_order_hours_schedule              = self::$lafka_order_hours_options['lafka_order_hours_schedule'] ?? '';
		self::$lafka_order_hours_force_override_check  = self::$lafka_order_hours_options['lafka_order_hours_force_override_check'] ?? false;
		self::$lafka_order_hours_force_override_status = self::$lafka_order_hours_options['lafka_order_hours_force_override_status'] ?? '';
		self::$lafka_order_hours_holidays_calendar     = self::$lafka_order_hours_options['lafka_order_hours_holidays_calendar'] ?? '';

		if ( isset( WC()->session ) ) {
			$lafka_branch_location_id_in_session = WC()->session->get( 'lafka_branch_location' )['branch_id'] ?? null;
			if ( ! empty( $lafka_branch_location_id_in_session ) ) {
				$override_global_order_hours = get_term_meta( $lafka_branch_location_id_in_session, 'lafka_branch_override_order_hours_global', true );
				if ( ! empty( $override_global_order_hours ) ) {
					$branch_timezone                               = get_term_meta( $lafka_branch_location_id_in_session, 'lafka_branch_timezone', true );
					self::$timezone                                = $branch_timezone === 'default' ? '' : $branch_timezone;
					$branch_schedule                               = htmlspecialchars_decode( get_term_meta( $lafka_branch_location_id_in_session, 'lafka_branch_order_hours_schedule', true ) );
					self::$lafka_order_hours_schedule              = empty( $branch_schedule ) ? '' : $branch_schedule;
					self::$lafka_order_hours_force_override_check  = get_term_meta( $lafka_branch_location_id_in_session, 'lafka_branch_order_hours_force_override_check', true );
					self::$lafka_order_hours_force_override_status = get_term_meta( $lafka_branch_location_id_in_session, 'lafka_branch_order_hours_force_override_status', true );
					self::$lafka_order_hours_holidays_calendar     = get_term_meta( $lafka_branch_location_id_in_session, 'lafka_branch_order_hours_holidays_calendar', true );
				}
			}
		}
	}

	public static function get_timezone(): DateTimeZone {
		if ( self::$timezone ) {
			return new DateTimeZone( self::$timezone );
		} else {
			return wp_timezone();
		}
	}

	public static function is_day_in_vacation( DateTime $date, $holidays_calendar = null ): bool {
		if ( is_null( $holidays_calendar ) ) {
			$holidays_calendar = self::$lafka_order_hours_holidays_calendar;
		}

		if ( $holidays_calendar ) {
			$vacation_dates_array = explode( ', ', $holidays_calendar );

			return in_array( $date->format( 'Y-m-d' ), $vacation_dates_array, true );
		}

		return false;
	}

	public function init() {
		$this->init_order_hours_options();
		$this->handle_shop_status();

		if ( is_admin() ) {
			include_once __DIR__ . '/settings/Lafka_Order_Hours_Admin.php';
			new Lafka_Order_Hours_Admin();
		}
	}

	public static function get_order_hours_time( ?DateTimeZone $timezone = null ): DateTime {
		if ( empty( $timezone ) ) {
			$temp_timezone = self::get_timezone();
		} else {
			$temp_timezone = $timezone;
		}
		try {

			return new DateTime( 'now', $temp_timezone );
		} catch ( Exception $e ) {
			error_log( '[Lafka Order Hours] DateTime error: ' . $e->getMessage() );
			return new DateTime( '@0' );
		}
	}

	public static function is_shop_open( $branch_timezone = null, $branch_schedule_json = null, $force_override_check = null, $force_override_status = null, $holidays_calendar = null ): bool {

		// Check if status is forced for the main store
		if ( is_null( $force_override_check ) && is_null( $force_override_status ) && self::$lafka_order_hours_force_override_check ) {
			if ( self::$lafka_order_hours_force_override_status ) {
				return true;
			} else {
				return false;
			}
		} elseif ( $force_override_check ) {
			if ( $force_override_status ) {
				return true;
			} else {
				return false;
			}
		}

		$current_time = self::get_order_hours_time( $branch_timezone );

		$is_day_in_vacation = self::is_day_in_vacation( $current_time, $holidays_calendar );
		if ( $is_day_in_vacation ) {
			return false;
		}

		$numeric_day_of_the_week = $current_time->format( 'N' );

		// check is it in the open hours periods
		if ( ! isset( self::$lafka_order_hours_schedule ) ) {
			return true;
		}
		if ( empty( $branch_schedule_json ) ) {
			$schedule_json = self::$lafka_order_hours_schedule;
		} else {
			$schedule_json = $branch_schedule_json;
		}

		$schedule_array = json_decode( $schedule_json );
		if ( ! isset( $schedule_array[ $numeric_day_of_the_week - 1 ] ) ) {
			return true;
		}

		$schedule_current_day_of_week = $schedule_array[ $numeric_day_of_the_week - 1 ];
		foreach ( $schedule_current_day_of_week->periods as $period ) {
			$open_time = DateTime::createFromFormat( 'H:i', $period->start, $current_time->getTimezone() );

			if ( $period->end === '00:00' ) {
				$period->end = '24:00';
			}
			$close_time = DateTime::createFromFormat( 'H:i', $period->end, $current_time->getTimezone() );

			if ( $open_time < $current_time && $current_time < $close_time ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * @return object
	 */
	public static function get_shop_status( $branch_timezone = null, $branch_schedule = null, $force_override_check = null, $force_override_status = null, $holidays_calendar = null ) {
		if ( self::is_shop_open( $branch_timezone, $branch_schedule, $force_override_check, $force_override_status, $holidays_calendar ) ) {
			return (object) array(
				'code'  => 'open',
				'value' => esc_html__( 'Open', 'lafka-plugin' ),
			);
		}

		return (object) array(
			'code'  => 'closed',
			'value' => esc_html__( 'Closed', 'lafka-plugin' ),
		);
	}

	/**
	 * Build a per-day "HH:MM-HH:MM" display map from the order-hours schedule
	 * JSON — the SAME store that gates ordering via is_shop_open().
	 *
	 * This is the single-source-of-truth bridge for the restaurant-info
	 * resolver (lafka_get_restaurant_info()): when the dedicated display-hours
	 * store (theme_mod / option `lafka_business_hours_*`) is unset, the
	 * storefront "Open now" badge and the JSON-LD openingHoursSpecification can
	 * be derived from this schedule so badge + schema + order gate all read one
	 * store. Without it the two independent stores could disagree — e.g. the
	 * badge says "Open now" (and Google is told the store is open) while
	 * is_shop_open() is blocking the order, or the reverse.
	 *
	 * Multi-branch note: the order gate supports per-branch schedules/timezones
	 * (term meta), but the display-hours store is single-location. This helper
	 * therefore reads the MAIN-store schedule (the option value), NOT the
	 * possibly branch-overridden self::$lafka_order_hours_schedule static, so
	 * per-branch gate overrides stay authoritative for the gate only and are
	 * never silently flattened into the single display map.
	 *
	 * The schedule JSON is an array indexed 0..6 == Monday..Sunday (matching
	 * is_shop_open()'s `$schedule_array[ format('N') - 1 ]` lookup and the
	 * scheduler widget's Monday-first export order). Each element exposes a
	 * `periods` list of `{ start: "HH:MM", end: "HH:MM" }` objects.
	 *
	 * @param string|null $schedule_json Raw schedule JSON; defaults to the
	 *                                   main-store option value.
	 * @return array<string, string> e.g. [ 'Monday' => '11:00-23:00',
	 *                               'Tuesday' => 'Closed', ... ]. Empty array
	 *                               when no usable schedule is configured (so
	 *                               callers keep their existing "no hours"
	 *                               behaviour rather than advertising a closed
	 *                               restaurant).
	 */
	public static function get_schedule_display_hours_map( $schedule_json = null ): array {
		if ( null === $schedule_json ) {
			$options = is_array( self::$lafka_order_hours_options )
				? self::$lafka_order_hours_options
				: ( function_exists( 'get_option' ) ? (array) get_option( 'lafka_order_hours_options' ) : array() );
			$schedule_json = $options['lafka_order_hours_schedule'] ?? '';
		}

		$schedule_json = (string) $schedule_json;
		if ( '' === $schedule_json ) {
			return array();
		}

		$schedule_array = json_decode( $schedule_json, true );
		if ( ! is_array( $schedule_array ) ) {
			return array();
		}

		// Index 0..6 == Monday..Sunday — see method docblock.
		$day_names = array( 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday' );

		$map           = array();
		$has_any_hours = false;

		foreach ( $day_names as $index => $day_name ) {
			$open_minutes  = null;
			$close_minutes = null;

			$periods = isset( $schedule_array[ $index ]['periods'] ) && is_array( $schedule_array[ $index ]['periods'] )
				? $schedule_array[ $index ]['periods']
				: array();

			foreach ( $periods as $period ) {
				$start = isset( $period['start'] ) ? trim( (string) $period['start'] ) : '';
				$end   = isset( $period['end'] ) ? trim( (string) $period['end'] ) : '';
				if ( ! preg_match( '/^(\d{1,2}):(\d{2})$/', $start, $sm ) || ! preg_match( '/^(\d{1,2}):(\d{2})$/', $end, $em ) ) {
					continue;
				}
				$start_min = ( (int) $sm[1] * 60 ) + (int) $sm[2];
				$end_min   = ( (int) $em[1] * 60 ) + (int) $em[2];
				// The scheduler encodes an end-of-day close as "00:00"; treat it
				// as midnight (1440) so it sorts after every same-day open time —
				// mirrors is_shop_open()'s "00:00" => "24:00" handling.
				if ( 0 === $end_min ) {
					$end_min = 1440;
				}
				if ( null === $open_minutes || $start_min < $open_minutes ) {
					$open_minutes = $start_min;
				}
				if ( null === $close_minutes || $end_min > $close_minutes ) {
					$close_minutes = $end_min;
				}
			}

			// A day with split periods (e.g. lunch + dinner) is summarised as the
			// earliest open to the latest close — the single-range shape the
			// display map / schema use. The order gate still enforces each period
			// individually, so the rare in-between gap is the only residual
			// divergence; the common single-period case is exact.
			if ( null !== $open_minutes && null !== $close_minutes && $close_minutes > $open_minutes ) {
				$map[ $day_name ] = self::minutes_to_hhmm( $open_minutes ) . '-' . self::minutes_to_hhmm( $close_minutes );
				$has_any_hours    = true;
			} else {
				$map[ $day_name ] = 'Closed';
			}
		}

		return $has_any_hours ? $map : array();
	}

	/**
	 * Format minutes-since-midnight as a zero-padded "HH:MM" string. 1440 (the
	 * scheduler's end-of-day close) renders as "24:00" — parser-compatible with
	 * both the schema regex and open-status.php, and an unambiguous "closes at
	 * midnight" marker consistent with is_shop_open()'s internal handling.
	 *
	 * @param int $minutes Minutes since midnight (0..1440).
	 * @return string
	 */
	private static function minutes_to_hhmm( int $minutes ): string {
		$minutes = max( 0, min( 1440, $minutes ) );

		return sprintf( '%02d:%02d', intdiv( $minutes, 60 ), $minutes % 60 );
	}

	/**
	 * @return bool|DateTime
	 * @throws Exception
	 */
	public static function get_next_opening_time( $timezone = null ) {
		return self::get_next_opening_time_by_params( $timezone, self::$lafka_order_hours_schedule, null, null, null );
	}

	public static function get_next_opening_time_by_params( $timezone, $schedule_json, $force_override_check, $force_override_status, $holidays_calendar ) {
		if ( ! self::is_shop_open( $timezone, $schedule_json, $force_override_check, $force_override_status, $holidays_calendar ) && ! $force_override_check ) {

			$current_time            = self::get_order_hours_time( $timezone );
			$numeric_day_of_the_week = $current_time->format( 'N' );

			$schedule_array = json_decode( $schedule_json );
			if ( ! isset( $schedule_array[ $numeric_day_of_the_week - 1 ] ) ) {
				return false;
			}

			$counter = 0;
			for ( $day_of_week = $numeric_day_of_the_week - 1; $day_of_week < $day_of_week + 6; $day_of_week++ ) {
				if ( $counter > 6 ) {
					return false;
				}
				$weekday_index = $day_of_week;
				if ( $day_of_week > 6 ) {
					$weekday_index = $day_of_week - 7;
				}
				$schedule_day_of_week = $schedule_array[ $weekday_index ];

				foreach ( $schedule_day_of_week->periods as $period ) {
					$open_time = DateTime::createFromFormat( 'H:i', $period->start, $timezone )->add( DateInterval::createFromDateString( $counter . ' days' ) );

					if ( $open_time > $current_time ) {
						return $open_time;
					}
				}

				++$counter;
			}
		}

		return false;
	}

	/**
	 * Format a DateTime as a human-readable next-open string.
	 *
	 * Uses wp_date() so it respects the operator's WP locale AND the
	 * DateTime's own timezone (critical for multi-branch operators where a
	 * branch can have its own timezone). Returns empty string for any input
	 * that is not a DateTime. Operators can override the format via the
	 * `lafka_next_open_time_format` filter.
	 *
	 * The parameter is intentionally untyped: the underlying next-open
	 * resolvers (get_next_opening_time() / get_first_opening_branch_datetime())
	 * follow a legacy bool|DateTime contract and can return false (e.g. a
	 * force-closed branch or a schedule with no upcoming period). A strict
	 * ?DateTime hint would fatal (TypeError) on that false while rendering the
	 * customer-facing closed-store card, so we accept anything and guard.
	 *
	 * @param DateTime|bool|null $datetime The next-open DateTime, or false/null when none.
	 * @return string Human-readable string like "Saturday at 11:00 AM", or empty.
	 * @since  9.7.26
	 */
	public static function format_next_open_time_human( $datetime ): string {
		// null is the common "no info" case (callers initialise $opening_datetime
		// to null); the instanceof check additionally absorbs the legacy false
		// that the next-open resolvers return for force-closed / scheduleless
		// branches, so neither can reach getTimestamp() and fatal.
		if ( null === $datetime || ! $datetime instanceof DateTime ) {
			return '';
		}

		/**
		 * Filter the date-format string used to render the next-open time.
		 * Default 'l \a\t g:i A' produces "Saturday at 11:00 AM".
		 *
		 * @param string   $format   WP date_i18n format string.
		 * @param DateTime $datetime The next-open DateTime.
		 */
		/* translators: WP date_i18n format for "next-open" rendering. Default 'l \a\t g:i A' produces "Saturday at 11:00 AM". Translators: provide a localized format like 'l \à H\hi' for "samedi à 11h00". */
		$default_format = _x( 'l \a\t g:i A', 'next-open time format', 'lafka-plugin' );
		$format         = apply_filters( 'lafka_next_open_time_format', $default_format, $datetime );

		return wp_date( $format, $datetime->getTimestamp(), $datetime->getTimezone() );
	}

	public static function get_first_opening_branch_datetime( $all_legit_branches ) {
		$branches_open_times = array();

		foreach ( $all_legit_branches as $branch_id => $branch_name ) {
			$is_overridden = get_term_meta( $branch_id, 'lafka_branch_override_order_hours_global', true );
			if ( ! empty( $is_overridden ) ) {
				$branch_timezone_string = get_term_meta( $branch_id, 'lafka_branch_timezone', true );
				if ( $branch_timezone_string === 'default' ) {
					$branch_timezone = wp_timezone();
				} else {
					$branch_timezone = new DateTimeZone( $branch_timezone_string );
				}
				$branch_schedule              = htmlspecialchars_decode( get_term_meta( $branch_id, 'lafka_branch_order_hours_schedule', true ) );
				$branch_force_override_check  = get_term_meta( $branch_id, 'lafka_branch_order_hours_force_override_check', true );
				$branch_force_override_status = get_term_meta( $branch_id, 'lafka_branch_order_hours_force_override_status', true );
				$branch_holidays_calendar     = get_term_meta( $branch_id, 'lafka_branch_order_hours_holidays_calendar', true );

				$branches_open_times[ $branch_id ] = self::get_next_opening_time_by_params( $branch_timezone, $branch_schedule, $branch_force_override_check, $branch_force_override_status, $branch_holidays_calendar );
			}
		}

		// Main store
		$branches_open_times[0] = self::get_next_opening_time_by_params(
			wp_timezone(),
			self::$lafka_order_hours_schedule,
			self::$lafka_order_hours_force_override_check,
			self::$lafka_order_hours_force_override_status,
			self::$lafka_order_hours_holidays_calendar
		);

		// Drop every falsy entry — get_next_opening_time_by_params() returns the
		// literal false for force-closed branches or branches whose schedule has
		// no upcoming period (including the main store at index 0). Leaving those
		// in would make arsort() sort a mix of DateTime objects and booleans and
		// array_pop() could then return false straight into the renderer.
		$branches_open_times = array_filter( $branches_open_times );

		if ( empty( $branches_open_times ) ) {
			return null;
		}

		arsort( $branches_open_times );

		return array_pop( $branches_open_times );
	}

	public static function get_branch_working_status( $branch_id ) {
		$is_overridden                = get_term_meta( $branch_id, 'lafka_branch_override_order_hours_global', true );
		$branch_force_override_check  = null;
		$branch_force_override_status = null;
		$branch_timezone              = null;
		$branch_schedule              = null;
		$branch_holidays_calendar     = null;
		if ( ! empty( $is_overridden ) ) {
			$branch_timezone_string = get_term_meta( $branch_id, 'lafka_branch_timezone', true );
			if ( $branch_timezone_string === 'default' ) {
				$branch_timezone = wp_timezone();
			} else {
				$branch_timezone = new DateTimeZone( $branch_timezone_string );
			}
			$branch_schedule              = htmlspecialchars_decode( get_term_meta( $branch_id, 'lafka_branch_order_hours_schedule', true ) );
			$branch_force_override_check  = get_term_meta( $branch_id, 'lafka_branch_order_hours_force_override_check', true );
			$branch_force_override_status = get_term_meta( $branch_id, 'lafka_branch_order_hours_force_override_status', true );
			$branch_holidays_calendar     = get_term_meta( $branch_id, 'lafka_branch_order_hours_holidays_calendar', true );
		}

		$shop_status                  = self::get_shop_status( $branch_timezone, $branch_schedule, $branch_force_override_check, $branch_force_override_status, $branch_holidays_calendar );
		$shop_status->branch_timezone = $branch_timezone;

		return $shop_status;
	}

	public function handle_shop_status() {
		// Canonical server-side ordering gate. The UI hooks inside the
		// is_shop_open() branch below are cosmetic ONLY: they swap the proceed/
		// place-order button HTML and print a "closed" card. A replayed or stale
		// classic place-order POST (the form is still rendered, only the button
		// markup is swapped) and the entire Cart/Checkout Blocks + Store API path
		// (which ignores woocommerce_order_button_html) bypass that UI, so the
		// server must enforce closure itself. These validation hooks are the real
		// gate; each re-checks is_shop_open() (per active branch/session) at fire
		// time, so a closed store can never accept an order — and, when the
		// operator opts into lafka_order_hours_disable_add_to_cart, can never
		// accept an add-to-cart either — no matter which checkout UI is used.
		add_action( 'woocommerce_checkout_process', array( $this, 'gate_checkout_when_closed' ) );
		add_filter( 'woocommerce_add_to_cart_validation', array( $this, 'gate_add_to_cart_when_closed' ) );
		add_action( 'woocommerce_store_api_validate_add_to_cart', array( $this, 'gate_store_api_add_to_cart_when_closed' ) );
		// NOTE: the Store API CHECKOUT gate (store-closed) is registered by
		// Lafka_Store_Api on woocommerce_store_api_cart_errors — the hook Store
		// API actually fires from CartController::validate_cart(). It reuses
		// is_shop_open() + get_closed_notice_message() here, so both checkout
		// paths share one decision. (woocommerce_store_api_validate_cart is NOT a
		// real WC hook — it never fires — so it is deliberately not registered.)

		if ( ! self::is_shop_open() ) {

			// Add classes to body
			add_filter( 'body_class', array( $this, 'add_body_class' ) );

			remove_action( 'woocommerce_proceed_to_checkout', 'woocommerce_button_proceed_to_checkout', 20 );
			add_action( 'woocommerce_proceed_to_checkout', array( $this, 'echo_closed_store_message' ), 20 );

			remove_action( 'woocommerce_widget_shopping_cart_buttons', 'woocommerce_widget_shopping_cart_proceed_to_checkout', 20 );
			add_action( 'woocommerce_widget_shopping_cart_buttons', array( $this, 'echo_closed_store_message' ), 20 );

			add_action( 'woocommerce_after_add_to_cart_button', array( $this, 'echo_closed_store_message' ), 99 );
			add_filter( 'woocommerce_order_button_html', array( $this, 'get_closed_store_message' ) );

			// v9.7.26: when the operator opts into disable_add_to_cart, hard-block
			// the add-to-cart and surface the closed-store card. Without this the
			// option was a no-op (only added a body class). See the (A)/(B) notes.
			if ( ! empty( self::$lafka_order_hours_options['lafka_order_hours_disable_add_to_cart'] ) ) {
				// (A) Authoritative, template-agnostic server-side block: a
				// non-purchasable product cannot be added by ANY path and WC stops
				// rendering its add-to-cart form. Backs the add_to_cart_validation gate.
				add_filter( 'woocommerce_is_purchasable', '__return_false' );

				// (B) Card swap on WC's single-product hook (classic / quick-view).
				// The redesigned PDP never fires woocommerce_single_product_summary;
				// it gates its own form on is_shop_open() and renders the card inline
				// via the now-static echo_closed_store_message(), so this is a no-op there.
				remove_action( 'woocommerce_after_add_to_cart_button', array( $this, 'echo_closed_store_message' ), 99 );
				remove_action( 'woocommerce_single_product_summary', 'woocommerce_template_single_add_to_cart', 30 );
				add_action( 'woocommerce_single_product_summary', array( $this, 'echo_closed_store_message' ), 30 );
			}
		}
	}

	/**
	 * Resolve the customer-facing "store closed" notice text. Prefers the
	 * operator's configured message (same source the closed-store card uses);
	 * falls back to a translatable default. Returned as plain text — callers
	 * escape it for their own output context.
	 *
	 * @return string
	 */
	public static function get_closed_notice_message(): string {
		$operator_message = self::$lafka_order_hours_options['lafka_order_hours_message'] ?? '';

		return '' !== $operator_message
			? $operator_message
			: __( 'Sorry, the store is currently closed and is not accepting orders.', 'lafka-plugin' );
	}

	/**
	 * Whether add-to-cart must be blocked while the store is closed.
	 *
	 * Mirrors the UI contract: add-to-cart is only disabled when the operator
	 * opts in via lafka_order_hours_disable_add_to_cart (otherwise customers may
	 * still build a cart while closed). Checkout, by contrast, is always gated.
	 *
	 * @return bool
	 */
	private function is_add_to_cart_disabled_when_closed(): bool {
		return ! empty( self::$lafka_order_hours_options['lafka_order_hours_disable_add_to_cart'] );
	}

	/**
	 * Hard server-side checkout gate for the classic checkout.
	 *
	 * Runs on woocommerce_checkout_process inside WC_Checkout::process_checkout()
	 * — the only classic hook where wc_add_notice( ..., 'error' ) actually aborts
	 * the order. Backs up the cosmetic button removal so a replayed or stale
	 * place-order POST cannot place an order while the store is closed.
	 *
	 * @return void
	 */
	public function gate_checkout_when_closed() {
		if ( self::is_shop_open() ) {
			return;
		}
		wc_add_notice( esc_html( self::get_closed_notice_message() ), 'error' );
	}

	/**
	 * Server-side add-to-cart gate for the classic / wc-ajax add-to-cart path.
	 *
	 * Enforces lafka_order_hours_disable_add_to_cart on the server: removing the
	 * PDP button is not a gate (a replayed POST still adds the item), so when the
	 * operator opts in and the store is closed the add is rejected with a notice.
	 *
	 * @param bool $passed Whether add-to-cart validation has passed so far.
	 * @return bool
	 */
	public function gate_add_to_cart_when_closed( $passed ) {
		if ( $passed && ! self::is_shop_open() && $this->is_add_to_cart_disabled_when_closed() ) {
			wc_add_notice( esc_html( self::get_closed_notice_message() ), 'error' );

			return false;
		}

		return $passed;
	}

	/**
	 * Store API / Cart-and-Checkout-Blocks add-to-cart gate.
	 *
	 * The blocks/Store API path ignores the classic add-to-cart validation
	 * filter, so it needs its own gate. Fires on woocommerce_store_api_validate_add_to_cart
	 * (only triggered by the Store API add-to-cart route). Throws RouteException,
	 * which the Store API converts into a proper REST error response.
	 *
	 * @return void
	 * @throws \Automattic\WooCommerce\StoreApi\Exceptions\RouteException When closed and add-to-cart is disabled.
	 */
	public function gate_store_api_add_to_cart_when_closed() {
		if ( self::is_shop_open() || ! $this->is_add_to_cart_disabled_when_closed() ) {
			return;
		}
		if ( ! class_exists( '\Automattic\WooCommerce\StoreApi\Exceptions\RouteException' ) ) {
			return;
		}
		throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
			'lafka_store_closed',
			esc_html( self::get_closed_notice_message() ),
			409
		);
	}

	public function add_body_class( $classes ) {
		$classes[] = 'lafka-store-closed';

		if ( isset( self::$lafka_order_hours_options['lafka_order_hours_disable_add_to_cart'] ) && self::$lafka_order_hours_options['lafka_order_hours_disable_add_to_cart'] ) {
			$classes[] = 'lafka-disabled-cart-buttons';
		}

		return $classes;
	}

	/**
	 * Render the customer-facing "store closed" card.
	 *
	 * Static so theme templates can render it directly — the redesigned PDP
	 * (lafka-theme/partials/pdp-summary.php) gates its own add-to-cart form on
	 * is_shop_open() and calls Lafka_Order_Hours::echo_closed_store_message()
	 * inline, because it never fires the WC single-product hooks the plugin
	 * attaches this card to. Also used as an instance-array action callback
	 * ( array( $this, 'echo_closed_store_message' ) ), which PHP resolves to the
	 * same static method. Uses only self:: references — no $this.
	 *
	 * @return void
	 */
	public static function echo_closed_store_message() {
		$operator_message = self::$lafka_order_hours_options['lafka_order_hours_message'] ?? '';
		$title            = '' !== $operator_message ? $operator_message : __( 'Closed right now', 'lafka-plugin' );

		$lafka_branch_location_id_in_session = null;
		$opening_datetime                    = null;
		if ( isset( WC()->session ) ) {
			$lafka_branch_location_id_in_session = WC()->session->get( 'lafka_branch_location' )['branch_id'] ?? null;
		}
		if ( null !== $lafka_branch_location_id_in_session ) {
			$timezone_object  = empty( self::$timezone ) ? null : new DateTimeZone( self::$timezone );
			$opening_datetime = self::get_next_opening_time( $timezone_object );
		} elseif ( class_exists( 'Lafka_Shipping_Areas' ) ) {
			$all_legit_branch_locations = Lafka_Shipping_Areas::get_all_legit_branch_locations();
			$opening_datetime           = self::get_first_opening_branch_datetime( $all_legit_branch_locations );
		}

		$subtitle_human = self::format_next_open_time_human( $opening_datetime );
		?>
		<div class="lafka-store-closed-card">
			<p class="lafka-store-closed-card__title"><?php echo esc_html( $title ); ?></p>
			<?php if ( '' !== $subtitle_human ) : ?>
				<p class="lafka-store-closed-card__subtitle">
					<?php echo esc_html( sprintf( __( 'Opens %s', 'lafka-plugin' ), $subtitle_human ) ); ?>
				</p>
			<?php endif; ?>
			<?php
			if ( ! empty( self::$lafka_order_hours_options['lafka_order_hours_message_countdown'] ) && $opening_datetime ) {
				$countdown_output_format = '{hn}:{mnn}:{snn}';
				$difference              = $opening_datetime->diff( self::get_order_hours_time() );
				if ( $difference && $difference->d > 0 ) {
					$countdown_output_format = '{dn} {dl} {hn}:{mnn}:{snn}';
				}
				?>
				<div class="lafka-store-closed-card__countdown count_holder_small">
					<div class="lafka_order_hours_countdown"
						data-diff-days="<?php echo esc_attr( $difference->d ); ?>"
						data-diff-hours="<?php echo esc_attr( $difference->h ); ?>"
						data-diff-minutes="<?php echo esc_attr( $difference->i ); ?>"
						data-diff-seconds="<?php echo esc_attr( $difference->s ); ?>"
						data-output-format="<?php echo esc_attr( $countdown_output_format ); ?>"
					></div>
					<div class="clear"></div>
				</div>
				<?php
			}
			?>
		</div>
		<?php
	}

	public function get_closed_store_message() {
		ob_start();
		self::echo_closed_store_message();

		return ob_get_clean();
	}
}

new Lafka_Order_Hours();
