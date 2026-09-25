<?php
/**
 * GX (T-25): load third-party front-end assets only where they can run.
 *
 * Contact Form 7 and the payment gateways enqueue their CSS + JS on every
 * front-end page. On a restaurant site that is ~10 requests of dead weight on
 * the home page, the menu and every product page:
 *
 *  1. Contact Form 7 — CF7's own `wpcf7_load_js` / `wpcf7_load_css` filters
 *     answer "yes" only on a singular page whose content embeds a form
 *     (`[contact-form-7 …]`, the legacy `[contact-form …]`, or the CF7 block).
 *     A form rendered from anywhere else (a widget, a template) still works:
 *     `do_shortcode_tag` enqueues CF7's assets late (footer scripts, late
 *     styles). Override: `lafka_cf7_assets_needed` (bool).
 *
 *  2. Payment gateways — handles matching `lafka_gateway_asset_patterns`
 *     (default: the SkyVerge payment-form framework and Authorize.Net CIM)
 *     are dequeued outside cart / checkout / account / order-pay /
 *     add-payment-method. Express-pay handles (Apple Pay, Google Pay,
 *     payment request, "express") are never touched. Runs after the
 *     gateways enqueue and again right before styles / footer scripts print,
 *     for gateways that enqueue late. Override: `lafka_gateway_assets_needed`
 *     (bool, true = keep everything).
 *
 * @package LafkaPlugin
 * @since   10.3.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'lafka_cf7_content_has_form' ) ) {
	/**
	 * Whether post content embeds a Contact Form 7 form.
	 *
	 * @param string $content Post content.
	 * @return bool
	 */
	function lafka_cf7_content_has_form( string $content ): bool {
		return false !== strpos( $content, '[contact-form-7' )
			|| (bool) preg_match( '/\[contact-form[\s\]]/', $content )
			|| false !== strpos( $content, 'wp:contact-form-7/' );
	}
}

if ( ! function_exists( 'lafka_cf7_assets_needed' ) ) {
	/**
	 * Whether the current request needs Contact Form 7's assets up front.
	 *
	 * @return bool
	 */
	function lafka_cf7_assets_needed(): bool {
		$needed = false;
		if ( is_singular() ) {
			$post   = get_post();
			$needed = is_object( $post ) && lafka_cf7_content_has_form( (string) ( $post->post_content ?? '' ) );
		}

		/**
		 * Filter whether Contact Form 7's JS + CSS load on this request.
		 *
		 * @since 10.3.0
		 * @param bool $needed True when the page content embeds a form.
		 */
		return (bool) apply_filters( 'lafka_cf7_assets_needed', $needed );
	}
}

if ( ! function_exists( 'lafka_cf7_load_assets' ) ) {
	/**
	 * `wpcf7_load_js` / `wpcf7_load_css`: load only where a form can render.
	 * An explicit "off" (WPCF7_LOAD_JS / WPCF7_LOAD_CSS = false) is kept.
	 *
	 * @param mixed $load CF7's current decision.
	 * @return bool
	 */
	function lafka_cf7_load_assets( $load ) {
		if ( ! $load ) {
			return false;
		}
		if ( is_admin() ) {
			return (bool) $load;
		}
		return lafka_cf7_assets_needed();
	}
}

if ( ! function_exists( 'lafka_cf7_late_enqueue' ) ) {
	/**
	 * `do_shortcode_tag`: a CF7 form rendered outside the page content (a
	 * widget, a template, the block) enqueues CF7's assets late so it still
	 * validates and submits over AJAX. Output is returned unchanged.
	 *
	 * @param string $output Shortcode output.
	 * @param string $tag    Shortcode tag.
	 * @return string
	 */
	function lafka_cf7_late_enqueue( $output, $tag ) {
		if ( ! in_array( (string) $tag, array( 'contact-form-7', 'contact-form' ), true ) ) {
			return $output;
		}
		if ( function_exists( 'wpcf7_enqueue_scripts' ) && ! wp_script_is( 'contact-form-7', 'enqueued' ) ) {
			wpcf7_enqueue_scripts();
			if ( function_exists( 'wpcf7_enqueue_styles' ) ) {
				wpcf7_enqueue_styles();
			}
		}
		return $output;
	}
}

