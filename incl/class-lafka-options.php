<?php
/**
 * Lafka_Options — Shared option access helper.
 *
 * Provides a single point of access for the 'lafka' option array consumed by
 * both the Lafka Plugin and Lafka Theme.  The class caches the DB read for the
 * duration of the request and supports an optional defaults layer that the
 * theme can register via Lafka_Options::set_defaults().
 *
 * @package Lafka
 * @since   8.6.0
 */

defined( 'ABSPATH' ) || exit;

class Lafka_Options {

	/**
	 * Cached options from the database (null = not yet loaded).
	 *
	 * @var array|null
	 */
	private static $options = null;

	/**
	 * Registered defaults (populated by the theme's options framework).
	 *
	 * @var array
	 */
	private static $defaults = array();

	/**
	 * Retrieve a single option value.
	 *
	 * Look-up order:
	 *   1. Saved value in the 'lafka' option array.
	 *   2. Explicit $default passed by the caller.
	 *   3. Registered defaults (set by the theme via set_defaults()).
	 *   4. false.
	 *
	 * @param string $name    Option key.
	 * @param mixed  $default Explicit default (takes precedence over registered defaults).
	 *
	 * @return mixed
	 */
	public static function get( $name, $default = null ) {
		if ( null === self::$options ) {
			self::$options = get_option( 'lafka', array() );
			if ( ! is_array( self::$options ) ) {
				self::$options = array();
			}
		}

		// 1. Saved value.
		if ( isset( self::$options[ $name ] ) ) {
			return self::$options[ $name ];
		}

		// 2. Caller-supplied default.
		if ( null !== $default ) {
			return $default;
		}

		// 3. Registered defaults from the theme's options framework.
		if ( isset( self::$defaults[ $name ] ) ) {
			return self::$defaults[ $name ];
		}

		return false;
	}

	/**
	 * Retrieve the entire options array (cached).
	 *
	 * @return array
	 */
	public static function get_all() {
		if ( null === self::$options ) {
			self::$options = get_option( 'lafka', array() );
			if ( ! is_array( self::$options ) ) {
				self::$options = array();
			}
		}

		return self::$options;
	}

	/**
	 * Check whether a feature flag option is explicitly set to 'enabled'.
	 *
	 * Useful for: product_addons, shipping_areas, product_combos, order_hours,
	 * kitchen_display, etc.
	 *
	 * @param string $name Option key (e.g. 'product_addons').
	 *
	 * @return bool
	 */
	public static function is_enabled( $name ) {
		return 'enabled' === self::get( $name );
	}

	/**
	 * Register (or merge) a set of default values.
	 *
	 * The theme calls this once after building its options array so that
	 * Lafka_Options::get() can fall back to framework defaults even when
	 * no value has been saved yet.
	 *
	 * @param array $defaults Associative array of option_key => default_value.
	 */
	public static function set_defaults( $defaults ) {
		self::$defaults = array_merge( self::$defaults, $defaults );
	}

	/**
	 * Bust the static cache.
	 *
	 * Useful after programmatic option updates in the same request (e.g. import).
	 */
	public static function flush() {
		self::$options = null;
	}
}
