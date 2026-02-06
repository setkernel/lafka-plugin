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
	}

	/**
	 * Sanitize options on save.
	 */
	public function sanitize_options( $input ) {
		$current = Lafka_Kitchen_Display::get_options();
		$output  = array();

		// Preserve existing token, or generate one if missing
		$output['token'] = ! empty( $current['token'] ) ? $current['token'] : wp_generate_password( 32, false );

		$output['pickup_times']   = isset( $input['pickup_times'] )
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
	 * Handle token regeneration.
	 */
	public function handle_regenerate_token() {
		if ( ! isset( $_POST['lafka_kds_regenerate_token'] ) ) {
			return;
		}

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}

		check_admin_referer( 'lafka_kds_regenerate_token' );

		$options          = Lafka_Kitchen_Display::get_options();
		$options['token'] = wp_generate_password( 32, false );
		update_option( 'lafka_kds_options', $options );

		add_settings_error( 'lafka_kds', 'token_regenerated', __( 'Access token regenerated.', 'lafka-plugin' ), 'updated' );
	}

	public function render_url_field() {
		$options = Lafka_Kitchen_Display::get_options();
		if ( ! empty( $options['token'] ) ) {
			$url = home_url( '/kitchen-display/' . $options['token'] . '/' );
			?>
			<code style="display:inline-block;padding:6px 10px;background:#f0f0f1;font-size:13px;word-break:break-all;"><?php echo esc_url( $url ); ?></code>
			<p class="description"><?php esc_html_e( 'Open this URL on your kitchen tablet or counter screen. No login required.', 'lafka-plugin' ); ?></p>
			<?php
		} else {
			?>
			<p><em><?php esc_html_e( 'Click "Save Changes" to generate the KDS access URL.', 'lafka-plugin' ); ?></em></p>
			<?php
		}
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
					<button type="submit" name="lafka_kds_regenerate_token" value="1" class="button button-secondary" onclick="return confirm('<?php esc_attr_e( 'This will invalidate the current URL. Continue?', 'lafka-plugin' ); ?>');">
						<?php esc_html_e( 'Regenerate Token', 'lafka-plugin' ); ?>
					</button>
					<span class="description"><?php esc_html_e( 'Generates a new secret URL and invalidates the old one.', 'lafka-plugin' ); ?></span>
				</p>
			</form>
		</div>
		<?php
	}
}
