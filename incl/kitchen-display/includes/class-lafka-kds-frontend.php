<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lafka_KDS_Frontend {

	public function __construct() {
		add_action( 'init', array( $this, 'add_endpoint' ) );
		add_action( 'init', array( $this, 'maybe_flush_rewrite' ) );
		add_action( 'template_redirect', array( $this, 'handle_request' ) );
	}

	/**
	 * Register the kitchen-display rewrite endpoint.
	 */
	public function add_endpoint() {
		add_rewrite_rule(
			'^kitchen-display/([^/]+)/?$',
			'index.php?lafka_kds_token=$matches[1]',
			'top'
		);
		add_filter( 'query_vars', array( $this, 'add_query_var' ) );
	}

	public function add_query_var( $vars ) {
		$vars[] = 'lafka_kds_token';

		return $vars;
	}

	/**
	 * Flush rewrite rules if needed (after settings save).
	 */
	public function maybe_flush_rewrite() {
		if ( get_transient( 'lafka_kds_flush_rewrite' ) ) {
			delete_transient( 'lafka_kds_flush_rewrite' );
			flush_rewrite_rules();
		}
	}

	/**
	 * Handle the standalone KDS page request.
	 */
	public function handle_request() {
		$token = get_query_var( 'lafka_kds_token' );
		if ( empty( $token ) ) {
			return;
		}

		$options = Lafka_Kitchen_Display::get_options();
		if ( empty( $options['token'] ) || ! hash_equals( $options['token'], $token ) ) {
			status_header( 403 );
			echo 'Access denied.';
			exit;
		}

		$this->render_page( $options );
		exit;
	}

	/**
	 * Render the standalone KDS HTML page.
	 */
	private function render_page( $options ) {
		$nonce           = wp_create_nonce( 'lafka_kds_nonce' );
		$ajax_url        = admin_url( 'admin-ajax.php' );
		$css_url         = plugins_url( '../assets/css/lafka-kds.css', __FILE__ );
		$js_url          = plugins_url( '../assets/js/lafka-kds.js', __FILE__ );
		$sound_url       = plugins_url( '../assets/sounds/new-order.mp3', __FILE__ );
		$site_name       = get_bloginfo( 'name' );
		$pickup_times    = array_map( 'absint', array_filter( explode( ',', $options['pickup_times'] ) ) );
		$delivery_times  = array_map( 'absint', array_filter( explode( ',', $options['delivery_times'] ) ) );

		$config = array(
			'ajaxUrl'       => $ajax_url,
			'nonce'         => $nonce,
			'token'         => $options['token'],
			'pollInterval'  => (int) $options['poll_interval'] * 1000,
			'soundEnabled'  => $options['sound_enabled'] === '1',
			'soundUrl'      => $sound_url,
			'pickupTimes'   => $pickup_times,
			'deliveryTimes' => $delivery_times,
			'i18n'          => array(
				'newOrders'    => __( 'New Orders', 'lafka-plugin' ),
				'accepted'     => __( 'Accepted', 'lafka-plugin' ),
				'preparing'    => __( 'Preparing', 'lafka-plugin' ),
				'ready'        => __( 'Ready', 'lafka-plugin' ),
				'accept'       => __( 'Accept', 'lafka-plugin' ),
				'startPrep'    => __( 'Start Preparing', 'lafka-plugin' ),
				'markReady'    => __( 'Mark Ready', 'lafka-plugin' ),
				'complete'     => __( 'Complete', 'lafka-plugin' ),
				'setEta'       => __( 'Set ETA', 'lafka-plugin' ),
				'pickup'       => __( 'Pickup', 'lafka-plugin' ),
				'delivery'     => __( 'Delivery', 'lafka-plugin' ),
				'paidOnline'   => __( 'Paid Online', 'lafka-plugin' ),
				'cod'          => __( 'Cash on Delivery', 'lafka-plugin' ),
				'overdue'      => __( 'OVERDUE', 'lafka-plugin' ),
				'noOrders'     => __( 'No orders', 'lafka-plugin' ),
				'min'          => __( 'min', 'lafka-plugin' ),
				'customMin'    => __( 'Custom (minutes)', 'lafka-plugin' ),
				'confirm'      => __( 'Confirm', 'lafka-plugin' ),
				'cancel'       => __( 'Cancel', 'lafka-plugin' ),
				'clickSound'   => __( 'Click anywhere to enable sounds', 'lafka-plugin' ),
				'order'        => __( 'Order', 'lafka-plugin' ),
				'scheduled'    => __( 'Scheduled', 'lafka-plugin' ),
				'note'         => __( 'Note', 'lafka-plugin' ),
				'etaLabel'     => __( 'ETA', 'lafka-plugin' ),
				'elapsed'      => __( 'ago', 'lafka-plugin' ),
			),
		);

		?>
<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
	<title><?php echo esc_html( $site_name ); ?> &mdash; <?php esc_html_e( 'Kitchen Display', 'lafka-plugin' ); ?></title>
	<link rel="stylesheet" href="<?php echo esc_url( $css_url ); ?>">
</head>
<body>
	<header class="kds-header">
		<div class="kds-header-left">
			<h1><?php echo esc_html( $site_name ); ?></h1>
		</div>
		<div class="kds-header-right">
			<span class="kds-clock" id="kds-clock"></span>
			<button class="kds-fullscreen-btn" id="kds-fullscreen" title="<?php esc_attr_e( 'Fullscreen', 'lafka-plugin' ); ?>">&#x26F6;</button>
		</div>
	</header>

	<div class="kds-board" id="kds-board">
		<div class="kds-column" data-status="processing">
			<div class="kds-column-header kds-status-processing">
				<span class="kds-column-title"><?php esc_html_e( 'New Orders', 'lafka-plugin' ); ?></span>
				<span class="kds-column-count" id="count-processing">0</span>
			</div>
			<div class="kds-column-body" id="col-processing"></div>
		</div>
		<div class="kds-column" data-status="accepted">
			<div class="kds-column-header kds-status-accepted">
				<span class="kds-column-title"><?php esc_html_e( 'Accepted', 'lafka-plugin' ); ?></span>
				<span class="kds-column-count" id="count-accepted">0</span>
			</div>
			<div class="kds-column-body" id="col-accepted"></div>
		</div>
		<div class="kds-column" data-status="preparing">
			<div class="kds-column-header kds-status-preparing">
				<span class="kds-column-title"><?php esc_html_e( 'Preparing', 'lafka-plugin' ); ?></span>
				<span class="kds-column-count" id="count-preparing">0</span>
			</div>
			<div class="kds-column-body" id="col-preparing"></div>
		</div>
		<div class="kds-column" data-status="ready">
			<div class="kds-column-header kds-status-ready">
				<span class="kds-column-title"><?php esc_html_e( 'Ready', 'lafka-plugin' ); ?></span>
				<span class="kds-column-count" id="count-ready">0</span>
			</div>
			<div class="kds-column-body" id="col-ready"></div>
		</div>
	</div>

	<!-- Sound overlay -->
	<div class="kds-sound-overlay" id="kds-sound-overlay">
		<div class="kds-sound-overlay-content">
			<span>&#128264;</span>
			<p><?php esc_html_e( 'Click anywhere to enable sounds', 'lafka-plugin' ); ?></p>
		</div>
	</div>

	<!-- ETA Modal -->
	<div class="kds-modal-backdrop" id="kds-eta-modal" style="display:none;">
		<div class="kds-modal">
			<div class="kds-modal-header">
				<h2><?php esc_html_e( 'Set ETA', 'lafka-plugin' ); ?> &mdash; <span id="kds-eta-order-num"></span></h2>
			</div>
			<div class="kds-modal-body">
				<div class="kds-eta-presets" id="kds-eta-presets"></div>
				<div class="kds-eta-custom">
					<label><?php esc_html_e( 'Custom (minutes)', 'lafka-plugin' ); ?></label>
					<input type="number" id="kds-eta-custom-input" min="1" max="999" placeholder="45">
				</div>
			</div>
			<div class="kds-modal-footer">
				<button class="kds-btn kds-btn-secondary" id="kds-eta-cancel"><?php esc_html_e( 'Cancel', 'lafka-plugin' ); ?></button>
				<button class="kds-btn kds-btn-primary" id="kds-eta-confirm"><?php esc_html_e( 'Confirm', 'lafka-plugin' ); ?></button>
			</div>
		</div>
	</div>

	<script>var LAFKA_KDS = <?php echo wp_json_encode( $config ); ?>;</script>
	<script src="<?php echo esc_url( $js_url ); ?>"></script>
</body>
</html>
		<?php
	}
}
