<?php
/**
 * ShortcodePartialsEscapingTest — locks down the v9.7.19 polish on the
 * shortcode partials.
 *
 * Two regression locks:
 *   - No `echo __(...)` pattern in the partials. `__()` returns a
 *     translated string but doesn't escape it, so a malicious or buggy
 *     translation could inject HTML/JS into the rendered output. Same bug
 *     class fixed in v9.7.4 (schema breadcrumb) and v9.7.16 (review email).
 *   - contact-form.php must defensively initialize $lafka_shortcode_params_for_tpl
 *     to avoid undefined-variable notices when the AJAX-handler include path
 *     re-renders the partial.
 *
 * @package Lafka\Plugin\Tests\Unit
 * @since   9.7.19
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class ShortcodePartialsEscapingTest extends TestCase {

	private function vendors_list_src(): string {
		return file_get_contents( dirname( __DIR__, 2 ) . '/shortcodes/partials/vendors_list.php' );
	}

	private function contact_form_src(): string {
		return file_get_contents( dirname( __DIR__, 2 ) . '/shortcodes/partials/contact-form.php' );
	}

	public function test_vendors_list_uses_escaped_translation_helpers(): void {
		// `echo __(...)` would let a malicious translation inject HTML; must
		// use esc_html_e / esc_attr_e / esc_html__ / esc_attr__ instead.
		$src = $this->vendors_list_src();
		$this->assertDoesNotMatchRegularExpression(
			"/echo\s+__\(\s*'/",
			$src,
			"vendors_list.php must not echo __() without escaping — use esc_html_e or esc_attr_e."
		);
	}

	public function test_vendors_list_emits_escaped_attributes(): void {
		// Sort button value flows into an `<input value="..." type="submit">` —
		// must be esc_attr__'d so a translation containing `"` can't break out.
		$src = $this->vendors_list_src();
		$this->assertMatchesRegularExpression(
			"/esc_attr__\(\s*'Sort'/",
			$src,
			"Sort button must use esc_attr__ for the value attribute."
		);
	}

	public function test_vendors_list_uses_selected_helper(): void {
		// The pre-fix code echoed 'selected="selected"' inline behind PHP
		// conditionals; the canonical WP helper `selected()` is both safer
		// and more idiomatic.
		$src = $this->vendors_list_src();
		$this->assertMatchesRegularExpression(
			"/selected\(\s*\\\$sort_type\s*,\s*'(registered|name|category)'\s*\)/",
			$src,
			"Sort-type select must use the WP selected() helper."
		);
	}

	public function test_contact_form_initializes_shortcode_params_for_tpl(): void {
		// The hidden field round-trips a JSON blob set by the shortcode-render
		// path (shortcodes.php:1414). When the partial is included from the
		// AJAX handler `lafka_submit_contact`, that variable isn't set, which
		// pre-fix produced an undefined-variable notice on every form refresh.
		$src = $this->contact_form_src();
		$this->assertMatchesRegularExpression(
			"/!\s*isset\(\s*\\\$lafka_shortcode_params_for_tpl\s*\)\s*\)\s*\{\s*\\\$lafka_shortcode_params_for_tpl\s*=\s*''/s",
			$src,
			"contact-form.php must default \$lafka_shortcode_params_for_tpl to '' when not set by the shortcode-render path."
		);
	}
}
