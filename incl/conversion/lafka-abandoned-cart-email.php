<?php
/**
 * Phase 3B (v9.27.0): Abandoned-cart recovery — email class.
 *
 * Registers `LAFKA_Abandoned_Cart_Email` (extends `WC_Email`) through the
 * `woocommerce_email_classes` filter so the email inherits WooCommerce's
 * own header/footer/styling — no separate brand drift between this email and
 * the order-confirmation flow.
 *
 * The class is loaded lazily — registration happens inside the filter handler,
 * which fires after `WC_Email` is available. Pattern matches
 * incl/kitchen-display/class-lafka-kitchen-display.php::register_emails().
 *
 * Trigger path: cron handler emits `do_action( 'lafka_abandoned_cart_email_trigger', $row )`;
 * the class instance binds to that action in its constructor and runs `trigger()`.
 *
 * Customizer-configurable copy:
 *   lafka_ac_subject         email subject (supports {site} placeholder)
 *   lafka_ac_intro_heading   H2 heading at the top of the body
 *   lafka_ac_intro_body      paragraph beneath the heading
 *   lafka_ac_cta_label       button text on the resume CTA
 *
 * @package Lafka\Plugin\Conversion
 * @since   9.27.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'lafka_ac_register_email_class' ) ) {
	/**
	 * `woocommerce_email_classes` filter handler.
	 *
	 * Lazy-loads the class definition (WC_Email must exist first) and registers
	 * a fresh instance into the array.
	 *
	 * @param array $email_classes
	 * @return array
	 */
	function lafka_ac_register_email_class( $email_classes ) {
		$email_classes = is_array( $email_classes ) ? $email_classes : array();

		if ( ! class_exists( 'WC_Email' ) ) {
			return $email_classes;
		}
		if ( ! class_exists( 'LAFKA_Abandoned_Cart_Email' ) ) {
			require_once __DIR__ . '/class-lafka-abandoned-cart-email-class.php';
		}
		if ( class_exists( 'LAFKA_Abandoned_Cart_Email' ) ) {
			$email_classes['LAFKA_Abandoned_Cart_Email'] = new LAFKA_Abandoned_Cart_Email();
		}
		return $email_classes;
	}
}

if ( function_exists( 'add_filter' ) ) {
	add_filter( 'woocommerce_email_classes', 'lafka_ac_register_email_class' );
}

if ( ! function_exists( 'lafka_ac_email_subject_default' ) ) {
	/**
	 * Default subject line. Reads Customizer override + `{site}` token expansion.
	 *
	 * @return string
	 */
	function lafka_ac_email_subject_default(): string {
		$tmpl = function_exists( 'get_theme_mod' )
			? (string) get_theme_mod( 'lafka_ac_subject', '' )
			: '';
		if ( '' === trim( $tmpl ) ) {
			$tmpl = 'Did you forget something? Your cart is waiting at {site}';
		}
		$site = function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'name' ) : '';
		return str_replace( '{site}', $site, $tmpl );
	}
}

if ( ! function_exists( 'lafka_ac_email_intro_heading' ) ) {
	function lafka_ac_email_intro_heading(): string {
		$value = function_exists( 'get_theme_mod' )
			? (string) get_theme_mod( 'lafka_ac_intro_heading', '' )
			: '';
		return '' === trim( $value ) ? 'Your cart is still here' : $value;
	}
}

if ( ! function_exists( 'lafka_ac_email_intro_body' ) ) {
	function lafka_ac_email_intro_body(): string {
		$value = function_exists( 'get_theme_mod' )
			? (string) get_theme_mod( 'lafka_ac_intro_body', '' )
			: '';
		return '' === trim( $value )
			? 'We saved your selection. Tap below to pick up where you left off.'
			: $value;
	}
}

if ( ! function_exists( 'lafka_ac_email_cta_label' ) ) {
	function lafka_ac_email_cta_label(): string {
		$value = function_exists( 'get_theme_mod' )
			? (string) get_theme_mod( 'lafka_ac_cta_label', '' )
			: '';
		return '' === trim( $value ) ? 'Resume my order' : $value;
	}
}

if ( ! function_exists( 'lafka_ac_email_resume_url' ) ) {
	/**
	 * Build the resume URL: home_url() + ?lafka_resume_cart={token}
	 *
	 * @param string $token
	 * @return string
	 */
	function lafka_ac_email_resume_url( string $token ): string {
		if ( '' === $token ) {
			return '';
		}
		$base = function_exists( 'home_url' ) ? (string) home_url( '/' ) : '/';
		if ( function_exists( 'add_query_arg' ) ) {
			return (string) add_query_arg( array( 'lafka_resume_cart' => $token ), $base );
		}
		$sep = ( false === strpos( $base, '?' ) ) ? '?' : '&';
		return $base . $sep . 'lafka_resume_cart=' . rawurlencode( $token );
	}
}

