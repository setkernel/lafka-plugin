<?php
/**
 * Auto-swap PNG/JPG image URLs to WebP siblings when present on disk.
 *
 * No-op until WebP siblings exist next to the original images. Once a sibling
 * file `foo.webp` exists alongside `foo.png` / `foo.jpg` in the uploads
 * directory, this module rewrites every emitted `<img src=…>`, `srcset`, and
 * `<link rel="preload">` to reference the WebP variant instead. Browser
 * support for WebP is universal in 2026 (Safari 14+, all evergreens).
 *
 * Generating the WebP siblings: run `wp lafka images convert-webp` (see
 * incl/cli/lafka-webp-convert.php) or use any image-optimization plugin
 * (ShortPixel, EWWW, Imagify, Smush) — this module is plugin-agnostic.
 *
 * Performance: file_exists() checks are cached per-request via a static
 * memoizer so the same URL costs at most one stat() per page render.
 *
 * Operator escape hatch: add_filter( 'lafka_disable_webp_swap', '__return_true' );
 *
 * @package LafkaPlugin
 * @since   9.10.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'lafka_webp_get_sibling_url' ) ) {
	/**
	 * Return the WebP-sibling URL for a PNG/JPG, or null when no sibling exists.
	 * Per-request memoized.
	 */
	function lafka_webp_get_sibling_url( string $url ): ?string {
		static $cache = array();
		if ( '' === $url ) {
			return null;
		}
		if ( array_key_exists( $url, $cache ) ) {
			return $cache[ $url ];
		}
		// Only swap raster URLs we recognize as PNG/JPG.
		if ( ! preg_match( '/\.(png|jpe?g)(\?.*)?$/i', $url ) ) {
			$cache[ $url ] = null;
			return null;
		}
		// Strip a trailing query/fragment for file-resolution; preserve it on the swap.
		$bare        = preg_replace( '/[?#].*$/', '', $url );
		$query       = substr( $url, strlen( $bare ) );
		$webp_url    = preg_replace( '/\.(png|jpe?g)$/i', '.webp', $bare ) . $query;
		$local_path  = lafka_webp_url_to_local_path( $bare );
		if ( ! $local_path ) {
			$cache[ $url ] = null;
			return null;
		}
		$webp_path = preg_replace( '/\.(png|jpe?g)$/i', '.webp', $local_path );
		if ( file_exists( $webp_path ) ) {
			$cache[ $url ] = $webp_url;
			return $webp_url;
		}
		$cache[ $url ] = null;
		return null;
	}
}

if ( ! function_exists( 'lafka_webp_url_to_local_path' ) ) {
	/**
	 * Convert an uploads-or-content URL to its local filesystem path.
	 * Returns null for off-site URLs (CDN-hosted images on a different origin).
	 */
	function lafka_webp_url_to_local_path( string $url ): ?string {
		$upload_dir = wp_get_upload_dir();
		if ( 0 === strpos( $url, $upload_dir['baseurl'] ) ) {
			return $upload_dir['basedir'] . substr( $url, strlen( $upload_dir['baseurl'] ) );
		}
		$content_url = content_url();
		if ( 0 === strpos( $url, $content_url ) ) {
			return WP_CONTENT_DIR . substr( $url, strlen( $content_url ) );
		}
		// Protocol-relative variants the theme sometimes emits.
		$proto_relative_baseurl = preg_replace( '/^https?:/', '', $upload_dir['baseurl'] );
		if ( 0 === strpos( $url, $proto_relative_baseurl ) ) {
			return $upload_dir['basedir'] . substr( $url, strlen( $proto_relative_baseurl ) );
		}
		return null;
	}
}

if ( ! function_exists( 'lafka_webp_enabled' ) ) {
	function lafka_webp_enabled(): bool {
		if ( apply_filters( 'lafka_disable_webp_swap', false ) ) {
			return false;
		}
		// Don't swap inside the admin or REST contexts; only frontend renders.
		if ( is_admin() ) {
			return false;
		}
		return true;
	}
}

/**
 * Swap the src URL for any wp_get_attachment_image_src() / similar call.
 * Covers WP-native attachment helpers; also feeds wp_get_attachment_image_attributes.
 */
