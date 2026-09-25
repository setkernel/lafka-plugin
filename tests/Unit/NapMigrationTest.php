<?php
/**
 * GX3 single NAP store: the one-time theme_mod → option migration
 * (incl/schema/lafka-nap-migration.php).
 *
 *   - a legacy theme_mod is copied only where the option is empty;
 *   - an option the operator already set is never overwritten (the real
 *     "two stores disagree" case is reported by Site Health instead);
 *   - the literal "Array" (pre-9.11 cast bug) is never copied;
 *   - array-shaped legacy values are flattened to the text the settings edit;
 *   - the store version is stamped, so the migration runs once.
 *
 * @package Lafka\Plugin\Tests\Unit
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

final class NapMigrationTest extends TestCase {

	/** @var array<string, mixed> */
	private array $options = array();

	/** @var array<string, mixed> */
	private array $theme_mods = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->options    = array();
		$this->theme_mods = array();

		Functions\when( 'get_option' )->alias( fn( $key, $default = false ) => array_key_exists( $key, $this->options ) ? $this->options[ $key ] : $default );
		Functions\when( 'update_option' )->alias(
			function ( $key, $value ) {
				$this->options[ $key ] = $value;
				return true;
			}
		);
		Functions\when( 'get_theme_mod' )->alias( fn( $key, $default = false ) => array_key_exists( $key, $this->theme_mods ) ? $this->theme_mods[ $key ] : $default );

		require_once dirname( __DIR__, 2 ) . '/incl/schema/lafka-schema-helpers.php';
		require_once dirname( __DIR__, 2 ) . '/incl/schema/lafka-nap-migration.php';
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_copies_legacy_values_only_into_empty_options(): void {
		$this->options    = array(
			'lafka_business_phone_e164' => '+15551230000',
			'lafka_business_hours_mon'  => '',
		);
		$this->theme_mods = array(
			'lafka_business_name'       => 'Legacy Kitchen',
			'lafka_business_street'     => '1 Legacy Lane',
			'lafka_business_phone_e164' => '+15559999999',
			'lafka_business_hours_mon'  => '11:00-23:00',
		);

		$copied = \lafka_nap_migrate_theme_mods();

		$this->assertSame(
			array(
				'lafka_business_name'      => 'Legacy Kitchen',
				'lafka_business_street'    => '1 Legacy Lane',
				'lafka_business_hours_mon' => '11:00-23:00',
			),
			$copied
		);
		$this->assertSame( '+15551230000', $this->options['lafka_business_phone_e164'], 'an operator-set option must never be overwritten' );
		$this->assertSame( '11:00-23:00', $this->options['lafka_business_hours_mon'], 'an empty-string option counts as unset' );
		$this->assertSame( LAFKA_NAP_STORE_VERSION, $this->options['lafka_business_store_version'] );
	}

	public function test_the_array_sentinel_is_never_copied(): void {
		$this->theme_mods = array(
			'lafka_business_cuisines'        => 'Array',
			'lafka_business_payment_methods' => 'array',
			'lafka_business_same_as'         => 'Array',
		);

		$this->assertSame( array(), \lafka_nap_migrate_theme_mods() );
		$this->assertArrayNotHasKey( 'lafka_business_cuisines', $this->options );
	}

	public function test_an_array_option_value_marked_array_is_replaced(): void {
		// The option holds the broken sentinel too — the legacy value wins.
		$this->options    = array( 'lafka_business_cuisines' => 'Array' );
		$this->theme_mods = array( 'lafka_business_cuisines' => 'Pizza, Salads' );

		\lafka_nap_migrate_theme_mods();

		$this->assertSame( 'Pizza, Salads', $this->options['lafka_business_cuisines'] );
	}

	public function test_array_shaped_legacy_values_are_flattened(): void {
		$this->theme_mods = array(
			'lafka_business_cuisines' => array( 'Pizza', ' Salads ', '' ),
			'lafka_business_same_as'  => array( 'https://a.example.test/', 'https://b.example.test/' ),
		);

		\lafka_nap_migrate_theme_mods();

		$this->assertSame( 'Pizza, Salads', $this->options['lafka_business_cuisines'] );
		$this->assertSame( "https://a.example.test/\nhttps://b.example.test/", $this->options['lafka_business_same_as'] );
	}

	public function test_runs_once_per_store_version(): void {
		$this->theme_mods = array( 'lafka_business_name' => 'Legacy Kitchen' );
		\lafka_nap_maybe_migrate();
		$this->assertSame( 'Legacy Kitchen', $this->options['lafka_business_name'] );

		// Operator later clears the name on purpose; the migration must not
		// resurrect the legacy value.
		$this->options['lafka_business_name'] = '';
		\lafka_nap_maybe_migrate();
		$this->assertSame( '', $this->options['lafka_business_name'] );
	}
}
