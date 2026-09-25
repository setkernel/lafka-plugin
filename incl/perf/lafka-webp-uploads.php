<?php
/**
 * GX (T-04): save new JPEG / PNG uploads as WebP.
 *
 * Restaurant photos uploaded as PNG weigh 100–500 KB per thumbnail; the same
 * image as WebP is typically 60–80% smaller. WordPress core can write every
 * generated size in another format through the `image_editor_output_format`
 * filter — this module maps image/jpeg and image/png to image/webp.
 *
 *  - Default: ON, but only when the server's image editor (Imagick or GD)
 *    can write WebP — `wp_image_editor_supports()` is asked once per request.
 *    An unsupported server keeps today's behaviour even when forced on.
 *  - Toggle: Lafka → Modules → "WebP images for new uploads" (option
 *    `lafka_webp_uploads`: '' = automatic, 'yes', 'no').
 *  - Filter: `lafka_webp_uploads_enabled` (bool).
 *  - Only NEW uploads are affected. Existing images: run
 *    `wp lafka images convert-webp` (WebP siblings, picked up by
 *    incl/perf/webp-swap.php) and/or `wp media regenerate` — see
 *    docs/PERFORMANCE.md.
 *  - A mapping another plugin or the operator already set for a MIME type
 *    is kept.
 *
 * @package LafkaPlugin
 * @since   10.3.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'lafka_webp_uploads_server_supported' ) ) {
	/**
	 * Whether the active image editor can write WebP (memoised per request).
	 *
	 * @param bool $reset Forget the memoised answer (tests).
	 * @return bool
	 */
	function lafka_webp_uploads_server_supported( bool $reset = false ): bool {
		static $supported = null;
		if ( $reset ) {
			$supported = null;
			return false;
		}
		if ( null === $supported ) {
			$supported = function_exists( 'wp_image_editor_supports' )
				&& (bool) wp_image_editor_supports( array( 'mime_type' => 'image/webp' ) );
		}
		return $supported;
	}
}

if ( ! function_exists( 'lafka_webp_uploads_reset_cache' ) ) {
	/**
	 * Forget the memoised server-support answer.
	 *
	 * @return void
	 */
	function lafka_webp_uploads_reset_cache() {
		lafka_webp_uploads_server_supported( true );
	}
}

if ( ! function_exists( 'lafka_webp_uploads_enabled' ) ) {
	/**
	 * Whether new JPEG / PNG uploads are written as WebP.
	 *
	 * @return bool
	 */
	function lafka_webp_uploads_enabled(): bool {
		$choice  = strtolower( (string) get_option( 'lafka_webp_uploads', '' ) );
		$enabled = 'no' !== $choice && lafka_webp_uploads_server_supported();

		/**
		 * Filter whether new JPEG / PNG uploads are saved as WebP. Returning
		 * true on a server that cannot write WebP has no effect.
		 *
		 * @since 10.3.0
		 * @param bool $enabled Operator choice (default on) AND server support.
		 */
		return (bool) apply_filters( 'lafka_webp_uploads_enabled', $enabled ) && lafka_webp_uploads_server_supported();
	}
}

if ( ! function_exists( 'lafka_webp_uploads_output_format' ) ) {
	/**
	 * `image_editor_output_format`: map JPEG + PNG to WebP.
	 *
	 * @param array<string,string> $formats Source MIME => output MIME.
	 * @return array<string,string>
	 */
	function lafka_webp_uploads_output_format( $formats ) {
		$formats = is_array( $formats ) ? $formats : array();
		if ( ! lafka_webp_uploads_enabled() ) {
			return $formats;
		}
		foreach ( array( 'image/jpeg', 'image/png' ) as $mime ) {
			if ( empty( $formats[ $mime ] ) ) {
				$formats[ $mime ] = 'image/webp';
			}
		}
		return $formats;
	}
}

if ( ! function_exists( 'lafka_webp_uploads_register_module' ) ) {
	/**
	 * List the toggle on Lafka → Modules.
	 *
	 * @return void
	 */
	function lafka_webp_uploads_register_module() {
		if ( ! class_exists( 'Lafka_Module' ) || ! class_exists( 'Lafka_Module_Registry' ) ) {
			return;
		}
		Lafka_Module_Registry::register(
			new Lafka_Module(
				array(
					'id'              => 'webp_uploads',
					'label'           => esc_html__( 'WebP images for new uploads', 'lafka-plugin' ),
					'description'     => esc_html__( 'Save new JPEG and PNG uploads as WebP (usually 60–80% smaller, faster pages). Only when your server supports WebP. Existing images: run "wp lafka images convert-webp".', 'lafka-plugin' ),
					'category'        => 'operations',
					'storage'         => 'option',
					'default_enabled' => true,
					'get_enabled'     => static function () {
						return lafka_webp_uploads_enabled();
					},
					'set_enabled'     => static function ( bool $enabled ) {
						update_option( 'lafka_webp_uploads', $enabled ? 'yes' : 'no' );
					},
					'docs_slug'       => 'webp-uploads',
				)
			)
		);
	}
}

add_filter( 'image_editor_output_format', 'lafka_webp_uploads_output_format' );
add_action( 'lafka_register_modules', 'lafka_webp_uploads_register_module' );