// ─────────────────────────────────────────────────────────────────────────────
// Generic per-recipient unsubscribe (CAN-SPAM / GDPR).
//
// Shared by the abandoned-cart recovery email and the review-prompt email so a
// guest-checkout customer (no WP user, no user meta) still gets a working
// opt-out. The recipient EMAIL is tokenized (not a user_id), and the opt-out is
// persisted in a dedicated option keyed by a salt-independent hash of the email
// so the preference survives wp_salt() rotation; the URL token itself is an HMAC
// (wp_salt) so it cannot be forged.
//
// Defined behind function_exists guards because the abandoned-cart email module
// loads before the review-prompt module — whichever loads first wins, and each
// module stays self-sufficient when loaded in isolation (unit tests).
// ─────────────────────────────────────────────────────────────────────────────

if ( ! function_exists( 'lafka_unsub_option_name' ) ) {
	/**
	 * Option that stores the per-recipient opt-out list (assoc: hash => ts).
	 *
	 * @return string
	 */
	function lafka_unsub_option_name(): string {
		return 'lafka_email_unsub_list';
	}
}

if ( ! function_exists( 'lafka_unsub_token' ) ) {
	/**
	 * Forgery-resistant token for a recipient email.
	 *
	 *   token = HMAC-SHA256( "lafka-unsub:{lower(email)}", wp_salt() )
	 *
	 * @param string $email
	 * @return string Empty string when $email is empty.
	 */
	function lafka_unsub_token( string $email ): string {
		$email = strtolower( trim( $email ) );
		if ( '' === $email ) {
			return '';
		}
		$salt = function_exists( 'wp_salt' ) ? (string) wp_salt() : 'lafka-default-salt';
		return hash_hmac( 'sha256', 'lafka-unsub:' . $email, $salt );
	}
}

if ( ! function_exists( 'lafka_unsub_store_key' ) ) {
	/**
	 * Salt-independent storage key for a recipient email. Decoupled from
	 * wp_salt() so the opt-out preference is permanent across salt rotation.
	 *
	 * @param string $email
	 * @return string Empty string when $email is empty.
	 */
	function lafka_unsub_store_key( string $email ): string {
		$email = strtolower( trim( $email ) );
		if ( '' === $email ) {
			return '';
		}
		return hash( 'sha256', 'lafka-unsub-store:' . $email );
	}
}

if ( ! function_exists( 'lafka_unsub_url' ) ) {
	/**
	 * Build the unsubscribe URL: home_url() + ?lafka_unsubscribe={TOKEN}&e={email}
	 *
	 * @param string $email
	 * @return string Empty string when $email is empty.
	 */
	function lafka_unsub_url( string $email ): string {
		$email = strtolower( trim( $email ) );
		if ( '' === $email ) {
			return '';
		}
		$token = lafka_unsub_token( $email );
		if ( '' === $token ) {
			return '';
		}
		$base = function_exists( 'home_url' ) ? (string) home_url( '/' ) : '/';
		if ( function_exists( 'add_query_arg' ) ) {
			return (string) add_query_arg(
				array(
					'lafka_unsubscribe' => $token,
					'e'                 => $email,
				),
				$base
			);
		}
		$sep = ( false === strpos( $base, '?' ) ) ? '?' : '&';
		return $base . $sep . 'lafka_unsubscribe=' . rawurlencode( $token ) . '&e=' . rawurlencode( $email );
	}
}

if ( ! function_exists( 'lafka_unsub_is_opted_out' ) ) {
	/**
	 * Is the recipient email on the persisted per-recipient opt-out list?
	 *
	 * @param string $email
	 * @return bool
	 */
	function lafka_unsub_is_opted_out( string $email ): bool {
		$key = lafka_unsub_store_key( $email );
		if ( '' === $key || ! function_exists( 'get_option' ) ) {
			return false;
		}
		// Reading the opt-out store must never fatal an email-eligibility decision.
		try {
			$list = get_option( lafka_unsub_option_name(), array() );
		} catch ( \Throwable $e ) {
			return false;
		}
		return is_array( $list ) && isset( $list[ $key ] );
	}
}

