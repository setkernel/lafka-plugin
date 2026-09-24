<?php
/**
 * Analytics tag emit layer: Customizer settings + sanitizers, Consent Mode v2
 * defaults, GTM / direct-platform emitters (override-not-additive), GSC meta
 * and the consent banner markup.
 *
 * @package Lafka\Plugin\Tests\Unit
 */

declare(strict_types=1);

namespace LafkaPlugin\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Lafka_Customizer_Analytics;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname( __DIR__, 2 ) . '/incl/customizer/class-lafka-customizer-analytics.php';
require_once dirname( __DIR__, 2 ) . '/incl/analytics/lafka-analytics-emitter.php';
// The consent banner gates on lafka_analytics_is_active() (page-context), which
// is built on the wc-events + cf-analytics gates.
require_once dirname( __DIR__, 2 ) . '/incl/analytics/lafka-wc-events.php';
require_once dirname( __DIR__, 2 ) . '/incl/analytics/lafka-cf-analytics.php';
require_once dirname( __DIR__, 2 ) . '/incl/analytics/lafka-page-context.php';

final class AnalyticsEmitterTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();
		Monkey\setUp();
		Functions\when( 'get_theme_mod' )->returnArg( 2 );
		Functions\when( 'wp_json_encode' )->alias( static fn( $v ) => json_encode( $v ) );
		Functions\when( 'esc_attr' )->returnArg();
		Functions\when( 'esc_js' )->returnArg();
		Functions\when( 'esc_html' )->returnArg();
		Functions\when( 'esc_html__' )->returnArg();
		Functions\when( 'esc_attr__' )->returnArg();
		Functions\when( 'wp_kses_post' )->returnArg();
		Functions\when( 'apply_filters' )->returnArg( 2 );
		Functions\when( 'did_action' )->justReturn( 0 );
	}

	protected function tearDown(): void {
		Monkey\tearDown();
		parent::tearDown();
	}

	/**
	 * @param array<string, string> $values
	 */
	private function stub_settings( array $values ): void {
		Functions\when( 'get_theme_mod' )->alias(
			static function ( $key, $default = null ) use ( $values ) {
				return array_key_exists( $key, $values ) ? $values[ $key ] : ( null === $default ? '' : $default );
			}
		);
	}

	private function capture( callable $fn ): string {
		ob_start();
		$fn();
		return (string) ob_get_clean();
	}

	// ── Customizer registration ─────────────────────────────────────────────

	public function test_every_setting_the_emitter_reads_is_registered_with_default_and_sanitizer(): void {
		$customize = new class() {
			/** @var array<string, array<string, mixed>> */
			public array $settings = array();
			public function add_panel( ...$args ): void {}
			public function add_section( ...$args ): void {}
			public function add_control( ...$args ): void {}
			public function add_setting( string $id, array $args ): void {
				$this->settings[ $id ] = $args;
			}
		};
		Lafka_Customizer_Analytics::register( $customize );

		$read_by_emitter = array(
			'lafka_gtm_container_id',
			'lafka_ga4_measurement_id',
			'lafka_clarity_project_id',
			'lafka_meta_pixel_id',
			'lafka_cf_beacon_token',
			'lafka_gsc_verification',
			'lafka_consent_banner_enabled',
			'lafka_consent_default_analytics',
			'lafka_consent_default_ad_storage',
			'lafka_consent_default_ad_user_data',
			'lafka_consent_default_ad_personalization',
			'lafka_consent_banner_text',
			'lafka_consent_banner_accept_label',
			'lafka_consent_banner_reject_label',
			'lafka_consent_banner_settings_label',
		);
		$this->assertSame( array(), array_diff( $read_by_emitter, array_keys( $customize->settings ) ), 'Settings read by the emitter but not registered.' );

		$violations = array();
		foreach ( $customize->settings as $id => $args ) {
			if ( ! array_key_exists( 'default', $args ) ) {
				$violations[] = "{$id}: no default";
			}
			$callback = $args['sanitize_callback'] ?? null;
			// WP core callbacks (sanitize_text_field) are strings the harness can't
			// call; the class's own sanitizers must resolve.
			if ( empty( $callback ) || ( is_array( $callback ) && ! is_callable( $callback ) ) ) {
				$violations[] = "{$id}: no sanitize_callback";
			}
		}
		$this->assertSame( array(), $violations );
	}

	// ── Sanitizers ──────────────────────────────────────────────────────────

	public function test_sanitize_gtm_container_id_accepts_valid_uppercases_and_rejects_invalid(): void {
		$this->assertSame( 'GTM-ABC123', Lafka_Customizer_Analytics::sanitize_gtm_container_id( 'gtm-abc123' ) );
		$this->assertSame( 'GTM-XYZ987', Lafka_Customizer_Analytics::sanitize_gtm_container_id( '  GTM-XYZ987  ' ) );
		$this->assertSame( '', Lafka_Customizer_Analytics::sanitize_gtm_container_id( 'XYZ-NOPREFIX' ) );
		$this->assertSame( '', Lafka_Customizer_Analytics::sanitize_gtm_container_id( 'GTM-' ) );
		$this->assertSame( '', Lafka_Customizer_Analytics::sanitize_gtm_container_id( '<script>alert(1)</script>' ) );
		$this->assertSame( '', Lafka_Customizer_Analytics::sanitize_gtm_container_id( array( 'GTM-X' ) ) );
	}

	public function test_sanitize_ga4_measurement_id_validates_g_prefix(): void {
		$this->assertSame( 'G-ABC1234567', Lafka_Customizer_Analytics::sanitize_ga4_measurement_id( 'g-abc1234567' ) );
		$this->assertSame( '', Lafka_Customizer_Analytics::sanitize_ga4_measurement_id( 'UA-12345-1' ) );
		$this->assertSame( '', Lafka_Customizer_Analytics::sanitize_ga4_measurement_id( 'G-' ) );
	}

	public function test_sanitize_meta_pixel_id_requires_15_to_16_digits(): void {
		$this->assertSame( '123456789012345', Lafka_Customizer_Analytics::sanitize_meta_pixel_id( '123456789012345' ) );
		$this->assertSame( '1234567890123456', Lafka_Customizer_Analytics::sanitize_meta_pixel_id( '1234567890123456' ) );
		$this->assertSame( '', Lafka_Customizer_Analytics::sanitize_meta_pixel_id( '12345' ) );
		$this->assertSame( '', Lafka_Customizer_Analytics::sanitize_meta_pixel_id( '12345678901234567' ) );
		$this->assertSame( '', Lafka_Customizer_Analytics::sanitize_meta_pixel_id( '12345abc12345ef' ) );
	}

	public function test_sanitize_consent_state_only_allows_denied_or_granted(): void {
		$this->assertSame( 'granted', Lafka_Customizer_Analytics::sanitize_consent_state( 'granted' ) );
		$this->assertSame( 'granted', Lafka_Customizer_Analytics::sanitize_consent_state( '  GRANTED  ' ) );
		$this->assertSame( 'denied', Lafka_Customizer_Analytics::sanitize_consent_state( 'denied' ) );
		$this->assertSame( 'denied', Lafka_Customizer_Analytics::sanitize_consent_state( 'maybe' ) );
		$this->assertSame( 'denied', Lafka_Customizer_Analytics::sanitize_consent_state( '' ) );
		$this->assertSame( 'denied', Lafka_Customizer_Analytics::sanitize_consent_state( array( 'granted' ) ) );
	}

	public function test_sanitize_gsc_verification_strips_html_breakouts(): void {
		$out = Lafka_Customizer_Analytics::sanitize_gsc_verification( "abc123\"><script>alert(1)</script>" );
		$this->assertStringNotContainsString( '<', $out );
		$this->assertStringNotContainsString( '>', $out );
		$this->assertStringNotContainsString( '"', $out );
	}

	// ── Consent Mode v2 defaults ────────────────────────────────────────────

	public function test_consent_mode_defaults_are_denied_and_datalayer_exists_before_gtag(): void {
		$out = $this->capture( 'lafka_emit_consent_mode_defaults' );

		$this->assertStringContainsString( "gtag('consent','default'", $out );
		foreach ( array( 'analytics_storage', 'ad_storage', 'ad_user_data', 'ad_personalization' ) as $category ) {
			$this->assertStringContainsString( '"' . $category . '":"denied"', $out );
		}
		$this->assertLessThan( strpos( $out, "gtag('consent','default'" ), strpos( $out, 'window.dataLayer' ) );
	}

	public function test_consent_mode_defaults_honour_granted_override(): void {
		$this->stub_settings( array( 'lafka_consent_default_analytics' => 'granted' ) );
		$out = $this->capture( 'lafka_emit_consent_mode_defaults' );
		$this->assertStringContainsString( '"analytics_storage":"granted"', $out );
		$this->assertStringContainsString( '"ad_storage":"denied"', $out );
	}

	/**
	 * Hook priority is the only thing that orders the consent scaffold before
	 * the tags, and add_action is a no-op under the unit harness, so this is
	 * read from the registration block.
	 */
	public function test_consent_scaffold_is_registered_before_any_tag(): void {
		$src = (string) file_get_contents( dirname( __DIR__, 2 ) . '/incl/analytics/lafka-analytics-emitter.php' );
		preg_match_all( "/add_action\(\s*'wp_head',\s*'(\w+)',\s*(\d+)\s*\)/", $src, $m, PREG_SET_ORDER );
		$order = array();
		foreach ( $m as $i => $hook ) {
			$order[ $hook[1] ] = array( (int) $hook[2], $i );
		}

		$defaults = $order['lafka_emit_consent_mode_defaults'] ?? null;
		$replay   = $order['lafka_emit_consent_replay'] ?? null;
		$this->assertNotNull( $defaults );
		$this->assertNotNull( $replay );
		$this->assertLessThan( $replay, $defaults, 'The stored-consent replay must run after the defaults define gtag().' );
		foreach ( array( 'lafka_emit_gtm_head', 'lafka_emit_direct_ga4', 'lafka_emit_direct_clarity', 'lafka_emit_direct_meta_pixel' ) as $tag ) {
			$this->assertArrayHasKey( $tag, $order );
			$this->assertLessThan( $order[ $tag ], $replay, "{$tag} must be registered after the consent scaffold." );
		}
	}

	// ── GTM / direct platform emitters ──────────────────────────────────────

	public function test_gtm_head_and_noscript_emit_when_container_id_set(): void {
		$this->stub_settings( array( 'lafka_gtm_container_id' => 'GTM-ABC123' ) );

		$head = $this->capture( 'lafka_emit_gtm_head' );
		$this->assertStringContainsString( 'googletagmanager.com/gtm.js', $head );
		$this->assertStringContainsString( "'GTM-ABC123'", $head );

		$body = $this->capture( 'lafka_emit_gtm_body_noscript' );
		$this->assertStringContainsString( 'googletagmanager.com/ns.html?id=GTM-ABC123', $body );
	}

	public function test_gtm_emitters_are_silent_without_a_container_id(): void {
		$this->assertSame( '', $this->capture( 'lafka_emit_gtm_head' ) );
		$this->assertSame( '', $this->capture( 'lafka_emit_gtm_body_noscript' ) );
		$this->assertSame( '', $this->capture( 'lafka_emit_gtm_body_noscript_fallback' ) );
	}

	public function test_direct_emitters_output_their_tag_when_gtm_is_empty(): void {
		$this->stub_settings(
			array(
				'lafka_ga4_measurement_id' => 'G-ABCDE12345',
				'lafka_clarity_project_id' => 'abc123xyz',
				'lafka_meta_pixel_id'      => '123456789012345',
			)
		);

		$ga4 = $this->capture( 'lafka_emit_direct_ga4' );
		$this->assertStringContainsString( 'googletagmanager.com/gtag/js?id=G-ABCDE12345', $ga4 );
		$this->assertStringContainsString( "gtag('config','G-ABCDE12345')", $ga4 );

		$clarity = $this->capture( 'lafka_emit_direct_clarity' );
		$this->assertStringContainsString( 'clarity.ms/tag/', $clarity );
		$this->assertStringContainsString( '"abc123xyz"', $clarity );

		$pixel = $this->capture( 'lafka_emit_direct_meta_pixel' );
		$this->assertStringContainsString( "fbq('init', '123456789012345')", $pixel );
		$this->assertStringContainsString( 'facebook.com/tr?id=123456789012345', $pixel );
	}

	/**
	 * @return array<string, array{0:string, 1:string, 2:string}>
	 */
	public static function direct_emitters(): array {
		return array(
			'ga4'     => array( 'lafka_emit_direct_ga4', 'lafka_ga4_measurement_id', 'G-ABCDE12345' ),
			'clarity' => array( 'lafka_emit_direct_clarity', 'lafka_clarity_project_id', 'abc123xyz' ),
			'pixel'   => array( 'lafka_emit_direct_meta_pixel', 'lafka_meta_pixel_id', '123456789012345' ),
		);
	}

	/**
	 * Override-not-additive: with a GTM container the platforms are wired in
	 * GTM, so a direct tag would double-count every event.
	 */
	#[DataProvider( 'direct_emitters' )]
	public function test_direct_emitter_is_silent_when_gtm_is_set_or_its_id_is_missing( string $emitter, string $key, string $id ): void {
		$this->stub_settings( array( 'lafka_gtm_container_id' => 'GTM-ABC123', $key => $id ) );
		$this->assertSame( '', $this->capture( $emitter ), 'Direct tag must no-op when GTM owns the tag layer.' );

		$this->stub_settings( array() );
		$this->assertSame( '', $this->capture( $emitter ), 'Direct tag must no-op without its own ID.' );
	}

	public function test_direct_emitters_reject_ids_that_bypassed_the_sanitizer(): void {
		$this->stub_settings( array( 'lafka_ga4_measurement_id' => "G-1');alert(1);('" ) );
		$this->assertSame( '', $this->capture( 'lafka_emit_direct_ga4' ) );
	}

	// ── GSC verification meta ───────────────────────────────────────────────

	public function test_gsc_verification_meta_emits_only_when_token_set(): void {
		$this->assertSame( '', $this->capture( 'lafka_emit_gsc_verification' ) );

		$this->stub_settings( array( 'lafka_gsc_verification' => 'gsc-token-abc123' ) );
		$this->assertStringContainsString(
			'<meta name="google-site-verification" content="gsc-token-abc123" />',
			$this->capture( 'lafka_emit_gsc_verification' )
		);
	}

	// ── Consent banner ──────────────────────────────────────────────────────

	public function test_consent_banner_renders_controls_and_persists_decisions(): void {
		$this->stub_settings(
			array(
				'lafka_consent_banner_enabled' => '1',
				'lafka_gtm_container_id'       => 'GTM-XYZ987',
			)
		);
		$out = $this->capture( 'lafka_emit_consent_banner' );

		foreach ( array( 'accept', 'reject', 'settings', 'save', 'close' ) as $action ) {
			$this->assertStringContainsString( 'data-lafka-consent="' . $action . '"', $out );
		}
		foreach ( array( 'analytics_storage', 'ad_storage', 'ad_user_data', 'ad_personalization' ) as $category ) {
			$this->assertStringContainsString( 'data-lafka-consent-cat="' . $category . '"', $out );
		}
		$this->assertStringContainsString( "var STORAGE_KEY = 'lafka_consent_v1'", $out );
		$this->assertStringContainsString( 'localStorage.setItem', $out );
		// Without a consent update the 'denied' default would never lift after an opt-in.
		$this->assertStringContainsString( "gtag('consent','update'", $out );
	}

	public function test_consent_banner_uses_customizer_copy(): void {
		$this->stub_settings(
			array(
				'lafka_consent_banner_enabled'        => '1',
				'lafka_gtm_container_id'              => 'GTM-XYZ987',
				'lafka_consent_banner_accept_label'   => 'Sounds good',
				'lafka_consent_banner_reject_label'   => 'No thanks',
				'lafka_consent_banner_settings_label' => 'Customize',
				'lafka_consent_banner_text'           => 'Custom banner copy.',
			)
		);
		$out = $this->capture( 'lafka_emit_consent_banner' );
		$this->assertStringContainsString( '>Sounds good</button>', $out );
		$this->assertStringContainsString( '>No thanks</button>', $out );
		$this->assertStringContainsString( '>Customize</button>', $out );
		$this->assertStringContainsString( '>Custom banner copy.</p>', $out );
	}
}
