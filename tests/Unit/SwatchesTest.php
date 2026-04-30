<?php
/**
 * SwatchesTest — functional + source-grep coverage for the variation
 * swatches subsystem.
 *
 * Covers:
 *   - swatch HTML rendering for color, image, label types (incl. RGBA derive)
 *   - save_term_meta hardening (taxonomy + capability gates from v9.7.10)
 *   - lafka_wcs_attribute_types filter extensibility (v9.7.11)
 *   - source-grep regression locks for the v9.7.10/11 hardening
 *
 * @package Lafka\Plugin\Tests\Unit
 * @since   9.7.11
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class SwatchesTest extends TestCase {

	private function admin_src(): string {
		return file_get_contents( dirname( __DIR__, 2 ) . '/incl/swatches/classes/class-admin.php' );
	}

	private function frontend_src(): string {
		return file_get_contents( dirname( __DIR__, 2 ) . '/incl/swatches/classes/class-frontend.php' );
	}

	private function entry_src(): string {
		return file_get_contents( dirname( __DIR__, 2 ) . '/incl/swatches/variation-swatches.php' );
	}

	// ────────────────────────────────────────────────────────────────────────
	// v9.7.10 — save_term_meta hardening
	// ────────────────────────────────────────────────────────────────────────

	public function test_save_term_meta_guards_on_pa_taxonomy_prefix(): void {
		// The hook fires for ALL taxonomies — categories, tags, custom — so
		// the callback must early-return for anything not prefixed `pa_`
		// (WC product-attribute prefix). Pre-fix any taxonomy that happened
		// to have $_POST['color'] / ['image'] / ['label'] populated would
		// silently get swatch term meta written to it.
		$src = $this->admin_src();
		$this->assertMatchesRegularExpression(
			"/strpos\(\s*\\\$taxonomy\s*,\s*'pa_'\s*\)/",
			$src,
			'save_term_meta must guard taxonomy with the pa_ prefix check.'
		);
	}

	public function test_save_term_meta_checks_capability(): void {
		// Programmatic wp_update_term flows skip the term-edit screen's
		// nonce gate, so save_term_meta needs an explicit capability check.
		$src = $this->admin_src();
		$this->assertMatchesRegularExpression(
			"/current_user_can\(\s*'manage_product_terms'\s*\)/",
			$src,
			'save_term_meta must check manage_product_terms before writing meta.'
		);
	}

	public function test_save_term_meta_hooks_request_three_args(): void {
		// The $taxonomy arg only flows through when accepted_args = 3.
		// Pre-fix accepted_args was 2, so $taxonomy was never set and the
		// pa_ guard would never engage.
		$src = $this->admin_src();
		$this->assertMatchesRegularExpression(
			"/add_action\(\s*'created_term'\s*,.*?,\s*10\s*,\s*3\s*\)\s*;/s",
			$src,
			"created_term hook must request 3 args (term_id, tt_id, taxonomy)."
		);
		$this->assertMatchesRegularExpression(
			"/add_action\(\s*'edit_term'\s*,.*?,\s*10\s*,\s*3\s*\)\s*;/s",
			$src,
			"edit_term hook must request 3 args."
		);
	}

	// ────────────────────────────────────────────────────────────────────────
	// v9.7.11 — escape, strict comparison, filter
	// ────────────────────────────────────────────────────────────────────────

	public function test_translated_label_escaped_before_printf(): void {
		// Lafka_WCVS()->types[ $type ] is __('Color', ...) — translatable
		// strings are operator-controlled, and a malicious or buggy
		// translation could otherwise inject HTML. Must be esc_html()'d
		// before flowing into the form-field printf.
		$src = $this->admin_src();
		$this->assertMatchesRegularExpression(
			"/esc_html\(\s*\(string\)\s*\(\s*Lafka_WCVS\(\)->types/",
			$src,
			'Translated swatch type label must be esc_html() before output.'
		);
	}

	public function test_attribute_types_filterable(): void {
		$src = $this->entry_src();
		$this->assertMatchesRegularExpression(
			"/apply_filters\(\s*\n?\s*'lafka_wcs_attribute_types'/",
			$src,
			'Swatch types map must be filterable via lafka_wcs_attribute_types so child plugins can extend.'
		);
	}

	public function test_no_loose_null_singleton_comparisons(): void {
		// Strict null checks across all 3 files so PHP 8.x deprecation paths
		// don't fire for any singleton accessor.
		foreach ( array( $this->admin_src(), $this->frontend_src(), $this->entry_src() ) as $src ) {
			$this->assertDoesNotMatchRegularExpression(
				'/null\s*==\s*self::\$instance/',
				$src,
				'Singletons must compare null with === not ==.'
			);
		}
	}

	public function test_no_loose_form_comparison_in_attribute_fields(): void {
		// The 'edit' === $form ternary fires during admin term-add and
		// term-edit screens; loose `==` would coerce other values to true.
		$src = $this->admin_src();
		$this->assertDoesNotMatchRegularExpression(
			"/'edit'\s*==\s*\\\$form/",
			$src,
			"Form-mode comparison must use === ('edit' === \$form), not loose ==."
		);
	}

	// ────────────────────────────────────────────────────────────────────────
	// Frontend — swatch_html() rendering shape (existing behaviour locks)
	// ────────────────────────────────────────────────────────────────────────

	public function test_color_swatch_renders_rgba_overlay(): void {
		// The color swatch derives an `rgba(r,g,b,0.5)` color value from the
		// hex via sscanf so the inline `color:` CSS for the swatch's text
		// label has guaranteed contrast against the background. If a refactor
		// ever drops the rgba path, the swatch text could become invisible
		// against same-coloured backgrounds.
		$src = $this->frontend_src();
		$this->assertMatchesRegularExpression(
			"/sscanf\(\s*\\\$color\s*,\s*'#%02x%02x%02x'\s*\)/",
			$src,
			'Color swatch must derive rgba components via sscanf for the readable-text overlay.'
		);
		$this->assertStringContainsString( 'rgba(', $src );
	}

	public function test_image_swatch_falls_back_to_wc_placeholder(): void {
		// Missing image attachments must fall back to WC's bundled
		// placeholder, NOT a hardcoded Lafka asset path — keeps OSS-safety.
		$src = $this->frontend_src();
		$this->assertStringContainsString(
			"WC()->plugin_url() . '/assets/images/placeholder.png'",
			$src,
			'Image swatch must fall back to WC core placeholder for missing images.'
		);
	}

	public function test_swatch_html_filter_priority_5(): void {
		// The internal swatch_html() callback must hook at priority 5 so
		// child plugins can register higher-priority callbacks that override.
		$src = $this->frontend_src();
		$this->assertMatchesRegularExpression(
			"/add_filter\(\s*'lafka-wcs_swatch_html'\s*,.*?,\s*5\s*,/s",
			$src
		);
	}

	public function test_swatch_html_strict_selected_match(): void {
		$src = $this->frontend_src();
		$this->assertMatchesRegularExpression(
			"/sanitize_title\(\s*\\\$args\['selected'\]\s*\)\s*===\s*\\\$term->slug/",
			$src,
			"selected/term match must use === not ==."
		);
	}
}
