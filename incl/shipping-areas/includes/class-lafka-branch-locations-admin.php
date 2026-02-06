<?php
defined( 'ABSPATH' ) || exit;

class Lafka_Branch_Locations_Admin {
	/**
	 * Setup Admin class.
	 */
	public static function init() {
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_enqueue_scripts' ) );
		add_action( 'lafka_branch_location_add_form_fields', array( __CLASS__, 'add_branch_location_fields' ), 11, 2 );
		add_action( 'lafka_branch_location_edit_form_fields', array( __CLASS__, 'edit_branch_location_fields' ), 11, 2 );
		add_action( 'created_lafka_branch_location', array( __CLASS__, 'edit_branch_location' ), 10, 4 );
		add_action( 'edit_lafka_branch_location', array( __CLASS__, 'edit_branch_location' ), 10, 4 );
		// Show Address field in the taxonomy terms list screens in admin
		add_filter( 'manage_edit-lafka_branch_location_columns', array( __CLASS__, 'manage_columns_on_location_branches' ), 2 );
		add_filter( 'manage_lafka_branch_location_custom_column', array( __CLASS__, 'manage_column_content_on_location_branches' ), 10, 3 );
		// Show Branch location field in the orders list screens in admin
		// Legacy orders
		add_filter( 'manage_shop_order_posts_columns', array( __CLASS__, 'add_columns_to_orders_list' ), 20 );
		// HPOS
		add_filter( 'manage_woocommerce_page_wc-orders_columns', array( __CLASS__, 'add_columns_to_orders_list' ), 20 );
		// Legacy orders
		add_action( 'manage_shop_order_posts_custom_column', array( __CLASS__, 'add_columns_content_to_orders_list' ), 10, 2 );
		// HPOS
		add_action( 'manage_woocommerce_page_wc-orders_custom_column', array( __CLASS__, 'add_columns_content_to_orders_list' ), 10, 2 );
		// Legacy orders
		add_action( 'manage_edit-shop_order_sortable_columns', array( __CLASS__, 'add_columns_to_sortable_columns' ) );
		// HPOS
		add_action( 'woocommerce_shop_order_list_table_sortable_columns', array( __CLASS__, 'add_columns_to_sortable_columns' ) );
		// Legacy orders
		add_action( 'pre_get_posts', array( __CLASS__, 'orders_list_define_sort_and_search_queries_for_custom_fields' ) );
		// HPOS
		add_action( 'woocommerce_order_query_args', array( __CLASS__, 'orders_list_define_sort_and_search_queries_for_custom_fields_hpos' ) );
		// Legacy orders
		add_action( 'restrict_manage_posts', array( __CLASS__, 'add_fields_to_orders_list_filter' ), 10, 2 );
		// HPOS
		add_action( 'woocommerce_order_list_table_restrict_manage_orders', array( __CLASS__, 'add_fields_to_orders_list_filter' ), 10, 2 );
		// TODO: HPOS ???
		add_filter( 'woocommerce_menu_order_count', array( __CLASS__, 'menu_order_count_for_user' ) );
	}

	public static function admin_enqueue_scripts() {
		wp_enqueue_media();
		wp_enqueue_style( 'lafka-schedule' );
		wp_enqueue_style( 'flatpickr' );
		wp_enqueue_script(
			'lafka-branch-locations-admin',
			plugins_url( '../assets/js/backend/lafka-branch-locations-admin.min.js', __FILE__ ),
			array(
				'jquery',
				'lafka-google-maps',
				'select2',
				'lafka-schedule',
				'flatpickr',
			),
			'1.0',
			true
		);
		$options_branches = get_option( 'lafka_shipping_areas_branches' );
		wp_localize_script(
			'lafka-branch-locations-admin',
			'lafka_branch_location_properties',
			array(
				'geocode_label'         => esc_html__( 'Geocode', 'lafka-plugin' ),
				'clear_label'           => esc_html__( 'Clear', 'lafka-plugin' ),
				'geocode_error'         => esc_html__( 'Geocode was not successful for the following reason', 'lafka-plugin' ),
				'geocode_approximate'   => esc_html__( 'The entered address leads to approximate result. Please precise the address or pick from the map.', 'lafka-plugin' ),
				'choose_image_label'    => esc_html__( 'Choose an image', 'lafka-plugin' ),
				'use_image_label'       => esc_html__( 'Use image', 'lafka-plugin' ),
				'placeholder_image_src' => esc_url( wc_placeholder_img_src() ),
				'products_by_branches'  => ! empty( $options_branches['products_by_branches'] ),
			)
		);
	}

