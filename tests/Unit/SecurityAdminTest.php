<?php
/**
 * SecurityAdminTest — locks down Lafka_Security_Admin's form-post handler
 * surface.
 *
 * The handler controls a security toggle. Its three gates — capability
 * check, nonce verification, allowlist sanitization — are exactly the
 * ones an attacker would target to flip headers off remotely; tests
 * confirm none of them have drifted.
 *
 * Source-grep based since the handler does too much (wp_die, wp_safe_redirect,
 * exit) to test functionally without a heavier harness.
 *
 * @package Lafka\Plugin\Tests\Unit
 * @since   9.7.12
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SecurityAdminTest extends TestCase {

	private string $src;

	protected function setUp(): void {
		parent::setUp();
		$this->src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/security/class-lafka-security-admin.php' );
	}

	public function test_handle_save_checks_capability_first(): void {
		// manage_options gate must come BEFORE check_admin_referer so an
		// unauthorized POST gets 403, not a "nonce expired" error that leaks
		// admin-area existence. (Caps before nonce is the standard pattern.)
		$cap_pos   = strpos( $this->src, "current_user_can( 'manage_options' )" );
		$nonce_pos = strpos( $this->src, 'check_admin_referer( self::NONCE_ACTION )' );
		$this->assertNotFalse( $cap_pos, 'handle_save must check manage_options.' );
		$this->assertNotFalse( $nonce_pos, 'handle_save must verify the nonce.' );
		$this->assertLessThan( $nonce_pos, $cap_pos, 'Capability check must come before nonce verification.' );
	}

	public function test_handle_save_uses_check_admin_referer_not_just_wp_verify_nonce(): void {
		// check_admin_referer wraps wp_verify_nonce + die on failure. Using
		// it (vs raw wp_verify_nonce) ensures a failed nonce returns 403
		// rather than silently falling through to the option write.
		$this->assertStringContainsString( 'check_admin_referer( self::NONCE_ACTION )', $this->src );
	}

	public function test_toggle_value_is_allowlisted(): void {
		// The submitted value flows from $_POST through sanitize_text_field
		// and then a strict allowlist (`'enabled' === $requested`) — anything
		// else falls back to 'disabled'. Ensures an attacker can't smuggle
		// a third value into the option.
		$this->assertMatchesRegularExpression(
			"/'enabled'\s*===\s*\\\$requested\s*\)\s*\?\s*'enabled'\s*:\s*'disabled'/",
			$this->src,
			"Submitted toggle value must be allowlisted to enabled|disabled."
		);
	}

	public function test_uses_dedicated_option_key_not_main_lafka_array(): void {
		// Storage rationale: theme's options-framework register_setting('lafka', ...)
		// drops unregistered keys. Writing through the main `lafka` option
		// would silently lose the toggle on next save. The dedicated
		// `lafka_security_options` key sidesteps that.
		$this->assertStringContainsString( 'Lafka_Security_Headers::OPTION_KEY', $this->src );
		$this->assertStringContainsString( 'update_option( Lafka_Security_Headers::OPTION_KEY, $opts )', $this->src );
	}

	public function test_uses_admin_post_action_not_admin_init(): void {
		// admin_post_<action> dispatches off `action` POST var — the right
		// pattern for a one-shot form-post handler. admin_init would fire
		// on every admin pageload and is the wrong hook.
		$this->assertMatchesRegularExpression(
			"/add_action\(\s*'admin_post_lafka_security_save'/",
			$this->src
		);
	}

	public function test_render_page_also_gates_on_capability(): void {
		// Defense-in-depth — even though add_management_page already gates
		// on manage_options, render_page double-checks in case a later
		// refactor changes the menu cap and the page-render mismatches.
		$this->assertMatchesRegularExpression(
			"/public function render_page[\s\S]*?current_user_can\(\s*'manage_options'\s*\)/",
			$this->src,
			'render_page must gate on manage_options as defense-in-depth.'
		);
	}

	public function test_post_save_redirect_uses_safe_redirect(): void {
		// wp_safe_redirect (not raw wp_redirect) caps targets to the host
		// allowlist — reduces blast radius if a future refactor accidentally
		// builds the URL from user input.
		$this->assertStringContainsString( 'wp_safe_redirect(', $this->src );
		$this->assertStringNotContainsString( 'wp_redirect(', $this->src );
	}
}