if ( ! function_exists( 'lafka_unsub_record_opt_out' ) ) {
	/**
	 * Persist a per-recipient opt-out. Idempotent.
	 *
	 * @param string $email
	 * @return void
	 */
	function lafka_unsub_record_opt_out( string $email ): void {
		$key = lafka_unsub_store_key( $email );
		if ( '' === $key || ! function_exists( 'get_option' ) || ! function_exists( 'update_option' ) ) {
			return;
		}
		// A storage failure must not 500 the unsubscribe request.
		try {
			$list = get_option( lafka_unsub_option_name(), array() );
			if ( ! is_array( $list ) ) {
				$list = array();
			}
			if ( isset( $list[ $key ] ) ) {
				return;
			}
			$list[ $key ] = time();
			update_option( lafka_unsub_option_name(), $list, false );
		} catch ( \Throwable $e ) {
			return;
		}
	}
}

if ( ! function_exists( 'lafka_unsub_postal_address' ) ) {
	/**
	 * Operator's physical postal address for the CAN-SPAM footer. Sourced from
	 * the single NAP source of truth (Customizer-driven), with a fallback to the
	 * WooCommerce store-address options. Never hardcoded.
	 *
	 * @return string Comma-separated address, or '' when unconfigured.
	 */
	function lafka_unsub_postal_address(): string {
		$parts = array();
		if ( function_exists( 'lafka_schema_get_nap' ) ) {
			$nap   = lafka_schema_get_nap();
			$parts = array(
				isset( $nap['name'] ) ? (string) $nap['name'] : '',
				isset( $nap['street'] ) ? (string) $nap['street'] : '',
				trim(
					( isset( $nap['city'] ) ? (string) $nap['city'] : '' ) . ', '
					. ( isset( $nap['region'] ) ? (string) $nap['region'] : '' ) . ' '
					. ( isset( $nap['postal'] ) ? (string) $nap['postal'] : '' ),
					' ,'
				),
				isset( $nap['country'] ) ? (string) $nap['country'] : '',
			);
		} elseif ( function_exists( 'get_option' ) ) {
			$parts = array(
				(string) get_option( 'woocommerce_store_address', '' ),
				(string) get_option( 'woocommerce_store_address_2', '' ),
				(string) get_option( 'woocommerce_store_city', '' ),
				(string) get_option( 'woocommerce_store_postcode', '' ),
			);
		}
		$parts = array_filter(
			array_map(
				static function ( $part ) {
					return trim( (string) $part );
				},
				$parts
			)
		);
		return implode( ', ', $parts );
	}
}

if ( ! function_exists( 'lafka_unsub_handle_request' ) ) {
	/**
	 * `init` handler — opt the recipient out when ?lafka_unsubscribe + ?e are
	 * present and the HMAC token matches. Works for both a GET click from the
	 * email body and an RFC 8058 one-click POST (the query string populates $_GET
	 * regardless of method). No confirmation page — matches the existing
	 * review-prompt unsubscribe behaviour.
	 *
	 * @return void
	 */
	function lafka_unsub_handle_request(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- HMAC token gates the action; opt-out is not a credentialed state change.
		if ( ! isset( $_GET['lafka_unsubscribe'] ) || ! isset( $_GET['e'] ) ) {
			return;
		}
		$token = ( is_string( $_GET['lafka_unsubscribe'] ) && function_exists( 'sanitize_text_field' ) && function_exists( 'wp_unslash' ) )
			? sanitize_text_field( wp_unslash( $_GET['lafka_unsubscribe'] ) )
			: '';
		$email = '';
		if ( is_string( $_GET['e'] ) ) {
			$raw   = function_exists( 'wp_unslash' ) ? wp_unslash( $_GET['e'] ) : $_GET['e'];
			$email = function_exists( 'sanitize_email' ) ? sanitize_email( $raw ) : (string) $raw;
		}
		// phpcs:enable WordPress.Security.NonceVerification.Recommended

		if ( '' === $token || '' === $email ) {
			return;
		}
		$expected = lafka_unsub_token( $email );
		if ( '' === $expected || ! hash_equals( $expected, $token ) ) {
			return;
		}

		lafka_unsub_record_opt_out( $email );

		if ( function_exists( 'wp_safe_redirect' ) && function_exists( 'home_url' ) ) {
			wp_safe_redirect( (string) home_url( '/?lafka_email_unsubscribed=1' ) );
			if ( ! ( defined( 'LAFKA_TESTING' ) && LAFKA_TESTING ) ) {
				exit;
			}
		}
	}
}

