<?php
/**
 * POST /lafka/v1/i (GX2 / B1): endpoint guards (same-origin, body cap, strict
 * schema, bots, consent, per-visit + global caps), the ≤ 2-writes budget, and
 * the payload → funnel/counter derivation.
 *
 * @package Lafka\Plugin\Tests\Unit
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey\Functions;
use Lafka_Insights_Collector;
use Lafka_Insights_DB;
use LafkaPlugin\Tests\Unit\Support\InsightsHarness;
use PHPUnit\Framework\TestCase;
use WP_Error;

require_once __DIR__ . '/Stubs/wp-error-class.php';
require_once __DIR__ . '/Support/InsightsHarness.php';

final class InsightsCollectorTest extends TestCase {

	use InsightsHarness;

	private const PAGE = array(
		'v'  => 1,
		'p'  => '/product/pizza/',
		't'  => 'product',
		'r'  => 'www.google.com',
		'us' => '',
		'um' => '',
		'uc' => '',
		'd'  => 'm',
		'e'  => array( array( 'v', '42' ), array( 's', 'Gluten Free', 0 ) ),
	);

	protected function setUp(): void {
		parent::setUp();
		$this->set_up_insights();
	}

	protected function tearDown(): void {
		$this->tear_down_insights();
		parent::tearDown();
	}

	// ─── Guards ──────────────────────────────────────────────────────────

	public function test_same_origin_beacons_pass_the_permission_check(): void {
		$this->assertTrue( Lafka_Insights_Collector::permission( $this->beacon( self::PAGE ) ) );
		$this->assertTrue(
			Lafka_Insights_Collector::permission( $this->beacon( self::PAGE, array( 'origin' => '', 'referer' => 'https://shop.example.test/menu/' ) ) ),
			'Referer is the fallback when Origin is absent.'
		);
	}

	public function test_cross_origin_or_originless_beacons_are_refused(): void {
		$this->assertInstanceOf( WP_Error::class, Lafka_Insights_Collector::permission( $this->beacon( self::PAGE, array( 'origin' => 'https://evil.example' ) ) ) );
		$this->assertInstanceOf( WP_Error::class, Lafka_Insights_Collector::permission( $this->beacon( self::PAGE, array( 'origin' => 'https://shop.example.test.evil.example' ) ) ) );
		$this->assertInstanceOf( WP_Error::class, Lafka_Insights_Collector::permission( $this->beacon( self::PAGE, array( 'origin' => '' ) ) ) );
	}

	public function test_oversized_or_empty_body_is_413_without_touching_the_db(): void {
		$this->assertSame( 413, $this->status_of( Lafka_Insights_Collector::handle( $this->beacon( str_repeat( 'x', 2049 ) ) ) ) );
		$this->assertSame( 413, $this->status_of( Lafka_Insights_Collector::handle( $this->beacon( '' ) ) ) );
		$this->assertSame( array(), $this->wpdb->queries );
	}

	public function test_schema_violations_are_400(): void {
		$bad = array(
			'unknown key'   => array_merge( self::PAGE, array( 'email' => 'a@b.c' ) ),
			'wrong version' => array_merge( self::PAGE, array( 'v' => 2 ) ),
			'unknown event' => array_merge( self::PAGE, array( 'e' => array( array( 'x' ) ) ) ),
			'bad item id'   => array_merge( self::PAGE, array( 'e' => array( array( 'v', 'abc' ) ) ) ),
			'too many'      => array_merge( self::PAGE, array( 'e' => array_fill( 0, 31, array( 'l' ) ) ) ),
			'non-string'    => array_merge( self::PAGE, array( 'p' => array( '/x' ) ) ),
		);
		foreach ( $bad as $label => $payload ) {
			$this->assertSame( 400, $this->status_of( Lafka_Insights_Collector::handle( $this->beacon( $payload ) ) ), $label );
		}
		$this->assertSame( 400, $this->status_of( Lafka_Insights_Collector::handle( $this->beacon( '{not json' ) ) ) );
		$this->assertSame( array(), $this->wpdb->writes() );
	}

	public function test_bots_staff_and_opted_out_visitors_are_dropped_silently(): void {
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (compatible; bingbot/2.0)';
		$this->assertSame( 204, $this->status_of( Lafka_Insights_Collector::handle( $this->beacon( self::PAGE ) ) ) );

		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0) Chrome/130.0 Safari/537.36';
		$_SERVER['HTTP_SEC_GPC']    = '1';
		$this->assertSame( 204, $this->status_of( Lafka_Insights_Collector::handle( $this->beacon( self::PAGE ) ) ) );

		unset( $_SERVER['HTTP_SEC_GPC'] );
		$this->logged_in  = true;
		$this->can_manage = true;
		$this->assertSame( 204, $this->status_of( Lafka_Insights_Collector::handle( $this->beacon( self::PAGE ) ) ) );

		$this->assertSame( array(), $this->wpdb->writes(), 'Nothing is recorded for bots, GPC or staff.' );
	}

	public function test_consent_required_without_consent_records_nothing(): void {
		$this->theme_mods['lafka_insights_consent_mode'] = 'consent_required';
		$this->assertSame( 204, $this->status_of( Lafka_Insights_Collector::handle( $this->beacon( self::PAGE ) ) ) );
		$this->assertSame( array(), $this->wpdb->writes() );

		$_COOKIE['lafka_consent'] = '1';
		Lafka_Insights_Collector::handle( $this->beacon( self::PAGE ) );
		$this->assertCount( 2, $this->wpdb->writes() );
	}

	public function test_per_visit_and_global_caps_answer_429_before_writing(): void {
		$this->wpdb->row = array(
			'pv' => 300,
			'g'  => 0,
		);
		$this->assertSame( 429, $this->status_of( Lafka_Insights_Collector::handle( $this->beacon( self::PAGE ) ) ) );

		$this->wpdb->row = array(
			'pv' => 1,
			'g'  => 5000,
		);
		$this->assertSame( 429, $this->status_of( Lafka_Insights_Collector::handle( $this->beacon( self::PAGE ) ) ) );
		$this->assertSame( array(), $this->wpdb->writes() );
	}

	// ─── Happy path + budget ─────────────────────────────────────────────

	public function test_a_beacon_costs_one_read_and_at_most_two_writes(): void {
		$reply = Lafka_Insights_Collector::handle( $this->beacon( self::PAGE ) );

		$this->assertSame( 204, $this->status_of( $reply ) );
		$this->assertCount( 1, $this->wpdb->reads, 'One read for the caps.' );
		$writes = $this->wpdb->writes();
		$this->assertCount( 2, $writes, 'Session upsert + one multi-row counter upsert — no transient.' );
		$this->assertCount( 2, $this->wpdb->queries );

		$this->assertStringContainsString( 'INSERT INTO wp_lafka_insights_sessions', $writes[0] );
		$this->assertStringContainsString( "'2026-09-24'", $writes[0] );
		$this->assertStringContainsString( 'stages = stages | VALUES(stages)', $writes[0] );
		$this->assertStringContainsString( "'organic'", $writes[0], 'Google referrer → organic, WC Order Attribution vocabulary.' );
		$this->assertStringContainsString( "'google.com'", $writes[0] );
		$this->assertStringNotContainsString( '203.0.113.7', implode( "\n", $writes ), 'The IP never reaches the database.' );

		$this->assertStringContainsString( 'INSERT INTO wp_lafka_insights_daily', $writes[1] );
		$this->assertStringContainsString( "'item_view','42',1", $writes[1] );
		$this->assertStringContainsString( "'search_zero','gluten free',1", $writes[1] );
		$this->assertStringContainsString( "'beacons','15',1", $writes[1] );
		$this->assertStringContainsString( 'value = value + VALUES(value)', $writes[1] );
	}

	public function test_closed_store_marks_the_visit(): void {
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value, ...$rest ) {
				return 'lafka_insights_store_is_open' === $hook ? false : $value;
			}
		);
		Lafka_Insights_Collector::handle( $this->beacon( self::PAGE ) );
		$bits = Lafka_Insights_DB::STAGE_VISIT | Lafka_Insights_DB::STAGE_PRODUCT | Lafka_Insights_DB::STAGE_MENU | Lafka_Insights_DB::STAGE_CLOSED;
		$this->assertStringContainsString( ",{$bits},", $this->wpdb->writes()[0] );
	}

	// ─── Derivation (pure) ───────────────────────────────────────────────

	public function test_derive_maps_events_to_stage_bits_and_counters(): void {
		$payload = Lafka_Insights_Collector::parse(
			(string) json_encode(
				array(
					'v' => 1,
					'p' => '/menu/',
					't' => 'page',
					'd' => 't',
					'e' => array( array( 'l' ), array( 'f', 'pickup' ), array( 'o', 'direct' ), array( 'h' ), array( 's', 'Pizza', 4 ), array( 'c' ) ),
				)
			)
		);
		$this->assertIsArray( $payload );

		$out = Lafka_Insights_Collector::derive( $payload, 'shop.example.test', '/menu/', true );

		$expected = Lafka_Insights_DB::STAGE_VISIT | Lafka_Insights_DB::STAGE_MENU | Lafka_Insights_DB::STAGE_CHECKOUT | Lafka_Insights_DB::STAGE_CLOSED;
		$this->assertSame( $expected, $out['row']['stages'] );
		$this->assertSame( 2, $out['row']['device'], 'Tablet viewport.' );
		$this->assertSame( 'typein', $out['row']['source_type'], 'No referrer → typed in.' );
		$this->assertSame( 'page', $out['row']['landing'] );
		$this->assertSame(
			array(
				'fulfilment'    => array( 'pickup' => 1 ),
				'order_channel' => array( 'direct' => 1 ),
				'search'        => array( 'pizza' => 1 ),
			),
			$out['counters']
		);
	}

	public function test_menu_page_path_counts_as_the_menu_step(): void {
		$payload = Lafka_Insights_Collector::parse( '{"v":1,"p":"/menu","t":"page"}' );
		$out     = Lafka_Insights_Collector::derive( $payload, 'shop.example.test', '/menu/', true );
		$this->assertSame( Lafka_Insights_DB::STAGE_VISIT | Lafka_Insights_DB::STAGE_MENU, $out['row']['stages'] );
	}

	public function test_internal_referrer_is_not_a_new_entry(): void {
		$payload = Lafka_Insights_Collector::parse( '{"v":1,"p":"/cart/","t":"cart","r":"shop.example.test"}' );
		$out     = Lafka_Insights_Collector::derive( $payload, 'shop.example.test', '', true );
		$this->assertSame( '', $out['row']['source_type'] );
		$this->assertSame( '', $out['row']['landing'], 'Landing is only set by an entry page view.' );
	}

	public function test_source_classification_matches_order_attribution_types(): void {
		$site = 'shop.example.test';
		$this->assertSame( 'utm', Lafka_Insights_Collector::classify_source( 'www.facebook.com', 'newsletter', 'email', 'fall', $site )['type'] );
		$this->assertSame( 'organic', Lafka_Insights_Collector::classify_source( 'www.bing.com', '', '', '', $site )['type'] );
		$this->assertSame( 'organic', Lafka_Insights_Collector::classify_source( 'duckduckgo.com', '', '', '', $site )['type'] );
		$this->assertSame( 'referral', Lafka_Insights_Collector::classify_source( 'l.facebook.com', '', '', '', $site )['type'] );
		$this->assertSame( 'typein', Lafka_Insights_Collector::classify_source( '', '', '', '', $site )['type'] );
		$this->assertSame( '', Lafka_Insights_Collector::classify_source( 'www.shop.example.test', '', '', '', $site )['type'] );
	}

	public function test_search_term_hygiene_drops_personal_data(): void {
		$this->assertSame( 'veggie pizza', Lafka_Insights_Collector::normalize_search_term( "  Veggie \n  PIZZA " ) );
		$this->assertSame( '', Lafka_Insights_Collector::normalize_search_term( 'jane@example.com' ) );
		$this->assertSame( '', Lafka_Insights_Collector::normalize_search_term( '902 555 0142' ) );
		$this->assertSame( '', Lafka_Insights_Collector::normalize_search_term( 'order 123456' ) );
		$this->assertSame( '2 for 1', Lafka_Insights_Collector::normalize_search_term( '2 for 1' ) );
		$this->assertSame( 64, mb_strlen( Lafka_Insights_Collector::normalize_search_term( str_repeat( 'a', 100 ) ) ) );
	}

	public function test_route_is_a_public_post_with_the_origin_permission(): void {
		$routes = array();
		Functions\when( 'register_rest_route' )->alias(
			static function ( $ns, $route, $args ) use ( &$routes ) {
				$routes[] = array( $ns, $route, $args );
				return true;
			}
		);
		Lafka_Insights_Collector::register_routes();
		$this->assertSame( 'lafka/v1', $routes[0][0] );
		$this->assertSame( '/i', $routes[0][1] );
		$this->assertSame( 'POST', $routes[0][2]['methods'] );
		$this->assertSame( array( Lafka_Insights_Collector::class, 'permission' ), $routes[0][2]['permission_callback'] );
	}
}
