<?php
/**
 * Shared doubles for the Insights (GX2) tests: an in-memory options /
 * theme_mod / transient store, a fixed site clock, a recording $wpdb and a
 * WP_REST_Request-shaped beacon request.
 *
 * @package Lafka_Plugin
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit\Support;

use Brain\Monkey;
use Brain\Monkey\Functions;

if ( ! defined( 'ARRAY_A' ) ) {
	define( 'ARRAY_A', 'ARRAY_A' );
}
if ( ! defined( 'DAY_IN_SECONDS' ) ) {
	define( 'DAY_IN_SECONDS', 86400 );
}

require_once dirname( __DIR__, 3 ) . '/incl/class-lafka-options.php';
require_once dirname( __DIR__, 3 ) . '/incl/class-lafka-beacon-guard.php';
require_once dirname( __DIR__, 3 ) . '/incl/insights/class-lafka-insights.php';
require_once dirname( __DIR__, 3 ) . '/incl/insights/class-lafka-insights-db.php';
require_once dirname( __DIR__, 3 ) . '/incl/insights/class-lafka-insights-session.php';
require_once dirname( __DIR__, 3 ) . '/incl/insights/class-lafka-insights-collector.php';
require_once dirname( __DIR__, 3 ) . '/incl/insights/class-lafka-insights-server-events.php';
require_once dirname( __DIR__, 3 ) . '/incl/insights/class-lafka-insights-rollup.php';
require_once dirname( __DIR__, 3 ) . '/incl/insights/class-lafka-insights-scheduler.php';
require_once dirname( __DIR__, 3 ) . '/incl/insights/class-lafka-insights-queries.php';
require_once dirname( __DIR__, 3 ) . '/incl/insights/class-lafka-insights-narrative.php';

/**
 * Recording $wpdb: prepare() inlines the arguments, query()/get_row()/
 * get_results() record the SQL and return canned values.
 */
final class FakeInsightsWpdb {

	public string $prefix = 'wp_';

	/** @var array<int,string> Every statement run through query(). */
	public array $queries = array();

	/** @var array<int,string> Every SELECT run through get_row()/get_results(). */
	public array $reads = array();

	/** @var array<string,mixed>|null Canned get_row() result. */
	public $row = null;

	/** @var array<string,array<int,array<string,mixed>>> Canned get_results() by needle in the SQL. */
	public array $results = array();

	public function prepare( $sql, ...$args ) {
		if ( 1 === count( $args ) && is_array( $args[0] ) ) {
			$args = $args[0];
		}
		foreach ( $args as $a ) {
			$replacement = ( is_int( $a ) || is_float( $a ) ) ? (string) $a : "'" . addslashes( (string) $a ) . "'";
			$sql         = preg_replace( '/%[dsf]/', str_replace( array( '\\', '$' ), array( '\\\\', '\\$' ), $replacement ), (string) $sql, 1 );
		}
		return $sql;
	}

	public function query( $sql ) {
		$this->queries[] = (string) $sql;
		return 1;
	}

	public function get_row( $sql, $output = null ) {
		$this->reads[] = (string) $sql;
		return $this->row;
	}

	public function get_results( $sql, $output = null ) {
		$this->reads[] = (string) $sql;
		foreach ( $this->results as $needle => $rows ) {
			if ( false !== strpos( (string) $sql, $needle ) ) {
				return $rows;
			}
		}
		return array();
	}

	public function get_charset_collate() {
		return 'DEFAULT CHARACTER SET utf8mb4';
	}

	/** Writes = INSERT / UPDATE / DELETE statements. */
	public function writes(): array {
		return array_values(
			array_filter(
				$this->queries,
				static function ( $q ) {
					return (bool) preg_match( '/^\s*(INSERT|UPDATE|DELETE)/i', $q );
				}
			)
		);
	}
}

/**
 * WP_REST_Request-shaped beacon request.
 */
final class FakeBeaconRequest {

	/** @var array<string,string> */
	private array $headers;
	private string $body;

	public function __construct( array $headers, string $body ) {
		$this->headers = array_change_key_case( $headers, CASE_LOWER );
		$this->body    = $body;
	}

	public function get_header( $key ) {
		return $this->headers[ strtolower( (string) $key ) ] ?? null;
	}

	public function get_body() {
		return $this->body;
	}
}

trait InsightsHarness {

	/** @var array<string,mixed> */
	protected array $options = array();

	/** @var array<string,mixed> */
	protected array $theme_mods = array();

	/** @var array<string,mixed> */
	protected array $transients = array();

	protected FakeInsightsWpdb $wpdb;

	/** Site clock (UTC == site time in these tests): Thu 2026-09-24 15:30. */
	protected int $now = 0;

