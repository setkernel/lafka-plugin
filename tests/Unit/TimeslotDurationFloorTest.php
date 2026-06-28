<?php
declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use DateTime;
use DateTimeZone;
use Lafka_Shipping_Areas_Admin;
use Lafka_Timeslots;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Regression lock for the timeslot-duration floor (audit f048).
 *
 * retrieve_time_slots_for_date() (a wp_ajax_nopriv endpoint) passed the raw
 * operator-configured slot duration into get_timeslots_for_date() with no
 * minimum guard. Because register_setting('lafka_shipping_areas_datetime')
 * had no sanitize_callback, the field's HTML min/max was the only guard and a
 * crafted POST / programmatic update_option() could store '0' or ''. With a
 * 0-minute duration the while(1) loop never advanced (start + 0 min stayed
 * < end forever) → CPU/memory exhaustion; with '' the empty DateInterval
 * string fataled. Either way an anonymous visitor could hang a PHP worker.
 *
 * The fix floors the duration to >= 1 everywhere it is read/consumed and adds
 * a server-side sanitize_callback that clamps the stored value to 1..720.
 *
 * Functional assertions cover the two pure, dependency-free surfaces (the
 * AJAX-facing guard and the settings sanitizer); the remaining surfaces are
 * source-structure locks, matching the TimeslotsDatetimeValidationTest /
 * TimeslotCapacityEnforcementTest convention for this file.
 */
final class TimeslotDurationFloorTest extends TestCase {

	private string $src;

	private string $admin_src;

	public static function setUpBeforeClass(): void {
		if ( ! class_exists( 'Lafka_Timeslots', false ) ) {
			require_once dirname( __DIR__, 2 ) . '/incl/timeslots/class-lafka-timeslots.php';
		}
		if ( ! class_exists( 'Lafka_Shipping_Areas_Admin', false ) ) {
			require_once dirname( __DIR__, 2 ) . '/incl/shipping-areas/includes/class-lafka-shipping-areas-admin.php';
		}
	}

	protected function setUp(): void {
		$this->src       = file_get_contents( dirname( __DIR__, 2 ) . '/incl/timeslots/class-lafka-timeslots.php' );
		$this->admin_src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/shipping-areas/includes/class-lafka-shipping-areas-admin.php' );
	}

	/**
	 * Functional: a non-positive duration must short-circuit to an empty slot
	 * list BEFORE the while(1) loop, so the AJAX endpoint can never stall. The
	 * early-return fires ahead of any WordPress call, so no stubs are needed.
	 *
	 * @return array<string, array{0: mixed}>
	 */
	public static function nonPositiveDurationProvider(): array {
		return array(
			'zero int'       => array( 0 ),
			'zero string'    => array( '0' ),
			'empty string'   => array( '' ),
			'negative int'   => array( -15 ),
			'non-numeric'    => array( 'abc' ),
		);
	}

	#[DataProvider( 'nonPositiveDurationProvider' )]
	public function test_get_timeslots_for_date_returns_empty_on_bad_duration( $duration ): void {
		$date = new DateTime( '2026-07-01', new DateTimeZone( 'UTC' ) );

		$result = Lafka_Timeslots::get_timeslots_for_date( $date, $duration );

		$this->assertSame(
			array(),
			$result,
			'A non-positive slot duration must yield no slots (early return) so the while(1) loop never runs.'
		);
	}

	/**
	 * Functional: the settings sanitizer clamps the stored duration.
	 *
	 * @return array<string, array{0: array<string, mixed>, 1: int}>
	 */
	public static function durationClampProvider(): array {
		return array(
			'zero clamps to 1'        => array( array( 'timeslot_duration' => '0' ), 1 ),
			'negative clamps to 1'    => array( array( 'timeslot_duration' => '-30' ), 1 ),
			'empty falls back to 60'  => array( array( 'timeslot_duration' => '' ), 60 ),
			'missing falls back 60'   => array( array(), 60 ),
			'over max clamps to 720'  => array( array( 'timeslot_duration' => '5000' ), 720 ),
			'in range preserved'      => array( array( 'timeslot_duration' => '45' ), 45 ),
			'float floored'           => array( array( 'timeslot_duration' => '90.7' ), 90 ),
		);
	}

	#[DataProvider( 'durationClampProvider' )]
	public function test_sanitize_clamps_timeslot_duration( array $input, int $expected ): void {
		$out = Lafka_Shipping_Areas_Admin::sanitize_datetime_settings( $input );

		$this->assertSame(
			$expected,
			$out['timeslot_duration'],
			'sanitize_datetime_settings() must clamp timeslot_duration into 1..720.'
		);
		$this->assertGreaterThanOrEqual( 1, $out['timeslot_duration'], 'Stored duration must never fall below 1.' );
	}

