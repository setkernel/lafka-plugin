<?php
/**
 * Lafka_Timeslots — date/time slot booking for delivery / pickup checkout.
 *
 * Extracted from the Lafka_Shipping_Areas god class in v9.4.0 (Path A
 * Phase 3). The behaviour is unchanged — same hooks, same priorities,
 * same field names, same order meta keys — but the timeslot concern
 * now lives in its own focused unit instead of blended into the
 * shipping-areas god class.
 *
 * What this class owns:
 *
 *   • Loading the per-branch-aware datetime config (mandatory flag,
 *     days-ahead window, timeslot duration) on `init` priority 99
 *     (after Lafka_Order_Hours has booted)
 *   • Rendering the date-picker + timeslot-select on the checkout
 *     page, gated on the operator's `enable_datetime_option` setting
 *   • The AJAX `time_slots_for_date` endpoint that hydrates available
 *     timeslots when the customer picks a date
 *   • Saving picked datetime to order meta on
 *     `woocommerce_checkout_create_order`
 *   • Admin order-list columns + sortable column registration for
 *     both legacy CPT and HPOS storage
 *   • Static utilities: enumerate dates, enumerate timeslots, count
 *     orders per slot for capacity enforcement
 *
 * What stays on Lafka_Shipping_Areas:
 *
 *   • get_order_meta_backward_compatible() — generic order-meta read
 *     helper, used by both timeslot and non-timeslot code, lives on
 *     the god class until a dedicated helpers file justifies extraction
 *   • output_custom_fields_in_thank_you_page() — cross-cutting (mixes
 *     order_type + branch + delivery_location + datetime) so easier to
 *     keep in the god class until further decomposition
 *
 * @package Lafka\Plugin\Timeslots
 * @since   9.4.0
 */

defined( 'ABSPATH' ) || exit;

class Lafka_Timeslots {

	/**
	 * @var Lafka_Timeslots|null
	 */
	protected static $_instance = null;

	/**
	 * Whether the customer must pick a date+time before checkout submits.
	 * Read from the per-branch override if a branch is in session, else
	 * from the global `lafka_shipping_areas_datetime` option.
	 *
	 * @var bool
	 */
	private $order_date_time_mandatory;

	/**
	 * How many days into the future the date picker shows.
	 *
	 * @var int
	 */
	private $order_date_time_days_ahead;

	/**
	 * Duration of one slot in minutes (e.g. 60 = hourly slots).
	 *
	 * @var int
	 */
	private $order_date_time_timeslot_duration;

	public static function instance(): ?Lafka_Timeslots {
		if ( null === self::$_instance ) {
			self::$_instance = new self();
		}
		return self::$_instance;
	}