if ( ! function_exists( 'lafka_unsub_email_headers' ) ) {
	/**
	 * `woocommerce_email_headers` filter — append a List-Unsubscribe (+ RFC 8058
	 * one-click) header to the Lafka marketing-class emails. Scoped by email id;
	 * the per-recipient URL is built from the live WC_Email instance recipient.
	 *
	 * @param string $headers
	 * @param string $email_id
	 * @param mixed  $object
	 * @param mixed  $email
	 * @return string
	 */
	function lafka_unsub_email_headers( $headers, $email_id = '', $object = null, $email = null ): string {
		$headers = (string) $headers;
		$ids     = array( 'lafka_abandoned_cart', 'lafka_review_prompt' );
		if ( ! in_array( (string) $email_id, $ids, true ) ) {
			return $headers;
		}
		$recipient = '';
		if ( is_object( $email ) && method_exists( $email, 'get_recipient' ) ) {
			$recipient = (string) $email->get_recipient();
		} elseif ( is_object( $email ) && isset( $email->recipient ) ) {
			$recipient = (string) $email->recipient;
		}
		// Fallback to the order object (review email; older 3-arg WC signature).
		if ( '' === $recipient && is_object( $object ) && method_exists( $object, 'get_billing_email' ) ) {
			$recipient = (string) $object->get_billing_email();
		}
		// Defend against a comma-joined recipient list — token the first address.
		if ( false !== strpos( $recipient, ',' ) ) {
			$first     = explode( ',', $recipient );
			$recipient = (string) reset( $first );
		}
		$recipient = trim( $recipient );
		if ( '' === $recipient ) {
			return $headers;
		}
		$url = lafka_unsub_url( $recipient );
		if ( '' === $url ) {
			return $headers;
		}
		if ( '' !== $headers && "\n" !== substr( $headers, -1 ) ) {
			$headers .= "\r\n";
		}
		$headers .= 'List-Unsubscribe: <' . $url . ">\r\n";
		$headers .= "List-Unsubscribe-Post: List-Unsubscribe=One-Click\r\n";
		return $headers;
	}
}

if ( function_exists( 'add_action' ) ) {
	add_action( 'init', 'lafka_unsub_handle_request', 5 );
}
if ( function_exists( 'add_filter' ) ) {
	add_filter( 'woocommerce_email_headers', 'lafka_unsub_email_headers', 10, 4 );
}

