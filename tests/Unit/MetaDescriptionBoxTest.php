<?php
declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * P6-SEO-4 W2-T2 regression lock: per-post meta description meta box must
 * be registered, rendered with a placeholder showing the auto-resolved fallback,
 * and saved with proper nonce + capability checks.
 */
final class MetaDescriptionBoxTest extends TestCase {

    private string $module;

    protected function setUp(): void {
        parent::setUp();
        $this->module = file_get_contents(
            dirname( __DIR__, 2 ) . '/incl/admin/lafka-meta-description-box.php'
        );
    }

    public function test_module_exists(): void {
        $this->assertNotEmpty( $this->module );
    }

    public function test_register_box_hooked_to_add_meta_boxes(): void {
        $this->assertMatchesRegularExpression(
            "/add_action\(\s*['\"]add_meta_boxes['\"]\s*,\s*['\"]lafka_meta_description_register_box['\"]/",
            $this->module
        );
    }

    public function test_save_hooked_to_save_post(): void {
        $this->assertMatchesRegularExpression(
            "/add_action\(\s*['\"]save_post['\"]\s*,\s*['\"]lafka_meta_description_save['\"]/",
            $this->module
        );
    }

    public function test_box_registers_for_post_page_and_product(): void {
        $this->assertStringContainsString( "'post'", $this->module );
        $this->assertStringContainsString( "'page'", $this->module );
        $this->assertStringContainsString( "post_type_exists( 'product' )", $this->module );
    }

    public function test_save_uses_nonce_and_capability_check(): void {
        $this->assertStringContainsString( 'wp_verify_nonce', $this->module );
        $this->assertStringContainsString( "current_user_can( 'edit_post'", $this->module );
    }

    public function test_save_uses_sanitize_text_field_not_textarea(): void {
        // Meta descriptions should NOT be multi-line (Google treats line breaks
        // as spaces in SERP snippets anyway), so sanitize_text_field is correct.
        $this->assertStringContainsString( 'sanitize_text_field', $this->module );
        $this->assertStringNotContainsString( 'sanitize_textarea_field', $this->module );
    }

    public function test_storage_key_matches_resolver(): void {
        // The resolver in W1-T15 (lafka_resolve_meta_description) reads
        // _lafka_meta_description; the box must write to the same key.
        $this->assertStringContainsString( "'_lafka_meta_description'", $this->module );
    }

    public function test_placeholder_uses_resolver_for_fallback_preview(): void {
        $this->assertStringContainsString( 'lafka_resolve_meta_description', $this->module );
    }

    public function test_main_plugin_requires_module(): void {
        $main = file_get_contents( dirname( __DIR__, 2 ) . '/lafka-plugin.php' );
        $this->assertStringContainsString( 'lafka-meta-description-box.php', $main );
    }
}
