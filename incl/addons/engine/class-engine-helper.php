<?php
/**
 * Lafka_Engine_Helper — public utility surface for the addon engine.
 *
 * Replaces the legacy WC_Product_Addons_Helper. Same static-method shape so
 * call sites in the cart, display, and any third-party theme overrides
 * port with a class rename. WC_Product_Addons_Helper is class_aliased to
 * this class at the bottom of the file for back-compat.
 *
 * The central read method, get_product_addons(), wraps Lafka_Engine_Resolver
 * (which returns Lafka_Addon_Group[] VOs) and converts back to the legacy
 * array shape templates and field classes consume. This lets v8.15.0 ship
 * the namespace move without a templates rewrite — that's a Phase 8 job.
 *
 * @package Lafka_Addons_Engine
 * @since   8.15.0
 */

defined( 'ABSPATH' ) || exit;

class Lafka_Engine_Helper {

	/**
	 * Per-request cache for the legacy-shape get_product_addons() output.
	 * Class-level so clear_cache() can wipe it on addon CPT save/trash/delete.
	 *
	 * @var array<string, array>
	 */
	private static array $product_addons_cache = array();

	/**
	 * Returns the merged addon list for a product as the legacy array shape.
	 *
	 * Each returned dict has at minimum: name, description, type, options[],
	 * required, position, variations, attribute, price, limit, plus a
	 * runtime-computed `field-name` derived from the prefix + name.
	 *
	 * @param int         $post_id
	 * @param string|bool $prefix     Field-name prefix; defaults to "{post_id}-".
	 * @param bool        $inc_parent
	 * @param bool        $inc_global
	 * @return array
	 */
	public static function get_product_addons( $post_id, $prefix = false, $inc_parent = true, $inc_global = true ): array {
		if ( ! $post_id ) {
			return array();
		}

		$extra_key = (string) apply_filters( 'lafka_product_addons_cache_key_extra', '', $post_id, $prefix );
		$cache_key = $post_id . '|' . ( $prefix ?: 'default' ) . '|' . (int) $inc_parent . '|' . (int) $inc_global . '|' . $extra_key;
		if ( isset( self::$product_addons_cache[ $cache_key ] ) ) {
			return self::$product_addons_cache[ $cache_key ];
		}

		$resolver = new Lafka_Engine_Resolver();
		$groups   = $resolver->resolve_for_product( (int) $post_id, $inc_parent, $inc_global );

		$addons = array();
		foreach ( $groups as $group ) {
			$addons[] = self::group_to_legacy_array( $group );
		}

		$addons = self::assign_field_names( $addons, (int) $post_id, $prefix );

		$result = (array) apply_filters( 'get_product_addons', $addons );

		self::$product_addons_cache[ $cache_key ] = $result;
		return $result;
	}

	/**
	 * Wipe the per-request legacy-shape cache. Called on addon CPT
	 * save/trash/delete so admin pages re-rendering on the same request
	 * see the current data, not the pre-save snapshot.
	 */
	public static function clear_cache(): void {
		self::$product_addons_cache = array();
	}

	/**
	 * Convert a Lafka_Addon_Group VO to the legacy associative array shape
	 * that templates and field classes expect.
	 */
	public static function group_to_legacy_array( Lafka_Addon_Group $group ): array {
		$options = array();
		foreach ( $group->options as $opt ) {
			$options[] = array(
				'id'       => $opt->id,
				'label'    => $opt->label,
				'image'    => $opt->image,
				'price'    => $opt->price,
				'default'  => $opt->default,
				'min'      => $opt->min,
				'max'      => $opt->max,
				'included' => $opt->included,
			);
		}
		return array(
			'name'        => $group->name,
			'description' => $group->description,
			'type'        => $group->type,
			'position'    => $group->position,
			'required'    => (string) $group->required,
			'limit'       => (string) $group->limit,
			'variations'  => $group->variations,
			'attribute'   => $group->attribute,
			'options'     => $options,
		);
	}

