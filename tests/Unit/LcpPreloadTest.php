<?php
/**
 * Homepage LCP hero: preload URL + fetchpriority. Both hooks are closures
 * registered at include time (unreachable while add_action/add_filter are
 * no-ops under the harness), so this reads the comment-free source until they
 * become named functions.
 *
 * @package Lafka\Plugin\Tests\Unit
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class LcpPreloadTest extends TestCase {

	public function test_both_hero_hooks_read_the_canonical_hero_setting_on_the_front_page_only(): void {
		$code = '';
		foreach ( token_get_all( (string) file_get_contents( dirname( __DIR__, 2 ) . '/incl/perf/lcp-preload.php' ) ) as $t ) {
			if ( ! is_array( $t ) || ! in_array( $t[0], array( T_COMMENT, T_DOC_COMMENT ), true ) ) {
				$code .= is_array( $t ) ? $t[1] : $t;
			}
		}

		// v9.30.x regression: the preload + fetchpriority hooks only read the
		// legacy keys, so a hero set via lafka_home_hero_image_id got neither.
		$this->assertSame( 2, substr_count( $code, "get_theme_mod( 'lafka_home_hero_image_id', 0 )" ) );
		$this->assertSame( 2, substr_count( $code, 'if ( ! is_front_page() ) {' ) );
		$this->assertStringContainsString( "\$attr['fetchpriority'] = 'high';", $code );
		$this->assertStringContainsString( "\$attr['loading']       = 'eager';", $code );
	}
}
