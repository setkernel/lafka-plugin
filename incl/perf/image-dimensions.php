<?php
/**
 * Image dimension auto-injection for CLS prevention.
 *
 * Migrated from lafka-child v5.10.6 in lafka-plugin v9.7.25.
 *
 * Parses the_content + post_thumbnail_html + widget_text output, finds
 * <img> tags missing width/height, looks up dimensions from the attachment
 * record first (no I/O), and only as a last resort reads the local file
 * dimensions from disk with a transient cache. Never fetches remote images.
 *
 * WP core's wp_filter_content_tags() does this for WP-managed images, but
 * skips images that aren't WP-managed (e.g. WPBakery output, hardcoded
 * URLs in custom templates). This module catches the stragglers.
 *
 * @package LafkaPlugin
 * @since   9.7.25
 */

defined( 'ABSPATH' ) || exit;

add_filter( 'the_content', 'lafka_inject_image_dimensions', 999 );
add_filter( 'post_thumbnail_html', 'lafka_inject_image_dimensions', 999 );
add_filter( 'widget_text', 'lafka_inject_image_dimensions', 999 );

if ( ! function_exists( 'lafka_inject_image_dimensions' ) ) {
	function lafka_inject_image_dimensions( $content ) {
		if ( empty( $content ) || strpos( $content, '<img' ) === false ) {
			return $content;
		}
		return preg_replace_callback(
			'/<img\b([^>]*)>/i',
			function ( $m ) {
				$attrs = $m[1];
				if ( preg_match( '/\bwidth=/i', $attrs ) && preg_match( '/\bheight=/i', $attrs ) ) {
					return $m[0];
				}
				if ( ! preg_match( '/\bsrc=["\']([^"\']+)["\']/', $attrs, $src ) ) {
					return $m[0];
				}
				$url = $src[1];

				$attachment_id = 0;
				if ( preg_match( '/\bclass=["\'][^"\']*\bwp-image-(\d+)\b/', $attrs, $cls ) ) {
					$attachment_id = (int) $cls[1];
				}
				if ( ! $attachment_id ) {
					$attachment_id = lafka_attachment_url_to_postid_cached( $url );
				}

				$w = 0;
				$h = 0;

				if ( $attachment_id ) {
					$meta = wp_get_attachment_metadata( $attachment_id );
					if ( ! empty( $meta['width'] ) && ! empty( $meta['height'] ) ) {
						$w = (int) $meta['width'];
						$h = (int) $meta['height'];
					}
				}

				if ( ! $w || ! $h ) {
					$cache_key = 'lafka_imgdims_' . md5( $url );
					$cached    = get_transient( $cache_key );
					if ( is_array( $cached ) && ! empty( $cached[0] ) && ! empty( $cached[1] ) ) {
						list( $w, $h ) = $cached;
					} else {
						$local_path = lafka_url_to_local_path( $url );
						if ( $local_path && file_exists( $local_path ) ) {
							$size = @getimagesize( $local_path );
							if ( is_array( $size ) && $size[0] && $size[1] ) {
								$w = (int) $size[0];
								$h = (int) $size[1];
								set_transient( $cache_key, array( $w, $h ), DAY_IN_SECONDS );
							}
						}
					}
				}

				if ( ! $w || ! $h ) {
					return $m[0];
				}

				$injected = sprintf( ' width="%d" height="%d"', $w, $h );
				return '<img' . $attrs . $injected . '>';
			},
			$content
		);
	}
}

if ( ! function_exists( 'lafka_url_to_local_path' ) ) {
	function lafka_url_to_local_path( $url ) {
		$upload_dir = wp_get_upload_dir();
		if ( strpos( $url, $upload_dir['baseurl'] ) === 0 ) {
			return $upload_dir['basedir'] . substr( $url, strlen( $upload_dir['baseurl'] ) );
		}
		$content_url = content_url();
		if ( strpos( $url, $content_url ) === 0 ) {
			return WP_CONTENT_DIR . substr( $url, strlen( $content_url ) );
		}
		return null;
	}
}

if ( ! function_exists( 'lafka_attachment_url_to_postid_cached' ) ) {
	/**
	 * Per-request memoized wrapper around attachment_url_to_postid().
	 *
	 * Each call to attachment_url_to_postid() is a `posts` table query joined
	 * against `postmeta`. Without memoization, a page with N <img> tags from
	 * non-WP-managed URLs (WPBakery output, hardcoded src) hits the DB N times
	 * per content-filter invocation, and the_content/post_thumbnail_html/widget_text
	 * each fire the filter independently.
	 *
	 * Static cache hoists the result to the request scope. WP core does NOT
	 * cache this lookup (unlike wp_get_attachment_metadata which uses the
	 * post-meta cache).
	 *
	 * @param string $url Absolute URL of the image.
	 * @return int Attachment ID, or 0 if not a managed attachment.
	 */
	function lafka_attachment_url_to_postid_cached( string $url ): int {
		static $cache = array();
		if ( array_key_exists( $url, $cache ) ) {
			return $cache[ $url ];
		}
		$cache[ $url ] = function_exists( 'attachment_url_to_postid' )
			? (int) attachment_url_to_postid( $url )
			: 0;
		return $cache[ $url ];
	}
}