	public static function add_branch_location_fields() {
		global /** @var Lafka_Shipping_Areas $lafka_shipping_areas */
		$lafka_shipping_areas;

		$delivery_areas = array();
		foreach ( $lafka_shipping_areas->get_all_delivery_areas() as $area ) {
			$delivery_areas[ $area->ID ] = get_the_title( $area->ID );
		}

		$manage_woocommerce_users = get_users( array( 'capability' => array( 'manage_woocommerce' ) ) );
		?>
		<div>
			<label for="lafka_branch_user"><?php esc_html_e( 'Branch Manager', 'lafka-plugin' ); ?></label>
			<select class="lafka-select2" name="lafka_branch_user" id="lafka_branch_user" data-allow-clear="true"
					data-placeholder="<?php esc_attr_e( 'Choose Branch Manager&hellip;', 'lafka-plugin' ); ?>" aria-label="<?php esc_attr_e( 'Branch Manager', 'lafka-plugin' ); ?>">
				<?php /** @var WP_User $user */ ?>
				<option value=""><?php esc_attr_e( 'Choose Branch Manager&hellip;', 'lafka-plugin' ); ?></option>
				<?php foreach ( $manage_woocommerce_users as $user ) : ?>
					<option value="<?php echo esc_attr( $user->ID ); ?>"><?php echo esc_html( $user->user_nicename ); ?></option>
				<?php endforeach; ?>
			</select>
			<p><?php esc_html_e( 'Selected user will see only the orders for this or any other assigned to him branches. The user will also get new orders push and email notifications only for his branches.', 'lafka-plugin' ); ?></p>
		</div>
		<div>
			<label for="lafka_branch_order_type"><?php esc_html_e( 'Order Type', 'lafka-plugin' ); ?></label>
			<select class="lafka-select2" name="lafka_branch_order_type" id="lafka_branch_order_type" data-allow-clear="true" aria-label="<?php esc_attr_e( 'Order Type', 'lafka-plugin' ); ?>">
				<option value="delivery_pickup"><?php esc_html_e( 'Delivery and Pickup', 'lafka-plugin' ); ?></option>
				<option value="delivery"><?php esc_html_e( 'Only Delivery', 'lafka-plugin' ); ?></option>
				<option value="pickup"><?php esc_html_e( 'Only Pickup', 'lafka-plugin' ); ?></option>
			</select>
			<p><?php esc_html_e( 'Specify which order types will be accepted by the branch.', 'lafka-plugin' ); ?></p>
		</div>
		<div>
			<label for="lafka_branch_delivery_time"><?php esc_html_e( 'Estimated Delivery/Prep Time', 'lafka-plugin' ); ?></label>
			<input name="lafka_branch_delivery_time" id="lafka_branch_delivery_time" type="text"/>
			<p><?php esc_html_e( 'Enter the average delivery/prep time for this branch. Will be displayed in the branch info box.', 'lafka-plugin' ); ?></p>
		</div>
		<div class="form-field">
			<label><?php echo esc_html__( 'Branch Location Image', 'lafka-plugin' ); ?></label>
			<div id="lafka_branch_location_img" style="float: left; margin-right: 10px;"><img
						src="<?php echo esc_url( wc_placeholder_img_src() ); ?>" width="60px" height="60px"/></div>
			<div style="line-height: 60px;">
				<input type="hidden" id="lafka_branch_location_img_id" name="lafka_branch_location_img_id"/>
				<button type="button"
						class="lafka_branch_location_img_upload_image_button button"><?php echo esc_html__( 'Upload/Add image', 'lafka-plugin' ); ?></button>
				<button type="button"
						class="lafka_branch_location_img_remove_image_button button"><?php echo esc_html__( 'Remove image', 'lafka-plugin' ); ?></button>
			</div>
			<div class="clear"></div>
			<p><?php esc_html_e( 'The image will be shown on the popup for selecting the branch.', 'lafka-plugin' ); ?></p>
		</div>
		<div>
			<label for="lafka_branch_shipping_areas"><?php esc_html_e( 'Shipping Areas', 'lafka-plugin' ); ?></label>
			<select multiple="multiple"
					name="lafka_branch_shipping_areas[]" id="lafka_branch_shipping_areas"
					data-placeholder="<?php esc_attr_e( 'Choose Shipping Areas&hellip;', 'lafka-plugin' ); ?>"
					aria-label="<?php esc_attr_e( 'Shipping Areas', 'lafka-plugin' ); ?>"
					class="lafka-admin-select2"
			>
				<?php foreach ( $delivery_areas as $option_key => $option_value ) : ?>
					<option value="<?php echo esc_attr( $option_key ); ?>"><?php echo esc_html( $option_value ); ?></option>
				<?php endforeach; ?>
			</select>
			<p><?php esc_html_e( 'Choose Lafka Shipping Areas covered by this branch.', 'lafka-plugin' ); ?></p>
		</div>
		<div>
			<label for="lafka_branch_distance_restriction"><?php esc_html_e( 'Radius Restriction', 'lafka-plugin' ); ?></label>
			<input name="lafka_branch_distance_restriction" id="lafka_branch_distance_restriction" type="number"/>
			<p><?php esc_html_e( 'Suitable for radius based delivery restrictions. This option is only used for the initial check (popup) of the delivery availability based on the user address.', 'lafka-plugin' ); ?></p>
		</div>
		<div>
			<label for="lafka_branch_distance_unit"><?php esc_html_e( 'Distance Unit', 'lafka-plugin' ); ?></label>
			<select class="select wc-enhanced-select" name="lafka_branch_distance_unit" id="lafka_branch_distance_unit" style="">
				<option value="metric"><?php esc_html_e( 'Metric (km)', 'lafka-plugin' ); ?></option>
				<option value="imperial"><?php esc_html_e( 'Imperial (miles)', 'lafka-plugin' ); ?></option>
			</select>
		</div>
		<div class="form-field form-required lafka_branch_address-wrap">
			<label for="lafka_branch_address"><?php esc_html_e( 'Address', 'lafka-plugin' ); ?></label>
			<input name="lafka_branch_address" id="lafka_branch_address" placeholder="<?php esc_html_e( 'Enter a location', 'lafka-plugin' ); ?>" type="text"/>
			<input name="lafka_branch_address_geocoded" id="lafka_branch_address_geocoded" type="hidden"/>
			<p class="description">
				<?php esc_html_e( 'The full address for the branch to calculate distance shipping and apply radius restrictions.', 'lafka-plugin' ); ?>
				<?php esc_html_e( 'Note: Branch Location will be active only if it is properly geocoded.', 'lafka-plugin' ); ?><br>
				<span class="lafka-branch-address-instructions">
					<strong><?php esc_html_e( 'Instructions', 'lafka-plugin' ); ?></strong>: <?php esc_html_e( 'Enter an address in the textbox and geocode or click on the map to set the location.', 'lafka-plugin' ); ?>
				</span>
			</p>
		</div>
		<div id="lafka_geocode_branch_location_map"></div>
		<?php $datetime_options = get_option( 'lafka_shipping_areas_datetime' ); ?>
		<?php if ( ! empty( $datetime_options['enable_datetime_option'] ) ) : ?>
			<h3><?php esc_html_e( 'Branch Specific Delivery/Pickup Date Time Settings', 'lafka-plugin' ); ?></h3>
			<div>
				<label for="lafka_branch_override_datetime_global">
					<input type="checkbox" name="lafka_branch_override_datetime_global" id="lafka_branch_override_datetime_global"/>
					<?php esc_html_e( 'Override Global Delivery/Pickup Date Time Settings', 'lafka-plugin' ); ?>
				</label>
			</div>
			<div>
				<label for="lafka_branch_datetime_mandatory">
					<input type="checkbox" name="lafka_branch_datetime_mandatory" id="lafka_branch_datetime_mandatory"/>
					<?php esc_html_e( 'Make Delivery/Pickup date and time fields mandatory', 'lafka-plugin' ); ?>
				</label>
			</div>
			<div>
				<label for="lafka_branch_datetime_days_ahead"><?php esc_html_e( 'Number of days ahead to order', 'lafka-plugin' ); ?></label>
				<input name="lafka_branch_datetime_days_ahead" id="lafka_branch_datetime_days_ahead" value="30" type="number"/>
				<p><?php esc_html_e( 'Enter for how many days ahead user can make an order. The following days will be disabled in the calendar on the checkout page.', 'lafka-plugin' ); ?></p>
			</div>
			<div>
				<label for="lafka_branch_datetime_timeslot_duration"><?php esc_html_e( 'Time Slot Duration', 'lafka-plugin' ); ?></label>
				<input name="lafka_branch_datetime_timeslot_duration" id="lafka_branch_datetime_timeslot_duration" type="number" value="60"/> <?php esc_html_e( 'Minutes', 'lafka-plugin' ); ?>
				<p><?php esc_html_e( 'Enter the time in minutes for the slots in which the user can request the order to be completed.', 'lafka-plugin' ); ?></p>
			</div>
			<div>
				<label for="lafka_branch_datetime_orders_per_timeslot"><?php esc_html_e( 'Orders Per Time Slot', 'lafka-plugin' ); ?></label>
				<input name="lafka_branch_datetime_orders_per_timeslot" id="lafka_branch_datetime_orders_per_timeslot" type="number" value=""/>
				<p><?php esc_html_e( 'Enter the number of orders which can be made in one time slot. If the number is reached for particular time slot, the users will still be able to see it, but it will be disabled.', 'lafka-plugin' ); ?></p>
			</div>
		<?php endif; ?>
		<?php if ( is_lafka_order_hours( get_option( 'lafka' ) ) ) : ?>
			<h3><?php esc_html_e( 'Branch Specific Order Hours Settings', 'lafka-plugin' ); ?></h3>
			<div>
				<label for="lafka_branch_override_order_hours_global">
					<input type="checkbox" name="lafka_branch_override_order_hours_global" id="lafka_branch_override_order_hours_global"/>
					<?php esc_html_e( 'Override Global Order Hours Settings', 'lafka-plugin' ); ?>
				</label>
			</div>
			<div>
				<?php
				$timezones         = DateTimeZone::listIdentifiers( DateTimeZone::ALL );
				$timezones_options = array( 'default' => esc_html__( 'Use WordPress default', 'lafka-plugin' ) );
				$timezones_options = array_merge( $timezones_options, array_combine( $timezones, $timezones ) );
				?>
				<label for="lafka_branch_timezone"><?php esc_html_e( 'Time Zone', 'lafka-plugin' ); ?></label>
				<select class="lafka-select2" name="lafka_branch_timezone" id="lafka_branch_timezone">
					<?php foreach ( $timezones_options as $key => $value ) : ?>
						<option value="<?php echo esc_attr( $key ); ?>">
							<?php esc_html_e( $value ); ?>
						</option>
					<?php endforeach; ?>
				</select>
			</div>
			<h2><?php esc_html_e( 'Force Override Store Schedule', 'lafka-plugin' ); ?></h2>
			<div>
				<label for="lafka_branch_order_hours_force_override_check">
					<input type="checkbox" name="lafka_branch_order_hours_force_override_check" id="lafka_branch_order_hours_force_override_check"/>
					<?php esc_html_e( 'Override current working time schedule and change the store status', 'lafka-plugin' ); ?>
				</label>
			</div>
			<div>
				<label for="lafka_branch_order_hours_force_override_status"><?php esc_html_e( 'Ordering Status', 'lafka-plugin' ); ?></label>
				<select class="select" name="lafka_branch_order_hours_force_override_status" id="lafka_branch_order_hours_force_override_status">
					<option value=""><?php esc_html_e( 'Disabled', 'lafka-plugin' ); ?></option>
					<option value="1"><?php esc_html_e( 'Enabled', 'lafka-plugin' ); ?></option>
				</select>
			</div>
			<h2><?php esc_html_e( 'Days Schedule', 'lafka-plugin' ); ?></h2>
			<div>
				<label for="lafka_branch_order_hours_schedule"><?php esc_html_e( 'Open Hours Periods', 'lafka-plugin' ); ?></label>
				<input name="lafka_branch_order_hours_schedule" id="lafka_branch_order_hours_schedule" type="hidden" readonly="readonly"/>
				<p class="description"><?php esc_html_e( 'Drag over the table to create order hours periods. Use X and Copy icons to delete or duplicate periods.', 'lafka-plugin' ); ?></p>
			</div>
			<div id="lafka_branch_order_hours_container"></div>
			<h2><?php esc_html_e( 'Holidays Schedule', 'lafka-plugin' ); ?></h2>
			<div>
				<label for="lafka_branch_order_hours_holidays_calendar"><?php esc_html_e( 'Holidays Calendar', 'lafka-plugin' ); ?></label>
				<input id="lafka_branch_order_hours_holidays_calendar"
						name="lafka_branch_order_hours_holidays_calendar"
						type="text"
						readonly="readonly"
				>
				<p class="description"><?php esc_html_e( 'Click on Text Box to Open Calendar and Select Your Holidays', 'lafka-plugin' ); ?></p>
			</div>
			<br>
		<?php endif; ?>
		<?php
	}

