<?php
defined( 'ABSPATH' ) || exit;
/**
 * Widget to show the payment options on the shop.
 *
 * @author aatanasov
 */
class LafkaPaymentOptionsWidget extends WP_Widget {
	public function __construct() {
		$widget_ops = array( 'description' => esc_html__( 'Display a list of available payment methods and seal code', 'lafka-plugin' ) );
		parent::__construct( 'lafka_payment_options_widget', 'Lafka Payment Options', $widget_ops );
	}

	public function widget( $args, $instance ) {
		extract( $args );
		$title = apply_filters( 'widget_title', $instance['title'] );

		echo wp_kses_post( $before_widget );
		if ( ! empty( $title ) ) {
				echo wp_kses_post( $before_title . $title . $after_title );
		}

		foreach ( $instance as $field_name => $value ) {
			if ( ! in_array( $field_name, array( 'title', 'seal' ) ) && $value ) : ?>
				<div class="<?php echo esc_attr( $field_name ); ?>_icon cards_icon"></div>
				<?php
			endif;
		}

		if ( trim( $instance['seal'] != '' ) ) :
			?>
			<div id="seals"><?php echo wp_kses_post( $instance['seal'] ); ?></div>
			<?php
		endif;
		echo wp_kses_post( $after_widget );
	}

	public function form( $instance ) {
		//Defaults
		$defaults = array(
			'title'            => esc_html__( 'Payment Options', 'lafka-plugin' ),
			'american-express' => true,
			'cirrus'           => true,
			'maestro'          => true,
			'mastercard'       => true,
			'visa'             => true,
			'cash-on-delivery' => true,
			'direct-debit'     => true,
			'paypal'           => true,
			'cheque'           => true,
			'google-checkout'  => false,
			'twocheckout'      => false,
			'delta'            => false,
			'discover'         => false,
			'moneybookers'     => false,
			'solo'             => false,
			'switch'           => false,
			'western-union'    => false,
			'sagepay'          => false,
			'ideal'            => false,
			'seal'             => '',
		);

		$labels = array(
			'american-express' => esc_html__( 'American Express', 'lafka-plugin' ),
			'cirrus'           => esc_html__( 'Cirrus', 'lafka-plugin' ),
			'maestro'          => esc_html__( 'Maestro', 'lafka-plugin' ),
			'mastercard'       => esc_html__( 'Mastercard', 'lafka-plugin' ),
			'visa'             => esc_html__( 'Visa', 'lafka-plugin' ),
			'cash-on-delivery' => esc_html__( 'Cash on delivery', 'lafka-plugin' ),
			'direct-debit'     => esc_html__( 'Direct Debit', 'lafka-plugin' ),
			'paypal'           => esc_html__( 'PayPal', 'lafka-plugin' ),
			'cheque'           => esc_html__( 'Cheque', 'lafka-plugin' ),
			'google-checkout'  => esc_html__( 'Google checkout', 'lafka-plugin' ),
			'twocheckout'      => esc_html__( '2Checkout', 'lafka-plugin' ),
			'delta'            => esc_html__( 'Delta', 'lafka-plugin' ),
			'discover'         => esc_html__( 'Discover', 'lafka-plugin' ),
			'moneybookers'     => esc_html__( 'Moneybookers', 'lafka-plugin' ),
			'solo'             => esc_html__( 'SOLO', 'lafka-plugin' ),
			'switch'           => esc_html__( 'Switch', 'lafka-plugin' ),
			'western-union'    => esc_html__( 'Western Union', 'lafka-plugin' ),
			'sagepay'          => esc_html__( 'Sage Pay', 'lafka-plugin' ),
			'ideal'            => esc_html__( 'iDEAL ', 'lafka-plugin' ),
		);

		$instance = wp_parse_args( (array) $instance, $defaults );
		?>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"><?php esc_html_e( 'Title:', 'lafka-plugin' ); ?></label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>" type="text" value="<?php echo esc_attr( $instance['title'] ); ?>" />
		</p>
		<?php foreach ( $instance as $field_name => $value ) : ?>
			<?php if ( ! in_array( $field_name, array( 'title', 'seal' ) ) ) : ?>
				<p>
					<input class="checkbox" type="checkbox" <?php checked( $value ); ?> id="<?php echo esc_attr( $this->get_field_id( $field_name ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( $field_name ) ); ?>" value="on" />&nbsp;
					<label for="<?php echo esc_attr( $this->get_field_id( $field_name ) ); ?>"><?php echo esc_attr( $labels[ $field_name ] ); ?></label>
				</p>
			<?php endif; ?>
		<?php endforeach; ?>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'seal' ) ); ?>"><?php esc_html_e( 'Seal code:', 'lafka-plugin' ); ?></label>
			<textarea class="widefat" rows="6" cols="10" id="<?php echo esc_attr( $this->get_field_id( 'seal' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'seal' ) ); ?>"><?php echo wp_kses_post( $instance['seal'] ); ?></textarea>
		</p>
		<?php
	}

	public function update( $new_instance, $old_instance ) {
		$instance                     = $old_instance;
		$instance['title']            = strip_tags( $new_instance['title'] );
		$instance['american-express'] = ! empty( $new_instance['american-express'] );
		$instance['cirrus']           = ! empty( $new_instance['cirrus'] );
		$instance['maestro']          = ! empty( $new_instance['maestro'] );
		$instance['mastercard']       = ! empty( $new_instance['mastercard'] );
		$instance['visa']             = ! empty( $new_instance['visa'] );
		$instance['cash-on-delivery'] = ! empty( $new_instance['cash-on-delivery'] );
		$instance['direct-debit']     = ! empty( $new_instance['direct-debit'] );
		$instance['paypal']           = ! empty( $new_instance['paypal'] );
		$instance['cheque']           = ! empty( $new_instance['cheque'] );
		$instance['google-checkout']  = ! empty( $new_instance['google-checkout'] );
		$instance['twocheckout']      = ! empty( $new_instance['twocheckout'] );
		$instance['delta']            = ! empty( $new_instance['delta'] );
		$instance['discover']         = ! empty( $new_instance['discover'] );
		$instance['moneybookers']     = ! empty( $new_instance['moneybookers'] );
		$instance['solo']             = ! empty( $new_instance['solo'] );
		$instance['switch']           = ! empty( $new_instance['switch'] );
		$instance['western-union']    = ! empty( $new_instance['western-union'] );
		$instance['sagepay']          = ! empty( $new_instance['sagepay'] );
		$instance['ideal']            = ! empty( $new_instance['ideal'] );
		$instance['seal']             = wp_kses_post( $new_instance['seal'] );

		return $instance;
	}
}

add_action( 'widgets_init', 'lafka_register_lafka_payment_widget' );
if ( ! function_exists( 'lafka_register_lafka_payment_widget' ) ) {
	function lafka_register_lafka_payment_widget() {
		register_widget( 'LafkaPaymentOptionsWidget' );
	}
}
?>
