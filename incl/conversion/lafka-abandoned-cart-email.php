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
