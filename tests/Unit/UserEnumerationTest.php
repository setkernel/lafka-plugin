<?php
/**
 * T-27 (GX): user enumeration is closed for anonymous visitors by default
 * (independent of the opt-in security-headers module):
 *
 *   - `/?author=N` answers 404 instead of redirecting to /author/<login>/
 *     (filterable to a redirect home);
 *   - `/wp-json/wp/v2/users` (list + single) answers 401 to anonymous
 *     requests while logged-in users — the block editor — keep full access;
 *   - `lafka_restrict_user_enumeration` turns both off.
 *
 * @package Lafka\Plugin\Tests\Unit
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Stubs/wp-error-class.php';

final class UserEnumerationTest extends TestCase {

	/** @var list<array{0:string, 1:mixed, 2:int, 3:int}>|null */
	private static ?array $registrations = null;

	/** @var array<string, mixed> */
	private array $filters = array();

	private bool $logged_in = false;

	private ?int $status = null;

	private ?string $redirect = null;

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->filters   = array();
		$this->logged_in = false;
		$this->status    = null;
		$this->redirect  = null;
		unset( $_GET['author'] );

		Functions\when( 'apply_filters' )->alias(
			fn( $hook, $value ) => array_key_exists( $hook, $this->filters ) ? $this->filters[ $hook ] : $value
		);
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'is_user_logged_in' )->alias( fn() => $this->logged_in );
		Functions\when( 'status_header' )->alias( fn( $code ) => $this->status = (int) $code );
		Functions\when( 'nocache_headers' )->justReturn( null );
		Functions\when( 'home_url' )->alias( static fn( $path = '' ) => 'https://example.test' . $path );
		Functions\when( '__' )->returnArg();

		if ( null === self::$registrations ) {
			$before = count( $GLOBALS['lafka_test_hooks'] );
			require_once dirname( __DIR__, 2 ) . '/incl/security/lafka-user-enumeration.php';
			self::$registrations = array_slice( $GLOBALS['lafka_test_hooks'], $before );
		}

		$GLOBALS['wp_query'] = new class() {
			public bool $is_404 = false;
			public function set_404(): void {
				$this->is_404 = true;
			}
		};
	}

	protected function tearDown(): void {
		unset( $_GET['author'], $GLOBALS['wp_query'] );
		Monkey\tearDown();
		parent::tearDown();
	}

	private static function request( string $route ): object {
		return new class( $route ) {
			public function __construct( private string $route ) {
			}
			public function get_route() {
				return $this->route;
			}
		};
	}

	public function test_hooks_run_before_core_canonical_redirects_and_rest_dispatch(): void {
		$tags = array_map( static fn( $r ) => $r[0] . ' -> ' . $r[1] . ' @' . $r[2], self::$registrations );
		self::assertContains( 'template_redirect -> lafka_block_author_enumeration @0', $tags );
		self::assertContains( 'rest_pre_dispatch -> lafka_restrict_users_endpoint @10', $tags );
	}

	public function test_author_probe_is_a_404_for_guests(): void {
		$_GET['author'] = '1';
		lafka_block_author_enumeration();
		self::assertSame( 404, $this->status );
		self::assertTrue( $GLOBALS['wp_query']->is_404 );
	}

	public function test_author_probe_passes_for_logged_in_users_normal_requests_and_when_off(): void {
		$_GET['author']  = '1';
		$this->logged_in = true;
		lafka_block_author_enumeration();
		self::assertNull( $this->status );

		$this->logged_in = false;
		unset( $_GET['author'] );
		lafka_block_author_enumeration();
		self::assertNull( $this->status, 'no ?author= probe' );

		$_GET['author']                                   = '1';
		$this->filters['lafka_restrict_user_enumeration'] = false;
		lafka_block_author_enumeration();
		self::assertNull( $this->status );
	}

	public function test_users_endpoint_is_closed_to_anonymous_requests_only(): void {
		foreach ( array( '/wp/v2/users', '/wp/v2/users/1', '/wp/v2/users/', '/wp/v2/Users' ) as $route ) {
			$result = lafka_restrict_users_endpoint( null, null, self::request( $route ) );
			self::assertInstanceOf( \WP_Error::class, $result, $route );
			self::assertSame( 'rest_user_cannot_view', $result->get_error_code() );
		}
		self::assertNull( lafka_restrict_users_endpoint( null, null, self::request( '/wp/v2/usersettings' ) ) );
		self::assertNull( lafka_restrict_users_endpoint( null, null, self::request( '/wc/store/v1/cart' ) ) );

		$this->logged_in = true;
		self::assertNull( lafka_restrict_users_endpoint( null, null, self::request( '/wp/v2/users' ) ), 'block editor' );

		$this->logged_in                                  = false;
		$this->filters['lafka_restrict_user_enumeration'] = false;
		self::assertNull( lafka_restrict_users_endpoint( null, null, self::request( '/wp/v2/users' ) ) );
	}

	public function test_an_earlier_dispatch_result_is_kept(): void {
		self::assertSame( 'cached', lafka_restrict_users_endpoint( 'cached', null, self::request( '/wp/v2/users' ) ) );
	}
}
