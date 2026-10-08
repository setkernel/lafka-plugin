<?php
/**
 * Script and style handle registration (front end + admin).
 *
 * Three layers, in order:
 *
 *  1. lafka_register_plugin_scripts() — wp_enqueue_scripts @10. Plugin-OWNED
 *     assets (flatpickr + its locale file). Their URLs point into this plugin,
 *     so they are registered under any theme (the standalone contract: the
 *     checkout date picker keeps working with a non-Lafka theme). The map
 *     handles live in incl/geo/lafka-maps-assets.php.
 *  2. lafka_register_theme_script_fallbacks() — wp_enqueue_scripts @20, i.e.
 *     AFTER the Lafka theme registered its own handles at @10. Only fills in
 *     handles nobody registered: with the Lafka theme active, theme-directory
 *     handles the theme doesn't register on a given request.
 *     The theme's own registration (e.g. its `defer` strategy) always wins.
 *  3. lafka_register_admin_plugin_scripts() — admin_enqueue_scripts.
 *
 * @package Lafka\Plugin
 * @since   10.1.0 (extracted from lafka-plugin.php)
 */

defined( 'ABSPATH' ) || exit;

add_action( 'wp_enqueue_scripts', 'lafka_register_plugin_scripts' );
add_action( 'wp_enqueue_scripts', 'lafka_register_theme_script_fallbacks', 20 );
add_action( 'admin_enqueue_scripts', 'lafka_register_admin_plugin_scripts' );

if ( ! function_exists( 'lafka_register_flatpickr' ) ) {
	/**
	 * Register the bundled flatpickr script + stylesheet.
	 *
	 * @return void
	 */
	function lafka_register_flatpickr() {
		wp_register_script( 'flatpickr', plugins_url( 'assets/js/flatpickr/flatpickr.min.js', LAFKA_PLUGIN_FILE ), array( 'jquery' ), lafka_plugin_asset_version( 'assets/js/flatpickr/flatpickr.min.js' ), true );
		wp_register_style( 'flatpickr', plugins_url( 'assets/js/flatpickr/flatpickr.min.css', LAFKA_PLUGIN_FILE ), array(), lafka_plugin_asset_version( 'assets/js/flatpickr/flatpickr.min.css' ) );
	}
}

if ( ! function_exists( 'lafka_register_plugin_scripts' ) ) {
	/**
	 * Register the plugin-owned front-end assets (see file header, layer 1).
	 *
	 * @return void
	 */
	function lafka_register_plugin_scripts() {
		lafka_register_flatpickr();

		// P6-PERF-6: register ONLY the current site locale's flatpickr l10n file.
		// Candidate filenames in priority order:
		//   1. Full locale lowercased with hyphen   (e.g. en-ca.js)
		//   2. Full locale lowercased with underscore (e.g. en_ca.js)
		//   3. Short-code only                       (e.g. en.js, fr.js)
		// English is flatpickr's built-in default — no l10n file needed.
		$fp_locale        = get_locale();
		$fp_short         = strtolower( substr( $fp_locale, 0, 2 ) );
		$fp_candidates    = array(
			str_replace( '_', '-', strtolower( $fp_locale ) ) . '.js',
			strtolower( $fp_locale ) . '.js',
			$fp_short . '.js',
		);
		$fp_l10n_dir      = plugin_dir_path( LAFKA_PLUGIN_FILE ) . 'assets/js/flatpickr/l10n/';
		$fp_l10n_url_base = plugins_url( 'assets/js/flatpickr/l10n/', LAFKA_PLUGIN_FILE );
		$fp_picked        = null;
		foreach ( $fp_candidates as $fp_candidate ) {
			if ( file_exists( $fp_l10n_dir . $fp_candidate ) ) {
				$fp_picked = $fp_candidate;
				break;
			}
		}
		if ( null === $fp_picked ) {
			// The theme's custom l10n override directory, same priority list.
			$fp_theme_dir      = get_stylesheet_directory() . '/lafka_plugin_templates/flatpickr_l10n/';
			$fp_theme_url_base = get_stylesheet_directory_uri() . '/lafka_plugin_templates/flatpickr_l10n/';
			foreach ( $fp_candidates as $fp_candidate ) {
				if ( file_exists( $fp_theme_dir . $fp_candidate ) ) {
					wp_register_script( 'flatpickr-l10n', $fp_theme_url_base . $fp_candidate, array( 'flatpickr' ), lafka_plugin_asset_version( 'assets/js/flatpickr/flatpickr.min.js' ), true );
					break;
				}
			}
		} else {
			wp_register_script( 'flatpickr-l10n', $fp_l10n_url_base . $fp_picked, array( 'flatpickr' ), lafka_plugin_asset_version( 'assets/js/flatpickr/l10n/' . $fp_picked ), true );
		}
		// Back-compat alias: 'flatpickr-local' is still referenced in shipping-areas.
		if ( wp_script_is( 'flatpickr-l10n', 'registered' ) && ! wp_script_is( 'flatpickr-local', 'registered' ) ) {
			$fp_l10n_obj = wp_scripts()->query( 'flatpickr-l10n', 'registered' );
			wp_register_script( 'flatpickr-local', $fp_l10n_obj->src, $fp_l10n_obj->deps, $fp_l10n_obj->ver, true );
		}
	}
}

