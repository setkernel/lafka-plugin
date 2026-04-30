<?php
/**
 * Addon_Option — immutable-ish value object for one option within an addon group.
 *
 * Public properties are read directly. Mutation via with_* methods returns a
 * new instance, leaving the original unchanged. Round-trips lossless via
 * from_array() / to_array() against the canonical schema.
 *
 * @package Lafka_Addons_Engine
 * @since   8.13.0
 */

defined( 'ABSPATH' ) || exit;

final class Lafka_Addon_Option {

	public string $id;
	public string $label;
	public string $image;
	/** @var string|array Scalar for flat pricing, nested array for matrix. */
	public $price;
	public string $default;
	public string $min;
	public string $max;
	public bool $included;

	private function __construct() {}

	public static function from_array( array $data ): self {
		$defaults = Lafka_Addon_Schema::default_option();
		$merged   = array_merge( $defaults, array_intersect_key( $data, $defaults ) );

		$option           = new self();
		$option->id       = (string) $merged['id'];
		$option->label    = (string) $merged['label'];
		$option->image    = (string) $merged['image'];
		$option->price    = $merged['price']; // mixed
		$option->default  = (string) $merged['default'];
		$option->min      = (string) $merged['min'];
		$option->max      = (string) $merged['max'];
		$option->included = (bool) $merged['included'];

		return $option;
	}

	public function to_array(): array {
		return array(
			'id'       => $this->id,
			'label'    => $this->label,
			'image'    => $this->image,
			'price'    => $this->price,
			'default'  => $this->default,
			'min'      => $this->min,
			'max'      => $this->max,
			'included' => $this->included,
		);
	}

	/**
	 * @param string|array $price
	 */
	public function with_price( $price ): self {
		$clone        = clone $this;
		$clone->price = $price;
		return $clone;
	}

	public function with_included( bool $included ): self {
		$clone           = clone $this;
		$clone->included = $included;
		return $clone;
	}
}
