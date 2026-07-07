<?php
declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * f075 regression lock — MIGRATED from lafka-theme (NX1-08b) alongside the
 * business logic it guards.
 *
 * The new-order poller decides which branch operator to alert by reading the
 * PLUGIN-owned order meta `lafka_selected_branch_id`. Under WooCommerce
 * High-Performance Order Storage (HPOS) that meta lives in `wc_orders_meta`, NOT
 * `wp_postmeta`, so a raw get_post_meta() returns empty and every branch operator
 * is notified for every order (or none) — silent multi-branch misrouting.
 *
 * The fix (now in incl/admin/class-lafka-order-notifications.php):
 *   - Reads branch meta through Lafka_Order_Notifications::get_order_meta(), which
 *     prefers the plugin's canonical
 *     Lafka_Shipping_Areas::get_order_meta_backward_compatible() accessor and falls
 *     back to the WC_Order object (HPOS + legacy safe).
 *   - Gates the bulk update_meta_cache( 'post', ... ) priming behind the HPOS check
 *     (OrderUtil::custom_orders_table_usage_is_enabled()), because under HPOS the
 *     order meta is already loaded onto the WC_Order objects and the 'post' cache
 *     prime is unnecessary (and wrong for orders with no wp_posts row).
 *
 * These source-grep locks fail if either accessor regresses back to raw post-meta.
 *
 * @package Lafka\Plugin\Tests\Unit
 */
final class OrderNotificationHposMetaTest extends TestCase {

	private function source(): string {
		$path = dirname( __DIR__, 2 ) . '/incl/admin/class-lafka-order-notifications.php';
		$this->assertFileExists( $path );

		return (string) file_get_contents( $path );
	}

	/**
	 * Isolate the body of the core notifier method so assertions don't
	 * accidentally match unrelated calls elsewhere in the file.
	 */
	private function notifier_body(): string {
		$src   = $this->source();
		$start = strpos( $src, 'public static function compute_notification(' );
		$this->assertNotFalse( $start, 'compute_notification() must exist.' );

		// Slice up to the next method declaration that follows the notifier.
		$end = strpos( $src, 'private static function build_payload(', $start );
		$this->assertNotFalse( $end, 'Could not locate the method following the notifier.' );

		return substr( $src, $start, $end - $start );
	}

	public function test_hpos_aware_accessor_exists(): void {
		$src = $this->source();

		$this->assertStringContainsString(
			'private static function get_order_meta(',
			$src,
			'An HPOS-safe order-meta accessor must be defined.'
		);
	}

	public function test_helper_prefers_plugin_backward_compatible_accessor(): void {
		$src = $this->source();

		$this->assertStringContainsString(
			'Lafka_Shipping_Areas::get_order_meta_backward_compatible(',
			$src,
			'The HPOS-safe accessor must delegate to the plugin canonical reader when available.'
		);
	}

	public function test_branch_meta_no_longer_read_via_raw_post_meta(): void {
		$body = $this->notifier_body();

		$this->assertDoesNotMatchRegularExpression(
			'/get_post_meta\s*\([^;]*lafka_selected_branch_id/',
			$body,
			'Branch routing meta must not be read via raw get_post_meta() (breaks under HPOS).'
		);
	}

	public function test_branch_meta_read_via_hpos_aware_helper(): void {
		$body = $this->notifier_body();

		$this->assertGreaterThanOrEqual(
			1,
			substr_count( $body, "self::get_order_meta( \$order_id, 'lafka_selected_branch_id' )" ),
			'The notifier must read branch-routing meta through the HPOS-aware helper.'
		);
	}

	public function test_post_meta_cache_prime_is_gated_behind_hpos_check(): void {
		$body = $this->notifier_body();

		$guard_pos = strpos( $body, 'self::hpos_enabled()' );
		$prime_pos = strpos( $body, "update_meta_cache( 'post'" );

		$this->assertNotFalse( $guard_pos, 'The notifier must consult the HPOS check before priming the post-meta cache.' );
		$this->assertNotFalse( $prime_pos, 'The notifier still primes the post-meta cache for legacy CPT storage.' );
		$this->assertLessThan(
			$prime_pos,
			$guard_pos,
			"update_meta_cache( 'post', ... ) must be guarded by the HPOS check, not run unconditionally."
		);
	}

	public function test_hpos_check_uses_order_util(): void {
		$src = $this->source();

		$this->assertStringContainsString(
			'custom_orders_table_usage_is_enabled',
			$src,
			'The HPOS gate must consult OrderUtil::custom_orders_table_usage_is_enabled().'
		);
	}
}