	protected bool $logged_in = false;

	protected bool $can_manage = false;

	/** @var array<string,mixed> $_SERVER keys this harness touches, as they were. */
	private array $server_backup = array();

	protected function set_up_insights(): void {
		Monkey\setUp();
		\Lafka_Options::flush();
		\Lafka_Insights_Server_Events::reset();

		$this->wpdb      = new FakeInsightsWpdb();
		$GLOBALS['wpdb'] = $this->wpdb;
		$this->options   = array( 'lafka' => array( 'insights' => 'enabled' ) );
		$this->now       = gmmktime( 15, 30, 0, 9, 24, 2026 );

		foreach ( array( 'REMOTE_ADDR', 'HTTP_USER_AGENT' ) as $key ) {
			$this->server_backup[ $key ] = $_SERVER[ $key ] ?? null;
		}
		$_SERVER['REMOTE_ADDR']     = '203.0.113.7';
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (iPhone; CPU iPhone OS 18_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.0 Mobile/15E148 Safari/604.1';
		unset( $_SERVER['HTTP_SEC_GPC'], $_SERVER['HTTP_DNT'], $_SERVER['HTTP_CF_CONNECTING_IP'], $_COOKIE['lafka_consent'] );

		Functions\when( 'get_option' )->alias(
			function ( $key, $default = false ) {
				return array_key_exists( $key, $this->options ) ? $this->options[ $key ] : $default;
			}
		);
		Functions\when( 'update_option' )->alias(
			function ( $key, $value, $autoload = null ) {
				$this->options[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_option' )->alias(
			function ( $key ) {
				unset( $this->options[ $key ] );
				return true;
			}
		);
		Functions\when( 'get_theme_mod' )->alias(
			function ( $key, $default = false ) {
				return array_key_exists( $key, $this->theme_mods ) ? $this->theme_mods[ $key ] : $default;
			}
		);
		Functions\when( 'get_transient' )->alias(
			function ( $key ) {
				return $this->transients[ $key ] ?? false;
			}
		);
		Functions\when( 'set_transient' )->alias(
			function ( $key, $value, $ttl = 0 ) {
				$this->transients[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'delete_transient' )->alias(
			function ( $key ) {
				unset( $this->transients[ $key ] );
				return true;
			}
		);
		Functions\when( 'wp_date' )->alias(
			function ( $format, $ts = null ) {
				return gmdate( $format, null === $ts ? $this->now : $ts );
			}
		);
		Functions\when( 'wp_using_ext_object_cache' )->justReturn( false );
		Functions\when( 'home_url' )->alias(
			static function ( $path = '' ) {
				return 'https://shop.example.test/' . ltrim( (string) $path, '/' );
			}
		);
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'wp_strip_all_tags' )->alias( 'strip_tags' );
		Functions\when( 'is_user_logged_in' )->alias(
			function () {
				return $this->logged_in;
			}
		);
		Functions\when( 'current_user_can' )->alias(
			function () {
				return $this->can_manage;
			}
		);
		Functions\when( 'did_action' )->justReturn( 1 );
		Functions\when( '__' )->returnArg();
		Functions\when( '_n' )->alias(
			static function ( $single, $plural, $n ) {
				return 1 === (int) $n ? $single : $plural;
			}
		);
	}

	protected function tear_down_insights(): void {
		foreach ( $this->server_backup as $key => $value ) {
			if ( null === $value ) {
				unset( $_SERVER[ $key ] );
			} else {
				$_SERVER[ $key ] = $value;
			}
		}
		unset( $GLOBALS['wpdb'], $_SERVER['HTTP_SEC_GPC'], $_SERVER['HTTP_DNT'], $_SERVER['HTTP_CF_CONNECTING_IP'], $_COOKIE['lafka_consent'] );
		\Lafka_Options::flush();
		Monkey\tearDown();
	}

	/**
	 * A same-origin beacon request.
	 *
	 * @param array<string,mixed>|string $body Payload (array → JSON).
	 * @param array<string,string>       $headers Extra/override headers.
	 */
	protected function beacon( $body, array $headers = array() ): FakeBeaconRequest {
		return new FakeBeaconRequest(
			array_merge( array( 'origin' => 'https://shop.example.test' ), $headers ),
			is_string( $body ) ? $body : (string) json_encode( $body )
		);
	}

	/** Status of a Lafka_Beacon_Guard::reply() value. */
	protected function status_of( $reply ): int {
		if ( is_object( $reply ) && method_exists( $reply, 'get_status' ) ) {
			return (int) $reply->get_status();
		}
		return (int) ( $reply['status'] ?? 0 );
	}
}
