<?php
declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * P6-SEO-5 + P6-SEO-4 regression lock: complete OG + Twitter Cards
 * + meta description must be emitted from lafka-plugin.
 */
final class OgTwitterTagsTest extends TestCase {

    public function test_lafka_insert_og_tags_emits_full_og_set(): void {
        $src = file_get_contents( dirname( __DIR__, 2 ) . '/lafka-plugin.php' );
        // Pull just the function body for lafka_insert_og_tags
        preg_match( '/function\s+lafka_insert_og_tags\s*\([^)]*\)\s*\{(.*?)\n\s*\}\s*\}/s', $src, $m );
        $this->assertNotEmpty( $m, 'function lafka_insert_og_tags not found' );
        $body = $m[1];

        $required = [
            'og:title',
            'og:description',
            'og:url',
            'og:type',
            'og:site_name',
            'og:locale',
            'og:image',
            'twitter:card',
            'twitter:title',
            'twitter:description',
            'twitter:image',
        ];
        foreach ( $required as $tag ) {
            $this->assertStringContainsString( $tag, $body, "Missing $tag in lafka_insert_og_tags" );
        }
    }

    public function test_meta_description_callback_registered(): void {
        $src = file_get_contents( dirname( __DIR__, 2 ) . '/lafka-plugin.php' );
        $this->assertMatchesRegularExpression(
            "/add_action\(\s*['\"]wp_head['\"]\s*,\s*['\"]lafka_render_meta_description['\"]/",
            $src
        );
        $this->assertStringContainsString( 'function lafka_render_meta_description', $src );
    }

    public function test_resolve_helper_handles_post_meta_override(): void {
        $src = file_get_contents( dirname( __DIR__, 2 ) . '/lafka-plugin.php' );
        $this->assertStringContainsString( 'function lafka_resolve_meta_description', $src );
        $this->assertStringContainsString( "'_lafka_meta_description'", $src,
            'resolver must read _lafka_meta_description post meta as override' );
    }

    public function test_resolve_helper_handles_wc_product_short_desc(): void {
        $src = file_get_contents( dirname( __DIR__, 2 ) . '/lafka-plugin.php' );
        $this->assertMatchesRegularExpression(
            '/get_short_description|woocommerce_short_description/',
            $src
        );
    }

    /**
     * Regression lock for the static-homepage og:type bug (v8.11.4).
     *
     * When the WP "Reading" setting routes the front page to a static page,
     * both is_front_page() and is_singular() return true. The is_front_page()
     * branch must run first so the homepage emits og:type=restaurant.restaurant
     * and og:title=site-name, not the page's literal title.
     */
    public function test_front_page_branch_runs_before_singular_branch(): void {
        $src = file_get_contents( dirname( __DIR__, 2 ) . '/lafka-plugin.php' );
        preg_match( '/function\s+lafka_insert_og_tags\s*\([^)]*\)\s*\{(.*?)\n\s*\}\s*\}/s', $src, $m );
        $this->assertNotEmpty( $m, 'function lafka_insert_og_tags not found' );
        $body = $m[1];

        $front_pos    = strpos( $body, 'is_front_page()' );
        $singular_pos = strpos( $body, 'is_singular() && $post' );

        $this->assertNotFalse( $front_pos, 'is_front_page() check missing' );
        $this->assertNotFalse( $singular_pos, 'is_singular() check missing' );
        $this->assertLessThan(
            $singular_pos,
            $front_pos,
            'is_front_page() must be checked before is_singular() — static-homepage og bug regression'
        );
    }

    /**
     * Restaurant-typed og:type must remain on the front-page branch.
     * Drops here would mean Google can't tag the homepage as a local-business
     * profile and the knowledge-panel pipeline degrades.
     */
    public function test_front_page_emits_restaurant_og_type(): void {
        $src = file_get_contents( dirname( __DIR__, 2 ) . '/lafka-plugin.php' );
        $this->assertStringContainsString(
            "'restaurant.restaurant'",
            $src,
            'og:type=restaurant.restaurant literal must remain in lafka_insert_og_tags'
        );
    }
}