if ( ! function_exists( 'lafka_gateway_asset_patterns' ) ) {
	/**
	 * Handle prefixes of payment-gateway assets that only a payment form uses.
	 *
	 * @return list<string>
	 */
	function lafka_gateway_asset_patterns(): array {
		$patterns = array(
			'sv-wc-payment-gateway-payment-form', // SkyVerge framework (versioned suffix).
			'wc-authorize-net-cim',               // Authorize.Net CIM card / eCheck.
		);

		/**
		 * Filter the handle prefixes dequeued outside the payment pages.
		 *
		 * @since 10.3.0
		 * @param list<string> $patterns Handle prefixes.
		 */
		$patterns = (array) apply_filters( 'lafka_gateway_asset_patterns', $patterns );
		return array_values( array_filter( array_map( 'strval', $patterns ) ) );
	}
}

if ( ! function_exists( 'lafka_gateway_asset_is_express' ) ) {
	/**
	 * Express-pay handles (Apple Pay, Google Pay, payment request buttons)
	 * can render on product and cart pages — never dequeued.
	 *
	 * @param string $handle Asset handle.
	 * @return bool
	 */
	function lafka_gateway_asset_is_express( string $handle ): bool {
		return (bool) preg_match( '/apple[-_]?pay|google[-_]?pay|express|payment[-_]request/i', $handle );
	}
}

if ( ! function_exists( 'lafka_gateway_assets_needed' ) ) {
	/**
	 * Whether a payment form can render on this request.
	 *
	 * @return bool
	 */
	function lafka_gateway_assets_needed(): bool {
		$needed = ( function_exists( 'is_cart' ) && is_cart() )
			|| ( function_exists( 'is_checkout' ) && is_checkout() )
			|| ( function_exists( 'is_account_page' ) && is_account_page() )
			|| ( function_exists( 'is_checkout_pay_page' ) && is_checkout_pay_page() )
			|| ( function_exists( 'is_add_payment_method_page' ) && is_add_payment_method_page() )
			|| ( function_exists( 'is_wc_endpoint_url' ) && ( is_wc_endpoint_url( 'order-pay' ) || is_wc_endpoint_url( 'add-payment-method' ) ) );

		/**
		 * Filter whether payment-gateway assets stay on this request.
		 *
		 * @since 10.3.0
		 * @param bool $needed True on cart / checkout / account / order-pay.
		 */
		return (bool) apply_filters( 'lafka_gateway_assets_needed', $needed );
	}
}

if ( ! function_exists( 'lafka_dequeue_gateway_assets' ) ) {
	/**
	 * Dequeue payment-form assets where no payment form can render.
	 *
	 * @return void
	 */
	function lafka_dequeue_gateway_assets() {
		if ( is_admin() || lafka_gateway_assets_needed() ) {
			return;
		}
		$patterns = lafka_gateway_asset_patterns();
		if ( empty( $patterns ) ) {
			return;
		}
		$registries = array(
			'style'  => function_exists( 'wp_styles' ) ? wp_styles() : null,
			'script' => function_exists( 'wp_scripts' ) ? wp_scripts() : null,
		);
		foreach ( $registries as $kind => $registry ) {
			if ( ! is_object( $registry ) || empty( $registry->queue ) ) {
				continue;
			}
			foreach ( (array) $registry->queue as $handle ) {
				$handle = (string) $handle;
				if ( lafka_gateway_asset_is_express( $handle ) ) {
					continue;
				}
				foreach ( $patterns as $prefix ) {
					if ( 0 === strpos( $handle, $prefix ) ) {
						if ( 'style' === $kind ) {
							wp_dequeue_style( $handle );
						} else {
							wp_dequeue_script( $handle );
						}
						break;
					}
				}
			}
		}
	}
}

add_filter( 'wpcf7_load_js', 'lafka_cf7_load_assets', 20 );
add_filter( 'wpcf7_load_css', 'lafka_cf7_load_assets', 20 );
add_filter( 'do_shortcode_tag', 'lafka_cf7_late_enqueue', 10, 2 );
add_action( 'wp_enqueue_scripts', 'lafka_dequeue_gateway_assets', 100 );
add_action( 'wp_print_styles', 'lafka_dequeue_gateway_assets', 1 );
add_action( 'wp_print_footer_scripts', 'lafka_dequeue_gateway_assets', 1 );
