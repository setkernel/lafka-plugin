<?php
/**
 * GX1 / A1 + A3 + A5: the Lafka_Log facade — threshold, channel → WC source,
 * scrubbing, request-id envelope + header + order stamp, the `lafka_log`
 * bridge, never-throws, and the guard() wrapper used at REST/cron entry points.
 *
 * @package Lafka_Plugin
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Actions;
use Brain\Monkey\Functions;
use Lafka_Log;
use LafkaPlugin\Tests\Unit\Support\Hooks;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/incl/observability/class-lafka-log-scrubber.php';
require_once dirname( __DIR__, 2 ) . '/incl/observability/class-lafka-log.php';
require_once __DIR__ . '/Stubs/wp-error-class.php';
require_once __DIR__ . '/Support/Hooks.php';

/**
 * Captures what the facade hands to WooCommerce's logger.
 */
final class RecordingLogger {
	/** @var array<int,array{level:string,message:string,context:array}> */
	public array $records = array();
	public bool $explode  = false;

	public function log( $level, $message, $context = array() ): void {
		if ( $this->explode ) {
			throw new \RuntimeException( 'disk full' );
		}
		$this->records[] = array(
			'level'   => $level,
			'message' => $message,
			'context' => $context,
		);
	}
}

final class LogFacadeTest extends TestCase {

	private RecordingLogger $logger;

