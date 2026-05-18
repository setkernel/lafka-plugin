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
        // v9.7.24: lafka_insert_og_tags now declares an inner closure
        // (`$resolve_post_image`) for image-src lookup, which trips brace-
        // counting regexes that try to extract the function body. Switched
        // to a fixed-size window slice anchored at the function name.
        // v9.22.2: bumped 6,000 → 9,000 bytes — the fallback-chain
        // tiers (per-post override + Customizer default) + filter-driven
        // locale resolution pushed the function past 7.7 KB.
        $src = file_get_contents( dirname( __DIR__, 2 ) . '/lafka-plugin.php' );
        $fn_start = strpos( $src, 'function lafka_insert_og_tags' );
        $this->assertNotFalse( $fn_start, 'function lafka_insert_og_tags not found' );
        $body = substr( $src, $fn_start, 9000 );

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

    public function test_og_image_dimensions_use_actual_image_size(): void {
        // v9.7.24: og:image:width / og:image:height now reflect actual
        // attachment dimensions (looked up via wp_get_attachment_image_src)
        // rather than the WP `large_size_w/h` option (which was emitted
        // regardless of the image's real size — Facebook/LinkedIn cached
        // wrong aspect ratios for any non-square thumbnail).
        $src = file_get_contents( dirname( __DIR__, 2 ) . '/lafka-plugin.php' );
        $fn_start = strpos( $src, 'function lafka_insert_og_tags' );
        $body = substr( $src, $fn_start, 9000 );

        $this->assertStringContainsString(
            'wp_get_attachment_image_src',
            $body,
            'og:image dimensions must come from wp_get_attachment_image_src() (actual size), not the large_size_w/h option.'
        );
        $this->assertDoesNotMatchRegularExpression(
            "/get_option\(\s*'large_size_w'/",
            $body,
            'og:image must not regress to reading large_size_w/h option for dimensions.'
        );
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

    public function test_resolve_constructed_pitch_reads_flat_cuisine_and_city_keys(): void {
        // v9.22.1 regression lock: lafka_get_restaurant_info() returns flat
        // `cuisines` + `city`. The constructed-pitch branch previously read
        // `servedCuisine` and `address.addressLocality`, which never
        // existed in the array — the pitch collapsed to just `name` on
        // every install, leading to "Peppery Pizza & Poutine" on every
        // page's meta description. Lock the correct keys.
        $src = file_get_contents( dirname( __DIR__, 2 ) . '/lafka-plugin.php' );
        $this->assertStringContainsString(
            "\$info['cuisines']",
            $src,
            'Constructed-pitch branch must read $info[\'cuisines\'] (not servedCuisine).'
        );
        $this->assertStringContainsString(
            "\$info['city']",
            $src,
            'Constructed-pitch branch must read $info[\'city\'] (not address.addressLocality).'
        );
        $this->assertStringNotContainsString(
            "\$info['servedCuisine']",
            $src,
            'Stale servedCuisine key must not reappear.'
        );
        $this->assertStringNotContainsString(
            "\$info['address']['addressLocality']",
            $src,
            'Stale address.addressLocality key must not reappear.'
        );
    }

    public function test_resolve_tagline_guard_against_site_name_dup(): void {
        // v9.22.0: when the tagline equals the site name (a common
        // fresh-install state) it produces a useless meta description
        // that competes with — and beats — the Restaurant Info fallback.
        $src = file_get_contents( dirname( __DIR__, 2 ) . '/lafka-plugin.php' );
        $this->assertStringContainsString(
            'is_dupe_of_nam',
            $src,
            'Tagline guard for site-name duplicate must be present.'
        );
        $this->assertStringContainsString(
            'Just another WordPress site',
            $src,
            'WP-default tagline guard must be present.'
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
        // v9.22.2: switched from a greedy `\n}\s*}` regex to a fixed-size
        // window slice. The fallback-chain refactor introduced nested
        // closures whose own `}` blocks ended the original lazy match too
        // early.
        $src = file_get_contents( dirname( __DIR__, 2 ) . '/lafka-plugin.php' );
        $fn_start = strpos( $src, 'function lafka_insert_og_tags' );
        $this->assertNotFalse( $fn_start, 'function lafka_insert_og_tags not found' );
        // The slice must extend past the if/elseif chain (singular branch
        // is the 2nd elseif arm). 9 KB covers the full function comfortably.
        $body = substr( $src, $fn_start, 9000 );

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

    // ────────────────────────────────────────────────────────────────────────
    // v9.22.2 — og:image fallback chain + locale Customizer/filter
    // ────────────────────────────────────────────────────────────────────────

    /**
     * Tier 1 of the v9.22.2 fallback chain: per-page _lafka_og_image post meta
     * override. Operators can pin a specific OG image on any page.
     */
    public function test_og_image_reads_per_post_override_meta(): void {
        $src = file_get_contents( dirname( __DIR__, 2 ) . '/lafka-plugin.php' );
        $this->assertStringContainsString(
            "'_lafka_og_image'",
            $src,
            'Per-post _lafka_og_image meta override (Tier 1) must be read in lafka_insert_og_tags.'
        );
    }

    /**
     * Tier 3 of the v9.22.2 fallback chain: Customizer-pinned default image.
     * Without this, /menu/ and /contact-us/ emit no og:image at all
     * (visual QA report O06-menu, O06-contact).
     */
    public function test_og_image_falls_back_to_customizer_default(): void {
        $src = file_get_contents( dirname( __DIR__, 2 ) . '/lafka-plugin.php' );
        $this->assertStringContainsString(
            "'lafka_og_image_default'",
            $src,
            'Customizer lafka_og_image_default (Tier 3) must be read as a fallback.'
        );
    }

    /**
     * v9.22.2 locale override: `lafka_og_locale` filter lets a plugin/theme
     * pin og:locale per-request without changing WP Settings.
     */
    public function test_og_locale_passes_through_filter(): void {
        $src = file_get_contents( dirname( __DIR__, 2 ) . '/lafka-plugin.php' );
        $this->assertMatchesRegularExpression(
            "/apply_filters\(\s*['\"]lafka_og_locale['\"]/",
            $src,
            'og:locale must pass through `lafka_og_locale` filter for plugin/theme overrides.'
        );
    }

    /**
     * v9.22.2: Customizer setting `lafka_default_locale` takes precedence over
     * Settings → General → Site Language so operators on `en_US` WP installs
     * can pin `en_CA` for og:locale + <html lang> without WP wrangling.
     */
    public function test_og_locale_reads_customizer_default_locale(): void {
        $src = file_get_contents( dirname( __DIR__, 2 ) . '/lafka-plugin.php' );
        $this->assertStringContainsString(
            "'lafka_default_locale'",
            $src,
            'Customizer lafka_default_locale must be read by lafka_insert_og_tags.'
        );
    }

    /**
     * <html lang> must also honour `lafka_default_locale` — otherwise og:locale
     * and html-lang disagree, confusing crawlers.
     */
    public function test_language_attributes_filter_registered(): void {
        $src = file_get_contents( dirname( __DIR__, 2 ) . '/lafka-plugin.php' );
        $this->assertMatchesRegularExpression(
            "/add_filter\(\s*['\"]language_attributes['\"]\s*,\s*['\"]lafka_filter_language_attributes['\"]/",
            $src,
            'language_attributes filter must be registered to drive <html lang> from Customizer.'
        );
        $this->assertStringContainsString(
            'function lafka_filter_language_attributes',
            $src,
            'lafka_filter_language_attributes callback must exist.'
        );
    }

    /**
     * `lafka_filter_language_attributes` must skip admin so back-office i18n
     * is unaffected.
     */
    public function test_language_attributes_filter_skips_admin(): void {
        $src = file_get_contents( dirname( __DIR__, 2 ) . '/lafka-plugin.php' );
        $fn_start = strpos( $src, 'function lafka_filter_language_attributes' );
        $this->assertNotFalse( $fn_start, 'lafka_filter_language_attributes function not found' );
        $body = substr( $src, $fn_start, 2000 );
        $this->assertStringContainsString( 'is_admin()', $body );
    }
}