	/**
	 * Walk the merged addon list and stamp each with a stable field-name.
	 * Mirrors legacy logic so display + cart agree on input names.
	 */
	private static function assign_field_names( array $addons, int $post_id, $prefix ): array {
		if ( ! $prefix ) {
			$prefix = (string) apply_filters( 'product_addons_field_prefix', "{$post_id}-", $post_id );
		}

		$max_addon_name_length = 45 - strlen( $prefix );
		if ( $max_addon_name_length < 0 ) {
			$max_addon_name_length = 0;
		}

		$counter = 0;
		foreach ( $addons as $key => $addon ) {
			if ( empty( $addon['name'] ) ) {
				unset( $addons[ $key ] );
				continue;
			}
			if ( empty( $addon['field-name'] ) ) {
				$truncated_name        = substr( $addon['name'], 0, $max_addon_name_length );
				$addons[ $key ]['field-name'] = sanitize_title( $prefix . $truncated_name . '-' . $counter );
				++$counter;
			}
		}

		return array_values( $addons );
	}

	/**
	 * Tax-aware addon price formatter for cart/checkout/display contexts.
	 *
	 * @param float           $price
	 * @param WC_Product|null $cart_item
	 * @return string|null
	 */
	public static function get_product_addon_price_for_display( $price, $cart_item = null ) {
		$product = ! empty( $GLOBALS['product'] ) && is_object( $GLOBALS['product'] ) ? clone $GLOBALS['product'] : null;

		if ( '' === $price || 0 === (float) $price ) {
			return null;
		}

		$neg = false;
		if ( $price < 0 ) {
			$neg    = true;
			$price *= -1;
		}

		if ( ( is_cart() || is_checkout() || wp_doing_ajax() ) && null !== $cart_item ) {
			$product = wc_get_product( $cart_item->get_id() );
		}

		if ( is_object( $product ) ) {
			$display_price = self::get_product_addon_tax_display_mode() === 'incl'
				? wc_get_price_including_tax(
                    $product,
                    array(
						'qty' => 1,
						'price' => $price,
                    ) 
                )
				: wc_get_price_excluding_tax(
                    $product,
                    array(
						'qty' => 1,
						'price' => $price,
                    ) 
                );

			// Tax-exempt customer + prices-exclude-tax → cart/checkout shows excl.
			if ( ( is_cart() || is_checkout() ) && ! empty( WC()->customer ) && WC()->customer->get_is_vat_exempt() && ! wc_prices_include_tax() ) {
				$display_price = wc_get_price_excluding_tax(
                    $product,
                    array(
						'qty' => 1,
						'price' => $price,
                    ) 
                );
			}
		} else {
			$display_price = $price;
		}

		return $neg ? '-' . $display_price : $display_price;
	}

	public static function get_product_addon_tax_display_mode(): string {
		if ( is_cart() || is_checkout() ) {
			return (string) get_option( 'woocommerce_tax_display_cart' );
		}
		return (string) get_option( 'woocommerce_tax_display_shop' );
	}

	public static function is_addon_required( array $addon = array() ): bool {
		if ( empty( $addon ) ) {
			return false;
		}
		return '1' === ( $addon['required'] ?? '' );
	}

	public static function should_display_description( array $addon = array() ): bool {
		if ( empty( $addon ) || empty( $addon['description_enable'] ) ) {
			return false;
		}
		return ! empty( $addon['description'] );
	}

	public static function is_wc_gte( string $version ): bool {
		return defined( 'WC_VERSION' ) && version_compare( WC_VERSION, $version, '>=' );
	}

	public static function is_wc_gt( string $version ): bool {
		return defined( 'WC_VERSION' ) && version_compare( WC_VERSION, $version, '>' );
	}

	public static function can_upload( $file ): bool {
		return $file < wp_max_upload_size();
	}

	public static function is_filesize_over_limit( array $post_file ): bool {
		$php_size_upload_errors = array( 1, 2 );
		if ( ! empty( $post_file['error'] ) && in_array( $post_file['error'], $php_size_upload_errors, true ) ) {
			return true;
		}
		return ! self::can_upload( $post_file['size'] ?? 0 );
	}

	public static function no_image_select_placeholder_src(): string {
		$src = ( defined( 'WC_PRODUCT_ADDONS_PLUGIN_URL' ) ? WC_PRODUCT_ADDONS_PLUGIN_URL : '' ) . '/assets/images/no-image-select-placeholder.png';
		return (string) apply_filters( 'woocommerce_product_addons_no_image_select_placeholder_src', $src );
	}
}

// Back-compat alias. Third-party themes/plugins reaching for the old class
// name keep working. v8.15.0 is the last release where this alias is
// guaranteed; v8.16.x may drop it.
if ( ! class_exists( 'WC_Product_Addons_Helper', false ) ) {
	class_alias( 'Lafka_Engine_Helper', 'WC_Product_Addons_Helper' );
}
