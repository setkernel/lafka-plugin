<?php
/**
 * AbandonedCartDispatchRetryTest — regression for f025.
 *
 * Locks down that lafka_ac_dispatch_recovery_email() stamps a row
 * recovery_sent ONLY when the WC email trigger genuinely fired. When
 * WooCommerce or its mailer is unavailable the row must be left pending so the
 * next cron pass can retry — it must never be burned recovery_sent unsent
 * (which, because lafka_ac_get_pending excludes recovery_sent rows, would
 * permanently deny the customer their recovery email).
 *
 * The stamp path runs through the real lafka_ac_mark_recovery_sent(), which
 * issues a single $wpdb->update(). We install a recording fake $wpdb so the
 * presence/absence of that update() call is our proxy for "row was stamped".
 *
 * @package Lafka\Plugin\Tests\Unit
 * @since   9.28.0
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/incl/conversion/lafka-abandoned-cart-db.php';
require_once dirname( __DIR__, 2 ) . '/incl/conversion/lafka-abandoned-cart-cron.php';

final class AbandonedCartDispatchRetryTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();

		// Recording fake $wpdb — the real lafka_ac_mark_recovery_sent() calls
		// $wpdb->update(); capturing it tells us whether the row was stamped.
		$GLOBALS['wpdb'] = new class() {
			public string $prefix = 'wp_';
			/** @var array<int, array<string, mixed>> */
			public array $updates = array();
			public function update( $table, $data, $where, $format = null, $where_format = null ) {
				$this->updates[] = array(
					'table' => $table,
					'data'  => $data,
					'where' => $where,
				);
				return 1;
			}
		};

		Functions\when( 'current_time' )->justReturn( '2026-06-28 12:00:00' );
		Functions\when( 'do_action' )->justReturn( null );
	}

	protected function tearDown(): void {
		unset( $GLOBALS['wpdb'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	private function pending_row(): \stdClass {
		return (object) array(
			'id'             => 42,
			'customer_email' => 'alice@example.com',
			'resume_token'   => 'TOKEN1234567890',
			'order_id'       => 0,
		);
	}

	/** @return array<int, array<string, mixed>> */
	private function recorded_updates(): array {
		return $GLOBALS['wpdb']->updates;
	}

	// ─────────────────────────────────────────────────────────────────────────
	// WooCommerce / mailer unavailable → row must stay pending (not stamped).
	// ─────────────────────────────────────────────────────────────────────────

	public function test_does_not_mark_recovery_sent_when_wc_returns_no_instance(): void {
		// WooCommerce down: WC() yields no object, so there is no mailer to fire.
		Functions\when( 'WC' )->justReturn( null );

		\lafka_ac_dispatch_recovery_email( $this->pending_row() );

		$this->assertSame(
			array(),
			$this->recorded_updates(),
			'A row that was never attempted (no WC instance) must stay pending, not be stamped recovery_sent.'
		);
	}

	public function test_does_not_mark_recovery_sent_when_mailer_unavailable(): void {
		// WC() exists but its mailer() is unavailable (e.g. emails not booted).
		$wc = new class() {
			public function mailer() {
				return null;
			}
		};
		Functions\when( 'WC' )->justReturn( $wc );

		\lafka_ac_dispatch_recovery_email( $this->pending_row() );

		$this->assertSame(
			array(),
			$this->recorded_updates(),
			'No mailer means the trigger never fired; the row must stay pending for retry.'
		);
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Email genuinely dispatched → row IS stamped recovery_sent.
	// ─────────────────────────────────────────────────────────────────────────

	public function test_marks_recovery_sent_after_trigger_fires(): void {
		$mailer = new class() {
			public array $emails = array();
		};
		$wc = new class( $mailer ) {
			private $mailer;
			public function __construct( $mailer ) {
				$this->mailer = $mailer;
			}
			public function mailer() {
				return $this->mailer;
			}
		};
		Functions\when( 'WC' )->justReturn( $wc );

		$fired = array();
		Functions\when( 'do_action' )->alias(
			static function ( $hook, $row = null ) use ( &$fired ) {
				$fired[] = $hook;
			}
		);

		\lafka_ac_dispatch_recovery_email( $this->pending_row() );

		$this->assertContains(
			'lafka_abandoned_cart_email_trigger',
			$fired,
			'The recovery email trigger must fire when WC + mailer are available.'
		);

		$updates = $this->recorded_updates();
		$this->assertCount( 1, $updates, 'A genuinely dispatched email must stamp the row exactly once.' );
		$this->assertSame( array( 'id' => 42 ), $updates[0]['where'] );
		$this->assertArrayHasKey( 'recovery_sent_at', $updates[0]['data'] );
		$this->assertSame( '2026-06-28 12:00:00', $updates[0]['data']['recovery_sent_at'] );
	}

	public function test_invalid_row_is_never_stamped(): void {
		$mailer = new class() {};
		$wc     = new class( $mailer ) {
			private $mailer;
			public function __construct( $mailer ) {
				$this->mailer = $mailer;
			}
			public function mailer() {
				return $this->mailer;
			}
		};
		Functions\when( 'WC' )->justReturn( $wc );

		\lafka_ac_dispatch_recovery_email( (object) array( 'id' => 0 ) );

		$this->assertSame( array(), $this->recorded_updates(), 'A row without a valid id must never be stamped.' );
	}

	// ─────────────────────────────────────────────────────────────────────────
	// Source-grep: the cron loop short-circuits without WooCommerce active.
	// ─────────────────────────────────────────────────────────────────────────

	public function test_run_check_guards_on_woocommerce_active(): void {
		$src = file_get_contents( dirname( __DIR__, 2 ) . '/incl/conversion/lafka-abandoned-cart-cron.php' );
		$this->assertStringContainsString( "class_exists( 'WooCommerce' )", $src );
	}
}
