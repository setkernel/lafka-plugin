<?php
/**
 * ShareLinksTest — locks down the v9.7.20 hardening of the
 * lafka_share_links() helper used by 5 theme files (forum, single-foodmenu,
 * single, woocommerce-functions ×2).
 *
 * Source-grep based. Functional testing the rendered output would need a
 * full WP/WC bootstrap to wire up get_the_post_thumbnail_url et al.
 *
 * @package Lafka\Plugin\Tests\Unit
 * @since   9.7.20
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ShareLinksTest extends TestCase {

	private function bootstrap_src(): string {
		return file_get_contents( dirname( __DIR__, 2 ) . '/lafka-plugin.php' );
	}

	public function test_share_endpoints_use_https(): void {
		// Modern browsers either upgrade http://→https:// or block mixed
		// content; emitting https from the start avoids both. Each share
		// host must appear exactly once in the share-links function with
		// https://.
		$src = $this->bootstrap_src();

		$endpoints = array(
			'https://www.facebook.com/sharer.php',
			'https://twitter.com/share',
			'https://pinterest.com/pin/create/button',
			'https://www.linkedin.com/shareArticle',
			'https://vk.com/share.php',
		);
		foreach ( $endpoints as $endpoint ) {
			$this->assertStringContainsString(
				$endpoint,
				$src,
				"Share endpoint must be https://: {$endpoint}"
			);
		}
	}

	public function test_no_http_share_endpoints_remain(): void {
		// Regression lock — the pre-fix code used http:// for all 5 hosts.
		// Searches for the specific http:// share endpoint strings; matches
		// elsewhere in the file (general http://) aren't blocked.
		$src = $this->bootstrap_src();

		$forbidden = array(
			'http://www.facebook.com/sharer.php',
			'http://twitter.com/share',
			'http://pinterest.com/pin/create/button',
			'http://www.linkedin.com/shareArticle',
			'http://vk.com/share.php',
		);
		foreach ( $forbidden as $bad ) {
			$this->assertStringNotContainsString(
				$bad,
				$src,
				"Share endpoint must not regress to http://: {$bad}"
			);
		}
	}

	public function test_share_hrefs_pass_through_esc_url(): void {
		// Defense-in-depth: each assembled href runs through esc_url() even
		// though hosts are hardcoded and query params are pre-encoded.
		// Looks for esc_url(...) calls inside the share-links function body.
		$src = $this->bootstrap_src();

		// Slice to just the function body for a tighter assertion.
		$fn_start = strpos( $src, 'function lafka_share_links' );
		$this->assertNotFalse( $fn_start, 'lafka_share_links function must exist.' );
		$slice = substr( $src, $fn_start, 4000 );

		// At least 5 esc_url() calls — one per share endpoint.
		$count = preg_match_all( "/esc_url\(\s*'https:/", $slice );
		$this->assertGreaterThanOrEqual( 5, $count, 'Expected at least 5 esc_url() wrapped https hrefs.' );
	}

	public function test_share_links_use_rawurlencode(): void {
		// urlencode() emits + for spaces (form-data style); rawurlencode()
		// emits %20 (URI style per RFC 3986). For URL query params,
		// rawurlencode is more correct.
		$src = $this->bootstrap_src();

		$fn_start = strpos( $src, 'function lafka_share_links' );
		$slice    = substr( $src, $fn_start, 4000 );

		$this->assertGreaterThanOrEqual(
			5,
			preg_match_all( '/rawurlencode\(/', $slice ),
			'Share links must use rawurlencode for query params.'
		);
		$this->assertSame(
			0,
			preg_match_all( '/[^w]urlencode\(/', $slice ),
			'Share links must not regress to urlencode().'
		);
	}

	public function test_share_links_have_noopener_noreferrer(): void {
		// target="_blank" without rel="noopener" lets the opened share page
		// access window.opener (tab-nabbing). All 5 share <a> tags must
		// include rel="noopener noreferrer".
		$src = $this->bootstrap_src();

		$fn_start = strpos( $src, 'function lafka_share_links' );
		$slice    = substr( $src, $fn_start, 4000 );

		$this->assertGreaterThanOrEqual(
			5,
			preg_match_all( '/rel="noopener noreferrer"/', $slice ),
			'Each share link must include rel="noopener noreferrer" alongside target="_blank".'
		);
	}
}