	public static function edit_branch_location_fields( $term ) {
		$branch_user_id                    = get_term_meta( $term->term_id, 'lafka_branch_user', true );
		$order_type                        = get_term_meta( $term->term_id, 'lafka_branch_order_type', true );
		$branch_delivery_time              = get_term_meta( $term->term_id, 'lafka_branch_delivery_time', true );
		$branch_image_id                   = get_term_meta( $term->term_id, 'lafka_branch_location_img_id', true );
		$address                           = get_term_meta( $term->term_id, 'lafka_branch_address', true );
		$address_geocoded                  = get_term_meta( $term->term_id, 'lafka_branch_address_geocoded', true );
		$branch_shipping_areas             = json_decode( get_term_meta( $term->term_id, 'lafka_branch_shipping_areas', true ) );
		$distance_restriction              = get_term_meta( $term->term_id, 'lafka_branch_distance_restriction', true );
		$distance_unit                     = get_term_meta( $term->term_id, 'lafka_branch_distance_unit', true );
		$override_datetime_global          = (bool) get_term_meta( $term->term_id, 'lafka_branch_override_datetime_global', true );
		$datetime_mandatory                = (bool) get_term_meta( $term->term_id, 'lafka_branch_datetime_mandatory', true );
		$datetime_days_ahead               = get_term_meta( $term->term_id, 'lafka_branch_datetime_days_ahead', true );
		$datetime_timeslot_duration        = get_term_meta( $term->term_id, 'lafka_branch_datetime_timeslot_duration', true );
		$datetime_orders_per_timeslot      = get_term_meta( $term->term_id, 'lafka_branch_datetime_orders_per_timeslot', true );
		$override_order_hours_global       = (bool) get_term_meta( $term->term_id, 'lafka_branch_override_order_hours_global', true );
		$branch_timezone                   = get_term_meta( $term->term_id, 'lafka_branch_timezone', true );
		$order_hours_force_override_check  = (bool) get_term_meta( $term->term_id, 'lafka_branch_order_hours_force_override_check', true );
		$order_hours_force_override_status = get_term_meta( $term->term_id, 'lafka_branch_order_hours_force_override_status', true );
		$order_hours_schedule              = get_term_meta( $term->term_id, 'lafka_branch_order_hours_schedule', true );
		$order_hours_holidays_calendar     = get_term_meta( $term->term_id, 'lafka_branch_order_hours_holidays_calendar', true );

		global /** @var Lafka_Shipping_Areas $lafka_shipping_areas */
		$lafka_shipping_areas;

		$delivery_areas = array();
		foreach ( $lafka_shipping_areas->get_all_delivery_areas() as $area ) {
			$delivery_areas[ $area->ID ] = get_the_title( $area->ID );
		}

		if ( empty( $address_geocoded ) ) {
			$address = '';
		}

		if ( $branch_image_id ) {
			$branch_image_src = wp_get_attachment_thumb_url( $branch_image_id );
		} else {
			$branch_image_src = wc_placeholder_img_src();
		}

		$manage_woocommerce_users = get_users( array( 'capability' => array( 'manage_woocommerce' ) ) );
		?>
		<tr class="form-field">
			<th scope="row">
				<label for="lafka_branch_user"><?php esc_html_e( 'Branch Manager', 'lafka-plugin' ); ?></label>
			</th>
			<td>
				<select class="lafka-select2" name="lafka_branch_user" id="lafka_branch_user" data-allow-clear="true"
						data-placeholder="<?php esc_attr_e( 'Choose Branch Manager&hellip;', 'lafka-plugin' ); ?>" aria-label="<?php esc_attr_e( 'Branch Manager', 'lafka-plugin' ); ?>">
					<option value="" <?php echo wc_selected( '', $branch_user_id ); ?>><?php esc_attr_e( 'Choose Branch Manager&hellip;', 'lafka-plugin' ); ?></option>
					<?php /** @var WP_User $user */ ?>
					<?php foreach ( $manage_woocommerce_users as $user ) : ?>
						<option value="<?php echo esc_attr( $user->ID ); ?>" <?php echo wc_selected( $user->ID, $branch_user_id ); ?>><?php echo esc_html( $user->user_nicename ); ?></option>
					<?php endforeach; ?>
				</select>
				<p><?php esc_html_e( 'Selected user will see only the orders for this or any other assigned to him branches. The user will also get new orders push and email notifications only for his branches.', 'lafka-plugin' ); ?></p>
			</td>
		</tr>
		<tr class="form-field">
			<th scope="row">
				<label for="lafka_branch_order_type"><?php esc_html_e( 'Order Type', 'lafka-plugin' ); ?></label>
			</th>
			<td>
				<select class="lafka-select2" name="lafka_branch_order_type" id="lafka_branch_order_type" data-allow-clear="true" aria-label="<?php esc_attr_e( 'Order Type', 'lafka-plugin' ); ?>">
					<option value="delivery_pickup" <?php selected( $order_type, 'delivery_pickup' ); ?>><?php esc_html_e( 'Delivery and Pickup', 'lafka-plugin' ); ?></option>
					<option value="delivery" <?php selected( $order_type, 'delivery' ); ?>><?php esc_html_e( 'Only Delivery', 'lafka-plugin' ); ?></option>
					<option value="pickup" <?php selected( $order_type, 'pickup' ); ?>><?php esc_html_e( 'Only Pickup', 'lafka-plugin' ); ?></option>
				</select>
				<p><?php esc_html_e( 'Specify which order types will be accepted by the branch.', 'lafka-plugin' ); ?></p>
			</td>
		</tr>
		<tr class="form-field">
			<th scope="row">
				<label for="lafka_branch_delivery_time"><?php esc_html_e( 'Estimated Delivery/Prep Time', 'lafka-plugin' ); ?></label>
			</th>
			<td>
				<input name="lafka_branch_delivery_time" id="lafka_branch_delivery_time" type="text" value="<?php esc_attr_e( $branch_delivery_time ); ?>"/>
				<p><?php esc_html_e( 'Enter the average delivery/prep time for this branch. Will be displayed in the branch info box.', 'lafka-plugin' ); ?></p>
			</td>
		</tr>
		<tr class="form-field">
			<th scope="row" valign="top"><label><?php echo esc_html__( 'Branch Location Image', 'lafka-plugin' ); ?></label>
			</th>
			<td>
				<div id="lafka_branch_location_img" style="float: left; margin-right: 10px;"><img
							src="<?php echo esc_url( $branch_image_src ); ?>" width="60px" height="60px"/></div>
				<div style="line-height: 60px;">
					<input type="hidden" id="lafka_branch_location_img_id" name="lafka_branch_location_img_id" value="<?php echo esc_attr( $branch_image_id ); ?>"/>
					<button type="button"
							class="lafka_branch_location_img_upload_image_button button"><?php echo esc_html__( 'Upload/Add image', 'lafka-plugin' ); ?></button>
					<button type="button"
							class="lafka_branch_location_img_remove_image_button button"><?php echo esc_html__( 'Remove image', 'lafka-plugin' ); ?></button>
				</div>
				<div class="clear"></div>
				<p class="description"><?php esc_html_e( 'The image will be shown on the popup for selecting the branch.', 'lafka-plugin' ); ?></p>
			</td>
		</tr>
		<tr class="form-field">
			<th scope="row">
				<label for="lafka_branch_shipping_areas"><?php esc_html_e( 'Shipping Areas', 'lafka-plugin' ); ?></label>
			</th>
			<td>
				<select multiple="multiple"
						name="lafka_branch_shipping_areas[]" id="lafka_branch_shipping_areas"
						data-placeholder="<?php esc_attr_e( 'Choose Shipping Areas&hellip;', 'lafka-plugin' ); ?>"
						aria-label="<?php esc_attr_e( 'Shipping Areas', 'lafka-plugin' ); ?>"
						class="lafka-admin-select2"
				>
					<?php foreach ( $delivery_areas as $option_key => $option_value ) : ?>
						<option value="<?php echo esc_attr( $option_key ); ?>" <?php echo wc_selected( $option_key, $branch_shipping_areas ); ?>><?php echo esc_html( $option_value ); ?></option>
					<?php endforeach; ?>
				</select>
				<p class="description"><?php esc_html_e( 'Choose Lafka Shipping Areas covered by this branch.', 'lafka-plugin' ); ?></p>
			</td>
		</tr>
		<tr class="form-field">
			<th scope="row">
				<label for="lafka_branch_distance_restriction"><?php esc_html_e( 'Radius Restriction', 'lafka-plugin' ); ?></label>
			</th>
			<td>
				<input name="lafka_branch_distance_restriction" id="lafka_branch_distance_restriction" type="number" value="<?php esc_attr_e( $distance_restriction ); ?>"/>
				<p class="description"><?php esc_html_e( 'Suitable for radius based delivery restrictions. This option is only used for the initial check (popup) of the delivery availability based on the user address.', 'lafka-plugin' ); ?></p>
			</td>
		</tr>
		<tr class="form-field">
			<th scope="row">
				<label for=" lafka_branch_distance_unit"><?php esc_html_e( 'Distance Unit', 'lafka-plugin' ); ?></label>
			</th>
			<td>
				<select class="select wc-enhanced-select" name="lafka_branch_distance_unit" id="lafka_branch_distance_unit" style="">
					<option value="metric" <?php selected( $distance_unit, 'metric' ); ?>><?php esc_html_e( 'Metric (km)', 'lafka-plugin' ); ?></option>
					<option value="imperial"<?php selected( $distance_unit, 'imperial' ); ?>><?php esc_html_e( 'Imperial (miles)', 'lafka-plugin' ); ?></option>
				</select>
			</td>
		</tr>
		<tr class="form-field form-required lafka_branch_address-wrap">
			<th scope="row">
				<label for="lafka_branch_address"><?php esc_html_e( 'Address', 'lafka-plugin' ); ?></label>
			</th>
			<td>
				<input name="lafka_branch_address" id="lafka_branch_address" placeholder="<?php esc_html_e( 'Enter a location', 'lafka-plugin' ); ?>" type="text"
						value="<?php esc_attr_e( $address ); ?>"/>
				<input name="lafka_branch_address_geocoded" id="lafka_branch_address_geocoded" type="hidden" value="<?php esc_attr_e( $address_geocoded ); ?>"/>
				<p class="description">
					<?php esc_html_e( 'The full address for the branch to calculate distance shipping and apply radius restrictions.', 'lafka-plugin' ); ?>
					<?php esc_html_e( 'Note: Branch Location will be active only if it is properly geocoded.', 'lafka-plugin' ); ?><br>
					<span class="lafka-branch-address-instructions">
						<strong><?php esc_html_e( 'Instructions', 'lafka-plugin' ); ?></strong>: <?php esc_html_e( 'Enter an address in the textbox and geocode or click on the map to set the location.', 'lafka-plugin' ); ?>
					</span>
				</p>
				<div id="lafka_geocode_branch_location_map"></div>
			</td>
		</tr>
		<?php $datetime_options = get_option( 'lafka_shipping_areas_datetime' ); ?>
		<?php if ( ! empty( $datetime_options['enable_datetime_option'] ) ) : ?>
			<tr class="form-field">
				<td colspan="2">
					<h3><?php esc_html_e( 'Branch Specific Delivery/Pickup Date Time Settings', 'lafka-plugin' ); ?></h3>
				</td>
			</tr>
			<tr class="form-field">
				<th scope="row">
					<label for="lafka_branch_override_datetime_global"><?php esc_html_e( 'Override Global Delivery/Pickup Date Time', 'lafka-plugin' ); ?></label>
				</th>
				<td>
					<input type="checkbox" name="lafka_branch_override_datetime_global" id="lafka_branch_override_datetime_global" <?php checked( $override_datetime_global ); ?> />
					<label for="lafka_branch_override_datetime_global"><?php esc_html_e( 'Override Global Delivery/Pickup Date Time Settings', 'lafka-plugin' ); ?></label>
				</td>
			</tr>
			<tr class="form-field">
				<th scope="row">
					<label for="lafka_branch_datetime_mandatory"><?php esc_html_e( 'Mandatory Delivery/Pickup Date Time', 'lafka-plugin' ); ?></label>
				</th>
				<td>
					<input type="checkbox" name="lafka_branch_datetime_mandatory" id="lafka_branch_datetime_mandatory" <?php checked( $datetime_mandatory ); ?> />
					<label for="lafka_branch_datetime_mandatory"><?php esc_html_e( 'Make Delivery/Pickup date and time fields mandatory', 'lafka-plugin' ); ?></label>
				</td>
			</tr>
			<tr class="form-field">
				<th scope="row">
					<label for="lafka_branch_datetime_days_ahead"><?php esc_html_e( 'Number of days ahead to order', 'lafka-plugin' ); ?></label>
				</th>
				<td>
					<input name="lafka_branch_datetime_days_ahead" id="lafka_branch_datetime_days_ahead" type="number" value="<?php esc_attr_e( $datetime_days_ahead ); ?>"/>
					<p><?php esc_html_e( 'Enter for how many days ahead user can make an order. The following days will be disabled in the calendar on the checkout page.', 'lafka-plugin' ); ?></p>
				</td>
			</tr>
			<tr class="form-field">
				<th scope="row">
					<label for="lafka_branch_datetime_timeslot_duration"><?php esc_html_e( 'Time Slot Duration (Minutes)', 'lafka-plugin' ); ?></label>
				</th>
				<td>
					<input name="lafka_branch_datetime_timeslot_duration" id="lafka_branch_datetime_timeslot_duration" type="number" value="<?php esc_attr_e( $datetime_timeslot_duration ); ?>"/>
					<p><?php esc_html_e( 'Enter the time in minutes for the slots in which the user can request the order to be completed.', 'lafka-plugin' ); ?></p>
				</td>
			</tr>
			<tr class="form-field">
				<th scope="row">
					<label for="lafka_branch_datetime_orders_per_timeslot"><?php esc_html_e( 'Orders Per Time Slot', 'lafka-plugin' ); ?></label>
				</th>
				<td>
					<input name="lafka_branch_datetime_orders_per_timeslot" id="lafka_branch_datetime_orders_per_timeslot" type="number" value="<?php esc_attr_e( $datetime_orders_per_timeslot ); ?>"/>
					<p><?php esc_html_e( 'Enter the number of orders which can be made in one time slot. If the number is reached for particular time slot, the users will still be able to see it, but it will be disabled.', 'lafka-plugin' ); ?></p>
				</td>
			</tr>
		<?php endif; ?>
		<?php if ( is_lafka_order_hours( get_option( 'lafka' ) ) ) : ?>
			<tr class="form-field">
				<td colspan="2">
					<h3><?php esc_html_e( 'Branch Specific Order Hours Settings', 'lafka-plugin' ); ?></h3>
				</td>
			</tr>
			<tr class="form-field">
				<th scope="row">
					<label for="lafka_branch_override_order_hours_global"><?php esc_html_e( 'Override Global Order Hours', 'lafka-plugin' ); ?></label>
				</th>
				<td>
					<input name="lafka_branch_override_order_hours_global" id="lafka_branch_override_order_hours_global" type="checkbox" <?php checked( $override_order_hours_global ); ?>/>
					<label for="lafka_branch_override_order_hours_global"><?php esc_html_e( 'Override Global Order Hours Settings', 'lafka-plugin' ); ?></label>
				</td>
			</tr>
			<tr class="form-field">
				<th scope="row">
					<?php
					$timezones         = DateTimeZone::listIdentifiers( DateTimeZone::ALL );
					$timezones_options = array( 'default' => esc_html__( 'Use WordPress default', 'lafka-plugin' ) );
					$timezones_options = array_merge( $timezones_options, array_combine( $timezones, $timezones ) );
					?>
					<label for="lafka_branch_timezone"><?php esc_html_e( 'Time Zone', 'lafka-plugin' ); ?></label>
				</th>
				<td>
					<select class="lafka-select2" name="lafka_branch_timezone" id="lafka_branch_timezone">
						<?php foreach ( $timezones_options as $key => $value ) : ?>
							<option value="<?php echo esc_attr( $key ); ?>" <?php echo wc_selected( $key, $branch_timezone ); ?>>
								<?php esc_html_e( $value ); ?>
							</option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr class="form-field">
				<td colspan="2">
					<h2><?php esc_html_e( 'Force Override Store Schedule', 'lafka-plugin' ); ?></h2>
				</td>
			</tr>
			<tr class="form-field">
				<th scope="row">
					<label for="lafka_branch_order_hours_force_override_check"><?php esc_html_e( 'Turn on Force Override', 'lafka-plugin' ); ?></label>
				</th>
				<td>
					<input name="lafka_branch_order_hours_force_override_check" id="lafka_branch_order_hours_force_override_check"
							type="checkbox" <?php checked( $order_hours_force_override_check ); ?>/>
					<label for="lafka_branch_order_hours_force_override_check"><?php esc_html_e( 'Override current working time schedule and change the store status.', 'lafka-plugin' ); ?></label>
				</td>
			</tr>
			<tr class="form-field">
				<th scope="row">
					<label for="lafka_branch_order_hours_force_override_status"><?php esc_html_e( 'Ordering Status', 'lafka-plugin' ); ?></label>
				</th>
				<td>
					<select class="select" name="lafka_branch_order_hours_force_override_status" id="lafka_branch_order_hours_force_override_status" style="">
						<option value="" <?php echo wc_selected( false, $order_hours_force_override_status ); ?>><?php esc_html_e( 'Disabled', 'lafka-plugin' ); ?></option>
						<option value="1" <?php echo wc_selected( 1, $order_hours_force_override_status ); ?>><?php esc_html_e( 'Enabled', 'lafka-plugin' ); ?></option>
					</select>
				</td>
			</tr>
			<tr class="form-field">
				<td colspan="2">
					<h2><?php esc_html_e( 'Days Schedule', 'lafka-plugin' ); ?></h2>
				</td>
			</tr>
			<tr class="form-field">
				<th scope="row">
					<label for="lafka_branch_order_hours_schedule"><?php esc_html_e( 'Open Hours Periods', 'lafka-plugin' ); ?></label>
				</th>
				<td>
					<input value="<?php echo esc_attr( $order_hours_schedule ); ?>" name="lafka_branch_order_hours_schedule" id="lafka_branch_order_hours_schedule" type="hidden" readonly="readonly"/>
					<p class="description"><?php esc_html_e( 'Drag over the table to create order hours periods. Use X and Copy icons to delete or duplicate periods.', 'lafka-plugin' ); ?></p>
				</td>
			</tr>
			<tr>
				<td colspan="2">
					<div id="lafka_branch_order_hours_container"></div>
				</td>
			</tr>
			<tr class="form-field">
				<td colspan="2">
					<h2><?php esc_html_e( 'Holidays Schedule', 'lafka-plugin' ); ?></h2>
				</td>
			</tr>
			<tr class="form-field">
				<th scope="row">
					<label for="lafka_branch_order_hours_holidays_calendar"><?php esc_html_e( 'Holidays Calendar', 'lafka-plugin' ); ?></label>
				</th>
				<td>
					<input id="lafka_branch_order_hours_holidays_calendar"
							name="lafka_branch_order_hours_holidays_calendar"
							type="text"
							value="<?php echo esc_attr( $order_hours_holidays_calendar ); ?>"
							readonly="readonly"
					>
					<p class="description"><?php esc_html_e( 'Click on Text Box to Open Calendar and Select Your Holidays', 'lafka-plugin' ); ?></p>
				</td>
			</tr>
		<?php endif; ?>
		<?php
	}

