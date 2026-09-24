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
