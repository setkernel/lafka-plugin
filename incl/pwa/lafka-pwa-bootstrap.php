<?php
/**
 * Installable app loader: the web app manifest, the offline page, the service
 * worker settings and the "Add to home screen" card (Lafka → Modules →
 * Installable app, default on; the card itself is a separate setting, default
 * off).
 *
 * @package Lafka\Plugin\Pwa
 * @since   10.4.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'lafka_pwa_json' ) ) {
	/**
	 * JSON for the manifest. Registered as an escaping function in
	 * .phpcs.xml.dist: HTML escaping would corrupt a JSON document.
	 *
	 * @since 10.4.0
	 * @param array<string,mixed> $data Document.
	 * @return string
	 */
	function lafka_pwa_json( array $data ): string {
		return (string) wp_json_encode( $data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
	}
}

if ( ! function_exists( 'lafka_pwa_boot' ) ) {
	/**
	 * Load and hook the module.
	 *
	 * @return void
	 */
	function lafka_pwa_boot(): void {
		require_once __DIR__ . '/class-lafka-pwa.php';
		if ( ! Lafka_Pwa::enabled() ) {
			return;
		}
		Lafka_Pwa::init();
	}
}
lafka_pwa_boot();
