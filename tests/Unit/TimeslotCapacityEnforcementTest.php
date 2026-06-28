<?php
declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Regression lock for timeslot capacity / validity enforcement at submit.
 *
 * Audit 2026-06-27 (f004): validate_datetime_fields() only checked that the
 * date + timeslot POST fields were non-empty. It never re-derived the valid
 * server-side set, so a crafted/stale POST could book a past date, a closed
 * day, a non-existent slot, or a slot already at capacity — overbooking the
 * kitchen. Separately, get_number_of_orders_per_timeslot() counted only
 * `wc-processing`, so the moment the KDS moved an order to
 * accepted/preparing/ready (or it reached completed/on-hold/pending) the slot
 * silently reopened.
 *
 * The fix:
 *   • validate_datetime_fields() re-derives the enabled-date set and the
 *     rendered slot ids, rejects anything off that set, and re-runs the
 *     per-slot capacity count before the order is created.
 *   • get_number_of_orders_per_timeslot() counts every booked status and
 *     excludes only cancelled/refunded/failed/rejected.
 *
 * These are source-structure locks (the method leans on WC()/order-hours
 * statics/session that aren't bootstrapped in unit tests), matching the
 * existing TimeslotsDatetimeValidationTest convention for this file.
 */
final class TimeslotCapacityEnforcementTest extends TestCase {

	private string $src;

	protected function setUp(): void {
		$this->src = file_get_contents(
			dirname( __DIR__, 2 ) . '/incl/timeslots/class-lafka-timeslots.php'
		);
	}

	public function test_validation_rederives_enabled_dates(): void {
		$body = $this->method_body( 'validate_datetime_fields' );
		$this->assertNotSame( '', $body, 'validate_datetime_fields body not found.' );
		$this->assertStringContainsString(
			'get_enabled_dates_for_days_ahead',
			$body,
			'Validation must re-derive the server-side enabled-date set.'
		);
		$this->assertStringContainsString(
			'get_all_days_ahead_public',
			$body,
			'Validation must mirror the no-schedule fallback the date picker uses, or it would reject every date when order hours are unset.'
		);
		$this->assertStringContainsString(
			'in_array( $raw_date, $enabled_dates, true )',
			$body,
			'Validation must reject dates that are not in the enabled set.'
		);
	}

	public function test_validation_checks_past_date(): void {
		$body = $this->method_body( 'validate_datetime_fields' );
		$this->assertStringContainsString(
			'$raw_date < $today',
			$body,
			'Validation must explicitly reject past dates.'
		);
	}

	public function test_validation_matches_slot_against_rendered_ids(): void {
		$body = $this->method_body( 'validate_datetime_fields' );
		$this->assertStringContainsString(
			'get_timeslots_for_date',
			$body,
			'Validation must re-derive the rendered slot ids for the date.'
		);
		$this->assertMatchesRegularExpression(
			"/\\\$slot\['id'\]\s*===\s*\\\$raw_slot/",
			$body,
			'Validation must require the submitted slot to be one of the rendered {start} - {end} ids.'
		);
	}

	public function test_validation_enforces_capacity_at_submit(): void {
		$body = $this->method_body( 'validate_datetime_fields' );
		$this->assertStringContainsString(
			'get_number_of_orders_per_timeslot',
			$body,
			'Validation must re-run the per-slot order count at submit time.'
		);
		$this->assertStringContainsString(
			'get_max_orders_per_slot',
			$body,
			'Validation must compare the count against the per-slot cap.'
		);
		$this->assertMatchesRegularExpression(
			'/\$orders_made\s*>=\s*\(int\)\s*\$max_orders_per_slot/',
			$body,
			'Validation must reject when the slot is at or above capacity.'
		);
	}

	public function test_validation_emits_blocking_error_notices(): void {
		$body  = $this->method_body( 'validate_datetime_fields' );
		$count = preg_match_all( "/wc_add_notice\(.*?,\s*['\"]error['\"]\s*\)/s", $body );
		$this->assertGreaterThanOrEqual(
			4,
			$count,
			'Validation must raise blocking error notices for the presence, validity, and capacity failures.'
		);
	}

	public function test_capacity_count_includes_all_booked_statuses(): void {
		$body = $this->method_body( 'get_number_of_orders_per_timeslot' );
		$this->assertNotSame( '', $body, 'get_number_of_orders_per_timeslot body not found.' );

		foreach ( array( 'wc-pending', 'wc-on-hold', 'wc-processing', 'wc-accepted', 'wc-preparing', 'wc-ready', 'wc-completed' ) as $status ) {
			$this->assertStringContainsString(
				"'" . $status . "'",
				$body,
				sprintf( 'Booked status %s must be counted so the slot stays full across KDS transitions.', $status )
			);
		}
	}

	public function test_capacity_count_no_longer_filters_only_processing(): void {
		$body = $this->method_body( 'get_number_of_orders_per_timeslot' );
		$this->assertStringNotContainsString(
			"'status'     => 'wc-processing'",
			$body,
			'The count must not query wc-processing alone — accepted orders would free the slot and overbook.'
		);
		// Cancelled/refunded/failed/rejected genuinely free the slot and must
		// NOT be counted.
		foreach ( array( 'wc-cancelled', 'wc-refunded', 'wc-failed', 'wc-rejected' ) as $status ) {
			$this->assertStringNotContainsString(
				"'" . $status . "'",
				$body,
				sprintf( 'Status %s frees the slot and must be excluded from the count.', $status )
			);
		}
	}

	/**
	 * Crude PHP-source method-body extractor: returns the text between a
	 * `function <name>` and the next method declaration (` function `) or EOF.
	 * Good enough for these structural regression assertions; mirrors the
	 * helper in TimeslotsDatetimeValidationTest.
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
