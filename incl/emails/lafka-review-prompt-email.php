<?php
/**
 * P6-UX-8 W3-T6: post-order review prompt email.
 *
 * When an order is marked "completed", schedule a one-shot WP-Cron event N days
 * later (default 7 days, filterable) that sends the customer an email asking for
 * product reviews.
 *
 * Uses WP-Cron + wp_mail (uses whatever SMTP/transport is already configured).
 *
 * Operator controls:
 *   filter `lafka_review_prompt_delay_days` (default 7)
 *   filter `lafka_review_prompt_enabled`    (default true)
 *   filter `lafka_review_prompt_subject`    (default "How was your order?")
 *   filter `lafka_review_prompt_message`    (default body text — see below)
 */
defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'lafka_review_prompt_schedule_on_completed' ) ) {
	add_action( 'woocommerce_order_status_completed', 'lafka_review_prompt_schedule_on_completed', 10, 1 );
	function lafka_review_prompt_schedule_on_completed( $order_id ) {
		if ( ! apply_filters( 'lafka_review_prompt_enabled', true ) ) {
			return;
		}
		$days = max( 1, (int) apply_filters( 'lafka_review_prompt_delay_days', 7 ) );
		$when = time() + ( $days * DAY_IN_SECONDS );
		// Avoid duplicate scheduling
		if ( ! wp_next_scheduled( 'lafka_review_prompt_send', array( $order_id ) ) ) {
			wp_schedule_single_event( $when, 'lafka_review_prompt_send', array( $order_id ) );
		}
	}
}

if ( ! function_exists( 'lafka_review_prompt_send' ) ) {
	add_action( 'lafka_review_prompt_send', 'lafka_review_prompt_send', 10, 1 );
	function lafka_review_prompt_send( $order_id ) {
		if ( ! apply_filters( 'lafka_review_prompt_enabled', true ) ) {
			return;
		}
		if ( ! function_exists( 'wc_get_order' ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		$email_to = $order->get_billing_email();
		if ( ! $email_to ) {
			return;
		}
		$customer = $order->get_billing_first_name() ?: 'there';
		$site     = get_bloginfo( 'name' );

		// Build product list with review-direct links.
		$items    = $order->get_items();
		$links_md = array();
		foreach ( $items as $item ) {
			$product = $item->get_product();
			if ( ! $product ) {
				continue;
			}
			$link       = get_permalink( $product->get_id() ) . '#reviews';
			$links_md[] = sprintf( '• %s — %s', $product->get_name(), $link );
		}
		if ( empty( $links_md ) ) {
			return; // nothing to ask about
		}

		$subject = apply_filters(
			'lafka_review_prompt_subject',
			sprintf( 'How was your order from %s?', $site )
		);

		$body = apply_filters(
			'lafka_review_prompt_message',
			sprintf(
				"Hi %s,\n\n" .
				"Thanks again for ordering from %s. Hope it was great!\n\n" .
				"If you have a minute, would you mind leaving a quick review? It really helps us — and helps neighbours decide what to try next.\n\n" .
				"Click any of these to review what you ordered:\n%s\n\n" .
				"Thanks,\n%s",
				$customer,
				$site,
				implode( "\n", $links_md ),
				$site
			),
			$order
		);

		$headers = array( 'From: ' . $site . ' <' . get_option( 'admin_email' ) . '>' );
		wp_mail( $email_to, $subject, $body, $headers );
	}
}
