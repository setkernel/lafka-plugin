<?php
/**
 * WooCommerce → Settings → Restaurant → Text messages.
 *
 * The fields of the section, the two field types that keep secrets out of the
 * page (a saved token is never printed: the box is empty and says it is saved;
 * leave it blank to keep it, type REMOVE to clear it), and the "Send test
 * message" button, which saves the form and then sends one message.
 *
 * @package Lafka\Plugin\Notify
 * @since   10.4.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Lafka_Notify_Settings' ) ) {

	/**
	 * Settings section and test button.
	 */
	final class Lafka_Notify_Settings {

		/** Options holding a secret: no autoload, never printed. */
		const SECRETS = array( 'lafka_notify_twilio_token', 'lafka_notify_wa_token' );

		/** Name of the test button and of its number box. */
		const TEST_BUTTON = 'lafka_notify_send_test';
		const TEST_TO     = 'lafka_notify_test_to';

		/**
		 * Hook in (admin).
		 *
		 * @return void
		 */
		public static function init(): void {
			add_action( 'woocommerce_admin_field_lafka_notify_secret', array( __CLASS__, 'output_secret' ) );
			add_action( 'woocommerce_admin_field_lafka_notify_test', array( __CLASS__, 'output_test' ) );
			add_filter( 'woocommerce_save_settings_lafka_restaurant_notify', array( __CLASS__, 'save_on_test' ) );
			add_action( 'woocommerce_update_options_lafka_restaurant_notify', array( __CLASS__, 'maybe_send_test' ) );
			foreach ( self::SECRETS as $id ) {
				add_filter( 'woocommerce_admin_settings_sanitize_option_' . $id, array( __CLASS__, 'sanitize_secret' ), 10, 2 );
			}
		}

		/**
		 * The settings fields.
		 *
		 * @return array<int,array<string,mixed>>
		 */
		public static function fields(): array {
			$fields = array(
				array(
					'title' => __( 'Text messages', 'lafka-plugin' ),
					'type'  => 'title',
					'desc'  => esc_html__( 'Tell customers where their order is by text message: SMS through Twilio or a WhatsApp template message. Messages go only to customers who tick the opt-in box at checkout, and only about their own order (order updates only, no marketing; Canada\'s CASL allows these transactional messages). The opt-in appears on the classic and the block checkout once a channel below is set up.', 'lafka-plugin' ),
					'id'    => 'lafka_notify_title',
				),
				array(
					'title'   => __( 'Text messages', 'lafka-plugin' ),
					'desc'    => __( 'Turn text messages on', 'lafka-plugin' ),
					'id'      => 'lafka_notify_enabled',
					'type'    => 'checkbox',
					'default' => 'no',
				),
				array(
					'title'   => __( 'Send through', 'lafka-plugin' ),
					'id'      => 'lafka_notify_channel',
					'type'    => 'select',
					'default' => '',
					'options' => array(
						''         => __( 'Nothing (only the free WhatsApp link)', 'lafka-plugin' ),
						'twilio'   => __( 'Twilio SMS', 'lafka-plugin' ),
						'whatsapp' => __( 'WhatsApp Cloud API', 'lafka-plugin' ),
					),
				),
				array(
					'title'       => __( 'Opt-in wording', 'lafka-plugin' ),
					'desc_tip'    => __( 'Shown next to the checkout checkbox and stored on each order the customer ticks it for, with the time. Say that the messages are about the order only.', 'lafka-plugin' ),
					'id'          => 'lafka_notify_optin_text',
					'type'        => 'textarea',
					'default'     => '',
					'placeholder' => Lafka_Notify_Checkout::wording(),
					'css'         => 'width: 100%; max-width: 480px; min-height: 60px;',
				),
				array(
					'type' => 'sectionend',
					'id'   => 'lafka_notify_end',
				),
				array(
					'title' => __( 'Messages', 'lafka-plugin' ),
					'type'  => 'title',
					'desc'  => esc_html__( 'Placeholders: {name}, {order}, {restaurant}, {eta} and {track_url}. Twilio sends the text. WhatsApp only lets a business start a chat with a template Meta approved, so give the template name and the placeholders that fill its variables, in order (for example {name},{order},{track_url}).', 'lafka-plugin' ),
					'id'    => 'lafka_notify_events_title',
				),
			);

			foreach ( Lafka_Notify::events() as $key => $event ) {
				$fields[] = array(
					'title'   => $event['label'],
					'desc'    => __( 'Send this message', 'lafka-plugin' ),
					'id'      => 'lafka_notify_event_' . $key,
					'type'    => 'checkbox',
					'default' => $event['on'] ? 'yes' : 'no',
				);
				$fields[] = array(
					'title'       => __( 'Text', 'lafka-plugin' ),
					'id'          => 'lafka_notify_sms_' . $key,
					'type'        => 'textarea',
					'default'     => '',
					'placeholder' => $event['text'],
					'css'         => 'width: 100%; max-width: 480px; min-height: 52px;',
				);
				$fields[] = array(
					'title'       => __( 'WhatsApp template', 'lafka-plugin' ),
					'id'          => 'lafka_notify_wa_template_' . $key,
					'type'        => 'text',
					'default'     => '',
					'placeholder' => 'order_' . $key,
					'css'         => 'width: 240px;',
				);
				$fields[] = array(
					'title'   => __( 'WhatsApp variables', 'lafka-plugin' ),
					'id'      => 'lafka_notify_wa_vars_' . $key,
					'type'    => 'text',
					'default' => '{name},{order},{track_url}',
					'css'     => 'width: 320px;',
				);
			}

			return array_merge(
				$fields,
				array(
					array(
						'type' => 'sectionend',
						'id'   => 'lafka_notify_events_end',
					),
					array(
						'title' => __( 'Twilio SMS', 'lafka-plugin' ),
						'type'  => 'title',
						'desc'  => esc_html__( 'From your Twilio console. Use a Messaging Service SID or a From number (the service wins when both are set).', 'lafka-plugin' ),
						'id'    => 'lafka_notify_twilio_title',
					),
					array(
						'title'    => __( 'Account SID', 'lafka-plugin' ),
						'id'       => 'lafka_notify_twilio_sid',
						'type'     => 'text',
						'default'  => '',
						'autoload' => false,
						'css'      => 'width: 320px;',
					),
					array(
						'title'    => __( 'Auth token', 'lafka-plugin' ),
						'id'       => 'lafka_notify_twilio_token',
						'type'     => 'lafka_notify_secret',
						'default'  => '',
						'autoload' => false,
					),
					array(
						'title'       => __( 'From number', 'lafka-plugin' ),
						'id'          => 'lafka_notify_twilio_from',
						'type'        => 'text',
						'default'     => '',
						'autoload'    => false,
						'placeholder' => '+15555550100',
						'css'         => 'width: 200px;',
					),
					array(
						'title'    => __( 'Messaging Service SID', 'lafka-plugin' ),
						'id'       => 'lafka_notify_twilio_service',
						'type'     => 'text',
						'default'  => '',
						'autoload' => false,
						'css'      => 'width: 320px;',
					),
					array(
						'type' => 'sectionend',
						'id'   => 'lafka_notify_twilio_end',
					),
					array(
						'title' => __( 'WhatsApp Cloud API', 'lafka-plugin' ),
						'type'  => 'title',
						'desc'  => esc_html__( 'From your Meta app (WhatsApp → API setup). Use a permanent system-user access token.', 'lafka-plugin' ),
						'id'    => 'lafka_notify_wa_title',
					),
					array(
						'title'    => __( 'Phone number ID', 'lafka-plugin' ),
						'id'       => 'lafka_notify_wa_phone_id',
						'type'     => 'text',
						'default'  => '',
						'autoload' => false,
						'css'      => 'width: 240px;',
					),
					array(
						'title'    => __( 'Access token', 'lafka-plugin' ),
						'id'       => 'lafka_notify_wa_token',
						'type'     => 'lafka_notify_secret',
						'default'  => '',
						'autoload' => false,
					),
					array(
						'title'    => __( 'Template language', 'lafka-plugin' ),
						'desc_tip' => __( 'The language code your templates were approved in, for example en_US or fr.', 'lafka-plugin' ),
						'id'       => 'lafka_notify_wa_language',
						'type'     => 'text',
						'default'  => 'en_US',
						'css'      => 'width: 100px;',
					),
					array(
						'type' => 'sectionend',
						'id'   => 'lafka_notify_wa_end',
					),
					array(
						'title' => __( 'Message us on WhatsApp', 'lafka-plugin' ),
						'type'  => 'title',
						'desc'  => esc_html__( 'A free link that opens a WhatsApp chat with your restaurant phone (Restaurant → Schema & Geo). It needs no account or channel above. It appears under the order tracker and on the Contact page.', 'lafka-plugin' ),
						'id'    => 'lafka_notify_link_title',
					),
					array(
						'title'   => __( 'Show the link', 'lafka-plugin' ),
						'desc'    => __( 'Show "Message us on WhatsApp"', 'lafka-plugin' ),
						'id'      => 'lafka_notify_wa_link',
						'type'    => 'checkbox',
						'default' => 'yes',
					),
					array(
						'title'       => __( 'Link text', 'lafka-plugin' ),
						'id'          => 'lafka_notify_wa_link_label',
						'type'        => 'text',
						'default'     => '',
						'placeholder' => __( 'Message us on WhatsApp', 'lafka-plugin' ),
						'css'         => 'width: 240px;',
					),
					array(
						'type' => 'sectionend',
						'id'   => 'lafka_notify_link_end',
					),
					array(
						'title' => __( 'Test', 'lafka-plugin' ),
						'type'  => 'title',
						'id'    => 'lafka_notify_test_title',
					),
					array(
						'title'     => __( 'Send test message', 'lafka-plugin' ),
						'type'      => 'lafka_notify_test',
						'id'        => 'lafka_notify_test_row',
						'is_option' => false,
					),
					array(
						'type' => 'sectionend',
						'id'   => 'lafka_notify_test_end',
					),
				)
			);
		}

		/**
		 * A secret's row: an empty box that says whether a value is saved.
		 *
		 * @param array<string,mixed> $field Field.
		 * @return void
		 */
		public static function output_secret( $field ): void {
			$saved = '' !== (string) get_option( (string) $field['id'], '' );
			?>
			<tr valign="top">
				<th scope="row" class="titledesc"><label for="<?php echo esc_attr( $field['id'] ); ?>"><?php echo esc_html( $field['title'] ); ?></label></th>
				<td class="forminp">
					<input type="password" name="<?php echo esc_attr( $field['id'] ); ?>" id="<?php echo esc_attr( $field['id'] ); ?>" value="" autocomplete="new-password" style="width: 320px;" placeholder="<?php echo $saved ? esc_attr__( '•••••••••••• saved', 'lafka-plugin' ) : ''; ?>">
					<?php if ( $saved ) : ?>
						<p class="description"><?php esc_html_e( 'Saved. Leave blank to keep it, or type REMOVE to clear it.', 'lafka-plugin' ); ?></p>
					<?php endif; ?>
				</td>
			</tr>
			<?php
		}

		/**
		 * Keep the saved secret when the box is blank; clear it on REMOVE.
		 *
		 * @param mixed               $value  Submitted value.
		 * @param array<string,mixed> $option Field.
		 * @return string
		 */
		public static function sanitize_secret( $value, $option ): string {
			$value = trim( (string) $value );
			if ( 'REMOVE' === $value ) {
				return '';
			}
			return '' === $value ? (string) get_option( (string) $option['id'], '' ) : $value;
		}

		/**
		 * The test row: a number box and a button.
		 *
		 * @param array<string,mixed> $field Field.
		 * @return void
		 */
		public static function output_test( $field ): void {
			?>
			<tr valign="top">
				<th scope="row" class="titledesc"><label for="<?php echo esc_attr( self::TEST_TO ); ?>"><?php echo esc_html( $field['title'] ); ?></label></th>
				<td class="forminp">
					<input type="tel" name="<?php echo esc_attr( self::TEST_TO ); ?>" id="<?php echo esc_attr( self::TEST_TO ); ?>" value="" autocomplete="off" style="width: 200px;" placeholder="<?php esc_attr_e( 'Mobile number', 'lafka-plugin' ); ?>">
					<button type="submit" name="<?php echo esc_attr( self::TEST_BUTTON ); ?>" value="1" class="button"><?php esc_html_e( 'Save and send test message', 'lafka-plugin' ); ?></button>
					<p class="description"><?php esc_html_e( 'Sends one message through the channel above, with sample details. WhatsApp uses the template of the first message that has one.', 'lafka-plugin' ); ?></p>
				</td>
			</tr>
			<?php
		}

		/**
		 * The test button saves the form too (WooCommerce saves only for its own button).
		 *
		 * @param bool $save Whether WooCommerce will save.
		 * @return bool
		 */
		public static function save_on_test( $save ): bool {
			return (bool) $save || '' !== lafka_input_request_text( self::TEST_BUTTON );
		}

		/**
		 * After the form saved: send the test message when its button was used.
		 *
		 * @return void
		 */
		public static function maybe_send_test(): void {
			if ( '' === lafka_input_request_text( self::TEST_BUTTON ) ) { // WC_Admin_Settings::save() checked the nonce.
				return;
			}
			$adapter = Lafka_Notify::adapter();
			if ( ! Lafka_Notify::enabled() || null === $adapter ) {
				WC_Admin_Settings::add_error( __( 'Turn text messages on, choose a channel and fill in its details first.', 'lafka-plugin' ) );
				return;
			}
			$to = lafka_phone_to_e164( lafka_input_request_text( self::TEST_TO ) );
			if ( '' === $to ) {
				WC_Admin_Settings::add_error( __( 'Enter the mobile number to send the test to, with the area code.', 'lafka-plugin' ) );
				return;
			}

			$info    = function_exists( 'lafka_get_restaurant_info' ) ? lafka_get_restaurant_info() : array();
			$values  = array(
				'{name}'       => __( 'Alex', 'lafka-plugin' ),
				'{order}'      => '1234',
				'{restaurant}' => (string) ( $info['name'] ?? get_bloginfo( 'name' ) ),
				'{eta}'        => '',
				'{track_url}'  => home_url( '/' ),
			);
			$message = array(
				'order_id' => 0,
				'event'    => 'test',
				'to'       => $to,
				'body'     => Lafka_Notify::fill( __( '{restaurant}: this is a test message. Order #{order} for {name}. {track_url}', 'lafka-plugin' ), $values ),
				'template' => '',
				'language' => trim( (string) lafka_setting( 'lafka_notify_wa_language', 'en_US' ) ),
				'params'   => array(),
			);
			foreach ( array_keys( Lafka_Notify::events() ) as $key ) {
				$template = trim( (string) lafka_setting( 'lafka_notify_wa_template_' . $key, '' ) );
				if ( '' !== $template ) {
					$message['template'] = $template;
					foreach ( array_filter( array_map( 'trim', explode( ',', (string) lafka_setting( 'lafka_notify_wa_vars_' . $key, '{name},{order},{track_url}' ) ) ) ) as $placeholder ) {
						$message['params'][] = isset( $values[ $placeholder ] ) && '' !== $values[ $placeholder ] ? $values[ $placeholder ] : '-';
					}
					break;
				}
			}

			$result = $adapter->send( $message );
			Lafka_Log::log(
				$result['ok'] ? 'info' : 'warning',
				'notify',
				$result['ok'] ? 'Test message sent' : 'Test message failed',
				array(
					'adapter' => $adapter->id(),
					'code'    => $result['code'],
					'http'    => $result['status'],
					'to_tail' => substr( $to, -2 ),
				)
			);
			if ( $result['ok'] ) {
				WC_Admin_Settings::add_message( __( 'Test message sent. It should arrive in a moment.', 'lafka-plugin' ) );
				return;
			}
			WC_Admin_Settings::add_error(
				sprintf(
					/* translators: %s: short error code, e.g. twilio_21211. */
					__( 'The test message could not be sent (%s). Check the details above; WooCommerce → Status → Logs (source lafka-notify) has the code.', 'lafka-plugin' ),
					'' !== $result['code'] ? $result['code'] : 'unknown'
				)
			);
		}
	}
}
