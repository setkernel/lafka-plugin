<?php
/**
 * C-8: CSRF bypass via inverted nonce guard in metaboxes.
 *
 * The pre-fix pattern was:
 *
 *     if ( isset( $_POST['nonce'] ) && ! wp_verify_nonce( ... ) ) {
 *         return;
 *     }
 *
 * This only returns when the nonce IS present BUT invalid — which means an
 * attacker who simply omits `_POST['nonce']` skips the guard entirely and the
 * save proceeds unauthenticated.
 *
 * The fix inverts the guard:
 *
 *     if ( ! isset( $_POST['nonce'] ) || ! wp_verify_nonce( ... ) ) {
 *         return;
 *     }
 *
 * This source-grep test enforces that metaboxes.php contains zero matches of
 * the dangerous pattern, and that the negated form is used at every site.
 *
 * @package Lafka\Plugin\Tests\Unit
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class MetaboxNonceGuardTest extends TestCase {

	private function source(): string {
		$path = dirname( __DIR__, 2 ) . '/incl/metaboxes.php';
		$this->assertFileExists( $path );

		return file_get_contents( $path );
	}

	public function test_no_dangerous_isset_AND_not_verify_pattern_remains(): void {
		$src = $this->source();

		// The bad shape: `isset( $_POST['…'] ) && ! wp_verify_nonce(`
		// (positive isset, conjunction, negative verify) → returns only when
		// the nonce is present AND invalid, allowing missing-nonce bypass.
		$matches = array();
		preg_match_all(
			'/isset\(\s*\$_POST\[[^\]]+\]\s*\)\s*&&\s*!\s*wp_verify_nonce\(/',
			$src,
			$matches
		);

		$this->assertSame(
			0,
			count( $matches[0] ),
			'metaboxes.php must not contain "isset(...) && ! wp_verify_nonce(...)" — that pattern lets attackers bypass nonce checks by omitting the field'
		);
	}

	public function test_all_negated_guards_are_in_place(): void {
		$src = $this->source();

		// Every nonce field below must be guarded with the negated form:
		// `! isset( $_POST['<field>'] ) || ! wp_verify_nonce(...)`.
		$expected_fields = array(
			'layout_nonce',
			'page_options_nonce',
			'lafka_revolution_slider',
			'video_bckgr_nonce',
			'lafka_foodmenu_nonce',
			'lafka_featuredmeta',
			'foodmenu_cz_nonce',
			'product_video_nonce',
			'product_gallery_type_nonce',
		);

		foreach ( $expected_fields as $field ) {
			$pattern = '/!\s*isset\(\s*\$_POST\[\s*\x27' . preg_quote( $field, '/' ) . '\x27\s*\]\s*\)\s*\|\|\s*!\s*wp_verify_nonce\(/';
			$this->assertMatchesRegularExpression(
				$pattern,
				$src,
				"Negated nonce guard missing for \$_POST['{$field}']"
			);
		}
	}

	public function test_wp_unslash_used_on_each_nonce_value(): void {
		$src = $this->source();

		// Defense-in-depth: nonce values from $_POST must be wp_unslash()'d
		// before sanitization.
		$this->assertGreaterThanOrEqual(
			9,
			substr_count( $src, 'wp_unslash( $_POST[' ),
			'Each metabox nonce read should use wp_unslash() on $_POST'
		);
	}
}
