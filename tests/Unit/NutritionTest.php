<?php
/**
 * NutritionTest — locks down the nutrition module's data-integrity and
 * extensibility guarantees.
 *
 * Two main areas:
 *   - v9.7.13 data-loss guard: process_meta_box must bail on saves where
 *     the panel marker isn't present (prevents Quick Edit / REST / bulk
 *     saves from silently wiping operator-entered nutrition meta).
 *   - v9.7.14 extensibility: lafka_nutrition_meta_fields filter must run
 *     and numeric inputs prevent typo'd values.
 *
 * @package Lafka\Plugin\Tests\Unit
 * @since   9.7.14
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class NutritionTest extends TestCase {

	private function admin_src(): string {
		return file_get_contents( dirname( __DIR__, 2 ) . '/incl/nutrition/admin/class-lafka-nutrition-admin.php' );
	}

	private function panel_src(): string {
		return file_get_contents( dirname( __DIR__, 2 ) . '/incl/nutrition/admin/views/html-nutrition-panel.php' );
	}

	private function config_src(): string {
		return file_get_contents( dirname( __DIR__, 2 ) . '/incl/nutrition/includes/class-lafka-nutrition-config.php' );
	}

	// ────────────────────────────────────────────────────────────────────────
	// v9.7.13 — data-loss guard
	// ────────────────────────────────────────────────────────────────────────

	public function test_panel_emits_marker_field(): void {
		// The marker is what process_meta_box looks for to confirm a save
		// originated from this product editor (vs. REST / Quick Edit /
		// programmatic). Removing it would re-introduce the data-loss bug.
		$src = $this->panel_src();
		$this->assertMatchesRegularExpression(
			"/<input\s+type=\"hidden\"\s+name=\"_lafka_nutrition_panel_present\"/",
			$src,
			'Panel template must emit _lafka_nutrition_panel_present hidden field.'
		);
	}

	public function test_process_meta_box_bails_when_marker_absent(): void {
		// Regression lock for the v9.7.13 fix. process_meta_box must early-
		// return when the marker isn't in $_POST so out-of-band saves don't
		// trigger the meta-wipe loop.
		$src = $this->admin_src();
		$this->assertMatchesRegularExpression(
			"/!\s*isset\(\s*\\\$_POST\['_lafka_nutrition_panel_present'\]\s*\)\s*\)\s*\{[^}]*return;/s",
			$src,
			'process_meta_box must early-return when panel marker is missing from $_POST.'
		);
	}

	public function test_process_meta_box_guard_runs_before_loop(): void {
		// Marker check must come BEFORE the meta-write loop inside
		// process_meta_box. The class iterates nutrition_meta_fields TWICE
		// — once in panel() to populate the editor, once in process_meta_box()
		// to save it — so we scope the assertion to the slice of source
		// inside process_meta_box().
		$src     = $this->admin_src();
		$fn_pos  = strpos( $src, 'public function process_meta_box' );
		$this->assertNotFalse( $fn_pos, 'process_meta_box function must exist' );
		$slice   = substr( $src, $fn_pos );

		$marker_pos = strpos( $slice, "isset( \$_POST['_lafka_nutrition_panel_present'] )" );
		$loop_pos   = strpos( $slice, '$nutrition_meta_fields as $field_name' );
		$this->assertNotFalse( $marker_pos, 'isset() guard must exist inside process_meta_box' );
		$this->assertNotFalse( $loop_pos, 'meta loop must exist inside process_meta_box' );
		$this->assertLessThan( $loop_pos, $marker_pos, 'Marker isset guard must come before the meta loop.' );
	}

	public function test_process_meta_box_handles_missing_product(): void {
		// wc_get_product() can return null/false for trashed/deleted products
		// — must early-return rather than calling ->update_meta_data() on null.
		$src = $this->admin_src();
		$this->assertMatchesRegularExpression(
			'/!\s*\$product\s*\)\s*\{[^}]*return;/s',
			$src,
			'process_meta_box must defend against null wc_get_product return.'
		);
	}

	// ────────────────────────────────────────────────────────────────────────
	// v9.7.14 — filter + numeric inputs
	// ────────────────────────────────────────────────────────────────────────

	public function test_meta_fields_map_is_filterable(): void {
		// US FDA DI defaults aren't right for EU/UK/Canada. The filter lets
		// non-US operators override without forking. Without it, this OSS
		// plugin imposes US dietary-target arithmetic on every market.
		$src = $this->config_src();
		$this->assertMatchesRegularExpression(
			"/apply_filters\(\s*\n?\s*'lafka_nutrition_meta_fields'/",
			$src,
			'Nutrition fields map must be filterable via lafka_nutrition_meta_fields.'
		);
	}

	public function test_filter_receives_full_default_map(): void {
		// The defaults must be passed AS-IS into the filter — operators that
		// hook need to see all 10 fields so they can override a subset rather
		// than rebuild the whole map.
		$src = $this->config_src();
		$this->assertStringContainsString(
			"apply_filters( 'lafka_nutrition_meta_fields', \$defaults )",
			$src
		);
	}

	public function test_panel_inputs_are_numeric_with_decimal_step(): void {
		// type=number prevents operators from typing letters ("65O" calories)
		// or other non-numeric junk that the frontend then divides by DI to
		// compute a meaningless percentage. step=0.01 + min=0 + inputmode=decimal
		// covers mobile UX and prevents negatives.
		$src = $this->panel_src();
		$this->assertMatchesRegularExpression(
			'/type="number"\s+min="0"\s+step="0\.01"\s+inputmode="decimal"/',
			$src,
			'Nutrition inputs must be type=number with min=0, step=0.01, inputmode=decimal.'
		);
	}

	public function test_panel_no_longer_uses_text_inputs_for_nutrition(): void {
		// Regression lock against re-introducing type=text on nutrition fields.
		// Allergens stays type=text (it's a comma-separated list); we only
		// want to assert the loop's nutrition inputs are numeric.
		$src = $this->panel_src();
		$this->assertDoesNotMatchRegularExpression(
			'/<input\s+type="text"\s+name="_<\?php echo esc_attr\(\s*\$nutrition_meta_field/',
			$src,
			'Nutrition inputs must not regress to type=text.'
		);
	}

	// ────────────────────────────────────────────────────────────────────────
	// Existing-shape locks (rendering, allergens, hook registration)
	// ────────────────────────────────────────────────────────────────────────

	public function test_allergens_field_remains_in_panel(): void {
		// Separate from the nutrition loop; comma-separated free text. Must
		// keep flowing through the marker-gated process_meta_box.
		$src = $this->panel_src();
		$this->assertStringContainsString( '_lafka_product_allergens', $src );
	}

	public function test_admin_hooks_woocommerce_product_data_panels(): void {
		$src = $this->admin_src();
		$this->assertMatchesRegularExpression(
			"/add_action\(\s*'woocommerce_product_data_panels'/",
			$src
		);
	}

	public function test_admin_hooks_process_product_meta(): void {
		$src = $this->admin_src();
		$this->assertMatchesRegularExpression(
			"/add_action\(\s*'woocommerce_process_product_meta'/",
			$src
		);
	}
}