	public static function edit_branch_location( $term_id ) {
		if ( isset( $_POST['lafka_branch_user'] ) ) {
			update_term_meta( $term_id, 'lafka_branch_user', sanitize_text_field( $_POST['lafka_branch_user'] ) );
		}
		if ( isset( $_POST['lafka_branch_order_type'] ) ) {
			update_term_meta( $term_id, 'lafka_branch_order_type', sanitize_text_field( $_POST['lafka_branch_order_type'] ) );
		}
		if ( isset( $_POST['lafka_branch_delivery_time'] ) ) {
			update_term_meta( $term_id, 'lafka_branch_delivery_time', sanitize_text_field( $_POST['lafka_branch_delivery_time'] ) );
		}
		if ( isset( $_POST['lafka_branch_location_img_id'] ) ) {
			update_term_meta( $term_id, 'lafka_branch_location_img_id', sanitize_text_field( $_POST['lafka_branch_location_img_id'] ) );
		}
		if ( isset( $_POST['lafka_branch_address'] ) ) {
			update_term_meta( $term_id, 'lafka_branch_address', sanitize_text_field( $_POST['lafka_branch_address'] ) );
		}
		if ( isset( $_POST['lafka_branch_address_geocoded'] ) ) {
			// Using esc_attr(), because sanitize_text_field() breaks the json string
			update_term_meta( $term_id, 'lafka_branch_address_geocoded', esc_attr( $_POST['lafka_branch_address_geocoded'] ) );
		}
		if ( isset( $_POST['lafka_branch_shipping_areas'] ) ) {
			update_term_meta( $term_id, 'lafka_branch_shipping_areas', sanitize_text_field( json_encode( $_POST['lafka_branch_shipping_areas'] ) ) );
		} else {
			update_term_meta( $term_id, 'lafka_branch_shipping_areas', '' );
		}
		if ( isset( $_POST['lafka_branch_distance_restriction'] ) ) {
			update_term_meta( $term_id, 'lafka_branch_distance_restriction', sanitize_text_field( $_POST['lafka_branch_distance_restriction'] ) );
		}
		if ( isset( $_POST['lafka_branch_distance_unit'] ) ) {
			update_term_meta( $term_id, 'lafka_branch_distance_unit', sanitize_text_field( $_POST['lafka_branch_distance_unit'] ) );
		}
		if ( isset( $_POST['lafka_branch_override_datetime_global'] ) ) {
			update_term_meta( $term_id, 'lafka_branch_override_datetime_global', sanitize_text_field( $_POST['lafka_branch_override_datetime_global'] ) );
		} else {
			update_term_meta( $term_id, 'lafka_branch_override_datetime_global', sanitize_text_field( false ) );
		}
		if ( isset( $_POST['lafka_branch_datetime_mandatory'] ) ) {
			update_term_meta( $term_id, 'lafka_branch_datetime_mandatory', sanitize_text_field( $_POST['lafka_branch_datetime_mandatory'] ) );
		} else {
			update_term_meta( $term_id, 'lafka_branch_datetime_mandatory', sanitize_text_field( false ) );
		}
		if ( isset( $_POST['lafka_branch_datetime_days_ahead'] ) ) {
			update_term_meta( $term_id, 'lafka_branch_datetime_days_ahead', sanitize_text_field( $_POST['lafka_branch_datetime_days_ahead'] ) );
		}
		if ( isset( $_POST['lafka_branch_datetime_timeslot_duration'] ) ) {
			update_term_meta( $term_id, 'lafka_branch_datetime_timeslot_duration', sanitize_text_field( $_POST['lafka_branch_datetime_timeslot_duration'] ) );
		}
		if ( isset( $_POST['lafka_branch_datetime_orders_per_timeslot'] ) ) {
			update_term_meta( $term_id, 'lafka_branch_datetime_orders_per_timeslot', sanitize_text_field( $_POST['lafka_branch_datetime_orders_per_timeslot'] ) );
		}
		if ( isset( $_POST['lafka_branch_override_order_hours_global'] ) ) {
			update_term_meta( $term_id, 'lafka_branch_override_order_hours_global', sanitize_text_field( $_POST['lafka_branch_override_order_hours_global'] ) );
		} else {
			update_term_meta( $term_id, 'lafka_branch_override_order_hours_global', false );
		}
		if ( isset( $_POST['lafka_branch_timezone'] ) ) {
			update_term_meta( $term_id, 'lafka_branch_timezone', sanitize_text_field( $_POST['lafka_branch_timezone'] ) );
		}
		if ( isset( $_POST['lafka_branch_order_hours_force_override_check'] ) ) {
			update_term_meta( $term_id, 'lafka_branch_order_hours_force_override_check', sanitize_text_field( $_POST['lafka_branch_order_hours_force_override_check'] ) );
		} else {
			update_term_meta( $term_id, 'lafka_branch_order_hours_force_override_check', false );
		}
		if ( isset( $_POST['lafka_branch_order_hours_force_override_status'] ) ) {
			update_term_meta( $term_id, 'lafka_branch_order_hours_force_override_status', sanitize_text_field( $_POST['lafka_branch_order_hours_force_override_status'] ) );
		}
		if ( isset( $_POST['lafka_branch_order_hours_schedule'] ) ) {
			update_term_meta( $term_id, 'lafka_branch_order_hours_schedule', esc_attr( $_POST['lafka_branch_order_hours_schedule'] ) );
		}
		if ( isset( $_POST['lafka_branch_order_hours_holidays_calendar'] ) ) {
			update_term_meta( $term_id, 'lafka_branch_order_hours_holidays_calendar', sanitize_text_field( $_POST['lafka_branch_order_hours_holidays_calendar'] ) );
		}
	}

