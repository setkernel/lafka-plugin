<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Lafka_KDS_Customer_View {

	public function __construct() {
		add_action( 'woocommerce_view_order', array( $this, 'render_progress_bar' ), 1 );
		add_action( 'woocommerce_thankyou', array( $this, 'render_progress_bar' ), 1 );
		add_action( 'wp_enqueue_scripts', array( $this, 'maybe_enqueue_assets' ) );
	}

	/**
	 * Conditionally enqueue assets on order view / thankyou pages.
	 */
	public function maybe_enqueue_assets() {
		if ( ! is_wc_endpoint_url( 'view-order' ) && ! is_wc_endpoint_url( 'order-received' ) ) {
			return;
		}

		$css_url = plugins_url( '../assets/css/lafka-kds-customer.css', __FILE__ );
		$js_url  = plugins_url( '../assets/js/lafka-kds-customer.js', __FILE__ );

		$css_ver = filemtime( dirname( __DIR__ ) . '/assets/css/lafka-kds-customer.css' );
		$js_ver  = filemtime( dirname( __DIR__ ) . '/assets/js/lafka-kds-customer.js' );

		wp_enqueue_style( 'lafka-kds-customer', $css_url, array(), $css_ver );
		wp_enqueue_script( 'lafka-kds-customer', $js_url, array(), $js_ver, true );
	}

	/**
	 * Render the progress bar above order details.
	 */
	public function render_progress_bar( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}

		$status = $order->get_status();

		// Only show for statuses in the KDS workflow
		$kds_statuses = array( 'processing', 'accepted', 'preparing', 'ready', 'completed', 'rejected' );
		if ( ! in_array( $status, $kds_statuses, true ) ) {
			return;
		}

		// Rejected orders get a special message instead of progress bar
		if ( 'rejected' === $status ) {
			?>
			<div class="lafka-kds-progress lafka-kds-rejected">
				<p style="text-align:center;color:#e94560;font-weight:bold;margin:0;">
					<?php esc_html_e( 'This order has been cancelled. Please contact us for more information.', 'lafka-plugin' ); ?>
				</p>
			</div>
			<?php
			return;
		}

		$order_type = Lafka_Kitchen_Display::get_order_type( $order );
		$is_pickup  = ( 'pickup' === $order_type );

		$steps = array(
			'processing' => __( 'Received', 'lafka-plugin' ),
			'accepted'   => __( 'Accepted', 'lafka-plugin' ),
			'preparing'  => __( 'Preparing', 'lafka-plugin' ),
			'ready'      => $is_pickup ? __( 'Ready for Pickup', 'lafka-plugin' ) : __( 'Out for Delivery', 'lafka-plugin' ),
			'completed'  => __( 'Complete', 'lafka-plugin' ),
		);

		$step_keys    = array_keys( $steps );
		$current_idx  = array_search( $status, $step_keys, true );
		$eta          = $order->get_meta( '_lafka_kds_eta' );
		$options      = Lafka_Kitchen_Display::get_options();

		?>
		<div class="lafka-kds-progress" id="lafka-kds-progress" data-order-id="<?php echo esc_attr( $order_id ); ?>" data-status="<?php echo esc_attr( $status ); ?>">
			<div class="lafka-kds-steps">
				<?php foreach ( $steps as $step_key => $step_label ) :
					$step_idx = array_search( $step_key, $step_keys, true );
					$class    = 'lafka-kds-step';
					if ( $step_idx < $current_idx ) {
						$class .= ' lafka-kds-step-done';
					} elseif ( $step_idx === $current_idx ) {
						$class .= ' lafka-kds-step-active';
					}
					?>
					<div class="<?php echo esc_attr( $class ); ?>" data-step="<?php echo esc_attr( $step_key ); ?>">
						<div class="lafka-kds-dot"></div>
						<span class="lafka-kds-label"><?php echo esc_html( $step_label ); ?></span>
					</div>
				<?php endforeach; ?>
			</div>
			<?php if ( $eta && in_array( $status, array( 'accepted', 'preparing' ), true ) ) : ?>
				<div class="lafka-kds-eta" id="lafka-kds-eta" data-eta="<?php echo esc_attr( $eta ); ?>">
					<span class="lafka-kds-eta-label"><?php esc_html_e( 'Estimated time:', 'lafka-plugin' ); ?></span>
					<span class="lafka-kds-eta-value" id="lafka-kds-eta-value"></span>
				</div>
			<?php endif; ?>
		</div>
		<?php

		// Only poll if not yet completed
		if ( 'completed' !== $status ) :
			$nonce = wp_create_nonce( 'lafka_kds_customer_nonce' );
			?>
			<script>
			var LAFKA_KDS_CUSTOMER = {
				ajaxUrl: <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>,
				nonce: <?php echo wp_json_encode( $nonce ); ?>,
				orderId: <?php echo (int) $order_id; ?>,
				orderKey: <?php echo wp_json_encode( $order->get_order_key() ); ?>,
				orderType: <?php echo wp_json_encode( $order_type ); ?>,
				pollInterval: <?php echo (int) $options['customer_poll_interval'] * 1000; ?>,
				i18n: {
					received:      <?php echo wp_json_encode( __( 'Received', 'lafka-plugin' ) ); ?>,
					accepted:      <?php echo wp_json_encode( __( 'Accepted', 'lafka-plugin' ) ); ?>,
					preparing:     <?php echo wp_json_encode( __( 'Preparing', 'lafka-plugin' ) ); ?>,
					readyPickup:   <?php echo wp_json_encode( __( 'Ready for Pickup', 'lafka-plugin' ) ); ?>,
					readyDelivery: <?php echo wp_json_encode( __( 'Out for Delivery', 'lafka-plugin' ) ); ?>,
					complete:      <?php echo wp_json_encode( __( 'Complete', 'lafka-plugin' ) ); ?>,
					estimated:     <?php echo wp_json_encode( __( 'Estimated time:', 'lafka-plugin' ) ); ?>,
					delayed:       <?php echo wp_json_encode( __( 'Delayed — taking longer than expected', 'lafka-plugin' ) ); ?>,
					rejected:      <?php echo wp_json_encode( __( 'This order has been cancelled. Please contact us for more information.', 'lafka-plugin' ) ); ?>
				}
			};
			</script>
		<?php
		endif;
	}
}
