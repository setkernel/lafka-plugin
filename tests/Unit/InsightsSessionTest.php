<?php
/**
 * Insights cookieless visit id (GX2 / B1): stable within a day, different
 * across days, the previous day's secret is gone; real-IP resolution trusts
 * Cloudflare's header only when the site says so; eligibility per consent mode.
 *
 * @package Lafka\Plugin\Tests\Unit
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey\Functions;
use Lafka_Insights_Session;
use LafkaPlugin\Tests\Unit\Support\InsightsHarness;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Support/InsightsHarness.php';

final class InsightsSessionTest extends TestCase {

	use InsightsHarness;

	protected function setUp(): void {
		parent::setUp();
		$this->set_up_insights();
	}

	protected function tearDown(): void {
		$this->tear_down_insights();
		parent::tearDown();
	}

	public function test_visit_id_is_stable_within_a_day_and_hides_the_ip(): void {
		$a = Lafka_Insights_Session::visitor_id( '203.0.113.7', $_SERVER['HTTP_USER_AGENT'] );
		$b = Lafka_Insights_Session::visitor_id( '203.0.113.7', $_SERVER['HTTP_USER_AGENT'] );

		$this->assertMatchesRegularExpression( '/^[a-f0-9]{32}$/', $a );
		$this->assertSame( $a, $b, 'Same visitor, same day → same visit id.' );
		$this->assertStringNotContainsString( '203.0.113.7', serialize( $this->options ), 'The IP is never stored.' );
	}

	public function test_visit_id_differs_between_visitors_and_ua_families(): void {
		$ua     = $_SERVER['HTTP_USER_AGENT'];
		$chrome = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0 Safari/537.36';

		$this->assertNotSame(
			Lafka_Insights_Session::visitor_id( '203.0.113.7', $ua ),
			Lafka_Insights_Session::visitor_id( '203.0.113.8', $ua )
		);
		$this->assertNotSame(
			Lafka_Insights_Session::visitor_id( '203.0.113.7', $ua ),
			Lafka_Insights_Session::visitor_id( '203.0.113.7', $chrome )
		);
	}

	public function test_a_new_day_rotates_the_secret_and_deletes_the_previous_one(): void {
		$ua        = $_SERVER['HTTP_USER_AGENT'];
		$today     = Lafka_Insights_Session::visitor_id( '203.0.113.7', $ua );
		$old_key   = $this->options[ Lafka_Insights_Session::SECRET_OPTION ]['key'];
		$this->now += 86400;

		$tomorrow = Lafka_Insights_Session::visitor_id( '203.0.113.7', $ua );

		$this->assertNotSame( $today, $tomorrow, 'Same visitor on another day → unlinkable id.' );
		$stored = $this->options[ Lafka_Insights_Session::SECRET_OPTION ];
		$this->assertSame( '2026-09-25', $stored['day'] );
		$this->assertNotSame( $old_key, $stored['key'], 'Yesterday\'s secret is overwritten, so yesterday\'s ids cannot be recomputed.' );
	}

	public function test_nightly_rotation_deletes_a_stale_secret_but_keeps_todays(): void {
		Lafka_Insights_Session::secret();
		$this->assertFalse( Lafka_Insights_Session::rotate(), "Today's secret stays." );
		$this->assertArrayHasKey( Lafka_Insights_Session::SECRET_OPTION, $this->options );

		$this->now += 86400;
		$this->assertTrue( Lafka_Insights_Session::rotate() );
		$this->assertArrayNotHasKey( Lafka_Insights_Session::SECRET_OPTION, $this->options );
	}

	public function test_cloudflare_header_is_trusted_only_when_the_site_is_behind_cloudflare(): void {
		$_SERVER['HTTP_CF_CONNECTING_IP'] = '198.51.100.23';

		$this->assertSame( '203.0.113.7', Lafka_Insights_Session::client_ip(), 'A forged CF header is ignored by default.' );

		$this->theme_mods['lafka_insights_behind_cloudflare'] = '1';
		$this->assertSame( '198.51.100.23', Lafka_Insights_Session::client_ip() );

		$_SERVER['HTTP_CF_CONNECTING_IP'] = 'not-an-ip';
		$this->assertSame( '203.0.113.7', Lafka_Insights_Session::client_ip(), 'An invalid header falls back to REMOTE_ADDR.' );
	}

	public function test_client_ip_filter_lets_other_proxies_resolve_the_ip(): void {
		Functions\when( 'apply_filters' )->alias(
			static function ( $hook, $value, ...$rest ) {
				return 'lafka_insights_client_ip' === $hook ? '192.0.2.44' : $value;
			}
		);
		$this->assertSame( '192.0.2.44', Lafka_Insights_Session::client_ip() );
	}

	public function test_ua_family_and_device_buckets(): void {
		$this->assertSame( 'safari-ios', Lafka_Insights_Session::ua_family( $_SERVER['HTTP_USER_AGENT'] ) );
		$this->assertSame( 'chrome-android', Lafka_Insights_Session::ua_family( 'Mozilla/5.0 (Linux; Android 14; Pixel 8) AppleWebKit/537.36 Chrome/130.0 Mobile Safari/537.36' ) );
		$this->assertSame( 'edge-windows', Lafka_Insights_Session::ua_family( 'Mozilla/5.0 (Windows NT 10.0) AppleWebKit/537.36 Chrome/130.0 Safari/537.36 Edg/130.0' ) );

		$this->assertSame( Lafka_Insights_Session::DEVICE_MOBILE, Lafka_Insights_Session::device_from_ua( $_SERVER['HTTP_USER_AGENT'] ) );
		$this->assertSame( Lafka_Insights_Session::DEVICE_TABLET, Lafka_Insights_Session::device_from_ua( 'Mozilla/5.0 (iPad; CPU OS 18_0 like Mac OS X)' ) );
		$this->assertSame( Lafka_Insights_Session::DEVICE_DESKTOP, Lafka_Insights_Session::device_from_ua( 'Mozilla/5.0 (Macintosh; Intel Mac OS X 14_0) Safari/605.1.15' ) );
	}

	public function test_aggregate_mode_honours_gpc_and_dnt(): void {
		$this->assertTrue( Lafka_Insights_Session::request_allowed() );

		$_SERVER['HTTP_SEC_GPC'] = '1';
		$this->assertFalse( Lafka_Insights_Session::request_allowed(), 'Global Privacy Control opts the visit out.' );

		unset( $_SERVER['HTTP_SEC_GPC'] );
		$_SERVER['HTTP_DNT'] = '1';
		$this->assertFalse( Lafka_Insights_Session::request_allowed(), 'Do Not Track opts the visit out.' );
	}

	public function test_consent_required_mode_needs_the_consent_cookie(): void {
		$this->theme_mods['lafka_insights_consent_mode'] = 'consent_required';
		$this->assertFalse( Lafka_Insights_Session::request_allowed() );

		$_COOKIE['lafka_consent'] = '0';
		$this->assertFalse( Lafka_Insights_Session::request_allowed(), 'A recorded refusal is respected.' );

		$_COOKIE['lafka_consent'] = '1';
		$this->assertTrue( Lafka_Insights_Session::request_allowed() );
	}

	public function test_off_mode_bots_and_staff_are_never_measured(): void {
		$this->theme_mods['lafka_insights_consent_mode'] = 'off';
		$this->assertFalse( Lafka_Insights_Session::request_allowed() );

		unset( $this->theme_mods['lafka_insights_consent_mode'] );
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';
		$this->assertFalse( Lafka_Insights_Session::request_allowed(), 'Crawlers are dropped.' );

		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (Windows NT 10.0) Chrome/130.0 Safari/537.36';
		$this->logged_in            = true;
		$this->can_manage           = true;
		$this->assertFalse( Lafka_Insights_Session::request_allowed(), 'Shop staff are excluded.' );

		$this->can_manage = false;
		$this->assertTrue( Lafka_Insights_Session::request_allowed(), 'Logged-in customers are measured.' );
	}
}