	public static function manage_columns_on_location_branches( $columns ): array {
		unset( $columns['description'] );
		unset( $columns['slug'] );
		$new_columns = array();
		if ( isset( $columns['cb'] ) ) {
			$new_columns['cb'] = $columns['cb'];
			unset( $columns['cb'] );
		}
		if ( isset( $columns['name'] ) ) {
			$new_columns['name'] = $columns['name'];
			unset( $columns['name'] );
		}
		$new_columns['lafka_branch_order_type'] = esc_html__( 'Order Type', 'lafka-plugin' );
		if ( is_lafka_order_hours( get_option( 'lafka' ) ) ) {
			$new_columns['lafka_branch_status'] = esc_html__( 'Status', 'lafka-plugin' );
		}
		$new_columns['lafka_branch_user']    = esc_html__( 'Manager', 'lafka-plugin' );
		$new_columns['lafka_branch_address'] = esc_html__( 'Address', 'lafka-plugin' );

		return array_merge( $new_columns, $columns );
	}

	public static function manage_column_content_on_location_branches( $columns, $column, $id ) {
		if ( $column === 'lafka_branch_address' ) {
			$address = get_term_meta( $id, 'lafka_branch_address', true );
			if ( $address ) {
				echo esc_html( $address );
			}
		} elseif ( $column === 'lafka_branch_user' ) {
			$user = get_userdata( get_term_meta( $id, 'lafka_branch_user', true ) );
			if ( ! empty( $user ) ) {
				echo esc_html( $user->user_nicename );
			}
		} elseif ( $column === 'lafka_branch_order_type' ) {
			$values                    = array(
				'delivery_pickup' => esc_html__( 'Delivery and Pickup', 'lafka-plugin' ),
				'delivery'        => esc_html__( 'Only Delivery', 'lafka-plugin' ),
				'pickup'          => esc_html__( 'Only Pickup', 'lafka-plugin' ),
			);
			$current_branch_order_type = get_term_meta( $id, 'lafka_branch_order_type', true );
			$branch_order_type         = empty( $current_branch_order_type ) ? 'delivery_pickup' : $current_branch_order_type;

			echo esc_html( $values[ $branch_order_type ] );
		} elseif ( $column === 'lafka_branch_status' ) {
			if ( is_lafka_order_hours( get_option( 'lafka' ) ) && class_exists( 'Lafka_Order_Hours' ) ) {
				$branch_status = Lafka_Order_Hours::get_branch_working_status( $id );
				?>
				<span class="lafka-order-hours-current-time <?php echo $branch_status->code; ?>">
					<?php echo Lafka_Order_Hours::get_order_hours_time( $branch_status->branch_timezone )->format( 'H:i' ); ?> | <?php echo strtoupper( $branch_status->value ); ?>
				</span>
				<?php
			}
		}
	}

