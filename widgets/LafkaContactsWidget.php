<?php
defined( 'ABSPATH' ) || exit;

/**
 * Widget to display contact information.
 *
 * v9.7.22: defaults all NAP fields to lafka_get_restaurant_info() — the
 * canonical resolver introduced in v9.7.6 that flows from WooCommerce →
 * Settings → General by default and is overridable via the Lafka Customizer
 * panel "Lafka — Restaurant Information". Operators no longer need to enter
 * address/phone/email three places.
 *
 * Per-widget overrides are still supported via the form fields — useful when
 * a sidebar contact block needs a different presentation (e.g. a different
 * formatted phone for marketing) than the canonical NAP that schema/checkout
 * uses.
 *
 * @author aatanasov
 */
class LafkaContactsWidget extends WP_Widget {

	public function __construct() {
		$widget_ops = array( 'description' => esc_html__( 'Shows contact details. Defaults to your WooCommerce store settings + Lafka Customizer NAP — only fill the fields below if you want this widget to display different values.', 'lafka-plugin' ) );
		parent::__construct( 'lafka_contacts_widget', 'Lafka Contacts details', $widget_ops );
	}

	/**
	 * Resolve a single NAP field, preferring the widget instance value over
	 * the canonical resolver. Empty instance values fall through to the
	 * resolver so each field independently inherits or overrides.
	 *
	 * @param array  $instance Widget instance.
	 * @param string $field    Instance key (worktime, address, phone, fax, email).
	 * @return string
	 */
	private function resolve( array $instance, string $field ): string {
		// Per-widget override wins.
		if ( isset( $instance[ $field ] ) && '' !== trim( (string) $instance[ $field ] ) ) {
			return (string) $instance[ $field ];
		}

		// Fall back to canonical NAP resolver (v9.7.6).
		if ( ! function_exists( 'lafka_get_restaurant_info' ) ) {
			return '';
		}
		$info = lafka_get_restaurant_info();

		switch ( $field ) {
			case 'address':
				return (string) ( $info['address_short'] ?? '' );
			case 'phone':
				return (string) ( $info['phone_display'] ?? ( $info['phone_e164'] ?? '' ) );
			case 'email':
				return (string) ( $info['email'] ?? '' );
			case 'worktime':
				// `hours` map is per-day display strings; widget wants a single
				// summary line. Operator who wants this in the widget should
				// fill the override field — no canonical "today's hours"
				// fallback that wouldn't surprise the operator.
				return '';
			case 'fax':
				// Fax isn't in the resolver — operator must fill the override.
				return '';
		}
		return '';
	}

	public function widget( $args, $instance ) {
		$instance = (array) $instance;
		$title    = apply_filters( 'widget_title', $instance['title'] ?? '' );

		$worktime = $this->resolve( $instance, 'worktime' );
		$address  = $this->resolve( $instance, 'address' );
		$phone    = $this->resolve( $instance, 'phone' );
		$fax      = $this->resolve( $instance, 'fax' );
		$email    = $this->resolve( $instance, 'email' );

		// Tap-to-call link uses the e164 form when available so mobile dialers
		// parse it correctly (display value can include spaces/dashes).
		$tel_e164 = '';
		if ( '' !== $phone && function_exists( 'lafka_get_restaurant_info' ) ) {
			$info     = lafka_get_restaurant_info();
			$tel_e164 = (string) ( $info['phone_e164'] ?? '' );
		}

		echo wp_kses_post( $args['before_widget'] );
		if ( ! empty( $title ) ) {
			echo wp_kses_post( $args['before_title'] . $title . $args['after_title'] );
		}

		if ( '' !== $worktime ) {
			echo '<span class="footer_time">' . esc_html( $worktime ) . '</span>';
		}
		if ( '' !== $address ) {
			echo '<span class="footer_address">' . esc_html( $address ) . '</span>';
		}
		if ( '' !== $phone ) {
			if ( '' !== $tel_e164 ) {
				printf(
					'<span class="footer_phone"><a href="tel:%s">%s</a></span>',
					esc_attr( $tel_e164 ),
					esc_html( $phone )
				);
			} else {
				echo '<span class="footer_phone">' . esc_html( $phone ) . '</span>';
			}
		}
		if ( '' !== $fax ) {
			echo '<span class="footer_fax">' . esc_html( $fax ) . '</span>';
		}
		if ( '' !== $email ) {
			if ( is_email( $email ) ) {
				printf(
					'<span class="footer_mail"><a href="mailto:%s">%s</a></span>',
					esc_attr( $email ),
					esc_html( $email )
				);
			} else {
				echo '<span class="footer_mail">' . esc_html( $email ) . '</span>';
			}
		}

		echo wp_kses_post( $args['after_widget'] );
	}

