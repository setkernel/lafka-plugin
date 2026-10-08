<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// `lafka_checkout_blocked` vocabulary (GX1) — the gates below report refusals.
require_once dirname( __DIR__ ) . '/observability/class-lafka-checkout-block-reasons.php';
require_once __DIR__ . '/class-lafka-order-hours-engine.php';

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
		$context = self::resolve_context();

		self::$timezone                                = $context['timezone'];
		self::$lafka_order_hours_schedule              = $context['schedule'];
		self::$lafka_order_hours_force_override_check  = $context['force_check'];
		self::$lafka_order_hours_force_override_status = $context['force_status'];
		self::$lafka_order_hours_holidays_calendar     = $context['holidays'];
	}

	/**
	 * Whether the order-hours module is on (it gates ordering). The class is
	 * always loaded so status() answers for every reader; only the gate hooks
	 * depend on the module.
	 *
	 * @return bool
	 */
	public static function module_enabled(): bool {
		return class_exists( 'Lafka_Options' ) && Lafka_Options::is_enabled( 'order_hours' );
	}

	/**
	 * The hours settings in force for this request: the main store's, or the
	 * session branch's when that branch overrides them.
	 *
	 * @return array{timezone:string,schedule:string,force_check:bool,force_status:bool,holidays:string,branch_id:int}
	 */
	public static function resolve_context(): array {
		$options = get_option( 'lafka_order_hours_options' );
		$options = is_array( $options ) ? $options : array();
		$context = array(
			'timezone'     => '',
			'schedule'     => (string) ( $options['lafka_order_hours_schedule'] ?? '' ),
			'force_check'  => ! empty( $options['lafka_order_hours_force_override_check'] ),
			'force_status' => ! empty( $options['lafka_order_hours_force_override_status'] ),
			'holidays'     => (string) ( $options['lafka_order_hours_holidays_calendar'] ?? '' ),
			'branch_id'    => 0,
		);

		if ( function_exists( 'WC' ) && isset( WC()->session ) ) {
			$branch_id = (int) ( WC()->session->get( 'lafka_branch_location' )['branch_id'] ?? 0 );
			if ( $branch_id > 0 && ! empty( get_term_meta( $branch_id, 'lafka_branch_override_order_hours_global', true ) ) ) {
				$branch_timezone         = (string) get_term_meta( $branch_id, 'lafka_branch_timezone', true );
				$context['branch_id']    = $branch_id;
				$context['timezone']     = 'default' === $branch_timezone ? '' : $branch_timezone;
				$context['schedule']     = (string) htmlspecialchars_decode( (string) get_term_meta( $branch_id, 'lafka_branch_order_hours_schedule', true ) );
				$context['force_check']  = ! empty( get_term_meta( $branch_id, 'lafka_branch_order_hours_force_override_check', true ) );
				$context['force_status'] = ! empty( get_term_meta( $branch_id, 'lafka_branch_order_hours_force_override_status', true ) );
				$context['holidays']     = (string) get_term_meta( $branch_id, 'lafka_branch_order_hours_holidays_calendar', true );
			}
		}

		return $context;
	}

	public static function get_timezone(): DateTimeZone {
		return self::resolve_timezone( (string) self::$timezone );
	}

	/**
	 * A branch timezone setting as a DateTimeZone: 'default', empty or an
	 * unknown identifier fall back to the site timezone instead of throwing
	 * (which fataled the closed-store card and branch status).
	 *
	 * @param string $timezone_string Stored lafka_branch_timezone value.
	 * @return DateTimeZone
	 */
	public static function resolve_timezone( string $timezone_string ): DateTimeZone {
		if ( '' === $timezone_string || 'default' === $timezone_string ) {
			return wp_timezone();
		}
		try {
			return new DateTimeZone( $timezone_string );
		} catch ( Throwable $e ) {
			// Throwable, not Exception: under Xdebug on PHP 8.3+, decorating the
			// DateInvalidTimeZoneException fails and surfaces as an Error.
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
			include_once __DIR__ . '/settings/class-lafka-order-hours-admin.php';
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
			$now = new DateTime( 'now', $temp_timezone );

			/**
			 * Filter the store clock. Lets a test or a preview freeze "now"
			 * without touching the hours themselves.
			 *
			 * @since 10.4.0
			 *
			 * @param DateTime     $now      The current time on the store's clock.
			 * @param DateTimeZone $timezone The store's (or branch's) timezone.
			 */
			$filtered = apply_filters( 'lafka_order_hours_now', $now, $temp_timezone );

			return $filtered instanceof DateTime ? $filtered : $now;
		} catch ( Exception $e ) {
			if ( class_exists( 'Lafka_Log' ) ) {
				Lafka_Log::error(
					'order-hours',
					'Could not read the store clock: ' . $e->getMessage(),
					array(
						'code'      => 'datetime_error',
						'exception' => $e,
					)
				);
			}
			return new DateTime( '@0' );
		}
	}

	/**
	 * Whether ordering is open right now. A store with no order-hours gate
	 * (module off, or no schedule, force override or holiday calendar) is open.
	 *
	 * The optional arguments evaluate a branch's own settings; omitted, the
	 * hours in force for this request (main store or the session's branch) are
	 * used. The decision itself lives in status().
	 *
	 * @param DateTimeZone|null $branch_timezone       Branch timezone.
	 * @param string|null       $branch_schedule_json  Branch schedule JSON.
	 * @param mixed             $force_override_check  Branch force override on.
	 * @param mixed             $force_override_status Branch force override: open.
	 * @param string|null       $holidays_calendar     Branch holiday calendar.
	 * @return bool
	 */
	public static function is_shop_open( $branch_timezone = null, $branch_schedule_json = null, $force_override_check = null, $force_override_status = null, $holidays_calendar = null ): bool {
		$context = self::resolve_context();
		if ( null !== $force_override_check || null !== $force_override_status ) {
			$context['force_check']  = (bool) $force_override_check;
			$context['force_status'] = (bool) $force_override_status;
		}
		if ( ! empty( $branch_schedule_json ) ) {
			$context['schedule'] = (string) $branch_schedule_json;
		}
		if ( $branch_timezone instanceof DateTimeZone ) {
			$context['timezone'] = $branch_timezone->getName();
		}
		if ( null !== $holidays_calendar ) {
			$context['holidays'] = (string) $holidays_calendar;
		}

		$status = self::status_for( $context );

		return ! $status['gated'] || $status['is_open'];
	}

	/**
	 * The store's opening status: the one answer every surface reads (the order
	 * gate, header and announce badges, the product-page trust line, Insights,
	 * schema, llms.txt and the live status script).
	 *
	 * Hours come from the order-hours schedule while the module is on and a
	 * schedule (or a force override, or a holiday) is set; that verdict also
	 * gates ordering (`gated`). Otherwise the per-day display hours
	 * (lafka_business_hours_*) answer, for display only. Holidays close the
	 * whole day; a force override beats the schedule.
	 *
	 * Filter `lafka_order_hours_now` freezes the clock.
	 *
	 * @since 10.4.0
	 *
	 * @param DateTimeInterface|null $now The moment to answer for; default now.
	 * @return array{is_open:bool,closes_at:?DateTimeImmutable,next_open:?DateTimeImmutable,source:string,forced:bool,gated:bool,has_hours:bool,schedule_open:?bool,holiday:bool,timezone:string,now:DateTimeImmutable}
	 *         `source` is `forced`, `holiday`, `schedule`, `display` or `none`.
	 *         `closes_at` is set while open (null for a run with no known end);
	 *         `next_open` while closed (null when forced closed or never).
	 */
	public static function status( ?DateTimeInterface $now = null ): array {
		return self::status_for( self::resolve_context(), $now );
	}

	/**
	 * status() for explicit hours settings (a branch's, or a preview).
	 *
	 * @since 10.4.0
	 *
	 * @param array{timezone:string,schedule:string,force_check:bool,force_status:bool,holidays:string} $context Hours settings.
	 * @param DateTimeInterface|null                                                                    $now     Moment; default now.
	 * @return array See status().
	 */
	public static function status_for( array $context, ?DateTimeInterface $now = null ): array {
		$timezone = self::resolve_timezone( (string) $context['timezone'] );
		$local    = null === $now
			? DateTimeImmutable::createFromMutable( self::get_order_hours_time( $timezone ) )->setTimezone( $timezone )
			: DateTimeImmutable::createFromInterface( $now )->setTimezone( $timezone );
		$holidays = Lafka_Order_Hours_Engine::parse_holidays( (string) $context['holidays'] );

		$week  = self::module_enabled() ? Lafka_Order_Hours_Engine::parse_schedule( (string) $context['schedule'] ) : null;
		$gated = self::module_enabled() && ( null !== $week || $context['force_check'] || array() !== $holidays );
		if ( $gated ) {
			$source = 'schedule';
		} else {
			$week     = Lafka_Order_Hours_Engine::parse_display( self::display_hours_raw() );
			$holidays = array();
			$source   = null === $week ? 'none' : 'display';
		}

		$resolved = Lafka_Order_Hours_Engine::resolve( $local, $week, $holidays );
		$forced   = $gated && $context['force_check'];
		$is_open  = $forced ? (bool) $context['force_status'] : $resolved['is_open'];
		if ( $forced ) {
			$source = 'forced';
		} elseif ( $gated && $resolved['holiday'] ) {
			$source = 'holiday';
		}

		return array(
			'is_open'       => $is_open,
			'closes_at'     => $is_open ? $resolved['closes_at'] : null,
			'next_open'     => ! $is_open && ! $forced ? $resolved['next_open'] : null,
			'source'        => $source,
			'forced'        => $forced,
			'gated'         => $gated,
			'has_hours'     => $resolved['has_hours'],
			'schedule_open' => $resolved['has_hours'] ? $resolved['is_open'] : null,
			'holiday'       => $gated && $resolved['holiday'],
			'timezone'      => $timezone->getName(),
			'now'           => $local,
		);
	}

	/**
	 * The per-day display hours (Customizer / WooCommerce → Restaurant),
	 * Monday first. These are the hours when the order-hours schedule is not
	 * in use; with a schedule they are derived and these are ignored.
	 *
	 * @since 10.4.0
	 *
	 * @return string[] Seven strings.
	 */
	public static function display_hours_raw(): array {
		$days = array();
		foreach ( array( 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun' ) as $key ) {
			$days[] = trim( (string) get_option( 'lafka_business_hours_' . $key, '' ) );
		}
		return $days;
	}

	/**
	 * The week the store publishes: the schedule's while the order-hours
	 * schedule is the source, else the display hours. Never branch-specific
	 * (the published hours are the main location's).
	 *
	 * @since 10.4.0
	 *
	 * @return array<int,array<int,array{0:int,1:int}>>|null
	 */
	public static function published_week(): ?array {
		if ( self::module_enabled() ) {
			$options = get_option( 'lafka_order_hours_options' );
			$week    = Lafka_Order_Hours_Engine::parse_schedule( is_array( $options ) ? (string) ( $options['lafka_order_hours_schedule'] ?? '' ) : '' );
			if ( null !== $week ) {
				return $week;
			}
		}
		return Lafka_Order_Hours_Engine::parse_display( self::display_hours_raw() );
	}

	/**
	 * The published hours as the display map ['Monday' => '11:00-23:00', …],
	 * for the storefront, schema, llms.txt. Empty when none are set.
	 *
	 * @since 10.4.0
	 *
	 * @return array<string,string>
	 */
	public static function hours_map(): array {
		return Lafka_Order_Hours_Engine::display_map( self::published_week() );
	}

	/**
	 * The published hours as schema.org OpeningHoursSpecification rows.
	 *
	 * @since 10.4.0
	 *
	 * @return array<int,array<string,string>>
	 */
	public static function schema_specification(): array {
		return Lafka_Order_Hours_Engine::schema_specification( self::published_week() );
	}

	/**
	 * Days where the display hours and the order-hours schedule disagree, for
	 * Site Health and WP-CLI. Empty when either is not set, or they agree.
	 *
	 * @since 10.4.0
	 *
	 * @return array<string,array{display:string,schedule:string}> Day name => both readings.
	 */
	public static function hours_discrepancies(): array {
		$options  = get_option( 'lafka_order_hours_options' );
		$schedule = Lafka_Order_Hours_Engine::parse_schedule( is_array( $options ) ? (string) ( $options['lafka_order_hours_schedule'] ?? '' ) : '' );
		$display  = Lafka_Order_Hours_Engine::parse_display( self::display_hours_raw() );
		if ( null === $schedule || null === $display ) {
			return array();
		}
		$schedule_map = Lafka_Order_Hours_Engine::display_map( $schedule );
		$display_map  = Lafka_Order_Hours_Engine::display_map( $display );
		$differences  = array();
		foreach ( Lafka_Order_Hours_Engine::DAYS as $day ) {
			$display_raw = str_replace( '-24:00', '-00:00', $display_map[ $day ] ?? 'Closed' );
			// 00:00, 24:00 and 23:59 all name the end of the day.
			$shown = str_replace( array( '-24:00', '-23:59' ), '-00:00', $display_map[ $day ] ?? 'Closed' );
			$gated = str_replace( array( '-24:00', '-23:59' ), '-00:00', $schedule_map[ $day ] ?? 'Closed' );
			if ( $shown !== $gated ) {
				$differences[ $day ] = array(
					'display'  => $display_raw,
					'schedule' => str_replace( '-24:00', '-00:00', $schedule_map[ $day ] ?? 'Closed' ),
				);
			}
		}
		return $differences;
	}

	/**
	 * Whether the order-hours schedule is what the site publishes (module on and
	 * a schedule saved). When true the per-day display hours are derived and
	 * ignored.
	 *
	 * @since 10.4.0
	 *
	 * @return bool
	 */
	public static function schedule_is_source(): bool {
		if ( ! self::module_enabled() ) {
			return false;
		}
		$options = get_option( 'lafka_order_hours_options' );

		return null !== Lafka_Order_Hours_Engine::parse_schedule( is_array( $options ) ? (string) ( $options['lafka_order_hours_schedule'] ?? '' ) : '' );
	}

	/**
	 * Copy the schedule's hours into the per-day display options so both views
	 * agree. Returns the strings written, Monday first.
	 *
	 * @since 10.4.0
	 *
	 * @return string[] Seven strings ("11:00-23:00", "Closed").
	 */
	public static function sync_display_hours(): array {
		$map     = Lafka_Order_Hours_Engine::display_map( self::schedule_is_source() ? self::published_week() : null );
		$written = array();
		foreach ( array( 'mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun' ) as $index => $key ) {
			$value     = str_replace( '-24:00', '-00:00', $map[ Lafka_Order_Hours_Engine::DAYS[ $index ] ] ?? '' );
			$written[] = $value;
			if ( '' !== $value ) {
				update_option( 'lafka_business_hours_' . $key, 'Closed' === $value ? 'closed' : $value );
			}
		}

		return $written;
	}

	/**
	 * The note shown beside the per-day display-hours fields.
	 *
	 * @since 10.4.0
	 *
	 * @return string Plain text; '' when these fields are the hours in use.
	 */
	public static function display_hours_note(): string {
		if ( ! self::schedule_is_source() ) {
			return '';
		}

		return __( 'The Order hours schedule is in use, so the site shows and enforces those hours and the fields below are ignored. Change the hours under Lafka → Order hours.', 'lafka-plugin' );
	}

	/**
	 * Site Health: the displayed hours and the order schedule should agree.
	 *
	 * @since 10.4.0
	 *
	 * @param array $tests Direct tests.
	 * @return array
	 */
	public static function register_health_test( $tests ) {
		$tests['direct']['lafka_opening_hours'] = array(
			'label' => __( 'Lafka opening hours', 'lafka-plugin' ),
			'test'  => array( __CLASS__, 'health_test' ),
		);

		return $tests;
	}

	/**
	 * Site Health test result.
	 *
	 * @since 10.4.0
	 *
	 * @return array
	 */
	public static function health_test(): array {
		$result = array(
			'label'       => __( 'Opening hours agree', 'lafka-plugin' ),
			'status'      => 'good',
			'badge'       => array(
				'label' => __( 'Lafka', 'lafka-plugin' ),
				'color' => 'blue',
			),
			'description' => '<p>' . esc_html__( 'The hours the site shows are the hours it enforces.', 'lafka-plugin' ) . '</p>',
			'test'        => 'lafka_opening_hours',
		);

		$differences = self::hours_discrepancies();
		if ( array() !== $differences ) {
			$rows = '';
			foreach ( $differences as $day => $pair ) {
				/* translators: 1: weekday, 2: hours typed in the per-day display fields, 3: hours in the order schedule. */
				$rows .= '<li>' . esc_html( sprintf( __( '%1$s: display hours %2$s, order schedule %3$s', 'lafka-plugin' ), $day, $pair['display'], $pair['schedule'] ) ) . '</li>';
			}
			$result['status']         = 'recommended';
			$result['label']          = __( 'Opening hours disagree with the order schedule', 'lafka-plugin' );
			$result['badge']['color'] = 'orange';
			$result['description']    = '<p>' . esc_html__( 'The order schedule decides when orders are accepted and what the site shows; the per-day display hours below are ignored. Change the schedule, or run wp lafka hours sync to copy it into the per-day fields.', 'lafka-plugin' ) . '</p><ul>' . $rows . '</ul>';
		} elseif ( self::module_enabled() && ! self::schedule_is_source() && null !== Lafka_Order_Hours_Engine::parse_display( self::display_hours_raw() ) ) {
			$result['status']         = 'recommended';
			$result['label']          = __( 'Opening hours are shown but not enforced', 'lafka-plugin' );
			$result['badge']['color'] = 'orange';
			$result['description']    = '<p>' . esc_html__( 'Order hours is on but has no schedule, so the store takes orders at any time while the per-day hours are shown. Set the schedule under Lafka → Order hours.', 'lafka-plugin' ) . '</p>';
		}

		return $result;
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
	 * @return bool|DateTime
	 * @throws Exception
	 */
	public static function get_next_opening_time( $timezone = null ) {
		return self::get_next_opening_time_by_params( $timezone, self::$lafka_order_hours_schedule, null, null, null );
	}

	public static function get_next_opening_time_by_params( $timezone, $schedule_json, $force_override_check, $force_override_status, $holidays_calendar ) {
		$context = self::resolve_context();
		if ( null !== $force_override_check || null !== $force_override_status ) {
			$context['force_check']  = (bool) $force_override_check;
			$context['force_status'] = (bool) $force_override_status;
		}
		if ( ! empty( $schedule_json ) ) {
			$context['schedule'] = (string) $schedule_json;
		}
		if ( $timezone instanceof DateTimeZone ) {
			$context['timezone'] = $timezone->getName();
		}
		if ( null !== $holidays_calendar ) {
			$context['holidays'] = (string) $holidays_calendar;
		}

		$status = self::status_for( $context );

		return $status['next_open'] instanceof DateTimeImmutable ? DateTime::createFromImmutable( $status['next_open'] ) : false;
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
	 * @param DateTimeInterface|bool|null $datetime  The next-open time, or false/null when none.
	 * @param DateTimeInterface|null      $reference The moment "today" is measured from; default now.
	 * @return string Human-readable string like "Saturday at 11:00 AM", or empty.
	 * @since  9.7.26
	 */
	public static function format_next_open_time_human( $datetime, ?DateTimeInterface $reference = null ): string {
		// null is the common "no info" case (callers initialise $opening_datetime
		// to null); the instanceof check additionally absorbs the legacy false
		// that the next-open resolvers return for force-closed / scheduleless
		// branches, so neither can reach getTimestamp() and fatal.
		if ( $datetime instanceof DateTimeImmutable ) {
			$datetime = DateTime::createFromImmutable( $datetime );
		}
		if ( null === $datetime || ! $datetime instanceof DateTime ) {
			return '';
		}

		// Sites that customised the old date-format filter keep their format.
		if ( has_filter( 'lafka_next_open_time_format' ) ) {
			/**
			 * Legacy: the WP date format for the next-open time. Hooking it keeps
			 * the old absolute "Saturday at 11:00 AM" style.
			 *
			 * @param string   $format   WP date format string.
			 * @param DateTime $datetime The next-open time.
			 */
			$format = (string) apply_filters( 'lafka_next_open_time_format', 'l \a\t g:i A', $datetime );
			return wp_date( $format, $datetime->getTimestamp(), $datetime->getTimezone() );
		}

		$timezone = $datetime->getTimezone();
		$today    = null === $reference ? new DateTime( 'now', $timezone ) : DateTime::createFromInterface( $reference )->setTimezone( $timezone );
		$today->setTime( 0, 0 );
		$day = clone $datetime;
		$day->setTime( 0, 0 );
		$days_away = (int) $today->diff( $day )->format( '%r%a' );
		$time      = self::format_time_plain( $datetime );

		if ( 0 === $days_away ) {
			/* translators: %s: opening time, e.g. "11 am". */
			$text = sprintf( __( 'today at %s', 'lafka-plugin' ), $time );
		} elseif ( 1 === $days_away ) {
			/* translators: %s: opening time, e.g. "11 am". */
			$text = sprintf( __( 'tomorrow at %s', 'lafka-plugin' ), $time );
		} else {
			/* translators: 1: weekday name, 2: opening time, e.g. "Saturday at 11 am". */
			$text = sprintf( __( '%1$s at %2$s', 'lafka-plugin' ), wp_date( 'l', $datetime->getTimestamp(), $timezone ), $time );
		}

		/**
		 * Filter the human next-open text ("today at 11 am", "Saturday at 4:30 pm").
		 *
		 * @param string   $text     Rendered text.
		 * @param DateTime $datetime The next-open time.
		 */
		return (string) apply_filters( 'lafka_next_open_time_text', $text, $datetime );
	}

	/**
	 * A short spoken time, matching the theme's open-status copy: "11 am",
	 * "4:30 pm", "noon", "midnight".
	 *
	 * @param DateTime $datetime Time to format.
	 * @return string
	 */
	public static function format_time_plain( DateTime $datetime ): string {
		$hour   = (int) $datetime->format( 'G' );
		$minute = (int) $datetime->format( 'i' );
		if ( 0 === $minute && 0 === $hour ) {
			return __( 'midnight', 'lafka-plugin' );
		}
		if ( 0 === $minute && 12 === $hour ) {
			return __( 'noon', 'lafka-plugin' );
		}
		$hour12 = 0 === $hour % 12 ? 12 : $hour % 12;
		$clock  = 0 === $minute ? (string) $hour12 : $hour12 . ':' . sprintf( '%02d', $minute );
		/* translators: %s: hour (and minutes), e.g. "11" or "11:30". */
		$spoken = $hour < 12 ? sprintf( __( '%s am', 'lafka-plugin' ), $clock ) : sprintf( __( '%s pm', 'lafka-plugin' ), $clock );
		// A no-break space keeps "11 am" on one line when the text wraps.
		return str_replace( ' ', "\u{00A0}", $spoken );
	}

	/**
	 * The status as words, the one wording every surface shows:
	 * strong "Open now" / "Closed", rest "until 11 pm" / "opens tomorrow at
	 * 11 am", and both joined as the label.
	 *
	 * @since 10.4.0
	 *
	 * @param array $status A status() result.
	 * @return array{strong:string,rest:string,label:string}
	 */
	public static function status_text( array $status ): array {
		$rest = '';
		if ( $status['is_open'] ) {
			if ( $status['closes_at'] instanceof DateTimeInterface ) {
				/* translators: %s: closing time, e.g. "11 pm". */
				$rest = sprintf( __( 'until %s', 'lafka-plugin' ), self::format_time_plain( DateTime::createFromInterface( $status['closes_at'] ) ) );
			}
		} elseif ( $status['next_open'] instanceof DateTimeInterface ) {
			/* translators: %s: next opening, e.g. "tomorrow at 11 am". */
			$rest = sprintf( __( 'opens %s', 'lafka-plugin' ), self::format_next_open_time_human( $status['next_open'], $status['now'] ) );
		}
		$strong = $status['is_open'] ? __( 'Open now', 'lafka-plugin' ) : __( 'Closed', 'lafka-plugin' );

		return array(
			'strong' => $strong,
			'rest'   => $rest,
			'label'  => '' !== $rest ? $strong . ' · ' . $rest : $strong,
		);
	}

	/**
	 * The status as JSON-ready data for the live status script (and the
	 * /lafka/v1/open-status route): the words, plus `until`, the Unix time the
	 * words next change (the opening or closing, or midnight while "today"
	 * wording is in play); 0 when they never do (forced, or no known change).
	 *
	 * @since 10.4.0
	 *
	 * @param DateTimeInterface|null $now Moment; default now.
	 * @return array{is_open:bool,strong:string,rest:string,label:string,source:string,now:int,until:int}
	 */
	public static function client_status( ?DateTimeInterface $now = null ): array {
		$status = self::status( $now );
		$text   = self::status_text( $status );
		$until  = 0;
		if ( $status['is_open'] && $status['closes_at'] instanceof DateTimeInterface ) {
			$until = $status['closes_at']->getTimestamp();
		} elseif ( ! $status['is_open'] && $status['next_open'] instanceof DateTimeInterface ) {
			$midnight = $status['now']->setTime( 0, 0 )->modify( '+1 day' )->getTimestamp();
			$until    = min( $status['next_open']->getTimestamp(), $midnight );
		}

		return array(
			'is_open' => $status['is_open'],
			'strong'  => $text['strong'],
			'rest'    => $text['rest'],
			'label'   => $text['label'],
			'source'  => $status['source'],
			'now'     => $status['now']->getTimestamp(),
			'until'   => $until,
		);
	}

	public static function get_first_opening_branch_datetime( $all_legit_branches ) {
		$branches_open_times = array();

		foreach ( $all_legit_branches as $branch_id => $branch_name ) {
			$is_overridden = get_term_meta( $branch_id, 'lafka_branch_override_order_hours_global', true );
			if ( ! empty( $is_overridden ) ) {
				$branch_timezone_string       = get_term_meta( $branch_id, 'lafka_branch_timezone', true );
				$branch_timezone              = self::resolve_timezone( (string) $branch_timezone_string );
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
			$branch_timezone_string       = get_term_meta( $branch_id, 'lafka_branch_timezone', true );
			$branch_timezone              = self::resolve_timezone( (string) $branch_timezone_string );
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
		// is_shop_open() branch below are cosmetic ONLY: they print a "closed" card
		// next to WooCommerce's own buttons, which stay in place. A replayed or
		// stale classic place-order POST and the entire Cart/Checkout Blocks +
		// Store API path bypass that UI, so the server must enforce closure
		// itself. These validation hooks are the real
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

			// Order ahead (date/time slots on, a later slot on offer): closed
			// means "schedule for when we open", not "stop". Keep the cart,
			// checkout and place-order buttons; the checkout gate requires a
			// slot instead. The closed card still tells the customer when we open.
			if ( self::can_order_ahead() ) {
				add_action( 'woocommerce_after_add_to_cart_button', array( $this, 'echo_closed_store_message' ), 99 );
				return;
			}

			// Closed and not taking orders ahead: say so where the customer is about
			// to commit, and leave WooCommerce's own buttons in place (express-pay
			// and other extensions hang on them). The theme dims them from the
			// lafka-store-closed body class; the gates above refuse the order and
			// any add-to-cart (when the operator opted in), through the cart's
			// own validation, the classic checkout and the Store API.
			add_action( 'woocommerce_proceed_to_checkout', array( $this, 'echo_closed_store_message' ), 10 );
			add_action( 'woocommerce_widget_shopping_cart_before_buttons', array( $this, 'echo_closed_store_message' ), 10 );
			add_action( 'woocommerce_before_checkout_form', array( $this, 'echo_closed_store_message' ), 5 );
			add_action( 'woocommerce_after_add_to_cart_button', array( $this, 'echo_closed_store_message' ), 99 );
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
	 * The closed notice with the next opening appended when it is known, e.g.
	 * "Sorry, we're closed. Opens Saturday at 11:00 AM."
	 *
	 * @return string
	 */
	public static function get_closed_notice_with_next_open(): string {
		return self::compose_closed_notice(
			self::get_closed_notice_message(),
			self::format_next_open_time_human( self::resolve_next_opening() )
		);
	}

	/**
	 * Join the closed message and the next-opening text (pure).
	 *
	 * @param string $message   Closed message (operator or default).
	 * @param string $next_open Human next-opening text ('' when unknown).
	 * @return string
	 */
	public static function compose_closed_notice( string $message, string $next_open ): string {
		if ( '' === $next_open ) {
			return $message;
		}
		$message = rtrim( $message );
		if ( '' !== $message && ! preg_match( '/[.!?…]$/u', $message ) ) {
			$message .= '.';
		}

		/* translators: 1: store-closed message, 2: next opening, e.g. "Saturday at 11:00 AM". */
		return trim( sprintf( __( '%1$s Opens %2$s.', 'lafka-plugin' ), $message, $next_open ) );
	}

	/**
	 * The next opening for the customer's context: the session branch's clock
	 * when a branch is chosen, the earliest opening across branches when the
	 * shipping-areas module is on, else the main store's schedule.
	 *
	 * @return DateTime|null
	 */
	public static function resolve_next_opening() {
		$branch_id = null;
		if ( function_exists( 'WC' ) && isset( WC()->session ) ) {
			$branch_id = WC()->session->get( 'lafka_branch_location' )['branch_id'] ?? null;
		}
		if ( null !== $branch_id ) {
			$timezone_object = empty( self::$timezone ) ? null : self::resolve_timezone( (string) self::$timezone );
			$opening         = self::get_next_opening_time( $timezone_object );
		} elseif ( class_exists( 'Lafka_Shipping_Areas' ) ) {
			$opening = self::get_first_opening_branch_datetime( Lafka_Shipping_Areas::get_all_legit_branch_locations() );
		} else {
			$opening = self::get_next_opening_time();
		}

		return $opening instanceof DateTime ? $opening : null;
	}

	/**
	 * Whether a closed store can still take orders for later: the delivery/
	 * pickup date-time feature is on and offers at least one slot date. A
	 * force-closed store (operator override) never takes orders.
	 *
	 * @return bool
	 */
	public static function can_order_ahead(): bool {
		$forced_closed = self::$lafka_order_hours_force_override_check && ! self::$lafka_order_hours_force_override_status;

		$can = false;
		if ( ! $forced_closed && class_exists( 'Lafka_Timeslots' ) && Lafka_Timeslots::is_feature_enabled() ) {
			$timeslots = Lafka_Timeslots::instance();
			if ( $timeslots instanceof Lafka_Timeslots ) {
				$can = empty( self::$lafka_order_hours_schedule )
					|| array() !== Lafka_Timeslots::get_enabled_dates_for_days_ahead( $timeslots->get_days_ahead(), $timeslots->get_timeslot_duration() );
			}
		}

		/**
		 * Filter whether a closed store accepts orders scheduled for later.
		 *
		 * @param bool $can Default: date/time slots on and a slot date on offer.
		 */
		return (bool) apply_filters( 'lafka_order_hours_can_order_ahead', $can );
	}

	/**
	 * Whether add-to-cart is blocked right now: closed, the operator opted
	 * into lafka_order_hours_disable_add_to_cart, and ordering ahead is not
	 * possible. Theme templates that render their own add-to-cart form read
	 * this so they agree with the server gates.
	 *
	 * @return bool
	 */
	public static function is_add_to_cart_blocked(): bool {
		return ! self::is_shop_open()
			&& ! empty( self::$lafka_order_hours_options['lafka_order_hours_disable_add_to_cart'] )
			&& ! self::can_order_ahead();
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
		if ( self::can_order_ahead() ) {
			// A chosen slot is validated (offered, in the future, not full) by
			// Lafka_Timeslots::validate_datetime_fields() on this same hook.
			$date = '';
			$slot = '';
			if ( lafka_verify_checkout_nonce() ) {
				$date = isset( $_POST['lafka_checkout_date'] ) ? sanitize_text_field( wp_unslash( $_POST['lafka_checkout_date'] ) ) : '';
				$slot = isset( $_POST['lafka_checkout_timeslot'] ) ? sanitize_text_field( wp_unslash( $_POST['lafka_checkout_timeslot'] ) ) : '';
			}
			if ( '' !== $date && '' !== $slot ) {
				return;
			}
			wc_add_notice( esc_html( self::get_closed_notice_with_next_open() . ' ' . self::choose_time_hint() ), 'error' );
			Lafka_Checkout_Block_Reasons::emit_code(
				'lafka_store_closed',
				array(
					'path'  => 'classic',
					'stage' => 'checkout',
				)
			);
			return;
		}
		wc_add_notice( esc_html( self::get_closed_notice_with_next_open() ), 'error' );
		Lafka_Checkout_Block_Reasons::emit_code(
			'lafka_store_closed',
			array(
				'path'  => 'classic',
				'stage' => 'checkout',
			)
		);
	}

	/**
	 * "Choose a time" hint appended to the closed notice when ordering ahead.
	 *
	 * @return string
	 */
	public static function choose_time_hint(): string {
		return __( 'Please choose a delivery or pickup time for when we are open.', 'lafka-plugin' );
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
		if ( ! $passed || self::is_shop_open() ) {
			return $passed;
		}

		if ( $this->is_add_to_cart_disabled_when_closed() && ! self::can_order_ahead() ) {
			wc_add_notice( esc_html( self::get_closed_notice_with_next_open() ), 'error' );
			Lafka_Checkout_Block_Reasons::emit_code(
				'lafka_store_closed',
				array(
					'path'  => 'classic',
					'stage' => 'add_to_cart',
				)
			);

			return false;
		}

		// The item may go in the cart, but say now — not at the place-order
		// button — that we are closed and when checkout works.
		$hint   = self::can_order_ahead()
			? __( 'You can order now and choose a time at checkout.', 'lafka-plugin' )
			: __( 'You can fill your cart now and check out once we are open.', 'lafka-plugin' );
		$notice = esc_html( self::get_closed_notice_with_next_open() . ' ' . $hint );
		if ( ! function_exists( 'wc_has_notice' ) || ! wc_has_notice( $notice, 'notice' ) ) {
			wc_add_notice( $notice, 'notice' );
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
		if ( ! self::is_add_to_cart_blocked() ) {
			return;
		}
		if ( ! class_exists( '\Automattic\WooCommerce\StoreApi\Exceptions\RouteException' ) ) {
			return;
		}
		Lafka_Checkout_Block_Reasons::emit_code(
			'lafka_store_closed',
			array(
				'path'  => 'store_api',
				'stage' => 'add_to_cart',
			)
		);
		throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException(
			'lafka_store_closed',
			esc_html( self::get_closed_notice_with_next_open() ),
			409
		);
	}

	public function add_body_class( $classes ) {
		$classes[] = 'lafka-store-closed';

		if ( self::can_order_ahead() ) {
			// Themes keep add-to-cart usable (no "disabled" styling) while
			// customers can still order for later.
			$classes[] = 'lafka-order-ahead';
		} elseif ( isset( self::$lafka_order_hours_options['lafka_order_hours_disable_add_to_cart'] ) && self::$lafka_order_hours_options['lafka_order_hours_disable_add_to_cart'] ) {
			$classes[] = 'lafka-disabled-cart-buttons';
		}

		return $classes;
	}

	/**
	 * Render the customer-facing "store closed" card.
	 *
	 * Static so theme templates can render it directly — the redesigned PDP
	 * (lafka-theme/partials/pdp-buybox.php) swaps its add-to-cart form for the
	 * card when is_add_to_cart_blocked() says so. Also used as an instance-array action callback
	 * ( array( $this, 'echo_closed_store_message' ) ), which PHP resolves to the
	 * same static method. Uses only self:: references — no $this.
	 *
	 * @return void
	 */
	public static function echo_closed_store_message() {
		$operator_message = self::$lafka_order_hours_options['lafka_order_hours_message'] ?? '';
		$title            = '' !== $operator_message ? $operator_message : __( 'Closed right now', 'lafka-plugin' );

		$opening_datetime = self::resolve_next_opening();

		$subtitle_human = self::format_next_open_time_human( $opening_datetime );
		?>
		<div class="lafka-store-closed-card">
			<p class="lafka-store-closed-card__title"><?php echo esc_html( $title ); ?></p>
			<?php if ( '' !== $subtitle_human ) : ?>
				<p class="lafka-store-closed-card__subtitle">
					<?php echo esc_html( sprintf( /* translators: %s: human-readable opening time. */ __( 'Opens %s', 'lafka-plugin' ), $subtitle_human ) ); ?>
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

	/**
	 * Register GET /lafka/v1/open-status: the live status script's refresh
	 * when a cached page's wording has gone stale. Public, uncached.
	 *
	 * @since 10.4.0
	 *
	 * @return void
	 */
	public static function register_rest_routes(): void {
		register_rest_route(
			'lafka/v1',
			'/open-status',
			array(
				'methods'             => 'GET',
				'permission_callback' => '__return_true',
				'callback'            => static function () {
					$response = new WP_REST_Response( self::client_status() );
					$response->header( 'Cache-Control', 'no-store' );
					return $response;
				},
			)
		);
	}
}

add_action( 'rest_api_init', array( 'Lafka_Order_Hours', 'register_rest_routes' ) );
add_filter( 'site_status_tests', array( 'Lafka_Order_Hours', 'register_health_test' ) );