	public static function add_columns_to_orders_list( $columns ): array {
		$columns['lafka_selected_branch'] = esc_html__( 'Branch', 'lafka-plugin' );
		$columns['lafka_order_type']      = esc_html__( 'Order Type', 'lafka-plugin' );

		return $columns;
	}

	public static function add_columns_content_to_orders_list( $column, $order ) {
		$order = wc_get_order( $order );

		if ( $column === 'lafka_selected_branch' ) {
			$branch_id = Lafka_Shipping_Areas::get_order_meta_backward_compatible( $order->get_id(), 'lafka_selected_branch_id' );
			$branch    = get_term( $branch_id );
			if ( ! empty( $branch->name ) ) {
				echo '<span>' . esc_html( $branch->name ) . '</span>';
			}
		} elseif ( $column === 'lafka_order_type' ) {
			$order_type = Lafka_Shipping_Areas::get_order_meta_backward_compatible( $order->get_id(), 'lafka_order_type' );
			if ( $order_type === 'delivery' ) {
				echo '<span class="lafka-order-type-delivery">' . esc_html__( 'Delivery', 'lafka-plugin' ) . '</span>';
			} elseif ( $order_type === 'pickup' ) {
				echo '<span class="lafka-order-type-pickup">' . esc_html__( 'Pickup', 'lafka-plugin' ) . '</span>';
			}
		}
	}

