<?php
/**
 * Nonce check for Lafka fields saved from the core term screens.
 *
 * @package Lafka\Plugin\Admin
 * @since   10.1.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'lafka_verify_term_form_nonce' ) ) {
	/**
	 * Whether the current request is a genuine core "Add New" or "Edit" term
	 * form submission for $term_id. The two forms carry different nonces:
	 * edit posts `_wpnonce` for `update-tag_{id}`; add (edit-tags.php, via
	 * admin-ajax add-tag) posts `_wpnonce_add-tag` for `add-tag`.
	 *
	 * @param int $term_id Term being created or updated.
	 * @return bool
	 */
	function lafka_verify_term_form_nonce( $term_id ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- this function IS the nonce verification.
		$action = isset( $_POST['action'] ) ? sanitize_key( wp_unslash( $_POST['action'] ) ) : '';
		if ( 'editedtag' === $action ) {
			$field        = '_wpnonce';
			$nonce_action = 'update-tag_' . (int) $term_id;
		} else {
			$field        = '_wpnonce_add-tag';
			$nonce_action = 'add-tag';
		}
		$nonce = isset( $_POST[ $field ] ) ? sanitize_text_field( wp_unslash( $_POST[ $field ] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Missing

		return '' !== $nonce && false !== wp_verify_nonce( $nonce, $nonce_action );
	}
}