	public function test_sanitize_floors_days_ahead_and_orders_per_timeslot(): void {
		$out = Lafka_Shipping_Areas_Admin::sanitize_datetime_settings(
			array(
				'days_ahead'          => '-5',
				'orders_per_timeslot' => '0',
			)
		);

		$this->assertSame( 0, $out['days_ahead'], 'days_ahead must floor at 0.' );
		$this->assertSame( 1, $out['orders_per_timeslot'], 'A set orders_per_timeslot must floor at 1.' );

		$capped = Lafka_Shipping_Areas_Admin::sanitize_datetime_settings(
			array(
				'days_ahead'          => '9999',
				'orders_per_timeslot' => '99999',
			)
		);
		$this->assertSame( 365, $capped['days_ahead'], 'days_ahead must cap at 365.' );
		$this->assertSame( 1000, $capped['orders_per_timeslot'], 'orders_per_timeslot must cap at 1000.' );
	}

	public function test_sanitize_treats_empty_orders_per_timeslot_as_no_cap(): void {
		$out = Lafka_Shipping_Areas_Admin::sanitize_datetime_settings( array( 'orders_per_timeslot' => '' ) );

		// An empty value means "no cap" — get_max_orders_per_slot() reads it as
		// falsy. It must stay falsy (not be forced to 1), preserving the
		// pre-sanitizer storage behaviour.
		$this->assertEmpty(
			$out['orders_per_timeslot'] ?? '',
			'An empty orders_per_timeslot means "no cap" and must not be forced to 1.'
		);
	}

	public function test_sanitize_preserves_checkboxes_and_unknown_keys(): void {
		$out = Lafka_Shipping_Areas_Admin::sanitize_datetime_settings(
			array(
				'enable_datetime_option' => '1',
				'datetime_mandatory'     => '1',
				'some_future_key'        => 'keepme',
			)
		);

		$this->assertSame( '1', $out['enable_datetime_option'], 'Enable checkbox must survive sanitization.' );
		$this->assertSame( '1', $out['datetime_mandatory'], 'Mandatory checkbox must survive sanitization.' );
		$this->assertSame( 'keepme', $out['some_future_key'], 'Unknown keys must not be dropped.' );
	}

	public function test_register_setting_has_sanitize_callback(): void {
		$this->assertMatchesRegularExpression(
			"/register_setting\(\s*['\"]lafka_shipping_areas_datetime['\"]\s*,\s*['\"]lafka_shipping_areas_datetime['\"]\s*,\s*array\(\s*'sanitize_callback'\s*=>\s*array\(\s*__CLASS__\s*,\s*'sanitize_datetime_settings'/s",
			$this->admin_src,
			'The datetime option must register a sanitize_callback so the HTML min/max is no longer the only guard.'
		);
	}

	public function test_init_floors_global_and_branch_duration(): void {
		$body = $this->method_body( $this->src, 'init_order_date_time_options' );
		$this->assertNotSame( '', $body, 'init_order_date_time_options body not found.' );

		$this->assertMatchesRegularExpression(
			"/order_date_time_timeslot_duration\s*=\s*max\(\s*1\s*,\s*\(int\)\s*\(\s*\\\$datetime_options\['timeslot_duration'\]\s*\?\?\s*60\s*\)\s*\)/",
			$body,
			'The global slot duration must be floored with max( 1, (int) ... ) at the source.'
		);
		$this->assertMatchesRegularExpression(
			"/order_date_time_timeslot_duration\s*=\s*max\(\s*1\s*,\s*\(int\)\s*get_term_meta\(/",
			$body,
			'The per-branch slot duration override must be floored with max( 1, (int) ... ) too.'
		);
	}

	public function test_accessor_floors_duration(): void {
		$body = $this->method_body( $this->src, 'get_timeslot_duration' );
		$this->assertMatchesRegularExpression(
			"/return\s+max\(\s*1\s*,\s*\(int\)\s*\\\$this->order_date_time_timeslot_duration\s*\)/",
			$body,
			'get_timeslot_duration() must never return below 1.'
		);
	}

	#[DataProvider( 'guardedMethodProvider' )]
	public function test_static_consumers_guard_duration( string $method ): void {
		$body = $this->method_body( $this->src, $method );
		$this->assertNotSame( '', $body, $method . ' body not found.' );

		$this->assertMatchesRegularExpression(
			"/\\\$timeslot_duration\s*=\s*\(int\)\s*\\\$timeslot_duration\s*;/",
			$body,
			$method . '() must cast $timeslot_duration to int before building a DateInterval.'
		);
		$this->assertMatchesRegularExpression(
			'/if\s*\(\s*\$timeslot_duration\s*<\s*1\s*\)/',
			$body,
			$method . '() must early-return on a sub-1 duration so the loop can never stall.'
		);
	}

	/**
	 * @return array<string, array{0: string}>
	 */
	public static function guardedMethodProvider(): array {
		return array(
			'get_timeslots_for_date'    => array( 'get_timeslots_for_date' ),
			'get_all_timeslots_static'  => array( 'get_all_timeslots_static' ),
			'has_future_slots_for_today' => array( 'has_future_slots_for_today' ),
		);
	}

	/**
	 * Crude PHP-source method-body extractor: returns the text between a
	 * `function <name>` and the next method declaration (` function `) or EOF.
	 * Mirrors the helper in the sibling timeslot regression tests.
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
