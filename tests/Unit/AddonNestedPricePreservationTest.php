<?php
/**
 * v8.12.4: addon admin save must not flatten nested per-attribute prices.
 *
 * Background: the addon admin form's price-input shape depends on whether
 * the per-term column rendering succeeds. If it fails (stale attribute ID,
 * deleted taxonomy, type-drift in serialized meta), the form falls back to
 * a single flat input. The save handler then reads $_POST and builds a
 * SCALAR price, irreversibly overwriting any nested array previously stored.
 *
 * Two fixes lock this in:
 *   1. resolve_addon_attribute_values() centralizes column resolution and
 *      iterates ALL options for the data-detected fallback (not just option 0).
 *   2. preserve_nested_prices_on_save() merges new addons against existing
 *      meta and restores nested price arrays when the form posted scalar.
 *
 * This source-grep test locks both fixes in place. Behavioral verification
 * happens in a manual UI smoke (see commit message).
 *
 * @package Lafka\Plugin\Tests\Unit
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class AddonNestedPricePreservationTest extends TestCase {

	private function admin_source(): string {
		$path = dirname( __DIR__, 2 ) . '/incl/addons/admin/class-lafka-product-addon-admin.php';
		$this->assertFileExists( $path );

		return file_get_contents( $path );
	}

	private function header_view_source(): string {
		$path = dirname( __DIR__, 2 ) . '/incl/addons/admin/views/html-addon.php';
		$this->assertFileExists( $path );

		return file_get_contents( $path );
	}

	private function row_view_source(): string {
		$path = dirname( __DIR__, 2 ) . '/incl/addons/admin/views/html-addon-option.php';
		$this->assertFileExists( $path );

		return file_get_contents( $path );
	}

	public function test_resolve_helper_exists(): void {
		$src = $this->admin_source();

		$this->assertStringContainsString(
			'public static function resolve_addon_attribute_values(',
			$src,
			'Centralized resolver missing — header and row would drift out of sync.'
		);
	}

	public function test_resolve_iterates_all_options_not_just_first(): void {
		$src = $this->admin_source();

		// Must use foreach over options, not reset() (which only checks option 0).
		$resolver = $this->extract_method_body( $src, 'resolve_addon_attribute_values' );
		$this->assertStringContainsString( "foreach ( \$addon['options'] as \$option )", $resolver );
		$this->assertStringNotContainsString( "reset( \$addon['options'] )", $resolver );
	}

	public function test_preserve_helper_exists(): void {
		$src = $this->admin_source();

		$this->assertStringContainsString(
			'public static function preserve_nested_prices_on_save(',
			$src,
			'Defensive merge helper missing — flat-form saves can destroy nested data.'
		);
	}

	public function test_preserve_only_acts_when_variations_enabled(): void {
		$src      = $this->admin_source();
		$preserve = $this->extract_method_body( $src, 'preserve_nested_prices_on_save' );

		// Operators who turn variations off should NOT have their flat saves blocked.
		$this->assertStringContainsString( "1 !== (int) ( \$new_addon['variations'] ?? 0 )", $preserve );
	}

	public function test_preserve_matches_options_by_stable_uuid(): void {
		$src      = $this->admin_source();
		$preserve = $this->extract_method_body( $src, 'preserve_nested_prices_on_save' );

		// Loop position is unstable across reorders; UUID is stable.
		$this->assertStringContainsString( "\$existing_options_by_id[ \$existing_option['id'] ]", $preserve );
	}

	public function test_preserve_only_restores_when_new_is_scalar_existing_is_array(): void {
		$src      = $this->admin_source();
		$preserve = $this->extract_method_body( $src, 'preserve_nested_prices_on_save' );

		// Skip if new is already nested (form rendered correctly — no preservation needed).
		$this->assertStringContainsString( "is_array( \$new_option['price'] ?? null )", $preserve );
		// Skip if existing was scalar/empty (nothing to preserve).
		$this->assertStringContainsString( "is_array( \$existing_option['price'] ?? null )", $preserve );
	}

	public function test_per_product_save_calls_preserve_before_writing_meta(): void {
		$src = $this->admin_source();

		$method = $this->extract_method_body( $src, 'process_meta_box' );
		$this->assertStringContainsString( "preserve_nested_prices_on_save( \$product_addons, \$existing_addons )", $method );

		// Order matters: preserve must run BEFORE update_meta_data.
		$preserve_pos = strpos( $method, 'preserve_nested_prices_on_save' );
		$update_pos   = strpos( $method, "update_meta_data( '_product_addons'" );
		$this->assertNotFalse( $preserve_pos );
		$this->assertNotFalse( $update_pos );
		$this->assertLessThan( $update_pos, $preserve_pos );
	}

	public function test_global_addon_save_calls_preserve_before_writing_meta(): void {
		$src = $this->admin_source();

		$method = $this->extract_method_body( $src, 'save_global_addons' );
		$this->assertStringContainsString( "preserve_nested_prices_on_save( \$product_addons, \$existing_addons )", $method );

		$preserve_pos = strpos( $method, 'preserve_nested_prices_on_save' );
		$update_pos   = strpos( $method, "update_post_meta( \$edit_id, '_product_addons'" );
		$this->assertNotFalse( $preserve_pos );
		$this->assertNotFalse( $update_pos );
		$this->assertLessThan( $update_pos, $preserve_pos );
	}

	public function test_header_view_uses_resolver(): void {
		$src = $this->header_view_source();

		$this->assertStringContainsString(
			'Lafka_Product_Addon_Admin::resolve_addon_attribute_values( $addon )',
			$src
		);
	}

	public function test_row_view_uses_resolver(): void {
		$src = $this->row_view_source();

		$this->assertStringContainsString(
			'Lafka_Product_Addon_Admin::resolve_addon_attribute_values( $addon )',
			$src
		);
	}

	/**
	 * Extract the body text of a named method/function from PHP source by
	 * locating its declaration and returning everything up to the matching
	 * closing brace. Matches braces only outside strings/comments would be
	 * overkill — the addon admin source is straightforward enough for naive
	 * brace counting.
	 */
	private function extract_method_body( string $src, string $method_name ): string {
		$needle = 'function ' . $method_name . '(';
		$start  = strpos( $src, $needle );
		$this->assertNotFalse( $start, "Method {$method_name} not found in source." );

		// Find first { after the signature.
		$body_start = strpos( $src, '{', $start );
		$this->assertNotFalse( $body_start );

		$depth = 0;
		$len   = strlen( $src );
		for ( $i = $body_start; $i < $len; $i++ ) {
			if ( '{' === $src[ $i ] ) {
				++$depth;
			} elseif ( '}' === $src[ $i ] ) {
				--$depth;
				if ( 0 === $depth ) {
					return substr( $src, $body_start, $i - $body_start + 1 );
				}
			}
		}

		$this->fail( "Unbalanced braces extracting {$method_name} body." );
	}
}