if ( ! function_exists( 'lafka_ac_render_email_body' ) ) {
	/**
	 * Render the HTML body of the recovery email.
	 *
	 * Inlined-style markup so it survives Gmail / Outlook (no <style> block).
	 * Uses WC's own email header + footer actions so the wrapper matches every
	 * other transactional email from the store.
	 *
	 * @param object $row             Row from wp_lafka_abandoned_carts.
	 * @param object $email_instance  The WC_Email child instance (for header/footer).
	 * @return string
	 */
	function lafka_ac_render_email_body( $row, $email_instance = null ): string {
		$contents = isset( $row->cart_contents ) ? (string) $row->cart_contents : '';
		$decoded  = json_decode( $contents, true );
		if ( ! is_array( $decoded ) || empty( $decoded['items'] ) ) {
			return '';
		}

		// Per-recipient opt-out (CAN-SPAM/GDPR): a guest who unsubscribed has no
		// WP user meta, so honour the email-keyed opt-out store here. Returning ''
		// makes LAFKA_Abandoned_Cart_Email::trigger() skip the send.
		$recipient = isset( $row->customer_email ) ? (string) $row->customer_email : '';
		if ( function_exists( 'lafka_unsub_is_opted_out' ) && lafka_unsub_is_opted_out( $recipient ) ) {
			return '';
		}

		$items    = $decoded['items'];
		$subtotal = isset( $decoded['subtotal'] ) ? (float) $decoded['subtotal'] : 0.0;
		$currency = isset( $decoded['currency'] ) ? (string) $decoded['currency'] : '';
		$token    = isset( $row->resume_token ) ? (string) $row->resume_token : '';

		$heading    = lafka_ac_email_intro_heading();
		$body_copy  = lafka_ac_email_intro_body();
		$cta_label  = lafka_ac_email_cta_label();
		$resume_url = lafka_ac_email_resume_url( $token );

		ob_start();

		// Inherit WC email styling — header + footer wrap the body.
		if ( function_exists( 'do_action' ) ) {
			do_action( 'woocommerce_email_header', $heading, $email_instance );
		}

		?>
		<p style="margin:0 0 16px 0;font-size:16px;line-height:1.5;color:#333;">
			<?php echo esc_html( $body_copy ); ?>
		</p>

		<table cellpadding="0" cellspacing="0" border="0" width="100%" style="margin:24px 0;border-collapse:collapse;">
			<thead>
				<tr>
					<th align="left" style="padding:8px;border-bottom:1px solid #e5e5e5;font-size:13px;color:#666;">Item</th>
					<th align="center" style="padding:8px;border-bottom:1px solid #e5e5e5;font-size:13px;color:#666;">Qty</th>
					<th align="right" style="padding:8px;border-bottom:1px solid #e5e5e5;font-size:13px;color:#666;">Price</th>
				</tr>
			</thead>
			<tbody>
				<?php foreach ( $items as $item ) : ?>
					<?php
					$name       = isset( $item['name'] ) ? (string) $item['name'] : '';
					$qty        = isset( $item['quantity'] ) ? (int) $item['quantity'] : 0;
					$price      = isset( $item['price'] ) ? (float) $item['price'] : 0.0;
					$image      = isset( $item['image'] ) ? (string) $item['image'] : '';
					$price_html = function_exists( 'wc_price' )
						? wc_price( $price, array( 'currency' => $currency ) )
						: number_format( $price, 2 );
					?>
					<tr>
						<td align="left" style="padding:12px 8px;border-bottom:1px solid #f0f0f0;font-size:15px;color:#222;">
							<?php if ( '' !== $image ) : ?>
								<img src="<?php echo esc_url( $image ); ?>"
									alt="<?php echo esc_attr( $name ); ?>"
									width="48" height="48"
									style="vertical-align:middle;border-radius:6px;margin-right:12px;">
							<?php endif; ?>
							<?php echo esc_html( $name ); ?>
						</td>
						<td align="center" style="padding:12px 8px;border-bottom:1px solid #f0f0f0;font-size:15px;color:#222;">
							<?php echo (int) $qty; ?>
						</td>
						<td align="right" style="padding:12px 8px;border-bottom:1px solid #f0f0f0;font-size:15px;color:#222;">
							<?php echo wp_kses_post( $price_html ); ?>
						</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
			<tfoot>
				<tr>
					<td colspan="2" align="right" style="padding:12px 8px;font-weight:600;font-size:15px;color:#222;">
						<?php esc_html_e( 'Subtotal', 'lafka-plugin' ); ?>
					</td>
					<td align="right" style="padding:12px 8px;font-weight:600;font-size:15px;color:#222;">
						<?php
						$subtotal_html = function_exists( 'wc_price' )
							? wc_price( $subtotal, array( 'currency' => $currency ) )
							: number_format( $subtotal, 2 );
						echo wp_kses_post( $subtotal_html );
						?>
					</td>
				</tr>
			</tfoot>
		</table>

		<p style="margin:24px 0;text-align:center;">
			<a href="<?php echo esc_url( $resume_url ); ?>"
				style="display:inline-block;background:#e53935;color:#ffffff;text-decoration:none;padding:14px 28px;border-radius:999px;font-size:16px;font-weight:600;">
				<?php echo esc_html( $cta_label ); ?>
			</a>
		</p>

		<p style="margin:24px 0 0 0;font-size:13px;line-height:1.5;color:#999;">
			<?php esc_html_e( 'If this wasn\'t you, you can safely ignore this email — we won\'t send another reminder.', 'lafka-plugin' ); ?>
		</p>

		<?php
		$ac_unsub_url   = function_exists( 'lafka_unsub_url' ) ? lafka_unsub_url( $recipient ) : '';
		$ac_postal_addr = function_exists( 'lafka_unsub_postal_address' ) ? lafka_unsub_postal_address() : '';
		?>
		<p style="margin:12px 0 0 0;font-size:12px;line-height:1.5;color:#999;">
			<?php if ( '' !== $ac_unsub_url ) : ?>
				<a href="<?php echo esc_url( $ac_unsub_url ); ?>" style="color:#999;text-decoration:underline;">
					<?php esc_html_e( 'Unsubscribe from these emails', 'lafka-plugin' ); ?>
				</a>
			<?php endif; ?>
			<?php if ( '' !== $ac_postal_addr ) : ?>
				<br>
				<span><?php echo esc_html( $ac_postal_addr ); ?></span>
			<?php endif; ?>
		</p>
		<?php

		if ( function_exists( 'do_action' ) ) {
			do_action( 'woocommerce_email_footer', $email_instance );
		}

		return (string) ob_get_clean();
	}
}

// Eagerly load the WC_Email child class file when WC is available — keeps the
// class definition in its own file so the autoloader/source-grep tests stay clean.
if ( ! class_exists( 'LAFKA_Abandoned_Cart_Email' ) && class_exists( 'WC_Email' ) ) {
	require_once __DIR__ . '/class-lafka-abandoned-cart-email-class.php';
}
