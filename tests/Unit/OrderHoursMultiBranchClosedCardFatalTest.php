<?php
declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Regression guard for the multi-branch closed-store TypeError fatal.
 *
 * get_next_opening_time_by_params() returns the literal `false` for a
 * force-closed branch or one whose schedule has no upcoming period. Those
 * values must not survive into format_next_open_time_human(), which would
 * otherwise fatal (TypeError) on the strict ?DateTime hint while rendering
 * the customer-facing closed-store card on shop/cart.
 */
final class OrderHoursMultiBranchClosedCardFatalTest extends TestCase {
	private string $src;

	protected function setUp(): void {
		parent::setUp();
		$this->src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/order-hours/Lafka_Order_Hours.php' );
	}

	private function method_slice( string $needle ): string {
		$pos = strpos( $this->src, $needle );
		$this->assertNotFalse( $pos, "method not found: {$needle}" );

		// Slice up to the start of the next class method (a tab-indented `public `
		// line) so the entire body is captured. A fixed-length window silently
		// truncated long methods like get_first_opening_branch_datetime, hiding
		// the array_filter()/return-null guards this test exists to lock.
		$next = strpos( $this->src, "\n\tpublic ", $pos + 1 );
		if ( false === $next ) {
			$next = strlen( $this->src );
		}

		return substr( $this->src, $pos, $next - $pos );
	}

	public function test_branch_resolver_filters_out_falsy_entries(): void {
		// array_filter() drops every false/null next-opening (including the
		// main store at index 0) so the sort below never mixes DateTime objects
		// with booleans.
		$slice = $this->method_slice( 'function get_first_opening_branch_datetime' );
		$this->assertMatchesRegularExpression(
			'/\$branches_open_times\s*=\s*array_filter\(\s*\$branches_open_times\s*\)/',
			$slice,
			'must array_filter() the collected branch open times before sorting'
		);
	}

	public function test_branch_resolver_no_longer_only_unsets_index_zero(): void {
		// The old index-0-only unset left branch `false` values in the array,
		// which array_pop() could then return straight into the renderer.
		$slice = $this->method_slice( 'function get_first_opening_branch_datetime' );
		$this->assertStringNotContainsString(
			'unset( $branches_open_times[0] )',
			$slice,
			'must not rely on the index-0-only unset; filter all falsy entries instead'
		);
	}

	public function test_branch_resolver_returns_null_when_no_openings(): void {
		$slice = $this->method_slice( 'function get_first_opening_branch_datetime' );
		$this->assertMatchesRegularExpression(
			'/if\s*\(\s*empty\(\s*\$branches_open_times\s*\)\s*\)\s*\{\s*return null;/',
			$slice,
			'must return null (not false) when no branch has an upcoming opening'
		);
	}

	public function test_formatter_param_is_not_strict_datetime_hint(): void {
		// A strict ?DateTime hint fatals on the legacy false; the param must be
		// untyped so the method can guard internally.
		$this->assertStringNotContainsString(
			'format_next_open_time_human( ?DateTime $datetime )',
			$this->src,
			'param must not carry a strict ?DateTime hint — it breaks on the legacy bool|DateTime contract'
		);
		$this->assertStringContainsString(
			'function format_next_open_time_human( $datetime ): string',
			$this->src,
			'formatter must accept an untyped $datetime and guard internally'
		);
	}

	public function test_formatter_guards_with_instanceof_datetime(): void {
		$slice = $this->method_slice( 'function format_next_open_time_human' );
		$this->assertMatchesRegularExpression(
			'/!\s*\$datetime\s+instanceof\s+DateTime/',
			$slice,
			'formatter must bail with `! $datetime instanceof DateTime` so false/null cannot reach getTimestamp()'
		);
		$this->assertStringContainsString( "return ''", $slice );
	}
}
