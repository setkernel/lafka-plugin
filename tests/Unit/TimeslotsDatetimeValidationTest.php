<?php
declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Regression lock for the mandatory delivery/pickup-time gate.
 *
 * Audit 2026-06-27: the mandatory-empty check ran on
 * `woocommerce_checkout_create_order`, which fires AFTER
 * WC_Checkout::validate_checkout() — so wc_add_notice( ..., 'error' )
 * there could not abort process_checkout(), and an order could be placed
 * with no delivery/pickup time even when the operator marked it mandatory.
 *
 * The fix splits the two concerns: the META WRITE stays on
 * woocommerce_checkout_create_order; the VALIDATION moves to
 * woocommerce_checkout_process (the hook where an error notice actually
 * blocks submission).
 */
final class TimeslotsDatetimeValidationTest extends TestCase {

	private string $src;

	protected function setUp(): void {
		$this->src = file_get_contents(
			dirname( __DIR__, 2 ) . '/incl/timeslots/class-lafka-timeslots.php'
		);
	}

	public function test_validation_registered_on_blocking_hook(): void {
		$this->assertMatchesRegularExpression(
			"/add_action\(\s*['\"]woocommerce_checkout_process['\"]\s*,\s*array\(\s*\\\$this\s*,\s*['\"]validate_datetime_fields['\"]/",
			$this->src,
			'Mandatory datetime must be validated on woocommerce_checkout_process so the error actually blocks checkout.'
		);
	}

	public function test_validation_method_exists(): void {
		$this->assertStringContainsString(
			'function validate_datetime_fields',
			$this->src,
			'A dedicated validation method must exist for the checkout_process gate.'
		);
	}

	public function test_validation_emits_blocking_error_notice(): void {
		// Isolate the validation method body and assert it raises an error notice.
		$body = $this->method_body( 'validate_datetime_fields' );
		$this->assertNotSame( '', $body, 'validate_datetime_fields body not found.' );
		$this->assertMatchesRegularExpression(
			"/wc_add_notice\(.*?,\s*['\"]error['\"]\s*\)/s",
			$body,
			'Validation must call wc_add_notice( ..., \'error\' ) to abort checkout.'
		);
		$this->assertStringContainsString(
			'order_date_time_mandatory',
			$body,
			'Validation must only fire when the datetime is mandatory.'
		);
	}

	public function test_meta_writer_no_longer_emits_dead_notice(): void {
		// The create_order handler must be a pure meta writer now — any
		// wc_add_notice there is dead code (cannot block) and is the bug.
		$body = $this->method_body( 'checkout_datetime_update_order_meta' );
		$this->assertNotSame( '', $body, 'checkout_datetime_update_order_meta body not found.' );
		$this->assertStringNotContainsString(
			'wc_add_notice',
			$body,
			'The create_order meta writer must not raise notices — they fire too late to block the order.'
		);
	}

	/**
	 * Crude PHP-source method-body extractor: returns the text between a
	 * `function <name>` and the next method declaration (` function `,
	 * which is preceded by a visibility/static keyword) or EOF. Good enough
	 * for these structural regression assertions.
	 */
	private function method_body( string $name ): string {
		$start = strpos( $this->src, 'function ' . $name );
		if ( false === $start ) {
			return '';
		}
		$rest = substr( $this->src, $start + strlen( 'function ' . $name ) );
		$next = strpos( $rest, ' function ' );
		return false === $next ? $rest : substr( $rest, 0, $next );
	}
}
