<?php
/**
 * Customer order messages by text: SMS (Twilio) or WhatsApp (Cloud API).
 *
 * Rides WooCommerce order statuses, which the kitchen display (when it is on)
 * extends with accepted, ready and rejected. A status change maps to one event;
 * an event that is switched on, for an order whose customer ticked the opt-in
 * at checkout, queues one Action Scheduler job. The job sends through the
 * chosen channel, retries with a growing delay when the service is unreachable
 * or busy, and writes the outcome to the Lafka log channel "notify" without the
 * message text and with only the last two digits of the phone number.
 *
 * Idempotent per order and event: the state lives in the order meta
 * `_lafka_notify_state_{event}` (queued, sent, failed), so setting a status twice
 * or running a job twice never sends twice.
 *
 * @package Lafka\Plugin\Notify
 * @since   10.4.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Lafka_Notify' ) ) {

	/**
	 * Events, queueing and sending.
	 */
	final class Lafka_Notify {

		/** Module flag (WooCommerce → Settings → Restaurant → Text messages). */
		const FLAG = 'lafka_notify_enabled';

		/** Action Scheduler hook and group of the send job. */
		const HOOK  = 'lafka_notify_send';
		const GROUP = 'lafka-notify';

		/** Send attempts before an event is given up. */
		const MAX_ATTEMPTS = 4;

		/** Order meta: the customer's opt-in, when they gave it, the wording they saw and their number. */
		const META_OPTIN = '_lafka_notify_optin';
		const META_TIME  = '_lafka_notify_optin_time';
		const META_TEXT  = '_lafka_notify_optin_text';
		const META_PHONE = '_lafka_notify_phone';

		/** Order meta prefix of the per-event state. */
		const META_STATE = '_lafka_notify_state_';

		/** Placeholders a template can use. */
		const PLACEHOLDERS = array( '{name}', '{order}', '{restaurant}', '{eta}', '{track_url}' );

		/**
		 * Whether the module is on.
		 *
		 * @return bool
		 */
		public static function enabled(): bool {
			return 'yes' === lafka_setting( self::FLAG, 'no' );
		}

		/**
		 * Hook in.
		 *
		 * @return void
		 */
		public static function init(): void {
			add_action( 'woocommerce_order_status_changed', array( __CLASS__, 'on_status_changed' ), 20, 4 );
			add_action( self::HOOK, array( __CLASS__, 'run' ), 10, 3 );
		}

		/**
		 * The events a customer can be told about: key => label, default on,
		 * default text. Delivery orders get "delivery" where pickup orders get
		 * "ready".
		 *
		 * @return array<string,array{label:string,on:bool,text:string}>
		 */
		public static function events(): array {
			return array(
				'received'  => array(
					'label' => __( 'Order received', 'lafka-plugin' ),
					'on'    => false,
					'text'  => __( '{restaurant}: we have your order #{order}. Track it: {track_url}', 'lafka-plugin' ),
				),
				'accepted'  => array(
					'label' => __( 'Order accepted by the kitchen', 'lafka-plugin' ),
					'on'    => true,
					'text'  => __( '{restaurant}: order #{order} is accepted. {eta} Track it: {track_url}', 'lafka-plugin' ),
				),
				'ready'     => array(
					'label' => __( 'Ready for pickup', 'lafka-plugin' ),
					'on'    => true,
					'text'  => __( '{restaurant}: order #{order} is ready for pickup. {track_url}', 'lafka-plugin' ),
				),
				'delivery'  => array(
					'label' => __( 'Out for delivery', 'lafka-plugin' ),
					'on'    => true,
					'text'  => __( '{restaurant}: order #{order} is out for delivery. {eta} {track_url}', 'lafka-plugin' ),
				),
				'completed' => array(
					'label' => __( 'Order completed', 'lafka-plugin' ),
					'on'    => false,
					'text'  => __( '{restaurant}: order #{order} is complete. Thank you, {name}!', 'lafka-plugin' ),
				),
				'cancelled' => array(
					'label' => __( 'Order cancelled or rejected', 'lafka-plugin' ),
					'on'    => true,
					'text'  => __( '{restaurant}: order #{order} was cancelled. Please call us if that is not what you expected.', 'lafka-plugin' ),
				),
			);
		}

		/**
		 * The event a status change tells the customer about, '' for none.
		 *
		 * @param WC_Order $order  Order.
		 * @param string   $status New status, without the "wc-" prefix.
		 * @return string
		 */
		public static function event_for( $order, string $status ): string {
			switch ( $status ) {
				case 'processing':
				case 'on-hold':
					return 'received';
				case 'accepted':
					return 'accepted';
				case 'ready':
					return 'pickup' === lafka_order_fulfilment_type( $order ) ? 'ready' : 'delivery';
				case 'completed':
					return 'completed';
				case 'cancelled':
				case 'rejected':
					return 'cancelled';
			}
			return '';
		}

		/**
		 * Whether an event is switched on.
		 *
		 * @param string $event Event key.
		 * @return bool
		 */
		public static function event_enabled( string $event ): bool {
			$events = self::events();
			return isset( $events[ $event ] ) && 'yes' === lafka_setting( 'lafka_notify_event_' . $event, $events[ $event ]['on'] ? 'yes' : 'no' );
		}

		/**
		 * The channel in use, or null when none is chosen or its credentials are missing.
		 *
		 * @return Lafka_Notify_Adapter|null
		 */
		public static function adapter(): ?Lafka_Notify_Adapter {
			$adapters = array();
			foreach ( array( new Lafka_Notify_Twilio(), new Lafka_Notify_Whatsapp() ) as $adapter ) {
				$adapters[ $adapter->id() ] = $adapter;
			}

			/**
			 * Filters the messaging channels, keyed by id (add your own
			 * Lafka_Notify_Adapter; the "channel" setting chooses one).
			 *
			 * @since 10.4.0
			 *
			 * @param array<string,Lafka_Notify_Adapter> $adapters Channels.
			 */
			$adapters = (array) apply_filters( 'lafka_notify_adapters', $adapters );
			$chosen   = $adapters[ (string) lafka_setting( 'lafka_notify_channel', '' ) ] ?? null;

			return $chosen instanceof Lafka_Notify_Adapter && $chosen->is_configured() ? $chosen : null;
		}

		/**
		 * Whether messages can go out at all: the module is on and a channel is ready.
		 * The checkout offers the opt-in only then.
		 *
		 * @return bool
		 */
		public static function can_send(): bool {
			return self::enabled() && null !== self::adapter();
		}

		/**
		 * Order status changed: queue the event it stands for.
		 *
		 * @param int      $order_id Order id.
		 * @param string   $from     Old status.
		 * @param string   $to       New status.
		 * @param WC_Order $order    Order.
		 * @return void
		 */
		public static function on_status_changed( $order_id, $from, $to, $order = null ): void {
			unset( $from );
			$order = $order instanceof WC_Order ? $order : wc_get_order( $order_id );
			if ( ! $order ) {
				return;
			}
			$event = self::event_for( $order, (string) $to );
			if ( '' !== $event ) {
				self::queue( $order, $event );
			}
		}

		/**
		 * Queue one event for an order, once. Nothing is queued without the
		 * customer's opt-in, a number, a channel or the event being on.
		 *
		 * @param WC_Order $order Order.
		 * @param string   $event Event key.
		 * @return bool Whether a job was queued.
		 */
		public static function queue( $order, string $event ): bool {
			$adapter = self::adapter();
			if ( 'yes' !== $order->get_meta( self::META_OPTIN ) || '' === (string) $order->get_meta( self::META_PHONE ) || ! self::event_enabled( $event ) || null === $adapter || ! $adapter->handles_event( $event ) ) {
				return false;
			}
			if ( '' !== (string) $order->get_meta( self::META_STATE . $event ) || ! function_exists( 'as_schedule_single_action' ) ) {
				return false;
			}
			$args = array( $order->get_id(), $event, 0 );
			if ( function_exists( 'as_has_scheduled_action' ) && as_has_scheduled_action( self::HOOK, $args, self::GROUP ) ) {
				return false;
			}

			$order->update_meta_data( self::META_STATE . $event, 'queued' );
			$order->save_meta_data();
			as_schedule_single_action( time(), self::HOOK, $args, self::GROUP );

			return true;
		}

		/**
		 * The values a template can use for an order.
		 *
		 * @param WC_Order $order Order.
		 * @return array<string,string> Placeholder => value.
		 */
		public static function values( $order ): array {
			$info = function_exists( 'lafka_get_restaurant_info' ) ? lafka_get_restaurant_info() : array();
			$eta  = '';
			$url  = $order->get_checkout_order_received_url();
			if ( class_exists( 'Lafka_Order_Tracking' ) ) {
				$eta = (string) Lafka_Order_Tracking::state( $order )['eta'];
				$url = Lafka_Order_Tracking::track_url( $order );
			}

			return array(
				'{name}'       => wp_strip_all_tags( $order->get_billing_first_name() ),
				'{order}'      => wp_strip_all_tags( (string) $order->get_order_number() ),
				'{restaurant}' => wp_strip_all_tags( (string) ( $info['name'] ?? get_bloginfo( 'name' ) ) ),
				'{eta}'        => wp_strip_all_tags( $eta ),
				'{track_url}'  => $url,
			);
		}

		/**
		 * Fill a template: placeholders replaced, gaps closed.
		 *
		 * @param string               $template Template text.
		 * @param array<string,string> $values   Placeholder => value.
		 * @return string
		 */
		public static function fill( string $template, array $values ): string {
			$text = strtr( $template, $values );
			return trim( (string) preg_replace( '/[ \t]{2,}/', ' ', str_replace( array( "\r", "\n" ), ' ', $text ) ) );
		}

		/**
		 * The message for an order and event.
		 *
		 * @param WC_Order $order Order.
		 * @param string   $event Event key.
		 * @return array<string,mixed> See Lafka_Notify_Adapter.
		 */
		public static function message( $order, string $event ): array {
			$events = self::events();
			$values = self::values( $order );
			$text   = (string) lafka_setting( 'lafka_notify_sms_' . $event, '' );
			$text   = '' !== trim( $text ) ? $text : $events[ $event ]['text'];

			$vars   = (string) lafka_setting( 'lafka_notify_wa_vars_' . $event, '{name},{order},{track_url}' );
			$params = array();
			foreach ( array_filter( array_map( 'trim', explode( ',', $vars ) ) ) as $placeholder ) {
				// WhatsApp rejects an empty variable.
				$params[] = isset( $values[ $placeholder ] ) && '' !== $values[ $placeholder ] ? $values[ $placeholder ] : '-';
			}

			$message = array(
				'order_id' => $order->get_id(),
				'event'    => $event,
				'to'       => (string) $order->get_meta( self::META_PHONE ),
				'body'     => mb_substr( self::fill( $text, $values ), 0, 1000 ),
				'template' => trim( (string) lafka_setting( 'lafka_notify_wa_template_' . $event, '' ) ),
				'language' => trim( (string) lafka_setting( 'lafka_notify_wa_language', 'en_US' ) ),
				'params'   => $params,
			);

			/**
			 * Filters a customer message before it is sent.
			 *
			 * @since 10.4.0
			 *
			 * @param array<string,mixed> $message Message (see Lafka_Notify_Adapter).
			 * @param WC_Order            $order   Order.
			 * @param string              $event   Event key.
			 */
			return (array) apply_filters( 'lafka_notify_message', $message, $order, $event );
		}

		/**
		 * The Action Scheduler job: send one event, or schedule the next attempt.
		 *
		 * @param int    $order_id Order id.
		 * @param string $event    Event key.
		 * @param int    $attempt  Attempts already made.
		 * @return void
		 */
		public static function run( $order_id, $event, $attempt = 0 ): void {
			$order   = wc_get_order( (int) $order_id );
			$event   = (string) $event;
			$attempt = (int) $attempt;
			if ( ! $order || ! isset( self::events()[ $event ] ) || 'sent' === $order->get_meta( self::META_STATE . $event ) ) {
				return;
			}
			$adapter = self::adapter();
			if ( 'yes' !== $order->get_meta( self::META_OPTIN ) || null === $adapter ) {
				self::finish( $order, $event, 'failed', $adapter, 'no_consent_or_channel', 0 );
				return;
			}

			$result = $adapter->send( self::message( $order, $event ) );
			if ( $result['ok'] ) {
				self::finish( $order, $event, 'sent', $adapter, '', $attempt, $result['status'] );
				return;
			}
			if ( $result['retry'] && $attempt + 1 < self::MAX_ATTEMPTS ) {
				/**
				 * Filters the seconds before the next send attempt.
				 *
				 * @since 10.4.0
				 *
				 * @param int $delay   Seconds (a minute, five minutes, then twenty-five).
				 * @param int $attempt Attempts already made.
				 */
				$delay = (int) apply_filters( 'lafka_notify_retry_delay', MINUTE_IN_SECONDS * (int) pow( 5, $attempt ), $attempt );
				as_schedule_single_action( time() + $delay, self::HOOK, array( $order->get_id(), $event, $attempt + 1 ), self::GROUP );
				self::log( 'warning', 'Customer message failed, will retry', $order, $event, $adapter, $result['code'], $result['status'], $attempt );
				return;
			}
			self::finish( $order, $event, 'failed', $adapter, $result['code'], $attempt, $result['status'] );
		}

		/**
		 * Record the end state of an event.
		 *
		 * @param WC_Order                  $order   Order.
		 * @param string                    $event   Event key.
		 * @param string                    $state   'sent' or 'failed'.
		 * @param Lafka_Notify_Adapter|null $adapter Channel.
		 * @param string                    $code    Error code ('' when sent).
		 * @param int                       $attempt Attempts already made.
		 * @param int                       $status  HTTP status.
		 * @return void
		 */
		private static function finish( $order, string $event, string $state, ?Lafka_Notify_Adapter $adapter, string $code, int $attempt, int $status = 0 ): void {
			$order->update_meta_data( self::META_STATE . $event, $state );
			$order->save_meta_data();
			if ( 'sent' === $state ) {
				self::log( 'info', 'Customer message sent', $order, $event, $adapter, '', $status, $attempt );
				/* translators: %s: event label, e.g. "Ready for pickup". */
				$order->add_order_note( sprintf( __( 'Text message sent: %s.', 'lafka-plugin' ), self::events()[ $event ]['label'] ) );
				return;
			}
			self::log( 'error', 'Customer message failed', $order, $event, $adapter, $code, $status, $attempt );
			$order->add_order_note(
				sprintf(
					/* translators: 1: event label, 2: short error code. */
					__( 'Text message "%1$s" could not be sent (%2$s). See WooCommerce → Status → Logs, source lafka-notify.', 'lafka-plugin' ),
					self::events()[ $event ]['label'],
					'' !== $code ? $code : 'unknown'
				)
			);
		}

		/**
		 * Log to the "notify" channel: ids, codes and the last two digits of the
		 * number, never the text or the whole number.
		 *
		 * @param string                    $level   Log level.
		 * @param string                    $message Message.
		 * @param WC_Order                  $order   Order.
		 * @param string                    $event   Event key.
		 * @param Lafka_Notify_Adapter|null $adapter Channel.
		 * @param string                    $code    Error code.
		 * @param int                       $status  HTTP status.
		 * @param int                       $attempt Attempts already made.
		 * @return void
		 */
		private static function log( string $level, string $message, $order, string $event, ?Lafka_Notify_Adapter $adapter, string $code, int $status, int $attempt ): void {
			Lafka_Log::log(
				$level,
				'notify',
				$message,
				array(
					'order_id' => $order->get_id(),
					'event'    => $event,
					'adapter'  => $adapter ? $adapter->id() : '',
					'code'     => $code,
					'http'     => $status,
					'attempt'  => $attempt + 1,
					'to_tail'  => substr( (string) $order->get_meta( self::META_PHONE ), -2 ),
				)
			);
		}
	}
}
