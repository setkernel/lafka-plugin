<?php
defined( 'ABSPATH' ) || exit;

/**
 * Widget to display contact information
 *
 * @author aatanasov
 */
class LafkaContactsWidget extends WP_Widget {

	public function __construct() {
		$widget_ops = array('description' => esc_html__('Shows contact details', 'lafka-plugin'));
		parent::__construct('lafka_contacts_widget', 'Lafka Contacts details', $widget_ops);
	}

	public function widget($args, $instance) {
		extract($args);
		$title = apply_filters('widget_title', $instance['title']);

		echo wp_kses_post($before_widget);
		if (!empty($title))
			echo wp_kses_post($before_title . $title . $after_title);

		if (!empty($instance['worktime'])):
			?>
			<span class="footer_time"><?php echo esc_attr($instance['worktime']) ?></span>
		<?php endif;
		if (!empty($instance['address'])):
			?>
			<span class="footer_address"><?php echo esc_attr($instance['address']) ?></span>
		<?php endif;
		if (!empty($instance['phone'])):
			?>
			<span class="footer_phone"><?php echo esc_attr($instance['phone']) ?></span>
		<?php endif;
		if (!empty($instance['fax'])):
			?>
			<span class="footer_fax"><?php echo esc_attr($instance['fax']) ?></span>
		<?php endif;
		if (!empty($instance['email'])):
			?>
			<?php if (is_email($instance['email'])): ?>
				<span class="footer_mail"><a href="mailto:<?php echo esc_attr($instance['email']) ?>"><?php echo esc_attr($instance['email']) ?></a></span>
			<?php else: ?>
				<span class="footer_mail"><?php echo esc_attr($instance['email']) ?></span>
			<?php endif; ?>
		<?php
		endif;

		echo wp_kses_post($after_widget);
	}

	public function form($instance) {
		// Defaults
		$defaults = array(
				'title' => esc_html__('Have a Question?', 'lafka-plugin'),
				'worktime' => '',
				'address' => '',
				'phone' => '',
				'fax' => '',
				'email' => ''
		);

		$instance = wp_parse_args((array) $instance, $defaults);
		?>
		<p>
			<label for="<?php echo esc_attr($this->get_field_id('title')); ?>"><?php esc_html_e('Title:', 'lafka-plugin'); ?></label>
			<input class="widefat" id="<?php echo esc_attr($this->get_field_id('title')); ?>" name="<?php echo esc_attr($this->get_field_name('title')); ?>" type="text" value="<?php echo esc_attr($instance['title']); ?>" />
		</p>
		<p>
			<label for="<?php echo esc_attr($this->get_field_id('worktime')); ?>"><?php esc_html_e('Store working time:', 'lafka-plugin'); ?></label>
			<input class="widefat" id="<?php echo esc_attr($this->get_field_id('worktime')); ?>" name="<?php echo esc_attr($this->get_field_name('worktime')); ?>" type="text" value="<?php echo esc_attr($instance['worktime']); ?>" />
		</p>
		<p>
			<label for="<?php echo esc_attr($this->get_field_id('address')); ?>"><?php esc_html_e('Address:', 'lafka-plugin'); ?></label>
			<input class="widefat" id="<?php echo esc_attr($this->get_field_id('address')); ?>" name="<?php echo esc_attr($this->get_field_name('address')); ?>" type="text" value="<?php echo esc_attr($instance['address']); ?>" />
		</p>
		<p>
			<label for="<?php echo esc_attr($this->get_field_id('phone')); ?>"><?php esc_html_e('Phone number:', 'lafka-plugin'); ?></label>
			<input class="widefat" id="<?php echo esc_attr($this->get_field_id('phone')); ?>" name="<?php echo esc_attr($this->get_field_name('phone')); ?>" type="text" value="<?php echo esc_attr($instance['phone']); ?>" />
		</p>
		<p>
			<label for="<?php echo esc_attr($this->get_field_id('fax')); ?>"><?php esc_html_e('Fax number:', 'lafka-plugin'); ?></label>
			<input class="widefat" id="<?php echo esc_attr($this->get_field_id('fax')); ?>" name="<?php echo esc_attr($this->get_field_name('fax')); ?>" type="text" value="<?php echo esc_attr($instance['fax']); ?>" />
		</p>
		<p>
			<label for="<?php echo esc_attr($this->get_field_id('email')); ?>"><?php esc_html_e('Email address:', 'lafka-plugin'); ?></label>
			<input class="widefat" id="<?php echo esc_attr($this->get_field_id('email')); ?>" name="<?php echo esc_attr($this->get_field_name('email')); ?>" type="text" value="<?php echo esc_attr($instance['email']); ?>" />
		</p>
		<?php
	}

	public function update($new_instance, $old_instance) {
		$instance = $old_instance;
		$instance['title'] = strip_tags($new_instance['title']);
		$instance['worktime'] = strip_tags($new_instance['worktime']);
		$instance['address'] = strip_tags($new_instance['address']);
		$instance['phone'] = strip_tags($new_instance['phone']);
		$instance['fax'] = strip_tags($new_instance['fax']);
		$instance['email'] = strip_tags($new_instance['email']);

		return $instance;
	}

}

add_action('widgets_init', 'lafka_register_lafka_contacts_widget');
if (!function_exists('lafka_register_lafka_contacts_widget')) {

	function lafka_register_lafka_contacts_widget() {
		register_widget('LafkaContactsWidget');
	}

}
?>
