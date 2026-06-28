<?php
declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/incl/woocommerce-functions.php';

/**
 * SSOT guard for the per-variation "show in catalog" meta key.
 *
 * The plugin writes this key and the theme reads it across the repo boundary,
 * so the value is a frozen persisted DB key. Renaming it would silently break
 * the theme's in-catalog variation rendering against existing stored meta.
 * This test pins the canonical accessor to that literal so any accidental
 * rename fails loudly in CI instead of in production.
 */
final class VariableInCatalogMetaKeyTest extends TestCase {

	public function test_accessor_is_defined(): void {
		self::assertTrue(
			function_exists( 'lafka_meta_variable_in_catalog' ),
			'Plugin must expose the SSOT accessor for the catalog-visibility meta key.'
		);
	}

	public function test_accessor_returns_frozen_db_key(): void {
		// This value is persisted post meta — it must never drift.
		self::assertSame( '_lafka_variable_in_catalog', lafka_meta_variable_in_catalog() );
	}
}
