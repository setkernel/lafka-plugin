<?php
declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Regression lock for the time_slots_for_date AJAX date-input guard.
 *
 * Audit 2026-06-28 (f076): after check_ajax_referer, the handler read
 * $_POST['date'] with no isset() guard and fed
 * sanitize_text_field() -> DateTime::createFromFormat( 'Y-m-d', ... )
 * straight into get_timeslots_for_date( DateTime $date, ... ). A missing
 * or malformed date makes createFromFormat() return false, and passing
 * false to the typed parameter raised a TypeError 500. The nonce is
 * embedded in the page for every visitor, so any visitor could trigger it.
 *
 * The fix guards the input before the typed call: reject an empty date with
 * a 400, wp_unslash()+sanitize the raw value, then require both a real
 * DateTime instance AND a format roundtrip (Y-m-d) so lenient/partial
 * parses are rejected with a 400 — get_timeslots_for_date() is only reached
 * with a fully valid DateTime.
 */
final class TimeslotsAjaxDateGuardTest extends TestCase {

	private string $body;

	protected function setUp(): void {
		$src = file_get_contents(
			dirname( __DIR__, 2 ) . '/incl/timeslots/class-lafka-timeslots.php'
		);
		$this->body = $this->method_body( $src, 'retrieve_time_slots_for_date' );
		$this->assertNotSame( '', $this->body, 'retrieve_time_slots_for_date body not found.' );
	}

	public function test_empty_date_is_rejected_with_400(): void {
		$this->assertMatchesRegularExpression(
			"/if\s*\(\s*empty\(\s*\\\$_POST\['date'\]\s*\)\s*\)\s*\{\s*wp_send_json_error\([^;]*?,\s*400\s*\)/s",
			$this->body,
			'A missing date must short-circuit with wp_send_json_error( ..., 400 ) before the typed call.'
		);
	}

	public function test_raw_input_is_unslashed_and_sanitized(): void {
		$this->assertMatchesRegularExpression(
			"/sanitize_text_field\(\s*wp_unslash\(\s*\\\$_POST\['date'\]\s*\)\s*\)/",
			$this->body,
			'The raw date must be wp_unslash()ed before sanitize_text_field().'
		);
	}

	public function test_parsed_date_is_validated_before_typed_call(): void {
		$this->assertStringContainsString(
			'instanceof DateTime',
			$this->body,
			'The parsed value must be confirmed to be a real DateTime instance.'
		);
		$this->assertMatchesRegularExpression(
			"/\\\$date->format\(\s*'Y-m-d'\s*\)\s*!==\s*\\\$raw/",
			$this->body,
			'A format roundtrip (Y-m-d) must reject lenient/partial parses.'
		);
		$this->assertMatchesRegularExpression(
			"/(! \\\$date instanceof DateTime|\\\$date->format\([^)]*\)\s*!==\s*\\\$raw)[^;]*?\)\s*\{\s*wp_send_json_error\([^;]*?,\s*400\s*\)/s",
			$this->body,
			'An invalid date must be rejected with wp_send_json_error( ..., 400 ).'
		);
	}

	public function test_validation_precedes_get_timeslots_call(): void {
		$validate_pos = strpos( $this->body, 'instanceof DateTime' );
		$consume_pos  = strpos( $this->body, 'get_timeslots_for_date' );
		$this->assertNotFalse( $validate_pos, 'Validation guard missing.' );
		$this->assertNotFalse( $consume_pos, 'get_timeslots_for_date call missing.' );
		$this->assertLessThan(
			$consume_pos,
			$validate_pos,
			'The DateTime validation must run before get_timeslots_for_date() consumes the value.'
		);
	}

	/**
	 * Crude PHP-source method-body extractor: returns the text between a
	 * `function <name>` and the next method declaration (` function `) or
	 * EOF. Good enough for these structural regression assertions.
	 */
	private function method_body( string $src, string $name ): string {
		$start = strpos( $src, 'function ' . $name );
		if ( false === $start ) {
			return '';
		}
		$rest = substr( $src, $start + strlen( 'function ' . $name ) );
		$next = strpos( $rest, ' function ' );
		return false === $next ? $rest : substr( $rest, 0, $next );
	}
}
