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

			return in_array( $date->format( 'Y-m-d' ), $vacation_dates_array );
		}

		return false;
	}

	public function init() {
		$this->init_order_hours_options();
		$this->handle_shop_status();

		if ( is_admin() ) {
			include_once( dirname( __FILE__ ) . '/settings/Lafka_Order_Hours_Admin.php' );
			new Lafka_Order_Hours_Admin();
		}
	}

	public static function get_order_hours_time( DateTimeZone $timezone = null ): DateTime {
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
			return (object) array( 'code' => 'open', 'value' => esc_html__( 'Open', 'lafka-plugin' ) );
		}

		return (object) array( 'code' => 'closed', 'value' => esc_html__( 'Closed', 'lafka-plugin' ) );
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
			for ( $day_of_week = $numeric_day_of_the_week - 1; $day_of_week < $day_of_week + 6; $day_of_week ++ ) {
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

				$counter ++;
			}
		}

		return false;
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

		if ( empty( $branches_open_times[0] ) ) {
			unset( $branches_open_times[0] );
		}

		if ( empty( $branches_open_times ) ) {
			return null;
		} else {
			arsort( $branches_open_times );

			return array_pop( $branches_open_times );
		}
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

		$shop_status                  = Lafka_Order_Hours::get_shop_status( $branch_timezone, $branch_schedule, $branch_force_override_check, $branch_force_override_status, $branch_holidays_calendar );
		$shop_status->branch_timezone = $branch_timezone;

		return $shop_status;
	}

	public function handle_shop_status() {
		if ( ! Lafka_Order_Hours::is_shop_open() ) {

			// Add classes to body
			add_filter( 'body_class', array( $this, 'add_body_class' ) );

			remove_action( 'woocommerce_proceed_to_checkout', 'woocommerce_button_proceed_to_checkout', 20 );
			add_action( 'woocommerce_proceed_to_checkout', array( $this, 'echo_closed_store_message' ), 20 );

			remove_action( 'woocommerce_widget_shopping_cart_buttons', 'woocommerce_widget_shopping_cart_proceed_to_checkout', 20 );
			add_action( 'woocommerce_widget_shopping_cart_buttons', array( $this, 'echo_closed_store_message' ), 20 );

			add_action( 'woocommerce_after_add_to_cart_button', array( $this, 'echo_closed_store_message' ), 99 );
			add_filter( 'woocommerce_order_button_html', array( $this, 'get_closed_store_message' ) );
		}
	}

	public function add_body_class( $classes ) {
		$classes[] = 'lafka-store-closed';

		if ( isset( self::$lafka_order_hours_options['lafka_order_hours_disable_add_to_cart'] ) && self::$lafka_order_hours_options['lafka_order_hours_disable_add_to_cart'] ) {
			$classes[] = 'lafka-disabled-cart-buttons';
		}

		return $classes;
	}

	public function echo_closed_store_message() {
		global $post;

		if ( isset( self::$lafka_order_hours_options['lafka_order_hours_message'] ) && self::$lafka_order_hours_options['lafka_order_hours_message'] ) {
			?>
            <div class="lafka-closed-store-message"><?php echo esc_html( self::$lafka_order_hours_options['lafka_order_hours_message'] ) ?>
				<?php
				if ( isset( self::$lafka_order_hours_options['lafka_order_hours_message_countdown'] ) && self::$lafka_order_hours_options['lafka_order_hours_message_countdown'] ) {
					$lafka_branch_location_id_in_session = null;
					$opening_datetime = null;
					if ( isset( WC()->session ) ) {
						$lafka_branch_location_id_in_session = WC()->session->get( 'lafka_branch_location' )['branch_id'] ?? null;
					}
					if ( $lafka_branch_location_id_in_session !== null ) {
						$timezone_object  = empty( self::$timezone ) ? null : new DateTimeZone( self::$timezone );
						$opening_datetime = self::get_next_opening_time( $timezone_object );
					} elseif ( class_exists( 'Lafka_Shipping_Areas' ) ) {
						$all_legit_branch_locations = Lafka_Shipping_Areas::get_all_legit_branch_locations();
						$opening_datetime           = Lafka_Order_Hours::get_first_opening_branch_datetime( $all_legit_branch_locations );
					}

					if ( $opening_datetime ) {
						$countdown_output_format = '{hn}:{mnn}:{snn}';
						$difference              = $opening_datetime->diff( self::get_order_hours_time() );
						if ( $difference && $difference->d > 0 ) {
							$countdown_output_format = '{dn} {dl} {hn}:{mnn}:{snn}';
						}
						?>
                        <div class="count_holder_small">
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
				}
				?>
            </div>
		<?php }

	}

	public function get_closed_store_message() {
		ob_start();
		$this->echo_closed_store_message();

		return ob_get_clean();
	}
}

new Lafka_Order_Hours();