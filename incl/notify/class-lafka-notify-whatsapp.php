<?php
/**
 * WhatsApp Cloud API: one approved template message per event.
 *
 * WhatsApp only lets a business start a conversation with a template Meta has
 * approved, so each event names its template and the placeholders that fill
 * its variables, in order. Credentials: WooCommerce → Settings → Restaurant →
 * Text messages. The access token is stored without autoload and never printed.
 *
 * @package Lafka\Plugin\Notify
 * @since   10.4.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Lafka_Notify_Whatsapp' ) ) {

	/**
	 * WhatsApp Cloud API channel.
	 */
	final class Lafka_Notify_Whatsapp extends Lafka_Notify_Adapter {

		/**
		 * @return string
		 */
		public function id(): string {
			return 'whatsapp';
		}

		/**
		 * @return string
		 */
		public function label(): string {
			return __( 'WhatsApp Cloud API', 'lafka-plugin' );
		}

		/**
		 * @return bool
		 */
		public function is_configured(): bool {
			return '' !== $this->option( 'phone_id' ) && '' !== $this->option( 'token' );
		}

		/**
		 * Only an event with an approved template can be sent.
		 *
		 * @param string $event Event key.
		 * @return bool
		 */
		public function handles_event( string $event ): bool {
			return '' !== trim( (string) lafka_setting( 'lafka_notify_wa_template_' . $event, '' ) );
		}

		/**
		 * @param string $key phone_id, token or language.
		 * @return string
		 */
		private function option( string $key ): string {
			return trim( (string) lafka_setting( 'lafka_notify_wa_' . $key, '' ) );
		}

		/**
		 * @param array<string,mixed> $message Message.
		 * @return array{ok:bool,retry:bool,code:string,status:int}
		 */
		public function send( array $message ): array {
			if ( ! $this->is_configured() ) {
				return $this->result( false, false, 'not_configured' );
			}
			if ( '' === (string) $message['template'] ) {
				return $this->result( false, false, 'no_template' );
			}

			$template = array(
				'name'     => (string) $message['template'],
				'language' => array( 'code' => (string) $message['language'] ),
			);
			if ( ! empty( $message['params'] ) ) {
				$parameters = array();
				foreach ( (array) $message['params'] as $text ) {
					$parameters[] = array(
						'type' => 'text',
						'text' => (string) $text,
					);
				}
				$template['components'] = array(
					array(
						'type'       => 'body',
						'parameters' => $parameters,
					),
				);
			}

			/**
			 * Filters the Graph API version the WhatsApp requests use.
			 *
			 * @since 10.4.0
			 *
			 * @param string $version For example "v21.0".
			 */
			$version = (string) apply_filters( 'lafka_notify_whatsapp_api_version', 'v21.0' );

			$response = wp_remote_post(
				'https://graph.facebook.com/' . rawurlencode( $version ) . '/' . rawurlencode( $this->option( 'phone_id' ) ) . '/messages',
				array(
					'timeout' => 15,
					'headers' => array(
						'Authorization' => 'Bearer ' . $this->option( 'token' ),
						'Content-Type'  => 'application/json',
					),
					'body'    => wp_json_encode(
						array(
							'messaging_product' => 'whatsapp',
							'to'                => ltrim( (string) $message['to'], '+' ),
							'type'              => 'template',
							'template'          => $template,
						)
					),
				)
			);

			return $this->from_response(
				$response,
				static function ( array $data ): string {
					return isset( $data['error']['code'] ) ? 'whatsapp_' . (int) $data['error']['code'] : '';
				}
			);
		}
	}
}
