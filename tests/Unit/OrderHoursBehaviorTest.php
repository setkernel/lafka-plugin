<?php
/**
 * Behavioural coverage for Lafka_Order_Hours: the store clock, the server-side
 * ordering gates, the next-opening resolver and the closed-store card.
 *
 * @package Lafka_Plugin
 */

declare(strict_types=1);

namespace Automattic\WooCommerce\StoreApi\Exceptions {
	// Store API error type the gates throw; WooCommerce is not booted here.
	if ( ! class_exists( RouteException::class ) ) {
		class RouteException extends \RuntimeException { // phpcs:ignore
			public string $error_code;
			public function __construct( $error_code, $message, $http_status_code = 400 ) {
				$this->error_code = (string) $error_code;
				parent::__construct( (string) $message, (int) $http_status_code );
			}
			public function getErrorCode(): string {
				return $this->error_code;
			}
		}
	}
}

namespace LafkaPlugin\Tests\Unit {

	use Automattic\WooCommerce\StoreApi\Exceptions\RouteException;
	use Brain\Monkey;
	use Brain\Monkey\Functions;
	use DateTime;
	use DateTimeZone;
	use Lafka_Order_Hours;
	use PHPUnit\Framework\TestCase;
	use ReflectionClass;

	final class OrderHoursBehaviorTest extends TestCase {

		private const DEFAULT_CLOSED = 'Sorry, the store is currently closed and is not accepting orders.';

		/** @var list<array{0: string, 1: string}> Captured wc_add_notice() calls. */
		private array $notices = array();

		/** @var array<int, array<string, string>> Branch term meta. */
		private array $term_meta = array();

		protected function setUp(): void {
			parent::setUp();
			Monkey\setUp();
			Functions\when( 'get_option' )->justReturn( array() );
			Functions\when( '__' )->returnArg();
			Functions\when( '_x' )->returnArg();
			Functions\when( 'esc_html' )->returnArg();
			Functions\when( 'esc_attr' )->returnArg();
			Functions\when( 'esc_html__' )->returnArg();
			Functions\when( 'apply_filters' )->returnArg( 2 );
			Functions\when( 'wp_timezone' )->justReturn( new DateTimeZone( 'UTC' ) );
			Functions\when( 'WC' )->justReturn( (object) array( 'session' => null ) );
			Functions\when( 'get_terms' )->justReturn( array() );
			Functions\when( 'get_term_meta' )->alias( fn( $id, $key ) => $this->term_meta[ $id ][ $key ] ?? '' );
			Functions\when( 'wc_add_notice' )->alias(
				function ( $message, $type ) {
					$this->notices[] = array( $message, $type );
				}
			);
			require_once dirname( __DIR__, 2 ) . '/incl/order-hours/Lafka_Order_Hours.php';
			Lafka_Order_Hours::$lafka_order_hours_options               = array();
			Lafka_Order_Hours::$timezone                                = '';
			Lafka_Order_Hours::$lafka_order_hours_schedule              = '';
			Lafka_Order_Hours::$lafka_order_hours_force_override_check  = false;
			Lafka_Order_Hours::$lafka_order_hours_force_override_status = '';
			Lafka_Order_Hours::$lafka_order_hours_holidays_calendar     = '';
		}

		protected function tearDown(): void {
			Lafka_Order_Hours::$lafka_order_hours_options               = null;
			Lafka_Order_Hours::$lafka_order_hours_schedule              = null;
			Lafka_Order_Hours::$lafka_order_hours_force_override_check  = false;
			Lafka_Order_Hours::$lafka_order_hours_force_override_status = '';
			Monkey\tearDown();
			parent::tearDown();
		}

		/** Force the main store open or closed, independent of the clock. */
		private function store_is( bool $open, bool $disable_add_to_cart = false ): Lafka_Order_Hours {
			Lafka_Order_Hours::$lafka_order_hours_force_override_check  = true;
			Lafka_Order_Hours::$lafka_order_hours_force_override_status = $open ? '1' : '';
			Lafka_Order_Hours::$lafka_order_hours_options               = $disable_add_to_cart
				? array( 'lafka_order_hours_disable_add_to_cart' => '1' )
				: array();

			return ( new ReflectionClass( Lafka_Order_Hours::class ) )->newInstanceWithoutConstructor();
		}

		/**
		 * Every day opens (and immediately closes) at $time, so the store is never
		 * open "now" and the next opening is always a well-defined $time.
		 */
		private static function schedule_opening_at( string $time ): string {
			return (string) json_encode(
				array_fill(
					0,
					7,
					array(
						'periods' => array(
							array(
								'start' => $time,
								'end'   => $time,
							),
						),
					)
				)
			);
		}

