<?php
/**
 * Microsoft Clarity custom-tags client: enqueued when Clarity is configured
 * directly, or when a GTM-loaded Clarity opts in via lafka_enable_clarity_tags.
 *
 * @package Lafka\Plugin\Tests\Unit
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/incl/analytics/lafka-analytics-emitter.php';
require_once dirname( __DIR__, 2 ) . '/incl/analytics/lafka-clarity-tags.php';

final class AnalyticsClarityTagsTest extends TestCase {

	/** @var list<string> */
	private array $enqueued = array();

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		$this->enqueued = array();
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'get_theme_mod' )->returnArg( 2 );
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'plugins_url' )->returnArg();
		Functions\when( 'lafka_plugin_asset_version' )->justReturn( '1' );
		Functions\when( 'wp_enqueue_script' )->alias(
			function ( $handle ) {
				$this->enqueued[] = $handle;
			}
		);
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	public function test_not_enqueued_without_clarity(): void {
		lafka_analytics_enqueue_clarity_tags();
		$this->assertSame( array(), $this->enqueued );
	}

	public function test_enqueued_when_clarity_is_configured_directly(): void {
		Functions\when( 'get_theme_mod' )->alias(
			static fn( $key, $default = '' ) => 'lafka_clarity_project_id' === $key ? 'abc123xyz' : $default
		);
		lafka_analytics_enqueue_clarity_tags();
		$this->assertSame( array( 'lafka-clarity-tags' ), $this->enqueued );
	}

	public function test_enqueued_for_gtm_loaded_clarity_via_filter_but_never_in_admin(): void {
		Functions\when( 'apply_filters' )->alias(
			static fn( $hook, $value ) => 'lafka_enable_clarity_tags' === $hook ? true : $value
		);
		lafka_analytics_enqueue_clarity_tags();
		$this->assertSame( array( 'lafka-clarity-tags' ), $this->enqueued );

		$this->enqueued = array();
		Functions\when( 'is_admin' )->justReturn( true );
		lafka_analytics_enqueue_clarity_tags();
		$this->assertSame( array(), $this->enqueued );
	}
}
