<?php
/**
 * v8.12.9: source-grep test for the _all_products mutual-exclusion fix.
 *
 * Old behavior: when the operator picked "All Products" (id 0) AND specific
 * categories simultaneously, save_global_addons() stored both — categories
 * via wp_set_post_terms() and the _all_products meta flag set to 1. Display
 * logic checks _all_products FIRST and short-circuits, silently ignoring
 * the category restriction.
 *
 * New behavior: when 0 is present in $objects, drop other ids before
 * persisting. The pair is canonical: _all_products=1 AND no terms, OR
 * _all_products=0 AND terms.
 *
 * @package Lafka\Plugin\Tests\Unit
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class AddonAdminAllProductsExclusivityTest extends TestCase {

	private function admin_source(): string {
		$path = dirname( __DIR__, 2 ) . '/incl/addons/admin/class-lafka-product-addon-admin.php';
		$this->assertFileExists( $path );
		return file_get_contents( $path );
	}

	public function test_save_resolves_applies_to_all_before_persisting(): void {
		$src = $this->admin_source();

		$this->assertStringContainsString( '$applies_to_all = in_array( 0, $objects, true );', $src );
		$this->assertStringContainsString( "if ( \$applies_to_all ) {\n\t\t\t\$objects = array();", $src );
	}

	public function test_save_writes_canonical_all_products_meta(): void {
		$src = $this->admin_source();

		$this->assertStringContainsString(
			"update_post_meta( \$edit_id, '_all_products', \$applies_to_all ? 1 : 0 );",
			$src
		);
		// And the old branching block must be gone (no separate 0/1 paths).
		$this->assertStringNotContainsString( "if ( in_array( 0, \$objects ) ) {\n\t\t\tupdate_post_meta", $src );
	}

	public function test_save_global_addons_has_capability_check(): void {
		$src = $this->admin_source();

		// First-line cap check inside save_global_addons().
		$start    = strpos( $src, 'public function save_global_addons() {' );
		$this->assertNotFalse( $start );
		$snippet = substr( $src, $start, 400 );
		$this->assertStringContainsString( "current_user_can( 'manage_woocommerce' )", $snippet );
		$this->assertStringContainsString( 'return false;', $snippet );
	}

	public function test_post_handler_has_capability_check(): void {
		$src = $this->admin_source();

		// Cap check between check_admin_referer and save_global_addons() call.
		$start = strpos( $src, "check_admin_referer( 'lafka_save_global_addons' )" );
		$end   = strpos( $src, '$this->save_global_addons()', $start );
		$this->assertNotFalse( $start );
		$this->assertNotFalse( $end );
		$snippet = substr( $src, $start, $end - $start );
		$this->assertStringContainsString( "current_user_can( 'manage_woocommerce' )", $snippet );
		$this->assertStringContainsString( 'wp_die', $snippet );
	}
}