	public static function add_columns_to_sortable_columns( $columns ): array {
		$columns['lafka_selected_branch'] = 'lafka_selected_branch_id';
		$columns['lafka_order_type']      = 'lafka_order_type';

		return $columns;
	}

	public static function orders_list_define_sort_and_search_queries_for_custom_fields( $query ) {
		if ( function_exists( 'get_current_screen' ) ) {
			$current_screen = get_current_screen();
			if ( is_admin() && ! empty( $current_screen ) && $current_screen->id === 'edit-shop_order' ) {
				$meta_query_args = array();
				$branch_id       = $_GET['branch_location_filter'] ?? '';
				if ( is_numeric( $branch_id ) ) {
					$meta_query_args[] = array(
						'key'   => 'lafka_selected_branch_id',
						'value' => $branch_id,
					);
				} elseif ( class_exists( 'Lafka_Branch_Locations' ) ) {
					$branches_of_current_user = Lafka_Branch_Locations::get_user_branches( get_current_user_id() );
					if ( ! empty( $branches_of_current_user ) ) {
						$meta_query_args[] = array(
							'key'     => 'lafka_selected_branch_id',
							'value'   => array_keys( $branches_of_current_user ),
							'compare' => 'IN',
						);
					}
				}

				$order_type = $_GET['order_type_filter'] ?? '';
				if ( $order_type ) {
					$meta_query_args[] = array(
						'key'   => 'lafka_order_type',
						'value' => sanitize_text_field( $order_type ),
					);
				}

				$order_by = $query->get( 'orderby' );
				if ( $order_by === 'lafka_checkout_date' ) {
					$meta_query_args[] = array(
						'relation' => 'OR',
						array(
							'lafka_checkout_date_clause' => array(
								'key'     => 'lafka_checkout_date',
								'compare' => 'EXISTS',
							),
							'lafka_checkout_timeslot_clause' => array(
								'key'     => 'lafka_checkout_timeslot',
								'compare' => 'EXISTS',
							),
						),
						array(
							'lafka_checkout_date_clause' => array(
								'key'     => 'lafka_checkout_date',
								'compare' => 'NOT EXISTS',
							),
							'lafka_checkout_timeslot_clause' => array(
								'key'     => 'lafka_checkout_timeslot',
								'compare' => 'NOT EXISTS',
							),
						),
					);
					$query->set(
						'orderby',
						array(
							'lafka_checkout_date_clause' => sanitize_text_field( $_GET['order'] ),
							'lafka_checkout_timeslot_clause' => sanitize_text_field( $_GET['order'] ),
						)
					);
				}

				if ( ! empty( $meta_query_args ) ) {
					$query->set( 'meta_query', $meta_query_args );
				}
			}
		}
	}

