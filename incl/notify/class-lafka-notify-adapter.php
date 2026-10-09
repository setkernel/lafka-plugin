<?php
/**
 * A channel that delivers one order message to a customer.
 *
 * An adapter turns the message Lafka_Notify built into one API call and says
 * how it went. It never logs, and never throws: the sender decides whether to
 * retry and what to write in the log (no message text, no phone number).
 *
 * Message array keys: order_id, event, to (E.164, "+19025550100"), body (the
 * plain text for SMS), template (an approved WhatsApp template name), language
 * (its language code) and params (its body variables, in order).
 *
 * Result array keys: ok (bool), retry (bool: a later attempt may work), code
 * (short machine code, '' on success) and status (HTTP status, 0 when the
 * request did not get an answer).
 *
 * @package Lafka\Plugin\Notify
 * @since   10.4.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Lafka_Notify_Adapter' ) ) {

	/**
	 * Base of the messaging channels.
	 */
	abstract class Lafka_Notify_Adapter {

		/**
		 * Short id stored in the "channel" setting.
		 *
		 * @return string
		 */
		abstract public function id(): string;

		/**
		 * Name shown in the settings.
		 *
		 * @return string
		 */
		abstract public function label(): string;

		/**
		 * Whether the credentials needed to send are saved.
		 *
		 * @return bool
		 */
		abstract public function is_configured(): bool;

		/**
		 * Whether this channel can send an event at all (WhatsApp needs a
		 * template for it); an event it cannot send is not queued.
		 *
		 * @param string $event Event key.
		 * @return bool
		 */
		public function handles_event( string $event ): bool {
			unset( $event );
			return true;
		}

		/**
		 * Send one message.
		 *
		 * @param array<string,mixed> $message Message (see the file header).
		 * @return array{ok:bool,retry:bool,code:string,status:int}
		 */
		abstract public function send( array $message ): array;

		/**
		 * Build a result.
		 *
		 * @param bool   $ok     Whether the message was accepted.
		 * @param bool   $retry  Whether a later attempt may work.
		 * @param string $code   Short machine code.
		 * @param int    $status HTTP status.
		 * @return array{ok:bool,retry:bool,code:string,status:int}
		 */
		protected function result( bool $ok, bool $retry = false, string $code = '', int $status = 0 ): array {
			return array(
				'ok'     => $ok,
				'retry'  => $retry,
				'code'   => $code,
				'status' => $status,
			);
		}

		/**
		 * Turn an HTTP response into a result. The caller names the error code
		 * from the decoded body.
		 *
		 * @param array|WP_Error $response Response of wp_remote_request().
		 * @param callable       $code_of  Receives the decoded JSON body, returns the API's error code.
		 * @return array{ok:bool,retry:bool,code:string,status:int}
		 */
		protected function from_response( $response, callable $code_of ): array {
			if ( is_wp_error( $response ) ) {
				return $this->result( false, true, 'http_error' );
			}
			$status = (int) wp_remote_retrieve_response_code( $response );
			if ( $status >= 200 && $status < 300 ) {
				return $this->result( true, false, '', $status );
			}
			$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
			$code = (string) $code_of( is_array( $body ) ? $body : array() );
			$code = '' !== $code ? $code : 'http_' . $status;
			return $this->result( false, 429 === $status || $status >= 500, $code, $status );
		}
	}
}
