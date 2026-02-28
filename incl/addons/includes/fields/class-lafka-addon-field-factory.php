<?php
/**
 * Lafka_Addon_Field_Factory
 *
 * Maps addon type strings to their concrete field class, eliminating
 * the duplicated switch($addon['type']) blocks scattered throughout
 * the cart and validation code.
 *
 * @package Lafka\Addons
 * @since   8.6.0
 */

defined( 'ABSPATH' ) || exit;

class Lafka_Addon_Field_Factory {

	/**
	 * Type → class mapping.
	 *
	 * Each entry is: type_string => [ 'file' => relative_include_path, 'class' => class_name ]
	 *
	 * @var array
	 */
	private static $types = array(
		'checkbox'    => array(
			'file'  => 'class-product-addon-field-list.php',
			'class' => 'Lafka_Product_Addon_Field_List',
		),
		'radiobutton' => array(
			'file'  => 'class-product-addon-field-list.php',
			'class' => 'Lafka_Product_Addon_Field_List',
		),
		'textarea'    => array(
			'file'  => 'class-lafka-addon-field-textarea.php',
			'class' => 'Lafka_Addon_Field_Textarea',
		),
	);

	/**
	 * Create the appropriate field instance for an addon.
	 *
	 * @param array $addon The addon data array (must include 'type' key).
	 * @param mixed $value The submitted/posted value.
	 *
	 * @return Lafka_Product_Addon_Field|null Null when the type is unknown.
	 */
	public static function create( $addon, $value ) {
		$type = isset( $addon['type'] ) ? $addon['type'] : '';

		if ( ! isset( self::$types[ $type ] ) ) {
			return null;
		}

		$map = self::$types[ $type ];

		// Lazy-include the class file once.
		include_once dirname( __FILE__ ) . '/' . $map['file'];

		return new $map['class']( $addon, $value );
	}

	/**
	 * Check if a given type string is supported.
	 *
	 * @param string $type
	 *
	 * @return bool
	 */
	public static function is_supported_type( $type ) {
		return isset( self::$types[ $type ] );
	}

	/**
	 * Return all registered type strings.
	 *
	 * @return string[]
	 */
	public static function get_supported_types() {
		return array_keys( self::$types );
	}
}