		public function test_unset_or_invalid_branch_timezones_fall_back_to_the_site_timezone(): void {
			$site = new DateTimeZone( 'Europe/Paris' );
			Functions\when( 'wp_timezone' )->justReturn( $site );

			foreach ( array( '', 'default', 'Not/AZone' ) as $stored ) {
				$this->assertSame( 'Europe/Paris', Lafka_Order_Hours::resolve_timezone( $stored )->getName(), "Stored: '{$stored}'" );
			}
			$this->assertSame( 'Asia/Tokyo', Lafka_Order_Hours::resolve_timezone( 'Asia/Tokyo' )->getName() );
		}

		public function test_next_opening_is_computed_on_the_store_clock(): void {
			// Every day "opens" (a zero-length period, so the store is never open)
			// at the store-clock time two hours from now; the next opening must be
			// exactly that instant — on the store's clock, not UTC's.
			$tz    = new DateTimeZone( 'Pacific/Kiritimati' ); // UTC+14.
			$at    = ( new DateTime( 'now', $tz ) )->modify( '+2 hours' );
			$at->setTime( (int) $at->format( 'H' ), (int) $at->format( 'i' ) );
			$slot  = $at->format( 'H:i' );
			$day   = array(
				'periods' => array(
					array(
						'start' => $slot,
						'end'   => $slot,
					),
				),
			);
			$schedule = (string) json_encode( array_fill( 0, 7, $day ) );
			Functions\when( 'wp_timezone' )->justReturn( $tz );
			Lafka_Order_Hours::$lafka_order_hours_schedule = $schedule;

			$next = Lafka_Order_Hours::get_next_opening_time_by_params( null, $schedule, null, null, null );

			$this->assertInstanceOf( DateTime::class, $next );
			$this->assertSame( $at->getTimestamp(), $next->getTimestamp() );
		}

		/* -------------------------------------------------------------- *
		 *  Server-side ordering gates
		 * -------------------------------------------------------------- */

		public function test_classic_checkout_is_blocked_whenever_the_store_is_closed(): void {
			$this->store_is( true )->gate_checkout_when_closed();
			$this->assertSame( array(), $this->notices, 'Open store: no notice.' );

			// Checkout is gated even when the operator did NOT opt into blocking add-to-cart.
			$this->store_is( false )->gate_checkout_when_closed();
			$this->assertSame( array( array( self::DEFAULT_CLOSED, 'error' ) ), $this->notices );
		}

		public function test_closed_notice_prefers_the_operator_message(): void {
			$this->store_is( false );
			Lafka_Order_Hours::$lafka_order_hours_options = array( 'lafka_order_hours_message' => 'Back at noon.' );

			$this->assertSame( 'Back at noon.', Lafka_Order_Hours::get_closed_notice_message() );
		}

		public function test_classic_add_to_cart_is_blocked_only_when_closed_and_opted_in(): void {
			$this->assertTrue( $this->store_is( true, true )->gate_add_to_cart_when_closed( true ), 'Open store.' );
			$this->assertTrue( $this->store_is( false, false )->gate_add_to_cart_when_closed( true ), 'Closed, not opted in: cart may still be built.' );
			$this->assertFalse( $this->store_is( false, true )->gate_add_to_cart_when_closed( false ), 'An earlier rejection is kept.' );
			$this->assertSame( array(), $this->notices );

			$this->assertFalse( $this->store_is( false, true )->gate_add_to_cart_when_closed( true ), 'Closed and opted in.' );
			$this->assertSame( array( array( self::DEFAULT_CLOSED, 'error' ) ), $this->notices );
		}

		public function test_store_api_add_to_cart_throws_only_when_closed_and_opted_in(): void {
			$this->store_is( true, true )->gate_store_api_add_to_cart_when_closed();
			$this->store_is( false, false )->gate_store_api_add_to_cart_when_closed();

			try {
				$this->store_is( false, true )->gate_store_api_add_to_cart_when_closed();
				$this->fail( 'A closed, opted-in store must reject the Store API add-to-cart.' );
			} catch ( RouteException $e ) {
				$this->assertSame( 'lafka_store_closed', $e->getErrorCode() );
				$this->assertSame( 409, $e->getCode() );
				$this->assertSame( self::DEFAULT_CLOSED, $e->getMessage() );
			}
		}

		/* -------------------------------------------------------------- *
		 *  Next opening across branches + formatting
		 * -------------------------------------------------------------- */

		public function test_first_opening_is_null_when_no_branch_has_an_upcoming_opening(): void {
			// Branch 3 is force-closed (resolver returns false); the main store has
			// no schedule (open, so also no "next opening").
			$this->term_meta[3] = array(
				'lafka_branch_override_order_hours_global'      => '1',
				'lafka_branch_timezone'                         => 'default',
				'lafka_branch_order_hours_force_override_check' => '1',
			);

			$this->assertNull( Lafka_Order_Hours::get_first_opening_branch_datetime( array( 3 => 'Closed branch' ) ) );
		}

