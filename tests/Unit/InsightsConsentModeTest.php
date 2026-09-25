<?php
/**
 * Insights module gate, destination gate and consent modes (GX2 / B1 + B5):
 *   - Insights counts as a dataLayer destination only while it collects;
 *   - aggregate mode needs no cookie banner; consent_required forces it and
 *     mirrors the decision into the `lafka_consent` cookie + Woo attribution;
 *   - off collects nothing;
 *   - boot() wires the collector only when the module is on.
 *
 * @package Lafka\Plugin\Tests\Unit
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey\Functions;
use Lafka_Customizer_Analytics;
use Lafka_Insights;
use Lafka_Module_Registry;
use LafkaPlugin\Tests\Unit\Support\Hooks;
use LafkaPlugin\Tests\Unit\Support\InsightsHarness;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

require_once __DIR__ . '/Support/Hooks.php';
require_once __DIR__ . '/Support/InsightsHarness.php';
require_once dirname( __DIR__, 2 ) . '/incl/customizer/class-lafka-customizer-analytics.php';
require_once dirname( __DIR__, 2 ) . '/incl/analytics/lafka-analytics-emitter.php';
require_once dirname( __DIR__, 2 ) . '/incl/analytics/lafka-wc-events.php';
require_once dirname( __DIR__, 2 ) . '/incl/analytics/lafka-custom-events.php';
require_once dirname( __DIR__, 2 ) . '/incl/analytics/lafka-cf-analytics.php';
require_once dirname( __DIR__, 2 ) . '/incl/analytics/lafka-page-context.php';
require_once dirname( __DIR__, 2 ) . '/incl/class-lafka-module-registry.php';

final class InsightsConsentModeTest extends TestCase {

	use InsightsHarness;

	protected function setUp(): void {
		parent::setUp();
		$this->set_up_insights();
		$this->reset_boot_state();
		Hooks::reset();
		Functions\when( 'is_admin' )->justReturn( false );
		Functions\when( 'wp_json_encode' )->alias( static fn( $v ) => json_encode( $v ) );
		foreach ( array( 'esc_attr', 'esc_js', 'esc_html', 'esc_html__', 'esc_attr__', 'wp_kses_post' ) as $fn ) {
			Functions\when( $fn )->returnArg();
		}
		Functions\when( 'rest_url' )->alias( static fn( $p = '' ) => 'https://shop.example.test/wp-json/' . $p );
		Functions\when( 'wp_create_nonce' )->justReturn( 'n0nce' );
		Functions\when( 'get_query_var' )->justReturn( '' );
		Functions\when( 'is_customize_preview' )->justReturn( false );
	}

	protected function tearDown(): void {
		$this->reset_boot_state();
		$this->tear_down_insights();
		parent::tearDown();
	}

	private function reset_boot_state(): void {
		$ref = new ReflectionClass( Lafka_Insights::class );
		$ref->getProperty( 'booted' )->setValue( null, false );
		$ref->getProperty( 'active' )->setValue( null, false );
	}

	private function boot( string $flag = 'enabled' ): void {
		$this->options['lafka'] = array( 'insights' => $flag );
		\Lafka_Options::flush();
		Lafka_Insights::boot();
	}

	private function banner(): string {
		ob_start();
		lafka_emit_consent_banner();
		return (string) ob_get_clean();
	}

	// ─── Destination gate ────────────────────────────────────────────────

	public function test_module_off_is_not_a_destination(): void {
		$this->boot( 'disabled' );
		$this->assertFalse( lafka_analytics_has_datalayer_destination() );
		$this->assertFalse( lafka_custom_events_has_analytics_id() );
		$this->assertFalse( lafka_analytics_is_active() );
	}

	public function test_collecting_insights_activates_the_existing_dataLayer_emitters_without_ga4(): void {
		$this->boot();
		$this->assertTrue( lafka_analytics_has_datalayer_destination(), 'The dataLayer events feed the Insights beacon.' );
		$this->assertTrue( lafka_custom_events_has_analytics_id(), 'The byte-identical copy of the gate agrees.' );
		$this->assertTrue( lafka_analytics_is_active() );
	}

	public function test_consent_mode_off_is_not_a_destination(): void {
		$this->theme_mods['lafka_insights_consent_mode'] = 'off';
		$this->boot();
		$this->assertFalse( Lafka_Insights::is_collecting() );
		$this->assertFalse( lafka_analytics_has_datalayer_destination() );
	}

	// ─── Consent banner ──────────────────────────────────────────────────

	private function mirror(): string {
		ob_start();
		lafka_emit_consent_mirror();
		return (string) ob_get_clean();
	}

	public function test_aggregate_mode_needs_no_cookie_banner(): void {
		$this->boot();
		$this->assertSame( '', $this->banner(), 'Cookieless aggregate counting must not put up a cookie banner.' );
		$this->assertSame( '', $this->mirror() );
	}

	public function test_consent_required_mode_shows_the_banner_and_mirrors_the_decision(): void {
		$this->theme_mods['lafka_insights_consent_mode'] = 'consent_required';
		$this->boot();

		$this->assertStringContainsString( 'id="lafka-consent-banner"', $this->banner() );
		$mirror = $this->mirror();
		$this->assertStringStartsWith( '<script id="lafka-consent-mirror">', $mirror, 'Server hooks read the decision from a first-party cookie.' );
		$this->assertStringContainsString( 'lafka_consent=', $mirror );
		$this->assertStringContainsString( 'setOrderTracking', $mirror );

		$this->theme_mods['lafka_consent_banner_enabled'] = '0';
		$this->assertSame( '', $this->mirror(), 'No banner, nothing to mirror.' );
	}

	public function test_third_party_destination_banner_does_not_mirror_for_insights(): void {
		$this->theme_mods['lafka_ga4_measurement_id'] = 'G-ABCDE12345';
		$this->boot( 'disabled' );
		$this->assertStringContainsString( 'id="lafka-consent-banner"', $this->banner() );
		$this->assertSame( '', $this->mirror() );
	}

	// ─── Woo attribution, privacy text, script config ────────────────────

	public function test_consent_required_holds_woo_order_attribution_until_consent(): void {
		$this->assertTrue( Lafka_Insights::filter_order_attribution_tracking( true ) );
		$this->theme_mods['lafka_insights_consent_mode'] = 'consent_required';
		$this->assertFalse( Lafka_Insights::filter_order_attribution_tracking( true ) );
	}

	public function test_privacy_policy_text_matches_the_mode(): void {
		$aggregate = Lafka_Insights::privacy_policy_text();
		$this->assertStringContainsString( 'without cookies', $aggregate );
		$this->assertStringContainsString( 'never stored', $aggregate );
		$this->assertStringNotContainsString( 'lafka_consent', $aggregate );

		$this->theme_mods['lafka_insights_consent_mode'] = 'consent_required';
		$this->assertStringContainsString( 'lafka_consent', Lafka_Insights::privacy_policy_text() );
	}

	public function test_script_config_is_cache_safe_for_anonymous_visitors(): void {
		$this->assertSame(
			array(
				'u' => 'https://shop.example.test/wp-json/lafka/v1/i',
				'm' => 'a',
				'n' => '',
			),
			Lafka_Insights::script_config()
		);

		$this->logged_in                                 = true;
		$this->theme_mods['lafka_insights_consent_mode'] = 'consent_required';
		$config = Lafka_Insights::script_config();
		$this->assertSame( 'n0nce', $config['n'], 'Logged-in visitors authenticate the beacon with a wp_rest nonce.' );
		$this->assertSame( 'c', $config['m'] );
	}

	public function test_script_is_not_loaded_for_staff_kds_or_admin(): void {
		$this->boot();
		$this->assertTrue( Lafka_Insights::should_load_front_script() );

		$this->logged_in  = true;
		$this->can_manage = true;
		$this->assertFalse( Lafka_Insights::should_load_front_script(), 'Staff are not measured.' );

		$this->can_manage = false;
		Functions\when( 'get_query_var' )->justReturn( 'kds-token' );
		$this->assertFalse( Lafka_Insights::should_load_front_script(), 'Never on the kitchen display.' );

		Functions\when( 'get_query_var' )->justReturn( '' );
		Functions\when( 'is_admin' )->justReturn( true );
		$this->assertFalse( Lafka_Insights::should_load_front_script() );
	}

	// ─── Boot + module registry ──────────────────────────────────────────

	public function test_boot_wires_collection_only_when_the_module_is_on(): void {
		$this->boot( 'disabled' );
		$this->assertNotContains( 'rest_api_init -> register_routes', Hooks::registered() );

		$this->reset_boot_state();
		Hooks::reset();
		$this->boot();
		$registered = Hooks::registered();
		foreach ( array(
			'rest_api_init -> register_routes',
			'wp_enqueue_scripts -> enqueue_script',
			'woocommerce_add_to_cart -> on_add_to_cart',
			'lafka_checkout_blocked -> on_checkout_blocked',
			'lafka_insights_nightly -> run_nightly',
			'lafka_insights_weekly_email -> send_weekly_email',
			'admin_init -> ensure_scheduled',
			'admin_init -> add_privacy_policy_content',
			'woocommerce_email_classes -> register_email_class',
			'wc_order_attribution_allow_tracking -> filter_order_attribution_tracking',
		) as $expected ) {
			$this->assertContains( $expected, $registered );
		}
	}

	public function test_turning_the_module_on_installs_tables_and_schedules_jobs(): void {
		$sql       = \Lafka_Insights_DB::schema_sql();
		$scheduled = array();
		Functions\when( 'as_get_scheduled_actions' )->justReturn( array() );
		Functions\when( 'as_schedule_single_action' )->alias(
			static function ( $when, $hook ) use ( &$scheduled ) {
				$scheduled[] = $hook;
				return 1;
			}
		);
		Functions\when( 'wp_timezone' )->alias( static fn() => new \DateTimeZone( 'UTC' ) );

		Lafka_Insights::on_flags_changed( array( 'insights' => 'disabled' ), array( 'insights' => 'enabled' ) );

		$this->assertStringContainsString( 'CREATE TABLE wp_lafka_insights_sessions', $sql[0] );
		$this->assertStringContainsString( 'PRIMARY KEY  (day,sid)', $sql[0] );
		$this->assertStringContainsString( 'CREATE TABLE wp_lafka_insights_daily', $sql[1] );
		$this->assertSame( '1.0.0', $this->options['lafka_insights_db_version'] );
		$this->assertSame( array( 'lafka_insights_nightly', 'lafka_insights_weekly_email' ), $scheduled );

		$unscheduled = array();
		Functions\when( 'as_unschedule_all_actions' )->alias(
			static function ( $hook ) use ( &$unscheduled ) {
				$unscheduled[] = $hook;
			}
		);
		Functions\when( 'wp_clear_scheduled_hook' )->justReturn( 0 );
		Lafka_Insights::on_flags_changed( array( 'insights' => 'enabled' ), array( 'insights' => 'disabled' ) );
		$this->assertSame( array( 'lafka_insights_nightly', 'lafka_insights_weekly_email' ), $unscheduled );
	}

	public function test_weekly_job_reschedules_and_emails_only_while_collecting(): void {
		$scheduled = array();
		Functions\when( 'as_get_scheduled_actions' )->justReturn( array() );
		Functions\when( 'as_schedule_single_action' )->alias(
			static function ( $when, $hook ) use ( &$scheduled ) {
				$scheduled[] = $hook;
				return 1;
			}
		);
		Functions\when( 'wp_timezone' )->alias( static fn() => new \DateTimeZone( 'UTC' ) );
		Functions\when( 'WC' )->justReturn( null );
		Functions\when( 'wc_get_orders' )->justReturn( array() );
		Functions\when( 'wc_get_product' )->justReturn( null );

		\Brain\Monkey\Actions\expectDone( 'lafka_insights_weekly_email_trigger' )
			->once()
			->with( \Mockery::on( static fn( $report ) => 7 === $report['days'] && isset( $report['funnel']['visit'] ) ) );
		Lafka_Insights::send_weekly_email();
		$this->assertSame( array( 'lafka_insights_weekly_email' ), $scheduled, 'Next Monday is booked before sending.' );

		$this->theme_mods['lafka_insights_consent_mode'] = 'off';
		Lafka_Insights::send_weekly_email(); // expectDone(...)->once() fails if this sends.
	}

	public function test_registry_toggle_writes_the_lafka_flag(): void {
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'do_action' )->justReturn( null );
		Lafka_Module_Registry::reset();
		$module = Lafka_Module_Registry::get( 'insights' );

		$this->assertFalse( $module->default_enabled() );
		$this->assertSame( 'lafka_option', $module->get_storage() );
		$this->assertTrue( $module->is_enabled() );

		$module->set_enabled( false );
		$this->assertSame( 'disabled', $this->options['lafka']['insights'] );
		Lafka_Module_Registry::reset();
	}

	public function test_customizer_consent_mode_sanitizer(): void {
		$this->assertSame( 'consent_required', Lafka_Customizer_Analytics::sanitize_insights_consent_mode( ' Consent_Required ' ) );
		$this->assertSame( 'off', Lafka_Customizer_Analytics::sanitize_insights_consent_mode( 'off' ) );
		$this->assertSame( 'aggregate', Lafka_Customizer_Analytics::sanitize_insights_consent_mode( 'everything' ) );
		$this->assertSame( 'aggregate', Lafka_Insights::sanitize_consent_mode( array() ) );
	}
}
