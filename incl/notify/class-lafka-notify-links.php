<?php
/**
 * "Message us on WhatsApp": a free click-to-chat link (wa.me) to the
 * restaurant's phone number, needing no WhatsApp Business account or API.
 *
 * Markup only: a paragraph with one link carrying the theme's `.lafka-btn`
 * class. Shown on the order confirmation (below the tracker, classic and block)
 * and wherever a theme or an operator prints it:
 *
 *     do_action( 'lafka_whatsapp_link' );   // a template
 *     [lafka_whatsapp]                      // content
 *
 * The filter `lafka_whatsapp_link_hooks` lists the extra actions that print it.
 *
 * @package Lafka\Plugin\Notify
 * @since   10.4.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( 'Lafka_Notify_Links' ) ) {

	/**
	 * The click-to-chat link.
	 */
	final class Lafka_Notify_Links {

		/** Order ids whose confirmation already carried the link this request. */
		private static $printed = array();

		/**
		 * Hook in.
		 *
		 * @return void
		 */
		public static function init(): void {
			if ( 'yes' !== lafka_setting( 'lafka_notify_wa_link', 'yes' ) ) {
				return;
			}
			add_action( 'lafka_whatsapp_link', array( __CLASS__, 'print_link' ) );
			add_shortcode( 'lafka_whatsapp', array( __CLASS__, 'shortcode' ) );
			add_filter( 'render_block', array( __CLASS__, 'append_to_confirmation_block' ), 11, 2 );

			/**
			 * Filters the actions that print the WhatsApp link (a hook that
			 * passes an order id as its first argument gets the order's greeting).
			 *
			 * @since 10.4.0
			 *
			 * @param string[] $hooks Action names; default the classic order confirmation.
			 */
			foreach ( (array) apply_filters( 'lafka_whatsapp_link_hooks', array( 'woocommerce_thankyou' ) ) as $hook ) {
				add_action( (string) $hook, array( __CLASS__, 'print_link' ), 20 );
			}
		}

		/**
		 * The click-to-chat URL, or '' without a usable restaurant phone.
		 *
		 * @param string $text Message to prefill ('' for none).
		 * @return string
		 */
		public static function url( string $text = '' ): string {
			$info = function_exists( 'lafka_get_restaurant_info' ) ? lafka_get_restaurant_info() : array();
			$land = (string) ( $info['country'] ?? '' );
			$e164 = lafka_phone_to_e164( (string) ( $info['phone_e164'] ?? '' ), 2 === strlen( $land ) ? $land : '' );

			/**
			 * Filters the WhatsApp number (E.164, e.g. "+19025550100") the link opens.
			 *
			 * @since 10.4.0
			 *
			 * @param string $e164 Number; defaults to the restaurant phone.
			 */
			$e164 = (string) apply_filters( 'lafka_whatsapp_link_phone', $e164 );
			if ( '' === $e164 ) {
				return '';
			}
			$url = 'https://wa.me/' . ltrim( $e164, '+' );
			return '' === $text ? $url : add_query_arg( 'text', rawurlencode( $text ), $url );
		}

		/**
		 * The link's HTML ('' when there is no number).
		 *
		 * @param int $order_id Order the link greets about (0 = none).
		 * @return string
		 */
		public static function html( int $order_id = 0 ): string {
			$order = $order_id > 0 ? wc_get_order( $order_id ) : false;
			$text  = $order ? sprintf(
				/* translators: %s: order number. */
				__( 'Hi, I have a question about my order #%s.', 'lafka-plugin' ),
				$order->get_order_number()
			) : '';
			$url = self::url( $text );
			if ( '' === $url ) {
				return '';
			}
			$label = trim( (string) lafka_setting( 'lafka_notify_wa_link_label', '' ) );
			$label = '' !== $label ? $label : __( 'Message us on WhatsApp', 'lafka-plugin' );

			$html = sprintf(
				'<p class="lafka-wa"><a class="lafka-wa__link lafka-btn lafka-btn--ghost" href="%1$s" target="_blank" rel="noopener noreferrer">%2$s</a></p>',
				esc_url( $url ),
				esc_html( $label )
			);

			/**
			 * Filters the WhatsApp link's HTML.
			 *
			 * @since 10.4.0
			 *
			 * @param string $html     Markup.
			 * @param string $url      wa.me URL.
			 * @param int    $order_id Order id, 0 outside an order page.
			 */
			return (string) apply_filters( 'lafka_whatsapp_link_html', $html, $url, $order_id );
		}

		/**
		 * Print the link. Called with an order id on the order confirmation.
		 *
		 * @param mixed $order_id Order id, or nothing.
		 * @return void
		 */
		public static function print_link( $order_id = 0 ): void {
			$order_id = is_numeric( $order_id ) ? (int) $order_id : 0;
			if ( $order_id > 0 ) {
				if ( isset( self::$printed[ $order_id ] ) ) {
					return;
				}
				self::$printed[ $order_id ] = true;
			}
			echo wp_kses_post( self::html( $order_id ) );
		}

		/**
		 * [lafka_whatsapp]
		 *
		 * @return string
		 */
		public static function shortcode(): string {
			return wp_kses_post( self::html() );
		}

		/**
		 * Block themes: add the link under the order-confirmation status block,
		 * after the tracker.
		 *
		 * @param string              $content Block output.
		 * @param array<string,mixed> $block   Parsed block.
		 * @return string
		 */
		public static function append_to_confirmation_block( $content, $block ): string {
			$content = (string) $content;
			if ( 'woocommerce/order-confirmation-status' !== ( $block['blockName'] ?? '' ) || '' === trim( $content ) ) {
				return $content;
			}
			$order_id = absint( get_query_var( 'order-received' ) );
			if ( $order_id <= 0 || isset( self::$printed[ $order_id ] ) ) {
				return $content;
			}
			self::$printed[ $order_id ] = true;
			return $content . self::html( $order_id );
		}
	}
}