	public function form( $instance ) {
		$defaults = array(
			'title'    => esc_html__( 'Have a Question?', 'lafka-plugin' ),
			'worktime' => '',
			'address'  => '',
			'phone'    => '',
			'fax'      => '',
			'email'    => '',
		);

		$instance = wp_parse_args( (array) $instance, $defaults );
		?>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>"><?php esc_html_e( 'Title:', 'lafka-plugin' ); ?></label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'title' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'title' ) ); ?>" type="text" value="<?php echo esc_attr( $instance['title'] ); ?>" />
		</p>
		<p style="background:#f0f6fc;border-left:4px solid #2271b1;padding:8px 12px;margin:12px 0;">
			<?php esc_html_e( 'Each field below leaves blank to inherit from your WooCommerce store settings + Lafka Customizer ("Restaurant Information"). Only fill in a field if you want this widget to display a different value than your canonical NAP.', 'lafka-plugin' ); ?>
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'worktime' ) ); ?>"><?php esc_html_e( 'Store working time:', 'lafka-plugin' ); ?></label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'worktime' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'worktime' ) ); ?>" type="text" value="<?php echo esc_attr( $instance['worktime'] ); ?>" />
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'address' ) ); ?>"><?php esc_html_e( 'Address:', 'lafka-plugin' ); ?></label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'address' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'address' ) ); ?>" type="text" value="<?php echo esc_attr( $instance['address'] ); ?>" />
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'phone' ) ); ?>"><?php esc_html_e( 'Phone number:', 'lafka-plugin' ); ?></label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'phone' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'phone' ) ); ?>" type="text" value="<?php echo esc_attr( $instance['phone'] ); ?>" />
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'fax' ) ); ?>"><?php esc_html_e( 'Fax number:', 'lafka-plugin' ); ?></label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'fax' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'fax' ) ); ?>" type="text" value="<?php echo esc_attr( $instance['fax'] ); ?>" />
		</p>
		<p>
			<label for="<?php echo esc_attr( $this->get_field_id( 'email' ) ); ?>"><?php esc_html_e( 'Email address:', 'lafka-plugin' ); ?></label>
			<input class="widefat" id="<?php echo esc_attr( $this->get_field_id( 'email' ) ); ?>" name="<?php echo esc_attr( $this->get_field_name( 'email' ) ); ?>" type="text" value="<?php echo esc_attr( $instance['email'] ); ?>" />
		</p>
		<?php
	}

	public function update( $new_instance, $old_instance ) {
		// sanitize_text_field over strip_tags: also decodes entities + normalises
		// whitespace + strips line breaks. sanitize_email for the email field
		// applies the additional address-shape check.
		$instance             = $old_instance;
		$instance['title']    = isset( $new_instance['title'] ) ? sanitize_text_field( wp_unslash( $new_instance['title'] ) ) : '';
		$instance['worktime'] = isset( $new_instance['worktime'] ) ? sanitize_text_field( wp_unslash( $new_instance['worktime'] ) ) : '';
		$instance['address']  = isset( $new_instance['address'] ) ? sanitize_text_field( wp_unslash( $new_instance['address'] ) ) : '';
		$instance['phone']    = isset( $new_instance['phone'] ) ? sanitize_text_field( wp_unslash( $new_instance['phone'] ) ) : '';
		$instance['fax']      = isset( $new_instance['fax'] ) ? sanitize_text_field( wp_unslash( $new_instance['fax'] ) ) : '';
		$instance['email']    = isset( $new_instance['email'] ) ? sanitize_email( wp_unslash( $new_instance['email'] ) ) : '';

		return $instance;
	}
}

add_action( 'widgets_init', 'lafka_register_lafka_contacts_widget' );
if ( ! function_exists( 'lafka_register_lafka_contacts_widget' ) ) {

	function lafka_register_lafka_contacts_widget() {
		register_widget( 'LafkaContactsWidget' );
	}

}
