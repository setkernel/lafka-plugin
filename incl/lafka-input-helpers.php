<?php
/**
 * Readers for public, read-only request parameters.
 *
 * These cover query-string values that only select what to show (filters,
 * tabs, sort order, pagination, "settings updated" flags). Anything that
 * changes state on the server must verify a nonce and a capability instead.
 * filter_input() reads the raw request, so values arrive unslashed.
 *
 * @package Lafka\Plugin
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether a query-string parameter was sent (the isset( $_GET[ $key ] ) test).
 *
 * @param string $key Parameter name.
 * @return bool
 */
function lafka_input_has_get( string $key ): bool {
	return filter_has_var( INPUT_GET, $key );
}

/**
 * Whether a query-string parameter is present and not empty (the
 * ! empty( $_GET[ $key ] ) test: absent, '' and '0' are all false).
 *
 * @param string $key Parameter name.
 * @return bool
 */
function lafka_input_get_flag( string $key ): bool {
	$value = lafka_input_get_text( $key );
	return '' !== $value && '0' !== $value;
}

/**
 * Read a query-string parameter as sanitised text.
 *
 * @param string $key     Parameter name.
 * @param string $default_value Value when the parameter is absent or not a scalar.
 * @return string
 */
function lafka_input_get_text( string $key, string $default_value = '' ): string {
	$value = filter_input( INPUT_GET, $key, FILTER_UNSAFE_RAW, FILTER_REQUIRE_SCALAR );
	return is_string( $value ) ? sanitize_text_field( $value ) : $default_value;
}

/**
 * Read a query-string parameter as an integer.
 *
 * @param string $key     Parameter name.
 * @param int    $default_value Value when the parameter is absent or not an integer.
 * @return int
 */
function lafka_input_get_int( string $key, int $default_value = 0 ): int {
	$value = filter_input( INPUT_GET, $key, FILTER_VALIDATE_INT );
	return is_int( $value ) ? $value : $default_value;
}

/**
 * Read a request parameter as sanitised text, query string first and then the
 * POST body, for choosing what to render (never for changing state).
 *
 * @param string $key           Parameter name.
 * @param string $default_value Value when the parameter is absent or not a scalar.
 * @return string
 */
function lafka_input_request_text( string $key, string $default_value = '' ): string {
	$value = filter_input( INPUT_GET, $key, FILTER_UNSAFE_RAW, FILTER_REQUIRE_SCALAR );
	if ( ! is_string( $value ) ) {
		$value = filter_input( INPUT_POST, $key, FILTER_UNSAFE_RAW, FILTER_REQUIRE_SCALAR );
	}
	return is_string( $value ) ? sanitize_text_field( $value ) : $default_value;
}

/**
 * Whether the current request carries a valid WooCommerce classic-checkout
 * nonce (the `woocommerce-process_checkout` action WooCommerce itself checks
 * before it fires the checkout hooks).
 *
 * Checkout hooks that read the submitted form call this first, so they act on
 * the posted fields only for a genuine checkout submission.
 *
 * @return bool
 */
function lafka_verify_checkout_nonce(): bool {
	$nonce = '';
	if ( isset( $_REQUEST['woocommerce-process-checkout-nonce'] ) ) {
		$nonce = sanitize_text_field( wp_unslash( $_REQUEST['woocommerce-process-checkout-nonce'] ) );
	} elseif ( isset( $_REQUEST['_wpnonce'] ) ) {
		$nonce = sanitize_text_field( wp_unslash( $_REQUEST['_wpnonce'] ) );
	}

	return '' !== $nonce && false !== wp_verify_nonce( $nonce, 'woocommerce-process_checkout' );
}

/**
 * Whether the current request carries a valid WooCommerce order-review
 * refresh nonce (the `update-order-review` action of the checkout page's
 * wc-ajax update_order_review request).
 *
 * @return bool
 */
function lafka_verify_order_review_nonce(): bool {
	$nonce = isset( $_REQUEST['security'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['security'] ) ) : '';

	return '' !== $nonce && false !== wp_verify_nonce( $nonce, 'update-order-review' );
}
