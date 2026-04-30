<?php
/**
 * Addon_Group — immutable-ish value object for one addon group.
 *
 * Wraps the v2 `_product_addons[i]` shape. Round-trips lossless via
 * from_array() / to_array() against the canonical schema.
 *
 * @package Lafka_Addons_Engine
 * @since   8.13.0
 */

defined( 'ABSPATH' ) || exit;

final class Lafka_Addon_Group {

	public string $name;
	public int $limit;
	public string $description;
	public string $type;
	public int $position;
	public int $required;
	public int $variations;
	public int $attribute;
	/** @var Lafka_Addon_Option[] */
	public array $options;

	public string $pricing_mode;
	public string $options_source;
	public string $options_source_attribute;
	/** @var string[] */
	public array $included_size_slugs;
	public string $group_flat_price;
	/** @var array<string, string> */
	public array $group_size_prices;
	public int $schema_version;

	private function __construct() {}

	public static function from_array( array $data ): self {
		$defaults = Lafka_Addon_Schema::default_group();
		$merged   = array_merge( $defaults, array_intersect_key( $data, $defaults ) );

		$group                           = new self();
		$group->name                     = (string) $merged['name'];
		$group->limit                    = (int) $merged['limit'];
		$group->description              = (string) $merged['description'];
		$group->type                     = (string) $merged['type'];
		$group->position                 = (int) $merged['position'];
		$group->required                 = (int) $merged['required'];
		$group->variations               = (int) $merged['variations'];
		$group->attribute                = (int) $merged['attribute'];
		$group->pricing_mode             = (string) $merged['pricing_mode'];
		$group->options_source           = (string) $merged['options_source'];
		$group->options_source_attribute = (string) $merged['options_source_attribute'];
		$group->included_size_slugs      = array_values( array_map( 'strval', (array) $merged['included_size_slugs'] ) );
		$group->group_flat_price         = (string) $merged['group_flat_price'];
		$group->group_size_prices        = (array) $merged['group_size_prices'];
		$group->schema_version           = (int) $merged['schema_version'];

		$group->options = array();
		foreach ( (array) $merged['options'] as $option_data ) {
			if ( ! is_array( $option_data ) ) {
				continue;
			}
			$group->options[] = Lafka_Addon_Option::from_array( $option_data );
		}

		return $group;
	}

	public function to_array(): array {
		return array(
			'name'                     => $this->name,
			'limit'                    => $this->limit,
			'description'              => $this->description,
			'type'                     => $this->type,
			'position'                 => $this->position,
			'required'                 => $this->required,
			'variations'               => $this->variations,
			'attribute'                => $this->attribute,
			'options'                  => array_map(
				static fn( Lafka_Addon_Option $o ) => $o->to_array(),
				$this->options
			),
			'pricing_mode'             => $this->pricing_mode,
			'options_source'           => $this->options_source,
			'options_source_attribute' => $this->options_source_attribute,
			'included_size_slugs'      => $this->included_size_slugs,
			'group_flat_price'         => $this->group_flat_price,
			'group_size_prices'        => $this->group_size_prices,
			'schema_version'           => $this->schema_version,
		);
	}

	/**
	 * @param Lafka_Addon_Option[] $options
	 */
	public function with_options( array $options ): self {
		$clone          = clone $this;
		$clone->options = array_values( $options );
		return $clone;
	}

	public function with_pricing_mode( string $mode ): self {
		$clone               = clone $this;
		$clone->pricing_mode = $mode;
		return $clone;
	}

	public function uses_per_attribute_pricing(): bool {
		return 1 === $this->variations && $this->attribute > 0;
	}

	/**
	 * Whether this group applies to a given size term slug.
	 * Empty included_size_slugs = all sizes apply.
	 */
	public function includes_size( string $size_slug ): bool {
		if ( empty( $this->included_size_slugs ) ) {
			return true;
		}
		return in_array( $size_slug, $this->included_size_slugs, true );
	}
}
