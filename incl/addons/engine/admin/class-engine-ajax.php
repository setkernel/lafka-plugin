<?php
/**
 * Lafka_Engine_Ajax — AJAX endpoints for the addon editor.
 *
 * Currently exposes:
 *   action: lafka_engine_sync_attribute
 *     POST: { taxonomy: 'pa_premium_toppings', existing: [{label, price, included}, ...] }
 *     auth: manage_woocommerce + nonce 'lafka_engine_sync'
 *     returns: JSON { success: true, options: [{label, price, included}, ...] }
 *              or { success: false, message: '...' }
 *
 * The endpoint runs the same Lafka_Attribute_Source::sync() the engine uses
 * server-side at save time. Returns canonical option dicts the editor JS
 * uses to rebuild the option-rows section.
 *
 * @package Lafka_Addons_Engine
 * @since   8.13.0
 */

defined( 'ABSPATH' ) || exit;

class Lafka_Engine_Ajax {

	const SYNC_ACTION = 'lafka_engine_sync_attribute';
	const SYNC_NONCE  = 'lafka_engine_sync';

	public function __construct() {
		add_action( 'wp_ajax_' . self::SYNC_ACTION, array( $this, 'sync_attribute' ) );
	}

	public function sync_attribute(): void {
		check_ajax_referer( self::SYNC_NONCE, 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Insufficient permissions.', 'lafka-plugin' ) ),
				403
			);
		}

		$taxonomy = isset( $_POST['taxonomy'] ) ? sanitize_key( wp_unslash( $_POST['taxonomy'] ) ) : '';
		if ( '' === $taxonomy ) {
			wp_send_json_error(
				array( 'message' => __( 'Missing attribute taxonomy.', 'lafka-plugin' ) ),
				400
			);
		}

		$existing_raw = isset( $_POST['existing'] ) && is_array( $_POST['existing'] )
			? wp_unslash( $_POST['existing'] )
			: array();

		$existing_options = array();
		foreach ( $existing_raw as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}
			$existing_options[] = Lafka_Addon_Option::from_array(
				array(
					'label'    => sanitize_text_field( $entry['label'] ?? '' ),
					'price'    => isset( $entry['price'] ) && '' !== $entry['price']
						? wc_format_decimal( sanitize_text_field( (string) $entry['price'] ) )
						: '',
					'included' => ! isset( $entry['included'] ) || ! empty( $entry['included'] ),
				)
			);
		}

		// Build a synthetic group with just the source taxonomy + existing options
		// so we can reuse the same Lafka_Attribute_Source::sync() the editor uses
		// at save time. Single source of truth for the merge logic.
		$group = Lafka_Addon_Group::from_array(
			array(
				'options_source'           => Lafka_Addon_Schema::SOURCE_ATTRIBUTE,
				'options_source_attribute' => $taxonomy,
				'options'                  => array_map(
					static fn( Lafka_Addon_Option $o ) => $o->to_array(),
					$existing_options
				),
			)
		);

		$sources = Lafka_Addons_Engine::instance()->sources();
		if ( ! isset( $sources[ Lafka_Addon_Schema::SOURCE_ATTRIBUTE ] ) ) {
			wp_send_json_error(
				array( 'message' => __( 'Attribute source unavailable.', 'lafka-plugin' ) ),
				500
			);
		}

		$synced = $sources[ Lafka_Addon_Schema::SOURCE_ATTRIBUTE ]->sync( $group );

		$payload = array();
		foreach ( $synced->options as $option ) {
			$payload[] = array(
				'id'       => $option->id,
				'label'    => $option->label,
				'price'    => is_scalar( $option->price ) ? (string) $option->price : '',
				'included' => $option->included,
			);
		}

		wp_send_json_success( array( 'options' => $payload ) );
	}
}
