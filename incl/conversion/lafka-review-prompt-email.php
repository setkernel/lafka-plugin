<?php
/**
 * Phase 3D (v9.28.0): Post-purchase review prompt — email channel.
 *
 * When an order's status flips to "completed", schedule a one-shot WP-Cron event
 * `lafka_send_review_email` N hours later (default 24h, operator-configurable).
 * The cron handler instantiates a WC_Email subclass which renders + sends the
 * email through WC's normal mail pipeline (so From-name / From-address / locale
 * inheritance all match every other transactional email the store sends).
 *
 * Idempotence: order meta `_lafka_review_email_sent` is flipped on a successful
 * send. The cron handler bails when the meta is already set, so a re-trigger
 * (e.g. operator manually re-completes the same order) won't double-mail the
 * customer.
 *
 * User opt-out: customer can hit `?lafka_unsubscribe_reviews={TOKEN}&u={user_id}`
 * — the handler sets `_lafka_review_email_optout = 1` on their user meta and
 * redirects to a friendly thank-you page. Token = HMAC of the user_id with
 * wp_salt() so it can't be forged.
 *
 * Customizer-configurable copy (panel `lafka_reviews`):
 *   lafka_review_email_enabled       master toggle (default OFF)
 *   lafka_review_email_delay_hours   1–336 (default 24)
 *   lafka_review_email_subject       supports {firstname} + {site} tokens
 *   lafka_review_email_intro         textarea
 *   lafka_review_target_url          operator's Google review URL
 *   lafka_review_target_label        CTA label below the stars
 *
 * @package Lafka\Plugin\Conversion
 * @since   9.28.0
 */

defined( 'ABSPATH' ) || exit;

// ─────────────────────────────────────────────────────────────────────────────
// Customizer reads — small helpers so the cron + email + banner layers all
// pull from the same source.
// ─────────────────────────────────────────────────────────────────────────────

if ( ! function_exists( 'lafka_review_email_is_enabled' ) ) {
	/**
	 * Master enable toggle — read from Customizer setting
	 * `lafka_review_email_enabled`.
	 *
	 * @return bool
	 */
	function lafka_review_email_is_enabled(): bool {
		if ( ! function_exists( 'get_theme_mod' ) ) {
			return false;
		}
		return '1' === (string) get_theme_mod( 'lafka_review_email_enabled', '0' );
	}
}

if ( ! function_exists( 'lafka_review_email_delay_hours' ) ) {
	/**
	 * Operator-configurable delay before the review email fires.
	 *
	 * @return int Hours, clamped to [1, 336] (1 hour — 14 days).
	 */
	function lafka_review_email_delay_hours(): int {
		$raw = 24;
		if ( function_exists( 'get_theme_mod' ) ) {
			$raw = (int) get_theme_mod( 'lafka_review_email_delay_hours', 24 );
		}
		return max( 1, min( 336, $raw ) );
	}
}

if ( ! function_exists( 'lafka_review_email_subject_default' ) ) {
	/**
	 * Default subject line. Reads Customizer override. Token expansion happens
	 * in WC_Email::get_subject() (we override there since we need the order
	 * context for {firstname}).
	 *
	 * @return string
	 */
	function lafka_review_email_subject_default(): string {
		$tmpl = function_exists( 'get_theme_mod' )
			? (string) get_theme_mod( 'lafka_review_email_subject', '' )
			: '';
		if ( '' === trim( $tmpl ) ) {
			$tmpl = 'How was your order, {firstname}?';
		}
		return $tmpl;
	}
}

if ( ! function_exists( 'lafka_review_email_intro' ) ) {
	function lafka_review_email_intro(): string {
		$value = function_exists( 'get_theme_mod' )
			? (string) get_theme_mod( 'lafka_review_email_intro', '' )
			: '';
		return '' === trim( $value )
			? 'We hope you enjoyed every bite. A quick rating goes a long way for a small spot like ours.'
			: $value;
	}
}

