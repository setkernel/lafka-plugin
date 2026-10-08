<?php
/**
 * Lafka Addon Schema — version constants and canonical default shapes.
 *
 * Single source of truth for what an addon group / option SHOULD look like
 * after migration v8.13.0. The repository validates against these defaults
 * when reading old data; the admin form uses them when constructing new
 * groups.
 *
 * @package Lafka_Addons_Engine
 * @since   8.13.0
 */

defined( 'ABSPATH' ) || exit;

class Lafka_Addon_Schema {

	/**
	 * Schema version stamped onto every group after migration.
	 */
	const SCHEMA_VERSION = 2;

	/**
	 * Pricing mode constants. Each maps to a Lafka_Pricing_Strategy implementation.
	 */
	const PRICING_FLAT_GROUP      = 'flat_group';
	const PRICING_FLAT_PER_OPTION = 'flat_per_option';
	const PRICING_FLAT_PER_SIZE   = 'flat_per_size';
	const PRICING_MATRIX          = 'matrix';

	/**
	 * Options source constants.
	 */
	const SOURCE_MANUAL    = 'manual';
	const SOURCE_ATTRIBUTE = 'attribute';

	/**
	 * Canonical default shape of a fresh addon group at schema v2.
	 *
	 * @return array
	 */
	public static function default_group(): array {
		return array(
			'name'                     => '',
			'limit'                    => 0,
			'description'              => '',
			'type'                     => 'checkbox',
			'position'                 => 0,
			'required'                 => 0,
			// Checkbox groups only: each option can go on the left half, the
			// right half or the whole item (half-and-half pizza toppings).
			'half_placement'           => 0,
			'variations'               => 0,
			'attribute'                => 0,
			'options'                  => array(),

			// v2 fields. Default mode matches the most common operator pattern
			// (flat per option). Operator changes mode via the editor.
			'pricing_mode'             => self::PRICING_FLAT_PER_OPTION,
			'options_source'           => self::SOURCE_MANUAL,
			'options_source_attribute' => '',
			'included_size_slugs'      => array(),
			'group_flat_price'         => '',
			'group_size_prices'        => array(),
			'schema_version'           => self::SCHEMA_VERSION,
		);
	}

	/**
	 * Canonical default shape of a fresh option.
	 *
	 * @return array
	 */
	public static function default_option(): array {
		return array(
			'id'       => self::generate_id(),
			'label'    => '',
			'image'    => '',
			'price'    => '',
			'default'  => '',
			'min'      => '',
			'max'      => '',
			'included' => true,
		);
	}

	/**
	 * Known pricing modes.
	 *
	 * @return string[]
	 */
	public static function pricing_modes(): array {
		return array(
			self::PRICING_FLAT_GROUP,
			self::PRICING_FLAT_PER_OPTION,
			self::PRICING_FLAT_PER_SIZE,
			self::PRICING_MATRIX,
		);
	}

	/**
	 * Known options sources.
	 *
	 * @return string[]
	 */
	public static function options_sources(): array {
		return array( self::SOURCE_MANUAL, self::SOURCE_ATTRIBUTE );
	}

	/**
	 * Generate a UUID for a new option.
	 *
	 * @return string
	 */
	private static function generate_id(): string {
		return wp_generate_uuid4();
	}
}