		public function test_first_opening_is_the_earliest_real_opening(): void {
			$branch = static fn( string $opens ) => array(
				'lafka_branch_override_order_hours_global' => '1',
				'lafka_branch_timezone'                    => 'default',
				'lafka_branch_order_hours_schedule'        => self::schedule_opening_at( $opens ),
			);
			$this->term_meta = array(
				3 => array(
					'lafka_branch_override_order_hours_global'      => '1',
					'lafka_branch_timezone'                         => 'default',
					'lafka_branch_order_hours_force_override_check' => '1',
				),
				4 => $branch( '09:00' ),
				5 => $branch( '10:00' ),
			);
			$utc      = new DateTimeZone( 'UTC' );
			$earliest = min(
				Lafka_Order_Hours::get_next_opening_time_by_params( $utc, self::schedule_opening_at( '09:00' ), '', '', '' ),
				Lafka_Order_Hours::get_next_opening_time_by_params( $utc, self::schedule_opening_at( '10:00' ), '', '', '' )
			);

			$first = Lafka_Order_Hours::get_first_opening_branch_datetime(
				array(
					3 => 'Closed',
					4 => 'Nine',
					5 => 'Ten',
				)
			);

			$this->assertInstanceOf( DateTime::class, $first );
			$this->assertSame( $earliest->getTimestamp(), $first->getTimestamp() );
		}

		public function test_next_open_text_is_empty_without_an_opening(): void {
			$this->assertSame( '', Lafka_Order_Hours::format_next_open_time_human( null ) );
			$this->assertSame( '', Lafka_Order_Hours::format_next_open_time_human( false ), 'Legacy false from the resolvers must not fatal.' );
		}

		public function test_next_open_text_uses_the_openings_own_timezone_and_the_public_format_filter(): void {
			Functions\when( 'wp_date' )->alias(
				static fn( $format, $timestamp, $tz ) => $format . '|' . $timestamp . '|' . $tz->getName()
			);
			$opening = new DateTime( '2031-01-18 11:00', new DateTimeZone( 'Pacific/Kiritimati' ) );

			$this->assertSame(
				'l \a\t g:i A|' . $opening->getTimestamp() . '|Pacific/Kiritimati',
				Lafka_Order_Hours::format_next_open_time_human( $opening )
			);

			Functions\when( 'apply_filters' )->alias(
				static fn( $hook, $value ) => 'lafka_next_open_time_format' === $hook ? 'H:i' : $value
			);
			$this->assertStringStartsWith( 'H:i|', Lafka_Order_Hours::format_next_open_time_human( $opening ) );
		}

		/* -------------------------------------------------------------- *
		 *  Closed-store card (called statically by theme templates)
		 * -------------------------------------------------------------- */

		private function render_card(): string {
			ob_start();
			Lafka_Order_Hours::echo_closed_store_message();
			return (string) ob_get_clean();
		}

		public function test_closed_card_renders_a_default_title_without_an_operator_message(): void {
			$html = $this->render_card();

			$this->assertStringContainsString( '<div class="lafka-store-closed-card">', $html );
			$this->assertStringContainsString( '<p class="lafka-store-closed-card__title">Closed right now</p>', $html );
			$this->assertStringNotContainsString( 'lafka-store-closed-card__subtitle', $html, 'No known opening, no subtitle.' );
		}

		public function test_closed_card_shows_the_operator_message_next_opening_and_countdown(): void {
			$session = new class() {
				public function get( $key ) {
					return 'lafka_branch_location' === $key ? array( 'branch_id' => 3 ) : null;
				}
			};
			Functions\when( 'WC' )->justReturn( (object) array( 'session' => $session ) );
			Functions\when( 'wp_date' )->justReturn( 'Saturday at 9:00 AM' );
			$this->store_is( false );
			Lafka_Order_Hours::$lafka_order_hours_schedule = self::schedule_opening_at( '09:00' );
			Lafka_Order_Hours::$lafka_order_hours_options  = array(
				'lafka_order_hours_message'           => 'Kitchen closed.',
				'lafka_order_hours_message_countdown' => '1',
			);

			$html = $this->render_card();

			$this->assertStringContainsString( '<p class="lafka-store-closed-card__title">Kitchen closed.</p>', $html );
			$this->assertMatchesRegularExpression( '#lafka-store-closed-card__subtitle">\s*Opens Saturday at 9:00 AM\s*</p>#', $html );
			$this->assertStringContainsString( 'class="lafka_order_hours_countdown"', $html );
		}
	}
}