if ( ! function_exists( 'lafka_review_target_url' ) ) {
	/**
	 * The operator's review destination — Google review URL, Yelp, etc.
	 * Empty string is a valid response; callers fall back to an on-site
	 * destination (most recent product's reviews tab).
	 *
	 * @return string
	 */
	function lafka_review_target_url(): string {
		$raw = function_exists( 'get_theme_mod' )
			? (string) get_theme_mod( 'lafka_review_target_url', '' )
			: '';
		return trim( $raw );
	}
}

if ( ! function_exists( 'lafka_review_target_label' ) ) {
	function lafka_review_target_label(): string {
		$value = function_exists( 'get_theme_mod' )
			? (string) get_theme_mod( 'lafka_review_target_label', '' )
			: '';
		return '' === trim( $value ) ? 'Leave a Google review' : $value;
	}
}

// ─────────────────────────────────────────────────────────────────────────────
// WC_Email registration via filter.
// ─────────────────────────────────────────────────────────────────────────────

if ( ! function_exists( 'lafka_review_email_register_class' ) ) {
	/**
	 * `woocommerce_email_classes` filter handler.
	 *
	 * Lazy-loads the class definition (WC_Email must exist first) and registers
	 * a fresh instance into the array.
	 *
	 * @param array $email_classes
	 * @return array
	 */
	function lafka_review_email_register_class( $email_classes ) {
		$email_classes = is_array( $email_classes ) ? $email_classes : array();
		if ( ! class_exists( 'WC_Email' ) ) {
			return $email_classes;
		}
		if ( ! class_exists( 'LAFKA_Review_Prompt_Email' ) ) {
			require_once __DIR__ . '/class-lafka-review-prompt-email-class.php';
		}
		if ( class_exists( 'LAFKA_Review_Prompt_Email' ) ) {
			$email_classes['LAFKA_Review_Prompt_Email'] = new LAFKA_Review_Prompt_Email();
		}
		return $email_classes;
	}
}

if ( function_exists( 'add_filter' ) ) {
	add_filter( 'woocommerce_email_classes', 'lafka_review_email_register_class' );
}

// Eagerly load the subclass file when WC is available — keeps the class
// definition in its own file so the autoloader/source-grep tests stay clean.
if ( ! class_exists( 'LAFKA_Review_Prompt_Email' ) && class_exists( 'WC_Email' ) ) {
	require_once __DIR__ . '/class-lafka-review-prompt-email-class.php';
}

// ─────────────────────────────────────────────────────────────────────────────
// Scheduling — hook the WC status-change action.
// ─────────────────────────────────────────────────────────────────────────────

