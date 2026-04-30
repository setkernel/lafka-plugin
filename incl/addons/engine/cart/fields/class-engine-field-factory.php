<?php
/**
 * Lafka_Engine_Field_Factory — type → class mapping for cart-side fields.
 *
 * Replaces Lafka_Addon_Field_Factory. Same surface; new namespace.
 *
 * @package Lafka_Addons_Engine
 * @since   8.15.0
 */

defined( 'ABSPATH' ) || exit;

class Lafka_Engine_Field_Factory {

	/**
	 * @var array<string, string> type → class name
	 */
	private static array $types = array(
		'checkbox'    => 'Lafka_Engine_Field_List',
		'radiobutton' => 'Lafka_Engine_Field_List',
		'textarea'    => 'Lafka_Engine_Field_Textarea',
	);

	/**
	 * @param mixed $value
	 */
	public static function create( array $addon, $value ): ?Lafka_Engine_Field {
		$type = $addon['type'] ?? '';
		if ( ! isset( self::$types[ $type ] ) ) {
			return null;
		}
		$class = self::$types[ $type ];
		return new $class( $addon, $value );
	}

	public static function is_supported_type( string $type ): bool {
		return isset( self::$types[ $type ] );
	}

	/**
	 * @return string[]
	 */
	public static function get_supported_types(): array {
		return array_keys( self::$types );
	}
}
