<?php

use Automattic\WooCommerce\Utilities\OrderUtil;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lafka_Shipping_Areas {
	/**
	 * The single instance of the class.
	 */
	protected static $_instance = null;

	private $order_date_time_mandatory;
	private $order_date_time_days_ahead;
	private $order_date_time_timeslot_duration;

	/**
	 * Constructor.
	 */
	protected function __construct() {
		$this->includes();
		$this->init();
	}

	/**
	 * Instance.
	 */
	public static function instance(): ?Lafka_Shipping_Areas {
		if ( is_null( self::$_instance ) ) {
			self::$_instance = new self();
		}

		return self::$_instance;
	}

	/**
	 * Cloning is forbidden.
	 */
	public function __clone() {
		wc_doing_it_wrong( __FUNCTION__, __( 'Cloning is forbidden.', 'lafka-plugin' ), '1.0.0' );
	}

	/**
	 * Unserializing instances of this class is forbidden.
	 */
	public function __wakeup() {
		_doing_it_wrong( __FUNCTION__, __( 'Foul!', 'lafka-plugin' ), '1.0.0' );
	}

	/**
	 * Function for loading dependencies.
	 */
	private function includes() {
		$options = get_option( 'lafka_shipping_areas_branches' );

		require_once dirname( __FILE__ ) . '/includes/class-lafka-shipping-areas-method.php';
		require_once dirname( __FILE__ ) . '/includes/class-lafka-api.php';
		require_once dirname( __FILE__ ) . '/shortcodes/shortcode-lafka-shipping-areas.php';
		if ( ! empty( $options['enable_branch_selection_modal'] ) ) {
			require_once dirname( __FILE__ ) . '/includes/class-lafka-branch-locations.php';
		}

		if ( is_admin() ) {
			require_once dirname( __FILE__ ) . '/includes/class-lafka-shipping-areas-admin.php';
			require_once dirname( __FILE__ ) . '/includes/class-lafka-branch-locations-admin.php';
			if ( function_exists( 'vc_lean_map' ) ) {
				vc_lean_map( 'lafka_shipping_areas', null, dirname( __FILE__ ) . '/shortcodes/shortcode-lafka-shipping-areas-to-vc.php' );
			}
		}
	}

	private function init() {
		// Register Lafka Shipping Areas post type
		add_action( 'init', array( $this, 'register_lafka_shipping_areas' ) );
		add_action( 'init', array( $this, 'register_branch_locations_taxonomy' ) );
		add_action( 'init', array( $this, 'init_order_date_time_options' ), 99 );

		// Scripts and styles
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

		// Shipping Method Register Hooks
		add_action( 'woocommerce_shipping_init', 'lafka_shipping_areas_method_init' );
		// Clear shipping rates cache in order to use dynamic shipping calculations
		add_action( 'woocommerce_shipping_init', array( __CLASS__, 'clear_wc_shipping_rates_cache' ) );
		add_filter( 'woocommerce_shipping_methods', array( $this, 'add_lafka_shipping_areas_method' ) );
		// Apply shipping areas restrictions
		add_action( 'woocommerce_after_get_rates_for_package', array( $this, 'apply_shipping_area_restrictions' ) );
		// Inject delivery address into ajax update_order_review response
		add_action( 'woocommerce_update_order_review_fragments', array( $this, 'update_order_review_fragments' ) );
		// Show picked location in order
		add_action( 'woocommerce_admin_order_data_after_billing_address', array( $this, 'show_checkout_fields_admin_order_meta' ), 10, 1 );
		// Add map to check out
		add_action( 'woocommerce_review_order_before_payment', array( $this, 'add_map_to_checkout' ) );
		// Validate "mandatory to pick address"
		add_action( 'woocommerce_checkout_process', array( $this, 'validate_checkout_field_process' ) );
		// Store picked map location to order
		add_action( 'woocommerce_checkout_update_order_meta', array( $this, 'checkout_update_order_meta' ), 10, 1 );

		$options = get_option( 'lafka_shipping_areas_general' );
		if ( ! empty( $options['lowest_cost_shipping'] ) ) {
			add_action( 'woocommerce_after_shipping_rate', array( $this, 'add_rates_cost_as_hidden' ), 10, 2 );
		}
		if ( ! empty( $options['hide_shipping_cost_at_cart'] ) ) {
			add_filter( 'woocommerce_cart_ready_to_calc_shipping', array( $this, 'disable_shipping_calculation_on_cart' ), 999 );
		}
		$advanced_options = get_option( 'lafka_shipping_areas_advanced' );
		if ( ! empty( $advanced_options['deactivate_post_code'] ) ) {
			add_filter( 'woocommerce_default_address_fields', array( $this, 'disable_postcode_validation' ) );
		}
		if ( ! empty( $advanced_options['disable_state'] ) ) {
			add_filter( 'woocommerce_checkout_fields', array( $this, 'disable_state_field' ) );
		}

		$branches_options = get_option( 'lafka_shipping_areas_branches' );
		if ( ! empty( $branches_options['hide_address_fields'] ) ) {
			add_filter( 'woocommerce_checkout_fields', array( $this, 'disable_address_fields' ) );
		}

		$datetime_options = get_option( 'lafka_shipping_areas_datetime' );
		if ( ! empty( $datetime_options['enable_datetime_option'] ) ) {
			// Add Delivery/Pickup Time to the checkout page
			add_action( 'woocommerce_checkout_before_customer_details', array( $this, 'show_datetime_fields_in_checkout' ) );
			// Retrieve time slots for date
			add_action( 'wp_ajax_time_slots_for_date', array( $this, 'retrieve_time_slots_for_date' ) );
			add_action( 'wp_ajax_nopriv_time_slots_for_date', array( $this, 'retrieve_time_slots_for_date' ) );
			// Save datetime to order
			add_action( 'woocommerce_checkout_update_order_meta', array( $this, 'checkout_datetime_update_order_meta' ), 10, 1 );
			// Show datetime in order list and make it sortable
			// Legacy orders
			add_filter( 'manage_shop_order_posts_columns', array( __CLASS__, 'add_datetime_to_orders_list' ), 20 );
			// HPOS
			add_filter( 'manage_woocommerce_page_wc-orders_columns', array( __CLASS__, 'add_datetime_to_orders_list' ), 20 );
            // Legacy orders
            add_action( 'manage_shop_order_posts_custom_column', array( __CLASS__, 'add_datetime_content_to_orders_list' ), 10, 2 );
			// HPOS
			add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( __CLASS__, 'add_datetime_content_to_orders_list' ), 10, 2 );
			// Legacy orders
            add_action( 'manage_edit-shop_order_sortable_columns', array( __CLASS__, 'add_datetime_to_sortable_columns' ) );
            // HPOS
			add_action( 'woocommerce_shop_order_list_table_sortable_columns', array( __CLASS__, 'add_datetime_to_sortable_columns' ) );
		}

		// Output order type, time for delivery/pickup, branch, picked delivery location in all places
		add_filter( 'woocommerce_get_order_item_totals', array( __CLASS__, 'output_custom_fields_in_thank_you_page' ), 10, 3 );

		// Handle order emails for branch managers
		if ( empty( $branches_options['disable_order_emails'] ) ) {
			foreach ( array( 'cancelled_order', 'failed_order', 'new_order' ) as $email_type_id ) {
				add_filter( 'woocommerce_email_recipient_' . $email_type_id, array( __CLASS__, 'add_recipient_to_order_emails' ), 10, 3 );
			}
		}
	}

	public function register_lafka_shipping_areas() {
		$labels = array(
			'name'               => __( 'Lafka Shipping Areas', 'lafka-plugin' ),
			'singular_name'      => __( 'Lafka Shipping Area', 'lafka-plugin' ),
			'name_admin_bar'     => __( 'Lafka Shipping Area', 'lafka-plugin' ),
			'add_new'            => __( 'Add New', 'lafka-plugin' ),
			'add_new_item'       => __( 'Add New Lafka Shipping Area', 'lafka-plugin' ),
			'new_item'           => __( 'New Lafka Shipping Area', 'lafka-plugin' ),
			'edit_item'          => __( 'Edit Lafka Shipping Area', 'lafka-plugin' ),
			'view_item'          => __( 'View Lafka Shipping Area', 'lafka-plugin' ),
			'all_items'          => __( 'Lafka Shipping Areas', 'lafka-plugin' ),
			'search_items'       => __( 'Search Lafka Shipping Areas', 'lafka-plugin' ),
			'parent_item_colon'  => '',
			'not_found'          => __( 'Nothing found.', 'lafka-plugin' ),
			'not_found_in_trash' => __( 'Nothing found in Trash.', 'lafka-plugin' ),
		);

		$args = array(
			'labels'             => $labels,
			'public'             => true,
			'publicly_queryable' => false,
			'show_ui'            => true,
			'show_in_menu'       => 'woocommerce',
			'query_var'          => true,
			'rewrite'            => false,
			'capability_type'    => 'product',
			'hierarchical'       => false,
			'menu_position'      => null,
			'supports'           => array( 'title', 'author' ),
		);

		register_post_type( 'lafka_shipping_areas', $args );

		add_shortcode( 'lafka_shipping_areas', 'lafka_shipping_areas_shortcode' );
	}

	public function register_branch_locations_taxonomy() {
		$labels = array(
			'name'          => esc_html__( 'Lafka Branch Locations', 'lafka-plugin' ),
			'singular_name' => esc_html__( 'Branch Location', 'lafka-plugin' ),
			'search_items'  => esc_html__( 'Search Branch Location', 'lafka-plugin' ),
			'all_items'     => esc_html__( 'All Branch Location', 'lafka-plugin' ),
			'edit_item'     => esc_html__( 'Edit Branch Location', 'lafka-plugin' ),
			'update_item'   => esc_html__( 'Update Branch Location', 'lafka-plugin' ),
			'add_new_item'  => esc_html__( 'Add New Branch Location', 'lafka-plugin' ),
			'back_to_items' => esc_html__( 'Go to Branch Locations', 'lafka-plugin' ),
			'not_found'     => esc_html__( 'No Branch Locations found', 'lafka-plugin' ),
			'menu_name'     => esc_html__( 'Lafka Branch Locations', 'lafka-plugin' ),
		);
		$args   = array(
			'publicly_queryable' => false,
			'hierarchical'       => false,
			'labels'             => $labels,
			'show_ui'            => true,
			'show_admin_column'  => true,
			'show_in_menu'       => true,
			'show_in_nav_menus'  => false,
			'show_tagcloud'      => false
		);

		register_taxonomy( 'lafka_branch_location', 'product', $args );
	}

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

	public function add_lafka_shipping_areas_method( $methods ) {
		$methods['lafka_shipping_areas_method'] = 'Lafka_Shipping_Areas_Method';

		return $methods;
	}

	public function get_all_delivery_areas(): array {
		$args = array(
			'numberposts' => 100,
			'post_type'   => 'lafka_shipping_areas',
			'post_status' => 'publish',
			'orderby'     => 'title',
		);

		return get_posts( $args );
	}

	public function enqueue_scripts() {
		wp_enqueue_style( 'lafka-shipping-areas-front', plugins_url( 'assets/css/frontend/lafka-shipping-areas-front.css', __FILE__ ), array(), '1.0' );

		if ( is_cart() || is_checkout() ) {
			wp_enqueue_script( 'lafka-shipping-areas-handle-shipping', plugins_url( 'assets/js/frontend/lafka-shipping-areas-handle-shipping.min.js', __FILE__ ), array(
				'jquery',
				'lafka-google-maps',
				'jquery-blockui'
			), '1.0', true );

			$options                 = get_option( 'lafka_shipping_areas_general' );
			$options_advanced        = get_option( 'lafka_shipping_areas_advanced' );
			$branch_location_session = WC()->session->get( 'lafka_branch_location' );

			// Init a properties variable
			wp_add_inline_script( 'lafka-shipping-areas-handle-shipping', '
				const lafka_shipping_properties = {};
				const lafka_no_shipping_methods_string = "' . esc_html__( 'There are no shipping options available. Please ensure that your address has been entered correctly, or contact us if you need any help.', 'lafka-plugin' ) . '";
				const lafka_debug_mode = ' . ( empty( $options_advanced['debug_mode'] ) ? 'false' : 'true' ) . ';
				const lafka_lowest_cost_shipping = ' . ( empty( $options['lowest_cost_shipping'] ) ? 'false' : 'true' ) . ';
				const lafka_store_address = "' . Lafka_Shipping_Areas::get_store_address() . '";
				const lafka_set_store_location = "' . ( empty( $options_advanced['set_store_location'] ) ? 'geo_woo_store' : $options_advanced['set_store_location'] ) . '";
				const lafka_store_map_location = "' . ( empty( $options_advanced['store_map_location'] ) ? '' : $options_advanced['store_map_location'] ) . '";
				const lafka_order_type = "' . ( empty( $branch_location_session['order_type'] ) ? '' : $branch_location_session['order_type'] ) . '";
				', 'before' );
		}

		$datetime_options = get_option( 'lafka_shipping_areas_datetime' );
		if ( is_checkout() && ! empty( $datetime_options['enable_datetime_option'] ) ) {
			if ( class_exists( 'Lafka_Order_Hours' ) && ! empty( Lafka_Order_Hours::$lafka_order_hours_schedule ) ) {
				$enabled_dates = self::get_enabled_dates_for_days_ahead( $this->order_date_time_days_ahead, $this->order_date_time_timeslot_duration );
			} else {
				$enabled_dates = self::get_all_days_ahead( $this->order_date_time_days_ahead );
			}
			$flatpickr_locale = apply_filters( 'lafka_flatpickr_locale', strtok( get_locale(), '_' ), get_locale() );
			wp_enqueue_style( 'flatpickr' );
			wp_enqueue_script( 'flatpickr-local' );
			wp_enqueue_script( 'lafka-shipping-datetime', plugins_url( 'assets/js/frontend/lafka-shipping-datetime.min.js', __FILE__ ), array( 'jquery', 'select2', 'flatpickr' ), '1.0', true );
			wp_localize_script( 'lafka-shipping-datetime', 'lafka_datetime_options', array(
				'is_order_hours_enabled' => class_exists( 'Lafka_Order_Hours' ),
				'days_ahead'             => $this->order_date_time_days_ahead,
				'enabled_dates'          => $enabled_dates,
				'ajax_url'               => admin_url( 'admin-ajax.php' ),
				'nonce'                  => wp_create_nonce( 'time_slots_for_date' ),
				'select_time_label'      => esc_html__( 'Select time...', 'lafka-plugin' ),
				'datetime_mandatory'     => ! empty( $this->order_date_time_mandatory ),
				'flatpickr_locale'       => $flatpickr_locale
			) );
		}
	}

	public function apply_shipping_area_restrictions( $package ) {
		unset( $package['rates'] );
	}

	public function update_order_review_fragments( $fragments ) {
		if ( WC()->customer->has_shipping_address() ) {
			$delivery_address = WC()->customer->get_shipping();
		} else {
			$delivery_address = WC()->customer->get_billing();
		}
		$all_countries                     = WC()->countries->get_countries();
		$delivery_address['country_label'] = $all_countries[ $delivery_address['country'] ] ?? '';
		$fragments['delivery_address']     = $delivery_address;

		return $fragments;
	}

	public function checkout_update_order_meta( $order_id ) {
		if ( ! empty( $_POST['lafka_picked_delivery_geocoded'] ) && ! empty( $_POST['lafka_is_location_clicked'] ) ) {
			if ( OrderUtil::custom_orders_table_usage_is_enabled() ) {
				$order = wc_get_order( $order_id );
                $order->update_meta_data('lafka_picked_delivery_geocoded',  sanitize_text_field( $_POST['lafka_picked_delivery_geocoded'] ));
                $order->save();
			} else {
				update_post_meta( $order_id, 'lafka_picked_delivery_geocoded', sanitize_text_field( $_POST['lafka_picked_delivery_geocoded'] ) );
			}
		}
	}

	public function checkout_datetime_update_order_meta( $order_id ) {
		if ( ! empty( $_POST['lafka_checkout_date'] ) ) {
			if ( OrderUtil::custom_orders_table_usage_is_enabled() ) {
				$order = wc_get_order( $order_id );
				$order->update_meta_data('lafka_checkout_date',  sanitize_text_field( $_POST['lafka_checkout_date'] ));
				$order->save();
			} else {
				update_post_meta( $order_id, 'lafka_checkout_date', sanitize_text_field( $_POST['lafka_checkout_date'] ) );
			}
		} elseif ( ! empty( $this->order_date_time_mandatory ) ) {
			wc_add_notice( esc_html__( 'Please enter Delivery/Pickup time.', 'lafka-plugin' ), 'error' );
		}
		if ( ! empty( $_POST['lafka_checkout_timeslot'] ) ) {
			if ( OrderUtil::custom_orders_table_usage_is_enabled() ) {
				$order = wc_get_order( $order_id );
				$order->update_meta_data('lafka_checkout_timeslot',  sanitize_text_field( $_POST['lafka_checkout_timeslot'] ));
				$order->save();
			} else {
				update_post_meta( $order_id, 'lafka_checkout_timeslot', sanitize_text_field( $_POST['lafka_checkout_timeslot'] ) );
			}
		} elseif ( ! empty( $this->order_date_time_mandatory ) ) {
			wc_add_notice( esc_html__( 'Please enter Delivery/Pickup time.', 'lafka-plugin' ), 'error' );
		}
	}

	public static function add_datetime_to_orders_list( $columns ): array {
		$columns['lafka_datetime_complete'] = esc_html__( 'Delivery/Pickup Time', 'lafka-plugin' );

		return $columns;
	}

	public static function add_datetime_content_to_orders_list( $column, $order ) {
        $order = wc_get_order($order);

		if ( $column === 'lafka_datetime_complete' ) {
			$date     = self::get_order_meta_backward_compatible( $order->get_id(), 'lafka_checkout_date' );
			$timeslot = self::get_order_meta_backward_compatible( $order->get_id(), 'lafka_checkout_timeslot' );

			if ( ! empty( $date ) ) {
				echo '<span class="lafka-delivery-date">' . esc_html( $date ) . '</span>';
			}
			if ( ! empty( $timeslot ) ) {
				echo '<span class="lafka-delivery-timeslot">' . esc_html( $timeslot ) . '</span>';
			}
		}
	}

	public static function add_recipient_to_order_emails( $recipient, $object, $wc_email_object ): string {
		if ( $object instanceof \Automattic\WooCommerce\Admin\Overrides\Order ) {
			$recipients      = array_map( 'trim', explode( ',', $recipient ) );
			$order_branch_id = self::get_order_meta_backward_compatible( $object->get_id(), 'lafka_selected_branch_id' );
			if ( ! empty( $order_branch_id ) ) {
				$branch_user_id   = get_term_meta( $order_branch_id, 'lafka_branch_user', true );
				$branch_user_data = get_userdata( $branch_user_id );
				if ( $branch_user_data && ! empty( $branch_user_data->user_email ) ) {
					$recipients[] = $branch_user_data->user_email;
				}
			}

			return implode( ',', $recipients );
		}

		return $recipient;
	}

	public static function add_datetime_to_sortable_columns( $columns ): array {
		$columns['lafka_datetime_complete'] = 'lafka_checkout_date';

		return $columns;
	}

	public static function output_custom_fields_in_thank_you_page( $total_rows, $order, $tax_display ) {
		$order_type                     = self::get_order_meta_backward_compatible( $order->get_id(), 'lafka_order_type' );
		$lafka_checkout_date            = self::get_order_meta_backward_compatible( $order->get_id(), 'lafka_checkout_date' );
		$lafka_checkout_timeslot        = self::get_order_meta_backward_compatible( $order->get_id(), 'lafka_checkout_timeslot' );
		$lafka_selected_branch_id       = self::get_order_meta_backward_compatible( $order->get_id(), 'lafka_selected_branch_id' );
		$lafka_picked_delivery_geocoded = self::get_order_meta_backward_compatible( $order->get_id(), 'lafka_picked_delivery_geocoded' );

		$order_type_label = esc_html__( 'Delivery', 'lafka-plugin' );
		if ( ! empty( $order_type ) ) {
			if ( $order_type === 'pickup' ) {
				$order_type_label = esc_html__( 'Pickup', 'lafka-plugin' );
			}
			$total_rows['lafka_order_type'] = array(
				'label' => esc_html__( 'Order Type:', 'lafka-plugin' ),
				'value' => $order_type_label,
			);
		}
		if ( ! empty( $lafka_checkout_date ) ) {
			$total_rows['lafka_checkout_date'] = array(
				'label' => esc_html( $order_type_label ) . ' ' . esc_html__( 'Date:', 'lafka-plugin' ),
				'value' => date_i18n( get_option( 'date_format' ), DateTime::createFromFormat( 'Y-m-d', $lafka_checkout_date )->getTimestamp() )
			);
		}
		if ( ! empty( $lafka_checkout_timeslot ) ) {
			$total_rows['lafka_checkout_timeslot'] = array(
				'label' => esc_html( $order_type_label ) . ' ' . esc_html__( 'Time:', 'lafka-plugin' ),
				'value' => esc_html( $lafka_checkout_timeslot )
			);
		}
		if ( ! empty( $lafka_selected_branch_id ) ) {
			$branch_location = get_term( $lafka_selected_branch_id, 'lafka_branch_location' );
			if ( ! empty( $branch_location ) ) {
				$total_rows['lafka_selected_branch_id'] = array(
					'label' => esc_html__( 'Branch:', 'lafka-plugin' ),
					'value' => esc_html( $branch_location->name )
				);
			}
		}
		if ( ! empty( $lafka_picked_delivery_geocoded ) && is_string( $lafka_picked_delivery_geocoded ) ) {
			$location = json_decode( $lafka_picked_delivery_geocoded );
			if ( $location !== null && isset( $location->lat ) ) {
				$total_rows['lafka_picked_delivery_geocoded'] = array(
					'label' => esc_html__( 'Picked Delivery Location:', 'lafka-plugin' ),
					'value' => self::get_delivery_location_link( $location )
				);
			}
		}

		return $total_rows;
	}

	public static function clear_wc_shipping_rates_cache() {
		if ( isset( WC()->cart ) ) {
			$packages = WC()->cart->get_shipping_packages();
			foreach ( $packages as $package_key => $package ) {
				WC()->session->set( 'shipping_for_package_' . $package_key, false ); // Or true
			}
		}
	}

	public static function get_enabled_dates_for_days_ahead( $days_ahead, $timeslot_duration ): array {
		$current_time  = Lafka_Order_Hours::get_order_hours_time();
		$interval      = DateInterval::createFromDateString( '1 day' );
		$enabled_dates = array();

		if ( ! empty( Lafka_Order_Hours::$lafka_order_hours_schedule ) ) {
			$schedule_json  = Lafka_Order_Hours::$lafka_order_hours_schedule;
			$schedule_array = json_decode( $schedule_json );

			for ( $i = 0; $i <= $days_ahead; $i ++ ) {
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
				usort( $day_periods, function ( $a, $b ) {
					return strcmp( $a->start, $b->start );
				} );
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
				'title'    => ( $option_title ? $time_period['start'] . ' - ' . $time_period['end'] . ' ' . $option_title : '' )
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
				usort( $day_periods, function ( $a, $b ) {
					return strcmp( $a->start, $b->start );
				} );
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

	public function show_checkout_fields_admin_order_meta( $order ) {
		echo '<div class="address">';

		$order_type                     = self::get_order_meta_backward_compatible( $order->get_id(), 'lafka_order_type' );
		$lafka_checkout_date            = self::get_order_meta_backward_compatible( $order->get_id(), 'lafka_checkout_date' );
		$lafka_checkout_timeslot        = self::get_order_meta_backward_compatible( $order->get_id(), 'lafka_checkout_timeslot' );
		$lafka_selected_branch_id       = self::get_order_meta_backward_compatible( $order->get_id(), 'lafka_selected_branch_id' );
		$lafka_picked_delivery_geocoded = self::get_order_meta_backward_compatible( $order->get_id(), 'lafka_picked_delivery_geocoded' );

		$order_type_label = esc_html__( 'Delivery', 'lafka-plugin' );
		if ( ! empty( $order_type ) ) {
			if ( $order_type === 'pickup' ) {
				$order_type_label = esc_html__( 'Pickup', 'lafka-plugin' );
			}
			?>
            <p>
                <strong><?php esc_html_e( 'Order Type', 'lafka-plugin' ); ?>:</strong>
				<?php echo esc_html( $order_type_label ); ?>
            </p>
			<?php
		}
		if ( ! empty( $lafka_checkout_date ) ) {
			?>
            <p>
                <strong><?php echo esc_html( $order_type_label ) . ' ' . esc_html__( 'Date', 'lafka-plugin' ); ?>:</strong>
				<?php echo esc_html( date_i18n( get_option( 'date_format' ), DateTime::createFromFormat( 'Y-m-d', $lafka_checkout_date )->getTimestamp() ) ); ?>
            </p>
			<?php
		}
		if ( ! empty( $lafka_checkout_timeslot ) ) {
			?>
            <p>
                <strong><?php echo esc_html( $order_type_label ) . ' ' . esc_html__( 'Time', 'lafka-plugin' ); ?>:</strong>
				<?php echo esc_html( $lafka_checkout_timeslot ); ?>
            </p>
			<?php
		}
		if ( ! empty( $lafka_selected_branch_id ) ) {
			$branch_location = get_term( $lafka_selected_branch_id, 'lafka_branch_location' );
			if ( ! empty( $branch_location ) ) {
				?>
                <p>
                    <strong><?php esc_html_e( 'Branch', 'lafka-plugin' ); ?>:</strong>
					<?php echo esc_html( $branch_location->name ); ?>
                </p>
				<?php
			}
		}
		if ( ! empty( $lafka_picked_delivery_geocoded ) && is_string( $lafka_picked_delivery_geocoded ) ) {
			$location = json_decode( $lafka_picked_delivery_geocoded );
			if ( $location !== null && isset( $location->lat ) ) {
				?>
                <p>
                    <strong><?php esc_html_e( 'Picked Delivery Location', 'lafka-plugin' ); ?>:</strong>
					<?php echo self::get_delivery_location_link( $location ); ?>
                </p>
				<?php
			}
		}
		echo '</div>';
	}

	public function add_map_to_checkout() {
		if ( ! is_checkout() || WC()->cart->needs_shipping() === false ) {
			return;
		}

		$options = get_option( 'lafka_shipping_areas_general' );
		if ( empty( $options['pick_delivery_address'] ) ) {
			return;
		}

		if ( empty( $options['mandatory_pickup_delivery'] ) ) {
			$title = esc_html__( 'Address not found! You can pinpoint Your Location on the map.', 'lafka-plugin' );
		} else {
			$title = esc_html__( 'Address not found! Pinpoint Your Location on the map to Continue', 'lafka-plugin' );
		}

		echo '<div id="lafka_pick_delivery_address_field" class="shop_table">';
		echo '<h3 class="lafka-address-not-found">' . esc_html( $title ) . '</h3>';
		echo '<h3 class="lafka-address-marked">' . esc_html__( 'Pinpoint your location on the map if it\'s not accurately marked.', 'lafka-plugin' ) . '</h3>';

		woocommerce_form_field( 'lafka_picked_delivery_geocoded', array(
			'type'  => 'text',
			'class' => array(
				'hidden'
			),
			'label' => esc_html__( 'Please Precise Your Location', 'lafka-plugin' )
		) );
		woocommerce_form_field( 'lafka_is_location_clicked', array(
			'type'  => 'text',
			'class' => array(
				'hidden'
			)
		) );

		echo '<div id="lafka-pick-delivery-address-content">';
		echo '<div id="lafka-pick-delivery-address-checkout-map">';
		echo '</div></div></div>';

		wp_add_inline_script( 'lafka-shipping-areas-handle-shipping', 'lafka_checkout_map_properties = ' . json_encode( array(
				'pick_delivery_address_option' => $options['pick_delivery_address'],
			) ), 'before' );
	}

	public function validate_checkout_field_process() {
		$options = get_option( 'lafka_shipping_areas_general' );

		if ( ! empty( $options['pick_delivery_address'] ) && ! empty( $options['mandatory_pickup_delivery'] ) && isset( $_POST['lafka_picked_delivery_geocoded'] ) && empty( $_POST['lafka_picked_delivery_geocoded'] ) ) {
			wc_add_notice( esc_html__( 'Please precise your address on the map.', 'lafka-plugin' ), 'error' );
		}
	}

	public function add_rates_cost_as_hidden( $method, $index ) {
		printf( '<input type="hidden" name="shipping_method[%1$d]_lafka_cost" data-index="%1$d" id="shipping_method_%1$d_%2$s_lafka_cost" value="%3$s" class="lafka-shipping-cost %4$s" />', $index, esc_attr( sanitize_title( $method->id ) ), esc_attr( $method->get_cost() ), sanitize_html_class( $method->get_method_id() ) ); // WPCS: XSS ok.
	}

	public function disable_shipping_calculation_on_cart( $show_shipping ) {
		if ( is_cart() ) {
			return false;
		}

		return $show_shipping;
	}

	public function disable_postcode_validation( $fields ): array {
		$fields['postcode']['required'] = false;

		return $fields;
	}

	public function disable_state_field( $fields ): array {
		unset( $fields['billing']['billing_state'] );
		unset( $fields['shipping']['shipping_state'] );

		return $fields;
	}

	public function disable_address_fields( $fields ): array {
		if ( isset( WC()->session ) ) {
			$branch_location_session = WC()->session->get( 'lafka_branch_location' );
			if ( ! empty( $branch_location_session ) && $branch_location_session['order_type'] === 'pickup' ) {
				unset( $fields['billing']['billing_state'] );
				unset( $fields['shipping']['shipping_state'] );
				unset( $fields['billing']['billing_address_1'] );
				unset( $fields['shipping']['shipping_address_1'] );
				unset( $fields['billing']['billing_address_2'] );
				unset( $fields['shipping']['shipping_address_2'] );
				unset( $fields['billing']['billing_city'] );
				unset( $fields['shipping']['shipping_city'] );
				unset( $fields['billing']['billing_state'] );
				unset( $fields['shipping']['shipping_state'] );
				unset( $fields['billing']['billing_postcode'] );
				unset( $fields['shipping']['shipping_postcode'] );
			}
		}

		return $fields;
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
					<?php if ( empty( $this->order_date_time_mandatory ) ): ?>
						<?php esc_html_e( '(optional)', 'lafka-plugin' ); ?>
					<?php else: ?>
                        <abbr class="required" title="<?php echo esc_html__( 'required', 'lafka-plugin' ); ?>">*</abbr>
					<?php endif; ?>
                </a>
            </div>
            <div class="lafka-checkout-datetime-fields<?php if ( empty( $this->order_date_time_mandatory ) ): ?> hidden<?php endif; ?>">
                <input name="lafka_checkout_date" id="lafka_checkout_date" <?php if ( $this->order_date_time_days_ahead < 1 ): ?>class="hidden"<?php endif; ?> type="text"
                       placeholder="<?php esc_html_e( 'Select Date', 'lafka-plugin' ); ?>..">
                <select name="lafka_checkout_timeslot" id="lafka_checkout_timeslot">
					<?php if ( empty( $this->order_date_time_mandatory ) ): ?>
                        <option></option>
					<?php endif; ?>
                </select>
				<?php if ( $this->order_date_time_days_ahead > 0 && empty( $this->order_date_time_mandatory ) ): ?>
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

		for ( $i = 1; $i <= $days_ahead; $i ++ ) {
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
					'end'   => $end
				);
			}
		}

		return $timeslots;
	}

	private static function get_number_of_orders_per_timeslot( $branch_id, DateTime $order_date, $order_timeslot ): int {
		$meta_query_args = array();
		if ( is_numeric( $branch_id ) ) {
			$meta_query_args[] = array(
				'key'   => 'lafka_selected_branch_id',
				'value' => $branch_id
			);
		} else {
			$meta_query_args[] = array(
				'key'   => 'lafka_selected_branch_id',
				'value' => null
			);
		}

		$meta_query_args[] = array(
			'key'   => 'lafka_checkout_date',
			'value' => $order_date->format( 'Y-m-d' )
		);
		$meta_query_args[] = array(
			'key'   => 'lafka_checkout_timeslot',
			'value' => ( $order_timeslot['start'] ?? '' ) . ' - ' . ( $order_timeslot['end'] ?? '' )
		);
        if ( OrderUtil::custom_orders_table_usage_is_enabled() ) {
			$args   = array(
				'status'     => 'wc-processing',
				'limit'      => - 1,
				'meta_query' => $meta_query_args
			);
			$orders = wc_get_orders( $args );
			$order_count = count($orders);
		} else {
			$query_args        = [
				'post_type'      => 'shop_order',
				'post_status'    => 'wc-processing',
				'posts_per_page' => - 1,
				'meta_query'     => [
					$meta_query_args
				],
			];
			$query             = new WP_Query( $query_args );
            $order_count = $query->post_count;;
		}

		return $order_count;
	}

	private static function get_delivery_location_link( $location ): string {
		$lat  = $location->lat;
		$long = $location->lng;

		return '<a target="_blank" href="https://www.google.com/maps/search/?api=1&query=' . $lat . ',' . $long . '" >' . esc_html__( 'Open delivery location with Google Maps', 'lafka-plugin' ) . '</a>';
	}

	public static function get_store_address(): string {
		$store_address     = get_option( 'woocommerce_store_address', '' );
		$store_address_2   = get_option( 'woocommerce_store_address_2', '' );
		$store_city        = get_option( 'woocommerce_store_city', '' );
		$store_postcode    = get_option( 'woocommerce_store_postcode', '' );
		$store_raw_country = get_option( 'woocommerce_default_country', '' );
		$split_country     = explode( ":", $store_raw_country );
		// Country and state
		$store_country = $split_country[0];
		// Convert country code to full name if available
		if ( isset( WC()->countries->countries[ $store_country ] ) ) {
			$store_country = WC()->countries->countries[ $store_country ];
		}
		$store_state = $split_country[1] ?? '';

		return $store_address . ' ' . $store_address_2 . ' ' . $store_postcode . ' ' . $store_city . ' ' . $store_state . ' ' . $store_country;
	}

	public static function get_all_legit_branch_locations(): array {
		$args = array(
			'taxonomy'   => 'lafka_branch_location',
			'hide_empty' => false,
			'fields'     => 'id=>name',
			'meta_query' => array(
				array(
					'key'     => 'lafka_branch_address',
					'value'   => '',
					'compare' => '!='
				),
				array(
					'key'     => 'lafka_branch_address_geocoded',
					'value'   => '',
					'compare' => '!='
				)
			)
		);

		$all_legit_branch_locations = get_terms( $args );

		if ( is_array( $all_legit_branch_locations ) ) {
			return $all_legit_branch_locations;
		} else {
			return array();
		}
	}

	public static function get_order_meta_backward_compatible( $order_id, $meta_field_key ) {
		if ( OrderUtil::custom_orders_table_usage_is_enabled() ) {
			$return_value = wc_get_order( $order_id )->get_meta( $meta_field_key );
		} else {
			$return_value = get_post_meta( $order_id, $meta_field_key, true );
		}

		return $return_value;
	}
}

/**
 * Function for delaying initialization of the extension until after WooCommerce is loaded.
 */
function lafka_shipping_areas_initialize() {

	// This is also a great place to check for the existence of the WooCommerce class
	if ( ! class_exists( 'WooCommerce' ) ) {
		return;
	}

	$GLOBALS['lafka_shipping_areas'] = Lafka_Shipping_Areas::instance();
}

add_action( 'plugins_loaded', 'lafka_shipping_areas_initialize', 10 );