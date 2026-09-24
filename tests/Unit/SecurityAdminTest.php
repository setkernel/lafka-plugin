<?php
/**
 * SecurityAdminTest — Lafka_Security_Admin's form-post handler controls the
 * security-headers toggle, so its gates are exactly what an attacker would
 * target to flip the headers off remotely:
 *
 *   - a non-admin is refused before the nonce is even consulted,
 *   - a failed nonce blocks the write,
 *   - the submitted value is allowlisted to enabled|disabled and written to the
 *     dedicated option (preserving sibling keys), then redirected safely,
 *   - the settings page itself refuses non-admins.
 *
 * @package Lafka\Plugin\Tests\Unit
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Lafka_Security_Admin;
use Lafka_Security_Headers;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

// Load the real Lafka_Options first so the bootstrap's stub (guarded by
// class_exists) never shadows it for later test files that require the real one.
require_once dirname( __DIR__, 2 ) . '/incl/class-lafka-options.php';
require_once __DIR__ . '/Stubs/security-headers-bootstrap.php';

final class SecurityAdminTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		\Lafka_Options::flush(); // Drop any 'lafka' option array cached by an earlier test.
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'sanitize_text_field' )->returnArg();
		Functions\when( 'wp_unslash' )->returnArg();
		Functions\when( 'admin_url' )->alias( static fn( $path = '' ) => 'https://example.test/wp-admin/' . $path );
		Functions\when( 'add_query_arg' )->alias( static fn( $args, $url ) => $url . '?' . http_build_query( $args ) );
		// WordPress dies here; throwing lets the test observe it without exit().
		Functions\when( 'wp_die' )->alias(
			static function () {
				throw new RuntimeException( 'wp_die' );
			}
		);
		// The class file auto-instantiates when is_admin(); keep it inert on load.
		Functions\when( 'is_admin' )->justReturn( false );
		require_once dirname( __DIR__, 2 ) . '/incl/security/class-lafka-security-admin.php';
	}

	protected function tearDown(): void {
		$_POST = array();
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_non_admin_is_refused_before_the_nonce_or_option_is_touched(): void {
		Functions\when( 'current_user_can' )->justReturn( false );
		Functions\expect( 'check_admin_referer' )->never();
		Functions\expect( 'update_option' )->never();
		$_POST = array( 'enable_security_headers' => 'disabled' );

		$this->expectExceptionMessage( 'wp_die' );
		Lafka_Security_Admin::instance()->handle_save();
	}

	public function test_failed_nonce_blocks_the_write(): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\expect( 'check_admin_referer' )
			->once()
			->with( Lafka_Security_Admin::NONCE_ACTION )
			->andThrow( new RuntimeException( 'nonce' ) );
		Functions\expect( 'update_option' )->never();
		$_POST = array( 'enable_security_headers' => 'disabled' );

		$this->expectExceptionMessage( 'nonce' );
		Lafka_Security_Admin::instance()->handle_save();
	}

	/**
	 * @return array<string,array{0:array<string,string>,1:string}>
	 */
	public static function provider_submitted_values(): array {
		return array(
			'enable'        => array( array( 'enable_security_headers' => 'enabled' ), 'enabled' ),
			'disable'       => array( array( 'enable_security_headers' => 'disabled' ), 'disabled' ),
			'smuggled'      => array( array( 'enable_security_headers' => 'enabled; other' ), 'disabled' ),
			'missing field' => array( array(), 'disabled' ),
		);
	}

	#[DataProvider( 'provider_submitted_values' )]
	public function test_save_allowlists_the_value_into_the_dedicated_option( array $post, string $stored ): void {
		Functions\when( 'current_user_can' )->justReturn( true );
		Functions\when( 'check_admin_referer' )->justReturn( 1 );
		Functions\when( 'get_option' )->justReturn( array( 'unrelated' => 'kept' ) );
		$written = array();
		Functions\when( 'update_option' )->alias(
			static function ( $key, $value ) use ( &$written ) {
				$written[ $key ] = $value;
				return true;
			}
		);
		$redirect = '';
		Functions\when( 'wp_safe_redirect' )->alias(
			static function ( $url ) use ( &$redirect ) {
				$redirect = $url;
				throw new RuntimeException( 'redirect' ); // stand-in for the exit that follows.
			}
		);
		$_POST = $post;

		try {
			Lafka_Security_Admin::instance()->handle_save();
			$this->fail( 'handle_save must end in a redirect.' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'redirect', $e->getMessage() );
		}

		$this->assertSame(
			array(
				Lafka_Security_Headers::OPTION_KEY => array(
					'unrelated'                               => 'kept',
					Lafka_Security_Headers::TOGGLE_OPTION_KEY => $stored,
				),
			),
			$written
		);
		$this->assertSame( 'https://example.test/wp-admin/tools.php?page=lafka-security&updated=' . $stored, $redirect );
	}

	public function test_settings_page_refuses_non_admins(): void {
		Functions\when( 'current_user_can' )->justReturn( false );

		ob_start();
		try {
			Lafka_Security_Admin::instance()->render_page();
			$this->fail( 'render_page must wp_die for a non-admin.' );
		} catch ( RuntimeException $e ) {
			$this->assertSame( 'wp_die', $e->getMessage() );
		} finally {
			$this->assertSame( '', ob_get_clean(), 'Nothing may render before the capability check.' );
		}
	}
}
