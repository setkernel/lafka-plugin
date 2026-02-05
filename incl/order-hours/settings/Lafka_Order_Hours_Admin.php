<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lafka_Order_Hours_Admin {
	public function __construct() {
		add_action( 'admin_enqueue_scripts', array( $this, 'styles' ), 99 );
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'admin_init', array( $this, 'admin_init' ) );
	}

	public function styles() {
		$screen = get_current_screen();

		if ( is_a( $screen, 'WP_Screen' ) && $screen->id === 'woocommerce_page_lafka_order_hours' ) {
			// dequeue jquery ui dialog css as it causes conflicts with the jquery-scheduler
			wp_dequeue_style( 'wp-jquery-ui-dialog' );

			wp_enqueue_style( 'lafka-schedule' );

			wp_enqueue_script( 'lafka-order-hours-admin', plugins_url( '../assets/js/lafka-order-hours-admin.js', __FILE__ ), array(
				'jquery',
				'flatpickr',
				'lafka-schedule'
			), '1.0.0', true );
			wp_enqueue_style( 'lafka-order-hours-admin', plugins_url( '../assets/css/lafka-order-hours-admin.css', __FILE__ ), array( 'flatpickr' ) );
		}
	}

	public function admin_menu() {
		add_submenu_page( 'woocommerce',
			esc_html__( 'Lafka Order Hours', 'lafka-plugin' ),
			esc_html__( 'Lafka Order Hours', 'lafka-plugin' ),
			'manage_woocommerce',
			'lafka_order_hours',
			array( $this, 'lafka_order_hours_admin' ) );
	}

	public function admin_init() {
		register_setting( 'lafka_order_hours', 'lafka_order_hours_options' );

		add_settings_section(
			'lafka_order_hours_status_section',
			esc_html__( 'Status', 'lafka-plugin' ),
			array( $this, 'lafka_order_hours_status_section_cb' ),
			'lafka_order_hours'
		);

		add_settings_section(
			'lafka_order_hours_message_section',
			esc_html__( 'Message', 'lafka-plugin' ),
			null,
			'lafka_order_hours'
		);

		add_settings_field(
			'lafka_order_hours_message',
			esc_html__( 'Closed Store Message', 'lafka-plugin' ),
			array( $this, 'lafka_order_hours_message_cb' ),
			'lafka_order_hours',
			'lafka_order_hours_message_section',
			[
				'label_for' => 'lafka_order_hours_message'
			]
		);

		add_settings_field(
			'lafka_order_hours_disable_add_to_cart',
			esc_html__( 'Disable Add to Cart', 'lafka-plugin' ),
			array( $this, 'lafka_order_hours_disable_add_to_cart_cb' ),
			'lafka_order_hours',
			'lafka_order_hours_message_section',
			[
				'label_for' => 'lafka_order_hours_disable_add_to_cart'
			]
		);

		add_settings_field(
			'lafka_order_hours_message_countdown',
			esc_html__( 'Countdown', 'lafka-plugin' ),
			array( $this, 'lafka_order_hours_message_countdown_cb' ),
			'lafka_order_hours',
			'lafka_order_hours_message_section',
			[
				'label_for' => 'lafka_order_hours_message_countdown'
			]
		);

		add_settings_section(
			'lafka_order_hours_closed_stores_section',
			esc_html__( 'Handle All Branches Closed', 'lafka-plugin' ),
			null,
			'lafka_order_hours'
		);
		add_settings_field(
			'lafka_order_hours_closed_stores_message_enabled',
			esc_html__( 'Enable All Branches Closed Message', 'lafka-plugin' ),
			array( $this, 'lafka_order_hours_closed_stores_message_enabled_cb' ),
			'lafka_order_hours',
			'lafka_order_hours_closed_stores_section',
			[
				'label_for' => 'lafka_order_hours_closed_stores_message_enabled'
			]
		);
		add_settings_field(
			'lafka_order_hours_closed_stores_message',
			esc_html__( 'All Branches Closed Message', 'lafka-plugin' ),
			array( $this, 'lafka_order_hours_closed_stores_message_cb' ),
			'lafka_order_hours',
			'lafka_order_hours_closed_stores_section',
			[
				'label_for' => 'lafka_order_hours_closed_stores_message'
			]
		);

		add_settings_section(
			'lafka_order_hours_force_override_section',
			esc_html__( 'Force Override Store Schedule', 'lafka-plugin' ),
			null,
			'lafka_order_hours'
		);

		add_settings_field(
			'lafka_order_hours_force_override_check',
			esc_html__( 'Turn-on Force Override', 'lafka-plugin' ),
			array( $this, 'lafka_order_hours_force_override_check_cb' ),
			'lafka_order_hours',
			'lafka_order_hours_force_override_section',
			[
				'label_for' => 'lafka_order_hours_force_override_check'
			]
		);

		add_settings_field(
			'lafka_order_hours_force_override_status',
			esc_html__( 'Ordering Status', 'lafka-plugin' ),
			array( $this, 'lafka_order_hours_force_override_status_cb' ),
			'lafka_order_hours',
			'lafka_order_hours_force_override_section',
			[
				'label_for' => 'lafka_order_hours_force_override_status'
			]
		);

		add_settings_section(
			'lafka_order_hours_schedule_section',
			esc_html__( 'Days Schedule', 'lafka-plugin' ),
			null,
			'lafka_order_hours'
		);

		add_settings_field(
			'lafka_order_hours_schedule',
			esc_html__( 'Open Hours Periods', 'lafka-plugin' ),
			array( $this, 'lafka_order_hours_schedule_cb' ),
			'lafka_order_hours',
			'lafka_order_hours_schedule_section',
			[
				'label_for' => 'lafka_order_hours_schedule'
			]
		);

		add_settings_section(
			'lafka_order_hours_holidays_section',
			esc_html__( 'Holidays Schedule', 'lafka-plugin' ),
			null,
			'lafka_order_hours'
		);

		add_settings_field(
			'lafka_order_hours_holidays_calendar',
			esc_html__( 'Holidays Calendar', 'lafka-plugin' ),
			array( $this, 'lafka_order_hours_holidays_calendar_cb' ),
			'lafka_order_hours',
			'lafka_order_hours_holidays_section',
			[
				'label_for' => 'lafka_order_hours_holidays_calendar'
			]
		);

		add_settings_section(
			'lafka_order_hours_cache_section',
			esc_html__( 'Cache Management', 'lafka-plugin' ),
			null,
			'lafka_order_hours'
		);

		add_settings_field(
			'lafka_order_hours_cache_enable',
			esc_html__( 'Enable Shopping Cart Cache Clearing', 'lafka-plugin' ),
			array( $this, 'lafka_order_hours_cache_enable_cb' ),
			'lafka_order_hours',
			'lafka_order_hours_cache_section',
			[
				'label_for' => 'lafka_order_hours_cache_enable'
			]
		);
	}

	public function lafka_order_hours_admin() {

		// check user capabilities
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		// add error/update messages

		// check if the user have submitted the settings
		// wordpress will add the "settings-updated" $_GET parameter to the url
		if ( isset( $_GET['settings-updated'] ) ) {
			// add settings saved message with the class of "updated"
			add_settings_error( 'lafka_order_hours_messages', 'lafka_order_hours_message', __( 'Settings Saved', 'lafka-plugin' ), 'updated' );
		}

		// show error/update messages
		settings_errors( 'lafka_order_hours_messages' );
		?>
        <div class="lafka-order-hours-admin-wrap wrap">
            <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
            <form id="lafka-plugin-open-hours-form" action="options.php" method="post">
				<?php
				// output security fields for the registered setting
				settings_fields( 'lafka_order_hours' );
				// output setting sections and their fields
				do_settings_sections( 'lafka_order_hours' );
				// output save settings button
				submit_button( __( 'Save Settings', 'lafka-plugin' ) );
				?>
            </form>
        </div>
		<?php
	}

	public function lafka_order_hours_status_section_cb( $args ) {
		?>
        <p><?php esc_html_e( 'WooCommerce main store current time', 'lafka-plugin' ); ?>:<br>
            <span class="lafka-order-hours-current-time <?php echo Lafka_Order_Hours::get_shop_status()->code ?>">
                <?php echo Lafka_Order_Hours::get_order_hours_time()->format( 'H:i' ); ?> <?php esc_html_e( 'Status', 'lafka-plugin' ); ?>: <?php echo strtoupper( Lafka_Order_Hours::get_shop_status()->value ); ?>
            </span>
        </p>
		<?php
	}

	public function lafka_order_hours_message_cb( $args ) {
		$options = get_option( 'lafka_order_hours_options' );
		?>
        <input id="<?php echo esc_attr( $args['label_for'] ); ?>"
               name="lafka_order_hours_options[<?php echo esc_attr( $args['label_for'] ); ?>]"
               type="text"
               value="<?php echo isset( $options[ $args['label_for'] ] ) ? ( $options[ $args['label_for'] ] ) : ( '' ); ?>"
			<?php echo isset( $options[ $args['label_for'] ] ) ? ( checked( $options[ $args['label_for'] ], 1 ) ) : ( '' ); ?>
        >
        <p class="description"><?php esc_html_e( 'Enter message that will appear below "Add to Cart" and instead of "Checkout" and "Place Order" links.', 'lafka-plugin' ); ?></p>
		<?php
	}

	public function lafka_order_hours_disable_add_to_cart_cb( $args ) {
		$options = get_option( 'lafka_order_hours_options' );
		?>
        <input id="<?php echo esc_attr( $args['label_for'] ); ?>"
               name="lafka_order_hours_options[<?php echo esc_attr( $args['label_for'] ); ?>]"
               type="checkbox"
               value="1"
			<?php echo isset( $options[ $args['label_for'] ] ) ? ( checked( $options[ $args['label_for'] ], 1 ) ) : ( '' ); ?>
        >
		<?php esc_html_e( 'Disable add to cart when closed for orders.', 'lafka-plugin' ); ?>
		<?php
	}

	public function lafka_order_hours_message_countdown_cb( $args ) {
		$options = get_option( 'lafka_order_hours_options' );
		?>
        <input id="<?php echo esc_attr( $args['label_for'] ); ?>"
               name="lafka_order_hours_options[<?php echo esc_attr( $args['label_for'] ); ?>]"
               type="checkbox"
               value="1"
			<?php echo isset( $options[ $args['label_for'] ] ) ? ( checked( $options[ $args['label_for'] ], 1 ) ) : ( '' ); ?>
        >
		<?php esc_html_e( 'Countdown the time to the next opening. Shown next to the closed store message.', 'lafka-plugin' ); ?>
        <p class="description"><?php esc_html_e( 'NOTE: Countdown will not be shown if shop status is forced. Countdown will not take into account the vacation schedule. ', 'lafka-plugin' ); ?></p>
		<?php
	}

	public function lafka_order_hours_closed_stores_message_enabled_cb( $args ) {
		$options = get_option( 'lafka_order_hours_options' );
		?>
        <input id="<?php echo esc_attr( $args['label_for'] ); ?>"
               name="lafka_order_hours_options[<?php echo esc_attr( $args['label_for'] ); ?>]"
               type="checkbox"
               value="1"
			<?php echo isset( $options[ $args['label_for'] ] ) ? ( checked( $options[ $args['label_for'] ], 1 ) ) : ( '' ); ?>
        >
		<?php esc_html_e( 'Show message in the location confirmation popup when all branches are closed and disable branch selection.', 'lafka-plugin' ); ?>
		<?php
	}

	public function lafka_order_hours_closed_stores_message_cb( $args ) {
		$options = get_option( 'lafka_order_hours_options' );
		?>
        <input id="<?php echo esc_attr( $args['label_for'] ); ?>"
               name="lafka_order_hours_options[<?php echo esc_attr( $args['label_for'] ); ?>]"
               type="text"
               value="<?php echo isset( $options[ $args['label_for'] ] ) ? ( $options[ $args['label_for'] ] ) : ( '' ); ?>"
			<?php echo isset( $options[ $args['label_for'] ] ) ? ( checked( $options[ $args['label_for'] ], 1 ) ) : ( '' ); ?>
        >
        <p class="description"><?php esc_html_e( 'The message when all branches are closed.', 'lafka-plugin' ); ?></p>
		<?php
	}

	public function lafka_order_hours_force_override_check_cb( $args ) {
		$options = get_option( 'lafka_order_hours_options' );
		?>
        <input id="<?php echo esc_attr( $args['label_for'] ); ?>"
               name="lafka_order_hours_options[<?php echo esc_attr( $args['label_for'] ); ?>]"
               type="checkbox"
               value="1"
			<?php echo isset( $options[ $args['label_for'] ] ) ? ( checked( $options[ $args['label_for'] ], 1 ) ) : ( '' ); ?>
        >
		<?php esc_html_e( 'Override current working time schedule and change the store status.', 'lafka-plugin' ); ?>
		<?php
	}

	public function lafka_order_hours_force_override_status_cb( $args ) {
		$options = get_option( 'lafka_order_hours_options' );
		?>
        <select id="<?php echo esc_attr( $args['label_for'] ); ?>"
                name="lafka_order_hours_options[<?php echo esc_attr( $args['label_for'] ); ?>]"
        >
            <option value="" <?php echo isset( $options[ $args['label_for'] ] ) ? ( selected( $options[ $args['label_for'] ], '', false ) ) : ( '' ); ?>>
				<?php esc_html_e( 'Disabled', 'lafka-plugin' ); ?>
            </option>
            <option value="1" <?php echo isset( $options[ $args['label_for'] ] ) ? ( selected( $options[ $args['label_for'] ], '1', false ) ) : ( '' ); ?>>
				<?php esc_html_e( 'Enabled', 'lafka-plugin' ); ?>
            </option>
        </select>
		<?php
	}

	public function lafka_order_hours_schedule_cb( $args ) {
		$options = get_option( 'lafka_order_hours_options' );
		?>
        <p class="description"><?php esc_html_e( 'Drag over the table to create order hours periods. Use X and Copy icons to delete or duplicate periods.', 'lafka-plugin' ); ?></p>
        <input id="<?php echo esc_attr( $args['label_for'] ); ?>"
               name="lafka_order_hours_options[<?php echo esc_attr( $args['label_for'] ); ?>]"
               type="hidden"
               value="<?php echo esc_attr( isset( $options[ $args['label_for'] ] ) ? $options[ $args['label_for'] ] : ( '' ) ); ?>"
               readonly="readonly"
        >
        <div id="lafka_order_hours_schedule_container"></div>
		<?php
	}

	public function lafka_order_hours_holidays_calendar_cb( $args ) {
		$options = get_option( 'lafka_order_hours_options' );
		?>
        <input id="<?php echo esc_attr( $args['label_for'] ); ?>"
               name="lafka_order_hours_options[<?php echo esc_attr( $args['label_for'] ); ?>]"
               type="text"
               value="<?php echo esc_attr( isset( $options[ $args['label_for'] ] ) ? $options[ $args['label_for'] ] : ( '' ) ); ?>"
               readonly="readonly"
        >
        <p class="description"><?php esc_html_e( 'Click on Text Box to Open Calendar and Select Your Holidays', 'lafka-plugin' ); ?></p>
		<?php
	}

	public function lafka_order_hours_cache_enable_cb( $args ) {
		$options = get_option( 'lafka_order_hours_options' );
		?>
        <input id="<?php echo esc_attr( $args['label_for'] ); ?>"
               name="lafka_order_hours_options[<?php echo esc_attr( $args['label_for'] ); ?>]"
               type="checkbox"
               value="1"
			<?php echo isset( $options[ $args['label_for'] ] ) ? ( checked( $options[ $args['label_for'] ], 1 ) ) : ( '' ); ?>
        >
		<?php esc_html_e( 'Shopping Cart cache will be cleared on each request.', 'lafka-plugin' ); ?>
        <p class="description"><?php esc_html_e( 'This will ensure that the presence of "Checkout" button in the cart is in sync with store status. Cart content stays intact.', 'lafka-plugin' ); ?></p>
		<?php
	}
}