if ( ! function_exists( 'lafka_webp_filter_attachment_src' ) ) {
	add_filter( 'wp_get_attachment_image_src', 'lafka_webp_filter_attachment_src', 10, 4 );
	function lafka_webp_filter_attachment_src( $image, $attachment_id = 0, $size = '', $icon = false ) {
		if ( ! lafka_webp_enabled() || ! is_array( $image ) || empty( $image[0] ) ) {
			return $image;
		}
		$webp = lafka_webp_get_sibling_url( (string) $image[0] );
		if ( $webp ) {
			$image[0] = $webp;
		}
		return $image;
	}
}

/**
 * Rewrite srcset URLs for responsive images.
 */
if ( ! function_exists( 'lafka_webp_filter_srcset' ) ) {
	add_filter( 'wp_calculate_image_srcset', 'lafka_webp_filter_srcset', 10, 5 );
	function lafka_webp_filter_srcset( $sources, $size_array, $image_src, $image_meta, $attachment_id ) {
		if ( ! lafka_webp_enabled() || ! is_array( $sources ) ) {
			return $sources;
		}
		foreach ( $sources as $width => $source ) {
			if ( empty( $source['url'] ) ) {
				continue;
			}
			$webp = lafka_webp_get_sibling_url( (string) $source['url'] );
			if ( $webp ) {
				$sources[ $width ]['url'] = $webp;
			}
		}
		return $sources;
	}
}

/**
 * Rewrite raw `<img src=...>` URLs in the_content / post_thumbnail_html / widget_text.
 * Catches images that come from WPBakery / hardcoded HTML rather than through
 * the WP attachment helpers above.
 */
if ( ! function_exists( 'lafka_webp_filter_content_imgs' ) ) {
	add_filter( 'the_content', 'lafka_webp_filter_content_imgs', 1000 );
	add_filter( 'post_thumbnail_html', 'lafka_webp_filter_content_imgs', 1000 );
	add_filter( 'widget_text', 'lafka_webp_filter_content_imgs', 1000 );
	function lafka_webp_filter_content_imgs( $content ) {
		if ( ! lafka_webp_enabled() || empty( $content ) || false === strpos( $content, '<img' ) ) {
			return $content;
		}
		// Swap each src= and data-src= URL in <img> tags.
		return preg_replace_callback(
			'/<img\b[^>]*>/i',
			function ( $m ) {
				$tag = $m[0];
				// src=
				$tag = preg_replace_callback(
					'/(\bsrc=["\'])([^"\']+)(["\'])/i',
					function ( $sm ) {
						$webp = lafka_webp_get_sibling_url( $sm[2] );
						return $webp ? ( $sm[1] . $webp . $sm[3] ) : $sm[0];
					},
					$tag
				);
				// srcset= (comma-separated URL w descriptor list)
				$tag = preg_replace_callback(
					'/(\bsrcset=["\'])([^"\']+)(["\'])/i',
					function ( $sm ) {
						$parts = preg_split( '/\s*,\s*/', $sm[2] );
						foreach ( $parts as $i => $part ) {
							$bits = preg_split( '/\s+/', trim( $part ), 2 );
							if ( ! empty( $bits[0] ) ) {
								$webp = lafka_webp_get_sibling_url( $bits[0] );
								if ( $webp ) {
									$bits[0]    = $webp;
									$parts[ $i ] = trim( implode( ' ', $bits ) );
								}
							}
						}
						return $sm[1] . implode( ', ', $parts ) . $sm[3];
					},
					$tag
				);
				return $tag;
			},
			$content
		);
	}
}

/**
 * Rewrite the LCP preload URL that lcp-preload.php emits, so the preload
 * targets the WebP variant when available.
 */
if ( ! function_exists( 'lafka_webp_filter_lcp_preload' ) ) {
	add_filter( 'lafka_lcp_image_url', 'lafka_webp_filter_lcp_preload', 100 );
	function lafka_webp_filter_lcp_preload( $url ) {
		if ( ! lafka_webp_enabled() || empty( $url ) ) {
			return $url;
		}
		$webp = lafka_webp_get_sibling_url( (string) $url );
		return $webp ? $webp : $url;
	}
}
