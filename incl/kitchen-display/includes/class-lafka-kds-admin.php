<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lafka_KDS_Admin {

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'admin_menu' ) );
		add_action( 'admin_init', array( $this, 'admin_init' ) );
		add_action( 'admin_init', array( $this, 'handle_regenerate_token' ) );
	}

	public function admin_menu() {
		add_submenu_page(
			'woocommerce',
			esc_html__( 'Lafka Kitchen Display', 'lafka-plugin' ),
			esc_html__( 'Lafka Kitchen Display', 'lafka-plugin' ),
			'manage_woocommerce',
			'lafka_kitchen_display',
			array( $this, 'render_page' )
		);
	}

	public function admin_init() {
		register_setting( 'lafka_kds', 'lafka_kds_options', array( $this, 'sanitize_options' ) );

		// Ensure token exists
		$options = Lafka_Kitchen_Display::get_options();
		if ( empty( $options['token'] ) ) {
			$options['token'] = wp_generate_password( 32, false );
			update_option( 'lafka_kds_options', $options );
			// Flush rewrite rules on first save
			set_transient( 'lafka_kds_flush_rewrite', '1', 60 );
		}

		add_settings_section(
			'lafka_kds_general',
			esc_html__( 'General Settings', 'lafka-plugin' ),
			null,
			'lafka_kds'
		);

		add_settings_field(
			'lafka_kds_url',
			esc_html__( 'Kitchen Display URL', 'lafka-plugin' ),
			array( $this, 'render_url_field' ),
			'lafka_kds',
			'lafka_kds_general'
		);

		add_settings_field(
			'lafka_kds_pickup_times',
			esc_html__( 'Pickup ETA Presets (minutes)', 'lafka-plugin' ),
			array( $this, 'render_pickup_times_field' ),
			'lafka_kds',
			'lafka_kds_general',
			array( 'label_for' => 'lafka_kds_pickup_times' )
		);

		add_settings_field(
			'lafka_kds_delivery_times',
			esc_html__( 'Delivery ETA Presets (minutes)', 'lafka-plugin' ),
			array( $this, 'render_delivery_times_field' ),
			'lafka_kds',
			'lafka_kds_general',
			array( 'label_for' => 'lafka_kds_delivery_times' )
		);

		add_settings_field(
			'lafka_kds_sound',
			esc_html__( 'New Order Sound', 'lafka-plugin' ),
			array( $this, 'render_sound_field' ),
			'lafka_kds',
			'lafka_kds_general',
			array( 'label_for' => 'lafka_kds_sound' )
		);

		add_settings_field(
			'lafka_kds_poll_interval',
			esc_html__( 'KDS Poll Interval (seconds)', 'lafka-plugin' ),
			array( $this, 'render_poll_interval_field' ),
			'lafka_kds',
			'lafka_kds_general',
			array( 'label_for' => 'lafka_kds_poll_interval' )
		);

		add_settings_field(
			'lafka_kds_customer_poll_interval',
			esc_html__( 'Customer Poll Interval (seconds)', 'lafka-plugin' ),
			array( $this, 'render_customer_poll_interval_field' ),
			'lafka_kds',
			'lafka_kds_general',
			array( 'label_for' => 'lafka_kds_customer_poll_interval' )
		);

		add_settings_field(
			'lafka_kds_order_notification_email',
			esc_html__( 'Order Notification Email', 'lafka-plugin' ),
			array( $this, 'render_order_notification_email_field' ),
			'lafka_kds',
			'lafka_kds_general',
			array( 'label_for' => 'lafka_kds_order_notification_email' )
		);
	}

	/**
	 * Sanitize options on save.
	 */
	public function sanitize_options( $input ) {
		$current = Lafka_Kitchen_Display::get_options();
		$output  = array();

		// Preserve existing token, or generate one if missing
		$output['token'] = ! empty( $current['token'] ) ? $current['token'] : wp_generate_password( 32, false );

		$output['pickup_times'] = isset( $input['pickup_times'] )
			? $this->sanitize_time_presets( $input['pickup_times'], $current['pickup_times'] )
			: $current['pickup_times'];

		$output['delivery_times'] = isset( $input['delivery_times'] )
			? $this->sanitize_time_presets( $input['delivery_times'], $current['delivery_times'] )
			: $current['delivery_times'];

		$output['sound_enabled'] = ! empty( $input['sound_enabled'] ) ? '1' : '0';

		$output['poll_interval'] = isset( $input['poll_interval'] )
			? max( 5, (int) $input['poll_interval'] )
			: 12;

		$output['customer_poll_interval'] = isset( $input['customer_poll_interval'] )
			? max( 10, (int) $input['customer_poll_interval'] )
			: 20;

		$output['order_notification_email'] = isset( $input['order_notification_email'] )
			? sanitize_email( $input['order_notification_email'] )
			: '';

		// Flush rewrite rules after saving
		set_transient( 'lafka_kds_flush_rewrite', '1', 60 );

		return $output;
	}

	/**
	 * Sanitize comma-separated time presets to valid positive integers.
	 */
	private function sanitize_time_presets( $input, $fallback ) {
		$raw   = array_map( 'trim', explode( ',', sanitize_text_field( $input ) ) );
		$clean = array();
		foreach ( $raw as $val ) {
			$int = (int) $val;
			if ( $int > 0 && $int <= 999 ) {
				$clean[] = $int;
			}
		}

		return $clean ? implode( ',', $clean ) : $fallback;
	}

	/**
	 * Handle token regeneration — legacy (plaintext) or secure (hash-at-rest).
	 *
	 * Secure mode stores only the HMAC of a freshly generated token and surfaces the
	 * raw URL exactly once (a 15-minute transient), since it cannot be reconstructed
	 * from the stored hash. The existing token/URL are untouched until the operator
	 * regenerates, so this never disrupts a live kitchen display mid-service.
	 */
	public function handle_regenerate_token() {
		$secure = isset( $_POST['lafka_kds_regenerate_token_secure'] );
		$legacy = isset( $_POST['lafka_kds_regenerate_token'] );
		if ( ! $secure && ! $legacy ) {
			return;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		check_admin_referer( 'lafka_kds_regenerate_token' );

		$raw     = wp_generate_password( 32, false );
		$options = Lafka_Kitchen_Display::get_options();
		delete_option( 'lafka_kds_token_activity' );

		if ( $secure ) {
			$options['token'] = Lafka_Kitchen_Display::hash_token( $raw );
			update_option( 'lafka_kds_options', $options );
			set_transient( 'lafka_kds_url_once', home_url( '/kitchen-display/' . $raw . '/' ), 15 * MINUTE_IN_SECONDS );
			add_settings_error( 'lafka_kds', 'token_regenerated', __( 'Secure access token regenerated (hash-stored). Copy the URL below now — for security it is shown only once.', 'lafka-plugin' ), 'updated' );
			return;
		}

		$options['token'] = $raw;
		update_option( 'lafka_kds_options', $options );
		add_settings_error( 'lafka_kds', 'token_regenerated', __( 'Access token regenerated.', 'lafka-plugin' ), 'updated' );
	}

	public function render_url_field() {
		// One-time reveal right after a secure (hashed) regeneration.
		$once = get_transient( 'lafka_kds_url_once' );
		if ( $once ) {
			?>
			<code style="display:inline-block;padding:6px 10px;background:#f0f0f1;font-size:13px;word-break:break-all;"><?php echo esc_url( $once ); ?></code>
			<p class="description" style="color:#b32d2e;"><strong><?php esc_html_e( 'Copy this URL now — for security it is shown only once and cannot be recovered. Open it on your kitchen tablet (no login required).', 'lafka-plugin' ); ?></strong></p>
			<?php
			$this->render_token_activity();
			return;
		}

		$options = Lafka_Kitchen_Display::get_options();

		if ( empty( $options['token'] ) ) {
			?>
			<p><em><?php esc_html_e( 'Click "Save Changes" to generate the KDS access URL.', 'lafka-plugin' ); ?></em></p>
			<?php
			return;
		}

		if ( Lafka_Kitchen_Display::is_hashed_token( $options['token'] ) ) {
			// Hash-at-rest: the URL is not stored and cannot be displayed.
			?>
			<p><span class="dashicons dashicons-lock" style="color:#46b450;vertical-align:text-bottom;"></span> <?php esc_html_e( 'The access URL is stored securely (hashed) and is not displayed. Use "Regenerate Token (secure)" below to issue a new URL.', 'lafka-plugin' ); ?></p>
			<?php
		} else {
			// Legacy plaintext token — URL still reconstructable.
			$url = home_url( '/kitchen-display/' . $options['token'] . '/' );
			?>
			<code style="display:inline-block;padding:6px 10px;background:#f0f0f1;font-size:13px;word-break:break-all;"><?php echo esc_url( $url ); ?></code>
			<p class="description"><?php esc_html_e( 'Open this URL on your kitchen tablet or counter screen. No login required.', 'lafka-plugin' ); ?></p>
			<p class="description"><?php esc_html_e( 'For better security, use "Regenerate Token (secure)" below to switch to hash-at-rest storage (the URL is then shown only once).', 'lafka-plugin' ); ?></p>
			<?php
		}

		$this->render_token_activity();
	}

	/**
	 * Surface the last-seen IP + time for the access token (anomaly detection).
	 */
	private function render_token_activity() {
		$activity = get_option( 'lafka_kds_token_activity', array() );
		if ( ! is_array( $activity ) || empty( $activity['time'] ) ) {
			return;
		}
		$when = wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $activity['time'] );
		$ip   = ! empty( $activity['ip'] ) ? $activity['ip'] : __( 'unknown IP', 'lafka-plugin' );
		?>
		<p class="description">
			<?php
			/* translators: 1: date/time of last access, 2: IP address. */
			printf( esc_html__( 'Last accessed: %1$s from %2$s', 'lafka-plugin' ), esc_html( $when ), esc_html( $ip ) );
			?>
		</p>
		<?php
	}

	public function render_pickup_times_field() {
		$options = Lafka_Kitchen_Display::get_options();
		?>
		<input type="text" id="lafka_kds_pickup_times" name="lafka_kds_options[pickup_times]" value="<?php echo esc_attr( $options['pickup_times'] ); ?>" class="regular-text">
		<p class="description"><?php esc_html_e( 'Comma-separated list of minutes for pickup ETA preset buttons.', 'lafka-plugin' ); ?></p>
		<?php
	}

	public function render_delivery_times_field() {
		$options = Lafka_Kitchen_Display::get_options();
		?>
		<input type="text" id="lafka_kds_delivery_times" name="lafka_kds_options[delivery_times]" value="<?php echo esc_attr( $options['delivery_times'] ); ?>" class="regular-text">
		<p class="description"><?php esc_html_e( 'Comma-separated list of minutes for delivery ETA preset buttons.', 'lafka-plugin' ); ?></p>
		<?php
	}

	public function render_sound_field() {
		$options = Lafka_Kitchen_Display::get_options();
		?>
		<label>
			<input type="checkbox" id="lafka_kds_sound" name="lafka_kds_options[sound_enabled]" value="1" <?php checked( $options['sound_enabled'], '1' ); ?>>
			<?php esc_html_e( 'Play sound alert when new orders arrive', 'lafka-plugin' ); ?>
		</label>
		<?php
	}

	public function render_poll_interval_field() {
		$options = Lafka_Kitchen_Display::get_options();
		?>
		<input type="number" id="lafka_kds_poll_interval" name="lafka_kds_options[poll_interval]" value="<?php echo esc_attr( $options['poll_interval'] ); ?>" min="5" max="120" step="1" style="width:80px;">
		<p class="description"><?php esc_html_e( 'How often the kitchen display checks for order updates (minimum 5 seconds).', 'lafka-plugin' ); ?></p>
		<?php
	}

	public function render_customer_poll_interval_field() {
		$options = Lafka_Kitchen_Display::get_options();
		?>
		<input type="number" id="lafka_kds_customer_poll_interval" name="lafka_kds_options[customer_poll_interval]" value="<?php echo esc_attr( $options['customer_poll_interval'] ); ?>" min="10" max="120" step="1" style="width:80px;">
		<p class="description"><?php esc_html_e( 'How often the customer order status page polls for updates (minimum 10 seconds).', 'lafka-plugin' ); ?></p>
		<?php
	}

	public function render_order_notification_email_field() {
		$options = Lafka_Kitchen_Display::get_options();
		?>
		<input type="email" id="lafka_kds_order_notification_email" name="lafka_kds_options[order_notification_email]" value="<?php echo esc_attr( $options['order_notification_email'] ); ?>" class="regular-text" placeholder="<?php esc_attr_e( 'e.g. manager@yourstore.com', 'lafka-plugin' ); ?>">
		<p class="description"><?php esc_html_e( 'This email address will receive the WooCommerce "New Order" notification whenever an order is placed. Leave blank to disable. Status-change emails (accepted, preparing, ready, rejected) are sent only to the customer.', 'lafka-plugin' ); ?></p>
		<?php
	}

	/**
	 * Render the admin page.
	 */
	public function render_page() {
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Lafka Kitchen Display', 'lafka-plugin' ); ?></h1>
			<?php settings_errors( 'lafka_kds' ); ?>
			<form method="post" action="options.php">
				<?php
				settings_fields( 'lafka_kds' );
				do_settings_sections( 'lafka_kds' );
				submit_button();
				?>
			</form>
			<hr>
			<h2><?php esc_html_e( 'Access Token', 'lafka-plugin' ); ?></h2>
			<form method="post">
				<?php wp_nonce_field( 'lafka_kds_regenerate_token' ); ?>
				<p>
					<button type="submit" name="lafka_kds_regenerate_token_secure" value="1" class="button button-primary" onclick="return confirm('<?php esc_attr_e( 'This will invalidate the current URL and show the new one only once. Continue?', 'lafka-plugin' ); ?>');">
						<?php esc_html_e( 'Regenerate Token (secure)', 'lafka-plugin' ); ?>
					</button>
					<span class="description"><?php esc_html_e( 'Recommended. Stores only a hash of the token, so a database/backup leak cannot reuse it; the new URL is shown once. Invalidates the old URL.', 'lafka-plugin' ); ?></span>
				</p>
				<p>
					<button type="submit" name="lafka_kds_regenerate_token" value="1" class="button button-secondary" onclick="return confirm('<?php esc_attr_e( 'This will invalidate the current URL. Continue?', 'lafka-plugin' ); ?>');">
						<?php esc_html_e( 'Regenerate Token (legacy)', 'lafka-plugin' ); ?>
					</button>
					<span class="description"><?php esc_html_e( 'Generates a new plaintext token whose URL stays visible on this page. Invalidates the old one.', 'lafka-plugin' ); ?></span>
				</p>
			</form>
		</div>
		<?php
	}
}
