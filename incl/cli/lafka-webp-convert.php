<?php
/**
 * WP-CLI: bulk-generate WebP siblings for every PNG/JPG in wp-content/uploads.
 *
 * Pairs with incl/perf/webp-swap.php — that filter rewrites <img src=foo.png>
 * to <img src=foo.webp> the moment the sibling exists. This command creates
 * the siblings.
 *
 * Strategy:
 *   - Walks wp-content/uploads recursively.
 *   - Skips files that already have a .webp sibling unless --force.
 *   - Uses Imagick if available (best quality + better animation support);
 *     falls back to GD with imagewebp() otherwise. Both ship with most
 *     modern WP hosts (PHP 8.1 + libwebp ≥0.5).
 *   - Quality 80 by default — visually indistinguishable from source for
 *     restaurant-grade photos, typically 60-80% smaller than the PNG/JPG.
 *
 * Usage:
 *   wp lafka images convert-webp                    # all uploads, quality 80
 *   wp lafka images convert-webp --quality=85       # higher quality
 *   wp lafka images convert-webp --dry-run          # preview, no writes
 *   wp lafka images convert-webp --force            # re-convert existing
 *   wp lafka images convert-webp --path=2026/01     # one subdir only
 *
 * Safe to re-run: idempotent unless --force.
 *
 * @package LafkaPlugin
 * @since   9.10.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

class Lafka_WebP_Convert_Command {

	/**
	 * Bulk-generate .webp siblings for PNG/JPG files in wp-content/uploads.
	 *
	 * ## OPTIONS
	 *
	 * [--quality=<n>]
	 * : WebP quality 0-100. Default: 80.
	 *
	 * [--dry-run]
	 * : Report what would be converted without writing any files.
	 *
	 * [--force]
	 * : Overwrite existing .webp siblings.
	 *
	 * [--path=<subdir>]
	 * : Restrict to a subdirectory of uploads (e.g. "2026/01"). Default: all.
	 *
	 * [--min-bytes=<n>]
	 * : Skip source files smaller than N bytes. Default: 5120 (5 KB). Tiny
	 *   images often compress poorly with WebP and aren't worth converting.
	 *
	 * ## EXAMPLES
	 *
	 *     wp lafka images convert-webp
	 *     wp lafka images convert-webp --quality=85 --force
	 *     wp lafka images convert-webp --path=2026/01 --dry-run
	 *
	 * @when after_wp_load
	 */
	public function convert_webp( $args, $assoc_args ) {
		$quality   = isset( $assoc_args['quality'] ) ? max( 0, min( 100, (int) $assoc_args['quality'] ) ) : 80;
		$dry_run   = ! empty( $assoc_args['dry-run'] );
		$force     = ! empty( $assoc_args['force'] );
		$min_bytes = isset( $assoc_args['min-bytes'] ) ? max( 0, (int) $assoc_args['min-bytes'] ) : 5120;
		$subpath   = isset( $assoc_args['path'] ) ? trim( (string) $assoc_args['path'], '/' ) : '';

		$upload_dir = wp_get_upload_dir();
		$root       = $upload_dir['basedir'] . ( '' !== $subpath ? '/' . $subpath : '' );
		if ( ! is_dir( $root ) ) {
			WP_CLI::error( "Directory not found: $root" );
		}

		// Detect available backend.
		$backend = $this->detect_backend();
		if ( 'none' === $backend ) {
			WP_CLI::error( 'No WebP-capable image backend available. Need Imagick with WebP OR GD with imagewebp().' );
		}
		WP_CLI::log( "Backend: $backend (quality=$quality)" );
		if ( $dry_run ) {
			WP_CLI::log( '(dry-run mode — no files will be written)' );
		}

		$converted        = 0;
		$skipped_existing = 0;
		$skipped_small    = 0;
		$errors           = 0;
		$bytes_saved      = 0;

		$iter = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator( $root, \FilesystemIterator::SKIP_DOTS ),
			\RecursiveIteratorIterator::LEAVES_ONLY
		);

		$progress = \WP_CLI\Utils\make_progress_bar( 'Converting', 0 );

		foreach ( $iter as $file ) {
			if ( ! $file->isFile() ) {
				continue;
			}
			$ext = strtolower( $file->getExtension() );
			if ( ! in_array( $ext, array( 'png', 'jpg', 'jpeg' ), true ) ) {
				continue;
			}
			$src      = $file->getPathname();
			$src_size = $file->getSize();
			if ( $src_size < $min_bytes ) {
				++$skipped_small;
				continue;
			}
			$webp = preg_replace( '/\.(png|jpe?g)$/i', '.webp', $src );
			if ( file_exists( $webp ) && ! $force ) {
				++$skipped_existing;
				continue;
			}

			if ( $dry_run ) {
				WP_CLI::line( sprintf( '  would convert: %s (%s)', basename( $src ), size_format( $src_size ) ) );
				++$converted;
				continue;
			}

			$ok = ( 'imagick' === $backend )
				? $this->convert_with_imagick( $src, $webp, $quality )
				: $this->convert_with_gd( $src, $webp, $quality );

			if ( $ok && file_exists( $webp ) ) {
				$webp_size    = filesize( $webp );
				$saved        = $src_size - $webp_size;
				$bytes_saved += max( 0, $saved );
				$pct          = $src_size > 0 ? round( 100 * $saved / $src_size ) : 0;
				WP_CLI::line( sprintf( '  ✓ %s → .webp (%s → %s, -%d%%)', basename( $src ), size_format( $src_size ), size_format( $webp_size ), $pct ) );
				++$converted;
			} else {
				WP_CLI::warning( "Failed: $src" );
				++$errors;
			}
		}

		$progress->finish();

		WP_CLI::success(
			sprintf(
				'Converted %d / Skipped %d (existing) / Skipped %d (too small) / Errors %d / Saved %s',
				$converted,
				$skipped_existing,
				$skipped_small,
				$errors,
				size_format( $bytes_saved )
			)
		);
	}

	/**
	 * Backend detection. Imagick preferred when available — better visual
	 * quality at the same file size + handles a wider range of input formats.
	 */
	private function detect_backend(): string {
		if ( class_exists( 'Imagick' ) ) {
			$formats = method_exists( 'Imagick', 'queryFormats' ) ? \Imagick::queryFormats( 'WEBP*' ) : array();
			if ( ! empty( $formats ) ) {
				return 'imagick';
			}
		}
		if ( function_exists( 'imagewebp' ) ) {
			return 'gd';
		}
		return 'none';
	}

	private function convert_with_imagick( string $src, string $dst, int $quality ): bool {
		try {
			$img = new \Imagick( $src );
			$img->setImageFormat( 'webp' );
			$img->setImageCompressionQuality( $quality );
			$img->setOption( 'webp:method', '6' );        // best compression effort
			$img->setOption( 'webp:lossless', 'false' );
			$img->stripImage();                            // drop EXIF + metadata
			$ok = $img->writeImage( $dst );
			$img->clear();
			$img->destroy();
			return (bool) $ok;
		} catch ( \Exception $e ) {
			return false;
		}
	}

	private function convert_with_gd( string $src, string $dst, int $quality ): bool {
		$mime = wp_check_filetype( $src )['type'] ?? mime_content_type( $src );
		$img  = false;
		if ( 'image/png' === $mime ) {
			$img = @imagecreatefrompng( $src );
			if ( $img ) {
				// Preserve transparency in WebP output.
				imagepalettetotruecolor( $img );
				imagealphablending( $img, true );
				imagesavealpha( $img, true );
			}
		} elseif ( in_array( $mime, array( 'image/jpeg', 'image/jpg' ), true ) ) {
			$img = @imagecreatefromjpeg( $src );
		}
		if ( ! $img ) {
			return false;
		}
		$ok = imagewebp( $img, $dst, $quality );
		imagedestroy( $img );
		return (bool) $ok;
	}
}

WP_CLI::add_command( 'lafka images', 'Lafka_WebP_Convert_Command' );
