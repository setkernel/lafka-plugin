<?php
declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * Audit f049 regression lock — SEO-plugin coexistence guard must cover ALL
 * head-metadata emitters, not just the JSON-LD @graph.
 *
 * Before f049 only incl/schema/class-lafka-json-ld.php deferred to an active
 * SEO plugin (Yoast / Rank Math / SEOPress / AIOSEO). The OpenGraph
 * (lafka_insert_og_tags) and meta-description (lafka_render_meta_description)
 * emitters had no such guard, so the site shipped a duplicate
 * <meta name="description"> plus a full duplicate og:* / twitter:* set
 * alongside the SEO plugin's own output on every public page.
 *
 * These are source-grep locks (matching OgTwitterTagsTest's convention) —
 * lafka-plugin.php cannot be include-loaded under the unit harness, so we
 * assert on its source text.
 */
final class SeoPluginCoexistenceGuardTest extends TestCase {

	private static function plugin_src(): string {
		return (string) file_get_contents( dirname( __DIR__, 2 ) . '/lafka-plugin.php' );
	}

	private static function schema_src(): string {
		return (string) file_get_contents( dirname( __DIR__, 2 ) . '/incl/schema/class-lafka-json-ld.php' );
	}

	/**
	 * Slice a fixed-size window anchored at a function definition so brace-
	 * counting against nested closures is unnecessary (same approach as
	 * OgTwitterTagsTest).
	 */
	private static function fn_body( string $src, string $signature, int $len = 2000 ): string {
		$start = strpos( $src, $signature );
		if ( false === $start ) {
			return '';
		}
		return substr( $src, $start, $len );
	}

	public function test_shared_helper_is_defined(): void {
		$src = self::plugin_src();
		$this->assertStringContainsString(
			'function lafka_seo_plugin_active',
			$src,
			'Shared SEO-plugin detection helper lafka_seo_plugin_active() must exist as the single source of truth.'
		);
	}

	/**
	 * The helper must detect every SEO plugin the JSON-LD module historically
	 * detected — dropping any one would reopen the duplicate-tag defect for
	 * that plugin's users.
	 *
	 * @return array<string, array{0:string}>
	 */
	public static function seo_plugin_signals(): array {
		return [
			'Yoast SEO'      => [ "defined( 'WPSEO_VERSION' )" ],
			'Rank Math'      => [ "class_exists( 'RankMath' )" ],
			'SEOPress'       => [ "defined( 'SEOPRESS_VERSION' )" ],
			'All in One SEO' => [ "class_exists( '\\\\AIOSEO\\\\Plugin\\\\AIOSEO' )" ],
		];
	}

	#[DataProvider('seo_plugin_signals')]
	public function test_helper_detects_each_seo_plugin( string $signal ): void {
		$body = self::fn_body( self::plugin_src(), 'function lafka_seo_plugin_active', 800 );
		$this->assertNotSame( '', $body, 'lafka_seo_plugin_active() body not found.' );
		$this->assertStringContainsString(
			$signal,
			$body,
			"lafka_seo_plugin_active() must detect SEO plugin via: $signal"
		);
	}

	public function test_og_emitter_defers_to_seo_plugin(): void {
		$body = self::fn_body( self::plugin_src(), 'function lafka_insert_og_tags', 1500 );
		$this->assertNotSame( '', $body, 'lafka_insert_og_tags() body not found.' );
		$this->assertStringContainsString(
			'lafka_seo_plugin_active()',
			$body,
			'lafka_insert_og_tags() must early-return when an SEO plugin is active (no duplicate og:* tags).'
		);
		$this->assertStringContainsString(
			'lafka_head_meta_force_emit',
			$body,
			'lafka_insert_og_tags() deferral must be overridable via the lafka_head_meta_force_emit filter.'
		);
	}

	public function test_meta_description_emitter_defers_to_seo_plugin(): void {
		$body = self::fn_body( self::plugin_src(), 'function lafka_render_meta_description', 1200 );
		$this->assertNotSame( '', $body, 'lafka_render_meta_description() body not found.' );
		$this->assertStringContainsString(
			'lafka_seo_plugin_active()',
			$body,
			'lafka_render_meta_description() must early-return when an SEO plugin is active (no duplicate <meta name="description">).'
		);
		$this->assertStringContainsString(
			'lafka_head_meta_force_emit',
			$body,
			'lafka_render_meta_description() deferral must be overridable via the lafka_head_meta_force_emit filter.'
		);
	}

	/**
	 * Single source of truth: the JSON-LD module must reuse the shared helper
	 * rather than carrying its own divergent copy of the detection (a stale
	 * copy could drift and start emitting a duplicate @graph for a plugin the
	 * head emitters defer to, or vice-versa).
	 */
	public function test_schema_module_reuses_shared_helper(): void {
		$src = self::schema_src();
		$this->assertStringContainsString(
			'lafka_seo_plugin_active()',
			$src,
			'class-lafka-json-ld.php must reuse lafka_seo_plugin_active() as the single source of truth.'
		);
	}
}
