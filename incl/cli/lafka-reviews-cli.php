<?php
/**
 * P6-UX-8 W3-T6: WP-CLI helpers for product review configuration.
 *
 *   wp lafka reviews status     # show current review settings
 *   wp lafka reviews enable     # turn on reviews + ratings (verified-owners default)
 *   wp lafka reviews disable    # turn off reviews
 *
 * Settings flipped:
 *   woocommerce_enable_reviews                          → yes
 *   woocommerce_enable_review_rating                    → yes
 *   woocommerce_review_rating_verification_required     → yes  (only verified buyers can review)
 *   woocommerce_review_rating_verification_label        → yes  (show "verified owner" badge)
 */
defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

class Lafka_Reviews_CLI_Command {

	/**
	 * Show the current WooCommerce review settings.
	 *
	 * ## EXAMPLES
	 *
	 *   wp lafka reviews status
	 *
	 * @when after_wp_load
	 */
	public function status() {
		$settings = array(
			'woocommerce_enable_reviews',
			'woocommerce_enable_review_rating',
			'woocommerce_review_rating_verification_required',
			'woocommerce_review_rating_verification_label',
		);
		$items = array();
		foreach ( $settings as $key ) {
			$items[] = array(
				'setting' => $key,
				'value' => get_option( $key, '(unset)' ),
			);
		}
		WP_CLI\Utils\format_items( 'table', $items, array( 'setting', 'value' ) );
	}

	/**
	 * Enable WooCommerce product reviews with verified-owner gate.
	 *
	 * Turns on reviews, star ratings, and restricts reviewing to verified
	 * purchasers only (with the "verified owner" badge visible). Safe to
	 * re-run — idempotent.
	 *
	 * ## EXAMPLES
	 *
	 *   wp lafka reviews enable
	 *
	 * @when after_wp_load
	 */
	public function enable() {
		update_option( 'woocommerce_enable_reviews', 'yes' );
		update_option( 'woocommerce_enable_review_rating', 'yes' );
		update_option( 'woocommerce_review_rating_verification_required', 'yes' );
		update_option( 'woocommerce_review_rating_verification_label', 'yes' );
		WP_CLI::success( 'Product reviews enabled with verified-owner gate.' );
	}

	/**
	 * Disable WooCommerce product reviews site-wide.
	 *
	 * ## EXAMPLES
	 *
	 *   wp lafka reviews disable
	 *
	 * @when after_wp_load
	 */
	public function disable() {
		update_option( 'woocommerce_enable_reviews', 'no' );
		WP_CLI::success( 'Product reviews disabled.' );
	}
}

WP_CLI::add_command( 'lafka reviews', 'Lafka_Reviews_CLI_Command' );