	/** @var array<string,mixed> */
	private array $options = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Lafka_Log::reset();
		$this->logger  = new RecordingLogger();
		$this->options = array();
		Lafka_Log::set_logger( $this->logger );
		Functions\when( 'get_option' )->alias( fn( $key, $default = false ) => $this->options[ $key ] ?? $default );
		Functions\when( '__' )->returnArg();
	}

	protected function tearDown(): void {
		Lafka_Log::reset();
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_default_threshold_keeps_warnings_and_drops_info(): void {
		self::assertFalse( Lafka_Log::info( 'checkout', 'fine' ) );
		self::assertTrue( Lafka_Log::warning( 'checkout', 'not fine' ) );

		self::assertCount( 1, $this->logger->records );
		self::assertSame( 'warning', $this->logger->records[0]['level'] );
	}

	public function test_min_level_option_and_per_channel_filter_override_the_threshold(): void {
		$this->options['lafka_log_settings'] = array( 'min_level' => 'debug' );
		self::assertTrue( Lafka_Log::debug( 'core', 'verbose' ) );

		Lafka_Log::reset();
		Lafka_Log::set_logger( $this->logger );
		Functions\when( 'apply_filters' )->alias(
			static fn( $hook, $value, ...$args ) => ( 'lafka_log_min_level' === $hook && 'kds' === ( $args[0] ?? '' ) ) ? 'error' : $value
		);
		self::assertFalse( Lafka_Log::warning( 'kds', 'quiet channel' ) );
		self::assertTrue( Lafka_Log::warning( 'core', 'loud channel' ) );
	}

	public function test_channel_becomes_the_wc_source_and_envelope_is_added(): void {
		Lafka_Log::error( 'store-api', 'Checkout rejected', array( 'code' => 'lafka_store_closed' ) );

		$context = $this->logger->records[0]['context'];
		self::assertSame( 'lafka-store-api', $context['source'] );
		self::assertSame( 'store-api', $context['channel'] );
		self::assertSame( 'lafka_store_closed', $context['code'] );
		self::assertMatchesRegularExpression( '/^[a-f0-9]{16}$/', $context['request_id'] );
		self::assertArrayHasKey( 'url_path', $context );
		self::assertArrayHasKey( 'lafka_version', $context );
	}

	public function test_unknown_channel_is_sanitized_and_levels_normalized(): void {
		Lafka_Log::log( 'LOUD', 'Order Hours!', 'x' );

		self::assertSame( 'notice', Lafka_Log::normalize_level( 'LOUD' ) );
		self::assertSame( 'order-hours', Lafka_Log::normalize_channel( 'Order Hours!' ) );
		self::assertSame( 'core', Lafka_Log::normalize_channel( '' ) );
	}

	public function test_message_and_context_are_scrubbed(): void {
		Lafka_Log::error(
			'payment',
			'Declined for jane@example.com',
			array(
				'billing_email' => 'jane@example.com',
				'order_id'      => 12,
			)
		);

		$record = $this->logger->records[0];
		self::assertStringNotContainsString( 'jane@example.com', $record['message'] );
		self::assertSame( '[redacted]', $record['context']['billing_email'] );
		self::assertSame( 12, $record['context']['order_id'] );
	}

	public function test_request_id_is_stable_within_a_request(): void {
		$first = Lafka_Log::request_id();

		self::assertSame( $first, Lafka_Log::request_id() );
		Lafka_Log::warning( 'core', 'a' );
		Lafka_Log::warning( 'core', 'b' );
		self::assertSame( $first, $this->logger->records[0]['context']['request_id'] );
		self::assertSame( $first, $this->logger->records[1]['context']['request_id'] );
	}

	public function test_a_broken_logger_never_throws(): void {
		$this->logger->explode = true;

		self::assertFalse( Lafka_Log::critical( 'core', 'boom' ) );
	}

	public function test_warning_and_above_fire_the_forwarding_action(): void {
		Actions\expectDone( 'lafka_log_record' )->once();

		Lafka_Log::error( 'core', 'forward me' );
		$this->options['lafka_log_settings'] = array( 'min_level' => 'debug' );
		Lafka_Log::reset();
		Lafka_Log::set_logger( $this->logger );
		Lafka_Log::info( 'core', 'not forwarded' );

		// Both were written to the WC log; only the error was forwarded.
		self::assertCount( 2, $this->logger->records );
	}

	public function test_bridge_action_routes_theme_records_to_the_facade(): void {
		Lafka_Log::on_bridge_log( 'warning', 'theme', 'Updater: GitHub returned HTTP 500', array() );
		Lafka_Log::on_bridge_log( 'error', '', 'no channel given' );

		self::assertSame( 'lafka-theme', $this->logger->records[0]['context']['source'] );
		self::assertSame( 'lafka-theme', $this->logger->records[1]['context']['source'] );
	}

	public function test_bridge_listener_is_registered_on_the_lafka_log_action(): void {
		Hooks::reset();
		Lafka_Log::register_hooks();

		self::assertContains( 'lafka_log -> on_bridge_log', Hooks::registered() );
		self::assertContains( 'woocommerce_checkout_create_order -> stamp_order', Hooks::registered() );
		self::assertContains( 'woocommerce_store_api_checkout_update_order_from_request -> stamp_order', Hooks::registered() );
	}

	public function test_request_id_header_on_lafka_and_store_api_responses_only(): void {
		$response = new class() {
			/** @var array<string,string> */
			public array $headers = array();
			public function header( $name, $value ): void {
				$this->headers[ $name ] = $value;
			}
		};
		$request  = static fn( string $route ) => new class( $route ) {
			public function __construct( private string $route ) {}
			public function get_route(): string {
				return $this->route;
			}
		};

		Lafka_Log::add_rest_header( $response, null, $request( '/wp/v2/posts' ) );
		self::assertSame( array(), $response->headers );

		Lafka_Log::add_rest_header( $response, null, $request( '/wc/store/v1/checkout' ) );
		self::assertSame( Lafka_Log::request_id(), $response->headers['X-Lafka-Request-Id'] );

		$response->headers = array();
		Lafka_Log::add_rest_header( $response, null, $request( '/lafka/v1/push/subscribe' ) );
		self::assertSame( Lafka_Log::request_id(), $response->headers['X-Lafka-Request-Id'] );
	}

	public function test_orders_created_at_checkout_are_stamped_with_the_request_id(): void {
		$order = new class() {
			/** @var array<string,mixed> */
			public array $meta = array();
			public function update_meta_data( $key, $value ): void {
				$this->meta[ $key ] = $value;
			}
		};

		Lafka_Log::stamp_order( $order );

		self::assertSame( Lafka_Log::request_id(), $order->meta['_lafka_request_id'] );
	}

	public function test_guard_rethrows_by_default_after_logging(): void {
		$guarded = Lafka_Log::guard(
			static function () {
				throw new \LogicException( 'bad state' );
			},
			'cron'
		);

		try {
			$guarded();
			self::fail( 'Expected the exception to be rethrown.' );
		} catch ( \LogicException $e ) {
			self::assertSame( 'bad state', $e->getMessage() );
		}
		self::assertSame( 'lafka-cron', $this->logger->records[0]['context']['source'] );
		self::assertSame( 'uncaught_exception', $this->logger->records[0]['context']['code'] );
	}

	public function test_guard_swallow_and_wp_error_policies(): void {
		$throws = static function () {
			throw new \RuntimeException( 'nope' );
		};

		self::assertNull( Lafka_Log::guard( $throws, 'cron', 'swallow' )() );
		$error = Lafka_Log::guard( $throws, 'rest', 'wp_error' )();
		self::assertInstanceOf( \WP_Error::class, $error );
		self::assertSame( 'lafka_internal_error', $error->get_error_code() );
	}

	public function test_guard_passes_arguments_and_return_values_through(): void {
		$sum = Lafka_Log::guard( static fn( $a, $b ) => $a + $b, 'cron' );

		self::assertSame( 5, $sum( 2, 3 ) );
		self::assertSame( array(), $this->logger->records );
	}

	public function test_rest_dispatch_guard_only_wraps_lafka_routes(): void {
		$handler = array(
			'callback' => static function () {
				throw new \RuntimeException( 'db gone' );
			},
		);

		self::assertNull( Lafka_Log::guard_rest_dispatch( null, null, '/wp/v2/posts', $handler ) );

		$result = Lafka_Log::guard_rest_dispatch( null, null, '/lafka/v1/push/subscribe', $handler );
		self::assertInstanceOf( \WP_Error::class, $result );
		self::assertSame( 'lafka-rest', $this->logger->records[0]['context']['source'] );

		$ok = array( 'callback' => static fn( $request ) => array( 'ok' => true ) );
		self::assertSame( array( 'ok' => true ), Lafka_Log::guard_rest_dispatch( null, null, '/wc-lafka/v1/groups', $ok ) );
		self::assertSame( 'already', Lafka_Log::guard_rest_dispatch( 'already', null, '/lafka/v1/x', $ok ) );
	}
}