	public static function orders_list_define_sort_and_search_queries_for_custom_fields_hpos( $args ): array {
		$current_page = sanitize_text_field( $_GET['page'] ?? '' );

		if ( $current_page === 'wc-orders' ) {
			$branch_id = $_GET['branch_location_filter'] ?? '';
			if ( is_numeric( $branch_id ) ) {
				$args['meta_query'][] = array(
					'key'   => 'lafka_selected_branch_id',
					'value' => $branch_id,
				);
			} elseif ( class_exists( 'Lafka_Branch_Locations' ) ) {
				$branches_of_current_user = Lafka_Branch_Locations::get_user_branches( get_current_user_id() );
				if ( ! empty( $branches_of_current_user ) ) {
					$args['meta_query'][] = array(
						'key'     => 'lafka_selected_branch_id',
						'value'   => array_keys( $branches_of_current_user ),
						'compare' => 'IN',
					);
				}
			}

			$order_type = $_GET['order_type_filter'] ?? '';
			if ( $order_type ) {
				$args['meta_query'][] = array(
					'key'   => 'lafka_order_type',
					'value' => sanitize_text_field( $order_type ),
				);
			}

			if ( isset( $_GET['orderby'] ) && sanitize_text_field( $_GET['orderby'] ) === 'lafka_selected_branch_id' ) {
				$args['orderby']      = 'lafka_selected_branch_id';
				$args['meta_query'][] = array(
					'relation' => 'OR',
					array(
						'lafka_selected_branch_id' => array(
							'key'     => 'lafka_selected_branch_id',
							'compare' => 'EXISTS',
						),
					),
					array(
						'lafka_selected_branch_id' => array(
							'key'     => 'lafka_selected_branch_id',
							'compare' => 'NOT EXISTS',
						),
					),
				);
			}

			if ( isset( $_GET['orderby'] ) && sanitize_text_field( $_GET['orderby'] ) === 'lafka_order_type' ) {
				$args['orderby']      = 'lafka_order_type';
				$args['meta_query'][] = array(
					'relation' => 'OR',
					array(
						'lafka_order_type' => array(
							'key'     => 'lafka_order_type',
							'compare' => 'EXISTS',
						),
					),
					array(
						'lafka_order_type' => array(
							'key'     => 'lafka_order_type',
							'compare' => 'NOT EXISTS',
						),
					),
				);
			}

			if ( isset( $_GET['orderby'] ) && sanitize_text_field( $_GET['orderby'] ) === 'lafka_checkout_date' ) {
				$args['meta_query'][] = array(
					'relation' => 'OR',
					array(
						'lafka_checkout_date_clause'     => array(
							'key'     => 'lafka_checkout_date',
							'compare' => 'EXISTS',
						),
						'lafka_checkout_timeslot_clause' => array(
							'key'     => 'lafka_checkout_timeslot',
							'compare' => 'EXISTS',
						),
					),
					array(
						'lafka_checkout_date_clause'     => array(
							'key'     => 'lafka_checkout_date',
							'compare' => 'NOT EXISTS',
						),
						'lafka_checkout_timeslot_clause' => array(
							'key'     => 'lafka_checkout_timeslot',
							'compare' => 'NOT EXISTS',
						),
					),
				);
				$args['orderby']      = array(
					'lafka_checkout_date_clause'     => sanitize_text_field( $_GET['order'] ),
					'lafka_checkout_timeslot_clause' => sanitize_text_field( $_GET['order'] ),
				);
			}
		}

		return $args;
	}

	public static function add_fields_to_orders_list_filter( $post_type, $which ) {
		if ( $post_type == 'shop_order' ) {
			$branches_for_select = array();
			if ( class_exists( 'Lafka_Branch_Locations' ) ) {
				$branches_of_current_user = Lafka_Branch_Locations::get_user_branches( get_current_user_id() );
			} else {
				$branches_of_current_user = array();
			}
			if ( ! empty( $branches_of_current_user ) ) {
				$branches_for_select = $branches_of_current_user;
			} else {
				$all_branches = Lafka_Shipping_Areas::get_all_legit_branch_locations();
				if ( ! empty( $all_branches ) ) {
					$branches_for_select = $all_branches;
				}
			}

			if ( ! empty( $branches_for_select ) ) {
			}
			$filtered_branch_id = $_GET['branch_location_filter'] ?? '';
			?>
			<select id="branch_location_filter" name="branch_location_filter">
				<option value=""><?php esc_html_e( 'All Branches', 'lafka-plugin' ); ?></option>
				<?php foreach ( $branches_for_select as $id => $name ) : ?>
					<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $id, (int) $filtered_branch_id ); ?> >
						<?php echo esc_html( $name ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<?php
		}
		$filtered_order_type = $_GET['order_type_filter'] ?? '';
		?>
		<select id="order_type_filter" name="order_type_filter">
			<option value=""><?php esc_html_e( 'All Order Types', 'lafka-plugin' ); ?></option>
			<option value="delivery" <?php selected( 'delivery', esc_attr( $filtered_order_type ) ); ?> ><?php esc_html_e( 'Delivery', 'lafka-plugin' ); ?></option>
			<option value="pickup" <?php selected( 'pickup', esc_attr( $filtered_order_type ) ); ?> ><?php esc_html_e( 'Pickup', 'lafka-plugin' ); ?></option>
		</select>
		<?php
	}

	public static function menu_order_count_for_user( $count ) {
		if ( class_exists( 'Lafka_Branch_Locations' ) ) {
			$branches_of_current_user = Lafka_Branch_Locations::get_user_branches( get_current_user_id() );
		} else {
			$branches_of_current_user = array();
		}

		if ( ! empty( $branches_of_current_user ) ) {
			$orders = wc_get_orders( array( 'lafka_selected_branch_id' => array_keys( $branches_of_current_user ) ) );

			return count( $orders );
		}

		return $count;
	}
}

Lafka_Branch_Locations_Admin::init();
