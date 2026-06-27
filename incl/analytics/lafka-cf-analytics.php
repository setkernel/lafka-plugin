<?php
/**
 * Cloudflare Web Analytics beacon.
 *
 * Emits the cookieless Cloudflare Web Analytics beacon when an operator has
 * pasted their site token in Customizer → "Lafka — Analytics" → Direct IDs.
 *
 * Unlike GA4/Clarity/Pixel (which route through GTM + Consent Mode v2), the CF
 * beacon is COOKIELESS and privacy-first, so it is emitted directly on every
 * front-end page and is NOT gated by consent or by GTM. When no token is set,
 * nothing is emitted — keeps the OSS plugin free of any account-specific id.
 *
 * @package Lafka\Plugin\Analytics
 * @since   9.31.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'lafka_analytics_cf_beacon_token' ) ) {
	/**
	 * Operator-configured Cloudflare Web Analytics token (32 hex chars).
	 *
	 * @return string Sanitised token, or '' when unset/invalid.
	 */
	function lafka_analytics_cf_beacon_token(): string {
		$token = (string) get_theme_mod( 'lafka_cf_beacon_token', '' );
		$token = strtolower( trim( $token ) );
		return preg_match( '/^[a-f0-9]{32}$/', $token ) ? $token : '';
	}
}

if ( ! function_exists( 'lafka_analytics_emit_cf_beacon' ) ) {
	add_action( 'wp_footer', 'lafka_analytics_emit_cf_beacon', 20 );

	/**
	 * Print the Cloudflare Web Analytics beacon in the footer.
	 *
	 * @return void
	 */
	function lafka_analytics_emit_cf_beacon(): void {
		if ( is_admin() ) {
			return;
		}
		$token = lafka_analytics_cf_beacon_token();
		if ( '' === $token ) {
			return;
		}
		// NOTE: no Subresource Integrity on this tag — by design. Cloudflare
		// serves beacon.min.js as a versionless, auto-updated first-party
		// analytics endpoint, so a pinned integrity hash would break on their
		// next update. This is Cloudflare's canonical Web Analytics snippet.
		// Beacon config is a fixed JSON literal with a validated hex token;
		// safe to emit directly. (data-cf-beacon must be valid JSON.)
		$beacon = wp_json_encode( array( 'token' => $token ) );
		printf(
			'<script defer src="https://static.cloudflareinsights.com/beacon.min.js" data-cf-beacon=\'%s\'></script>' . "\n",
			esc_attr( $beacon )
		);
	}
}