if ( ! function_exists( 'lafka_review_email_schedule_for_order' ) ) {
	/**
	 * Schedule a one-shot WP-Cron `lafka_send_review_email` event N hours after
	 * the order is marked completed.
	 *
	 * Idempotent on three axes:
	 *   1. Bails when the master toggle is OFF.
	 *   2. Bails when the order already has `_lafka_review_email_sent` meta.
	 *   3. Bails when a `lafka_send_review_email` event for this order_id is
	 *      already on the schedule.
	 *
	 * @param int $order_id
	 * @return void
	 */
	function lafka_review_email_schedule_for_order( $order_id ): void {
		if ( ! lafka_review_email_is_enabled() ) {
			return;
		}
		$order_id = (int) $order_id;
		if ( $order_id <= 0 ) {
			return;
		}
		if ( ! function_exists( 'wc_get_order' ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order || ! is_object( $order ) ) {
			return;
		}
		if ( ! function_exists( 'wp_next_scheduled' ) || ! function_exists( 'wp_schedule_single_event' ) ) {
			return;
		}

		// Idempotence: already sent?
		if ( method_exists( $order, 'get_meta' ) ) {
			$already = (string) $order->get_meta( '_lafka_review_email_sent', true );
			if ( '' !== $already && '0' !== $already ) {
				return;
			}
		}

		// Idempotence: already scheduled?
		if ( wp_next_scheduled( 'lafka_send_review_email', array( $order_id ) ) ) {
			return;
		}

		$delay   = lafka_review_email_delay_hours();
		$seconds = $delay * HOUR_IN_SECONDS;
		$when    = time() + $seconds;

		wp_schedule_single_event( $when, 'lafka_send_review_email', array( $order_id ) );
	}
}

if ( function_exists( 'add_action' ) ) {
	// WC's order_status_completed fires after the status transition is persisted.
	add_action( 'woocommerce_order_status_completed', 'lafka_review_email_schedule_for_order', 20, 1 );
}

// ─────────────────────────────────────────────────────────────────────────────
// Cron handler.
// ─────────────────────────────────────────────────────────────────────────────

if ( ! function_exists( 'lafka_review_email_should_skip_order' ) ) {
	/**
	 * Return true when the review email should NOT fire for this order:
	 *   - already sent (`_lafka_review_email_sent` meta set)
	 *   - the order's customer user has `_lafka_review_email_optout` user meta
	 *   - no billing email
	 *
	 * @param object $order WC_Order
	 * @return bool
	 */
	function lafka_review_email_should_skip_order( $order ): bool {
		if ( ! is_object( $order ) ) {
			return true;
		}
		if ( method_exists( $order, 'get_meta' ) ) {
			$sent = (string) $order->get_meta( '_lafka_review_email_sent', true );
			if ( '' !== $sent && '0' !== $sent ) {
				return true;
			}
		}
		$email = method_exists( $order, 'get_billing_email' ) ? (string) $order->get_billing_email() : '';
		if ( '' === $email ) {
			return true;
		}
		// User-level opt-out — only relevant if the order is tied to a user.
		$user_id = method_exists( $order, 'get_customer_id' ) ? (int) $order->get_customer_id() : 0;
		if ( $user_id > 0 && function_exists( 'get_user_meta' ) ) {
			$optout = get_user_meta( $user_id, '_lafka_review_email_optout', true );
			if ( '1' === (string) $optout ) {
				return true;
			}
		}
		return false;
	}
}

if ( ! function_exists( 'lafka_review_email_mark_sent' ) ) {
	/**
	 * Flip `_lafka_review_email_sent` meta so subsequent triggers no-op.
	 *
	 * @param object $order WC_Order
	 * @return void
	 */
	function lafka_review_email_mark_sent( $order ): void {
		if ( ! is_object( $order ) ) {
			return;
		}
		if ( method_exists( $order, 'update_meta_data' ) ) {
			$order->update_meta_data( '_lafka_review_email_sent', (string) time() );
			if ( method_exists( $order, 'save' ) ) {
				$order->save();
			}
		}
	}
}

if ( ! function_exists( 'lafka_review_email_run_cron' ) ) {
	/**
	 * The `lafka_send_review_email` cron handler.
	 *
	 * Self-gates on the master toggle so an operator who flipped the feature off
	 * between schedule + fire times never sends.
	 *
	 * @param int $order_id
	 * @return void
	 */
	function lafka_review_email_run_cron( $order_id ): void {
		if ( ! lafka_review_email_is_enabled() ) {
			return;
		}
		$order_id = (int) $order_id;
		if ( $order_id <= 0 ) {
			return;
		}
		if ( ! function_exists( 'wc_get_order' ) ) {
			return;
		}
		$order = wc_get_order( $order_id );
		if ( ! $order || ! is_object( $order ) ) {
			return;
		}
		if ( lafka_review_email_should_skip_order( $order ) ) {
			return;
		}

		// Fire the WC email class. Class is registered through
		// woocommerce_email_classes filter; the instance subscribes to this
		// action in its constructor.
		if ( function_exists( 'do_action' ) ) {
			do_action( 'lafka_review_prompt_email_trigger', $order_id );
		}
	}
}

if ( function_exists( 'add_action' ) ) {
	add_action( 'lafka_send_review_email', 'lafka_review_email_run_cron', 10, 1 );
}

// ─────────────────────────────────────────────────────────────────────────────
// Unsubscribe link.
// ─────────────────────────────────────────────────────────────────────────────

if ( ! function_exists( 'lafka_review_email_unsubscribe_token' ) ) {
	/**
	 * Forgery-resistant token for the unsubscribe link.
	 *
	 *   token = first 24 chars of HMAC-SHA256( "lafka-review-unsub:{user_id}",
	 *                                          wp_salt() )
	 *
	 * Time-invariant — same input → same token, so the link works forever
	 * until the user's salt rotates.
	 *
	 * @param int $user_id
	 * @return string
	 */
	function lafka_review_email_unsubscribe_token( int $user_id ): string {
		if ( $user_id <= 0 ) {
			return '';
		}
		$salt = function_exists( 'wp_salt' ) ? (string) wp_salt() : 'lafka-default-salt';
		$hmac = hash_hmac( 'sha256', 'lafka-review-unsub:' . $user_id, $salt );
		return substr( $hmac, 0, 24 );
	}
}

if ( ! function_exists( 'lafka_review_email_unsubscribe_url' ) ) {
	/**
	 * Build the unsubscribe URL: home_url() + ?lafka_unsubscribe_reviews={TOKEN}&u={user_id}
	 *
	 * Returns empty string when user_id is invalid (e.g. guest checkout —
	 * unsubscribe isn't meaningful, so the email footer hides the link).
	 *
	 * @param int $user_id
	 * @return string
	 */
	function lafka_review_email_unsubscribe_url( int $user_id ): string {
		if ( $user_id <= 0 ) {
			return '';
		}
		$token = lafka_review_email_unsubscribe_token( $user_id );
		if ( '' === $token ) {
			return '';
		}
		$base = function_exists( 'home_url' ) ? (string) home_url( '/' ) : '/';
		if ( function_exists( 'add_query_arg' ) ) {
			return (string) add_query_arg(
				array(
					'lafka_unsubscribe_reviews' => $token,
					'u'                         => $user_id,
				),
				$base
			);
		}
		$sep = ( false === strpos( $base, '?' ) ) ? '?' : '&';
		return $base . $sep . 'lafka_unsubscribe_reviews=' . rawurlencode( $token )
			. '&u=' . rawurlencode( (string) $user_id );
	}
}

if ( ! function_exists( 'lafka_review_email_handle_unsubscribe_request' ) ) {
	/**
	 * Inspect $_GET on every request — if the unsubscribe params are present and
	 * the token matches the user_id's HMAC, flip the user's
	 * `_lafka_review_email_optout` meta to 1 and redirect to the home page with
	 * `?lafka_review_unsubscribed=1` so the theme can render a confirmation.
	 *
	 * Runs on `init` priority 5 — same shape as the resume-cart handler from
	 * Phase 3B.
	 *
	 * @return void
	 */
	function lafka_review_email_handle_unsubscribe_request(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- HMAC token gates the action; no state-changing form submit.
		if ( ! isset( $_GET['lafka_unsubscribe_reviews'] ) || ! isset( $_GET['u'] ) ) {
			return;
		}
		$token   = function_exists( 'sanitize_text_field' ) && is_string( $_GET['lafka_unsubscribe_reviews'] )
			? sanitize_text_field( wp_unslash( $_GET['lafka_unsubscribe_reviews'] ) )
			: '';
		$user_id = is_scalar( $_GET['u'] ) ? (int) $_GET['u'] : 0;
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( '' === $token || $user_id <= 0 ) {
			return;
		}
		$expected = lafka_review_email_unsubscribe_token( $user_id );
		if ( ! hash_equals( $expected, $token ) ) {
			return;
		}

		if ( function_exists( 'update_user_meta' ) ) {
			update_user_meta( $user_id, '_lafka_review_email_optout', '1' );
		}

		// Redirect to home with a confirmation param so the theme can render
		// a small thank-you notice without us having to ship a dedicated page.
		if ( function_exists( 'wp_safe_redirect' ) && function_exists( 'home_url' ) ) {
			$target = (string) home_url( '/?lafka_review_unsubscribed=1' );
			wp_safe_redirect( $target );
			if ( ! ( defined( 'LAFKA_TESTING' ) && LAFKA_TESTING ) ) {
				exit;
			}
		}
	}
}

if ( function_exists( 'add_action' ) ) {
	add_action( 'init', 'lafka_review_email_handle_unsubscribe_request', 5 );
}

// ─────────────────────────────────────────────────────────────────────────────
// Email body renderer.
// ─────────────────────────────────────────────────────────────────────────────

if ( ! function_exists( 'lafka_review_email_resolve_target_url' ) ) {
	/**
	 * Build the URL a star tap should open. When the operator has configured a
	 * Google/Yelp review URL we use that, appending the chosen rating as a
	 * `?rating=N` query arg so the operator can tag the inbound traffic in
	 * their analytics. When unset we fall back to the order-received page so
	 * the email still has a working link (the customer can hit "Leave a review"
	 * on each product themselves).
	 *
	 * @param int $rating 1–5
	 * @param object $order WC_Order
	 * @return string
	 */
	function lafka_review_email_resolve_target_url( int $rating, $order ): string {
		$rating = max( 1, min( 5, $rating ) );
		$base   = lafka_review_target_url();
		if ( '' === $base ) {
			// Fallback — link the customer to a page with their order's first
			// product's reviews tab, or just the shop.
			if ( is_object( $order ) && method_exists( $order, 'get_items' ) ) {
				foreach ( $order->get_items() as $item ) {
					if ( is_object( $item ) && method_exists( $item, 'get_product_id' ) ) {
						$pid = (int) $item->get_product_id();
						if ( $pid > 0 && function_exists( 'get_permalink' ) ) {
							$link = (string) get_permalink( $pid );
							if ( '' !== $link ) {
								return $link . '#reviews';
							}
						}
					}
				}
			}
			return function_exists( 'home_url' ) ? (string) home_url( '/' ) : '/';
		}
		if ( function_exists( 'add_query_arg' ) ) {
			return (string) add_query_arg( array( 'rating' => $rating ), $base );
		}
		$sep = ( false === strpos( $base, '?' ) ) ? '?' : '&';
		return $base . $sep . 'rating=' . rawurlencode( (string) $rating );
	}
}

if ( ! function_exists( 'lafka_review_email_render_body' ) ) {
	/**
	 * Render the HTML body of the review-prompt email.
	 *
	 * Inlined-style markup so it survives Gmail / Outlook. Uses WC's own email
	 * header + footer actions so the wrapper matches every other transactional
	 * email from the store.
	 *
	 * @param object $order          WC_Order
	 * @param object $email_instance The WC_Email child instance (for header/footer)
	 * @return string
	 */
	function lafka_review_email_render_body( $order, $email_instance = null ): string {
		if ( ! is_object( $order ) ) {
			return '';
		}

		$first_name = method_exists( $order, 'get_billing_first_name' )
			? (string) $order->get_billing_first_name()
			: '';
		if ( '' === $first_name ) {
			$first_name = function_exists( '__' ) ? __( 'there', 'lafka-plugin' ) : 'there';
		}
		$order_number = method_exists( $order, 'get_order_number' )
			? (string) $order->get_order_number()
			: '';
		$user_id      = method_exists( $order, 'get_customer_id' ) ? (int) $order->get_customer_id() : 0;

		$intro      = lafka_review_email_intro();
		$cta_label  = lafka_review_target_label();
		$heading    = sprintf(
			/* translators: %s: customer first name */
			function_exists( '__' ) ? __( 'How was your order, %s?', 'lafka-plugin' ) : 'How was your order, %s?',
			$first_name
		);

		ob_start();

		// Inherit WC email styling — header + footer wrap the body.
		if ( function_exists( 'do_action' ) ) {
			do_action( 'woocommerce_email_header', $heading, $email_instance );
		}

		?>
		<p style="margin:0 0 16px 0;font-size:16px;line-height:1.5;color:#333;">
			<?php echo esc_html( $intro ); ?>
		</p>

		<?php if ( '' !== $order_number ) : ?>
			<p style="margin:0 0 24px 0;font-size:14px;line-height:1.5;color:#666;">
				<?php
				printf(
					/* translators: %s: order number */
					esc_html( function_exists( '__' ) ? __( 'Order #%s', 'lafka-plugin' ) : 'Order #%s' ),
					esc_html( $order_number )
				);
				?>
			</p>
		<?php endif; ?>

		<p style="margin:0 0 16px 0;font-size:16px;line-height:1.5;color:#333;font-weight:600;">
			<?php esc_html_e( 'Tap a star to rate your experience:', 'lafka-plugin' ); ?>
		</p>

		<table cellpadding="0" cellspacing="0" border="0" style="margin:0 auto 24px;border-collapse:collapse;">
			<tr>
				<?php for ( $i = 1; $i <= 5; $i++ ) : ?>
					<td style="padding:0 4px;">
						<a href="<?php echo esc_url( lafka_review_email_resolve_target_url( $i, $order ) ); ?>"
							style="display:inline-block;text-decoration:none;font-size:36px;line-height:1;color:#f5b400;">
							<?php
							echo esc_html(
								// Five "★" chars in a row so the rating contour is obvious in clients
								// that strip CSS — older Outlook in particular.
								html_entity_decode( '&#9733;', ENT_QUOTES, 'UTF-8' )
							);
							?>
						</a>
					</td>
				<?php endfor; ?>
			</tr>
		</table>

		<p style="margin:24px 0;text-align:center;">
			<a href="<?php echo esc_url( lafka_review_email_resolve_target_url( 5, $order ) ); ?>"
				style="display:inline-block;background:#43a047;color:#ffffff;text-decoration:none;padding:14px 28px;border-radius:999px;font-size:16px;font-weight:600;">
				<?php echo esc_html( $cta_label ); ?>
			</a>
		</p>

		<p style="margin:32px 0 0 0;font-size:12px;line-height:1.5;color:#999;text-align:center;">
			<?php esc_html_e( 'Thanks for your support — every rating helps us grow.', 'lafka-plugin' ); ?>
			<?php
			$unsub = lafka_review_email_unsubscribe_url( $user_id );
			if ( '' !== $unsub ) :
				?>
				<br>
				<a href="<?php echo esc_url( $unsub ); ?>" style="color:#999;text-decoration:underline;">
					<?php esc_html_e( 'Unsubscribe from review emails', 'lafka-plugin' ); ?>
				</a>
			<?php endif; ?>
		</p>
		<?php

		if ( function_exists( 'do_action' ) ) {
			do_action( 'woocommerce_email_footer', $email_instance );
		}

		return (string) ob_get_clean();
	}
}

if ( ! function_exists( 'lafka_review_email_render_preview' ) ) {
	/**
	 * Preview pane in /wp-admin/admin.php?page=wc-settings&tab=email — synthesise
	 * an order-shaped object so the body renders without needing a live order.
	 *
	 * @param object $email_instance
	 * @return string
	 */
	function lafka_review_email_render_preview( $email_instance ): string {
		$preview = new \stdClass();
		// Minimal duck-typed object mimicking the WC_Order methods the renderer
		// + class actually call. Stays inert in production paths because the
		// preview body is only reachable from WC's email-settings admin screen.
		$preview->billing_first_name = 'Preview';
		$preview->order_number       = 'PREVIEW-0001';
		$preview->customer_id        = 0;
		$preview->items              = array();

		$preview_proxy = new class( $preview ) {
			private $d;
			public function __construct( $data ) {
				$this->d = $data;
			}
			public function get_billing_first_name(): string {
				return (string) $this->d->billing_first_name;
			}
			public function get_order_number(): string {
				return (string) $this->d->order_number;
			}
			public function get_customer_id(): int {
				return (int) $this->d->customer_id;
			}
			public function get_items(): array {
				return is_array( $this->d->items ) ? $this->d->items : array();
			}
			public function get_billing_email(): string {
				return 'preview@example.com';
			}
		};

		return lafka_review_email_render_body( $preview_proxy, $email_instance );
	}
}
