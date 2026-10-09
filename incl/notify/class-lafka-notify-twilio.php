<?php
/**
 * Twilio SMS: one Messages API call per message.
 *
 * Credentials: WooCommerce → Settings → Restaurant → Text messages. The auth
 * token is stored without autoload and never printed. The sender is a "From"
 * number or a Messaging Service SID (the service wins when both are set).
 *
 * @package Lafka\Plugin\Notify
 * @since   10.4.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Lafka_Notify_Twilio' ) ) {

	/**
	 * Twilio SMS channel.
	 */
	final class Lafka_Notify_Twilio extends Lafka_Notify_Adapter {

		/**
		 * @return string
		 */
		public function id(): string {
			return 'twilio';
		}

		/**
		 * @return string
		 */
		public function label(): string {
			return __( 'Twilio SMS', 'lafka-plugin' );
		}

		/**
		 * @return bool
		 */
		public function is_configured(): bool {
			return '' !== $this->option( 'sid' ) && '' !== $this->option( 'token' ) && ( '' !== $this->option( 'service' ) || '' !== $this->option( 'from' ) );
		}

		/**
		 * @param string $key sid, token, from or service.
		 * @return string
		 */
		private function option( string $key ): string {
			return trim( (string) lafka_setting( 'lafka_notify_twilio_' . $key, '' ) );
		}

		/**
		 * @param array<string,mixed> $message Message.
		 * @return array{ok:bool,retry:bool,code:string,status:int}
		 */
		public function send( array $message ): array {
			if ( ! $this->is_configured() ) {
				return $this->result( false, false, 'not_configured' );
			}
			$sid  = $this->option( 'sid' );
			$body = array(
				'To'   => (string) $message['to'],
				'Body' => (string) $message['body'],
			);
			if ( '' !== $this->option( 'service' ) ) {
				$body['MessagingServiceSid'] = $this->option( 'service' );
			} else {
				$body['From'] = $this->option( 'from' );
			}

			$response = wp_remote_post(
				'https://api.twilio.com/2010-04-01/Accounts/' . rawurlencode( $sid ) . '/Messages.json',
				array(
					'timeout' => 15,
					'headers' => array(
						'Authorization' => 'Basic ' . sodium_bin2base64( $sid . ':' . $this->option( 'token' ), SODIUM_BASE64_VARIANT_ORIGINAL ), // Twilio's HTTP Basic authentication.
					),
					'body'    => $body,
				)
			);

			return $this->from_response(
				$response,
				static function ( array $data ): string {
					return isset( $data['code'] ) ? 'twilio_' . (int) $data['code'] : '';
				}
			);
		}
	}
}