if ( ! function_exists( 'lafka_register_theme_script_fallbacks' ) ) {
	/**
	 * Register fallback handles nobody else registered (see file header,
	 * layer 2). Every registration is skipped when the handle already exists.
	 *
	 * @return void
	 */
	function lafka_register_theme_script_fallbacks() {
		$register_script = static function ( $handle, $src, $deps, $ver ) {
			if ( ! wp_script_is( $handle, 'registered' ) ) {
				wp_register_script( $handle, $src, $deps, $ver, true );
			}
		};
		$register_style  = static function ( $handle, $src, $deps, $ver ) {
			if ( ! wp_style_is( $handle, 'registered' ) ) {
				wp_register_style( $handle, $src, $deps, $ver );
			}
		};

		// Theme-directory handles: only meaningful (and only non-404) when the
		// Lafka theme — parent or child — is active.
		if ( ! function_exists( 'wp_get_theme' ) || 'lafka' !== (string) wp_get_theme()->get_template() ) {
			return;
		}

		$theme_uri = get_template_directory_uri();
		$theme_ver = static function ( $relative ) {
			return function_exists( 'lafka_asset_version' ) ? lafka_asset_version( $relative ) : (string) wp_get_theme()->get( 'Version' );
		};

		// lafka-dialog (native <dialog> wrapper). The theme's .min is build
		// output (present in release zips, maybe not in a git checkout): use it
		// when it exists and SCRIPT_DEBUG is off, the source otherwise.
		$dialog_suffix = ( ! ( defined( 'SCRIPT_DEBUG' ) && SCRIPT_DEBUG ) && file_exists( get_template_directory() . '/js/lafka-dialog.min.js' ) ) ? '.min' : '';
		$register_script( 'lafka-dialog', $theme_uri . '/js/lafka-dialog' . $dialog_suffix . '.js', array(), $theme_ver( '/js/lafka-dialog' . $dialog_suffix . '.js' ) );
		$register_style( 'lafka-dialog', $theme_uri . '/styles/lafka-dialog.css', array(), $theme_ver( '/styles/lafka-dialog.css' ) );
	}
}

if ( ! function_exists( 'lafka_register_admin_plugin_scripts' ) ) {
	/**
	 * Register (and on its own screen, enqueue) the admin assets.
	 *
	 * @return void
	 */
	function lafka_register_admin_plugin_scripts() {
		lafka_register_flatpickr();

		wp_register_script(
			'lafka-schedule',
			plugins_url( 'assets/js/schedule/jquery.schedule.min.js', LAFKA_PLUGIN_FILE ),
			array(
				'jquery-ui-core',
				'jquery-ui-draggable',
				'jquery-ui-resizable',
			),
			lafka_plugin_asset_version( 'assets/js/schedule/jquery.schedule.min.js' ),
			true
		);
		wp_register_style( 'lafka-schedule', plugins_url( 'assets/css/schedule/jquery.schedule.min.css', LAFKA_PLUGIN_FILE ), array(), lafka_plugin_asset_version( 'assets/css/schedule/jquery.schedule.min.css' ) );
	}
}
