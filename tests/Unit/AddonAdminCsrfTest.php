<?php
/**
 * C-7: IDOR / arbitrary post deletion in addons admin.
 *
 * The original delete handler in `class-lafka-product-addon-admin.php` had
 * four problems:
 *   1. global nonce action (any addon delete nonce works for any other ID)
 *   2. no post-type validation (any post ID could be deleted)
 *   3. no per-post capability check (only relied on the menu cap)
 *   4. force-deleted (`wp_delete_post( $id, true )`) so deletion was
 *      irrecoverable
 *
 * This source-grep test locks each fix in place. It also asserts the
 * matching `wp_nonce_url()` call in the views file emits a per-ID action.
 *
 * @package Lafka\Plugin\Tests\Unit
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class AddonAdminCsrfTest extends TestCase {

	private function admin_source(): string {
		$path = dirname( __DIR__, 2 ) . '/incl/addons/admin/class-lafka-product-addon-admin.php';
		$this->assertFileExists( $path );

		return file_get_contents( $path );
	}

	private function view_source(): string {
		$path = dirname( __DIR__, 2 ) . '/incl/addons/admin/views/html-global-admin.php';
		$this->assertFileExists( $path );

		return file_get_contents( $path );
	}

	public function test_per_id_nonce_action_used_in_handler(): void {
		$src = $this->admin_source();

		// Old: 'delete_addon' (no $id suffix). New: 'delete_addon_' . $id.
		$this->assertStringContainsString( "'delete_addon_' . \$id", $src );
	}

	public function test_per_id_nonce_action_used_in_view(): void {
		$src = $this->view_source();

		$this->assertMatchesRegularExpression(
			"/'delete_addon_'\s*\.\s*\\\$global_addon\['id'\]/",
			$src,
			'View must use per-ID nonce action when generating delete URL'
		);
	}

	public function test_post_type_validation_is_present(): void {
		$src = $this->admin_source();

		$this->assertStringContainsString( "get_post_type( \$id )", $src );
		$this->assertStringContainsString( "'lafka_glb_addon'", $src );
	}

	public function test_capability_check_present(): void {
		$src = $this->admin_source();

		$this->assertStringContainsString( "current_user_can( 'delete_post', \$id )", $src );
	}

	public function test_uses_wp_trash_post_not_wp_delete_post(): void {
		$src = $this->admin_source();

		$this->assertStringContainsString(
			'wp_trash_post( $id )',
			$src,
			'Deletion must be recoverable: wp_trash_post() instead of wp_delete_post( $id, true )'
		);
		$this->assertDoesNotMatchRegularExpression(
			"/wp_delete_post\(\s*absint\(\s*\\\$_GET\['delete'\]\s*\)\s*,\s*true\s*\)/",
			$src,
			'Old force-delete pattern must not return'
		);
	}

	public function test_handler_wp_dies_on_invalid_input(): void {
		$src = $this->admin_source();

		// At least one wp_die() now guards the delete branch (invalid id /
		// nonce failure / cap failure).
		$this->assertGreaterThanOrEqual(
			3,
			substr_count( $src, 'wp_die(' ),
			'Three wp_die() calls expected: invalid ID, nonce, capability'
		);
	}
}
