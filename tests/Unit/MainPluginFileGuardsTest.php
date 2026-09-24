<?php
/**
 * Source guards for behaviours that live in lafka-plugin.php, which the unit
 * harness cannot load (it boots WP/WC at include time). Each guard reads the
 * comment-stripped body of one function, so only executable code counts.
 *
 * These are placeholders: once the functions move into includable modules
 * (e.g. incl/seo/lafka-head-meta.php, incl/lafka-share-links.php) they should
 * be replaced by tests that call them.
 *
 * @package Lafka\Plugin\Tests\Unit
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class MainPluginFileGuardsTest extends TestCase {

	/** @var array<string, string>|null Function name => comment-free body. */
	private static ?array $bodies = null;

	private static function body( string $function ): string {
		if ( null === self::$bodies ) {
			self::$bodies = array();
			$tokens       = token_get_all( (string) file_get_contents( dirname( __DIR__, 2 ) . '/lafka-plugin.php' ) );
			$count        = count( $tokens );
			for ( $i = 0; $i < $count; $i++ ) {
				if ( ! is_array( $tokens[ $i ] ) || T_FUNCTION !== $tokens[ $i ][0] ) {
					continue;
				}
				$j = $i + 1;
				while ( $j < $count && is_array( $tokens[ $j ] ) && T_WHITESPACE === $tokens[ $j ][0] ) {
					++$j;
				}
				if ( ! is_array( $tokens[ $j ] ) || T_STRING !== $tokens[ $j ][0] ) {
					continue; // Closure.
				}
				$name  = $tokens[ $j ][1];
				$code  = '';
				$depth = 0;
				for ( $k = $j; $k < $count; $k++ ) {
					$t = $tokens[ $k ];
					if ( is_array( $t ) ) {
						if ( T_COMMENT === $t[0] || T_DOC_COMMENT === $t[0] ) {
							continue;
						}
						if ( T_CURLY_OPEN === $t[0] || T_DOLLAR_OPEN_CURLY_BRACES === $t[0] ) {
							++$depth;
						}
						$code .= $t[1];
						continue;
					}
					$code .= $t;
					if ( '{' === $t ) {
						++$depth;
					} elseif ( '}' === $t && 0 === --$depth ) {
						break;
					}
				}
				self::$bodies[ $name ] = $code;
			}
		}
		self::assertArrayHasKey( $function, self::$bodies, "{$function}() not found in lafka-plugin.php" );
		return self::$bodies[ $function ];
	}

	/**
	 * f049: with Yoast / Rank Math / SEOPress / AIOSEO active, Lafka's
	 * description + OG/Twitter tags must defer (duplicate head metadata
	 * otherwise), overridable via lafka_head_meta_force_emit.
	 */
	public function test_head_meta_emitters_defer_to_an_active_seo_plugin(): void {
		$detector = self::body( 'lafka_seo_plugin_active' );
		foreach ( array( "defined( 'WPSEO_VERSION' )", "class_exists( 'RankMath' )", "defined( 'SEOPRESS_VERSION' )", "class_exists( '\\\\AIOSEO\\\\Plugin\\\\AIOSEO' )" ) as $signal ) {
			$this->assertStringContainsString( $signal, $detector );
		}

		$guard = "/if\s*\(\s*lafka_seo_plugin_active\(\)\s*&&\s*!\s*\(bool\)\s*apply_filters\(\s*'lafka_head_meta_force_emit',\s*false\s*\)\s*\)\s*\{\s*return;/";
		$this->assertMatchesRegularExpression( $guard, self::body( 'lafka_insert_og_tags' ) );
		$this->assertMatchesRegularExpression( $guard, self::body( 'lafka_render_meta_description' ) );
	}

	/**
	 * v8.11.4: a static front page is also is_singular(); the front-page
	 * branch must win so the homepage gets og:type restaurant.restaurant.
	 * v9.7.24: og:image dimensions come from the actual attachment, not the
	 * large_size_w/h option.
	 */
	public function test_og_tags_front_page_branch_and_real_image_dimensions(): void {
		$og = self::body( 'lafka_insert_og_tags' );

		$front    = strpos( $og, 'if ( is_front_page() || is_home() )' );
		$singular = strpos( $og, '} elseif ( is_singular() && $post )' );
		$this->assertNotFalse( $front );
		$this->assertNotFalse( $singular );
		$this->assertLessThan( $singular, $front );
		$this->assertStringContainsString( "\$og_type     = 'restaurant.restaurant';", substr( $og, $front, $singular - $front ) );

		$this->assertStringContainsString( 'wp_get_attachment_image_src(', $og );
		$this->assertStringNotContainsString( 'large_size_w', $og );
	}

	/**
	 * v9.22.0 / v9.22.1: the WP default tagline and a tagline equal to the
	 * site name are skipped, and the constructed pitch reads the resolver's
	 * flat `cuisines` / `city` keys (it read keys that never existed).
	 */
	public function test_meta_description_fallbacks(): void {
		$resolver = self::body( 'lafka_resolve_meta_description' );
		$this->assertStringContainsString( "'Just another WordPress site' === \$tagline", $resolver );
		$this->assertStringContainsString( 'strcasecmp( trim( $tagline ), trim( $site_name ) )', $resolver );
		$this->assertStringContainsString( "\$info['cuisines']", $resolver );
		$this->assertStringContainsString( "\$info['city']", $resolver );
	}

	/**
	 * Share links open in a new tab: rel="noopener noreferrer" (tab-nabbing)
	 * and every href through esc_url().
	 */
	public function test_share_links_are_escaped_and_noopener(): void {
		$share = self::body( 'lafka_share_links' );
		$this->assertMatchesRegularExpression( '/<a [^\']*target="_blank" rel="noopener noreferrer"/', $share );
		$this->assertMatchesRegularExpression( "/esc_url\(\s*\\\$net\['url'\]\s*\)/", $share );
		$this->assertStringNotContainsString( 'http://', $share );
	}
}
