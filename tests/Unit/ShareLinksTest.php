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
		// v9.7.24: refactored to a foreach over a filterable network array,
		// so esc_url() is now called once inside the format string (not
		// per-network). The number of rendered links is dynamic; we just
		// assert the format string carries esc_url on the href.
		$src = $this->bootstrap_src();

		$fn_start = strpos( $src, 'function lafka_share_links' );
		$this->assertNotFalse( $fn_start, 'lafka_share_links function must exist.' );
		$slice = substr( $src, $fn_start, 8000 );

		$this->assertMatchesRegularExpression(
			"/esc_url\(\s*\\\$net\['url'\]\s*\)/",
			$slice,
			"Share-link href must run \$net['url'] through esc_url() before output."
		);
	}

	public function test_share_links_use_rawurlencode(): void {
		// urlencode() emits + for spaces (form-data style); rawurlencode()
		// emits %20 (URI style per RFC 3986). For URL query params,
		// rawurlencode is more correct.
		$src = $this->bootstrap_src();

		$fn_start = strpos( $src, 'function lafka_share_links' );
		$slice    = substr( $src, $fn_start, 8000 );

		// v9.7.24 expanded the default network list 5 → 8 (added WhatsApp,
		// Telegram, email). Bumping the floor accordingly.
		$this->assertGreaterThanOrEqual(
			8,
			preg_match_all( '/rawurlencode\(/', $slice ),
			'Share links must use rawurlencode for query params.'
		);
		$this->assertSame(
			0,
			preg_match_all( '/[^w]urlencode\(/', $slice ),
			'Share links must not regress to urlencode().'
		);
	}

	public function test_share_networks_filter_present(): void {
		// v9.7.24: list of share networks is filterable so child plugins
		// can add Mastodon / BlueSky / etc. without forking. Pre-fix the
		// 5 networks were hardcoded as 5 separate sprintf calls.
		$src = $this->bootstrap_src();
		$this->assertMatchesRegularExpression(
			"/apply_filters\(\s*\n?\s*'lafka_share_networks'/",
			$src,
			'Share-network list must be filterable via lafka_share_networks.'
		);
	}

	/**
	 * @dataProvider modernNetworksProvider
	 */
	public function test_default_network_list_includes_modern_network( string $key ): void {
		// Defaults must include the modern essentials so operators don't
		// have to write a filter just to enable WhatsApp / Telegram / email.
		$src = $this->bootstrap_src();
		$fn_start = strpos( $src, 'function lafka_share_links' );
		$slice    = substr( $src, $fn_start, 8000 );
		$this->assertMatchesRegularExpression(
			"/'" . preg_quote( $key, '/' ) . "'\s*=>\s*array\(/",
			$slice,
			"Default share-network list must include '{$key}'."
		);
	}

	public function modernNetworksProvider(): array {
		return array(
			'whatsapp' => array( 'whatsapp' ),
			'telegram' => array( 'telegram' ),
			'email'    => array( 'email' ),
		);
	}

	public function test_share_links_have_noopener_noreferrer(): void {
		// target="_blank" without rel="noopener" lets the opened share page
		// access window.opener (tab-nabbing). v9.7.24: refactored to a
		// single sprintf format string (not per-network), so we look for
		// the literal pattern in the format string itself.
		$src = $this->bootstrap_src();

		$fn_start = strpos( $src, 'function lafka_share_links' );
		$slice    = substr( $src, $fn_start, 8000 );

		$this->assertGreaterThanOrEqual(
			1,
			preg_match_all( '/rel="noopener noreferrer"/', $slice ),
			'Each share link must include rel="noopener noreferrer" alongside target="_blank".'
		);
	}
}