	protected function __construct() {
		// Load datetime config after Lafka_Order_Hours has registered its
		// schedule (priority 99 stays the same as the god-class original).
		add_action( 'init', array( $this, 'init_order_date_time_options' ), 99 );

		$datetime_options = get_option( 'lafka_shipping_areas_datetime' );
		if ( empty( $datetime_options['enable_datetime_option'] ) ) {
			return;
		}

		add_action( 'woocommerce_checkout_before_customer_details', array( $this, 'show_datetime_fields_in_checkout' ) );
		add_action( 'wp_ajax_time_slots_for_date', array( $this, 'retrieve_time_slots_for_date' ) );
		add_action( 'wp_ajax_nopriv_time_slots_for_date', array( $this, 'retrieve_time_slots_for_date' ) );
		// Save datetime to order. `woocommerce_checkout_update_order_meta`
		// was deprecated in WC 9.0; `woocommerce_checkout_create_order`
		// fires before the order is saved, receives WC_Order directly,
		// and is HPOS-safe without branching.
		add_action( 'woocommerce_checkout_create_order', array( $this, 'checkout_datetime_update_order_meta' ), 10, 2 );

		// Show datetime in admin order list — both legacy CPT + HPOS.
		add_filter( 'manage_shop_order_posts_columns', array( __CLASS__, 'add_datetime_to_orders_list' ), 20 );
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( __CLASS__, 'add_datetime_to_orders_list' ), 20 );
		add_action( 'manage_shop_order_posts_custom_column', array( __CLASS__, 'add_datetime_content_to_orders_list' ), 10, 2 );
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( __CLASS__, 'add_datetime_content_to_orders_list' ), 10, 2 );
		add_action( 'manage_edit-shop_order_sortable_columns', array( __CLASS__, 'add_datetime_to_sortable_columns' ) );
		add_action( 'woocommerce_shop_order_list_table_sortable_columns', array( __CLASS__, 'add_datetime_to_sortable_columns' ) );
	}

	public function __clone() {
		wc_doing_it_wrong( __FUNCTION__, __( 'Cloning is forbidden.', 'lafka-plugin' ), '9.4.0' );
	}

	public function __wakeup() {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- _doing_it_wrong() emits to error log + do_action hook, not HTML; escaping would corrupt plain-text log output.
		_doing_it_wrong( __FUNCTION__, __( 'Foul!', 'lafka-plugin' ), '9.4.0' );
	}

	/**
	 * Accessors for the per-branch-resolved datetime config. Used by the
	 * shipping-areas god class's enqueue_scripts() to localize the date-
	 * picker JS, which needs the same values this class uses internally.
	 */
	public function is_mandatory(): bool {
		return ! empty( $this->order_date_time_mandatory );
	}

	public function get_days_ahead(): int {
		return (int) ( $this->order_date_time_days_ahead ?? 30 );
	}

	public function get_timeslot_duration(): int {
		return (int) ( $this->order_date_time_timeslot_duration ?? 60 );
	}

	/**
	 * Public alias for the internal get_all_days_ahead() helper, exposed
	 * for the shipping-areas script enqueue path. Internal callers still
	 * use the private variant.
	 */
	public static function get_all_days_ahead_public( $days_ahead ): array {
		return self::get_all_days_ahead( $days_ahead );
	}

	/**
	 * Hydrate datetime config: read globals, optionally override from
	 * the in-session branch's term meta if the branch opts out of the
	 * global config.
	 */
	public function init_order_date_time_options() {
		$datetime_options = get_option( 'lafka_shipping_areas_datetime' );

		$this->order_date_time_mandatory         = $datetime_options['datetime_mandatory'] ?? false;
		$this->order_date_time_days_ahead        = $datetime_options['days_ahead'] ?? 30;
		$this->order_date_time_timeslot_duration = $datetime_options['timeslot_duration'] ?? 60;

		if ( isset( WC()->session ) ) {
			$lafka_branch_location_id_in_session = WC()->session->get( 'lafka_branch_location' )['branch_id'] ?? null;
			if ( ! empty( $lafka_branch_location_id_in_session ) ) {
				$override_global_date_time = get_term_meta( $lafka_branch_location_id_in_session, 'lafka_branch_override_datetime_global', true );
				if ( ! empty( $override_global_date_time ) ) {
					$this->order_date_time_mandatory         = get_term_meta( $lafka_branch_location_id_in_session, 'lafka_branch_datetime_mandatory', true );
					$this->order_date_time_days_ahead        = get_term_meta( $lafka_branch_location_id_in_session, 'lafka_branch_datetime_days_ahead', true );
					$this->order_date_time_timeslot_duration = get_term_meta( $lafka_branch_location_id_in_session, 'lafka_branch_datetime_timeslot_duration', true );
				}
			}
		}
	}

	public function checkout_datetime_update_order_meta( $order, $data = null ) {
		// Backward-compat: legacy hook passed an int order_id.
		if ( is_numeric( $order ) ) {
			$order = wc_get_order( $order );
		}
		if ( ! $order instanceof WC_Order ) {
			return;
		}
		// CSRF: hooked to woocommerce_checkout_order_processed / woocommerce_checkout_process;
		// WC core verifies its own checkout nonce upstream before this hook fires.
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- WC core verifies checkout nonce upstream.
		if ( ! empty( $_POST['lafka_checkout_date'] ) ) {
			$order->update_meta_data( 'lafka_checkout_date', sanitize_text_field( wp_unslash( $_POST['lafka_checkout_date'] ) ) );
		} elseif ( ! empty( $this->order_date_time_mandatory ) ) {
			wc_add_notice( esc_html__( 'Please enter Delivery/Pickup time.', 'lafka-plugin' ), 'error' );
		}
		if ( ! empty( $_POST['lafka_checkout_timeslot'] ) ) {
			$order->update_meta_data( 'lafka_checkout_timeslot', sanitize_text_field( wp_unslash( $_POST['lafka_checkout_timeslot'] ) ) );
		} elseif ( ! empty( $this->order_date_time_mandatory ) ) {
			wc_add_notice( esc_html__( 'Please enter Delivery/Pickup time.', 'lafka-plugin' ), 'error' );
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing
	}

	public static function add_datetime_to_orders_list( $columns ): array {
		$columns['lafka_datetime_complete'] = esc_html__( 'Delivery/Pickup Time', 'lafka-plugin' );

		return $columns;
	}

	public static function add_datetime_content_to_orders_list( $column, $order ) {
		$order = wc_get_order( $order );

		if ( $column === 'lafka_datetime_complete' ) {
			$date     = Lafka_Shipping_Areas::get_order_meta_backward_compatible( $order->get_id(), 'lafka_checkout_date' );
			$timeslot = Lafka_Shipping_Areas::get_order_meta_backward_compatible( $order->get_id(), 'lafka_checkout_timeslot' );

			if ( ! empty( $date ) ) {
				echo '<span class="lafka-delivery-date">' . esc_html( $date ) . '</span>';
			}
			if ( ! empty( $timeslot ) ) {
				echo '<span class="lafka-delivery-timeslot">' . esc_html( $timeslot ) . '</span>';
			}
		}
	}

	public static function add_datetime_to_sortable_columns( $columns ): array {
		$columns['lafka_datetime_complete'] = 'lafka_checkout_date';

		return $columns;
	}

	public static function get_enabled_dates_for_days_ahead( $days_ahead, $timeslot_duration ): array {
		$current_time  = Lafka_Order_Hours::get_order_hours_time();
		$interval      = DateInterval::createFromDateString( '1 day' );
		$enabled_dates = array();

		if ( ! empty( Lafka_Order_Hours::$lafka_order_hours_schedule ) ) {
			$schedule_json  = Lafka_Order_Hours::$lafka_order_hours_schedule;
			$schedule_array = json_decode( $schedule_json );

			for ( $i = 0; $i <= $days_ahead; $i++ ) {
				$day_of_the_week_to_check = $current_time->format( 'N' ) - 1;
				if ( ! empty( $schedule_array[ $day_of_the_week_to_check ]->periods ) && ! Lafka_Order_Hours::is_day_in_vacation( $current_time ) ) {
					// If is today
					if ( $current_time->format( 'Y-m-d' ) === Lafka_Order_Hours::get_order_hours_time()->format( 'Y-m-d' ) ) {
						if ( self::has_future_slots_for_today( $current_time, $timeslot_duration ) ) {
							$enabled_dates[] = $current_time->format( 'Y-m-d' );
						}
					} else {
						$enabled_dates[] = $current_time->format( 'Y-m-d' );
					}
				}
				$current_time->add( $interval );
			}
		}

		return $enabled_dates;
	}

	public static function get_timeslots_for_date( DateTime $date, $timeslot_duration ): array {
		$time_periods = array();

		if ( class_exists( 'Lafka_Order_Hours' ) && ! empty( Lafka_Order_Hours::$lafka_order_hours_schedule ) ) {
			$schedule_json            = Lafka_Order_Hours::$lafka_order_hours_schedule;
			$schedule_array           = json_decode( $schedule_json );
			$day_of_the_week_to_check = $date->format( 'N' ) - 1;
			if ( ! empty( $schedule_array[ $day_of_the_week_to_check ]->periods ) ) {
				$day_periods = $schedule_array[ $day_of_the_week_to_check ]->periods;
				usort(
					$day_periods,
					function ( $a, $b ) {
						return strcmp( $a->start, $b->start );
					}
				);
				foreach ( $day_periods as $period ) {
					if ( $period->end === '00:00' ) {
						$period->end = '24:00';
					}

					while ( 1 ) {
						$entry               = array();
						$curr_start          = $period->start;
						$entry['start']      = $curr_start;
						$current_time        = new DateTime( 'now', $date->getTimezone() );
						$start_time          = DateTime::createFromFormat( 'Y-m-d H:i', $date->format( 'Y-m-d' ) . ' ' . $period->start, $date->getTimezone() );
						$end_time            = DateTime::createFromFormat( 'Y-m-d H:i', $date->format( 'Y-m-d' ) . ' ' . $period->end, $date->getTimezone() );
						$start_plus_timeslot = DateTime::createFromFormat( 'Y-m-d H:i', $date->format( 'Y-m-d' ) . ' ' . $period->start, $date->getTimezone() )->add( DateInterval::createFromDateString( $timeslot_duration . ' minutes' ) );
						$is_today            = Lafka_Order_Hours::get_order_hours_time()->format( 'Y-m-d' ) === $start_plus_timeslot->format( 'Y-m-d' );
						if ( $start_plus_timeslot >= $end_time ) {
							$entry['end'] = $period->end;
							if ( $is_today ) {
								if ( $current_time < $start_plus_timeslot && $current_time <= $start_time ) {
									$time_periods[] = $entry;
								}
							} else {
								$time_periods[] = $entry;
							}
							break;
						} else {
							$entry['end']  = $start_plus_timeslot->format( 'H:i' );
							$period->start = $entry['end'];
							if ( $is_today ) {
								if ( $current_time < $start_plus_timeslot && $current_time <= $start_time ) {
									$time_periods[] = $entry;
								}
							} else {
								$time_periods[] = $entry;
							}
						}
					}
				}
			}
		} else {
			$time_periods = self::get_all_timeslots_static( $date, $timeslot_duration );
		}

		$branch_id = null;
		if ( isset( WC()->session ) ) {
			$branch_id = WC()->session->get( 'lafka_branch_location' )['branch_id'] ?? null;
		}
		$max_orders_per_slot = self::get_max_orders_per_slot( $branch_id );

		$response = array();
		foreach ( $time_periods as $time_period ) {
			$timeslot_disabled        = false;
			$option_title             = '';
			$orders_made_per_timeslot = self::get_number_of_orders_per_timeslot( $branch_id, $date, $time_period );
			if ( $max_orders_per_slot && $orders_made_per_timeslot >= $max_orders_per_slot ) {
				$timeslot_disabled = true;
				$option_title      = esc_html__( 'unavailable. Maximum orders reached.', 'lafka-plugin' );
			}

			$response[] = array(
				'id'       => $time_period['start'] . ' - ' . $time_period['end'],
				'text'     => $time_period['start'] . ' - ' . $time_period['end'],
				'disabled' => $timeslot_disabled,
				'title'    => ( $option_title ? $time_period['start'] . ' - ' . $time_period['end'] . ' ' . $option_title : '' ),
			);
		}

		return $response;
	}

	private static function get_max_orders_per_slot( $branch_id ) {
		$datetime_options = get_option( 'lafka_shipping_areas_datetime' );
		if ( $branch_id !== null ) {
			$branch_override_global_datetime = get_term_meta( $branch_id, 'lafka_branch_override_datetime_global', true );
			$branch_max_orders_per_timeslot  = get_term_meta( $branch_id, 'lafka_branch_datetime_orders_per_timeslot', true );
			if ( ! empty( $branch_override_global_datetime ) ) {
				return $branch_max_orders_per_timeslot;
			}
		}

		return $datetime_options['orders_per_timeslot'] ?? false;
	}

	private static function has_future_slots_for_today( DateTime $date, $timeslot_duration ): bool {
		if ( ! empty( Lafka_Order_Hours::$lafka_order_hours_schedule ) ) {
			$schedule_json            = Lafka_Order_Hours::$lafka_order_hours_schedule;
			$schedule_array           = json_decode( $schedule_json );
			$day_of_the_week_to_check = $date->format( 'N' ) - 1;
			if ( ! empty( $schedule_array[ $day_of_the_week_to_check ]->periods ) ) {
				$day_periods = $schedule_array[ $day_of_the_week_to_check ]->periods;
				usort(
					$day_periods,
					function ( $a, $b ) {
						return strcmp( $a->start, $b->start );
					}
				);
				foreach ( $day_periods as $period ) {
					if ( $period->end === '00:00' ) {
						$period->end = '24:00';
					}
					$hours_minutes_array_end = explode( ':', $period->end );
					if ( Lafka_Order_Hours::get_order_hours_time()->setTime( $hours_minutes_array_end[0], $hours_minutes_array_end[1] )->sub( DateInterval::createFromDateString( $timeslot_duration . ' minutes' ) ) > $date ) {
						return true;
					}
				}
			}

			return false;
		}

		return false;
	}

	public function show_datetime_fields_in_checkout() {
		$order_type_label = esc_html__( 'Delivery', 'lafka-plugin' );
		if ( ! empty( WC()->session ) ) {
			$branch_location_session = WC()->session->get( 'lafka_branch_location' );
			if ( ! empty( $branch_location_session ) ) {
				$order_type = $branch_location_session['order_type'];
				if ( $order_type === 'pickup' ) {
					$order_type_label = esc_html__( 'Pickup', 'lafka-plugin' );
				}
			}
		}
		?>
		<div class="lafka-checkout-datetime-container">
			<div class="lafka-checkout-datetime-trigger">
				<a href="javascript:" class="lafka-delivery-time-toggle" title="<?php esc_html_e( 'Toggle Time Pickers', 'lafka-plugin' ); ?>">
					<?php echo esc_html__( 'Specify', 'lafka-plugin' ) . ' '; ?><?php echo esc_html( $order_type_label ); ?><?php echo ' ' . esc_html__( 'Time', 'lafka-plugin' ); ?>
					<?php if ( empty( $this->order_date_time_mandatory ) ) : ?>
						<?php esc_html_e( '(optional)', 'lafka-plugin' ); ?>
					<?php else : ?>
						<abbr class="required" title="<?php echo esc_html__( 'required', 'lafka-plugin' ); ?>">*</abbr>
					<?php endif; ?>
				</a>
			</div>
			<div class="lafka-checkout-datetime-fields
			<?php
			if ( empty( $this->order_date_time_mandatory ) ) :
				?>
				hidden<?php endif; ?>">
				<input name="lafka_checkout_date" id="lafka_checkout_date"
				<?php
				if ( $this->order_date_time_days_ahead < 1 ) :
					?>
					class="hidden"<?php endif; ?> type="text"
						placeholder="<?php esc_html_e( 'Select Date', 'lafka-plugin' ); ?>..">
				<select name="lafka_checkout_timeslot" id="lafka_checkout_timeslot">
					<?php if ( empty( $this->order_date_time_mandatory ) ) : ?>
						<option></option>
					<?php endif; ?>
				</select>
				<?php if ( $this->order_date_time_days_ahead > 0 && empty( $this->order_date_time_mandatory ) ) : ?>
					<a href="javascript:" class="lafka-datetime-clear"
						title="<?php esc_html_e( 'Clear', 'lafka-plugin' ); ?> <?php echo esc_html( $order_type_label ); ?> <?php esc_html_e( 'Time Entries', 'lafka-plugin' ); ?>"><?php echo esc_html__( 'Clear', 'lafka-plugin' ); ?></a>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	public function retrieve_time_slots_for_date() {
		check_ajax_referer( 'time_slots_for_date' );

		$date      = DateTime::createFromFormat( 'Y-m-d', sanitize_text_field( $_POST['date'] ), class_exists( 'Lafka_Order_Hours' ) ? Lafka_Order_Hours::get_timezone() : wp_timezone() );
		$timeslots = self::get_timeslots_for_date( $date, $this->order_date_time_timeslot_duration );
		wp_send_json_success( $timeslots );
	}

	private static function get_all_days_ahead( $days_ahead ): array {
		$current_time = new DateTime( 'now' );
		$interval     = DateInterval::createFromDateString( '1 day' );
		$days         = array( $current_time->format( 'Y-m-d' ) );

		for ( $i = 1; $i <= $days_ahead; $i++ ) {
			$days[] = $current_time->add( $interval )->format( 'Y-m-d' );
		}

		return $days;
	}

	private static function get_all_timeslots_static( DateTime $date, $timeslot_duration ): array {
		$timeslots = array();

		$curr_time = new DateTime( 'now', $date->getTimezone() );
		$time      = ( clone $curr_time )->setTime( 0, 0 );
		$interval  = DateInterval::createFromDateString( $timeslot_duration . ' minutes' );
		while ( $curr_time->format( 'Y-m-d' ) === $time->format( 'Y-m-d' ) ) {
			$start = $time->format( 'H:i' );
			$end   = $time->add( $interval )->format( 'H:i' );
			if ( $time->format( 'Y-m-d' ) !== $curr_time->format( 'Y-m-d' ) ) {
				$end = '24:00';
			}
			$is_today = $curr_time->format( 'Y-m-d' ) === $date->format( 'Y-m-d' );
			if ( $is_today && $curr_time <= ( clone $time )->sub( $interval ) || ! $is_today ) {
				$timeslots[] = array(
					'start' => $start,
					'end'   => $end,
				);
			}
		}

		return $timeslots;
	}

	private static function get_number_of_orders_per_timeslot( $branch_id, DateTime $order_date, $order_timeslot ): int {
		// Branch clause: a numeric ID matches that branch's orders; anything else
		// (empty/null/non-numeric) matches orders with NO branch assigned. Using
		// `'value' => null` was a bug because SQL `meta_value = NULL` never
		// matches — orders were silently undercounted, leading to overbooking.
		// Use `compare => 'NOT EXISTS'` for the unset case.
		if ( is_numeric( $branch_id ) ) {
			$branch_clause = array(
				'key'     => 'lafka_selected_branch_id',
				'value'   => $branch_id,
				'compare' => '=',
			);
		} else {
			$branch_clause = array(
				'key'     => 'lafka_selected_branch_id',
				'compare' => 'NOT EXISTS',
			);
		}

		$meta_query = array(
			'relation' => 'AND',
			$branch_clause,
			array(
				'key'     => 'lafka_checkout_date',
				'value'   => $order_date->format( 'Y-m-d' ),
				'compare' => '=',
			),
			array(
				'key'     => 'lafka_checkout_timeslot',
				'value'   => ( $order_timeslot['start'] ?? '' ) . ' - ' . ( $order_timeslot['end'] ?? '' ),
				'compare' => '=',
			),
		);

		// `wc_get_orders()` supports meta_query natively in both HPOS (WC 8.x+
		// OrdersTableQuery) and the CPT data store. `'return' => 'ids'` skips
		// hydrating WC_Order objects we never use.
		$ids = wc_get_orders(
			array(
				'status'     => 'wc-processing',
				'limit'      => -1,
				'return'     => 'ids',
				'meta_query' => $meta_query,
			)
		);

		return count( $ids );
	}
}
