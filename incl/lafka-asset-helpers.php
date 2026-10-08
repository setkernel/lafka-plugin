<?php
/**
 * Script path helper: readable source under SCRIPT_DEBUG, minified build
 * otherwise (the build is regenerated from the source by `npm run build`).
 *
 * @package Lafka\Plugin
 * @since   10.1.0
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'lafka_plugin_script_path' ) ) {
	/**
	 * Plugin-relative path of the script to enqueue for a `*.min.js` asset:
	 * its `*.js` source when SCRIPT_DEBUG is on (and the source exists), the
	 * `.min.js` build otherwise.
	 *
	 * @param string $min_path Plugin-relative path to the `.min.js` build.
	 * @return string
	 */
	function lafka_plugin_script_path( $min_path ) {
		if ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) {
			$source = preg_replace( '/\.min\.js$/', '.js', (string) $min_path );
			if ( file_exists( dirname( LAFKA_PLUGIN_FILE ) . '/' . $source ) ) {
				return $source;
			}
		}

		return (string) $min_path;
	}
}

if ( ! function_exists( 'lafka_local_filesystem' ) ) {
	/**
	 * The WordPress filesystem abstraction's direct driver, for plugin-owned
	 * assets, uploaded temp files and CLI exports on the local disk.
	 *
	 * @return WP_Filesystem_Direct
	 */
	function lafka_local_filesystem() {
		if ( ! class_exists( 'WP_Filesystem_Direct' ) ) {
			require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-base.php';
			require_once ABSPATH . 'wp-admin/includes/class-wp-filesystem-direct.php';
		}
		return new WP_Filesystem_Direct( null );
	}
}

if ( ! function_exists( 'lafka_read_local_file' ) ) {
	/**
	 * Read a local file (plugin-owned asset or an uploaded temp file).
	 *
	 * @param string $path Absolute filesystem path.
	 * @return string|false The file contents, or false when unreadable.
	 */
	function lafka_read_local_file( string $path ) {
		if ( '' === $path || ! is_readable( $path ) ) {
			return false;
		}
		return lafka_local_filesystem()->get_contents( $path );
	}
}

if ( ! function_exists( 'lafka_write_local_file' ) ) {
	/**
	 * Write a local file (e.g. a CLI export).
	 *
	 * @param string $path     Absolute or CWD-relative filesystem path.
	 * @param string $contents File contents.
	 * @return bool True on success.
	 */
	function lafka_write_local_file( string $path, string $contents ): bool {
		if ( '' === $path ) {
			return false;
		}
		return lafka_local_filesystem()->put_contents( $path, $contents );
	}
}
